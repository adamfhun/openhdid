<?php

use App\Support\Placeholders;

/**
 * The sample text of a field is the same on the portal and the panel: a
 * phone number field shows Placeholders::PHONE everywhere, a phone label
 * field the same sentence in both dictionaries.
 */
it('gives every phone number field of the portal and the panel the same sample', function (): void {
    $portal = (string) file_get_contents(resource_path('js/client/views/Phones.vue'));
    expect($portal)->toContain('v-model="number" type="tel" class="input" placeholder="'.Placeholders::PHONE.'"');

    $fields = 0;

    foreach (File::allFiles(app_path('Filament')) as $file) {
        // Each statement that builds a text input checked as a phone number.
        foreach (explode(';', $file->getContents()) as $statement) {
            foreach (preg_split('/(?=TextInput::make\()/', $statement) ?: [] as $chain) {
                if (! str_starts_with($chain, 'TextInput::make(') || ! str_contains($chain, 'new PhoneNumberRule')) {
                    continue;
                }

                $fields++;
                expect($chain)->toContain('->placeholder(Placeholders::PHONE)', '->tel()');
            }
        }
    }

    expect($fields)->toBeGreaterThanOrEqual(2);
});

it('gives the phone label field the same sample in both dictionaries', function (): void {
    $portal = (string) file_get_contents(resource_path('js/client/views/Phones.vue'));
    $dictionary = (string) file_get_contents(resource_path('js/client/i18n.js'));
    $hungarian = json_decode((string) file_get_contents(lang_path('hu.json')), true);

    expect($portal)->toContain(":placeholder=\"t('mobile, office…')\"")
        ->and($dictionary)->toContain("'mobile, office…': '".$hungarian['mobile, office…']."'")
        ->and(Placeholders::phoneLabel())->toBe('mobile, office…');
});
