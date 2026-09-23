<?php

namespace App\Audit;

use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes audit entries. Every domain event that matters goes through here.
 */
class Auditor
{
    /** @var list<string> */
    private const GUARDS = ['web', 'client', 'sanctum'];

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly ?Request $request = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $event,
        ?Model $subject = null,
        array $context = [],
        ?Authenticatable $actor = null,
    ): AuditLog {
        $actor ??= $this->currentActor();

        return AuditLog::query()->create([
            'event' => $event,
            'actor_type' => $actor instanceof Model ? $actor->getMorphClass() : null,
            'actor_id' => $actor?->getAuthIdentifier(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'context' => $context,
            'ip_address' => $this->request?->ip(),
            'user_agent' => $this->request?->userAgent() ? mb_substr($this->request->userAgent(), 0, 500) : null,
            'request_id' => $this->requestId(),
        ]);
    }

    /**
     * One id per HTTP request, so the entries of a single request can be read
     * together: the caller's X-Request-Id if it sent one, otherwise generated
     * on first use and echoed back in the response header (SecurityHeaders).
     */
    public const REQUEST_ID_ATTRIBUTE = 'hdid.request_id';

    private function requestId(): ?string
    {
        // A command or queue job has no routed request behind it.
        if ($this->request === null || $this->request->route() === null) {
            return null;
        }

        $id = $this->request->attributes->get(self::REQUEST_ID_ATTRIBUTE)
            ?? mb_substr(trim((string) $this->request->header('X-Request-Id')), 0, 64)
            ?: (string) Str::uuid7();

        $this->request->attributes->set(self::REQUEST_ID_ATTRIBUTE, $id);

        return $id;
    }

    private function currentActor(): ?Authenticatable
    {
        foreach (self::GUARDS as $guard) {
            $user = $this->auth->guard($guard)->user();

            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }
}
