<?php

namespace App\Filament\Pages\Auth;

use App\Auth\AccountLogin;
use App\Auth\LoginLockout;
use App\Auth\LoginRejectedException;
use App\Auth\LoginRejection;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\SsoAccess;
use App\Enums\PrincipalType;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\ClientAddress;
use App\Support\EmailAddressRule;
use App\Support\RateLimitExemptions;
use App\System\Installation;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Staff login: password form (if enabled) plus the enabled SSO providers.
 */
class Login extends BaseLogin
{
    /** The password field takes what the account form takes (UserResource). */
    public const PASSWORD_MAX_LENGTH = 255;

    /** Lock around one staff account's password checks. */
    private const PASSWORD_LOCK_PREFIX = 'hdid:login-password:';

    /**
     * Set while an attempt runs whose one per-address count was taken
     * first; the parent's own count of the same attempt is then skipped.
     * Not public: it is no Livewire state a request could set.
     */
    private bool $attemptCounted = false;

    /** The exact account of the typed address, looked up once per request. */
    private ?User $exactUser = null;

    private ?string $exactUserFor = null;

    /**
     * The password form logs in through Filament's own guard call, so the
     * last-login stamp and the audit entry the SSO flows write have to be
     * added here; a null response means the login did not happen (yet).
     */
    public function authenticate(): ?LoginResponse
    {
        // Every attempt counts once, first, in one bucket, whichever branch it
        // takes: the early refusals (password login off, locked account) as
        // well as the password check.
        try {
            $this->rateLimit(5, method: 'authenticate');
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $this->attemptCounted = true;

        try {
            return $this->authenticateCounted();
        } finally {
            $this->attemptCounted = false;
        }
    }

    private function authenticateCounted(): ?LoginResponse
    {
        // Hiding the form is not enough: the Livewire action can be called
        // directly, so a disabled password login has to be refused here.
        if (! app(Settings::class)->bool(SettingKey::UserLoginPasswordEnabled)) {
            app(AccountLogin::class)->recordRejection(PrincipalType::User, 'password', LoginRejection::MethodDisabled, ['email' => $this->attemptedEmail()]);

            parent::throwFailureValidationException();
        }

        $user = $this->attemptedUser();

        // No account by exactly this address: the parent finds none either
        // (getCredentialsFromFormData) and refuses it as a wrong password.
        if ($user === null) {
            return $this->checkPassword(null);
        }

        // One account's attempts are judged one at a time: the lock check,
        // the password check, the failure count and the success form one
        // critical section, so a parallel wrong guess that locks the account
        // cannot be passed by a right password checked at the same moment.
        try {
            return Cache::lock(Installation::lockName(self::PASSWORD_LOCK_PREFIX.$user->id), 15)->block(5, fn (): ?LoginResponse => $this->checkPassword($user));
        } catch (LockTimeoutException) {
            app(AccountLogin::class)->recordRejection(PrincipalType::User, 'password', LoginRejection::InvalidCredentials, ['email' => $user->email, 'detail' => 'busy'], $user);

            parent::throwFailureValidationException();
        }
    }

    private function checkPassword(?User $user): ?LoginResponse
    {
        // A locked account is refused before its password is even checked.
        if ($user !== null && $user->refresh()->isLoginLocked()) {
            try {
                app(LoginLockout::class)->assertNotLocked($user, 'password');
            } catch (LoginRejectedException $e) {
                throw ValidationException::withMessages(['data.email' => $e->getMessage()]);
            }
        }

        app(AccountLogin::class)->applyRememberDuration(PrincipalType::User);
        $response = parent::authenticate();

        if ($response !== null && ($signedIn = auth()->user()) instanceof User) {
            // The account signed in, as it is now: a parallel wrong guess may
            // have locked it while the password was being checked.
            if ($signedIn->refresh()->isLoginLocked()) {
                $this->signOutRefused();
                app(AccountLogin::class)->recordRejection(PrincipalType::User, 'password', LoginRejection::AccountLocked, ['email' => $signedIn->email, 'detail' => 'locked_during_check', 'locked_until' => $signedIn->locked_until?->toIso8601String()], $signedIn);

                throw ValidationException::withMessages(['data.email' => (new LoginRejectedException(LoginRejection::AccountLocked))->getMessage()]);
            }

            // The one login rule covers the password path too: Filament's own
            // check only looks at the closed flag and the panel permission, so
            // a missing or mismatched directory record would pass here while
            // the SSO paths refuse it.
            try {
                app(AccountLogin::class)->assertEligible($signedIn, PrincipalType::User, 'password');
            } catch (LoginRejectedException $e) {
                $this->signOutRefused();

                throw ValidationException::withMessages(['data.email' => $e->getMessage()]);
            }

            app(LoginLockout::class)->recordSuccess($signedIn);
            app(AccountLogin::class)->recordSuccess($signedIn, 'password');
        }

        return $response;
    }

    private function signOutRefused(): void
    {
        auth()->logout();
        session()->invalidate();
        session()->regenerateToken();
    }

    /**
     * The guard looks up exactly the account attemptedUser() names, by its
     * key: on MariaDB a look-alike address ("víctim@…") would find the
     * account through the accent-insensitive collation while every other
     * check here (the lock, the lockout count) saw no account. A look-alike
     * address is an unknown one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $exact = $this->attemptedUser();

        return [
            'email' => fn ($query) => $exact === null ? $query->whereRaw('1 = 0') : $query->whereKey($exact->getKey()),
            'password' => $data['password'],
        ];
    }

    /**
     * Every wrong password is audited and counts towards the lockout of the
     * account it names. An account Filament refuses for its state (closed,
     * no panel access) is audited with that reason instead: the password may
     * well have been right, and the lock would outlive a reopening.
     */
    protected function throwFailureValidationException(): never
    {
        $user = $this->attemptedUser();

        if ($user === null) {
            app(AccountLogin::class)->recordRejection(PrincipalType::User, 'password', LoginRejection::InvalidCredentials, ['email' => $this->attemptedEmail()]);
        } elseif (! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
            app(AccountLogin::class)->recordRejection(PrincipalType::User, 'password', $user->isClosed() ? LoginRejection::AccountClosed : LoginRejection::NoPermission, ['email' => $user->email], $user);
        } else {
            app(LoginLockout::class)->recordFailure($user, 'password');
        }

        parent::throwFailureValidationException();
    }

    /**
     * Filament's limit of login attempts per network address (five a
     * minute, here and in the parent): an address of
     * HDID_RATE_LIMIT_EXEMPT_NETWORKS is not limited, since a whole company
     * may sign in from it. The account lockout (LoginLockout) still counts
     * every wrong password.
     *
     * @param  int  $maxAttempts
     * @param  int  $decaySeconds
     * @param  string|null  $method
     * @param  string|null  $component
     */
    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null)
    {
        if (app(RateLimitExemptions::class)->covers(request()->ip())) {
            return;
        }

        $method ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 2)[1]['function'];

