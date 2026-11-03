<?php

use Coyote6\LaravelSettings\Exceptions\InvalidSettingsType;
use Coyote6\LaravelSettings\Exceptions\MissingSettingsOwner;
use Coyote6\LaravelSettings\Exceptions\UnregisteredSettingName;
use Coyote6\LaravelSettings\Exceptions\UnregisteredSettingsOwner;
use Coyote6\LaravelSettings\Facades\Settings;
use Coyote6\LaravelSettings\Models\SettingValue;
use Coyote6\LaravelSettings\SettingsRepository;
use Coyote6\LaravelSettings\Tests\Fixtures\Enums\ClientSetting;
use Coyote6\LaravelSettings\Tests\Fixtures\Enums\Setting;
use Coyote6\LaravelSettings\Tests\Fixtures\Enums\UnregisteredSetting;
use Coyote6\LaravelSettings\Tests\Fixtures\Enums\UserSetting;
use Coyote6\LaravelSettings\Tests\Fixtures\Models\Client;
use Coyote6\LaravelSettings\Tests\Fixtures\Models\Settings\ClientSettingValue;
use Coyote6\LaravelSettings\Tests\Fixtures\Models\Settings\UserSettingValue;
use Coyote6\LaravelSettings\Tests\Fixtures\Models\Team;
use Coyote6\LaravelSettings\Tests\Fixtures\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

// Reading and saving on an owner

it('saves and reads a setting on its owner', function () {
    $user = User::create(['name' => 'Ann']);

    $user->saveSetting(UserSetting::Theme, 'dark');

    // A new manager forces a fresh load from the database.
    app()->forgetScopedInstances();

    expect($user->setting(UserSetting::Theme))->toBe('dark')
        ->and(UserSettingValue::where('user_id', $user->id)->value('value'))->toBe('dark');
});

it('uses the stored value, then the call-site default, then the enum default', function () {
    $user = User::create(['name' => 'Ann']);

    expect($user->setting(UserSetting::Theme))->toBe('light')
        ->and($user->setting(UserSetting::Theme, 'blue'))->toBe('blue');

    $user->saveSetting(UserSetting::Theme, 'dark');

    expect($user->setting(UserSetting::Theme, 'blue'))->toBe('dark');
});

it('returns a stored null instead of a default', function () {
    $user = User::create(['name' => 'Ann']);

    $user->saveSetting(UserSetting::Theme, null);

    expect($user->setting(UserSetting::Theme, 'blue'))->toBeNull();
});

it('updates an owner\'s row instead of adding another', function () {
    $client = Client::create(['name' => 'Acme']);

    $client->saveSetting(ClientSetting::ShowFieldingStats, false);
    $setting = $client->saveSetting(ClientSetting::ShowFieldingStats, true);

    expect($setting)->toBeInstanceOf(ClientSettingValue::class)
        ->and($setting->value)->toBeTrue()
        ->and(ClientSettingValue::count())->toBe(1);
});

it('stores the owner key for integer and UUID owners', function () {
    $user = User::create(['name' => 'Ann']);
    $client = Client::create(['name' => 'Acme']);

    $user->saveSetting(UserSetting::Theme, 'dark');
    $client->saveSetting(ClientSetting::Theme, 'dark');

    expect(UserSettingValue::value('user_id'))->toEqual($user->id)
        ->and(ClientSettingValue::value('client_id'))->toBe($client->id);
});

it('keeps each owner\'s settings apart', function () {
    // The case from section 4 of the plan: with the setting name as the row
    // id, saving or deleting for one user changed every user's row.
    $ann = User::create(['name' => 'Ann']);
    $bob = User::create(['name' => 'Bob']);

    $ann->saveSetting(UserSetting::Theme, 'dark');
    $bob->saveSetting(UserSetting::Theme, 'light');
    $ann->saveSetting(UserSetting::Theme, 'blue');
    app()->forgetScopedInstances();

    expect($ann->setting(UserSetting::Theme))->toBe('blue')
        ->and($bob->setting(UserSetting::Theme))->toBe('light');

    setting()->for($ann)->forget(UserSetting::Theme);
    app()->forgetScopedInstances();

    expect(UserSettingValue::count())->toBe(1)
        ->and($ann->setting(UserSetting::Theme))->toBe('light')
        ->and($bob->setting(UserSetting::Theme))->toBe('light');
});

