<?php

namespace Coyote6\LaravelSettings\Contracts;

use BackedEnum;

/**
 * A setting name, written as a case of a string-backed enum.
 *
 * Extending BackedEnum means only an enum can implement this, so every
 * setting name in the application is listed in one place and a typo is a
 * PHP error instead of a silently missing setting.
 */
interface SettingName extends BackedEnum
{
    /**
     * Get the human-readable name of this setting.
     *
     * @label Label
     */
    public function label(): string;

    /**
     * Get the value used when this setting has not been saved.
     *
     * Runs only when nothing is stored and the call site gave no default,
     * so it may itself read another setting (for example, fall back to a
     * site-wide value).
     *
     * @label Default
     */
    public function default(): mixed;
}
