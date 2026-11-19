<?php

namespace App\System;

use App\Audit\Auditor;
use App\Messaging\Channel;
use App\Messaging\Messenger;
use App\Models\OutboundMessage;
use App\Support\HuDate;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * HTTP 500 Report: a full report of an unexpected error, by e-mail to the
 * addresses of HDID_ERROR_MAIL_TO, through the outbound messages (a row and
 * a queued job, like every message). The answers of a web request that
 * send one are listed in HDID_ERROR_MAIL_STATUSES (empty: 500); an error
 * outside a web request (queue job, command, scheduled task) counts as 500.
 *
 * In a web request the reporting only notes the error, and the outermost
 * middleware (ReportServerErrors) decides on the final answer, so an error
 * is reported once, with its status. Elsewhere the report goes out when the
 * error is reported. The same error (class, file, line) goes out at most
 * once in REPEAT_MINUTES, the next report counts the repeats in between,
 * and HOURLY_LIMIT caps the reports of all errors together.
 *
 * Secrets never go into the report: request input, headers, query string
 * and command options are masked by name, the trace has no arguments, a
 * database error has its SQL without the bound values, and a password in
 * an address or message is masked. Personal data (names, addresses) stays,
 * as the report is for the administrator; the body is wiped once sent.
 * Nothing here ever throws: a report that cannot be queued (the database is
 * down) leaves a log line, the error itself is logged anyway.
 */
final class ErrorMail
{
    /** Request attribute of the errors noted during a web request. */
    public const REQUEST_ATTRIBUTE = 'hdid.error_mail';

    public const REPEAT_MINUTES = 15;

    public const HOURLY_LIMIT = 20;

    public const DEFAULT_STATUS = 500;

    private const MAX_TRACE_FRAMES = 60;

    private const MAX_PREVIOUS = 5;

    private const MAX_VALUE_LENGTH = 2000;

    private const MAX_BLOCK_LENGTH = 20000;

    /** Names whose value is a secret: input fields, headers, query keys, command options. */
    private const SECRET_NAME = '/pass|pwd|secret|token|pin|otp|code|answer|signature|key|cookie|session|auth|csrf|xsrf|credential|hash|lookup|checksum/i';

    private const MASK = '***';

    /** True while a report is being composed or queued: an error inside it is not reported again. */
    private static bool $busy = false;

    /** True while a report's own failed delivery is reported (SendOutboundMessage). */
    private static bool $muted = false;

    /** @var array{name: string, queue: ?string, connection: string, attempts: int, id: ?string}|null */
    private static ?array $job = null;

    public function __construct(private readonly Messenger $messenger) {}

    public function enabled(): bool
    {
        return $this->recipients() !== [];
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        return array_values(array_filter(self::entries((string) config('hdid.error_mail.to')), self::isAddress(...)));
    }

    /**
     * The 5xx answers that send a report; 500 when none is set (or none of
     * the entries is one).
     *
     * @return list<int>
     */
    public function statuses(): array
    {
        $statuses = array_map(intval(...), array_values(array_filter(self::entries((string) config('hdid.error_mail.statuses')), self::isStatus(...))));

        return $statuses === [] ? [self::DEFAULT_STATUS] : array_values(array_unique($statuses));
    }

    /**
     * Entries of the two keys that are neither an address nor a 5xx status;
     * they are left out (the container does not start with one).
     *
     * @return list<string>
     */
    public function ignoredEntries(): array
    {
        return [
            ...array_map(fn (string $entry): string => 'HDID_ERROR_MAIL_TO: '.$entry, array_values(array_filter(self::entries((string) config('hdid.error_mail.to')), fn (string $entry): bool => ! self::isAddress($entry)))),
            ...array_map(fn (string $entry): string => 'HDID_ERROR_MAIL_STATUSES: '.$entry, array_values(array_filter(self::entries((string) config('hdid.error_mail.statuses')), fn (string $entry): bool => ! self::isStatus($entry)))),
        ];
    }

    /**
     * The exception handler's hook: in a web request the error is noted for
     * the final answer, elsewhere it is reported now (as a 500).
     */
    public function reported(Throwable $exception): void
    {
        if (self::$busy || self::$muted || ! $this->enabled()) {
            return;
        }

        $request = app()->bound('request') ? app('request') : null;

        if ($request instanceof Request && $request->attributes->has(self::REQUEST_ATTRIBUTE)) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, [...(array) $request->attributes->get(self::REQUEST_ATTRIBUTE), $exception]);

