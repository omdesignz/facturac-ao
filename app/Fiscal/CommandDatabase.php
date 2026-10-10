<?php

namespace App\Fiscal;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CommandDatabase
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function bounded(Closure $operation): mixed
    {
        abort_unless(DB::getDriverName() === 'pgsql' || (DB::getDriverName() === 'sqlite' && app()->environment('testing')), 503);
        if (DB::getDriverName() !== 'pgsql') {
            return self::sensitive($operation);
        }
        $connection = DB::connection();
        $statement = $connection->selectOne('SHOW statement_timeout')->statement_timeout;
        $lock = $connection->selectOne('SHOW lock_timeout')->lock_timeout;
        try {
            $connection->select("SELECT set_config('statement_timeout','2s',false), set_config('lock_timeout','1s',false)");

            return self::sensitive($operation);
        } finally {
            try {
                $connection->select("SELECT set_config('statement_timeout',?,false), set_config('lock_timeout',?,false)", [$statement, $lock]);
            } catch (\Throwable) {
                DB::purge($connection->getName());
                abort(503);
            }
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function sensitive(Closure $operation, CommandCapability $capability = CommandCapability::CustomerCreate): mixed
    {
        $connection = DB::connection();
        $events = $connection->getEventDispatcher();
        $logging = $connection->logging();
        $connection->unsetEventDispatcher();
        $connection->disableQueryLog();
        try {
            return $operation();
        } catch (QueryException $exception) {
            $nif = (($exception->errorInfo[0] ?? '') === '23505' && str_contains((string) ($exception->errorInfo[2] ?? ''), '"'.($capability === CommandCapability::CustomerCreate ? 'customers_entity_tax_id_unique' : 'catalogue_items_entity_code_unique').'"'))
                || (DB::getDriverName() === 'sqlite' && str_contains((string) ($exception->errorInfo[2] ?? ''), ($capability === CommandCapability::CustomerCreate ? 'UNIQUE constraint failed: customers.legal_entity_id, customers.tax_identification_number' : 'UNIQUE constraint failed: catalogue_items.legal_entity_id, catalogue_items.code')));
            throw new HttpException($nif ? 409 : 503, $nif ? $capability->conflict() : '');
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
