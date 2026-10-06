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
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Staff login: password form (if enabled) plus the enabled SSO providers.
 */
class Login extends BaseLogin
{
    /**
     * The password form logs in through Filament's own guard call, so the
     * last-login stamp and the audit entry the SSO flows write have to be
     * added here; a null response means the login did not happen (yet).
     */
    public function authenticate(): ?LoginResponse
    {
        // Hiding the form is not enough: the Livewire action can be called
        // directly, so a disabled password login has to be refused here.
        // These early refusals count towards the same per-address limit as
        // the password check in the parent, which they never reach.
        if (! app(Settings::class)->bool(SettingKey::UserLoginPasswordEnabled)) {
            if ($this->refusedByRateLimit()) {
                return null;
            }

            app(AccountLogin::class)->recordRejection(PrincipalType::User, 'password', LoginRejection::MethodDisabled, ['email' => $this->attemptedEmail()]);

            parent::throwFailureValidationException();
        }

        // A locked account is refused before its password is even checked.
        if (($locked = $this->attemptedUser()) !== null && $locked->isLoginLocked()) {
            if ($this->refusedByRateLimit()) {
                return null;
            }

            try {
                app(LoginLockout::class)->assertNotLocked($locked, 'password');
            } catch (LoginRejectedException $e) {
                throw ValidationException::withMessages(['data.email' => $e->getMessage()]);
            }
        }

        app(AccountLogin::class)->applyRememberDuration(PrincipalType::User);
        $response = parent::authenticate();

        if ($response !== null && ($user = auth()->user()) instanceof User) {
            // The one login rule covers the password path too: Filament's own
            // check only looks at the closed flag and the panel permission, so
            // a missing or mismatched directory record would pass here while
            // the SSO paths refuse it.
            try {
                app(AccountLogin::class)->assertEligible($user, PrincipalType::User, 'password');
            } catch (LoginRejectedException $e) {
                auth()->logout();
                session()->invalidate();
                session()->regenerateToken();

                throw ValidationException::withMessages(['data.email' => $e->getMessage()]);
            }

            app(LoginLockout::class)->recordSuccess($user);
            app(AccountLogin::class)->recordSuccess($user, 'password');
        }

        return $response;
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
     * Filament's own per-address limit, applied where the parent's check is
     * not reached; true (with the notification sent) when the limit is hit.
     * (Not named isRateLimited: the rate-limiting trait took that name.)
     */
    private function refusedByRateLimit(): bool
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return true;
        }

        return false;
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

    private function attemptedEmail(): ?string
    {
        $email = $this->data['email'] ?? null;

        return is_string($email) && $email !== '' ? mb_strtolower(trim($email)) : null;
    }

    private function attemptedUser(): ?User
    {
        $email = $this->attemptedEmail();

        return $email === null ? null : User::query()->where('email', $email)->first();
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
