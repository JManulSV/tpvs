<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const TEST_DATABASE = 'tpvs_test';

    /**
     * Abort before any test touches a database other than tpvs_test.
     *
     * The check lives here and not in setUp() because RefreshDatabase runs
     * inside parent::setUp() and would already have wiped the database.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $database = $app['config']->get('database.connections.pgsql.database');

        if ($database !== self::TEST_DATABASE) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against database "%s". Tests only run against "%s". '
                .'Use `make test` or `docker compose exec -T -e DB_DATABASE=%s tpvs-backend php artisan test`.',
                $database,
                self::TEST_DATABASE,
                self::TEST_DATABASE,
            ));
        }

        return $app;
    }
}
