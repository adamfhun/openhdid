<?php

namespace App\Sms;

/**
 * Why no text message may go to a number (SmsDestinations): the cost of an
 * SMS is set by the destination, so only mobile numbers of the accepted
 * countries receive one.
 */
enum SmsRefusal: string
{
    case InvalidNumber = 'invalid_number';
    case RegionNotAllowed = 'region_not_allowed';
    case NotMobile = 'not_mobile';

    /**
     * The reason as a short clause, for "No text message goes to this number (…)".
     */
    public function label(): string
    {
        return match ($this) {
            self::InvalidNumber => __('not a valid phone number'),
            self::RegionNotAllowed => __('the country is not on the accepted list'),
            self::NotMobile => __('not a mobile number'),
        };
    }

    /**
     * What the form of the client page's Phone numbers tab says while staff
     * type such a number: it is stored all the same, it still recognises
     * the caller.
     */
    public function staffWarning(): string
    {
        return match ($this) {
            self::RegionNotAllowed => __('This number is not of an accepted country: no text message goes to it (the SMS login code, the PIN by SMS and the SMS confirmation cannot reach it), and the client could not add it on the portal. It still recognises the caller.'),
            default => __('This number cannot be used for text messages (:reason): the SMS login code, the PIN by SMS and the SMS confirmation cannot reach it. It still recognises the caller.', ['reason' => $this->label()]),
        };
    }

    /**
     * The short note under such a number in the list of the Phone numbers tab.
     */
    public function staffNote(): string
    {
        return match ($this) {
            self::RegionNotAllowed => __('Not an accepted country: no text messages go to it'),
            default => __('Cannot be used for text messages: :reason', ['reason' => $this->label()]),
        };
    }

    /**
     * What the portal tells the client who asks for a confirmation code to
     * such a number; the portal shows the same sentence from its own
     * dictionary by the reason code (portalReason()).
     */
    public function portalMessage(): string
    {
        return match ($this) {
            self::InvalidNumber => __('This is not a valid phone number.'),
            self::RegionNotAllowed => __('We do not send text messages to phone numbers of this country, so this number cannot be confirmed by SMS code. The helpdesk can confirm it for you.'),
            self::NotMobile => __('This number cannot receive text messages (it is not a mobile number), so it cannot be confirmed by SMS code. The helpdesk can confirm it for you.'),
        };
    }

    /**
     * The machine-readable reason of the portal's 422 answer.
     */
    public function portalReason(): string
    {
        return 'sms_'.$this->value;
    }
}
