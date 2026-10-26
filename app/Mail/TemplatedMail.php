<?php

namespace App\Mail;

use App\Mail\Transport\EwsTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * A fully rendered HTML e-mail produced from a message template.
 *
 * It counts as carrying a secret (a sign-in code, a login link) unless the
 * sender says otherwise, like MessageKey::carriesSecret(): such a mail is
 * not kept in the Sent Items of an Exchange mailbox (EwsTransport).
 */
class TemplatedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $htmlBody,
        public readonly bool $carriesSecret = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->htmlBody);
    }

    public function headers(): Headers
    {
        return new Headers(text: $this->carriesSecret ? [] : EwsTransport::keepSavedCopyHeaders($this->mailer));
    }
}
