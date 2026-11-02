<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;

/**
 * Delete every file settings:install can create in the Testbench app.
 *
 * @label Remove Installed Files
 */
function removeInstalledFiles(): void
{
    $files = new Filesystem;

    $files->delete(config_path('settings.php'));
    $files->delete($files->glob(database_path('migrations/*_create_settings_table.php')));
    $files->deleteDirectory(app_path('Enums/Settings'));
}

/**
 * Get the published settings migrations.
 *
 * @label Published Migrations
 *
 * @return list<string>
 */
function publishedMigrations(): array
{
    return (new Filesystem)->glob(database_path('migrations/*_create_settings_table.php'));
}

beforeEach(function () {
    removeInstalledFiles();

    // A fresh application: no settings table yet, and the enum in App\.
    Schema::dropIfExists('settings');
    config(['settings.enum' => 'App\\Enums\\Settings\\Setting']);
});

afterEach(function () {
    removeInstalledFiles();
});

it('publishes the config and migration and creates the Setting enum', function () {
    $this->artisan('settings:install', ['--no-interaction' => true])->assertSuccessful();

    $enum = app_path('Enums/Settings/Setting.php');

    expect(config_path('settings.php'))->toBeFile()
        ->and(publishedMigrations())->toHaveCount(1)
        ->and($enum)->toBeFile()
        ->and(file_get_contents($enum))
        ->toContain('namespace App\\Enums\\Settings;')
        ->toContain('enum Setting: string implements SettingName')
        ->toContain("case SiteName = 'site-name';");

    if (! enum_exists('App\\Enums\\Settings\\Setting')) {
        require $enum;
    }

    $siteName = constant('App\\Enums\\Settings\\Setting::SiteName');

    expect($siteName->label())->toBe('Site Name')
        ->and($siteName->default())->toBe(config('app.name'));
});

it('changes nothing when run a second time', function () {
    $this->artisan('settings:install', ['--no-interaction' => true])->assertSuccessful();

    $enum = app_path('Enums/Settings/Setting.php');
    file_put_contents($enum, "<?php\n\n// edited by the developer\n");

    $this->artisan('settings:install', ['--no-interaction' => true])->assertSuccessful();

    expect(publishedMigrations())->toHaveCount(1)
        ->and(file_get_contents($enum))->toContain('edited by the developer');
});

it('does not migrate without a terminal unless asked', function () {
    $this->artisan('settings:install', ['--no-interaction' => true])->assertSuccessful();

    expect(Schema::hasTable('settings'))->toBeFalse();
});

it('runs only the settings migration with --migrate', function () {
    $this->artisan('settings:install', ['--migrate' => true, '--no-interaction' => true])->assertSuccessful();

    expect(Schema::hasTable('settings'))->toBeTrue();
});

it('asks before migrating when run in a terminal', function () {
    $this->artisan('settings:install')
        ->expectsConfirmation('Run the settings migration now?', 'yes')
        ->assertSuccessful();

    expect(Schema::hasTable('settings'))->toBeTrue();
});

it('does not publish the migration when the table already exists', function () {
    Schema::create('settings', function ($table) {
        $table->string('id', 50)->primary();
    });

    $this->artisan('settings:install', ['--no-interaction' => true])
        ->expectsOutputToContain('table already exists')
        ->assertSuccessful();

    expect(publishedMigrations())->toBeEmpty();
});

it('does not create an enum outside the App namespace', function () {
    config(['settings.enum' => 'Vendor\\Settings\\Setting']);

    $this->artisan('settings:install', ['--no-interaction' => true])
        ->expectsOutputToContain('outside the [App\\] namespace')
        ->assertSuccessful();

    expect(app_path('Enums/Settings/Setting.php'))->not->toBeFile();
});
