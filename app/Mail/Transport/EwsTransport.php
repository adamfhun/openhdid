<?php

namespace App\Mail\Transport;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends mail through Exchange Web Services (CreateItem with MIME content).
 * Authentication is basic, NTLM, or an Entra client-credentials token,
 * chosen by `hdid.ews.auth`.
 *
 * A refused request is reported with what the operator needs to act: the
 * HTTP status, the authentication methods the server offers on a 401, the
 * EWS response code and message text when the SOAP answer carries one, and
 * an explicit note when the body is empty.
 *
 * Exchange keeps a SendAndSaveCopy message in the Sent Items of the sending
 * mailbox, where anyone who can read that mailbox finds it. So every message
 * goes out SendOnly unless its mailable says it carries no secret with
 * keepSavedCopyHeaders(): a mailable that forgets to say anything (a new one,
 * a framework notification such as a password reset) never leaves a login
 * link or a code in the mailbox. The marker is removed before the message is
 * built. In a failover chain a later leg (SMTP) may send the marker header on
 * to the recipient; it only says "keep a copy" and carries no data.
 */
class EwsTransport extends AbstractTransport
{
    /** Internal marker of a mail without a secret, whose copy may stay in the Sent Items; never sent by EWS. */
    public const KEEP_SAVED_COPY_HEADER = 'X-HDID-Keep-Saved-Copy';

    /** The marker of earlier versions (a mail with a secret); removed if a mailable still sets it. */
    public const NO_SAVED_COPY_HEADER = 'X-HDID-No-Saved-Copy';

    /** Whether the message send() is handing to doSend() keeps a copy in the Sent Items. */
    private bool $saveCopy = false;

    /**
     * The marker header for a mail that carries no secret, so Exchange may
     * keep its copy. Only a mailer that sends through EWS (alone, or as a
     * named leg of a failover or round-robin mailer) gets it, because only
     * this transport removes it again.
     *
     * @return array<string, string>
     */
    public static function keepSavedCopyHeaders(?string $mailer = null): array
    {
        $config = (array) config('mail.mailers.'.($mailer ?? config('mail.default')), []);
        $legs = array_filter((array) ($config['mailers'] ?? []), is_string(...));
        $viaEws = ($config['transport'] ?? null) === 'ews'
            || array_filter($legs, fn (string $leg): bool => config("mail.mailers.{$leg}.transport") === 'ews') !== [];

        return $viaEws ? [self::KEEP_SAVED_COPY_HEADER => '1'] : [];
    }

