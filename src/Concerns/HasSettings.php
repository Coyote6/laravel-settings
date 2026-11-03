<?php

namespace Coyote6\LaravelSettings\Concerns;

use Coyote6\LaravelSettings\Contracts\SettingName;
use Coyote6\LaravelSettings\Exceptions\MissingSettingsOwner;
use Coyote6\LaravelSettings\Exceptions\UnregisteredSettingName;
use Coyote6\LaravelSettings\Exceptions\UnregisteredSettingsOwner;
use Coyote6\LaravelSettings\Models\SettingValue;
use Coyote6\LaravelSettings\SettingsManager;
use Illuminate\Database\Eloquent\Model;

/**
 * Read and save a model's own settings, e.g. $client->setting(ClientSetting::Theme).
 *
 * @phpstan-require-extends Model
 */
trait HasSettings
{
    /**
     * Get one of this model's settings.
     *
     * The stored value wins; without one, $default is used, then the enum's
     * own default().
     *
     * @label Setting
     *
     * @param  SettingName  $name  The setting to read, e.g. ClientSetting::Theme
     * @param  mixed  $default  The value to use when none is stored; null means "use the enum's default"
     *
     * @throws UnregisteredSettingsOwner
     * @throws UnregisteredSettingName
     * @throws MissingSettingsOwner
     */
    public function setting(SettingName $name, mixed $default = null): mixed
    {
        return app(SettingsManager::class)->for($this)->get($name, $default);
    }

    /**
     * Save one of this model's settings.
     *
     * @label Save Setting
     *
     * @param  SettingName  $name  The setting to save, e.g. ClientSetting::Theme
     * @param  mixed  $value  Anything json_encode() accepts, e.g. 'dark'
     *
     * @throws UnregisteredSettingsOwner
     * @throws UnregisteredSettingName
     * @throws MissingSettingsOwner
     */
    public function saveSetting(SettingName $name, mixed $value): SettingValue
    {
        return app(SettingsManager::class)->for($this)->set($name, $value);
    }
}
