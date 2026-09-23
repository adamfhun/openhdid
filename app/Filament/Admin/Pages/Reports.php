<?php

namespace App\Filament\Admin\Pages;

use App\Auth\Permission;
use App\Filament\Admin\NavigationGroup;
use App\Reporting\ReportFormat;
use App\Reporting\ReportPeriodTooLongException;
use App\Reporting\ReportSection;
use App\Reporting\ReportWriter;
use App\Reporting\UserReport;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Staff report: a supervisor picks the period, the parts and the format,
 * and downloads the report. The numbers come from UserReport, the file from
 * ReportWriter; every download is audited.
 */
class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 5;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Helpdesk;

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.admin.reports';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Reports');
    }

    public function getTitle(): string
    {
        return __('Reports');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::ReportsExport->value) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => today()->subDays(29)->toDateString(),
            'until' => today()->toDateString(),
            'sections' => ReportSection::values(),
            'format' => ReportFormat::Html->value,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $dateOnly = fn (?string $state): ?string => $state === null ? null : preg_replace('/^(\d{4}-\d{2}-\d{2}) \d{2}:\d{2}:\d{2}$/', '$1', $state);

        return $schema
            ->components([
                Section::make(__('Period'))->columns(2)->schema([
                    // The limit is the end of today: the picker may hand back today's
                    // date with a time, and midnight would reject it.
                    DatePicker::make('from')->label(__('From'))->native(false)->format('Y-m-d')->mutateStateForValidationUsing($dateOnly)->required()->maxDate(fn () => today()->endOfDay()),
                    DatePicker::make('until')->label(__('Until'))->native(false)->format('Y-m-d')->mutateStateForValidationUsing($dateOnly)->required()->maxDate(fn () => today()->endOfDay())->afterOrEqual('from'),
                ]),
                Section::make(__('Parts of the report'))->schema([
                    CheckboxList::make('sections')->hiddenLabel()->required()
                        ->options(ReportSection::options())
                        ->descriptions(collect(ReportSection::cases())->mapWithKeys(fn (ReportSection $s) => [$s->value => $s->description()])->all())
                        ->bulkToggleable(),
                ]),
                Section::make(__('Format'))->schema([
                    Radio::make('format')->hiddenLabel()->required()
                        ->options(collect(ReportFormat::cases())->mapWithKeys(fn (ReportFormat $f) => [$f->value => $f->label()])->all()),
                ]),
            ])
            ->statePath('data');
    }

    public function download(): ?StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        $from = CarbonImmutable::parse($state['from']);
        $until = CarbonImmutable::parse($state['until']);
        $sections = array_values(array_filter(array_map(fn ($v) => ReportSection::tryFrom((string) $v), $state['sections'] ?? [])));
        $format = ReportFormat::from($state['format']);

        try {
            $report = app(UserReport::class)->build(auth()->user(), $from, $until, $sections);
        } catch (ReportPeriodTooLongException $e) {
            $this->addError('data.until', $e->getMessage());

            return null;
        }

        return app(ReportWriter::class)->download($report, $format);
    }

    /**
     * @return array<int, Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('download')->label(__('Download report'))->icon('heroicon-o-arrow-down-tray')->submit('download'),
        ];
    }
}
