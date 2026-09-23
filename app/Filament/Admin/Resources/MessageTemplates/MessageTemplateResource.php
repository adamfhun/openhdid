<?php

namespace App\Filament\Admin\Resources\MessageTemplates;

use App\Filament\Admin\Clusters\Content;
use App\Filament\Admin\Resources\BaseResource;
use App\Filament\Admin\Resources\MessageTemplates\Pages\EditMessageTemplate;
use App\Filament\Admin\Resources\MessageTemplates\Pages\ListMessageTemplates;
use App\Localization\SetLocale;
use App\Messaging\Channel;
use App\Messaging\MessageKey;
use App\Messaging\TemplateRenderer;
use App\Models\MessageTemplate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Subject and body of every e-mail and SMS the system sends, per language.
 * A row exists for each key and language; an empty body means the code
 * default is used.
 */
class MessageTemplateResource extends BaseResource
{
    protected static ?string $model = MessageTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static ?string $cluster = Content::class;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('message template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Message templates');
    }

    public static function getNavigationLabel(): string
    {
        return __('Message templates');
    }

    /**
     * Make sure every key has a row per supported language so the list is
     * complete, and drop rows in languages a key no longer offers.
     */
    public static function ensureRows(): void
    {
        foreach (MessageKey::cases() as $key) {
            foreach ($key->locales() as $locale) {
                MessageTemplate::query()->firstOrCreate(['key' => $key->value, 'locale' => $locale]);
            }

            MessageTemplate::query()->where('key', $key->value)->whereNotIn('locale', $key->locales())->delete();
        }
    }

    public static function form(Schema $schema): Schema
    {
        /** @var MessageTemplate|null $record */
        $record = $schema->getRecord();
        $isEmail = $record?->key->channel() === Channel::Email;

        return $schema->components([
            Section::make(fn (MessageTemplate $record) => $record->key->label().' · '.strtoupper($record->locale))
                ->description(fn (MessageTemplate $record) => $record->key->channel() === Channel::Email
                    ? __('HTML e-mail. It is placed inside the branded layout; use the "button" class on a link to get a button.')
                    : __('Plain text SMS. 160 characters fit in one message with plain letters, 70 with accented ones.'))
                ->schema([
                    TextInput::make('subject')->label(__('Subject'))->maxLength(255)
                        ->visible(fn (MessageTemplate $record) => $record->key->channel() === Channel::Email)
                        ->placeholder(fn (MessageTemplate $record) => $record->key->defaultSubject($record->locale))
                        ->rule(fn (MessageTemplate $record) => fn (string $attribute, mixed $value, \Closure $fail) => static::checkPlaceholders($record->key, $value, 'subject', $fail))
                        ->live(onBlur: true),
                    $isEmail ? RichEditor::make('body')->label(__('Body'))
                        ->toolbarButtons(['bold', 'italic', 'underline', 'link', 'h2', 'h3', 'bulletList', 'orderedList', 'blockquote', 'undo', 'redo'])
                        ->rule(fn (MessageTemplate $record) => fn (string $attribute, mixed $value, \Closure $fail) => static::checkPlaceholders($record->key, $value, 'body', $fail))
                        ->live(onBlur: true)
                        ->columnSpanFull()
                    : Textarea::make('body')->label(__('Body'))->rows(4)
                        ->placeholder(fn (MessageTemplate $record) => $record->key->defaultBody($record->locale))
                        ->rule(fn (MessageTemplate $record) => fn (string $attribute, mixed $value, \Closure $fail) => static::checkPlaceholders($record->key, $value, 'body', $fail))
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get, MessageTemplate $record) => static::smsLengthHint((string) ($get('body') ?: $record->key->defaultBody($record->locale))))
                        ->columnSpanFull(),
                    View::make('filament.admin.template-placeholders')
                        ->viewData(fn (MessageTemplate $record) => ['placeholders' => $record->key->placeholders()])
                        ->columnSpanFull(),
                ]),
            Section::make(__('Preview'))
                ->description(__('Rendered with sample data. Leave the body empty to fall back to the built-in default.'))
                ->schema([
                    Html::make(fn (Get $get, MessageTemplate $record) => static::preview($record, $get('subject'), $get('body')))->columnSpanFull(),
                ])
                ->collapsible(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label(__('Message'))->formatStateUsing(fn (MessageKey $state) => $state->label())->weight('semibold')->sortable(),
                TextColumn::make('channel')->label(__('Channel'))->badge()->state(fn (MessageTemplate $record) => $record->key->channel()->label()),
                TextColumn::make('locale')->label(__('Language'))->badge()->color('gray')->formatStateUsing(fn (string $state) => strtoupper($state))->sortable(),
                TextColumn::make('customised')->label(__('Source'))->badge()
                    ->state(fn (MessageTemplate $record) => $record->isCustomised() ? __('Customised') : __('Default'))
                    ->color(fn (MessageTemplate $record) => $record->isCustomised() ? 'info' : 'gray'),
                TextColumn::make('updatedBy.name')->label(__('Last edited by'))->placeholder('-'),
                TextColumn::make('updated_at')->label(__('Updated'))->since(),
            ])
            ->filters([
                SelectFilter::make('locale')->label(__('Language'))->options(array_combine(SetLocale::SUPPORTED, array_map('strtoupper', SetLocale::SUPPORTED))),
                SelectFilter::make('key')->label(__('Message'))->options(collect(MessageKey::cases())->mapWithKeys(fn (MessageKey $k) => [$k->value => $k->label()])->all()),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMessageTemplates::route('/'),
            'edit' => EditMessageTemplate::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('updatedBy');
    }

    public static function checkPlaceholders(MessageKey $key, mixed $value, string $field, \Closure $fail): void
    {
        try {
            app(TemplateRenderer::class)->assertPlaceholdersKnown($key, is_string($value) ? $value : null, $field);
        } catch (ValidationException $e) {
            $fail($e->errors()[$field][0]);
        }
    }

    public static function smsLengthHint(string $body): string
    {
        $length = mb_strlen($body);
        $unicode = (bool) preg_match('/[^\x00-\x7F]/u', $body);
        $perPart = $unicode ? 70 : 160;
        $parts = max(1, (int) ceil($length / $perPart));

        return __(':n characters (:enc), about :p SMS part(s) after placeholders are filled in', ['n' => $length, 'enc' => $unicode ? 'UCS-2' : 'GSM-7', 'p' => $parts]);
    }

    public static function preview(MessageTemplate $record, mixed $subject, mixed $body): HtmlString
    {
        $renderer = app(TemplateRenderer::class);
        $key = $record->key;
        $data = $key->sampleData();
        $bodyText = is_string($body) && trim(strip_tags($body)) !== '' ? $body : $key->defaultBody($record->locale);
        $subjectText = is_string($subject) && $subject !== '' ? $subject : $key->defaultSubject($record->locale);

        if ($key->channel() === Channel::Sms) {
            return new HtmlString('<pre class="whitespace-pre-wrap rounded-lg bg-gray-50 p-4 text-sm dark:bg-white/5">'.e($renderer->substitute($bodyText, $data, html: false)).'</pre>');
        }

        $html = $renderer->wrap($renderer->substitute($bodyText, $data, html: true), $subjectText === null ? null : $renderer->substitute($subjectText, $data, html: false));

        return new HtmlString(
            '<div class="mb-2 text-sm"><span class="text-gray-500">'.e(__('Subject')).':</span> <strong>'.e($subjectText === null ? '' : $renderer->substitute($subjectText, $data, html: false)).'</strong></div>'
            .'<iframe title="preview" sandbox="" class="w-full rounded-lg border border-gray-200 bg-white dark:border-white/10" style="height: 520px" srcdoc="'.e($html).'"></iframe>'
        );
    }
}
