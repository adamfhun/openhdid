<?php

/**
 * The CI workflows run third-party code with the repository checked out: the private repository's
 * CI (.github/workflows/ci.yml) and the public release workflows (deploy/openhdid/repo/.github/workflows,
 * the root of an exported tree). Every one of them pins its actions to a commit SHA, runs with a
 * read-only, non-persisted token and without npm install scripts (TEL-6, F19).
 */

/**
 * @return array<string, string> workflow path => content
 */
function supplyChainWorkflows(): array
{
    $files = [
        ...(glob(base_path('.github/workflows/*.yml')) ?: []),
        ...(glob(base_path('deploy/openhdid/repo/.github/workflows/*.yml')) ?: []),
    ];

    $workflows = [];
    foreach ($files as $file) {
        $workflows[substr($file, strlen(base_path()) + 1)] = (string) file_get_contents($file);
    }

    if ($workflows === []) {
        test()->markTestSkipped('This tree has no workflows.');
    }

    return $workflows;
}

/**
 * The lines of a YAML block: the key's own line and every following line indented deeper.
 *
 * @param  list<string>  $lines
 * @return list<string>
 */
function supplyChainBlock(array $lines, int $start, int $indent): array
{
    $block = [$lines[$start]];
    for ($i = $start + 1; $i < count($lines); $i++) {
        if (trim($lines[$i]) !== '' && strlen($lines[$i]) - strlen(ltrim($lines[$i])) <= $indent) {
            break;
        }
        $block[] = $lines[$i];
    }

    return $block;
}

/**
 * What a workflow does unsafely (TEL-6): an action not pinned to a commit SHA with its version as a
 * comment, an action pinned differently across the workflows, no workflow-level permissions, write-all
 * or pull_request_target, a checkout that keeps its token, npm install scripts, and an event value
 * expanded straight into a run script.
 *
 * @param  array<string, string>  $workflows  workflow path => content
 * @return list<string>
 */
function supplyChainProblems(array $workflows): array
{
    $problems = [];
    $pins = [];

    foreach ($workflows as $path => $workflow) {
        $lines = explode("\n", $workflow);

        if (! preg_match('/^permissions:/m', $workflow)) {
            $problems[] = "{$path}: no workflow-level permissions";
        }
        // pull_request_target as a mapping key, as the only event or in an event list (not in a comment).
        if (preg_match('/^\s*permissions:\s*write-all/m', $workflow) || preg_match('/^[^#\n]*\bpull_request_target\b/m', $workflow)) {
            $problems[] = "{$path}: write-all permissions or pull_request_target";
        }

        foreach ($lines as $number => $line) {
            $where = $path.':'.($number + 1);

            if (preg_match('/^(\s*)(?:-\s+)?uses:\s*(\S+)(.*)$/', $line, $uses)) {
                if (! preg_match('/^([\w.-]+\/[\w.\/-]+)@([0-9a-f]{40})$/', $uses[2], $ref) || ! preg_match('/^\s+#\s*(v?\d+(?:\.\d+)*)\s*$/', $uses[3], $tag)) {
                    $problems[] = "{$where}: {$uses[2]} is not pinned to a commit SHA with its version as a comment";
                } else {
                    $pins[$ref[1]][$ref[2].' '.$tag[1]][] = $where;
                }

                if (str_starts_with($uses[2], 'actions/checkout@')) {
                    $step = implode("\n", supplyChainBlock($lines, $number, strlen($uses[1])));
                    if (! preg_match('/^\s+persist-credentials:\s*false\s*$/m', $step)) {
                        $problems[] = "{$where}: actions/checkout keeps the token in .git/config";
                    }
                }
            }

            if (preg_match('/^(\s*)(?:-\s+)?run:/', $line, $run)) {
                $script = implode("\n", supplyChainBlock($lines, $number, strlen($run[1])));
                if (preg_match('/\$\{\{\s*github\.(event\.|head_ref)/', $script)) {
                    $problems[] = "{$where}: an event value is expanded straight into a run script";
                }
                if (preg_match_all('/\bnpm\s+(?:ci|install)\b[^\n&|;]*/', $script, $installs)) {
                    foreach ($installs[0] as $install) {
                        if (! str_contains($install, '--ignore-scripts')) {
                            $problems[] = "{$where}: \"{$install}\" runs the install scripts of the dependencies";
                        }
                    }
                }
            }
        }
    }

    // One pin per action across the workflows, so the private CI and the release run the same code.
    foreach ($pins as $action => $variants) {
        if (count($variants) > 1) {
            $problems[] = "{$action} is pinned differently: ".implode('; ', array_map(
                fn (string $pin, array $places): string => $pin.' ('.implode(', ', $places).')',
                array_keys($variants),
                $variants,
            ));
        }
    }

    return $problems;
}

it('pins every action of every workflow to a commit SHA and runs with read-only, non-persisted credentials and without install scripts', function (): void {
    expect(supplyChainProblems(supplyChainWorkflows()))->toBe([]);
});

it('flags an unsafe workflow in every form it can take', function (string $workflow, string $problem): void {
    $problems = supplyChainProblems(['.github/workflows/unsafe.yml' => $workflow]);

    expect(implode("\n", $problems))->toContain($problem);
})->with([
    'pull_request_target as a mapping key' => ["on:\n  pull_request_target:\n    types: [opened]\npermissions:\n  contents: read\n", 'pull_request_target'],
    'pull_request_target as the only event' => ["on: pull_request_target\npermissions:\n  contents: read\n", 'pull_request_target'],
    'pull_request_target in an event list' => ["on: [push, pull_request_target]\npermissions:\n  contents: read\n", 'pull_request_target'],
    'no workflow-level permissions' => ["on: push\njobs:\n  test:\n    runs-on: ubuntu-24.04\n", 'no workflow-level permissions'],
    'an action on a movable tag' => ["on: push\npermissions:\n  contents: read\njobs:\n  test:\n    steps:\n      - uses: actions/setup-node@v4\n", 'is not pinned'],
    'a checkout that keeps its token' => ["on: push\npermissions:\n  contents: read\njobs:\n  test:\n    steps:\n      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1\n      - run: true\n", 'keeps the token'],
    'npm install scripts' => ["on: push\npermissions:\n  contents: read\njobs:\n  test:\n    steps:\n      - run: |\n          npm ci\n          npm run build\n", 'install scripts'],
    'an event value in a run script' => ["on: issues\npermissions:\n  contents: read\njobs:\n  test:\n    steps:\n      - run: echo \"\${{ github.event.issue.title }}\"\n", 'event value'],
]);

it('runs every workflow job on a fixed runner image', function (): void {
    $problems = [];

    foreach (supplyChainWorkflows() as $path => $workflow) {
        foreach (explode("\n", $workflow) as $number => $line) {
            if (preg_match('/^\s*(?:runner|runs-on):\s*(.+?)\s*$/', $line, $runner)
                && ! preg_match('/^(ubuntu-\d{2}\.\d{2}(-arm)?|\$\{\{\s*matrix\.runner\s*\}\})$/', $runner[1])) {
                $problems[] = $path.':'.($number + 1).": {$runner[1]}";
            }
        }
    }

    expect($problems)->toBe([]);
});
