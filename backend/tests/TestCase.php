<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Passport\Passport;
use RuntimeException;
use Tests\Support\PassportTestKeys;

/**
 * Base for feature tests: every test runs in a rolled-back transaction on helpdesk_test.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * `TEST_DATABASE=helpdesk_a_test vendor/bin/pest` runs the suite on another test database, so
     * several workers can test at once. The `_test` guard below still applies.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $database = getenv('TEST_DATABASE');

        if (is_string($database) && $database !== '') {
            $app['config']->set('database.connections.pgsql.database', $database);
            $app['config']->set('database.connections.pgsql_owner.database', $database);
        }

        // Passport signs and verifies client-credentials tokens with these (M3-04).
        Passport::loadKeysFrom(PassportTestKeys::directory());

        return $app;
    }

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
