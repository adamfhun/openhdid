<?php

namespace App\Filament\Pages\Auth;

use App\Auth\Mfa\MfaException;
use App\Auth\Mfa\StaffMfa;
use App\Enums\MfaMethod;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * The second step of every staff sign-in while two-factor sign-in is on:
 * EnsureStaffMfa sends every panel request here until the session passes.
 * The page only calls StaffMfa, which decides and audits.
 */
class MfaChallenge extends SimplePage
{
    /**
     * A code as typed: StaffMfa::CODE_LENGTH digits, with room for the
     * spaces or dashes people put between them. Anything longer is refused
     * here, before the account lock, the slow check and the attempt count.
     */
    public const CODE_INPUT_MAX_LENGTH = 12;

    protected string $view = 'filament.auth.mfa-challenge';

    #[Locked]
    public ?string $method = null;

    #[Locked]
    public ?string $sentTo = null;

    public string $code = '';

    public function mount(): void
    {
        $mfa = app(StaffMfa::class);

        if ($mfa->isSatisfied($this->user())) {
            $this->redirect(Filament::getUrl());

            return;
        }

        if ($mfa->needsSetup($this->user())) {
            $this->redirectRoute('filament.admin.mfa.setup');

            return;
        }

        $methods = $this->methods();
        $this->method = count($methods) === 1 ? $methods[0]->value : null;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Second factor');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('Verify your sign-in');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Two-factor sign-in is on: confirm it is you with one of your second factors.');
    }

    /**
     * @return list<MfaMethod>
     */
    public function methods(): array
    {
        return app(StaffMfa::class)->enrolledMethods($this->user());
    }

    public function chosen(): ?MfaMethod
    {
        $method = MfaMethod::tryFrom((string) $this->method);

        return $method !== null && in_array($method, $this->methods(), true) ? $method : null;
    }

    public function choose(string $method): void
    {
        $chosen = MfaMethod::tryFrom($method);
        $this->method = $chosen !== null && in_array($chosen, $this->methods(), true) ? $chosen->value : null;
        $this->sentTo = null;
        $this->code = '';
        $this->resetErrorBag();
    }

    public function sendCode(): void
    {
        $method = $this->chosen();

        if ($method === null || ! $method->isSent()) {
            return;
        }

        try {
            $this->sentTo = app(StaffMfa::class)->sendCode($this->user(), $method, StaffMfa::PURPOSE_CHALLENGE);
            $this->resetErrorBag();
        } catch (MfaException $e) {
            $this->addError('code', $e->getMessage());
        }
    }

    public function verify(): void
    {
        $method = $this->chosen();
        $code = $this->code;
        $this->code = '';

        if ($method === null) {
            return;
        }

        if (($tooLong = self::codeTooLong($code)) !== null) {
            $this->addError('code', $tooLong);

            return;
        }

        try {
            app(StaffMfa::class)->verify($this->user(), $method, $code);
        } catch (MfaException $e) {
            $this->addError('code', $e->getMessage());

            return;
        }

        $this->redirectIntended(Filament::getUrl());
    }

    /**
     * The message for a typed code longer than a code can be; null when it fits.
     */
    public static function codeTooLong(string $code): ?string
    {
        return mb_strlen($code) > self::CODE_INPUT_MAX_LENGTH
            ? __('The code may be at most :max characters long.', ['max' => self::CODE_INPUT_MAX_LENGTH])
            : null;
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
