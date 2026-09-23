<?php

use App\Auth\Role;
use App\Filament\Admin\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Admin\Resources\MessageTemplates\Pages\EditMessageTemplate;
use App\Mail\TemplatedMail;
use App\Messaging\MessageKey;
use App\Messaging\TemplateRenderer;
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
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('renders the code default with placeholders substituted and escaped', function (): void {
    $rendered = app(TemplateRenderer::class)->render(MessageKey::MagicLink, 'hu', [
        'name' => 'Anna <b>x</b>', 'link' => 'https://x.hu/l?a=1&b=2', 'days' => '3', 'app_name' => 'HDID',
    ]);

    expect($rendered->subject)->toBe('Belépési link – HDID')
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
