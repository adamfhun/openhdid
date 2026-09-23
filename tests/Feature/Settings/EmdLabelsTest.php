<?php

use App\Enums\PhoneNumberSource;
use App\Settings\SettingKey;

it('names the EMD settings tab and fields through the dictionaries, not code exceptions', function (string $locale, string $tab, string $fields, string $source): void {
    app()->setLocale($locale);

    expect(SettingKey::SyncCsvEncoding->groupLabel())->toBe($tab)
        ->and(SettingKey::ClientsDirectoryFields->label())->toBe($fields)
        ->and(PhoneNumberSource::Sync->label())->toBe($source);
})->with([
    'Hungarian' => ['hu', 'Ügyféltörzs (EMD)', 'Megjelenített ügyféltörzs-oszlopok', 'ügyféltörzs'],
    'English' => ['en', 'Enterprise Master Data (EMD)', 'EMD fields', 'EMD'],
]);
