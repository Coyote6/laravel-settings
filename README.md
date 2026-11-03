# coyote6/laravel-settings

Settings for Laravel, named by enums and stored in the database.

```php
setting(Setting::SiteName);                    // read a site-wide setting
setting(Setting::SiteName, 'My Site');         // read, with a default
saveSetting(Setting::SiteName, 'Scorebook');   // save

$client->setting(ClientSetting::Theme);        // read one client's setting
$client->saveSetting(ClientSetting::Theme, 'dark');
setting(UserSetting::Theme);                   // read the logged-in user's setting
```

Every setting name is a case of an enum, so a typo is a PHP error instead of
a setting that silently never loads, and renaming one is done in one place.

Site-wide settings live in one `settings` table. Settings that belong to a
model (each user, each client) live in a table per model, with a real
foreign key to the owner, so deleting the owner deletes its settings.

> **Status:** site-wide and per-model settings work today. The
> `settings:add-type` command that generates a model's settings table, enum
> and model is in progress; until then, create them by hand as shown in
> [Settings per model](#settings-per-model).

## Installation

```bash
composer require coyote6/laravel-settings
php artisan settings:install
```

`settings:install` does three things, and skips any that is already done, so
it is safe to run again:

1. Publishes `config/settings.php`.
2. Publishes the migration for the `settings` table.
3. Creates the site enum, `app/Enums/Settings/Setting.php`.

It then asks whether to run that one migration. Pass `--migrate` to run it
without asking; without a terminal (scripts, CI) it never migrates unless
you pass `--migrate`.

**Already have a `settings` table?** The install command will say so and not
publish the migration. Set a different `table` in `config/settings.php`, then
run `php artisan settings:install` again.

## Naming settings

Site-wide settings are the cases of `App\Enums\Settings\Setting`:

```php
namespace App\Enums\Settings;

use Coyote6\LaravelSettings\Contracts\SettingName;

/**
 * The settings that belong to the whole site.
 */
enum Setting: string implements SettingName
{
    case SiteName = 'site-name';
    case Theme = 'theme';

    /**
     * Get the human-readable name of this setting.
     *
     * @label Label
     */
    public function label(): string
    {
        return match ($this) {
            self::SiteName => 'Site Name',
            self::Theme => 'Theme',
        };
    }

    /**
     * Get the value used when this setting has not been saved.
     *
     * @label Default
     */
    public function default(): mixed
    {
        return match ($this) {
            self::SiteName => config('app.name'),
            self::Theme => 'light',
        };
    }
}
```

- The backing value (`'site-name'`) is what is stored in the database.
- `label()` is for forms and admin screens.
- `default()` is used when nothing is saved and the call site gives no
  default.

To use a different enum, set `enum` in `config/settings.php`. It must
implement `Coyote6\LaravelSettings\Contracts\SettingName`.

## Reading

```php
setting(Setting::Theme);           // 'light' (the enum default)
setting(Setting::Theme, 'dark');   // 'dark'  (the call-site default)
setting(Setting::Theme, default: 'dark');
```

A read returns the first of these that exists:

1. the stored value, even when the stored value is `null`
2. the default passed at the call site (`null` counts as "none given")
3. the enum's `default()`

## Saving, checking and deleting

```php
saveSetting(Setting::Theme, 'dark');       // creates or updates the row

setting()->has(Setting::Theme);            // true when a value is stored
setting()->forget(Setting::Theme);         // delete it; reads use the defaults again
setting()->site()->all();                  // ['theme' => 'dark', ...] (stored values only)
```

Values may be anything `json_encode()` accepts: strings, numbers, booleans,
arrays. They come back with the same type.

`setting()` with no arguments returns the settings manager. The `Settings`
facade gives the same methods:

```php
use Coyote6\LaravelSettings\Facades\Settings;

Settings::get(Setting::Theme, 'light');
Settings::set(Setting::Theme, 'dark');
```

## Settings per model

Each model that owns settings (users, clients, ...) is a **settings type**:
its own table, its own enum and its own small model.

### Setting up a type

Four files, then one line of config. This is what `settings:add-type` will
write for you. The example is for clients.

**1. The migration.** `foreignIdFor()` reads the owner model and picks the
right column type: `bigint` for integer ids, `uuid` for `HasUuids` models,
`ulid` for `HasUlids` models.

```php
Schema::create('client_settings', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->string('name', 100);
    $table->foreignIdFor(Client::class)->constrained()->cascadeOnDelete();
    $table->json('value')->nullable();
    $table->timestamps();

    $table->unique(['client_id', 'name']);
});
```

The unique index starts with the owner column, so one index serves "this
client's settings", "this client's theme" and the foreign key.

**2. The enum**, `app/Enums/Settings/ClientSetting.php`: the same shape as
the site enum (`implements SettingName`, `label()`, `default()`).

**3. The model**, `app/Models/Settings/ClientSettingValue.php`:

```php
namespace App\Models\Settings;

use Coyote6\LaravelSettings\Models\OwnedSettingValue;

class ClientSettingValue extends OwnedSettingValue
{
    protected $table = 'client_settings';
}
```

**4. The owner model** uses the `HasSettings` trait:

```php
use Coyote6\LaravelSettings\Concerns\HasSettings;

class Client extends Model
{
    use HasSettings;
}
```

**5. Register the type** in `config/settings.php`:

```php
'types' => [
    // settings:add-type adds entries below this line.
    'client' => [
        'owner' => App\Models\Client::class,
        'enum' => App\Enums\Settings\ClientSetting::class,
        'model' => App\Models\Settings\ClientSettingValue::class,
    ],
],
```

Add `'owner_key' => 'column'` only when the table's owner column is not the
owner's usual foreign key name (`client_id` for `Client`). Each model can
have one settings type, and each enum belongs to one set of settings.

### Reading and saving for a specific owner

```php
$client->setting(ClientSetting::Theme);           // stored, else the enum default
$client->setting(ClientSetting::Theme, 'light');  // stored, else 'light'
$client->saveSetting(ClientSetting::Theme, 'dark');

setting()->for($client)->has(ClientSetting::Theme);
setting()->for($client)->forget(ClientSetting::Theme);
setting()->for($client)->all();                   // stored values only, by name
```

The lookup order is the same as for site settings. Using another enum's
setting on an owner (`$client->setting(UserSetting::Theme)`) throws, even
when both enums have a `theme` case.

### The current owner

`setting(ClientSetting::Theme)` without an owner reads the **current**
client. For users that is the logged-in user, with nothing to set up. For
any other model, tell the package how to find the current one, in
`AppServiceProvider::boot()`:

```php
use Coyote6\LaravelSettings\Facades\Settings;

Settings::resolveOwnerUsing(Client::class, fn () => auth()->user()?->client);
```

When there is no current owner (a guest, a queued job, an Artisan command,
or a resolver that returns `null`):

- reads skip the stored values and return the defaults
- `has()` returns `false`
- saving and `forget()` throw `MissingSettingsOwner`

So in jobs and commands, always use the owner: `$client->saveSetting(...)`.

## Falling back to another setting

There is no built-in fallback chain. When one setting should fall back to
another, say so in `default()`:

```php
public function default(): mixed
{
    return match ($this) {
        // Use the site theme unless the admin theme was saved.
        self::AdminTheme => setting(self::Theme),
        self::Theme => 'light',
    };
}
```

The same works across tables, for example a client setting that falls back
to the site setting:

```php
// In ClientSetting
self::Theme => setting(Setting::Theme),
```

`default()` only runs when nothing is stored and the call site gave no
default, so the extra lookup costs nothing otherwise.

## Renaming a setting

- **Renaming the case** (`Theme` → `ColorTheme`) changes code only. Nothing
  in the database changes.
- **Changing the backing value** (`'theme'` → `'color-theme'`) changes the
  name stored in the database. Existing rows keep the old name, so reads
  quietly return the default until you move them with a migration:

```php
DB::table('settings')->where('name', 'theme')->update(['name' => 'color-theme']);
```

## How many queries

Nothing is loaded until you read a setting. Each read loads that one setting
with one query and remembers it for the rest of the request, including when
it has no stored value, so reading it again costs nothing. Saving and
forgetting update that memory. Owned settings work the same way, per owner.

`all()` is the exception: it always queries every stored row.

The memory is cleared between queued jobs (and Octane requests), so a
long-running worker never serves values loaded for an earlier job.

## Customising the generated files

```bash
php artisan vendor:publish --tag=settings-stubs
```

This copies the enum stub to `stubs/settings/`, where the commands will use
your copy instead of the package's.

## Testing

```bash
composer test
```

Runs Pint, Larastan and Pest.

## License

MIT. See [LICENSE](LICENSE).