            return;
        }

        if (in_array(self::DEFAULT_STATUS, $this->statuses(), true)) {
            $this->send(self::DEFAULT_STATUS, $exception, null);
        }
    }

    /**
     * After the answer of a web request: a listed 5xx answer is reported with
     * the error behind it; an error the request reported but answered
     * without a 5xx counts as 500.
     */
    public function afterRequest(Request $request, Response $response): void
    {
        /** @var list<Throwable> $noted */
        $noted = array_values((array) $request->attributes->get(self::REQUEST_ATTRIBUTE, []));
        $request->attributes->remove(self::REQUEST_ATTRIBUTE);

        if (self::$busy || ! $this->enabled()) {
            return;
        }

        $status = $response->getStatusCode();
        $behind = isset($response->exception) && $response->exception instanceof Throwable ? $response->exception : null;

        if ($status >= 500 && in_array($status, $this->statuses(), true)) {
            $exception = $behind ?? ($noted[0] ?? null);
            $this->send($status, $exception, $request, array_values(array_filter($noted, fn (Throwable $other): bool => $other !== $exception)));

            return;
        }

        if ($noted !== [] && in_array(self::DEFAULT_STATUS, $this->statuses(), true)) {
            $this->send(self::DEFAULT_STATUS, $noted[0], $request, array_slice($noted, 1), $status);
        }
    }

    /**
     * A sample report to every address, regardless of the repeat limits
     * (hdid:test-error-mail).
     *
     * @return list<OutboundMessage>
     */
    public function sendSample(): array
    {
        $exception = new \RuntimeException('HTTP 500 Report test (php artisan hdid:test-error-mail)');

        return $this->queue(...$this->compose(self::DEFAULT_STATUS, $exception, null, [], null, ['repeats' => 0, 'dropped' => 0]));
    }

    /**
     * Runs the callback without reporting what it reports: the failed delivery
     * of a report must not send another report.
     */
    public static function muted(Closure $callback): void
    {
        $previous = self::$muted;
        self::$muted = true;

        try {
            $callback();
        } finally {
            self::$muted = $previous;
        }
    }

    /** The queue job a worker runs now (JobProcessing). */
    public static function jobStarted(Job $job): void
    {
        self::$job = [
            'name' => $job->resolveName(),
            'queue' => $job->getQueue(),
            'connection' => $job->getConnectionName(),
            'attempts' => $job->attempts(),
            'id' => $job->getJobId() === null ? null : (string) $job->getJobId(),
        ];
    }

    /** No job runs any more (JobProcessed, or the worker looks for the next one). */
    public static function jobEnded(): void
    {
        self::$job = null;
    }

    /**
     * @param  list<Throwable>  $others
     */
    private function send(int $status, ?Throwable $exception, ?Request $request, array $others = [], ?int $answered = null): void
    {
        self::$busy = true;

        try {
            $fingerprint = $exception !== null
                ? $status.'|'.$exception::class.'|'.$exception->getFile().'|'.$exception->getLine()
                : $status.'|'.($request?->method() ?? '').'|'.($request?->route()?->getName() ?? $request?->path() ?? '');
            $counts = $this->admit(hash('sha256', $fingerprint));

            if ($counts !== null) {
                $this->queue(...$this->compose($status, $exception, $request, $others, $answered, $counts));
            }
        } catch (Throwable $failure) {
            Log::warning('HTTP 500 Report could not be queued: '.$failure::class.': '.self::clean($failure->getMessage()));
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Whether the report may go out now, with the repeats held back since
     * the last one of this error and the reports held back by the hourly
     * limit; null when it waits.
     *
     * @return array{repeats: int, dropped: int}|null
     */
    private function admit(string $fingerprint): ?array
    {
        $key = Installation::key('error-mail:'.$fingerprint);
        $repeats = $key.':repeats';
        $dropped = Installation::key('error-mail:dropped');

        if (! Cache::add($key, true, now()->addMinutes(self::REPEAT_MINUTES))) {
            Cache::add($repeats, 0, now()->addDays(7));
            Cache::increment($repeats);

            return null;
        }

        $hour = Installation::key('error-mail:hour:'.now()->utc()->format('YmdH'));
        Cache::add($hour, 0, now()->addHours(2));

        if ((int) Cache::increment($hour) > self::HOURLY_LIMIT) {
            Cache::add($dropped, 0, now()->addDays(7));
            Cache::increment($dropped);

            return null;
        }

        return ['repeats' => (int) Cache::pull($repeats, 0), 'dropped' => (int) Cache::pull($dropped, 0)];
    }

    /**
     * @return list<OutboundMessage>
     */
    private function queue(string $subject, string $body): array
    {
        $messages = [];

        foreach ($this->recipients() as $address) {
            $messages[] = $this->messenger->sendRaw(Channel::Email, $address, $subject, $body, carriesSecret: true, meta: ['error_report' => true]);
        }

        return $messages;
    }

    /**
     * The subject and the HTML body, in the application's own language.
     *
     * @param  list<Throwable>  $others
     * @param  array{repeats: int, dropped: int}  $counts
     * @return array{0: string, 1: string}
     */
    private function compose(int $status, ?Throwable $exception, ?Request $request, array $others, ?int $answered, array $counts): array
    {
        $previousLocale = app()->getLocale();
        app()->setLocale((string) config('app.locale'));

        try {
            $host = (string) (gethostname() ?: '');
            $what = $exception !== null ? class_basename($exception) : $status.' '.(Response::$statusTexts[$status] ?? '');
            $subject = __('[:app] Server error (:status): :what (:host)', ['app' => config('app.name'), 'status' => $status, 'what' => $what, 'host' => $host]);

            $sections = [];
            $summary = [
                __('Time') => now()->format(HuDate::DATETIME_SECONDS).' ('.now()->utc()->toIso8601String().')',
                __('Status') => (string) $status.($answered !== null ? ' '.__('(the request was answered with :status)', ['status' => $answered]) : ''),
                __('Where') => $request !== null ? __('web request') : (self::$job !== null ? __('queue job') : __('command or scheduled task')),
                __('Error') => $exception !== null ? $exception::class : __('no exception behind the answer'),
                __('Message') => $exception !== null ? self::message($exception) : '',
                __('File') => $exception !== null ? self::path($exception->getFile()).':'.$exception->getLine() : '',
            ];

            if ($counts['repeats'] > 0) {
                $summary[__('Repeats')] = __('This error occurred :n more time(s) since its last report (at most one report every :minutes minutes).', ['n' => $counts['repeats'], 'minutes' => self::REPEAT_MINUTES]);
            }

            if ($counts['dropped'] > 0) {
                $summary[__('Held back')] = __(':n report(s) of other errors were held back by the hourly limit (:limit); see the log.', ['n' => $counts['dropped'], 'limit' => self::HOURLY_LIMIT]);
            }

            $sections[] = [__('Summary'), $summary];

            if ($request !== null) {
                $sections[] = [__('Request'), $this->requestFacts($request)];
            }

            if (self::$job !== null && $request === null) {
                $sections[] = [__('Queue job'), [
                    __('Job') => self::$job['name'],
                    __('Queue') => (self::$job['connection']).' / '.(self::$job['queue'] ?? 'default'),
                    __('Attempt') => (string) self::$job['attempts'],
                    __('Job id') => (string) self::$job['id'],
                ]];
            } elseif ($request === null) {
                $sections[] = [__('Command'), [__('Command line') => self::commandLine()]];
            }

            $sections[] = [__('Environment'), $this->environmentFacts()];

            $blocks = [];

            if ($exception !== null) {
                $blocks[__('Trace')] = self::trace($exception);
                $chain = self::previous($exception);

                if ($chain !== '') {
                    $blocks[__('Previous exceptions')] = $chain;
                }
            }

            if ($others !== []) {
                $blocks[__('Further errors reported in the same request')] = implode("\n\n", array_map(fn (Throwable $other): string => $other::class.': '.self::message($other)."\n".self::path($other->getFile()).':'.$other->getLine(), $others));
            }

            if ($request !== null) {
                $blocks[__('Request input (secrets masked)')] = self::json(self::mask($request->input()));
                $blocks[__('Request headers (secrets masked)')] = self::json(self::mask(array_map(fn (array $values): string => implode(', ', $values), $request->headers->all())));
            }

            return [$subject, self::html($subject, $sections, $blocks)];
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * @return array<string, string>
     */
    private function requestFacts(Request $request): array
    {
        $route = $request->route();
        $facts = [
            __('Method and address') => $request->method().' '.self::maskedUrl($request),
            __('Route') => trim(($route?->getName() ?? '').' '.($route?->getActionName() ?? '')),
            __('Request id') => (string) $request->attributes->get(Auditor::REQUEST_ID_ATTRIBUTE, ''),
            __('Client address') => (string) $request->ip(),
            __('Browser') => mb_substr((string) $request->userAgent(), 0, 500),
        ];

        $livewire = self::livewireCalls($request);

        if ($livewire !== '') {
            $facts[__('Livewire')] = $livewire;
        }

        $user = self::actor();

        if ($user !== null) {
            $facts[__('Signed-in user')] = class_basename($user).' '.$user->getAuthIdentifier()
                .($user instanceof Model ? ' · '.trim(((string) ($user->getAttribute('name') ?? '')).' '.((string) ($user->getAttribute('email') ?? ''))) : '');
        }

        if (defined('LARAVEL_START')) {
            $facts[__('Duration')] = round((microtime(true) - LARAVEL_START) * 1000).' ms';
        }

        return $facts;
    }

    /**
     * @return array<string, string>
     */
    private function environmentFacts(): array
    {
        return [
            __('Application') => config('app.name').' · '.config('app.url'),
            __('Version') => (string) (config('hdid.version') ?? '-'),
            __('Environment') => (string) config('app.env').(config('app.debug') ? ' · debug' : ''),
            __('Server') => (string) (gethostname() ?: '-'),
            __('PHP') => PHP_VERSION.' · '.PHP_SAPI.' · Laravel '.Application::VERSION,
            __('Memory peak') => round(memory_get_peak_usage(true) / 1048576, 1).' MB',
            __('Process') => (string) getmypid(),
        ];
    }

    private static function actor(): ?Authenticatable
    {
        try {
            $auth = app('auth');
            $user = $auth->guard()->user();

            foreach (['web', 'client'] as $guard) {
                $user ??= $auth->guard($guard)->user();
            }

            return $user;
        } catch (Throwable) {
            return null;
        }
    }

    /** The components and the called methods of a Livewire update. */
    private static function livewireCalls(Request $request): string
    {
        $components = $request->input('components');

        if (! is_array($components)) {
            return '';
        }

        $parts = [];

        foreach ($components as $component) {
            $snapshot = is_array($component) && is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : null;
            $name = is_array($snapshot) ? (string) ($snapshot['memo']['name'] ?? '?') : '?';
            $calls = is_array($component['calls'] ?? null) ? array_map(fn (mixed $call): string => is_array($call) ? (string) ($call['method'] ?? '?') : '?', $component['calls']) : [];
            $parts[] = $name.($calls !== [] ? ' → '.implode(', ', $calls) : '');
        }

        return implode('; ', $parts);
    }

    private static function maskedUrl(Request $request): string
    {
        $query = $request->query();

        return $request->url().($query === [] ? '' : '?'.urldecode(http_build_query(self::mask($query))));
    }

    private static function commandLine(): string
    {
        $arguments = array_map(function (mixed $argument): string {
            $argument = (string) $argument;

            return preg_match('/^(--?[^=]+)=/', $argument, $match) === 1 && preg_match(self::SECRET_NAME, $match[1]) === 1
                ? $match[1].'='.self::MASK
                : $argument;
        }, (array) ($_SERVER['argv'] ?? []));

        return implode(' ', $arguments);
    }

    /**
     * The values under a secret name masked, at any depth (everything below
     * a secret name too); a JSON text (a Livewire snapshot) is opened and
     * masked the same way; long values are cut.
     */
    private static function mask(mixed $value, bool $secret = false): mixed
    {
        if (is_array($value)) {
            $masked = [];

            foreach ($value as $key => $item) {
                $masked[$key] = self::mask($item, $secret || (is_string($key) && preg_match(self::SECRET_NAME, $key) === 1));
            }

            return $masked;
        }

        if ($secret) {
            return $value === null || $value === '' ? $value : self::MASK;
        }

        if (is_string($value)) {
            $first = ltrim($value)[0] ?? '';

            if ($first === '{' || $first === '[') {
                $decoded = json_decode($value, true);

                if (is_array($decoded)) {
                    return self::mask($decoded);
                }
            }

            return mb_strlen($value) > self::MAX_VALUE_LENGTH ? mb_substr($value, 0, self::MAX_VALUE_LENGTH).'…' : $value;
        }

        return $value;
    }

    /**
     * The message without what may be a secret: a database error gives its
     * SQL without the bound values, a password in an address or a name=value
     * pair is masked.
     */
    private static function message(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            $driver = $exception->getPrevious()?->getMessage() ?? '';

            return self::clean($driver)."\nSQL: ".$exception->getSql()."\n".__('(:n bound value(s) left out)', ['n' => count($exception->getBindings())]);
        }

        if ($exception instanceof HttpExceptionInterface && $exception->getMessage() === '') {
            return (string) (Response::$statusTexts[$exception->getStatusCode()] ?? '');
        }

        return self::clean($exception->getMessage());
    }

    private static function clean(string $text): string
    {
        $text = (string) preg_replace('#([a-z][a-z0-9+.-]*://)[^/\s:@]+:[^/\s@]+@#i', '$1'.self::MASK.':'.self::MASK.'@', $text);
        $text = (string) preg_replace('/\b(password|passwd|pwd|secret|client_secret|token|access_token|refresh_token|api_key|apikey|signature)(["\']?\s*[=:]\s*["\']?)[^\s&"\',;]+/i', '$1$2'.self::MASK, $text);

        return mb_strlen($text) > self::MAX_BLOCK_LENGTH ? mb_substr($text, 0, self::MAX_BLOCK_LENGTH).'…' : $text;
    }

    /** The frames without their arguments (a password or a PIN may be one). */
    private static function trace(Throwable $exception): string
    {
        $lines = [];

        foreach (array_slice($exception->getTrace(), 0, self::MAX_TRACE_FRAMES) as $index => $frame) {
            $where = isset($frame['file']) ? self::path((string) $frame['file']).':'.($frame['line'] ?? '?') : '[internal]';
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '').'()';
            $lines[] = '#'.$index.' '.$where.' '.$call;
        }

        $more = count($exception->getTrace()) - self::MAX_TRACE_FRAMES;

        if ($more > 0) {
            $lines[] = __('… :n more frame(s)', ['n' => $more]);
        }

        return implode("\n", $lines);
    }

    private static function previous(Throwable $exception): string
    {
        $parts = [];
        $previous = $exception->getPrevious();

        for ($depth = 0; $previous !== null && $depth < self::MAX_PREVIOUS; $depth++, $previous = $previous->getPrevious()) {
            $parts[] = $previous::class.': '.self::message($previous)."\n".self::path($previous->getFile()).':'.$previous->getLine();
        }

        return implode("\n\n", $parts);
    }

    private static function path(string $file): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    private static function json(mixed $value): string
    {
        $text = (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return mb_strlen($text) > self::MAX_BLOCK_LENGTH ? mb_substr($text, 0, self::MAX_BLOCK_LENGTH).'…' : $text;
    }

    /**
     * A plain, colourless HTML report: every value escaped.
     *
     * @param  list<array{0: string, 1: array<string, string>}>  $sections
     * @param  array<string, string>  $blocks
     */
    private static function html(string $title, array $sections, array $blocks): string
    {
        $html = '<div style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.45;">';
        $html .= '<h2 style="font-size: 18px; margin: 0 0 12px;">'.e($title).'</h2>';

        foreach ($sections as [$heading, $facts]) {
            $html .= '<h3 style="font-size: 15px; margin: 18px 0 6px;">'.e($heading).'</h3><table cellpadding="4" cellspacing="0" style="border-collapse: collapse;">';

            foreach ($facts as $label => $value) {
                if ($value === '') {
                    continue;
                }

                $html .= '<tr><td style="vertical-align: top; white-space: nowrap; padding-right: 16px;"><strong>'.e($label).'</strong></td><td style="vertical-align: top; white-space: pre-wrap; word-break: break-word;">'.e($value).'</td></tr>';
            }

            $html .= '</table>';
        }

        foreach ($blocks as $heading => $text) {
            $html .= '<h3 style="font-size: 15px; margin: 18px 0 6px;">'.e($heading).'</h3><pre style="font-family: Menlo, Consolas, monospace; font-size: 12px; white-space: pre-wrap; word-break: break-word; margin: 0;">'.e($text).'</pre>';
        }

        return $html.'<p style="font-size: 12px; margin-top: 18px;">'.e(__('HTTP 500 Report (HDID_ERROR_MAIL_TO). The same error is reported at most once every :minutes minutes, all errors together at most :limit times an hour; everything is also in the application log.', ['minutes' => self::REPEAT_MINUTES, 'limit' => self::HOURLY_LIMIT])).'</p></div>';
    }

    /**
     * @return list<string>
     */
    private static function entries(string $value): array
    {
        // Commas or spaces, as the container start check reads it.
        return array_values(array_filter(preg_split('/[\s,]+/', $value) ?: [], fn (string $entry): bool => $entry !== ''));
    }

    private static function isAddress(string $entry): bool
    {
        return filter_var($entry, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function isStatus(string $entry): bool
    {
        return preg_match('/^5\d\d$/', $entry) === 1;
    }
}
