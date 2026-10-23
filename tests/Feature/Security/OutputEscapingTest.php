<?php

use App\Audit\Auditor;
use App\Auth\Role;
use App\Models\ApiKey;
use App\Models\Call;
use App\Models\Client;
use App\Models\MessageTemplate;
use App\Models\NewsPost;
use App\Models\OutboundMessage;
use App\Models\Question;
use App\Models\SyncRun;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Symfony\Component\Finder\Finder;

/**
 * Two layers against a future escaping mistake.
 *
 * 1. A static gate: every place that emits raw HTML (Blade `{!! !!}`,
 *    HtmlString, Vue `v-html`, markdown rendering, ...) is on a reviewed
 *    allowlist with its occurrence count; a new one fails the test until a
 *    reviewer adds it here, having checked that its input is escaped or
 *    sandboxed.
 * 2. A canary: a script tag planted in every free-text field the panel and
 *    the portal render must come out escaped on every page; the markdown
 *    body of the news keeps no script at all. CSV formula prefixes are
 *    covered by ClientExportTest ("exports imported formula shaped names").
 */
const XSS_CANARY = '<script>hdidXssCanary()</script>';

/**
 * Raw-HTML output sites and why each is safe (reviewed 2026-10-23).
 *
 * @var array<string, array<string, int>>
 */
const RAW_HTML_ALLOWLIST = [
    // The notification body wraps an e() escaped URL.
    'app/Filament/Actions/SendMagicLinkAction.php' => ['HtmlString' => 2],
    // The notification body wraps an e() escaped secret.
    'app/Filament/Admin/Resources/ApiKeys/ApiKeyResource.php' => ['HtmlString' => 2],
    // The notification body is built from translated literals and e() escaped values.
    'app/Filament/Admin/Resources/ApiKeys/Pages/ManageApiKeys.php' => ['HtmlString' => 2],
    // Name marks and the description are assembled from e() escaped values and static markup.
    'app/Filament/Admin/Resources/Clients/ClientResource.php' => ['HtmlString' => 3],
    // The preview renders text in an e() escaped <pre>, HTML in a sandboxed iframe (srcdoc e() escaped).
    'app/Filament/Admin/Resources/MessageTemplates/MessageTemplateResource.php' => ['HtmlString' => 4],
    // The stored e-mail body is shown in a sandboxed iframe whose srcdoc is e() escaped.
    'app/Filament/Admin/Resources/OutboundMessages/OutboundMessageResource.php' => ['HtmlString' => 2],
    // The exception text is e() escaped inside a <pre>.
    'app/Filament/Admin/Widgets/FailedJobsWidget.php' => ['HtmlString' => 3],
    // The brand CSS is built from parsed colours, the favicon partial escapes its URLs.
    'app/Filament/BrandedPanel.php' => ['HtmlString' => 5],
    // CommonMark with html_input=strip and allow_unsafe_links=false.
    'app/Models/NewsPost.php' => ['Str::markdown' => 1],
    // The HTML report is a Blade view with escaped output; "html" is the format method's name.
    'app/Reporting/ReportWriter.php' => ['->html(' => 1],
    // Renders the server-side stripped markdown of a news post.
    'resources/js/client/components/NewsList.vue' => ['v-html' => 1],
    // The message body was rendered by TemplateRenderer with escaped placeholder values.
    'resources/views/mail/layout.blade.php' => ['{!!' => 1],
];

const RAW_HTML_PATTERNS = ['{!!', 'HtmlString', '->html(', 'v-html', 'innerHTML', 'insertAdjacentHTML', 'document.write(', 'Str::markdown', '->markdown(', 'toHtmlString('];

