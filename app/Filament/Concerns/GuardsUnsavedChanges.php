<?php

namespace App\Filament\Concerns;

use App\Support\InputLimits;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * Unsaved changes on a page with a Save button. The page keeps the form state
 * it was filled or last saved with; the guard (views filament.admin.unsaved-
 * guard*, rendered on every page using this trait) compares it with the form
 * in the browser. Leaving through a link, the menu or a panel form then asks:
 * save and go on, discard and go on, or stay, naming the changed fields; the
 * settings tabs with changes get a dot. Closing or reloading the tab only gets
 * the browser's own question, which cannot offer a Save button.
 */
trait GuardsUnsavedChanges
{
    /** The form state as filled or last saved, JSON; the browser compares with it. */
    #[Locked]
    public string $unsavedBaseline = '';

    /**
     * What each top-level form key is called on screen, for the dialog: the
     * field, the tab it is on (whose dot marks a change) and the block
     * within the tab.
     *
     * @var array<string, array{label: string, group: ?string, section: ?string}>
     */
    #[Locked]
    public array $unsavedLabels = [];

    public function mountGuardsUnsavedChanges(): void
    {
        $this->rememberData();
        $this->unsavedLabels = $this->unsavedChangeLabels();
    }

    /**
     * Remember the form as it stands as the saved state. Filament's record
     * pages call this after filling and after saving; other pages call it
     * themselves.
     */
    protected function rememberData(): void
    {
        $this->unsavedBaseline = $this->unsavedState();
    }

    /**
     * Filament's record pages call this after every fill (mount, "create
     * another", a page action that reloads the form): the refilled form is
     * the saved state.
     */
    protected function afterFill(): void
    {
        $this->rememberData();
    }

    /**
     * Whether the form still holds what was filled or last saved.
     */
    public function isUnchangedSinceSaved(): bool
    {
        return $this->unsavedState() === $this->unsavedBaseline;
    }

    /**
     * "Save and go on" from the leave dialog: saves the way the page's own
     * Save button does, then follows the link. A save that needs a
     * confirmation opens it, and the link is followed once it is confirmed; a
     * refused save (validation) keeps the page open with its errors. Without
     * a link (the dialog was opened by a panel form, such as signing out) it
     * only saves and tells whether it did; after a confirmation it tells the
     * browser through the hdid-leave-continue event.
     */
    public function saveAndLeave(?string $url = null, bool $viaForm = false): bool
    {
        $url = $url === null ? null : static::safeLeaveUrl($url);

        if (method_exists($this, 'saveAction')) {
            $this->mountAction('save', ['leaveTo' => $url, 'leaveForm' => $viaForm]);

            return $this->getMountedAction() === null && $this->isUnchangedSinceSaved();
        }

        if ($this instanceof CreateRecord) {
            $this->create();
            $saved = $this->getRecord()?->exists ?? false;
        } else {
            $this->save(shouldRedirect: false);
            $saved = $this->isUnchangedSinceSaved();
        }

        if ($saved && $url !== null) {
            $this->redirect($url);
        }

        return $saved;
    }

    /**
     * Follow the link the leave dialog was opened for, after a Save action
     * that needed a confirmation went through. Hooked to that action's after().
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function followLeaveLink(array $arguments): void
    {
        $url = $arguments['leaveTo'] ?? null;

        if (! $this->isUnchangedSinceSaved()) {
            return;
        }

        if (is_string($url) && $url !== '') {
            $this->redirect(static::safeLeaveUrl($url));
        } elseif (($arguments['leaveForm'] ?? false) === true) {
            $this->dispatch('hdid-leave-continue');
        }
    }

    /**
     * Only this site's own addresses are followed; anything else leads to the
     * panel's home page. Browsers read a backslash as a slash and drop tabs
     * and line breaks, so an address carrying either is refused outright, and
     * an absolute one must start with this site's own origin and a slash. No
     * page of the panel has an address longer than a URL can be
     * (InputLimits::URL).
     */
    public static function safeLeaveUrl(string $url): string
    {
        $home = Filament::getUrl() ?? '/';

        if ($url === '' || strlen($url) > InputLimits::URL || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return $home;
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? $home : $url;
        }

        // The panel's own links begin with the configured root (APP_URL, forced outside
        // local), which may be written differently from the request's origin (a :443,
        // capitals, http behind a TLS proxy); either is this site.
        foreach ([request()->getSchemeAndHttpHost(), rtrim(url('/'), '/')] as $origin) {
            if (str_starts_with($url, $origin.'/')) {
                return $url;
            }
        }

        return $home;
    }

    /**
     * The on-screen name of each top-level form key: the field's label, the
     * tab around it and the block within that tab; on a page without tabs
     * the group is the nearest block.
     *
     * @return array<string, array{label: string, group: ?string, section: ?string}>
     */
    protected function unsavedChangeLabels(): array
    {
        $labels = [];

        foreach ($this->form->getFlatFields(withHidden: true) as $field) {
            /** @var Field $field */
            $key = Str::before(Str::after($field->getStatePath(), 'data.'), '.');

            if ($key === '' || isset($labels[$key])) {
                continue;
            }

            $labels[$key] = ['label' => trim(strip_tags((string) $field->getLabel())), ...$this->unsavedPlaceOf($field)];
        }

        return $labels;
    }

    /**
     * @return array{group: ?string, section: ?string}
     */
    private function unsavedPlaceOf(Field $field): array
    {
        $section = null;
        $parent = $field->getContainer()->getParentComponent();

        while ($parent !== null) {
            if ($parent instanceof Tab) {
                return ['group' => trim(strip_tags((string) $parent->getLabel())), 'section' => $section];
            }

            if ($section === null && $parent instanceof Section && filled($heading = $parent->getHeading())) {
                $section = trim(strip_tags((string) $heading));
            }

            $parent = $parent->getContainer()->getParentComponent();
        }

        return ['group' => $section, 'section' => null];
    }

    private function unsavedState(): string
    {
        return (string) json_encode($this->data ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
