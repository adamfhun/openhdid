<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * SQLite keeps no length on a string column, MariaDB refuses a longer value in
 * strict mode: a 13-character "undeliverable" in a 10-character status column
 * stopped every SMS delivery report (fixed in 1.15.5). The MariaDB run of the
 * suite (CI) checks every enum-cast column against its longest value.
 */
it('fits every value of an enum cast into its database column', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite keeps no string length; the MariaDB run of the suite checks it.');
    }

    $checked = 0;
    $problems = [];
    foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');
        if (! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $model = new $class;
        $columns = collect(Schema::getColumns($model->getTable()))->keyBy('name');

        foreach ($model->getCasts() as $column => $cast) {
            $enum = Str::before((string) $cast, ':');
            $type = (string) ($columns[$column]['type'] ?? '');
            if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class) || ! preg_match('/char\((\d+)\)/', $type, $length)) {
                continue;
            }

            $checked++;
            $longest = max(array_map(fn (BackedEnum $case): int => mb_strlen((string) $case->value), $enum::cases()));
            if ($longest > (int) $length[1]) {
                $problems[] = "{$model->getTable()}.{$column} is {$type}, {$enum} needs {$longest}";
            }
        }
    }

    expect($checked)->toBeGreaterThan(10)
        ->and($problems)->toBe([]);
});
