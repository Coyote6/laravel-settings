<?php

namespace Coyote6\LaravelSettings;

use Coyote6\LaravelSettings\Contracts\SettingName;
use Coyote6\LaravelSettings\Exceptions\UnregisteredSettingName;
use Coyote6\LaravelSettings\Models\SettingValue;

/**
 * Read and save settings, choosing the right table from the setting's enum.
 *
 * Bound as a scoped singleton: one instance per request or queued job, so
 * values loaded in one request are never served to the next.
 */
class SettingsManager
{
    /**
     * The repository for site-wide settings, once created.
     */
    protected ?SettingsRepository $site = null;

    /**
     * Get a setting's value.
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
        return $this->repositoryFor($name)->get($name, $default);
    }

    /**
     * Save a setting's value.
     *
     * @label Set
     *
     * @param  SettingName  $name  The setting to save, e.g. Setting::SiteName
     * @param  mixed  $value  Anything json_encode() accepts, e.g. 'Scorebook'
     *
     * @throws UnregisteredSettingName
     */
    public function set(SettingName $name, mixed $value): SettingValue
    {
        return $this->repositoryFor($name)->set($name, $value);
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
        return $this->repositoryFor($name)->has($name);
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
        $this->repositoryFor($name)->forget($name);
    }

    /**
     * Get the repository for site-wide settings.
     *
     * @label Site
     */
    public function site(): SettingsRepository
    {
        return $this->site ??= new SettingsRepository(new SettingValue);
    }

    /**
     * Get the repository that stores the given setting.
     *
     * @label Repository For
     *
     * @todo Phase 2: look the enum up in config('settings.types') and return
     *       the current owner's repository.
     *
     * @param  SettingName  $name  The setting being read or saved, e.g. Setting::SiteName
     *
     * @throws UnregisteredSettingName
     */
    protected function repositoryFor(SettingName $name): SettingsRepository
    {
        if ($name::class === ltrim(config()->string('settings.enum'), '\\')) {
            return $this->site();
        }

        throw UnregisteredSettingName::forName($name);
    }
}
