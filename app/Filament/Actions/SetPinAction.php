<?php

namespace App\Filament\Actions;

use App\Audit\Auditor;
use App\Auth\Permission;
use App\Identification\PinService;
use App\Messaging\MessageKey;
use App\Messaging\Messenger;
use App\Models\Client;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Give a client a new PIN. A random one is proposed; when the client has a
 * primary phone number the operator confirms whether to send it by SMS.
 */
class SetPinAction
{
    public static function make(string $name = 'setPin'): Action
    {
        $pins = app(PinService::class);

        return Action::make($name)
            ->label(__('Set PIN'))
            ->icon('heroicon-o-key')
            ->color('warning')
            ->visible(fn (Client $record): bool => auth()->user()?->can(Permission::ClientsPinSet->value) && ! $record->isClosed())
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
                    ->helperText(__(':min to :max digits. A random :min-digit PIN is proposed; overwrite it if the client asked for a specific one.', ['min' => $pins->minLength(), 'max' => $pins->maxLength()])),
                Toggle::make('send_sms')
                    ->label(fn () => __('Send the PIN by SMS to :number', ['number' => $record->primaryPhoneNumber()]))
                    ->default(true)
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

                $phone = $record->primaryPhoneNumber();
                $sent = false;

                if (($data['send_sms'] ?? false) && $phone !== null) {
                    app(Messenger::class)->sendTemplate(MessageKey::PinSms, $record, ['pin' => (string) $data['pin']], $phone);
                    app(Auditor::class)->record('client.pin_sent_sms', $record, ['phone' => $phone]);
                    $sent = true;
                }

                Notification::make()
                    ->title($sent ? __('PIN set and sent by SMS to :number', ['number' => $phone]) : __('PIN set'))
                    ->success()
                    ->send();
            });
    }
}
