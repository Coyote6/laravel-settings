<?php

namespace Coyote6\LaravelSettings\Tests\Fixtures\Enums;

use Coyote6\LaravelSettings\Contracts\SettingName;

/**
 * A setting enum that config/settings.php does not list.
 */
enum UnregisteredSetting: string implements SettingName
{
    case Theme = 'theme';

    /**
     * Get the human-readable name of this setting.
     *
     * @label Label
     */
    public function label(): string
    {
        return match ($this) {
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
            self::Theme => 'light',
        };
    }
}
