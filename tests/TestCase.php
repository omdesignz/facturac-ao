<?php

namespace Tests;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();
        if (getenv('VERIFICATION_RUNTIME_PG_RESET') === '1') {
            $application->make(Kernel::class)->rerouteSymfonyCommandEvents();
        }
        $application['events']->listen(CommandStarting::class, function ($event) use ($application): void {
            if ($event->command !== 'migrate:fresh' || getenv('VERIFICATION_RUNTIME_PG_RESET') !== '1') {
                return;
            }
            $connection = $application['db']->connection();
            if (! $application->environment('testing') || $connection->getDriverName() !== 'pgsql'
                || ! str_starts_with($connection->getDatabaseName(), 'facturac_test_verification_cr1_')) {
                throw new \RuntimeException('Disposable verification schema reset refused');
            }
            $connection->unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
        });

        return $application;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

    }
}
