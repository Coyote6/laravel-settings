<?php

use Coyote6\LaravelSettings\Contracts\SettingName;
use Coyote6\LaravelSettings\Models\SettingValue;
use Coyote6\LaravelSettings\SettingsManager;

if (! function_exists('setting')) {
    /**
     * Get a setting's value, or the settings manager when no name is given.
     *
     * The stored value wins; without one, $default is used, then the enum's
     * own default(). An owned setting (e.g. ClientSetting::Theme) is read for
     * the current owner; with no current owner, the defaults are returned.
     *
     * @label Setting
     *
     * @param  SettingName|null  $name  The setting to read, e.g. Setting::SiteName
     * @param  mixed  $default  The value to use when none is stored, e.g. 'My Site'
     * @return ($name is null ? SettingsManager : mixed)
     */
    function setting(?SettingName $name = null, mixed $default = null): mixed
    {
        $settings = app(SettingsManager::class);

        return $name === null ? $settings : $settings->get($name, $default);
    }
}

if (! function_exists('saveSetting')) {
    /**
     * Save a setting's value, for the current owner when the setting is owned.
     *
     * @label Save Setting
     *
     * @param  SettingName  $name  The setting to save, e.g. Setting::SiteName
     * @param  mixed  $value  Anything json_encode() accepts, e.g. 'Scorebook'
     */
    function saveSetting(SettingName $name, mixed $value): SettingValue
    {
        return app(SettingsManager::class)->set($name, $value);
    }
}
