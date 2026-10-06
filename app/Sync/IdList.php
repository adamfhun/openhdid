<?php

namespace App\Sync;

use App\Audit\Auditor;
use App\Models\SyncIdListItem;
use App\Settings\SettingKey;
use App\Settings\Settings;
use Collator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The EMD ID list (e.g. organizations): queried with the export's token and
 * kept in sync_id_list_items together with the staff's selection. The
 * selected IDs still on the list fill the export payload's `{{ ids }}`.
 * Nothing is selected automatically. An ID that leaves the list is no longer
 * sent, so its records stop arriving and their accounts close after the
 * configured number of missed runs; when it returns, its earlier selection
 * applies again.
 */
class IdList
{
    private const STATUS_CACHE_KEY = 'hdid.sync.id_list:status';

    private const LOCK = 'hdid:sync-id-list';

    public function __construct(
        private readonly Http $http,
        private readonly ApiTokens $tokens,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('hdid.sync.id_list_url'));
    }

    /**
     * Queries the list and stores it: new IDs arrive unselected, renamed ones
     * are updated, missing ones marked removed, returning ones restored.
     *
     * @return array{status: int, http_status: int, received: int, added: int, renamed: int, removed: int, returned: int}
     *
     * @throws IdListException
     */
    public function refresh(?string $token = null): array
    {
        try {
            [$status, $httpStatus, $items] = $this->query($token);
        } catch (IdListException $exception) {
            Cache::forever(self::STATUS_CACHE_KEY, [
                'status' => $exception->status, 'http_status' => $exception->httpStatus,
                'at' => now()->toIso8601String(), 'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $result = ['status' => $status, 'http_status' => $httpStatus, 'received' => count($items)] + $this->store($items);
        Cache::forever(self::STATUS_CACHE_KEY, $result + ['at' => now()->toIso8601String(), 'error' => null]);

        return $result;
    }

    /**
     * The panel's fetch button: a refresh written to the audit log with its
     * status codes, whether it succeeds or not.
     *
     * @return array{status: int, http_status: int, received: int, added: int, renamed: int, removed: int, returned: int}
     *
     * @throws IdListException
     */
    public function fetchNow(): array
    {
        try {
            $result = $this->refresh();
        } catch (IdListException $exception) {
            $this->auditor->record('sync.id_list.fetch_failed', context: [
                'status' => $exception->status, 'http_status' => $exception->httpStatus, 'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->auditor->record('sync.id_list.fetched', context: $result);

        return $result;
    }

    /**
     * The last query's outcome for the settings page.
     *
     * @return array{status?: ?int, http_status?: ?int, received?: int, at?: string, error?: ?string}
     */
    public function status(): array
    {
        return Cache::get(self::STATUS_CACHE_KEY, []);
    }

    /**
     * Selected IDs still on the list, in natural order: the value of `{{ ids }}`.
     *
     * @return list<string>
     */
    public function selectedIds(): array
    {
        return SyncIdListItem::query()->listed()->where('selected', true)->pluck('external_id')
            ->map(fn ($id): string => (string) $id)->sort(SORT_NATURAL)->values()->all();
    }

    /**
     * The listed IDs as checkbox options, "name (ID)", in Hungarian
     * alphabetical order of the names.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        $collator = new Collator('hu_HU');

        return SyncIdListItem::query()->listed()->get()
            ->sort(fn (SyncIdListItem $a, SyncIdListItem $b): int => $collator->compare($a->name, $b->name) ?: strnatcmp($a->external_id, $b->external_id))
            ->mapWithKeys(fn (SyncIdListItem $item): array => [$item->external_id => $item->name.' ('.$item->external_id.')'])
            ->all();
    }

    /**
     * Selected IDs that have left the list: not sent, but selected again
     * when they return.
     *
     * @return list<string>
     */
    public function selectedButRemoved(): array
    {
        return SyncIdListItem::query()->whereNotNull('removed_at')->where('selected', true)->orderBy('name')->get()
            ->map(fn (SyncIdListItem $item): string => $item->name.' ('.$item->external_id.')')->all();
    }

    /**
     * Listed, selected IDs the given selection would drop, for the warning
     * before saving.
     *
     * @param  array<int, string|int>  $externalIds
     * @return list<string>
     */
    public function wouldDeselect(array $externalIds): array
    {
        return SyncIdListItem::query()->listed()->where('selected', true)
            ->whereNotIn('external_id', array_map('strval', $externalIds))->orderBy('name')->get()
            ->map(fn (SyncIdListItem $item): string => $item->name.' ('.$item->external_id.')')->all();
    }

    /**
     * The reason a selection would leave `{{ ids }}` empty, or null.
     *
     * @param  array<int, string|int>  $externalIds
     */
    public function emptySelectionMessage(array $externalIds): ?string
    {
        $selected = $externalIds === [] ? 0
            : SyncIdListItem::query()->listed()->whereIn('external_id', array_map('strval', $externalIds))->count();

        return $selected === 0 ? __('The export payload uses {{ ids }}: select at least one ID of the ID list.') : null;
    }

    /**
     * Stores the selection of the listed IDs; each change is audited. IDs off
     * the list keep their earlier choice.
     *
     * @param  array<int, string|int>  $externalIds
     */
    public function select(array $externalIds): void
    {
        $wanted = array_flip(array_map('strval', $externalIds));

        Cache::lock(self::LOCK, 60)->block(10, fn () => DB::transaction(function () use ($wanted): void {
            foreach (SyncIdListItem::query()->listed()->get() as $item) {
                $item->selected = array_key_exists($item->external_id, $wanted);
                if ($item->isDirty('selected')) {
                    $item->save();
                }
            }
        }));
    }

    /**
     * @return array{0: int, 1: int, 2: array<string, string>} status, HTTP status, ID => name
     *
     * @throws IdListException
     */
    private function query(?string $token): array
    {
        $url = config('hdid.sync.id_list_url');
        if (! is_string($url) || $url === '') {
            throw new IdListException(__('EMD_SYNC_ID_LIST_URL is not configured.'));
        }

        $payload = $this->settings->string(SettingKey::SyncIdListPayload);
        if (! RequestPayload::isJsonObject($payload)) {
            throw new IdListException(__('The ID list request payload must be a JSON object.'));
        }
        if ($unknown = RequestPayload::unknownPlaceholderMessage($payload, RequestPayload::ID_LIST_PLACEHOLDERS)) {
            throw new IdListException($unknown);
        }

        $token ??= $this->tokens->accessToken();

        try {
            $response = $this->http->withToken($token)->acceptJson()->withoutRedirecting()
                ->withBody(RequestPayload::render($payload), 'application/json')
                ->connectTimeout(10)->timeout((int) config('hdid.sync.export_timeout', 600))
                ->post($url);
        } catch (ConnectionException) {
            throw new IdListException(__('The EMD ID list query could not connect or timed out.'));
        }

        $httpStatus = $response->status();
        $body = $response->json();
        $statusPath = trim((string) $this->settings->string(SettingKey::SyncIdListStatusPath));
        $status = $statusPath === '' ? $httpStatus : $this->numberAt($body, $statusPath);

        if (! $response->successful()) {
            $status ??= $httpStatus;

            throw new IdListException(__('The EMD ID list query failed (status :status, HTTP :http).', ['status' => $status, 'http' => $httpStatus]), $status, $httpStatus);
        }
        if ($status === null) {
            throw new IdListException(__('The EMD ID list response has no numeric status code at ":path" (HTTP :http).', ['path' => $statusPath, 'http' => $httpStatus]), null, $httpStatus);
        }
        if ($status < 200 || $status > 299) {
            throw new IdListException(__('The EMD ID list query failed (status :status, HTTP :http).', ['status' => $status, 'http' => $httpStatus]), $status, $httpStatus);
        }
        if (! is_array($body)) {
            throw new IdListException(__('The EMD ID list response is not JSON.'), $status, $httpStatus);
        }

        return [$status, $httpStatus, $this->itemsOf($body, $status, $httpStatus)];
    }

    /**
     * ID => name from the configured paths; an ID repeated in the response
     * counts once (its first name wins).
     *
     * @param  array<mixed>  $body
     * @return array<string, string>
     *
     * @throws IdListException
     */
    private function itemsOf(array $body, int $status, int $httpStatus): array
    {
        $idPath = $this->path(SettingKey::SyncIdListIdPath);
        $names = self::pluck($body, explode('.', $this->path(SettingKey::SyncIdListNamePath)));
        $items = [];

        foreach (self::pluck($body, explode('.', $idPath)) as $key => $raw) {
            $id = is_int($raw) || is_string($raw) ? trim((string) $raw) : '';
            if ($id === '' || mb_strlen($id) > 191 || preg_match('/[\s,"\'\\\\]/u', $id)) {
                throw new IdListException(__('The EMD ID list holds an unusable ID (:id) at ":path"; an ID must not be empty or contain spaces, commas or quotes.', [
                    'id' => Str::limit(is_scalar($raw) ? (string) $raw : (string) json_encode($raw), 40),
                    'path' => $idPath,
                ]), $status, $httpStatus);
            }

            if (array_key_exists($id, $items)) {
                continue;
            }

            $name = $names[$key] ?? null;
            $items[$id] = is_scalar($name) && trim((string) $name) !== '' ? Str::limit(trim((string) $name), 250, '') : $id;
        }

        if ($items === []) {
            throw new IdListException(__('The EMD ID list response holds no ID at ":path".', ['path' => $idPath]), $status, $httpStatus);
        }

        return $items;
    }

    /**
     * @param  array<string, string>  $items
     * @return array{added: int, renamed: int, removed: int, returned: int}
     */
    private function store(array $items): array
    {
        return Cache::lock(self::LOCK, 60)->block(10, fn (): array => DB::transaction(function () use ($items): array {
            $now = now();
            $counts = ['added' => 0, 'renamed' => 0, 'removed' => 0, 'returned' => 0];
            $existing = SyncIdListItem::query()->get()->keyBy('external_id');

            foreach ($items as $id => $name) {
                $item = $existing->get((string) $id);
                if ($item === null) {
                    SyncIdListItem::query()->create([
                        'external_id' => (string) $id, 'name' => $name, 'selected' => false,
                        'first_seen_at' => $now, 'last_seen_at' => $now,
                    ]);
                    $counts['added']++;

                    continue;
                }

                $counts['renamed'] += (int) ($item->name !== $name);
                $counts['returned'] += (int) ! $item->isListed();
                $item->fill(['name' => $name, 'last_seen_at' => $now, 'removed_at' => null])->save();
            }

            foreach ($existing as $id => $item) {
                if (! array_key_exists($id, $items) && $item->isListed()) {
                    $item->update(['removed_at' => $now]);
                    $counts['removed']++;
                }
            }

            return $counts;
        }));
    }

    private function path(SettingKey $key): string
    {
        return trim((string) $this->settings->string($key)) ?: (string) $key->default();
    }

    private function numberAt(mixed $body, string $path): ?int
    {
        $value = is_array($body) ? data_get($body, $path) : null;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /**
     * Values at a dotted path, keyed by the indexes of the lists crossed on
     * the way. A list is crossed implicitly (`result.data.id` reads `data.id`
     * of every element of `result`) or with `*`; a number picks one element.
     * Two paths through the same list yield the same keys, which pairs an ID
     * with its name.
     *
     * @param  list<string>  $segments
     * @return array<int|string, mixed>
     */
    private static function pluck(mixed $node, array $segments, string $key = ''): array
    {
        if ($segments === []) {
            return [$key => $node];
        }
        if (! is_array($node)) {
            return [];
        }

        $segment = $segments[0];
        $rest = array_slice($segments, 1);

        if ($segment === '*' || (array_is_list($node) && ! ctype_digit($segment))) {
            $values = [];
            foreach ($node as $index => $child) {
                $values += self::pluck($child, $segment === '*' ? $rest : $segments, $key === '' ? (string) $index : $key.'.'.$index);
            }

            return $values;
        }

        return array_key_exists($segment, $node) ? self::pluck($node[$segment], $rest, $key) : [];
    }
}
