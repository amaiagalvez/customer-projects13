<?php

namespace CustomerProjects13\Tests\Support;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

final class RacesNameInsert
{
    public static function afterUniquenessSelect(string $table, string $name, Closure $factory): void
    {
        $competitorCreated = false;

        DB::listen(static function (QueryExecuted $query) use (
            $table,
            $name,
            $factory,
            &$competitorCreated,
        ): void {
            if (
                $competitorCreated
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains(strtolower($query->sql), strtolower($table))
                || ! in_array($name, $query->bindings, true)
            ) {
                return;
            }

            $competitorCreated = true;
            $factory();
        });
    }
}
