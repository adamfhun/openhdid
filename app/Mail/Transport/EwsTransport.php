<?php

namespace App\Mail\Transport;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Sends mail through Exchange Web Services (CreateItem with MIME content).
 * Authentication is basic, NTLM, or an Entra client-credentials token,
 * chosen by `hdid.ews.auth`.
 *
 * A refused request is reported with what the operator needs to act: the
 * HTTP status, the authentication methods the server offers on a 401, the
 * EWS response code and message text when the SOAP answer carries one, and
 * an explicit note when the body is empty.
 */
class EwsTransport extends AbstractTransport
{
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

    protected function doSend(SentMessage $message): void
    {
        if ($this->config['auth'] === 'ntlm' && ! self::ntlmSupported()) {
            throw new TransportException('EWS CreateItem failed: '.self::ntlmUnavailableMessage());
        }

        $mime = base64_encode($message->toString());

        $body = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:t="http://schemas.microsoft.com/exchange/services/2006/types"
               xmlns:m="http://schemas.microsoft.com/exchange/services/2006/messages">
  <soap:Header><t:RequestServerVersion Version="Exchange2016"/></soap:Header>
  <soap:Body>
    <m:CreateItem MessageDisposition="SendAndSaveCopy">
      <m:SavedItemFolderId><t:DistinguishedFolderId Id="sentitems"/></m:SavedItemFolderId>
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

        return match ($this->config['auth']) {
            'ntlm' => $request->withOptions(['auth' => [$this->config['username'], $this->config['password'], 'ntlm']]),
            'oauth' => $request->withToken($this->oauthToken()),
            default => $request->withBasicAuth((string) $this->config['username'], (string) $this->config['password']),
        };
    }

    private function oauthToken(): string
    {
        $oauth = $this->config['oauth'];

        return $this->cache->remember('ews.oauth.token', 3000, function () use ($oauth): string {
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
