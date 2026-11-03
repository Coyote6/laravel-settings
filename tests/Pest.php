<?php

use Coyote6\LaravelSettings\Tests\TestCase;
use Illuminate\Support\Facades\DB;

uses(TestCase::class)->in(__DIR__);

/**
 * Count the select queries run while the callback runs.
 *
 * @label Count Selects
 *
 * @param  Closure  $callback  The code to measure
 */
function countSelects(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $selects = collect(DB::getQueryLog())
        ->filter(fn (array $query) => str_starts_with(strtolower($query['query']), 'select'))
        ->count();

    DB::disableQueryLog();

    return $selects;
}
