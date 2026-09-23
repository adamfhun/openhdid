<?php

namespace App\Filament\Admin\Resources\MessageTemplates\Pages;

use App\Filament\Admin\Resources\MessageTemplates\MessageTemplateResource;
use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Messaging\TemplateRenderer;
use App\Models\MessageTemplate;
use App\Support\PhoneNormalizer;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditMessageTemplate extends EditRecord
{
    protected static string $resource = MessageTemplateResource::class;

    public function getTitle(): string
    {
        /** @var MessageTemplate $record */
        $record = $this->getRecord();

        return $record->key->label().' · '.strtoupper($record->locale);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['subject'] = isset($data['subject']) && trim((string) $data['subject']) !== '' ? $data['subject'] : null;
        $data['body'] = isset($data['body']) && trim(strip_tags((string) $data['body'])) !== '' ? $data['body'] : null;
        $data['updated_by_user_id'] = auth()->id();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetToDefault')
                ->label(__('Reset to default'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->isCustomised() || $this->getRecord()->subject !== null)
                ->action(function (): void {
                    $this->getRecord()->forceFill(['subject' => null, 'body' => null, 'updated_by_user_id' => auth()->id()])->save();
                    $this->fillForm();
                    Notification::make()->title(__('Template reset to the built-in default'))->success()->send();
                }),
            Action::make('sendTest')
                ->label(__('Send a test'))
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                ->schema(fn (): array => $this->getRecord()->key->channel() === Channel::Sms
                    ? [TextInput::make('phone')->label(__('Phone number'))->tel()->required()->placeholder('+36 30 123 4567')]
                    : [TextInput::make('email')->label(__('E-mail address'))->email()->required()->default(auth()->user()?->email)])
                ->action(function (array $data): void {
                    /** @var MessageTemplate $record */
                    $record = $this->getRecord();
                    $key = $record->key;
                    $rendered = app(TemplateRenderer::class)->render($key, $record->locale, $key->sampleData());

                    try {
                        if ($key->channel() === Channel::Sms) {
                            $phone = app(PhoneNormalizer::class)->normalize($data['phone']) ?? throw new \InvalidArgumentException(__('Invalid phone number.'));
                            app(Messenger::class)->sendRaw(Channel::Sms, $phone, null, $rendered->body);
                            $to = $phone;
                        } else {
                            app(Messenger::class)->sendRaw(Channel::Email, $data['email'], '[TEST] '.$rendered->subject, $rendered->body);
                            $to = $data['email'];
                        }
                    } catch (Throwable $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('Test message queued to :to', ['to' => $to]))->success()->send();
                }),
        ];
    }

    /**
     * Templates have no view page: back to the list after saving.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
