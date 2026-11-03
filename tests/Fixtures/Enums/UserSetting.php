<?php

namespace Coyote6\LaravelSettings\Tests\Fixtures\Enums;

use Coyote6\LaravelSettings\Contracts\SettingName;

/**
 * The settings each user can change, used by the tests.
 */
enum UserSetting: string implements SettingName
{
    case Theme = 'theme';
    case Timezone = 'timezone';

    /**
     * Get the human-readable name of this setting.
     *
     * @label Label
     */
    public function label(): string
    {
        return match ($this) {
            self::Theme => 'Theme',
            self::Timezone => 'Timezone',
        };
    }

    /**
     * Get the value used when a user has not saved this setting.
     *
     * @label Default
     */
    public function default(): mixed
    {
        return match ($this) {
            self::Theme => 'light',
            self::Timezone => 'UTC',
        };
    }
}