it('stores the same name separately for the site, users and clients', function () {
    $user = User::create(['name' => 'Ann']);
    $client = Client::create(['name' => 'Acme']);

    saveSetting(Setting::Theme, 'site');
    $user->saveSetting(UserSetting::Theme, 'user');
    $client->saveSetting(ClientSetting::Theme, 'client');
    app()->forgetScopedInstances();

    expect(setting(Setting::Theme))->toBe('site')
        ->and($user->setting(UserSetting::Theme))->toBe('user')
        ->and($client->setting(ClientSetting::Theme))->toBe('client')
        ->and(SettingValue::count())->toBe(1)
        ->and(UserSettingValue::count())->toBe(1)
        ->and(ClientSettingValue::count())->toBe(1);
});

it('lets an owned default fall back to the site setting', function () {
    $client = Client::create(['name' => 'Acme']);

    expect($client->setting(ClientSetting::Theme))->toBe('light');

    saveSetting(Setting::Theme, 'dark');

    expect($client->setting(ClientSetting::Theme))->toBe('dark');

    $client->saveSetting(ClientSetting::Theme, 'blue');

    expect($client->setting(ClientSetting::Theme))->toBe('blue');
});

it('deletes an owner\'s settings with the owner', function () {
    $user = User::create(['name' => 'Ann']);
    $client = Client::create(['name' => 'Acme']);
    $user->saveSetting(UserSetting::Theme, 'dark');
    $client->saveSetting(ClientSetting::Theme, 'dark');

    $user->delete();
    $client->delete();

    expect(UserSettingValue::count())->toBe(0)
        ->and(ClientSettingValue::count())->toBe(0);
});

// Loading

it('loads an owner\'s setting with one query, once per request', function () {
    $user = User::create(['name' => 'Ann']);
    $user->saveSetting(UserSetting::Theme, 'dark');
    $user->saveSetting(UserSetting::Timezone, 'America/Denver');
    app()->forgetScopedInstances();

    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($user->setting(UserSetting::Theme))->toBe('dark')
        ->and($user->setting(UserSetting::Theme))->toBe('dark')
        ->and(setting()->for($user)->has(UserSetting::Theme))->toBeTrue();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)
        ->and($queries[0]['bindings'])->toBe([$user->id, 'theme']);
});

it('remembers that an owner has no stored value', function () {
    $user = User::create(['name' => 'Ann']);

    $selects = countSelects(function () use ($user) {
        expect($user->setting(UserSetting::Theme))->toBe('light')
            ->and($user->setting(UserSetting::Theme, 'dark'))->toBe('dark');
    });

    expect($selects)->toBe(1);
});

it('reads each owner\'s settings separately', function () {
    $ann = User::create(['name' => 'Ann']);
    $bob = User::create(['name' => 'Bob']);

    $selects = countSelects(function () use ($ann, $bob) {
        $ann->setting(UserSetting::Theme);
        $bob->setting(UserSetting::Theme);
        $ann->setting(UserSetting::Theme);
    });

    expect($selects)->toBe(2);
});

// The current owner

it('reads and saves for the logged-in user when no owner is given', function () {
    $user = User::create(['name' => 'Ann']);
    $this->actingAs($user);

    saveSetting(UserSetting::Theme, 'dark');

    expect(setting(UserSetting::Theme))->toBe('dark')
        ->and(setting()->has(UserSetting::Theme))->toBeTrue()
        ->and($user->setting(UserSetting::Theme))->toBe('dark');

    setting()->forget(UserSetting::Theme);

    expect(UserSettingValue::count())->toBe(0);
});

