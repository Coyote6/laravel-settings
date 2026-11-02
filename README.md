# coyote6/laravel-settings

Settings for Laravel, named by enums and stored in the database.

```php
setting(Setting::SiteName);                 // read
setting(Setting::SiteName, 'My Site');      // read, with a default
saveSetting(Setting::SiteName, 'Scorebook'); // save
```

Every setting name is a case of an enum, so a typo is a PHP error instead of
a setting that silently never loads, and renaming one is done in one place.

> **Status:** site-wide settings work today. Per-model settings (each user,
> each client, each in its own table with a real foreign key) and the
> `settings:add-type` generator are in progress.

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
forgetting update that memory.

`setting()->site()->all()` is the exception: it always queries every stored
row.

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
