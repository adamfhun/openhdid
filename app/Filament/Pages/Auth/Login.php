<?php

namespace App\Filament\Pages\Auth;

use App\Auth\AccountLogin;
use App\Auth\LoginRejectedException;
use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\SsoAccess;
use App\Enums\PrincipalType;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
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
        if (! app(Settings::class)->bool(SettingKey::UserLoginPasswordEnabled)) {
            $this->throwFailureValidationException();
        }

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

            app(AccountLogin::class)->recordSuccess($user, 'password');
        }

        return $response;
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
