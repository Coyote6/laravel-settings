<?php

use Coyote6\LaravelSettings\Exceptions\UnregisteredSettingName;
use Coyote6\LaravelSettings\Facades\Settings;
use Coyote6\LaravelSettings\Models\SettingValue;
use Coyote6\LaravelSettings\SettingsManager;
use Coyote6\LaravelSettings\Tests\Fixtures\Enums\Setting;
use Coyote6\LaravelSettings\Tests\Fixtures\Enums\UnregisteredSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('returns the enum default when nothing is stored', function () {
    expect(setting(Setting::SiteName))->toBe('Default Site');
});

it('prefers the call-site default over the enum default', function () {
    expect(setting(Setting::SiteName, 'Call Site'))->toBe('Call Site');
});

it('treats a null call-site default as no default', function () {
    expect(setting(Setting::SiteName, null))->toBe('Default Site');
});

it('prefers the stored value over both defaults', function () {
    saveSetting(Setting::SiteName, 'Scorebook');

    expect(setting(Setting::SiteName, 'Call Site'))->toBe('Scorebook');
});

it('returns a stored null instead of a default', function () {
    saveSetting(Setting::SiteName, null);

    expect(setting(Setting::SiteName, 'Call Site'))->toBeNull();
});

it('keeps the value type through the database', function (mixed $value) {
    saveSetting(Setting::Limits, $value);

    // A new manager forces a fresh load from the database.
    app()->forgetScopedInstances();

    expect(setting(Setting::Limits))->toBe($value);
})->with([
    'string' => 'dark',
    'false' => false,
    'true' => true,
    'int' => 42,
    'float' => 1.5,
    'list' => [[1, 2, 3]],
    'nested array' => [['email' => true, 'sms' => ['start' => '08:00']]],
]);

it('updates an existing row instead of adding another', function () {
    saveSetting(Setting::Theme, 'dark');
    $setting = saveSetting(Setting::Theme, 'blue');

    expect($setting)->toBeInstanceOf(SettingValue::class)
        ->and($setting->value)->toBe('blue')
        ->and(SettingValue::count())->toBe(1);
});

it('loads nothing until a setting is read', function () {
    saveSetting(Setting::SiteName, 'Scorebook');
    app()->forgetScopedInstances();

    expect(countSelects(fn () => setting()))->toBe(0);
});

it('loads only the setting that is read', function () {
    saveSetting(Setting::SiteName, 'Scorebook');
    saveSetting(Setting::Theme, 'dark');
    saveSetting(Setting::MaintenanceMode, true);
    app()->forgetScopedInstances();

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect(setting(Setting::Theme))->toBe('dark');

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)
        ->and($queries[0]['bindings'])->toBe(['theme']);
});

it('reads each setting from the database once per request', function () {
    saveSetting(Setting::SiteName, 'Scorebook');
    saveSetting(Setting::Theme, 'dark');
    app()->forgetScopedInstances();

    $selects = countSelects(function () {
        setting(Setting::SiteName);
        setting(Setting::Theme);
        setting(Setting::SiteName);
        setting(Setting::Theme);
    });

    expect($selects)->toBe(2);
});

it('remembers that a setting has no stored value', function () {
    $selects = countSelects(function () {
        expect(setting(Setting::Theme))->toBe('light')
            ->and(setting(Setting::Theme, 'dark'))->toBe('dark')
            ->and(setting()->has(Setting::Theme))->toBeFalse();
    });

    expect($selects)->toBe(1);
});

it('does not query again after a forget', function () {
    saveSetting(Setting::Theme, 'dark');
    setting()->forget(Setting::Theme);

    expect(countSelects(fn () => setting(Setting::Theme)))->toBe(0)
        ->and(setting(Setting::Theme))->toBe('light');
});

it('serves a saved value without reading the database again', function () {
    $selects = countSelects(function () {
        saveSetting(Setting::Theme, 'dark');

        expect(setting(Setting::Theme))->toBe('dark');
    });

    // updateOrCreate() does one select to find the row; the read does none.
    expect($selects)->toBe(1);
});

it('loads fresh values after the scoped manager is reset, as between queued jobs', function () {
    expect(setting(Setting::Theme))->toBe('light');

    // Another process changes the value.
    SettingValue::create(['name' => Setting::Theme->value, 'value' => 'dark']);

    expect(setting(Setting::Theme))->toBe('light');

    app()->forgetScopedInstances();

    expect(setting(Setting::Theme))->toBe('dark');
});

it('forgets a value so reads fall back to the defaults', function () {
    saveSetting(Setting::Theme, 'dark');
    expect(setting(Setting::Theme))->toBe('dark');

    setting()->forget(Setting::Theme);

    expect(setting(Setting::Theme))->toBe('light')
        ->and(SettingValue::count())->toBe(0);
});

it('reports whether a value is stored', function () {
    expect(setting()->has(Setting::Theme))->toBeFalse();

    saveSetting(Setting::Theme, 'dark');

    expect(setting()->has(Setting::Theme))->toBeTrue();
});

it('lists every stored site value in name order', function () {
    saveSetting(Setting::SiteName, 'Scorebook');
    saveSetting(Setting::MaintenanceMode, true);

    expect(setting()->site()->all())->toBe([
        'maintenance-mode' => true,
        'site-name' => 'Scorebook',
    ]);
});

it('lets an enum default read another setting', function () {
    expect(setting(Setting::AdminTheme))->toBe('light');

    saveSetting(Setting::Theme, 'dark');

    expect(setting(Setting::AdminTheme))->toBe('dark');

    saveSetting(Setting::AdminTheme, 'blue');

    expect(setting(Setting::AdminTheme))->toBe('blue');
});

it('returns the manager when called without a name', function () {
    expect(setting())->toBeInstanceOf(SettingsManager::class)
        ->and(setting())->toBe(Settings::getFacadeRoot());
});

it('works through the facade', function () {
    Settings::set(Setting::Theme, 'dark');

    expect(Settings::get(Setting::Theme))->toBe('dark')
        ->and(Settings::has(Setting::Theme))->toBeTrue();
});

it('rejects an enum that is not registered', function (Closure $call) {
    expect($call)->toThrow(UnregisteredSettingName::class, UnregisteredSetting::class);
})->with([
    // Pest passes a closure through unchanged when the parameter is typed Closure.
    'read' => fn () => setting(UnregisteredSetting::Theme),
    'save' => fn () => saveSetting(UnregisteredSetting::Theme, 'dark'),
]);

it('allows only one row per setting name', function () {
    SettingValue::create(['name' => 'theme', 'value' => 'dark']);

    expect(fn () => SettingValue::create(['name' => 'theme', 'value' => 'blue']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('uses the table named in config', function () {
    $migrations = ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true];

    // reset, not rollback: rollback undoes only the latest batch, and the
    // fixture migrations run in a later batch than this one.
    $this->artisan('migrate:reset', $migrations)->assertSuccessful();
    config(['settings.table' => 'site_settings']);
    $this->artisan('migrate', $migrations)->assertSuccessful();

    saveSetting(Setting::Theme, 'dark');

    expect(Schema::hasTable('site_settings'))->toBeTrue()
        ->and(Schema::hasTable('settings'))->toBeFalse()
        ->and(DB::table('site_settings')->value('name'))->toBe('theme');
});
