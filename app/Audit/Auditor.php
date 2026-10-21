<?php

namespace App\Audit;

use App\Models\AuditLog;
use Closure;
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

    /**
     * Where a bulk job sends the entries it keeps elsewhere (see divert()).
     * Static on purpose: the auditor is resolved anew by every caller, the
     * diversion must hold for the whole process while the job runs.
     *
     * @var (Closure(string, ?Model, array<string, mixed>): bool)|null
     */
    private static ?Closure $sink = null;

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

        $attributes = [
            'event' => $event,
            'actor_type' => $actor instanceof Model ? $actor->getMorphClass() : null,
            'actor_id' => $actor?->getAuthIdentifier(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'context' => $context,
            'ip_address' => $this->request?->ip(),
            'user_agent' => $this->request?->userAgent() ? mb_substr($this->request->userAgent(), 0, 500) : null,
            'request_id' => $this->requestId(),
        ];

        // A diverted entry is handed back unsaved: the caller gets the same
        // shape, the audit_log table gets nothing.
        if (self::$sink !== null && (self::$sink)($event, $subject, $context)) {
            return new AuditLog($attributes);
        }

        return AuditLog::query()->create($attributes);
    }

    /**
     * Routes the entries a bulk job keeps on its own record (the EMD sync
     * stores the per-record diffs on the run, not in the audit log) to the
     * given sink while the job runs. The sink returns true for an entry it
     * took; every other entry is written as usual. The returned closure ends
     * the diversion and must run in a finally block.
     *
     * @param  Closure(string, ?Model, array<string, mixed>): bool  $sink
     * @return Closure(): void
     */
    public static function divert(Closure $sink): Closure
    {
        $previous = self::$sink;
        self::$sink = $sink;

        return static function () use ($previous): void {
            self::$sink = $previous;
        };
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
