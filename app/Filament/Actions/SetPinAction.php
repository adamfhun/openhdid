<?php

namespace App\Filament\Actions;

use App\Audit\Auditor;
use App\Auth\Mfa\StaffMfa;
use App\Auth\Permission;
use App\Enums\OutboundMessageStatus;
use App\Filament\NotificationText;
use App\Identification\PinService;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Models\Client;
use App\Sms\SmsDestinations;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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

    /**
     * Why the PIN cannot go out by SMS, or null when it can: the client has a
     * number an SMS may go to, and an SMS gateway is connected (the "log"
     * driver sends nothing).
     */
    public static function smsBlocker(Client $client): ?string
    {
        if (! StaffMfa::smsAvailable()) {
            return __('No SMS gateway is connected (SMS_DRIVER=log), so the PIN cannot be sent.');
        }

        if (static::smsTarget($client) !== null) {
            return null;
        }

        $primary = $client->primaryPhoneNumber();

        return $primary === null
            ? __('The client has no phone number, so the PIN cannot be sent.')
            : __('No text message goes to :number (:reason), and the client has no other number that receives one.', ['number' => $primary, 'reason' => app(SmsDestinations::class)->refusal($primary)?->label() ?? '']);
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
            // Two ways to finish: set it only, or set it and send it. The second one
            // exists only while an SMS can really go out; the dialog says why when not.
            ->modalSubmitActionLabel(__('Set PIN only'))
            ->extraModalFooterActions(fn (Action $action, Client $record): array => static::smsBlocker($record) === null
                ? [$action->makeModalSubmitAction('setAndSend', arguments: ['send_sms' => true])->label(__('Set and send by SMS'))->color('primary')]
                : [])
            ->schema(fn (Client $record): array => [
                Hidden::make('pin_shown')->default(false)->dehydrated(false),
                TextInput::make('pin')
                    ->label(__('PIN'))
                    // Masked always; uncovered only by the audited button, and only when the setting allows it.
                    ->password(fn (Get $get): bool => ! $get('pin_shown'))
                    ->autocomplete('off')
                    // A proposed PIN is of use only when it can reach the client by SMS (or be uncovered).
                    ->default(fn (): ?string => static::smsBlocker($record) === null || $pins->staffMayReveal() ? $pins->generatePin($record) : null)
                    ->required()
                    ->inputMode('numeric')
                    ->rule('digits_between:'.$pins->minLength().','.$pins->maxLength())
                    ->minLength($pins->minLength())
                    ->maxLength($pins->maxLength())
                    ->suffixAction(
                        Action::make('revealPin')
                            ->label(__('Uncover the PIN'))
                            ->icon('heroicon-o-eye')
                            ->visible(fn (Get $get): bool => $pins->staffMayReveal() && ! $get('pin_shown'))
                            ->action(function (Set $set) use ($record): void {
                                $set('pin_shown', true);
                                app(Auditor::class)->record('client.pin_revealed', $record, actor: Filament::auth()->user());
                            }),
                    )
                    ->helperText(fn (): string => __(':min to :max digits, shown masked. The phone menu checks it only when the client calls from a registered number.', ['min' => $pins->minLength(), 'max' => $pins->maxLength()])
                        .' '.(static::smsBlocker($record) === null || $pins->staffMayReveal()
                            ? __('A random PIN is proposed; overwrite it if the client asked for a specific one.')
                            : __('No PIN is proposed, because it could not reach the client: type the PIN the client asks for.'))
                        .' '.($pins->staffMayReveal()
                            ? __('Uncovering the PIN is written to the audit log.')
                            : __('Uncovering is switched off (Settings › Identification › PIN).'))),
                Text::make(fn (): string => __('The SMS goes to :number.', ['number' => (string) static::smsTarget($record)]))
                    ->visible(fn (): bool => static::smsBlocker($record) === null),
                Text::make(fn (): string => (string) static::smsBlocker($record).' '.__('The button that sends it is therefore not offered: tell the client the PIN in person.'))
                    ->color('warning')
                    ->visible(fn (): bool => static::smsBlocker($record) !== null),
            ])
            ->action(function (Client $record, array $data, array $arguments, HasActions $livewire) use ($pins): void {
                try {
                    $pins->setPin($record, (string) $data['pin'], Filament::auth()->user());
                } catch (ValidationException $e) {
                    $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();

                    throw ValidationException::withMessages([$path.'.pin' => $e->errors()['pin']]);
                } catch (Throwable $e) {
                    Notification::make()->title(NotificationText::of($e->getMessage()))->danger()->send();

                    return;
                }

                $phone = static::smsTarget($record);

                // Asked again on the server: the footer button is not what decides.
                if (($arguments['send_sms'] ?? false) && static::smsBlocker($record) === null && $phone !== null) {
                    $message = app(Messenger::class)->sendTemplate(MessageKey::PinSms, $record, ['pin' => (string) $data['pin']], $phone);

                    if ($message->status === OutboundMessageStatus::Cancelled) {
                        Notification::make()
                            ->title(__('PIN set; the SMS was not sent'))
                            ->body(NotificationText::of((string) $message->error.' '.__('Tell the client the PIN in person.')))
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

                    app(Auditor::class)->record('client.pin_sent_sms', $record, ['phone' => $phone]);

                    Notification::make()->title(NotificationText::of(__('PIN set and sent by SMS to :number', ['number' => $phone])))->success()->send();

                    return;
                }

                Notification::make()->title(__('PIN set'))->success()->send();
            });
    }
}