    /**
     * @param  array{url: string, auth: string, username: ?string, password: ?string, oauth: array{tenant: ?string, client_id: ?string, client_secret: ?string, scope?: string}, verify_tls: bool}  $config
     */
    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly array $config,
    ) {
        parent::__construct();
    }

    /** Test seam: null reads the real libcurl, a bool pretends. */
    public static ?bool $ntlmSupported = null;

    public function __toString(): string
    {
        return 'ews';
    }

    /**
     * Whether this PHP's libcurl can do NTLM. Alpine's curl cannot, which is
     * why the OpenHDID image builds its own libcurl (Dockerfile); a classic
     * installation depends on the distribution's curl.
     */
    public static function ntlmSupported(): bool
    {
        return self::$ntlmSupported ?? (bool) (curl_version()['features'] & CURL_VERSION_NTLM);
    }

    public static function ntlmUnavailableMessage(): string
    {
        return __('EWS_AUTH=ntlm cannot work here: the libcurl of this PHP has no NTLM support. Use a PHP whose libcurl has NTLM (the OpenHDID image from 1.8.0 does), basic (enable basic authentication on the EWS virtual directory), oauth (Exchange Online), or an SMTP connector (MAIL_MAILER=smtp).');
    }

    /**
     * Reads and removes the markers, so they never reach Exchange or the
     * recipient, then sends as usual: SendOnly unless the mail is marked as
     * carrying no secret.
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $headers = $message instanceof Message ? $message->getHeaders() : null;
        $saveCopy = $headers !== null && $headers->has(self::KEEP_SAVED_COPY_HEADER) && ! $headers->has(self::NO_SAVED_COPY_HEADER);

        if ($headers !== null && ($headers->has(self::KEEP_SAVED_COPY_HEADER) || $headers->has(self::NO_SAVED_COPY_HEADER))) {
            $message = clone $message;
            $message->getHeaders()->remove(self::KEEP_SAVED_COPY_HEADER);
            $message->getHeaders()->remove(self::NO_SAVED_COPY_HEADER);
        }

        $this->saveCopy = $saveCopy;

        try {
            return parent::send($message, $envelope);
        } finally {
            $this->saveCopy = false;
        }
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->config['auth'] === 'ntlm' && ! self::ntlmSupported()) {
            throw new TransportException('EWS CreateItem failed: '.self::ntlmUnavailableMessage());
        }

        $mime = base64_encode($message->toString());

        // SendOnly takes no SavedItemFolderId: Exchange keeps no copy of the message.
        [$disposition, $savedItemFolder] = $this->saveCopy
            ? ['SendAndSaveCopy', '<m:SavedItemFolderId><t:DistinguishedFolderId Id="sentitems"/></m:SavedItemFolderId>']
            : ['SendOnly', ''];

        $body = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"
               xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages">
  <soap:Header><t:RequestServerVersion Version="Exchange2016"/></soap:Header>
  <soap:Body>
    <m:CreateItem MessageDisposition="{$disposition}">{$savedItemFolder}
      <m:Items><t:Message><t:MimeContent CharacterSet="UTF-8">{$mime}</t:MimeContent></t:Message></m:Items>
    </m:CreateItem>
  </soap:Body>
</soap:Envelope>
XML;

        try {
            $response = $this->request()
                ->withBody($body, 'text/xml; charset=utf-8')
                ->post($this->config['url']);
        } catch (ConnectionException $e) {
            throw new TransportException('EWS CreateItem failed: the server could not be reached ('.$e->getMessage().'). Check EWS_URL, the firewall, the outbound proxy (HDID_HTTP_PROXY) and the CA of an internal certificate.', 0, $e);
        }

        if ($response->failed() || ! str_contains($response->body(), 'ResponseClass="Success"')) {
            throw new TransportException('EWS CreateItem failed: '.self::describe($response));
        }
    }

    /**
     * What went wrong, in one line: status, the server's authentication
     * offer on a 401, the EWS response code and text when there is one, the
     * start of any other body, or the fact that the body is empty.
     */
    public static function describe(Response $response): string
    {
        $status = $response->status();
        $parts = ['HTTP '.$status.($response->reason() !== '' ? ' '.$response->reason() : '')];

        if ($status === 401 && ($offer = $response->header('WWW-Authenticate')) !== '') {
            $parts[] = 'the server offers: '.$offer;
        }

        $body = trim($response->body());

        if ($body === '') {
            $parts[] = 'empty response body';
        } elseif (preg_match('/<(?:[a-z]+:)?ResponseCode>([^<]+)</i', $body, $code) === 1) {
            $parts[] = 'EWS '.trim($code[1]);
            if (preg_match('/<(?:[a-z]+:)?MessageText>([^<]+)</i', $body, $text) === 1) {
                $parts[] = html_entity_decode(trim($text[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        } elseif (preg_match('/<(?:[a-z]+:)?faultstring>([^<]+)</i', $body, $fault) === 1) {
            $parts[] = 'SOAP fault: '.html_entity_decode(trim($fault[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        } else {
            $contentType = $response->header('Content-Type');
            $parts[] = (str_contains($contentType, 'text/html') ? 'HTML answer (sign-in page or proxy?): ' : '')
                .mb_substr(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '', 0, 300);
        }

        return implode(' · ', $parts);
    }

    private function request(): PendingRequest
    {
        $request = $this->http->timeout(30)->withOptions(['verify' => $this->config['verify_tls']]);

        // Guzzle 8 handles basic and digest itself and ignores other `auth` types, so an
        // `auth => [user, password, 'ntlm']` request went out without credentials. NTLM is
        // libcurl's own: it is set on the handle, and libcurl runs the three-step handshake.
        return match ($this->config['auth']) {
            'ntlm' => $request->withOptions(['curl' => [
                CURLOPT_HTTPAUTH => CURLAUTH_NTLM,
                CURLOPT_USERPWD => $this->config['username'].':'.$this->config['password'],
            ]]),
            'oauth' => $request->withToken($this->oauthToken()),
            default => $request->withBasicAuth((string) $this->config['username'], (string) $this->config['password']),
        };
    }

    private function oauthToken(): string
    {
        $oauth = $this->config['oauth'];

        // Keyed by the app registration: a cache shared with another installation
        // must never hand this one the other's token.
        $key = 'ews.oauth.token.'.hash('sha256', ($oauth['tenant'] ?? '').'|'.($oauth['client_id'] ?? '').'|'.($oauth['scope'] ?? 'https://outlook.office365.com/.default'));

        return $this->cache->remember($key, 3000, function () use ($oauth): string {
            $response = $this->http->asForm()->post(
                "https://login.microsoftonline.com/{$oauth['tenant']}/oauth2/v2.0/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => $oauth['client_id'],
                    'client_secret' => $oauth['client_secret'],
                    'scope' => $oauth['scope'] ?? 'https://outlook.office365.com/.default',
                ],
            )->throw();

            return (string) $response->json('access_token');
        });
    }
}
