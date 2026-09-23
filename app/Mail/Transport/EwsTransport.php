<?php

namespace App\Mail\Transport;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Sends mail through Exchange Web Services (CreateItem with MIME content).
 * Authentication is basic, NTLM, or an Entra client-credentials token,
 * chosen by `hdid.ews.auth`.
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

    public function __toString(): string
    {
        return 'ews';
    }

    protected function doSend(SentMessage $message): void
    {
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

        $response = $this->request()
            ->withBody($body, 'text/xml; charset=utf-8')
            ->post($this->config['url']);

        if ($response->failed() || ! str_contains($response->body(), 'ResponseClass="Success"')) {
            throw new TransportException('EWS CreateItem failed: '.mb_substr($response->body(), 0, 500));
        }
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
