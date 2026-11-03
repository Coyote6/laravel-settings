<?php

use Coyote6\LaravelSettings\Tests\Fixtures\Models\Account;
use Coyote6\LaravelSettings\Tests\Fixtures\Models\Client;
use Coyote6\LaravelSettings\Tests\Fixtures\Models\Team;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;

/**
 * Delete every file settings:add-type can create in the Testbench app.
 *
 * @label Remove Generated Type Files
 */
function removeGeneratedTypeFiles(): void
{
    $files = new Filesystem;

    $files->delete(config_path('settings.php'));
    $files->delete($files->glob(database_path('migrations/*_settings_table.php')));
    $files->deleteDirectory(app_path('Enums/Settings'));
    $files->deleteDirectory(app_path('Models/Settings'));

    // Enums written outside Enums/Settings by the short-name tests.
    $files->delete([app_path('Enums/Team.php'), app_path('Models/Settings.php')]);
}

/**
 * Determine whether PHP can compile a file, as php -l checks it.
 *
 * @label Compiles
 *
 * @param  string  $path  The file, e.g. config/settings.php
 */
function compiles(string $path): bool
{
    return Process::run([PHP_BINARY, '-l', $path])->successful();
}

/**
 * Get the generated migration for a table.
 *
 * @label Generated Migration
 *
 * @param  string  $table  The settings table, e.g. team_settings
 * @return string|null The migration's path, or null when there is none
 */
function generatedMigration(string $table): ?string
{
    return (new Filesystem)->glob(database_path("migrations/*_create_{$table}_table.php"))[0] ?? null;
}

/**
 * Load a generated class into PHP, once.
 *
 * @label Load Generated Class
 *
 * @param  string  $class  The class, e.g. App\Enums\Settings\TeamSetting
 * @param  string  $path  The generated file, e.g. app/Enums/Settings/TeamSetting.php
 */
function loadGeneratedClass(string $class, string $path): void
{
    if (! class_exists($class) && ! enum_exists($class)) {
        require $path;
    }
}

/**
 * Use the types in the generated config/settings.php.
 *
 * @label Use Generated Types
 */
function useGeneratedTypes(): void
{
    config(['settings.types' => (require config_path('settings.php'))['types']]);
}

beforeEach(function () {
    removeGeneratedTypeFiles();

    // An application that has run settings:install.
    copy(__DIR__.'/../../config/settings.php', config_path('settings.php'));
});

afterEach(function () {
    removeGeneratedTypeFiles();
});

// Creating a type

it('creates the migration, enum and model and registers the type', function () {
    $this->artisan('settings:add-type', ['model' => Team::class, '--names' => 'theme, Show Banner', '--no-interaction' => true])
        ->expectsOutputToContain('resolveOwnerUsing(Team::class')
        ->doesntExpectOutputToContain('Add the trait')
        ->assertSuccessful();

    expect(file_get_contents((string) generatedMigration('team_settings')))
        ->toContain('use '.Team::class.';')
        ->toContain("Schema::create('team_settings'")
        ->toContain('$table->foreignIdFor(Team::class)->constrained()->cascadeOnDelete();')
        ->toContain("\$table->unique(['team_id', 'name']);")
        ->and(file_get_contents(app_path('Enums/Settings/TeamSetting.php')))
        ->toContain('namespace App\\Enums\\Settings;')
        ->toContain('The settings each team can change.')
        ->toContain('enum TeamSetting: string implements SettingName')
        ->toContain("case Theme = 'theme';")
        ->toContain("case ShowBanner = 'show-banner';")
        ->toContain("self::ShowBanner => 'Show Banner',")
        ->toContain('self::Theme => null,')
        ->and(file_get_contents(app_path('Models/Settings/TeamSettingValue.php')))
        ->toContain('namespace App\\Models\\Settings;')
        ->toContain('class TeamSettingValue extends OwnedSettingValue')
        ->toContain("protected \$table = 'team_settings';")
        ->and(file_get_contents(config_path('settings.php')))
        ->toContain('use '.Team::class.';')
        ->toContain('use App\\Enums\\Settings\\TeamSetting;')
        ->toContain('use App\\Models\\Settings\\TeamSettingValue;')
        // The whole entry, so it has no owner_key line.
        ->toContain("        'team' => [\n            'owner' => Team::class,\n            'enum' => TeamSetting::class,\n            'model' => TeamSettingValue::class,\n        ],")
        // Without a terminal, only --migrate runs the migration.
        ->and(Schema::hasTable('team_settings'))->toBeFalse();
});

