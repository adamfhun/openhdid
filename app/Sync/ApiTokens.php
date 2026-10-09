<?php

namespace App\Sync;

use App\Support\HuDate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

class ApiTokens
{
    public const REFRESH_MINUTES = 15;

    public function __construct(private readonly Http $http) {}

    public function isConfigured(): bool
    {
        return collect(['login_url', 'refresh_url', 'username', 'password'])
            ->every(fn (string $key): bool => filled(config('hdid.sync.'.$key)));
    }

    /**
     * One line for the sync page: when the token was last refreshed and whether
     * it can be expected to work. Null when the file export integration is off.
     */
    public function describe(): ?string
    {
        if (config('hdid.sync.driver') !== 'spreadsheet') {
            return null;
        }

        if (! $this->isConfigured()) {
            return __('EMD API token: login is not configured (EMD_SYNC_LOGIN_URL, EMD_SYNC_REFRESH_URL, EMD_SYNC_USERNAME, EMD_SYNC_PASSWORD).');
        }

        $status = $this->status();

        if (! empty($status['error'])) {
            $attempt = ! empty($status['last_attempt']) ? Carbon::parse($status['last_attempt'])->format(HuDate::DATETIME) : '-';

            return __('EMD API token: the last refresh failed (:time): :error', ['time' => $attempt, 'error' => $status['error']]);
        }

        if (empty($status['last_success'])) {
            return __('EMD API token: no successful login yet. A trial run or hdid:emd-sync-token checks the credentials.');
        }

        $lastSuccess = Carbon::parse($status['last_success']);
        $values = ['time' => $lastSuccess->format(HuDate::DATETIME), 'ago' => $lastSuccess->diffForHumans()];

        if (config('hdid.sync.token_keep_alive') && $lastSuccess->lt(now()->subMinutes(self::REFRESH_MINUTES + 5))) {
            return __('EMD API token: last refreshed :time (:ago); the 15-minute keep-alive seems to have stopped, check the scheduler.', $values);
        }

        return __('EMD API token: last refreshed :time (:ago), presumably valid.', $values);
    }

    public function accessToken(): string
    {
        return $this->rotate(false);
    }

    public function refresh(): void
    {
        $this->rotate(true);
    }

    /** @return array{last_success?: string, last_attempt?: string, error?: string|null} */
    public function status(): array
    {
        return Cache::get($this->cacheKey().':status', []);
    }

    private function rotate(bool $force): string
    {
        return Cache::lock($this->cacheKey().':lock', 120)->block(5, function () use ($force): string {
            try {
                if (! $this->isConfigured()) {
                    throw new RuntimeException(__('EMD API login is not configured.'));
                }

                $stored = Cache::get($this->cacheKey());
                $tokens = is_string($stored) ? json_decode(Crypt::decryptString($stored), true, flags: JSON_THROW_ON_ERROR) : null;

                if (! $force && $tokens !== null && Carbon::parse($tokens['refreshed_at'])->gt(now()->subMinutes(self::REFRESH_MINUTES))) {
                    return $tokens['access_token'];
                }

                if ($tokens === null) {
                    $tokens = $this->tokensFrom($this->login());
                } else {
                    try {
                        $tokens = $this->tokensFrom($this->request('refresh_url', ['refreshToken' => $tokens['refresh_token']]));
                    } catch (RuntimeException) {
                        // Whatever the refresh answers (expired or revoked token, an
                        // unexpected status, a broken body, no connection), one fresh
                        // login follows: without the keep-alive the stored refresh
                        // token can be days old, and a stuck one would block every sync.
                        $tokens = $this->tokensFrom($this->login());
                    }
                }

                Cache::forever($this->cacheKey(), Crypt::encryptString(json_encode($tokens, JSON_THROW_ON_ERROR)));
                Cache::forever($this->cacheKey().':status', [
                    'last_success' => $tokens['refreshed_at'], 'last_attempt' => $tokens['refreshed_at'], 'error' => null,
                ]);

                return $tokens['access_token'];
            } catch (Throwable $exception) {
                Cache::forever($this->cacheKey().':status', array_merge($this->status(), [
                    'last_attempt' => now()->toIso8601String(), 'error' => $exception->getMessage(),
                ]));

                throw $exception;
            }
        });
    }

    /**
     * @return array{access_token: string, refresh_token: string, refreshed_at: string}
     */
    private function tokensFrom(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException(__('EMD API authentication failed (HTTP :status).', ['status' => $response->status()]));
        }

        $body = $response->json();
        if (! is_array($body) || ! is_string($body['access_token'] ?? null) || trim($body['access_token']) === ''
            || ! is_string($body['refresh_token'] ?? null) || trim($body['refresh_token']) === '') {
            throw new RuntimeException(__('EMD API authentication returned an invalid token response.'));
        }

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'],
            'refreshed_at' => now()->toIso8601String(),
        ];
    }

    private function login(): Response
    {
        return $this->request('login_url', [
            'Password' => config('hdid.sync.password'),
            'Username' => config('hdid.sync.username'),
        ]);
    }

    /** @param array<string, string> $payload */
    private function request(string $route, array $payload): Response
    {
        try {
            return $this->http->acceptJson()->asJson()->withoutRedirecting()
                ->connectTimeout(10)->timeout((int) config('hdid.sync.auth_timeout', 20))
                ->post(config('hdid.sync.'.$route), $payload);
        } catch (ConnectionException) {
            // Do not retain the exception: its request can contain credentials.
            throw new RuntimeException(__('EMD API authentication could not connect or timed out.'));
        }
    }

    private function cacheKey(): string
    {
        $identity = collect(['login_url', 'refresh_url', 'username', 'password'])
            ->map(fn (string $key): mixed => config('hdid.sync.'.$key))->all();

        return 'hdid.sync.tokens:'.hash_hmac('sha256', json_encode($identity, JSON_THROW_ON_ERROR), config('app.key'));
    }
}
