<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Admin\Resources\MessageTemplates\Pages\EditMessageTemplate;
use App\Mail\TemplatedMail;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Messaging\TemplateRenderer;
use App\Models\Client;
use App\Models\MessageTemplate;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sms\FakeSmsSender;
use App\Sms\SmsSender;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('renders the code default with placeholders substituted and escaped', function (): void {
    $rendered = app(TemplateRenderer::class)->render(MessageKey::MagicLink, 'hu', [
        'name' => 'Anna <b>x</b>', 'link' => 'https://x.hu/l?a=1&b=2', 'days' => '3', 'app_name' => 'HDID',
    ]);

    expect($rendered->subject)->toBe('Belépési hivatkozás – HDID')
        ->and($rendered->body)->toContain('Kedves Anna &lt;b&gt;x&lt;/b&gt;!')
        ->and($rendered->body)->toContain('href="https://x.hu/l?a=1&amp;b=2"')
        ->and($rendered->body)->toContain('<!doctype html>')
        ->and($rendered->body)->not->toContain('{{');

    // SMS templates exist in Hungarian only; any other language falls back to it.
    $sms = app(TemplateRenderer::class)->render(MessageKey::PinSms, 'en', ['pin' => '4321', 'app_name' => 'HDID']);
    expect($sms->subject)->toBeNull()->and($sms->body)->toBe('HDID: az új azonosító PIN-kódja: 4321. Ne ossza meg senkivel.');
});

it('wraps e-mails in the configured brand palette instead of fixed colours', function (): void {
    $settings = app(Settings::class);
    $settings->set(SettingKey::BrandingPrimaryColor, '#123456');
    $settings->set(SettingKey::BrandingBackgroundColor, '#ABCDEF');
    $settings->set(SettingKey::BrandingSurfaceColor, '#FEFEFE');
    $settings->set(SettingKey::BrandingTextColor, '#101010');
    $settings->set(SettingKey::BrandingMutedTextColor, '#707070');

    $body = app(TemplateRenderer::class)->render(MessageKey::MagicLink, 'hu', [
        'name' => 'Anna', 'link' => 'https://x.hu/l', 'days' => '3', 'app_name' => 'HDID',
    ])->body;

    expect($body)->toContain('background:#123456', 'background:#ABCDEF', 'background:#FEFEFE', 'color:#101010', 'color:#707070')
        ->and($body)->not->toContain('#f4f5f6', '#1e252d', '#5e6975');
});

it('prefers a stored override and tolerates spacing in placeholders', function (): void {
    MessageTemplate::query()->create(['key' => 'pin_sms', 'locale' => 'hu', 'body' => 'PIN:{{pin}} / {{  name  }} / {{ missing }}']);

    $rendered = app(TemplateRenderer::class)->render(MessageKey::PinSms, 'hu', ['pin' => '9', 'name' => 'B']);
    expect($rendered->body)->toBe('PIN:9 / B / ');
});

it('rejects unknown placeholders', function (): void {
    expect(fn () => app(TemplateRenderer::class)->assertPlaceholdersKnown(MessageKey::PinSms, 'Hi {{ name }} {{ link }}'))
        ->toThrow(ValidationException::class);

    app(TemplateRenderer::class)->assertPlaceholdersKnown(MessageKey::PinSms, 'Hi {{ name }} {{ pin }}');
});