it('generates files that work end to end', function () {
    $this->artisan('settings:add-type', ['model' => Team::class, '--names' => 'theme', '--migrate' => true, '--no-interaction' => true])
        ->assertSuccessful();

    loadGeneratedClass('App\\Enums\\Settings\\TeamSetting', app_path('Enums/Settings/TeamSetting.php'));
    loadGeneratedClass('App\\Models\\Settings\\TeamSettingValue', app_path('Models/Settings/TeamSettingValue.php'));
    useGeneratedTypes();

    $theme = constant('App\\Enums\\Settings\\TeamSetting::Theme');
    $team = Team::create();

    expect($team->setting($theme, 'light'))->toBe('light');

    $team->saveSetting($theme, 'dark');
    app()->forgetScopedInstances();

    expect($team->setting($theme))->toBe('dark')
        ->and(DB::table('team_settings')->value('team_id'))->toBe($team->id);

    $team->delete();

    expect(DB::table('team_settings')->count())->toBe(0);
});

it('generates files that pass Pint', function () {
    $pint = realpath(__DIR__.'/../../vendor/bin/pint');

    if ($pint === false) {
        $this->markTestSkipped('Pint is not installed.');
    }

    $this->artisan('settings:add-type', ['model' => Team::class, '--names' => 'theme, text-alerts', '--no-interaction' => true])
        ->assertSuccessful();

    // Copied out of the Testbench app, which lives under vendor/.
    $dir = sys_get_temp_dir().'/settings-pint-'.uniqid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($dir);

    foreach ([
        (string) generatedMigration('team_settings'),
        app_path('Enums/Settings/TeamSetting.php'),
        app_path('Models/Settings/TeamSettingValue.php'),
        config_path('settings.php'),
    ] as $file) {
        $files->copy($file, $dir.'/'.basename($file));
    }

    $result = Process::path(dirname($pint, 3))->run([$pint, '--test', $dir]);
    $files->deleteDirectory($dir);

    expect($result->output())->toContain('"result":"passed"');
});

it('asks each question in a terminal', function () {
    $this->artisan('settings:add-type')
        ->expectsQuestion('Which model are these settings for?', Team::class)
        ->expectsQuestion('Which enum names these settings?', 'App\\Enums\\Settings\\SquadSetting')
        ->expectsQuestion('Which settings should the enum start with?', 'theme')
        ->expectsQuestion('What should the settings table be called?', 'squad_settings')
        ->expectsConfirmation('squad_settings.team_id → teams.id (ulid). Correct?', 'no')
        ->expectsQuestion('Which column of squad_settings holds the owner key?', 'squad_team_id')
        ->expectsConfirmation('Run this migration now?', 'yes')
        ->assertSuccessful();

    expect(file_get_contents((string) generatedMigration('squad_settings')))
        ->toContain("\$table->foreignIdFor(Team::class, 'squad_team_id')->constrained()->cascadeOnDelete();")
        ->toContain("\$table->unique(['squad_team_id', 'name']);")
        ->and(app_path('Enums/Settings/SquadSetting.php'))->toBeFile()
        ->and(file_get_contents(config_path('settings.php')))
        ->toContain("'enum' => SquadSetting::class,")
        ->toContain("'owner_key' => 'squad_team_id',")
        ->and(Schema::hasColumn('squad_settings', 'squad_team_id'))->toBeTrue();
});

