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

it('signs in through the login form component, chosen by its name, not the first snapshot that mentions the page', function (): void {
    $script = loadTestScript();
    preg_match('/export function officeSignIn\(\) \{.*?^\}/ms', $script, $signIn);

    // The notifications component of the login page carries the page path too; sending the
    // credentials to it is a 500, never a sign-in, and the burst would measure nothing.
    expect($signIn[0])->not->toContain("value.includes('login')")
        ->and($signIn[0])->toContain('JSON.parse(value).memo.name')
        ->and($script)->toContain("LOGIN_COMPONENT = __ENV.LOGIN_COMPONENT || 'Pages\\\\Auth\\\\Login'");
});

it('demands the limits off only for the capacity run', function (): void {
    $script = loadTestScript();

    expect($script)->toContain("MODE === 'capacity'")
        ->and(substr_count($script, 'rate_limited_requests: [\'rate==0\']'))->toBe(1);
});

it('finds the polling widgets by their Livewire names, the class names of the page', function (): void {
    $script = loadTestScript();

    // The snapshot's memo.name is the component class (App\Filament\Admin\Widgets\OngoingCallsWidget),
    // so kebab-case suffixes find no widget and the office run measures nothing.
    expect($script)->toContain("(__ENV.POLL_COMPONENTS || 'OngoingCallsWidget,MissedCallsWidget,OverviewStatsWidget')")
        ->and($script)->not->toContain('ongoing-calls-widget');
});

it('waits a polling interval after a dashboard that could not be read, instead of retrying at once', function (): void {
    $script = loadTestScript();
    preg_match('/export function office\(\) \{.*?^\}/ms', $script, $office);

    // A VU that throws on every iteration loops without sleep and floods the stack itself.
    expect($office[0])->toContain('catch (error)')
        ->and($office[0])->toMatch('/catch \(error\) \{.*?sleep\(POLL_SECONDS\);\s*return;/s');
});

it('counts a sign-in as refused unless it redirects into the panel, and a rate-limited login page too', function (): void {
    $script = loadTestScript();
    preg_match('/export function officeSignIn\(\) \{.*?^\}/ms', $script, $signIn);

    // Filament puts the throttle notification into the session for the next request, so its text is not
    // in the answer: only a sign-in that redirects into the panel got in. A login page refused with 429
    // is a refusal too, not a sign-in that never happened.
    expect($signIn[0])->toContain('effects.redirect')
        ->and($signIn[0])->toContain('page.status === 429')
        ->and($script)->not->toContain('THROTTLE_TEXT');
});