it('returns the defaults for a guest without querying', function () {
    User::create(['name' => 'Ann'])->saveSetting(UserSetting::Theme, 'dark');
    app()->forgetScopedInstances();

    $selects = countSelects(function () {
        expect(setting(UserSetting::Theme))->toBe('light')
            ->and(setting(UserSetting::Theme, 'blue'))->toBe('blue')
            ->and(setting()->has(UserSetting::Theme))->toBeFalse();
    });

    expect($selects)->toBe(0);
});

it('refuses to save or forget without a current owner', function (Closure $call) {
    expect($call)->toThrow(MissingSettingsOwner::class, 'There is no current ['.User::class.']');
})->with([
    // Pest passes a closure through unchanged when the parameter is typed Closure.
    'save' => fn () => saveSetting(UserSetting::Theme, 'dark'),
    'forget' => fn () => setting()->forget(UserSetting::Theme),
]);

it('has no current client until a resolver is registered', function () {
    $client = Client::create(['name' => 'Acme']);
    $client->saveSetting(ClientSetting::ShowFieldingStats, true);

    // The logged-in user is a User, so it is not the current Client.
    $this->actingAs(User::create(['name' => 'Ann']));

    expect(setting(ClientSetting::ShowFieldingStats))->toBeFalse()
        ->and(fn () => saveSetting(ClientSetting::ShowFieldingStats, false))
        ->toThrow(MissingSettingsOwner::class, Client::class);
});

it('reads and saves for the owner a resolver returns', function () {
    $client = Client::create(['name' => 'Acme']);
    Settings::resolveOwnerUsing(Client::class, fn () => $client);

    saveSetting(ClientSetting::ShowFieldingStats, true);

    expect(setting(ClientSetting::ShowFieldingStats))->toBeTrue()
        ->and($client->setting(ClientSetting::ShowFieldingStats))->toBeTrue();
});

it('treats a resolver that returns null as no current owner', function () {
    Settings::resolveOwnerUsing(Client::class, fn () => null);

    expect(setting(ClientSetting::ShowFieldingStats))->toBeFalse()
        ->and(fn () => saveSetting(ClientSetting::ShowFieldingStats, true))
        ->toThrow(MissingSettingsOwner::class);
});

it('keeps owner resolvers after the scoped manager is reset, as between queued jobs', function () {
    $client = Client::create(['name' => 'Acme']);
    $client->saveSetting(ClientSetting::ShowFieldingStats, true);
    Settings::resolveOwnerUsing(Client::class, fn () => $client);

    // What the queue worker does after every job (QueueServiceProvider).
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();

    expect(setting(ClientSetting::ShowFieldingStats))->toBeTrue();
});

it('rejects a resolver that returns the wrong model', function () {
    $user = User::create(['name' => 'Ann']);
    Settings::resolveOwnerUsing(Client::class, fn () => $user);

    expect(fn () => setting(ClientSetting::Theme))
        ->toThrow(UnregisteredSettingsOwner::class, 'must be a ['.Client::class.']');
});

// A specific owner through for()

it('reads, saves, checks, lists and forgets through for()', function () {
    $client = Client::create(['name' => 'Acme']);
    $settings = setting()->for($client);

    $settings->set(ClientSetting::Theme, 'dark');
    $settings->set(ClientSetting::ShowFieldingStats, true);

    expect($settings)->toBeInstanceOf(SettingsRepository::class)
        ->and($settings->get(ClientSetting::Theme))->toBe('dark')
        ->and($settings->has(ClientSetting::Theme))->toBeTrue()
        ->and($settings->all())->toBe([
            'show-fielding-stats' => true,
            'theme' => 'dark',
        ]);

    $settings->forget(ClientSetting::Theme);

    expect($settings->has(ClientSetting::Theme))->toBeFalse()
        ->and($client->setting(ClientSetting::Theme))->toBe('light');
});

