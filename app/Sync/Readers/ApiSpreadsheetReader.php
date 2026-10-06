<?php

namespace App\Sync\Readers;

use App\Enums\SyncSource;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\ApiTokens;
use App\Sync\Contracts\SourceReader;
use App\Sync\IdList;
use App\Sync\IdListException;
use App\Sync\RequestPayload;
use App\Sync\SourceFormatException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class ApiSpreadsheetReader implements SourceReader
{
    /** @var array<string, array<string, mixed>> */
    private array $details = [];

    public function __construct(
        private readonly Http $http,
        private readonly ApiTokens $tokens,
        private readonly Settings $settings,
        private readonly IdList $idList,
        private readonly string $url,
        private readonly string $format,
    ) {}

    public function source(): SyncSource
    {
        return SyncSource::Api;
    }

    public function label(): ?string
    {
        return $this->url;
    }

    /**
     * Status codes of the ID list query and the export, and the IDs sent.
     *
     * @return array<string, array<string, mixed>>
     */
    public function details(): array
    {
        return $this->details;
    }

    public function read(): iterable
    {
        $payload = $this->settings->string(SettingKey::SyncExportPayload);
        if (! RequestPayload::isJsonObject($payload)) {
            throw new SourceFormatException(__('EMD export payload must be a JSON object.'));
        }
        if ($unknown = RequestPayload::unknownPlaceholderMessage($payload, RequestPayload::EXPORT_PLACEHOLDERS)) {
            throw new SourceFormatException($unknown);
        }

        $token = $this->tokens->accessToken();
        $values = RequestPayload::uses($payload, 'ids') ? ['ids' => $this->selectedIds($token)] : [];
        $disk = Storage::disk('local');
        $disk->makeDirectory('sync-uploads');
        $file = 'sync-uploads/'.Str::uuid().'.'.$this->format;
        $path = $disk->path($file);

        try {
            try {
                $response = $this->http->withToken($token)->withoutRedirecting()
                    ->withBody(RequestPayload::render($payload, $values), 'application/json')
                    ->connectTimeout(10)->timeout((int) config('hdid.sync.export_timeout', 600))
                    ->sink($path)->post($this->url);
            } catch (ConnectionException) {
                throw new SourceFormatException(__('EMD export could not connect or timed out.'));
            }

            $this->details['export'] = ['status' => $response->status()];
            if ($response->status() !== 200) {
                throw new SourceFormatException(__('EMD export failed (HTTP :status); a complete file was expected.', ['status' => $response->status()]));
            }

            $this->assertFile($path, (string) $response->header('Content-Type'));

            yield from (new SpreadsheetReader(
                path: $path,
                mapping: $this->settings->array(SettingKey::SyncColumnMapping),
                label: $this->url,
                encoding: $this->settings->string(SettingKey::SyncCsvEncoding) ?: 'UTF-8',
                delimiter: (string) config('hdid.sync.csv_delimiter', ','),
            ))->read();
        } finally {
            $disk->delete($file);
        }
    }

    /**
     * Refreshes the ID list and returns the selected IDs still on it,
     * comma-separated; an empty list is never sent.
     */
    private function selectedIds(string $token): string
    {
        try {
            $result = $this->idList->refresh($token);
        } catch (IdListException $exception) {
            $this->details['id_list'] = ['status' => $exception->status, 'http_status' => $exception->httpStatus];

            throw $exception;
        }

        $ids = $this->idList->selectedIds();
        $this->details['id_list'] = $result + ['sent' => count($ids), 'ids' => implode(',', $ids)];

        if ($ids === []) {
            throw new SourceFormatException(__('No selected ID is on the EMD ID list; the export is not sent with an empty ID list.'));
        }

        return implode(',', $ids);
    }

    private function assertFile(string $path, string $contentType): void
    {
        if (! is_file($path) || filesize($path) === 0 || preg_match('/json|html|xml/i', $contentType) && ! str_contains($contentType, 'spreadsheetml')) {
            throw new SourceFormatException(__('EMD export did not return the expected spreadsheet file.'));
        }

        if ($this->format === 'xlsx') {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new SourceFormatException(__('EMD export did not return a valid XLSX file.'));
            }

            try {
                if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                    throw new SourceFormatException(__('EMD export did not return a valid XLSX file.'));
                }
            } finally {
                $zip->close();
            }
        }
    }
}
