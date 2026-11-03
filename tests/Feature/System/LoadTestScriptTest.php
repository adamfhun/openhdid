<?php

/*
 * The k6 load script (deploy/k6/hdid.js) measures two things: the capacity of
 * a profile with the per-address limits off, and, with MODE=office, a call
 * centre behind one NAT address with the limits on, the way it really meets
 * nginx: the dashboard widgets' Livewire updates and a shift-start sign-in
 * burst. The run itself is a measurement on a live stack; this test only
 * pins what the script measures. The script is not part of the public tree.
 */

function loadTestScript(): string
{
    $path = base_path('deploy/k6/hdid.js');
    if (! is_file($path)) {
        test()->markTestSkipped('The k6 load script is not part of this tree.');
    }

    return (string) file_get_contents($path);
}

it('measures a call centre behind one address with the nginx limits on, counting every 429 of the polling phase as a failure', function (): void {
    $script = loadTestScript();

    expect($script)->toContain("MODE === 'office'")
        ->and($script)->toContain('export function office()')
        ->and($script)->toContain("'rate_limited_requests{scenario:office,phase:steady}': ['rate==0']")
        // The polls go the way the browser sends them: Livewire update POSTs, not whole page loads.
        ->and($script)->toContain('data-update-uri')
        ->and($script)->toContain('$refresh')
        // A shift-start burst of sign-ins from the same address.
        ->and($script)->toContain('export function officeSignIn()');
});

it('demands the limits off only for the capacity run', function (): void {
    $script = loadTestScript();

    expect($script)->toContain("MODE === 'capacity'")
        ->and(substr_count($script, 'rate_limited_requests: [\'rate==0\']'))->toBe(1);
});
