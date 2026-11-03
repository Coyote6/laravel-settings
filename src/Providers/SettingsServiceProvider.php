<?php

namespace Coyote6\LaravelSettings\Providers;

use Coyote6\LaravelSettings\Console\Commands\AddSettingType;
use Coyote6\LaravelSettings\Console\Commands\InstallSettings;
use Coyote6\LaravelSettings\OwnerResolvers;
use Coyote6\LaravelSettings\SettingsManager;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Merge the package config and bind the settings manager and owner resolvers.
     *
     * @label Register
     *
     * @ai scoped(), not singleton(), for the manager: it keeps loaded values
     *     in memory, and Laravel clears scoped instances between queued jobs
     *     (QueueServiceProvider calls forgetScopedInstances()) and between
     *     Octane requests, so a long-running worker never serves values
     *     loaded for an earlier job.
     *
     *     singleton() for the owner resolvers: they are registered once in a
     *     service provider's boot(), so on the scoped manager they would be
     *     lost after the first queued job.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/settings.php', 'settings');

        $this->app->singleton(OwnerResolvers::class);
        $this->app->scoped(SettingsManager::class);
    }

    /**
     * Register the publishable files and the Artisan commands.
     *
     * @label Boot
     *
     * @ai The migration is published, not loaded with loadMigrationsFrom()
     *     as laravel-locale does, because many applications already have a
     *     "settings" table (Scorebook does). Publishing puts the migration in
     *     the app, where the table name can be checked before it runs.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/settings.php' => config_path('settings.php'),
        ], 'settings-config');

        $this->publishesMigrations([
            __DIR__.'/../../database/migrations' => database_path('migrations'),
        ], 'settings-migrations');

        $this->publishes([
            __DIR__.'/../../stubs' => base_path('stubs/settings'),
        ], 'settings-stubs');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallSettings::class,
                AddSettingType::class,
            ]);
        }
    }
}
