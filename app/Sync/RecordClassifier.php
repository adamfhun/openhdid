<?php

namespace App\Sync;

use App\Enums\PrincipalType;
use App\Models\ExternalRecord;
use App\Settings\SettingKey;
use App\Settings\SettingRules;
use App\Settings\Settings;

/** Classifies only domains explicitly listed as staff or client; staff takes precedence. */
class RecordClassifier
{
    public function __construct(private readonly Settings $settings) {}

    public function classify(ExternalRecordDto $dto): ?PrincipalType
    {
        $domain = (string) ExternalRecord::domainOf($dto->email);

        if (in_array($domain, $this->normalized(SettingKey::SyncUserDomains), true)) {
            return PrincipalType::User;
        }

        if (in_array($domain, $this->normalized(SettingKey::SyncClientDomains), true)) {
            return PrincipalType::Client;
        }

        return null;
    }

    /**
     * Why classify() returned null for this row.
     */
    public function skipReason(ExternalRecordDto $dto): string
    {
        return SkippedRow::REASON_UNCLASSIFIED;
    }

    /**
     * @return list<string>
     */
    private function normalized(SettingKey $key): array
    {
        return SettingRules::normalizeDomains($this->settings->array($key));
    }
}
