<?php

namespace App\Http\Middleware;

use App\Enums\PrincipalType;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps last_seen_at on the signed-in account, at most once in five
 * minutes per account (a cache marker debounces the write), with a plain
 * update that fires no model event. The capacity tile counts the active
 * accounts from it, whatever the session store is.
 */
class TouchLastSeen
{
    public const DEBOUNCE_SECONDS = 300;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Cache $cache,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->signedIn($request) as [$type, $id]) {
            if ($this->cache->add('hdid.seen.'.$type->value.'.'.$id, 1, self::DEBOUNCE_SECONDS)) {
                $type->modelClass()::query()->whereKey($id)->update(['last_seen_at' => now()]);
            }
        }

        return $response;
    }

    /**
     * The accounts this request authenticated: the session guards, and the
     * token guard when a bearer token came with the request.
     *
     * @return list<array{0: PrincipalType, 1: string}>
     */
    private function signedIn(Request $request): array
    {
        $seen = [];

        foreach (PrincipalType::cases() as $type) {
            $account = $this->auth->guard($type->guard())->user();
            if ($account === null && $request->bearerToken() !== null) {
                $account = $this->auth->guard('sanctum')->user();
            }

            if ($account !== null && $account::class === $type->modelClass()) {
                $seen[$type->value] = [$type, (string) $account->getAuthIdentifier()];
            }
        }

        return array_values($seen);
    }
}