it('returns the same settings for the same owner during a request', function () {
    $client = Client::create(['name' => 'Acme']);

    expect(setting()->for($client))->toBe(setting()->for(Client::find($client->id)))
        ->and(Settings::for($client))->toBe(setting()->for($client));
});

it('rejects a setting from another enum', function (Closure $call) {
    expect($call)->toThrow(UnregisteredSettingName::class, 'these settings are named by ['.ClientSetting::class.']');
})->with([
    'read on the owner' => fn () => Client::create(['name' => 'Acme'])->setting(UserSetting::Theme),
    'save through for()' => fn () => setting()->for(Client::create(['name' => 'Acme']))->set(Setting::Theme, 'dark'),
]);

it('rejects a model without a settings type', function () {
    expect(fn () => (new Team)->setting(UserSetting::Theme))
        ->toThrow(UnregisteredSettingsOwner::class, 'The model ['.Team::class.'] has no settings type');
});

it('rejects a model with more than one settings type', function () {
    config(['settings.types.admin' => [
        'owner' => User::class,
        'enum' => UnregisteredSetting::class,
        'model' => UserSettingValue::class,
    ]]);

    expect(fn () => setting()->for(User::create(['name' => 'Ann'])))
        ->toThrow(UnregisteredSettingsOwner::class, 'more than one settings type ([user], [admin])');
});

it('rejects an owner that has not been saved', function () {
    expect(fn () => (new User)->setting(UserSetting::Theme))
        ->toThrow(MissingSettingsOwner::class, 'has not been saved');
});

// Config

it('uses the owner column named in config', function () {
    config(['settings.types.client.owner_key' => 'owner_uuid']);

    expect(fn () => Client::create(['name' => 'Acme'])->setting(ClientSetting::Theme))
        ->toThrow(QueryException::class, 'owner_uuid');
});

it('rejects a settings type with a bad config entry', function (mixed $entry, string $message) {
    config(['settings.types.user' => $entry]);

    expect(fn () => setting(UserSetting::Theme))->toThrow(InvalidSettingsType::class, $message);
})->with([
    'not an array' => [User::class, 'must be an array'],
    'no model' => [['owner' => User::class, 'enum' => UserSetting::class], 'needs "model"'],
    'site model as the model' => [
        ['owner' => User::class, 'enum' => UserSetting::class, 'model' => SettingValue::class],
        'needs "model" to be a class that extends or implements [Coyote6\LaravelSettings\Models\OwnedSettingValue]',
    ],
    'missing owner class' => [
        ['owner' => 'App\Models\Missing', 'enum' => UserSetting::class, 'model' => UserSettingValue::class],
        'got [App\Models\Missing]',
    ],
    'a model as the enum' => [
        ['owner' => User::class, 'enum' => User::class, 'model' => UserSettingValue::class],
        'needs "enum"',
    ],
    'empty owner key' => [
        ['owner' => User::class, 'enum' => UserSetting::class, 'model' => UserSettingValue::class, 'owner_key' => ''],
        'needs "owner_key"',
    ],
]);

it('rejects an enum used by two sets of settings', function (array $types, string $message) {
    config(['settings.types' => $types]);

    expect(fn () => setting(UserSetting::Theme))->toThrow(InvalidSettingsType::class, $message);
})->with([
    'two types' => [
        [
            'user' => ['owner' => User::class, 'enum' => UserSetting::class, 'model' => UserSettingValue::class],
            'admin' => ['owner' => Client::class, 'enum' => UserSetting::class, 'model' => ClientSettingValue::class],
        ],
        'used by both type [user] and type [admin]',
    ],
    'the site enum' => [
        [
            'user' => ['owner' => User::class, 'enum' => Setting::class, 'model' => UserSettingValue::class],
        ],
        'used by both the site "enum" and type [user]',
    ],
]);

it('reads site settings without reading the types', function () {
    config(['settings.types.user' => 'broken']);

    expect(setting(Setting::Theme))->toBe('light');
});
