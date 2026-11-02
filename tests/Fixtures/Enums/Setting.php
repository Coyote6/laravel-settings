<?php

namespace Coyote6\LaravelSettings\Tests\Fixtures\Enums;

use Coyote6\LaravelSettings\Contracts\SettingName;

/**
 * Site-wide settings used by the tests.
 */
enum Setting: string implements SettingName
{
    case SiteName = 'site-name';
    case Theme = 'theme';
    case AdminTheme = 'admin-theme';
    case MaintenanceMode = 'maintenance-mode';
    case Limits = 'limits';

    /**
     * Get the human-readable name of this setting.
     *
     * @label Label
     */
    public function label(): string
    {
        return match ($this) {
            self::SiteName => 'Site Name',
            self::Theme => 'Theme',
            self::AdminTheme => 'Admin Theme',
            self::MaintenanceMode => 'Maintenance Mode',
            self::Limits => 'Limits',
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
            self::SiteName => 'Default Site',
            self::Theme => 'light',
            // Falls back to the stored site theme, as the README shows.
            self::AdminTheme => setting(self::Theme),
            self::MaintenanceMode => false,
            self::Limits => null,
        };
    }
}