it('turns typed names into enum cases', function () {
    $this->artisan('settings:add-type', [
        'model' => Team::class,
        '--names' => 'textAlerts, SMS alerts, show_fielding_stats',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(file_get_contents(app_path('Enums/Settings/TeamSetting.php')))
        ->toContain("case TextAlerts = 'text-alerts';")
        ->toContain("case SmsAlerts = 'sms-alerts';")
        ->toContain("case ShowFieldingStats = 'show-fielding-stats';")
        ->toContain("self::ShowFieldingStats => 'Show Fielding Stats',");
});

// Owners with a plain string key

it('writes a string column and a foreign key for a plain string key', function () {
    $this->artisan('settings:add-type', ['model' => Account::class, '--names' => 'theme', '--migrate' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Add the trait')
        ->assertSuccessful();

    expect(file_get_contents((string) generatedMigration('account_settings')))
        ->toContain("\$table->string('account_code');")
        ->toContain("\$table->foreign('account_code')->references('code')->on('accounts')->cascadeOnDelete();")
        ->not->toContain('foreignIdFor')
        ->not->toContain('use '.Account::class.';');

    loadGeneratedClass('App\\Enums\\Settings\\AccountSetting', app_path('Enums/Settings/AccountSetting.php'));
    loadGeneratedClass('App\\Models\\Settings\\AccountSettingValue', app_path('Models/Settings/AccountSettingValue.php'));
    useGeneratedTypes();

    $theme = constant('App\\Enums\\Settings\\AccountSetting::Theme');
    $account = Account::create(['code' => 'ACME-1', 'name' => 'Acme']);

    setting()->for($account)->set($theme, 'dark');
    app()->forgetScopedInstances();

    expect(setting()->for($account)->get($theme))->toBe('dark');

    $account->delete();

    expect(DB::table('account_settings')->count())->toBe(0);
});

// The config file

it('publishes the config first when it is missing', function () {
    unlink(config_path('settings.php'));

    $this->artisan('settings:add-type', ['model' => Team::class, '--names' => 'theme', '--no-interaction' => true])
        ->assertSuccessful();

    expect(file_get_contents(config_path('settings.php')))->toContain("'team' => [");
});

it('prints the entry when the marker line is gone', function () {
    $config = str_replace('// settings:add-type adds entries below this line.', '', file_get_contents(config_path('settings.php')));
    file_put_contents(config_path('settings.php'), $config);

    // One expectation: the entry is printed in one write, and Mockery
    // credits a write to the first expectation it matches only.
    $this->artisan('settings:add-type', ['model' => Team::class, '--names' => 'theme', '--no-interaction' => true])
        ->expectsOutputToContain("'owner' => \\".Team::class.'::class,')
        ->assertSuccessful();

    expect(file_get_contents(config_path('settings.php')))->toBe($config);
});

it('reuses an import and writes a clashing class in full', function () {
    $config = str_replace(
        "use App\\Enums\\Settings\\Setting;\n",
        "use App\\Enums\\Settings\\Setting;\nuse App\\Enums\\Settings\\TeamSetting;\nuse Vendor\\Package\\Team;\n",
        file_get_contents(config_path('settings.php')),
    );
    file_put_contents(config_path('settings.php'), $config);

    $this->artisan('settings:add-type', ['model' => Team::class, '--names' => 'theme', '--no-interaction' => true])
        ->assertSuccessful();

    $updated = file_get_contents(config_path('settings.php'));

    expect($updated)
        ->toContain("'owner' => \\".Team::class.'::class,')
        ->toContain("'enum' => TeamSetting::class,")
        ->not->toContain('use '.Team::class.';')
        ->and(substr_count($updated, 'use App\\Enums\\Settings\\TeamSetting;'))->toBe(1);
});

it('writes a class in full when another import has its short name', function () {
    // The case of two classes named Settings: App\Helpers\Settings is
    // already imported, so the new enum App\Models\Settings cannot be.
    $config = str_replace(
        "use App\\Enums\\Settings\\Setting;\n",
        "use App\\Enums\\Settings\\Setting;\nuse App\\Helpers\\Settings;\n",
        file_get_contents(config_path('settings.php')),
    );
    file_put_contents(config_path('settings.php'), $config);

    $this->artisan('settings:add-type', ['model' => Team::class, '--names' => 'theme', '--enum' => 'App\\Models\\Settings', '--no-interaction' => true])
        ->assertSuccessful();

    $updated = file_get_contents(config_path('settings.php'));

    expect($updated)
        ->toContain("'enum' => \\App\\Models\\Settings::class,")
        ->toContain('use App\\Helpers\\Settings;')
        ->not->toContain('use App\\Models\\Settings;')
        ->and(compiles(config_path('settings.php')))->toBeTrue()
        ->and((require config_path('settings.php'))['types']['team']['enum'])->toBe('App\\Models\\Settings');
});

it('keeps two new classes with the same short name apart', function () {
    // The owner and the enum are both called Team: the owner is imported
    // first, so the enum is written in full.
    $this->artisan('settings:add-type', ['model' => Team::class, '--names' => 'theme', '--enum' => 'App\\Enums\\Team', '--no-interaction' => true])
        ->assertSuccessful();

    expect(file_get_contents(config_path('settings.php')))
        ->toContain("'owner' => Team::class,")
        ->toContain("'enum' => \\App\\Enums\\Team::class,")
        ->toContain('use '.Team::class.';')
        ->not->toContain('use App\\Enums\\Team;')
        // Checked before the require below: a clashing import is a fatal
        // error that would stop the whole test run.
        ->and(compiles(config_path('settings.php')))->toBeTrue();

    $types = (require config_path('settings.php'))['types'];

    expect($types['team']['owner'])->toBe(Team::class)
        ->and($types['team']['enum'])->toBe('App\\Enums\\Team');
});

// Refusals

it('refuses a model that already has a settings type', function () {
    $this->artisan('settings:add-type', ['model' => Client::class, '--names' => 'theme', '--no-interaction' => true])
        ->expectsOutputToContain('already has a settings type')
        ->assertFailed();

    expect(generatedMigration('client_settings'))->toBeNull()
        ->and(app_path('Enums/Settings'))->not->toBeDirectory();
});

it('refuses something that is not a model', function () {
    $this->artisan('settings:add-type', ['model' => 'Nope', '--names' => 'theme', '--no-interaction' => true])
        ->expectsOutputToContain('is not an Eloquent model')
        ->assertFailed();
});

it('creates nothing when a file it would write already exists', function () {
    $config = file_get_contents(config_path('settings.php'));
    $model = app_path('Models/Settings/TeamSettingValue.php');
    (new Filesystem)->ensureDirectoryExists(dirname($model));
    file_put_contents($model, "<?php\n\n// written by the developer\n");

    $this->artisan('settings:add-type', ['model' => Team::class, '--names' => 'theme', '--no-interaction' => true])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect(generatedMigration('team_settings'))->toBeNull()
        ->and(app_path('Enums/Settings/TeamSetting.php'))->not->toBeFile()
        ->and(file_get_contents($model))->toContain('written by the developer')
        ->and(file_get_contents(config_path('settings.php')))->toBe($config);
});

it('refuses a table that already exists', function () {
    $this->artisan('settings:add-type', ['model' => Team::class, '--names' => 'theme', '--table' => 'users', '--no-interaction' => true])
        ->expectsOutputToContain('[users] table already exists')
        ->assertFailed();
});

it('refuses names that cannot be enum cases', function (string $names, string $message) {
    $this->artisan('settings:add-type', ['model' => Team::class, '--names' => $names, '--no-interaction' => true])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(app_path('Enums/Settings'))->not->toBeDirectory();
})->with([
    'starts with a digit' => ['theme, 2fa', 'must start with a letter'],
    'listed twice' => ['theme, Theme', 'is the same setting as [theme]'],
    'no names' => [' , ', 'at least one setting name'],
]);

it('needs the names without a terminal', function () {
    $this->artisan('settings:add-type', ['model' => Team::class, '--no-interaction' => true])
        ->expectsOutputToContain('--names=')
        ->assertFailed();
});

it('refuses an invalid table name', function () {
    $this->artisan('settings:add-type', ['model' => Team::class, '--names' => 'theme', '--table' => 'team-settings', '--no-interaction' => true])
        ->expectsOutputToContain('is not a valid table name')
        ->assertFailed();
});
