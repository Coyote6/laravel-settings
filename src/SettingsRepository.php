<?php

namespace Coyote6\LaravelSettings;

use Coyote6\LaravelSettings\Contracts\SettingName;
use Coyote6\LaravelSettings\Exceptions\UnregisteredSettingName;
use Coyote6\LaravelSettings\Models\SettingValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The stored settings for one scope: the whole site, or one owner.
 *
 * Each setting is loaded only when it is first read, with one query for
 * that setting alone, and remembered for the rest of the request, including
 * the fact that it has no stored value.
 */
class SettingsRepository
{
    /**
     * The stored values read so far, keyed by setting name.
     *
     * @var array<string, mixed>
     */
    protected array $values = [];

    /**
     * The setting names read so far that have no stored value.
     *
     * @var array<string, true>
     */
    protected array $missing = [];

    /**
     * Create a new settings repository.
     *
     * @label Construct
     *
     * @param  SettingValue  $model  An empty model whose table holds these settings
     * @param  string  $enum  The class of the enum that names these settings, e.g. App\Enums\Settings\ClientSetting
     * @param  Model|null  $owner  The model these settings belong to; null for site settings
     * @param  string|null  $ownerKey  The column holding the owner's key; null means the owner's foreign key name, e.g. client_id
     */
    public function __construct(
        protected SettingValue $model,
        protected string $enum,
        protected ?Model $owner = null,
        protected ?string $ownerKey = null,
    ) {}

    /**
     * Get a setting's value.
     *
     * The stored value wins, even when it is null. Without one, the call
     * site's default is used, then the enum's own default().
     *
     * @label Get
     *
     * @param  SettingName  $name  The setting to read, e.g. Setting::SiteName
     * @param  mixed  $default  The value to use when none is stored; null means "use the enum's default"
     *
     * @throws UnregisteredSettingName
     */
    public function get(SettingName $name, mixed $default = null): mixed
    {
        $key = $this->nameOf($name);

        if ($this->load($key)) {
            return $this->values[$key];
        }

        return $default ?? $name->default();
    }

    /**
     * Save a setting's value, creating its row or updating the existing one.
     *
     * @label Set
     *
     * @ai updateOrCreate() is safe when two requests save the same setting at
     *     once: firstOrCreate() falls back to createOrFirst(), which catches
     *     the unique-index error and re-reads (Laravel 13.39: Eloquent\Builder
     *     for site settings, HasOneOrMany for owned ones). It relies on the
     *     unique index on name, plus the owner column for owned settings.
     *
     * @param  SettingName  $name  The setting to save, e.g. Setting::SiteName
     * @param  mixed  $value  Anything json_encode() accepts, e.g. 'Scorebook'
     *
     * @throws UnregisteredSettingName
     */
    public function set(SettingName $name, mixed $value): SettingValue
    {
        $key = $this->nameOf($name);

        $setting = $this->query()->updateOrCreate(
            ['name' => $key],
            ['value' => $value],
        );

        // Remember the decoded value, so the next read matches what a fresh
        // load from the database would return.
        $this->values[$key] = $setting->value;
        unset($this->missing[$key]);

        return $setting;
    }

    /**
     * Determine whether a value is stored for a setting.
     *
     * @label Has
     *
     * @param  SettingName  $name  The setting to check, e.g. Setting::SiteName
     *
     * @throws UnregisteredSettingName
     */
    public function has(SettingName $name): bool
    {
        return $this->load($this->nameOf($name));
    }

    /**
     * Delete a setting's stored value, so reads fall back to the defaults.
     *
     * @label Forget
     *
     * @param  SettingName  $name  The setting to delete, e.g. Setting::SiteName
     *
     * @throws UnregisteredSettingName
     */
    public function forget(SettingName $name): void
    {
        $key = $this->nameOf($name);

        $this->query()->where('name', $key)->delete();

        unset($this->values[$key]);
        $this->missing[$key] = true;
    }

    /**
     * Get every stored value in this scope, keyed by setting name.
     *
     * Always queries the database: only stored values are returned, not
     * defaults, and rows whose name is no longer an enum case are included.
     *
     * @label All
     *
     * @ai Ordered by name so every database returns the same order. Without
     *     it, MariaDB returns an owner's rows in the order of the
     *     (owner, name) unique index while SQLite returns them as inserted.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->query()
            ->orderBy('name')
            ->get(['name', 'value'])
            ->mapWithKeys(fn (SettingValue $setting) => [$setting->name => $setting->value])
            ->all();
    }

    /**
     * Load one setting into memory unless it was read already.
     *
     * @label Load
     *
     * @param  string  $key  The stored setting name, e.g. site-name
     * @return bool Whether a value is stored
     */
    protected function load(string $key): bool
    {
        if (array_key_exists($key, $this->values)) {
            return true;
        }

        if (isset($this->missing[$key])) {
            return false;
        }

        $setting = $this->query()->where('name', $key)->first(['value']);

        if ($setting === null) {
            $this->missing[$key] = true;

            return false;
        }

        $this->values[$key] = $setting->value;

        return true;
    }

    /**
     * Get the name a setting is stored under, after checking it belongs here.
     *
     * @label Name Of
     *
     * @ai The enum check stops a setting from another enum reading or
     *     writing this table, e.g. $client->setting(UserSetting::Theme). Both
     *     enums may have a "theme" case, so without the check it would
     *     silently use the client's row.
     *
     *     Cast to string because SettingName can only require BackedEnum,
     *     and PHP has no way to require a string-backed one. The name column
     *     is a string and the database returns string keys, so an int-backed
     *     enum's names are stored and compared as strings too.
     *
     * @param  SettingName  $name  The setting, e.g. Setting::SiteName
     *
     * @throws UnregisteredSettingName
     */
    protected function nameOf(SettingName $name): string
    {
        if (! $name instanceof $this->enum) {
            throw UnregisteredSettingName::wrongEnum($name, $this->enum);
        }

        return (string) $name->value;
    }

    /**
     * Get a query limited to this repository's rows.
     *
     * @label Query
     *
     * @ai Owned settings are queried through the owner's hasMany relation.
     *     Eloquent adds "where client_id = ?" to every query and sets
     *     client_id itself when it creates a row
     *     (HasOneOrMany::setForeignAttributesForCreate()), so the owner column
     *     never has to be mass assignable. A new relation is built for every
     *     query because where() on a relation changes that relation.
     *
     * @return Builder<SettingValue>|HasMany<SettingValue, Model>
     */
    protected function query(): Builder|HasMany
    {
        if ($this->owner === null) {
            return $this->model->newQuery();
        }

        return $this->owner->hasMany($this->model::class, $this->ownerKey);
    }
}
