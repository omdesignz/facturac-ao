<?php

namespace App\Fiscal;

use Closure;
use Illuminate\Support\Facades\DB;

final class AssistantExecutionGuard
{
    private float $started;

    public function __construct()
    {
        $this->started = hrtime(true) / 1e9;
    }

    public function remaining(): float
    {
        return max(0, 30 - (hrtime(true) / 1e9 - $this->started));
    }

    public function check(): void
    {
        abort_if(hrtime(true) / 1e9 - $this->started >= 30, 503);
    }

    public function plannerFinished(float $started): void
    {
        abort_if(hrtime(true) / 1e9 - $started >= 10, 503);
        $this->check();
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function database(Closure $operation): mixed
    {
        $connection = DB::connection();
        abort_unless($connection->getDriverName() === 'pgsql' || ($connection->getDriverName() === 'sqlite' && app()->environment('testing')), 503);
        abort_if($connection->transactionLevel() !== 0, 503);
        foreach (['cache.stores.database.connection', 'cache.stores.database.lock_connection', 'activitylog.database_connection'] as $configuration) {
            abort_unless(config($configuration) === null || config($configuration) === $connection->getName(), 503);
        }
        $events = $connection->getEventDispatcher();
        $logging = $connection->logging();
        $readPdo = $connection->getRawReadPdo();
        $connection->unsetEventDispatcher();
        $connection->disableQueryLog();
        $previous = null;
        try {
            $connection->setReadPdo($connection->getPdo());
            if ($connection->getDriverName() === 'pgsql') {
                $previous = $connection->selectOne("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout", [], false);
                $connection->statement("SET statement_timeout = '2s'");
                $connection->statement("SET lock_timeout = '250ms'");
            }

            return $operation();
        } finally {
            try {
                if ($previous !== null) {
                    $connection->select("SELECT set_config('statement_timeout', ?, false), set_config('lock_timeout', ?, false)", [$previous->statement_timeout, $previous->lock_timeout], false);
                }
                $connection->setReadPdo($readPdo);
            } catch (\Throwable) {
                DB::purge();
                abort(503);
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
}
