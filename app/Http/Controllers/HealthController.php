<?php

namespace App\Http\Controllers;

use App\System\CheckStatus;
use App\System\HealthChecks;
use Illuminate\Http\JsonResponse;

/**
 * Machine-readable health for monitoring: 200 when nothing fails, 503
 * otherwise. Only check names and statuses are exposed; the details (hosts,
 * counts) stay on the authenticated status page.
 */
class HealthController extends Controller
{
    public function __invoke(HealthChecks $checks): JsonResponse
    {
        $results = $checks->all();
        $failed = array_values(array_filter($results, fn ($c) => $c->status === CheckStatus::Fail));

        return response()->json([
            'status' => $failed === [] ? 'ok' : 'fail',
            'checks' => array_map(fn ($c) => ['key' => $c->key, 'status' => $c->status->value], $results),
            'checked_at' => now()->toIso8601String(),
        ], $failed === [] ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
