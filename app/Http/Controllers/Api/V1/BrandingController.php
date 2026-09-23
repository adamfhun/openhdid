<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\Oidc\OidcProvider;
use App\Auth\Oidc\SsoAccess;
use App\Auth\Passwordless\ClientPasswordlessLogin;
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
     * @response array{branding: array{app_name: string, logo_url: ?string, favicon_url: string, primary_color: string, palette: array<string, string>, tiers: array<string, array{background_image: ?string, login_background_image: ?string, badge_label: ?string, support: array{email: ?string, phone: ?string, hours: ?string}}>, portal: array<string, ?string>}, news_on_overview: bool, login_methods: array{magic_link: bool, otp_sms: bool, adfs: bool, entra: bool}}
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
        ]);
    }
}
