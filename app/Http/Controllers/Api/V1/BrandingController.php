<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\SsoAccess;
use App\Auth\Passwordless\ClientPasswordlessLogin;
use App\Auth\Passwordless\LoginMessageCaps;
use App\Branding\Branding;
use App\Enums\PrincipalType;
use App\Http\Controllers\Controller;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Http\JsonResponse;

/**
 * Public branding and the login methods available on the client site.
 */
class BrandingController extends Controller
{
    /**
     * @response array{branding: array{app_name: string, logo_url: ?string, favicon_url: string, primary_color: string, palette: array<string, string>, tiers: array<string, array{background_image: ?string, login_background_image: ?string, badge_label: ?string, support: array{email: ?string, phone: ?string, hours: ?string}}>, portal: array<string, ?string>}, news_on_overview: bool, login_methods: array{magic_link: bool, otp_sms: bool, adfs: bool, entra: bool}, otp_sms: array{code_length: int, retry_after_seconds: int, max_per_hour: int, email_fallback: bool}, login_caps: array{magic_link: bool, otp_sms: bool}}
     */
    public function __invoke(Branding $branding, Settings $settings, ClientPasswordlessLogin $passwordless, SsoAccess $access): JsonResponse
    {
        return response()->json([
            'branding' => $branding->toArray(),
            'news_on_overview' => $settings->bool(SettingKey::PortalNewsOnOverview),
            'login_methods' => [
                'magic_link' => $passwordless->magicLinkEnabled(),
                'otp_sms' => $passwordless->otpSmsEnabled(),
                'adfs' => $access->isAvailable(PrincipalType::Client, OidcProvider::Adfs),
                'entra' => $access->isAvailable(PrincipalType::Client, OidcProvider::Entra),
            ],
            // How the login page paces the SMS code: one re-send after this
            // many seconds, at most this many codes an hour, then the e-mailed
            // link (when allowed) or support.
            'otp_sms' => [
                // The field asks for exactly this many digits.
                'code_length' => $passwordless->otpCodeLength(),
                'retry_after_seconds' => $passwordless->otpRetryAfterSeconds(),
                'max_per_hour' => $settings->int(SettingKey::ClientLoginOtpSmsMaxPerNumberPerHour),
                'email_fallback' => $passwordless->otpEmailFallbackEnabled(),
            ],
            // A system-wide limit of the anonymous login messages turns requests away now
            // (LoginMessageCaps): the login page says so to every visitor alike.
            'login_caps' => [
                'magic_link' => $passwordless->capped(LoginMessageCaps::CHANNEL_EMAIL),
                'otp_sms' => $passwordless->capped(LoginMessageCaps::CHANNEL_SMS),
            ],
        ]);
    }
}
