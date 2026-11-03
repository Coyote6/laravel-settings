<?php

namespace Coyote6\LaravelSettings\Models;

use Illuminate\Support\Str;

/**
 * The base class for models that store one owner's setting values.
 *
 * Each settings type has its own model extending this one, with its own
 * table, e.g. App\Models\Settings\ClientSettingValue for client_settings.
 * The package sets the owner column itself, through the owner's hasMany
 * relation, so it is not mass assignable.
 */
abstract class OwnedSettingValue extends SettingValue
{
    /**
     * Get the table associated with the model.
     *
     * @label Get Table
     *
     * @ai Overrides SettingValue, which falls back to the site table. A
     *     subclass that does not set $table gets Eloquent's own default
     *     (ClientSettingValue → client_setting_values) instead, so owned
     *     rows can never be written to the site table.
     */
    public function getTable(): string
    {
        return $this->table ?? Str::snake(Str::pluralStudly(class_basename($this)));
    }
}
