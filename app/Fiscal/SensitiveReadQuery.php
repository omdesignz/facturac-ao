<?php

namespace App\Fiscal;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class SensitiveReadQuery
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function run(Closure $operation): mixed
    {
        $connection = DB::connection();
        $events = $connection->getEventDispatcher();
        $logging = $connection->logging();
        $connection->unsetEventDispatcher();
        $connection->disableQueryLog();
        try {
            return $operation();
        } catch (QueryException) {
            throw new \RuntimeException('Master-data read failed.');
        } finally {
            if ($events !== null) {
                $connection->setEventDispatcher($events);
            }
            if ($logging) {
                $connection->enableQueryLog();
            }
        }
    }
}