it('lets an admin edit, preview, test-send and reset a template', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $admin = User::factory()->withRole(Role::Admin)->create(['email' => 'admin@x.hu']);
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $sms);

    $this->get('/admin/content/message-templates')->assertOk();
    expect(MessageTemplate::query()->count())->toBe(collect(MessageKey::cases())->sum(fn (MessageKey $key) => count($key->locales())))
        ->and(MessageTemplate::query()->where('key', 'pin_sms')->pluck('locale')->all())->toBe(['hu']);

    $template = MessageTemplate::query()->where('key', 'pin_sms')->where('locale', 'hu')->first();

    Livewire::test(EditMessageTemplate::class, ['record' => $template->id])
        ->fillForm(['body' => 'Rossz {{ link }}'])
        ->call('save')
        ->assertHasFormErrors(['body']);

    Livewire::test(EditMessageTemplate::class, ['record' => $template->id])
        ->fillForm(['body' => 'Új PIN: {{ pin }}'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->callAction('sendTest', ['phone' => '+36301234567']);

    expect($template->fresh()->isCustomised())->toBeTrue()
        ->and($template->fresh()->updated_by_user_id)->toBe($admin->id)
        ->and($sms->lastTo('+36301234567'))->toBe('Új PIN: 1234')
        ->and(OutboundMessage::query()->count())->toBe(1);

    // A landline receives no SMS: the test send says it did not go, not that it was queued.
    Livewire::test(EditMessageTemplate::class, ['record' => $template->id])
        ->callAction('sendTest', ['phone' => '+3612345678'])
        ->assertNotified(__('Test message not sent to :to', ['to' => '+3612345678']));
    expect($sms->lastTo('+3612345678'))->toBeNull();

    Livewire::test(EditMessageTemplate::class, ['record' => $template->id])
        ->callAction('resetToDefault');
    expect($template->fresh()->isCustomised())->toBeFalse();

    $email = MessageTemplate::query()->where('key', 'magic_link')->where('locale', 'en')->first();
    Livewire::test(EditMessageTemplate::class, ['record' => $email->id])
        ->callAction('sendTest', ['email' => 'admin@x.hu']);
    Mail::assertSent(TemplatedMail::class, fn ($mail) => $mail->hasTo('admin@x.hu') && str_starts_with($mail->subjectLine, '[TEST]'));

    expect(MessageTemplateResource::smsLengthHint('Árvíztűrő'))->toContain('UCS-2')
        ->and(MessageTemplateResource::smsLengthHint('plain'))->toContain('GSM-7');
});

it('stores the largest allowed template inside the layout, and its subject cut to the column', function (): void {
    Queue::fake();
    $html = '<p>'.str_repeat('x', MessageTemplateResource::STORED_BODY_MAX_BYTES - 40).' {{ link }}</p>';
    MessageTemplate::query()->create(['key' => 'magic_link', 'locale' => 'hu', 'subject' => str_repeat('S', 200).' {{ name }}', 'body' => $html]);
    $client = Client::factory()->synced()->create(['name' => str_repeat('N', 255), 'locale' => 'hu']);

    $message = app(Messenger::class)->sendTemplate(MessageKey::MagicLink, $client, ['link' => 'https://hdid.test/l', 'minutes' => '720', 'hours' => '12', 'days' => '1'], $client->email);
    $stored = OutboundMessage::query()->findOrFail($message->id);

    // MariaDB strict mode refuses what does not fit: TEXT holds 65 535 bytes, MEDIUMTEXT 16 777 215.
    expect(strlen((string) $stored->body))->toBeGreaterThan(65_535, 'the layout makes it longer than a TEXT column')
        ->and(strlen((string) $stored->body))->toBeLessThan(16_777_215)
        ->and(mb_strlen((string) $stored->subject))->toBeLessThanOrEqual(255);
});

it('says in the login link e-mail that a newer request makes the link stop working', function (): void {
    expect(MessageKey::MagicLink->defaultBody('hu'))->toContain('Ha közben újabb belépési hivatkozást kér (vagy valaki az Ön e-mail-címével kér egyet), ez a hivatkozás érvényét veszti; mindig a legutóbbi levelet használja.')
        ->and(MessageKey::MagicLink->defaultBody('en'))->toContain('If a newer login link is requested in the meantime (by you, or by someone with your e-mail address), this link stops working; always use the latest e-mail.');
});

it('treats a template test send like the real message: its content is wiped once it is out, and the mail keeps no saved copy', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create(['email' => 'admin@x.hu']));
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $sms = new FakeSmsSender;
    app()->instance(SmsSender::class, $sms);
    // The list page creates the template rows.
    $this->get('/admin/content/message-templates')->assertOk();

    $pin = MessageTemplate::query()->where('key', 'pin_sms')->where('locale', 'hu')->first();
    Livewire::test(EditMessageTemplate::class, ['record' => $pin->id])
        ->callAction('sendTest', ['phone' => '+36301234567'])
        ->assertNotified(__('Test message queued to :to', ['to' => '+36301234567']));

    $message = OutboundMessage::query()->latest('id')->first();
    expect($sms->lastTo('+36301234567'))->not->toBeNull()
        ->and($message->isRedacted())->toBeTrue()
        ->and($message->body)->toBe('');

    $email = MessageTemplate::query()->where('key', 'magic_link')->where('locale', 'en')->first();
    Livewire::test(EditMessageTemplate::class, ['record' => $email->id])
        ->callAction('sendTest', ['email' => 'admin@x.hu']);
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail): bool => $mail->hasTo('admin@x.hu') && $mail->carriesSecret === true);
});

