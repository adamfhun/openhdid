<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\System\FilePermissions;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Request;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Portal access is package-gated; factories give clients the "Premium" package.
        app(Settings::class)->set(SettingKey::PackagesPremium, ['Premium']);
        app(Settings::class)->set(SettingKey::PackagesStandard, ['Basic']);
        // The file permission tile would read the checkout's own files, whose modes depend on
        // the machine; a test that is about it hands over files of its own.
        app()->instance(FilePermissions::class, new FilePermissions([]));
        // The trusted host list is static on the request class: a test that ran a request
        // as production (TrustHosts) would leave it set for every later test.
        Request::setTrustedHosts([]);
    })
    ->in('Feature');

/**
 * Press the button on the login link's landing page: the token sits in the
 * link's fragment and is posted, as the portal does.
 */
function signInWithLink(string $url): TestResponse
{
    preg_match('/#token=([A-Za-z0-9]+)$/', $url, $match);

    return test()->postJson('/api/v1/client/auth/magic-link/consume', ['token' => $match[1] ?? '']);
}

/**
 * A machine request signed the way the partner documentation describes:
 * a JSON body for POST, the data in the query string for GET.
 *
 * @param  array<string, mixed>  $data
 */
function signedMachineCall(string $key, string $secret, string $method, string $uri, array $data = []): TestResponse
{
    $method = strtoupper($method);
    $body = $method === 'GET' ? '' : json_encode($data, JSON_THROW_ON_ERROR);

    if ($method === 'GET' && $data !== []) {
        $uri .= (str_contains($uri, '?') ? '&' : '?').http_build_query($data);
    }

    $timestamp = time();

    return test()->call($method, $uri, [], [], [], [
        'HTTP_X_API_KEY' => $key,
        'HTTP_X_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SIGNATURE' => hash_hmac('sha256', AuthenticateApiKey::signedPayload($timestamp, $method, $uri, $body), $secret),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body);
}

/**
 * A file of the container release: under deploy/openhdid/repo in the main
 * tree, at the root of the published OpenHDID tree. Null when neither has it.
 */
function openhdidReleaseFile(string $path): ?string
{
    foreach ([base_path('deploy/openhdid/repo/'.$path), base_path($path)] as $candidate) {
        if (is_file($candidate)) {
            return (string) file_get_contents($candidate);
        }
    }

    return null;
}

/**
 * The indented block under a two-space key of a top-level YAML section
 * ("services" or "configs"), without parsing YAML (no YAML parser ships
 * with the release).
 */
function composeBlock(string $compose, string $section, string $name): string
{
    preg_match('/^'.$section.':\n(.*?)(?=^\S|\z)/ms', $compose, $sectionMatch);
    preg_match('/^  '.preg_quote($name, '/').':\n((?:(?:    .*)?\n)+)/m', $sectionMatch[1] ?? '', $blockMatch);

    return $blockMatch[1] ?? '';
}

/**
 * Markup that Filament's HTML sanitizer keeps (an outside link with a style
 * that covers the screen, an outside image): planted in a name, it must come
 * out of every sink as text (OutputEscapingTest, NotificationEscapingTest).
 */
const XSS_SURVIVOR_CANARY = '<a href="https://hdid-canary.example/" style="position:fixed;inset:0">hdidLinkCanary</a><img src="https://hdid-canary.example/p.png">';

/**
 * Every Filament notification sent so far, drawn the way the panel draws it:
 * the title and the body go through Filament's HTML sanitizer.
 */
function renderedNotifications(): string
{
    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();

    return collect($mailbox->pull())
        ->map(fn (array $notification): string => Notification::fromArray($notification)->toEmbeddedHtml())
        ->implode("\n");
}

/**
 * The surviving canary came out as text: no live outside link or image, but
 * its marker is there, so the check cannot pass on an empty notification.
 */
function expectCanaryAsText(string $html, string $marker = 'hdidLinkCanary'): void
{
    expect($html)
        ->not->toContain('href="https://hdid-canary.example')
        ->not->toContain('<img')
        ->toContain($marker);
}

/**
 * The EWS settings as config/hdid.php builds them with the given environment.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function ewsConfigWithEnv(array $env): array
{
    $saved = [];
    foreach ($env as $key => $value) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    try {
        return (require config_path('hdid.php'))['ews'];
    } finally {
        foreach ($saved as $key => [$envValue, $serverValue]) {
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }
            if ($serverValue === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $serverValue;
            }
        }
    }
}
