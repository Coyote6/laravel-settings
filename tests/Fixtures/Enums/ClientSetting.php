<?php

namespace Coyote6\LaravelSettings\Tests\Fixtures\Enums;

use Coyote6\LaravelSettings\Contracts\SettingName;

/**
 * The settings each client can change, used by the tests.
 */
enum ClientSetting: string implements SettingName
{
    case Theme = 'theme';
    case ShowFieldingStats = 'show-fielding-stats';

    /**
     * Get the human-readable name of this setting.
     *
     * @label Label
     */
    public function label(): string
    {
        return match ($this) {
            self::Theme => 'Theme',
            self::ShowFieldingStats => 'Show Fielding Stats',
        };
    }

    /**
     * Get the value used when a client has not saved this setting.
     *
     * @label Default
     */
    public function default(): mixed
    {
        return match ($this) {
            // Falls back to the site-wide theme, as the README shows.
            self::Theme => setting(Setting::Theme),
            self::ShowFieldingStats => false,
        };
    }
}
