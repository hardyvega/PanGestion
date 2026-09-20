<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create the application and verify the isolated testing database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        if (! $app->environment('testing')) {
            throw new RuntimeException('Test suite aborted: the application environment must be testing.');
        }

        $connectionName = $app['config']->get('database.default');

        if ($connectionName !== 'pgsql') {
            throw new RuntimeException('Test suite aborted: the default database connection must be pgsql.');
        }

        $configuredDatabase = $app['config']->get("database.connections.{$connectionName}.database");

        if ($configuredDatabase !== 'pangestion_test') {
            throw new RuntimeException('Test suite aborted: the configured database must be pangestion_test.');
        }

        try {
            $connection = $app['db']->connection($connectionName);
            $currentDatabase = $connection->scalar('SELECT current_database()');
            $currentUser = $connection->scalar('SELECT current_user');
        } catch (Throwable) {
            throw new RuntimeException('Test suite aborted: the PostgreSQL testing connection could not be verified.');
        }

        if ($currentDatabase !== 'pangestion_test') {
            throw new RuntimeException('Test suite aborted: the active database must be pangestion_test.');
        }

        if ($currentUser !== 'pangestion_test_user') {
            throw new RuntimeException('Test suite aborted: the active database user must be pangestion_test_user.');
        }

        return $app;
    }
}