        // The attempt was counted once, first (authenticate()).
        if ($this->attemptCounted && $method === 'authenticate') {
            return;
        }

        parent::rateLimit($maxAttempts, $decaySeconds, $method, $component);
    }

    /**
     * The key of Filament's per-address login limit: an IPv6 address counts
     * by its /64 network (ClientAddress), so a subscriber cannot rotate
     * through its addresses for fresh attempts.
     *
     * @param  string|null  $method
     * @param  string|null  $component
     */
    protected function getRateLimitKey($method, $component = null)
    {
        $method ??= debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 2)[1]['function'];
        $component ??= static::class;

        return 'livewire-rate-limiter:'.hash('sha256', $component.'|'.$method.'|'.ClientAddress::limitKey(request()->ip()));
    }

    /**
     * The address within the standard lengths (EmailAddressRule: 254 in
     * all, 64 before the @), shown on the field and checked before any
     * account is looked up; Filament's own field only asks for an e-mail
     * address, whose rule lets longer ones through.
     */
    protected function getEmailFormComponent(): Component
    {
        /** @var TextInput $field */
        $field = parent::getEmailFormComponent();

        return $field->maxLength(EmailAddressRule::MAX_LENGTH)->rule(new EmailAddressRule);
    }

    protected function getPasswordFormComponent(): Component
    {
        /** @var TextInput $field */
        $field = parent::getPasswordFormComponent();

        return $field->maxLength(self::PASSWORD_MAX_LENGTH);
    }

    /**
     * The "remember me" box only while remember-me is switched on; hidden, its
     * state is not part of the form data, so a forged value is ignored.
     */
    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()
            ->visible(fn (): bool => app(AccountLogin::class)->rememberMinutes(PrincipalType::User) !== null);
    }

    /**
     * The typed address, at most as long as an e-mail address can be (RFC
     * 5321: 254 characters): the early refusals write it to the audit log
     * before the form is validated, and the field takes whatever the caller
     * sends.
     */
    private function attemptedEmail(): ?string
    {
        $email = $this->data['email'] ?? null;

        return is_string($email) && $email !== '' ? mb_strtolower(mb_substr(trim($email), 0, EmailAddressRule::MAX_LENGTH)) : null;
    }

    /**
     * The account the typed address names exactly (AccountLogin::findByEmail):
     * an address longer than an e-mail address can be names none, its cut
     * beginning is only for the audit entry.
     */
    private function attemptedUser(): ?User
    {
        $typed = $this->data['email'] ?? null;
        $email = $this->attemptedEmail();

        if ($email === null || ! is_string($typed) || mb_strlen(trim($typed)) > EmailAddressRule::MAX_LENGTH) {
            return null;
        }

        if ($this->exactUserFor !== $email) {
            $user = app(AccountLogin::class)->findByEmail(PrincipalType::User, $email);
            $this->exactUser = $user instanceof User ? $user : null;
            $this->exactUserFor = $email;
        }

        return $this->exactUser;
    }

    /**
     * With company sign-on configured, the password form is the secondary
     * path: it stays folded behind a small link until the user asks for it.
     */
    public bool $showPasswordForm = false;

    public function getFormContentComponent(): Component
    {
        $passwordEnabled = app(Settings::class)->bool(SettingKey::UserLoginPasswordEnabled);

        return parent::getFormContentComponent()
            ->visible(fn (): bool => $passwordEnabled && ($this->ssoProviders() === [] || $this->showPasswordForm));
    }

    public function getMultiFactorChallengeFormContentComponent(): Component
    {
        return parent::getMultiFactorChallengeFormContentComponent();
    }

    /**
     * @return list<OidcProvider>
     */
    protected function ssoProviders(): array
    {
        return array_values(array_filter(
            OidcProvider::cases(),
            fn (OidcProvider $p) => app(SsoAccess::class)->isAvailable(PrincipalType::User, $p),
        ));
    }

    /**
     * Company sign-on buttons first, then (if passwords are allowed) a small
     * link that unfolds the password form.
     *
     * @return array<int, Component>
     */
    protected function ssoComponents(): array
    {
        $providers = $this->ssoProviders();

        if ($providers === []) {
            return [];
        }

        $passwordEnabled = app(Settings::class)->bool(SettingKey::UserLoginPasswordEnabled);

        return [
            View::make('filament.sso-buttons')->viewData(['providers' => $providers]),
            View::make('filament.password-toggle')->visible(fn (): bool => $passwordEnabled && ! $this->showPasswordForm),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $schema = parent::content($schema);

        $components = $schema->getComponents();
        $sso = $this->ssoComponents();

        if ($sso === []) {
            return $schema;
        }

        // The password form is the component with id "form"; the SSO block goes right before it.
        $index = 0;
        foreach ($components as $i => $component) {
            if (method_exists($component, 'getId') && $component->getId() === 'form') {
                $index = $i;
                break;
            }
        }

        array_splice($components, $index, 0, $sso);

        return $schema->components($components);
    }
}
