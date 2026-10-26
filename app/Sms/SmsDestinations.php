<?php

namespace App\Sms;

use App\Models\Client;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Support\PhoneNormalizer;
use Collator;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Locale;

/**
 * The accepted countries of phone numbers (one list in the settings,
 * Hungary by default) and which numbers a text message may go to: mobile
 * numbers (or numbers the numbering plan does not tell apart from a
 * landline) of those countries. The price of an SMS is set by the
 * destination, so a premium-rate, foreign or non-geographic number chosen
 * by whoever adds it must never receive one. The Messenger asks this before
 * it queues any SMS; the portal takes only numbers of the accepted
 * countries and flags the ones no SMS may go to; the panel says both in
 * advance. Staff and the EMD sync may record any number.
 */
class SmsDestinations
{
    /** @var list<string>|null worked out once per process: it depends on the installed metadata only */
    private static ?array $regionsWithoutSeparateMobileNumbers = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly PhoneNormalizer $phones,
    ) {}

    /**
     * The accepted countries as ISO 3166 region codes (HU, AT, …).
     *
     * @return list<string>
     */
    public function allowedRegions(): array
    {
        return self::normalizeRegions($this->settings->array(SettingKey::PhoneAllowedRegions));
    }

    /**
     * Whether the number is a valid one of an accepted country: the portal
     * takes only these. A non-geographic number ("001") never is.
     */
    public function acceptsRegionOf(string $number): bool
    {
        $region = $this->phones->regionOf($number);

        return $region !== null && in_array($region, $this->allowedRegions(), true);
    }

    /**
     * Why no text message may go to the number, or null when it may.
     */
    public function refusal(string $number): ?SmsRefusal
    {
        $region = $this->phones->regionOf($number);

        return match (true) {
            $region === null => SmsRefusal::InvalidNumber,
            ! in_array($region, $this->allowedRegions(), true) => SmsRefusal::RegionNotAllowed,
            ! $this->phones->isMobile($number) => SmsRefusal::NotMobile,
            default => null,
        };
    }

    public function allows(string $number): bool
    {
        return $this->refusal($number) === null;
    }

    /**
     * The number a text message to the client goes to (the SMS sign-in code,
     * the PIN by SMS): the primary one when it may receive SMS, otherwise
     * the first other number that may, so a landline added first and made
     * primary on its own does not swallow the client's codes. Null when no
     * number of the client may receive SMS.
     */
    public function targetFor(Client $client): ?string
    {
        $numbers = $client->relationLoaded('phoneNumbers') ? $client->phoneNumbers : $client->phoneNumbers()->get();
        $ordered = $numbers->sortByDesc(fn ($phone): bool => (bool) $phone->is_primary)->pluck('number_e164');

        return $ordered->first(fn (string $number): bool => $this->allows($number));
    }

    /**
     * Region codes as stored: upper case, each once, in alphabetical order.
     *
     * @param  array<array-key, mixed>  $regions
     * @return list<string>
     */
    public static function normalizeRegions(array $regions): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            fn (mixed $region): string => strtoupper(trim((string) $region)),
            $regions,
        ))));
        sort($codes);

        return $codes;
    }

    /**
     * Every country the numbering plan knows, as code => "name (+calling code)"
     * in the language of the page, for the settings field.
     *
     * @return array<string, string>
     */
    public static function regionOptions(): array
    {
        $util = PhoneNumberUtil::getInstance();
        $locale = app()->getLocale();
        $options = [];

        foreach ($util->getSupportedRegions() as $code) {
            $options[$code] = self::regionName($code).' (+'.$util->getCountryCodeForRegion($code).')';
        }

        return self::sortByName($options, $locale);
    }

    /**
     * The name of the country in the language of the page ("Hungary",
     * "Magyarország"); the code itself when the system has no name for it.
     */
    public static function regionName(string $code): string
    {
        $name = class_exists(Locale::class) ? Locale::getDisplayRegion('-'.$code, app()->getLocale()) : null;

        return is_string($name) && $name !== '' && $name !== $code ? $name : $code;
    }

    /**
     * The names of the countries, in the alphabetical order of the page's language.
     *
     * @param  array<array-key, string>  $codes
     * @return list<string>
     */
    public static function regionNames(array $codes): array
    {
        $names = [];

        foreach ($codes as $code) {
            $names[$code] = self::regionName($code);
        }

        return array_values(self::sortByName($names, app()->getLocale()));
    }

    /**
     * Countries whose numbering plan does not tell every mobile number apart
     * from a landline (libphonenumber gives FIXED_LINE_OR_MOBILE for their
     * example landline or mobile number, as for the United States): a text
     * message may reach a landline there, so the settings page names them.
     * Worked out from the installed metadata, not kept by hand.
     *
     * @return list<string>
     */
    public static function regionsWithoutSeparateMobileNumbers(): array
    {
        if (self::$regionsWithoutSeparateMobileNumbers !== null) {
            return self::$regionsWithoutSeparateMobileNumbers;
        }

        $util = PhoneNumberUtil::getInstance();
        $regions = [];

        foreach ($util->getSupportedRegions() as $region) {
            foreach ([PhoneNumberType::FIXED_LINE, PhoneNumberType::MOBILE] as $type) {
                $example = $util->getExampleNumberForType($region, $type);

                if ($example !== null && $util->getNumberType($example) === PhoneNumberType::FIXED_LINE_OR_MOBILE) {
                    $regions[] = $region;

                    break;
                }
            }
        }

        return self::$regionsWithoutSeparateMobileNumbers = self::normalizeRegions($regions);
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, string>  $names
     * @return array<TKey, string>
     */
    private static function sortByName(array $names, string $locale): array
    {
        if (class_exists(Collator::class)) {
            (new Collator($locale))->asort($names);
        } else {
            asort($names);
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public static function knownRegions(): array
    {
        return array_values(PhoneNumberUtil::getInstance()->getSupportedRegions());
    }
}
