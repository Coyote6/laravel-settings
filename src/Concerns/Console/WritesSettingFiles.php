<?php

namespace Coyote6\LaravelSettings\Concerns\Console;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;

/**
 * Helpers for the commands that write settings files into the application.
 *
 * @phpstan-require-extends Command
 */
trait WritesSettingFiles
{
    /**
     * Get the file path of a class in the application namespace.
     *
     * @label App Class Path
     *
     * @param  string  $class  The full class name, e.g. App\Enums\Settings\ClientSetting
     * @return string|null The path, e.g. /var/www/app/Enums/Settings/ClientSetting.php, or null outside the namespace
     */
    protected function appClassPath(string $class): ?string
    {
        $namespace = $this->laravel->getNamespace();

        if (! Str::startsWith($class, $namespace)) {
            return null;
        }

        return app_path(str_replace('\\', '/', Str::after($class, $namespace)).'.php');
    }

    /**
     * Build a setting enum's source from the enum stub.
     *
     * @label Render Enum
     *
     * @param  Filesystem  $files  The filesystem used to read the stub
     * @param  string  $class  The enum's full class name, e.g. App\Enums\Settings\ClientSetting
     * @param  string  $description  The enum's docblock summary, e.g. The settings each client can change.
     * @param  list<array{case: string, value: string, label: string, default: string}>  $cases  The cases; default is PHP source, e.g. null
     */
    protected function renderEnum(Filesystem $files, string $class, string $description, array $cases): string
    {
        $lines = fn (callable $line): string => implode("\n", array_map($line, $cases));

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ description }}', '{{ cases }}', '{{ labels }}', '{{ defaults }}'],
            [
                Str::beforeLast($class, '\\'),
                class_basename($class),
                $description,
                $lines(fn (array $case) => "    case {$case['case']} = '{$case['value']}';"),
                $lines(fn (array $case) => "            self::{$case['case']} => '".addcslashes($case['label'], "'\\")."',"),
                $lines(fn (array $case) => "            self::{$case['case']} => {$case['default']},"),
            ],
            $files->get($this->stubPath('setting-enum.stub')),
        );
    }

    /**
     * Write a new file and report it.
     *
     * @label Write File
     *
     * @param  Filesystem  $files  The filesystem used to write the file
     * @param  string  $path  The full path, e.g. /var/www/app/Enums/Settings/ClientSetting.php
     * @param  string  $contents  The file's contents
     */
    protected function writeFile(Filesystem $files, string $path, string $contents): void
    {
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $contents);

        $this->components->twoColumnDetail($this->relativePath($path), '<fg=green;options=bold>CREATED</>');
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
     * Determine whether to run a migration now.
     *
     * @label Should Migrate
     *
     * @param  string  $question  The question to ask in a terminal, e.g. Run this migration now?
     */
    protected function shouldMigrate(string $question): bool
    {
        if ($this->option('migrate')) {
            return true;
        }

        // Without a terminal, only --migrate runs it, so scripts and CI never
        // migrate by surprise.
        return $this->input->isInteractive() && confirm($question, true);
    }

    /**
     * Run one migration file.
     *
     * @label Run Migration
     *
     * @param  string  $path  The migration's full path
     */
    protected function runMigration(string $path): void
    {
        $this->call('migrate', ['--path' => $this->relativePath($path)]);
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
