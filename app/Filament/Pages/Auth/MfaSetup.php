<?php

namespace App\Filament\Pages\Auth;

use App\Auth\Mfa\MfaException;
use App\Auth\Mfa\StaffMfa;
use App\Enums\MfaMethod;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * Setting up and removing second factors. Reachable before the first factor
 * exists (EnsureStaffMfa sends a staff member without one here) and in a
 * verified session; StaffMfa refuses everything else on the server.
 */
class MfaSetup extends SimplePage
{
    protected string $view = 'filament.auth.mfa-setup';

    /** The factor being set up now. */
    #[Locked]
    public ?string $setting = null;

    #[Locked]
    public ?string $qr = null;

    #[Locked]
    public ?string $secret = null;

    #[Locked]
    public ?string $sentTo = null;

    #[Locked]
    public ?string $bootstrapSentTo = null;

    public ?string $smsNumber = null;

    public string $code = '';

    public string $emailCode = '';

    public function mount(): void
    {
        $mfa = app(StaffMfa::class);

        if (! $mfa->isEnabled()) {
            $this->redirect(Filament::getUrl());

            return;
        }

        if (! $mfa->canManageFactors($this->user())) {
            $this->redirectRoute('filament.admin.mfa.challenge');
        }
    }

    public function getTitle(): string|Htmlable
    {
        return __('Second factors');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Second factors');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return app(StaffMfa::class)->enrolledMethods($this->user()) === []
            ? __('Two-factor sign-in is on: set up a second factor to continue. You will need it at every sign-in.')
            : __('Every sign-in asks for one of these. Add another one in case you lose a device.');
    }

    /**
     * @return list<array{method: MfaMethod, enrolled: bool, blocker: ?string}>
     */
    public function factors(): array
    {
        $mfa = app(StaffMfa::class);
        $user = $this->user();

        return array_map(fn (MfaMethod $method): array => [
            'method' => $method,
            'enrolled' => $mfa->hasFactor($user, $method),
            'blocker' => match (true) {
                $method !== MfaMethod::Sms || $mfa->smsNumbers($user) !== [] => null,
                $mfa->smsNumbersOutsideAcceptedCountries($user) !== [] => __('The mobile number of your EMD record is not of a country accepted for phone numbers (Settings › Identification › Phone numbers), so no SMS code goes to it. Ask an administrator, or choose another factor.'),
                default => __('Your EMD record has no mobile number; SMS codes go only to a mobile number of the EMD record.'),
            },
        ], $mfa->allowedMethods());
    }

    /**
     * @return array<string, string>
     */
    public function smsOptions(): array
    {
        $numbers = app(StaffMfa::class)->smsNumbers($this->user());

        return array_combine($numbers, array_map(fn (string $number): string => StaffMfa::mask($number), $numbers));
    }

    public function smsNumberLabel(): ?string
    {
        $number = $this->user()->mfa_sms_number;

        return $number === null ? null : StaffMfa::mask($number);
    }

    public function needsBootstrap(): bool
    {
        return app(StaffMfa::class)->needsBootstrap($this->user());
    }

    public function isVerified(): bool
    {
        return app(StaffMfa::class)->passedInSession($this->user());
    }

    public function start(string $method): void
    {
        $this->cancel();
        $chosen = MfaMethod::tryFrom($method);

        if ($chosen === null) {
            return;
        }

        try {
            if ($chosen === MfaMethod::App) {
                ['secret' => $this->secret, 'qr' => $this->qr] = app(StaffMfa::class)->beginAppSetup($this->user());
            }
            $this->setting = $chosen->value;
            $this->smsNumber = array_key_first($this->smsOptions());
        } catch (MfaException $e) {
            $this->addError('code', $e->getMessage());
        }
    }

    public function sendCode(): void
    {
        $method = MfaMethod::tryFrom((string) $this->setting);

        if ($method === null || ! $method->isSent()) {
            return;
        }

        try {
            $this->sentTo = app(StaffMfa::class)->sendCode($this->user(), $method, StaffMfa::PURPOSE_SETUP, $method === MfaMethod::Sms ? $this->smsNumber : null);
            $this->resetErrorBag();
        } catch (MfaException $e) {
            $this->addError('code', $e->getMessage());
        }
    }

    public function sendBootstrapCode(): void
    {
        try {
            $this->bootstrapSentTo = app(StaffMfa::class)->sendCode($this->user(), MfaMethod::Email, StaffMfa::PURPOSE_BOOTSTRAP);
            $this->resetErrorBag();
        } catch (MfaException $e) {
            $this->addError('emailCode', $e->getMessage());
        }
    }

    public function confirm(): void
    {
        $method = MfaMethod::tryFrom((string) $this->setting);
        [$code, $emailCode] = [$this->code, $this->emailCode];
        $this->code = '';
        $this->emailCode = '';

        if ($method === null) {
            return;
        }

        foreach (['code' => $code, 'emailCode' => $emailCode] as $field => $typed) {
            if (($tooLong = MfaChallenge::codeTooLong($typed)) !== null) {
                $this->addError($field, $tooLong);

                return;
            }
        }

        try {
            $method === MfaMethod::App
                ? app(StaffMfa::class)->confirmAppSetup($this->user(), $code, $emailCode)
                : app(StaffMfa::class)->confirmSentSetup($this->user(), $method, $code);
        } catch (MfaException $e) {
            $this->addError('code', $e->getMessage());

            return;
        }

        $this->cancel();
        Notification::make()->title(__(':method set up', ['method' => $method->label()]))->success()->send();
    }

    public function remove(string $method): void
    {
        $chosen = MfaMethod::tryFrom($method);

        if ($chosen === null) {
            return;
        }

        try {
            app(StaffMfa::class)->removeFactor($this->user(), $chosen);
        } catch (MfaException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title(__(':method removed', ['method' => $chosen->label()]))->success()->send();
    }

    public function cancel(): void
    {
        $this->setting = null;
        $this->qr = null;
        $this->secret = null;
        $this->sentTo = null;
        $this->bootstrapSentTo = null;
        $this->code = '';
        $this->emailCode = '';
        $this->resetErrorBag();
    }

    public function continue(): void
    {
        if (! app(StaffMfa::class)->isSatisfied($this->user())) {
            Notification::make()->title(__('Set up a second factor first.'))->warning()->send();

            return;
        }

        $this->redirectIntended(Filament::getUrl());
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
