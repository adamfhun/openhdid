<?php

namespace App\Filament\Actions;

use App\Clients\ClientExporter;
use App\Clients\ClientExportField;
use App\Models\Client;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Fieldset;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Client export with a tick list of columns. Same protection as the
 * screens: only fields from ClientExportField and the surfaced directory
 * columns exist, the exporter drops anything else, and every export is
 * written to the audit log with its column list.
 */
class ExportClientsAction
{
    public static function make(): Action
    {
        $exporter = app(ClientExporter::class);
        $user = fn (): ?User => auth()->user();

        return Action::make('exportClients')
            ->label(__('Export CSV'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn () => $user()?->can($exporter->permission()->value) ?? false)
            ->modalHeading(__('Export clients'))
            ->modalDescription(__('Tick the columns to include. Security answers are never exported and the PIN itself cannot be, only whether one is set. The export is written to the audit log.'))
            ->modalSubmitActionLabel(__('Download CSV'))
            ->schema(function () use ($exporter, $user): array {
                $fields = [
                    Toggle::make('filtered_only')->label(__('Only the clients matching the current list filter'))->default(false)
                        ->helperText(__('Off: every client, closed accounts included, regardless of the list filter and tab.')),
                ];

                foreach ($exporter->options($user()) as $group => $options) {
                    $fields[] = Fieldset::make(ClientExportField::groupLabel($group))->columnSpanFull()->schema([
                        CheckboxList::make('fields.'.$group)->hiddenLabel()->options($options)->columns(2)->bulkToggleable()
                            ->default(array_values(array_intersect(array_map(fn (ClientExportField $f) => $f->value, ClientExportField::defaults()), array_keys($options)))),
                    ]);
                }

                return $fields;
            })
            ->action(function (Action $action, ListRecords $livewire, array $data) use ($exporter, $user): ?StreamedResponse {
                $actor = $user();
                abort_unless($actor?->can($exporter->permission()->value), 403);

                $keys = $exporter->allowedKeys(array_merge(...array_values(array_map(fn ($v) => is_array($v) ? $v : [], $data['fields'] ?? [])) ?: [[]]), $actor);

                if ($keys === []) {
                    Notification::make()->title(__('Tick at least one column.'))->danger()->send();
                    $action->halt();

                    return null;
                }

                $filteredOnly = (bool) ($data['filtered_only'] ?? false);

                /** @var Builder<Client> $query */
                $query = $filteredOnly ? $livewire->getFilteredSortedTableQuery() : Client::query()->orderBy('name');

                return ExportTableAction::stream(
                    $exporter->prepare($query),
                    fn (Client $client): array => $exporter->row($client, $keys),
                    'clients',
                    fn (int $rows, bool $truncated) => $exporter->recordExport($actor, $keys, $filteredOnly, $rows, $truncated),
                    array_values($exporter->headings($keys)),
                );
            });
    }
}