it('refuses a secret placeholder in the subject, which stays readable after the message is sent', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->withRole(Role::Admin)->create());
    Filament::setCurrentPanel('admin');
    $this->get('/admin/content/message-templates')->assertOk();
    $template = MessageTemplate::query()->where('key', 'magic_link')->where('locale', 'hu')->firstOrFail();

    Livewire::test(EditMessageTemplate::class, ['record' => $template->id])
        ->fillForm(['subject' => 'Belépés: {{ link }}'])
        ->call('save')
        ->assertHasFormErrors(['subject']);

    // The body may carry it, and the subject the placeholders that are no secret.
    Livewire::test(EditMessageTemplate::class, ['record' => $template->id])
        ->fillForm(['subject' => '{{ app_name }}: belépés {{ minutes }} percig', 'body' => '<p><a href="{{ link }}">Belépés</a></p>'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(MessageKey::MagicLink->subjectPlaceholders())->not->toHaveKey('link')->toHaveKeys(['name', 'minutes', 'app_name'])
        ->and(array_keys(MessageKey::MagicLink->placeholders()))->toContain('link')
        ->and(fn () => app(TemplateRenderer::class)->assertPlaceholdersKnown(MessageKey::MagicLink, 'x {{ link }}', 'subject'))->toThrow(ValidationException::class);
});

it('never puts the login link into the stored subject, also with a template saved before the rule', function (): void {
    Queue::fake();
    // As an older release could store it: the link placeholder in the subject.
    MessageTemplate::query()->create(['key' => 'magic_link', 'locale' => 'hu', 'subject' => 'Belépés: {{ link }} ({{ name }})', 'body' => '<p>{{ link }}</p>']);
    $client = Client::factory()->create(['name' => 'Kiss Éva', 'locale' => 'hu']);
    $link = 'https://hdid.example.org/auth/client/magic/secret-token?signature=abc';

    $message = app(Messenger::class)->sendTemplate(MessageKey::MagicLink, $client, ['link' => $link, 'minutes' => 720, 'hours' => 12, 'days' => 1], 'eva@x.hu');

    expect($message->subject)->not->toContain('secret-token')->not->toContain('://')
        ->and($message->subject)->toBe('Belépés:  (Kiss Éva)')
        ->and($message->body)->toContain('secret-token');

    $message->redactSecret();
    expect(json_encode($message->fresh()->getAttributes()))->not->toContain('secret-token');
});

it('clears the login links already stored in subjects and the template subjects that put them there', function (): void {
    $template = MessageTemplate::query()->create(['key' => 'magic_link', 'locale' => 'hu', 'subject' => 'Belépés: {{ link }}', 'body' => '<p>{{ link }}</p>']);
    $plain = MessageTemplate::query()->create(['key' => 'magic_link', 'locale' => 'en', 'subject' => 'Sign in to {{ app_name }}', 'body' => '<p>{{ link }}</p>']);
    $leaked = OutboundMessage::factory()->sent()->create(['template_key' => MessageKey::MagicLink, 'subject' => 'Belépés: https://hdid.example.org/auth/client/magic/t0ken?signature=abc', 'body' => '', 'redacted_at' => now()]);
    $clean = OutboundMessage::factory()->sent()->create(['template_key' => MessageKey::MagicLink, 'subject' => 'Belépés a portálra', 'body' => '', 'redacted_at' => now()]);
    $other = OutboundMessage::factory()->sent()->create(['template_key' => null, 'subject' => 'Lásd: https://example.org/doc']);

    $migration = require database_path('migrations/2026_11_18_100001_clear_login_links_from_message_subjects.php');
    $migration->up();
    $migration->up();

    expect($leaked->fresh()->subject)->toBeNull()
        ->and($clean->fresh()->subject)->toBe('Belépés a portálra')
        ->and($other->fresh()->subject)->toBe('Lásd: https://example.org/doc')
        ->and($template->fresh()->subject)->toBeNull()
        ->and($template->fresh()->body)->toBe('<p>{{ link }}</p>')
        ->and($plain->fresh()->subject)->toBe('Sign in to {{ app_name }}');
});
