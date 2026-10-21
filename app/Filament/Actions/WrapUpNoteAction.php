<?php

namespace App\Filament\Actions;

use App\Models\Call;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * A short note on the call for whoever looks at it later. Offered wherever
 * the agent works on a call they hold: the identification page and the
 * identified client's record.
 */
class WrapUpNoteAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'wrapUpNote';
    }

    /**
     * @param  Closure(): ?Call  $call  the call the agent holds now, re-read on every use
     */
    public static function for(Closure $call): static
    {
        return static::make()
            ->label(fn () => filled($call()?->wrap_up_note) ? __('Edit call note') : __('Call note'))
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->outlined()
            ->visible(fn () => $call() !== null)
            ->modalHeading(__('Note on this call'))
            ->schema([
                Textarea::make('note')->label(__('Note'))->maxLength(1000)->rows(4)
                    ->default(fn () => $call()?->wrap_up_note)
                    ->placeholder(__('What was the call about, what is left to do?')),
            ])
            ->modalSubmitActionLabel(__('Save'))
            ->action(function (array $data) use ($call): void {
                $held = $call() ?? abort(403);

                $note = trim((string) ($data['note'] ?? ''));
                $held->forceFill(['wrap_up_note' => $note === '' ? null : $note])->save();
                Notification::make()->title(__('Call note saved'))->success()->send();
            });
    }
}
