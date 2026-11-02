<?php

namespace Coyote6\LaravelSettings\Tests;

use Coyote6\LaravelSettings\Providers\SettingsServiceProvider;
use Coyote6\LaravelSettings\Tests\Fixtures\Enums\Setting;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Get the package providers to register.
     *
     * @label Get Package Providers
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SettingsServiceProvider::class,
        ];
    }

    /**
     * Define the test environment: the test database and the fixture enum.
     *
     * @label Define Environment
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->databaseConnectionConfig());
        $app['config']->set('settings.enum', Setting::class);
    }

    /**
     * Start each test from an empty database, then run the package's migration.
     *
     * @label Define Database Migrations
     *
     * @ai db:wipe first because a real database (the CI MariaDB job) keeps
     *     its tables and its migrations ledger between tests, unlike SQLite
     *     in memory. The install tests drop and re-create the settings table
     *     and run published copies of the migration; without the wipe the
     *     ledger still says the migration ran while the table is gone, and
     *     every later test fails with "table doesn't exist" (reproduced
     *     with a file-based SQLite database, the same 28 failures as CI).
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('db:wipe')->run();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * Get the test database connection config.
     *
     * SQLite in memory by default, or a real connection built from the
     * standard DB_* environment variables when DB_CONNECTION is set (the CI
     * MariaDB job).
     *
     * @label Database Connection Config
     *
     * @ai Not named test*(): Pint's php_unit_method_casing rule would rename
     *     a TestCase method starting with "test".
     *
     *     foreign_key_constraints is set on purpose: SQLite leaves foreign
     *     keys off unless the key is present in the connection array, and
     *     phase 2 adds tables whose foreign keys the tests must enforce.
     *
     * @return array<string, mixed>
     */
    protected function databaseConnectionConfig(): array
    {
        if (env('DB_CONNECTION') === null) {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => env('DB_CONNECTION'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'testing'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];
    }
}
