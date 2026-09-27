<?php

namespace App\Filament\Actions;

use App\Models\Call;
use Filament\Actions\Action;

/**
 * The dashboard's "Identify" / "Take over" button. The agent the button was
 * drawn for travels with the click, so the call is only taken over from that
 * colleague: a call that changed hands between drawing the row and clicking
 * it is never taken over silently, the agent gets the warning instead.
 */
class TakeCallAction extends Action
{
    public const FROM_ARGUMENT = 'from';

    /**
     * @return array<string, mixed>|null
     */
    public function getInvokedArguments(): ?array
    {
        $record = $this->getRecord();

        return [
            ...(parent::getInvokedArguments() ?? []),
            self::FROM_ARGUMENT => $record instanceof Call ? $record->agent_user_id : null,
        ];
    }

    /**
     * The holder the click was confirmed against; null when the button was
     * drawn for a call nobody held, or when the request carries no such claim.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function from(array $arguments): ?string
    {
        $from = $arguments[self::FROM_ARGUMENT] ?? null;

        return is_string($from) && $from !== '' ? $from : null;
    }
}
