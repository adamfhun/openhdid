<?php

namespace App\Messaging;

use App\Localization\SetLocale;

/**
 * Every message the system sends to clients. The key knows its channel, the
 * placeholders it accepts and a code default per language; administrators
 * override subject and body in the panel.
 */
enum MessageKey: string
{
    case MagicLink = 'magic_link';
    case PinSms = 'pin_sms';
    case LoginOtpSms = 'login_otp_sms';
    case PhoneVerifySms = 'phone_verify_sms';

    public function channel(): Channel
    {
        return match ($this) {
            self::MagicLink => Channel::Email,
            self::PinSms, self::LoginOtpSms, self::PhoneVerifySms => Channel::Sms,
        };
    }

    /**
     * Whether the rendered message carries a secret (PIN, login code, login
     * link). Such bodies are wiped once the message left the system and are
     * never shown in the panel. Every key does today; new keys must opt out
     * explicitly.
     */
    public function carriesSecret(): bool
    {
        return true;
    }

    public function label(): string
    {
        return match ($this) {
            self::MagicLink => __('Login link e-mail'),
            self::PinSms => __('New PIN SMS'),
            self::LoginOtpSms => __('Login code SMS'),
            self::PhoneVerifySms => __('Phone number verification SMS'),
        };
    }

    /**
     * Placeholders shared by every message.
     *
     * @return array<string, string>
     */
    public static function commonPlaceholders(): array
    {
        return [
            'name' => __('Name of the client'),
            'email' => __('E-mail address of the client'),
            'app_name' => __('Name of the application'),
            'support_email' => __('Support e-mail for the client\'s package'),
            'support_phone' => __('Support phone for the client\'s package'),
            'support_hours' => __('Support opening hours for the client\'s package'),
        ];
    }

    /**
     * Allowed placeholders with a description, common ones included.
     *
     * @return array<string, string>
     */
    public function placeholders(): array
    {
        $own = match ($this) {
            self::MagicLink => ['link' => __('The one-time login link'), 'minutes' => __('Validity of the link in minutes'), 'days' => __('Validity of the link in days (rounded)')],
            self::PinSms => ['pin' => __('The new PIN')],
            self::LoginOtpSms => ['code' => __('The login code'), 'minutes' => __('Validity of the code in minutes')],
            self::PhoneVerifySms => ['code' => __('The verification code'), 'minutes' => __('Validity of the code in minutes')],
        };

        return $own + self::commonPlaceholders();
    }

    /**
     * Sample values for previews and test sends.
     *
     * @return array<string, string>
     */
    public function sampleData(): array
    {
        return [
            'name' => 'Kovács Anna',
            'email' => 'anna@example.com',
            'app_name' => config('app.name'),
            'support_email' => 'help@example.com',
            'support_phone' => '+36 1 234 5678',
            'support_hours' => 'H-P 8:00-17:00',
            'link' => url('/auth/client/magic/example'),
            'minutes' => '4320',
            'days' => '3',
            'pin' => '1234',
            'code' => '123456',
        ];
    }

    /**
     * SMS go out in Hungarian only (one template per key); e-mails follow
     * the client's language.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->channel() === Channel::Sms ? ['hu'] : SetLocale::SUPPORTED;
    }

    public function locale(string $preferred): string
    {
        return in_array($preferred, $this->locales(), true) ? $preferred : $this->locales()[0];
    }

    public function defaultSubject(string $locale): ?string
    {
        if ($this->channel() === Channel::Sms) {
            return null;
        }

        return match ([$this, $locale]) {
            [self::MagicLink, 'hu'] => 'Belépési link – {{ app_name }}',
            default => 'Your {{ app_name }} login link',
        };
    }

    public function defaultBody(string $locale): string
    {
        return match ([$this, $this->locale($locale)]) {
            [self::MagicLink, 'hu'] => <<<'HTML'
<h2>Kedves {{ name }}!</h2>
<p>Az alábbi gombra kattintva beléphet a(z) {{ app_name }} ügyfélfelületére. A link {{ days }} napig érvényes, és egyszer használható fel.</p>
<p><a class="button" href="{{ link }}">Belépés</a></p>
<p>Ha a gomb nem működik, másolja be ezt a címet a böngészőbe:<br>{{ link }}</p>
<p>Ha nem Ön kérte a belépést, ezt a levelet figyelmen kívül hagyhatja.</p>
<p>Kérdés esetén ügyfélszolgálatunk elérhető: {{ support_phone }} · {{ support_email }} ({{ support_hours }})</p>
HTML,
            [self::MagicLink, 'en'] => <<<'HTML'
<h2>Hello {{ name }},</h2>
<p>Use the button below to sign in to {{ app_name }}. The link is valid for {{ days }} days and can be used once.</p>
<p><a class="button" href="{{ link }}">Sign in</a></p>
<p>If the button does not work, copy this address into your browser:<br>{{ link }}</p>
<p>If you did not request this, you can ignore this e-mail.</p>
<p>Questions? Our support is available at {{ support_phone }} · {{ support_email }} ({{ support_hours }})</p>
HTML,
            [self::PinSms, 'hu'] => '{{ app_name }}: az új azonosító PIN-kódja: {{ pin }}. Ne ossza meg senkivel.',
            [self::PhoneVerifySms, 'hu'] => '{{ app_name }}: a telefonszám megerősítő kódja: {{ code }} ({{ minutes }} percig érvényes).',
            [self::LoginOtpSms, 'hu'] => '{{ app_name }} belépési kód: {{ code }} ({{ minutes }} percig érvényes).',
        };
    }
}
