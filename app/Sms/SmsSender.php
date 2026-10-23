<?php

namespace App\Sms;

interface SmsSender
{
    /**
     * Hands the message to the gateway. Returns the gateway's own id of the
     * message when it gives one (used to ask for the delivery report
     * later), null when the driver has no such thing.
     *
     * @param  string  $to  E.164 number
     *
     * @throws SmsException when the gateway refused or could not be reached
     */
    public function send(string $to, string $message): ?string;

    /**
     * Asks the gateway what became of a message it accepted earlier. Null
     * when the driver cannot report; an SmsException when the gateway could
     * not be asked or answered with an error.
     */
    public function deliveryStatus(string $messageId): ?SmsDeliveryReport;
}
