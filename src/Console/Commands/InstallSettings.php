<?php

namespace Coyote6\LaravelSettings\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Set up site-wide settings: config, migration and the Setting enum.
 */
class InstallSettings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'settings:install
        {--migrate : Run the settings migration without asking}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish the settings config and migration, and create the site Setting enum';

    /**
     * Execute the console command.
     *
     * Safe to run again: each step skips what already exists.
     *
     * @label Handle
     *
     * @param  Filesystem  $files  The filesystem used to find and write files
     */
    public function handle(Filesystem $files): int
    {
        $this->publishConfig();

        $migration = $this->publishMigration($files);

        $this->createEnum($files);

        if ($migration !== null && $this->shouldMigrate()) {
            $this->call('migrate', [
                '--path' => Str::after($migration, base_path().DIRECTORY_SEPARATOR),
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Publish config/settings.php unless the application already has it.
     *
     * @label Publish Config
     */
    protected function publishConfig(): void
    {
        if (file_exists(config_path('settings.php'))) {
            $this->components->twoColumnDetail('config/settings.php', '<fg=yellow;options=bold>EXISTS</>');

            return;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'settings-config']);

        $this->components->twoColumnDetail('config/settings.php', '<fg=green;options=bold>PUBLISHED</>');
    }

    /**
     * Publish the settings table migration once, and return its path.
     *
     * @label Publish Migration
     *
     * @ai vendor:publish cannot be trusted to skip a migration it published
     *     before: it checks for the file under its original name, then
     *     renames it to the current date (VendorPublishCommand::publishFile()
     *     and ensureMigrationNameIsUpToDate(), Laravel 13.33), so a second
     *     run would add a duplicate. This looks for an earlier copy first.
     *
     * @param  Filesystem  $files  The filesystem used to find the migration
     * @return string|null The migration's full path, or null when it was not published
     */
    protected function publishMigration(Filesystem $files): ?string
    {
        if ($existing = $this->findMigration($files)) {
            $this->components->twoColumnDetail($this->relativePath($existing), '<fg=yellow;options=bold>EXISTS</>');

            return $existing;
        }

        $table = config()->string('settings.table', 'settings');

        if ($this->tableExists($table)) {
            $this->components->warn(
                "A [{$table}] table already exists, so the migration was not published. "
                .'Set a different "table" in config/settings.php, then run settings:install again.'
            );

            return null;
        }

        $this->callSilently('vendor:publish', ['--tag' => 'settings-migrations']);

        $published = $this->findMigration($files);

        if ($published !== null) {
            $this->components->twoColumnDetail($this->relativePath($published), '<fg=green;options=bold>PUBLISHED</>');
        }

        return $published;
    }

    /**
     * Create the site Setting enum named in config('settings.enum').
     *
     * @label Create Enum
     *
     * @param  Filesystem  $files  The filesystem used to write the enum
     */
    protected function createEnum(Filesystem $files): void
    {
        $class = ltrim(config()->string('settings.enum'), '\\');
        $appNamespace = $this->laravel->getNamespace();

        if (! Str::startsWith($class, $appNamespace)) {
            $this->components->warn("[{$class}] is outside the [{$appNamespace}] namespace, so it was not created. Create it by hand.");

            return;
        }

        $path = app_path(str_replace('\\', '/', Str::after($class, $appNamespace)).'.php');

        if ($files->exists($path)) {
            $this->components->twoColumnDetail($this->relativePath($path), '<fg=yellow;options=bold>EXISTS</>');

            return;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $this->renderEnum($files, $class));

        $this->components->twoColumnDetail($this->relativePath($path), '<fg=green;options=bold>CREATED</>');
    }

    /**
     * Build the site enum's source from the enum stub.
     *
     * @label Render Enum
     *
     * @param  Filesystem  $files  The filesystem used to read the stub
     * @param  string  $class  The enum's full class name, e.g. App\Enums\Settings\Setting
     */
    protected function renderEnum(Filesystem $files, string $class): string
    {
        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ description }}', '{{ cases }}', '{{ labels }}', '{{ defaults }}'],
            [
                Str::beforeLast($class, '\\'),
                class_basename($class),
                'The settings that belong to the whole site.',
                "    case SiteName = 'site-name';",
                "            self::SiteName => 'Site Name',",
                "            self::SiteName => config('app.name'),",
            ],
            $files->get($this->stubPath('setting-enum.stub')),
        );
    }

    /**
     * Get a stub's path, preferring the application's published copy.
     *
     * @label Stub Path
     *
     * @param  string  $stub  The stub's file name, e.g. setting-enum.stub
     */
    protected function stubPath(string $stub): string
    {
        $published = base_path("stubs/settings/{$stub}");

        return file_exists($published) ? $published : __DIR__."/../../../stubs/{$stub}";
    }

    /**
     * Find the application's copy of the settings table migration.
     *
     * @label Find Migration
     *
     * @param  Filesystem  $files  The filesystem used to search
     * @return string|null The migration's full path, or null when there is none
     */
    protected function findMigration(Filesystem $files): ?string
    {
        return $files->glob(database_path('migrations/*_create_settings_table.php'))[0] ?? null;
    }

    /**
     * Determine whether a table exists, treating an unreachable database as "no".
     *
     * @label Table Exists
     *
     * @param  string  $table  The table to look for, e.g. settings
     */
    protected function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (QueryException) {
            // No database yet (a fresh project): there is nothing to collide with.
            return false;
        }
    }

    /**
     * Determine whether to run the migration now.
     *
     * @label Should Migrate
     */
    protected function shouldMigrate(): bool
    {
        if ($this->option('migrate')) {
            return true;
        }

        // Without a terminal, only --migrate runs it, so scripts and CI never
        // migrate by surprise.
        return $this->input->isInteractive()
            && $this->confirm('Run the settings migration now?', true);
    }

    /**
     * Get a path relative to the application root, for display.
     *
     * @label Relative Path
     *
     * @param  string  $path  The full path, e.g. /var/www/app/Enums/Settings/Setting.php
     */
    protected function relativePath(string $path): string
    {
        return Str::after($path, base_path().DIRECTORY_SEPARATOR);
    }
}
