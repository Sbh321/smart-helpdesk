<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

/**
 * Base for feature tests: every test runs in a rolled-back transaction on helpdesk_test.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Refuses to wipe anything but a test database, whatever the environment says.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $database = (string) config('database.connections.pgsql_owner.database');

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to refresh database [{$database}]: tests only run against *_test databases.");
        }
    }

    /**
     * Migrations run as the owner role, exactly as in `just migrate`; the tests themselves
     * query through the default connection as the runtime role (docs/08-database/overview.md).
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => 'pgsql_owner',
            '--drop-views' => true,
            '--drop-types' => true,
            '--seed' => false,
        ];
    }
}
