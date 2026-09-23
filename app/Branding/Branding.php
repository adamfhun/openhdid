<?php

namespace App\Branding;

use App\Clients\ClientTiers;
use App\Enums\ClientTier;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Illuminate\Support\Facades\Storage;

/**
 * Resolved brand values used by the staff panel and the client site.
 * The palette follows the configured scheme; the defaults are a neutral slate palette.
 * Images are stored on the public disk and resolved to URLs here.
 */
class Branding
{
    public const DEFAULT_FAVICON = 'favicon.svg';

    public const DEFAULT_TOUCH_ICON = 'apple-touch-icon.png';

    public function __construct(
        private readonly Settings $settings,
        private readonly ClientTiers $tiers,
    ) {}

    public function appName(): string
    {
        return $this->settings->string(SettingKey::BrandingAppName) ?? config('app.name');
    }

    public function logoUrl(): ?string
    {
        return $this->imageUrl(SettingKey::BrandingLogo);
    }

    /**
     * The uploaded icon wins; without one the shipped default is used, so a
     * fresh install is not left with the browser's blank page icon.
     */
    public function faviconUrl(): string
    {
        return $this->imageUrl(SettingKey::BrandingFavicon) ?? asset(self::DEFAULT_FAVICON);
    }

    public function hasCustomFavicon(): bool
    {
        return $this->imageUrl(SettingKey::BrandingFavicon) !== null;
    }

    public function adminLoginBackgroundUrl(): ?string
    {
        return $this->imageUrl(SettingKey::BrandingAdminLoginBackgroundImage);
    }

    public function primaryColor(): string
    {
        return $this->color(SettingKey::BrandingPrimaryColor);
    }

    public function accentColor(): string
    {
        return $this->color(SettingKey::BrandingAccentColor);
    }

    public function backgroundColor(): string
    {
        return $this->color(SettingKey::BrandingBackgroundColor);
    }

    public function surfaceColor(): string
    {
        return $this->color(SettingKey::BrandingSurfaceColor);
    }

    public function textColor(): string
    {
        return $this->color(SettingKey::BrandingTextColor);
    }

    public function mutedTextColor(): string
    {
        return $this->color(SettingKey::BrandingMutedTextColor);
    }

    public function premiumAccentColor(): string
    {
        return $this->color(SettingKey::PremiumAccentColor);
    }

    /**
     * @return array<string, string>
     */
    public function palette(): array
    {
        return [
            'primary' => $this->primaryColor(),
            'accent' => $this->accentColor(),
            'background' => $this->backgroundColor(),
            'surface' => $this->surfaceColor(),
            'text' => $this->textColor(),
            'muted' => $this->mutedTextColor(),
            'premium' => $this->premiumAccentColor(),
        ];
    }

    /**
     * CSS custom properties for the palette, usable in any stylesheet.
     */
    public function cssVariables(): string
    {
        return collect($this->palette())->map(fn (string $value, string $name) => "--brand-{$name}: {$value};")->implode(' ');
    }

    /**
     * Per-tier look of the client site.
     *
     * @return array<string, array{background_image: ?string, login_background_image: ?string, badge_label: ?string, support: array{email: ?string, phone: ?string, hours: ?string}}>
     */
    public function tiers(): array
    {
        return [
            ClientTier::Standard->value => [
                'background_image' => $this->imageUrl(SettingKey::BrandingBackgroundImage),
                'login_background_image' => $this->imageUrl(SettingKey::BrandingLoginBackgroundImage),
                'badge_label' => null,
                'support' => $this->tiers->supportFor(ClientTier::Standard),
            ],
            ClientTier::Premium->value => [
                'background_image' => $this->imageUrl(SettingKey::PremiumBackgroundImage) ?? $this->imageUrl(SettingKey::BrandingBackgroundImage),
                'login_background_image' => $this->imageUrl(SettingKey::PremiumLoginBackgroundImage) ?? $this->imageUrl(SettingKey::BrandingLoginBackgroundImage),
                'badge_label' => $this->settings->string(SettingKey::PremiumBadgeLabel),
                'support' => $this->tiers->supportFor(ClientTier::Premium),
            ],
        ];
    }

    /**
     * The source link is a deployment value, not a setting: it points at the code the build was made from.
     *
     * @return array{footer_text: ?string, legal_name: ?string, privacy_url: ?string, terms_url: ?string, imprint_url: ?string, source_url: ?string}
     */
    public function portal(): array
    {
        return [
            'footer_text' => $this->settings->string(SettingKey::PortalFooterText),
            'legal_name' => $this->settings->string(SettingKey::PortalLegalName),
            'privacy_url' => $this->settings->string(SettingKey::PortalPrivacyUrl),
            'terms_url' => $this->settings->string(SettingKey::PortalTermsUrl),
            'imprint_url' => $this->settings->string(SettingKey::PortalImprintUrl),
            'source_url' => config('hdid.source_url'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'app_name' => $this->appName(),
            'logo_url' => $this->logoUrl(),
            'favicon_url' => $this->faviconUrl(),
            'primary_color' => $this->primaryColor(),
            'palette' => $this->palette(),
            'tiers' => $this->tiers(),
            'portal' => $this->portal(),
        ];
    }

    /**
     * A stored image setting as a public URL. Absolute URLs pass through so
     * that an externally hosted asset still works.
     */
    public function imageUrl(SettingKey $key): ?string
    {
        $value = $this->settings->string($key);

        if ($value === null) {
            return null;
        }

        return preg_match('#^(https?:)?//#i', $value) ? $value : Storage::disk('public')->url($value);
    }

    private function color(SettingKey $key): string
    {
        $value = $this->settings->string($key);

        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) ? $value : (string) $key->default();
    }
}
