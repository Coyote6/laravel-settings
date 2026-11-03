<?php

namespace Coyote6\LaravelSettings\Console\Commands;

use Coyote6\LaravelSettings\Concerns\Console\WritesSettingFiles;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Set up site-wide settings: config, migration and the Setting enum.
 */
class InstallSettings extends Command
{
    use WritesSettingFiles;

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

        if ($migration !== null && $this->shouldMigrate('Run the settings migration now?')) {
            $this->runMigration($migration);
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
        $path = $this->appClassPath($class);

        if ($path === null) {
            $this->components->warn("[{$class}] is outside the [{$this->laravel->getNamespace()}] namespace, so it was not created. Create it by hand.");

            return;
        }

        if ($files->exists($path)) {
            $this->components->twoColumnDetail($this->relativePath($path), '<fg=yellow;options=bold>EXISTS</>');

            return;
        }

        $this->writeFile($files, $path, $this->renderEnum($files, $class, 'The settings that belong to the whole site.', [
            ['case' => 'SiteName', 'value' => 'site-name', 'label' => 'Site Name', 'default' => "config('app.name')"],
        ]));
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
}
