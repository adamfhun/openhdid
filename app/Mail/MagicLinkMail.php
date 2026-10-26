<?php

namespace App\Mail;

use App\Branding\Branding;
use App\Mail\Transport\EwsTransport;
use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The one-time login link. The body comes from the editable "login link"
 * message template; the markdown view is only a fallback.
 */
class MagicLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Client $client,
        public readonly string $url,
        public readonly int $ttlMinutes,
        public readonly ?string $subjectLine = null,
        public readonly ?string $htmlBody = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine ?? __('Your :app login link', ['app' => app(Branding::class)->appName()]));
    }

    public function content(): Content
    {
        if ($this->htmlBody !== null) {
            return new Content(htmlString: $this->htmlBody);
        }

        return new Content(markdown: 'mail.magic-link', with: ['branding' => app(Branding::class)->toArray()]);
    }

    /**
     * The link signs in whoever opens it, so no copy may stay in the Sent
     * Items of an Exchange mailbox: no keep-a-copy marker (EwsTransport).
     */
    public function headers(): Headers
    {
        return new Headers;
    }
}