it('keeps every raw-HTML output site on the reviewed allowlist', function (): void {
    $found = [];

    $files = Finder::create()->files()->in([base_path('app'), base_path('resources/views'), base_path('resources/js')])->name(['*.php', '*.vue', '*.js']);
    foreach ($files as $file) {
        $relative = str_replace(base_path().'/', '', $file->getRealPath());
        $contents = $file->getContents();

        foreach (RAW_HTML_PATTERNS as $pattern) {
            $count = substr_count($contents, $pattern);
            if ($count > 0) {
                $found[$relative][$pattern] = $count;
            }
        }
    }

    ksort($found);
    $allowed = RAW_HTML_ALLOWLIST;
    ksort($allowed);

    expect($found)->toBe($allowed, 'A raw-HTML output site appeared, moved or disappeared. Review it (escape with e(), sandbox it, or use {{ }}), then update RAW_HTML_ALLOWLIST in '.__FILE__.". Found:\n".json_encode($found, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

/**
 * One record of every kind with the canary in each free-text field.
 *
 * @return array{client: Client, user: User}
 */
function plantCanaries(): array
{
    $settings = app(Settings::class);
    $settings->set(SettingKey::ClientsDirectoryFields, ['costcenter' => XSS_CANARY]);
    $settings->set(SettingKey::PremiumBadgeLabel, XSS_CANARY);
    $settings->set(SettingKey::SupportPremiumPhone, XSS_CANARY);
    $settings->set(SettingKey::SupportPremiumEmail, 'support@x.hu');
    $settings->set(SettingKey::SupportPremiumHours, XSS_CANARY);
    $settings->set(SettingKey::PortalFooterText, XSS_CANARY);

    $user = User::factory()->synced()->withRole(Role::Agent)->create(['name' => XSS_CANARY]);
    $user->externalRecord->update(['company' => XSS_CANARY, 'title' => XSS_CANARY, 'department' => XSS_CANARY, 'login_name' => XSS_CANARY, 'room' => XSS_CANARY, 'employment_status' => XSS_CANARY]);

    $client = Client::factory()->synced()->premium()->create(['name' => XSS_CANARY, 'notes' => XSS_CANARY]);
    $client->externalRecord->update(['company' => XSS_CANARY, 'title' => XSS_CANARY, 'department' => XSS_CANARY, 'login_name' => XSS_CANARY, 'attributes' => ['costcenter' => XSS_CANARY]]);
    $client->phoneNumbers()->create(['number_e164' => '+36301234567', 'source' => 'admin', 'is_primary' => true, 'label' => XSS_CANARY]);

    NewsPost::factory()->create(['title' => XSS_CANARY, 'body' => 'News hdidXssCanary '.XSS_CANARY."\n\n<img src=x onerror=hdidXssCanary()>"]);
    MessageTemplate::query()->create(['key' => 'magic_link', 'locale' => 'hu', 'subject' => XSS_CANARY, 'body' => '<p>'.e(XSS_CANARY).'</p>']);
    OutboundMessage::factory()->failed()->create(['recipient' => 'r@x.hu', 'subject' => XSS_CANARY, 'body' => '<p>'.XSS_CANARY.'</p>', 'error' => XSS_CANARY]);
    OutboundMessage::factory()->sms()->create(['body' => XSS_CANARY]);
    Question::factory()->create();
    Question::query()->first()->currentVersion->update(['text' => XSS_CANARY, 'hint' => XSS_CANARY]);
    ApiKey::factory()->create(['name' => XSS_CANARY]);
    Call::factory()->create(['queue' => XSS_CANARY, 'caller_number_raw' => XSS_CANARY, 'client_id' => $client->id, 'agent_user_id' => $user->id]);
    SyncRun::factory()->create(['status' => 'failed', 'error' => XSS_CANARY, 'file_name' => XSS_CANARY.'.csv', 'triggered_by_user_id' => $user->id]);
    app(Auditor::class)->record('client.viewed', $client, ['note' => XSS_CANARY], $user);

    return ['client' => $client, 'user' => $user];
}

it('renders the canary escaped on every admin page it reaches', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    ['client' => $client, 'user' => $user] = plantCanaries();
    $admin = User::factory()->withRole(Role::SuperAdmin)->create();
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    $pages = ['', 'accounts/users', 'accounts/roles', 'clients', 'system/external-records', 'system/sync-runs', 'system/api-keys', 'system/status', 'questions', 'id-sessions', 'calls', 'system/audit-logs', 'system/settings', 'content/news-posts', 'content/message-templates', 'content/outbound-messages', 'verify-code', 'search-clients', 'reports', 'shared-phone-numbers', 'system/sync-changes', 'system/audit-archive', 'system/queues',
        'clients/'.$client->id, 'clients/'.$client->id.'/edit', 'accounts/users/'.$user->id.'/edit', 'content/message-templates/'.MessageTemplate::query()->first()->id.'/edit', 'content/news-posts', 'search-clients?search=hdidXssCanary'];
    $seenEscaped = 0;

    foreach ($pages as $path) {
        $response = $this->get('/admin/'.$path);
        $response->assertOk();
        $body = $response->getContent();

        expect($body)->not->toContain(XSS_CANARY, "Raw canary on /admin/{$path}");
        expect($body)->not->toContain('onerror=hdidXssCanary', "Raw canary attribute on /admin/{$path}");

        if (str_contains($body, e(XSS_CANARY))) {
            $seenEscaped++;
        }
    }

    // The canary did flow through the pages, escaped, so the gate is not vacuous.
    expect($seenEscaped)->toBeGreaterThanOrEqual(8);
});

it('renders the canary escaped on the portal and strips it from the news body', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    ['client' => $client] = plantCanaries();
    app(Settings::class)->set(SettingKey::PortalNewsOnOverview, true);

    $shell = $this->get('/premium/login');
    $shell->assertOk();
    expect($shell->getContent())->not->toContain(XSS_CANARY)->toContain(json_encode(XSS_CANARY, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT));

    $branding = $this->getJson('/api/v1/branding')->assertOk()->json();
    expect(json_encode($branding))->toContain('hdidXssCanary');

    $this->actingAs($client, 'client');
    $news = $this->getJson('/api/v1/client/news')->assertOk()->json('data.0');
    expect($news['title'])->toBe(XSS_CANARY)
        ->and($news['body_html'])->not->toContain('<script')->not->toContain('onerror')
        ->and($news['body_html'])->toContain('News hdidXssCanary');

    $me = $this->getJson('/api/v1/client/me')->assertOk()->json();
    expect($me['data']['name'])->toBe(XSS_CANARY);
});
