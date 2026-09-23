<?php

namespace App\Sync;

use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Sync\Contracts\SourceReader;
use App\Sync\Readers\ApiSpreadsheetReader;
use App\Sync\Readers\JsonApiReader;
use App\Sync\Readers\SpreadsheetReader;
use Illuminate\Http\Client\Factory as Http;
use InvalidArgumentException;

class ReaderFactory
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Http $http,
        private readonly ApiTokens $tokens,
    ) {}

    public function forFile(string $path, ?string $label = null): SourceReader
    {
        // The API path reads the configured delimiter; an uploaded file must
        // not be parsed by a different rule.
        $delimiter = config('hdid.sync.csv_delimiter', ',');

        return new SpreadsheetReader(
            $path,
            $this->mapping(),
            $label,
            (string) ($this->settings->string(SettingKey::SyncCsvEncoding) ?: 'UTF-8'),
            is_string($delimiter) && strlen($delimiter) === 1 ? $delimiter : null,
        );
    }

    public function forApi(): SourceReader
    {
        $url = config('hdid.sync.api_url');

        if (! is_string($url) || $url === '') {
            throw new InvalidArgumentException(__('EMD_SYNC_API_URL is not configured.'));
        }

        if (config('hdid.sync.driver', 'json') === 'spreadsheet') {
            $format = config('hdid.sync.export_format', 'xlsx');
            if (! in_array($format, ['csv', 'xlsx'], true)) {
                throw new InvalidArgumentException(__('EMD export format must be csv or xlsx.'));
            }

            $delimiter = config('hdid.sync.csv_delimiter', ',');
            if ($format === 'csv' && (! is_string($delimiter) || strlen($delimiter) !== 1)) {
                throw new InvalidArgumentException(__('The CSV delimiter must be exactly one byte.'));
            }

            return new ApiSpreadsheetReader($this->http, $this->tokens, $this->settings, $url, $format);
        }

        if (config('hdid.sync.driver', 'json') !== 'json') {
            throw new InvalidArgumentException(__('Unknown EMD sync driver.'));
        }

        $headers = ['Accept' => 'application/json'];
        if ($token = config('hdid.sync.api_token')) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return new JsonApiReader(
            http: $this->http,
            url: $url,
            headers: $headers,
            mapping: $this->mapping(),
            dataPath: $this->settings->string(SettingKey::SyncApiDataPath) ?: null,
            pageParam: $this->settings->string(SettingKey::SyncApiPageParam) ?: null,
            timeout: (int) config('hdid.sync.api_timeout', 60),
        );
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function mapping(): array
    {
        return $this->settings->array(SettingKey::SyncColumnMapping);
    }
}
