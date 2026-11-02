<?php

namespace Coyote6\LaravelSettings\Facades;

use Coyote6\LaravelSettings\Contracts\SettingName;
use Coyote6\LaravelSettings\Models\SettingValue;
use Coyote6\LaravelSettings\SettingsManager;
use Coyote6\LaravelSettings\SettingsRepository;
use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed get(SettingName $name, mixed $default = null)
 * @method static SettingValue set(SettingName $name, mixed $value)
 * @method static bool has(SettingName $name)
 * @method static void forget(SettingName $name)
 * @method static SettingsRepository site()
 *
 * @see SettingsManager
 */
class Settings extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @label Get Facade Accessor
     */
    protected static function getFacadeAccessor(): string
    {
        return SettingsManager::class;
    }
}
