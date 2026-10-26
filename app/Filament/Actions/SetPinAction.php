<?php

namespace App\Filament\Actions;

use App\Audit\Auditor;
use App\Auth\Permission;
use App\Enums\OutboundMessageStatus;
use App\Identification\PinService;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Models\Client;
use App\Sms\SmsDestinations;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Give a client a new PIN. A random one is proposed; when a number of the
 * client may receive SMS the operator confirms whether to send it there.
 * The notification says what really happened: a refused SMS is not
 * reported as sent.
 */
class SetPinAction
{
    /**
     * Where a PIN by SMS goes: the primary number, or the first other one an
     * SMS may go to (SmsDestinations::targetFor); null when none may.
     */
    public static function smsTarget(Client $client): ?string
    {
        return app(SmsDestinations::class)->targetFor($client);
    }

    public static function make(string $name = 'setPin'): Action
    {
        $pins = app(PinService::class);

        return Action::make($name)
            ->label(__('Set PIN'))
            ->icon('heroicon-o-key')
            ->color('warning')
            ->visible(fn (Client $record): bool => auth()->user()?->can(Permission::ClientsPinSet->value) && ! $record->isClosed())
            // The phone menu recognises the caller by number before it asks for the PIN: no number, no usable PIN.
            ->disabled(fn (Client $record): bool => ! $pins->canSetPin($record))
            ->tooltip(fn (Client $record): ?string => $pins->setPinBlocker($record))
            ->modalHeading(fn (Client $record): string => __('New PIN for :name', ['name' => $record->name]))
            ->modalSubmitActionLabel(__('Set PIN'))
            ->schema(fn (Client $record): array => [
                TextInput::make('pin')
                    ->label(__('PIN'))
                    ->default(fn () => $pins->generatePin($record))
                    ->required()
                    ->inputMode('numeric')
                    ->rule('digits_between:'.$pins->minLength().','.$pins->maxLength())
                    ->minLength($pins->minLength())
                    ->maxLength($pins->maxLength())
                    ->helperText(__(':min to :max digits. A random :min-digit PIN is proposed; overwrite it if the client asked for a specific one. The phone menu checks it only when the client calls from a registered number.', ['min' => $pins->minLength(), 'max' => $pins->maxLength()])),
                // The primary number, or the first other one an SMS may go to; a client
                // with landlines only gets no SMS, and the switch says why instead of
                // claiming a send that the Messenger would refuse.
                Toggle::make('send_sms')
                    ->label(fn () => __('Send the PIN by SMS to :number', ['number' => static::smsTarget($record) ?? $record->primaryPhoneNumber()]))
                    ->default(fn (): bool => static::smsTarget($record) !== null)
                    ->disabled(fn (): bool => static::smsTarget($record) === null)
                    ->helperText(fn (): ?string => static::smsTarget($record) === null && ($primary = $record->primaryPhoneNumber()) !== null
                        ? __('No text message goes to :number (:reason), and the client has no other number that receives one: tell the PIN in person.', ['number' => $primary, 'reason' => app(SmsDestinations::class)->refusal($primary)?->label() ?? ''])
                        : null)
                    ->visible(fn () => $record->primaryPhoneNumber() !== null),
                Text::make(__('The client has no phone number, so the PIN has to be told in person.'))
                    ->visible(fn () => $record->primaryPhoneNumber() === null),
            ])
            ->action(function (Client $record, array $data, HasActions $livewire) use ($pins): void {
                try {
                    $pins->setPin($record, (string) $data['pin']);
                } catch (ValidationException $e) {
                    $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();

                    throw ValidationException::withMessages([$path.'.pin' => $e->errors()['pin']]);
                } catch (Throwable $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                $phone = static::smsTarget($record);

                if (($data['send_sms'] ?? false) && $phone !== null) {
                    $message = app(Messenger::class)->sendTemplate(MessageKey::PinSms, $record, ['pin' => (string) $data['pin']], $phone);

                    if ($message->status === OutboundMessageStatus::Cancelled) {
                        Notification::make()
                            ->title(__('PIN set; the SMS was not sent'))
                            ->body((string) $message->error.' '.__('Tell the client the PIN in person.'))
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

                    app(Auditor::class)->record('client.pin_sent_sms', $record, ['phone' => $phone]);

                    Notification::make()->title(__('PIN set and sent by SMS to :number', ['number' => $phone]))->success()->send();

                    return;
                }

                Notification::make()->title(__('PIN set'))->success()->send();
            });
    }
}
