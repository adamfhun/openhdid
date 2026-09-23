<?php

namespace App\Sync\Readers;

use App\Enums\SyncSource;
use App\Sync\Contracts\SourceReader;
use App\Sync\ExternalRecordDto;
use App\Sync\SkippedRow;
use App\Sync\SourceFormatException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Arr;

/**
 * Generic reader for a JSON endpoint. Supports a static header set, an
 * optional data path inside the response and page-based pagination. A
 * response without the data path is an error, not an empty page; paging
 * stops on an empty page, a repeated page or the page cap.
 */
class JsonApiReader implements SourceReader
{
    public const MAX_PAGES = 10_000;

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string|list<string>>  $mapping
     */
    public function __construct(
        private readonly Http $http,
        private readonly string $url,
        private readonly array $headers,
        private readonly array $mapping,
        private readonly ?string $dataPath = null,
        private readonly ?string $pageParam = null,
        private readonly int $timeout = 60,
    ) {}

    public function source(): SyncSource
    {
        return SyncSource::Api;
    }

    public function label(): ?string
    {
        return $this->url;
    }

    public function read(): iterable
    {
        $page = 1;
        $previousFingerprint = null;

        do {
            $query = $this->pageParam ? [$this->pageParam => $page] : [];

            $response = $this->http
                ->withHeaders($this->headers)
                ->timeout($this->timeout)
                ->retry(3, 500)
                ->get($this->url, $query)
                ->throw();

            $json = $response->json();

            if ($this->dataPath) {
                if (! is_array($json) || ! Arr::has($json, $this->dataPath)) {
                    throw new SourceFormatException(sprintf('The API response has no "%s" element (page %d).', $this->dataPath, $page));
                }
                $rows = Arr::get($json, $this->dataPath);
            } else {
                $rows = $json;
            }

            if (! is_array($rows)) {
                throw new SourceFormatException(sprintf('The API response rows are not a list (page %d).', $page));
            }

            $fingerprint = $rows === [] ? null : md5(json_encode(array_slice($rows, 0, 1)).count($rows));
            if ($fingerprint !== null && $fingerprint === $previousFingerprint) {
                // The endpoint ignores the page parameter: stop instead of re-importing forever.
                break;
            }
            $previousFingerprint = $fingerprint;

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    yield new SkippedRow(SkippedRow::REASON_INVALID_ROW);

                    continue;
                }

                $flat = Arr::dot($row) + $row;
                yield ExternalRecordDto::fromRow($flat, $this->mapping) ?? SkippedRow::fromRow(SkippedRow::REASON_INVALID_ROW, $flat, $this->mapping);
            }

            if ($page >= self::MAX_PAGES) {
                throw new SourceFormatException(sprintf('More than %d pages; pagination does not terminate.', self::MAX_PAGES));
            }

            $page++;
        } while ($this->pageParam !== null && $rows !== []);
    }
}
