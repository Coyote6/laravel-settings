<?php

namespace Coyote6\LaravelSettings\Exceptions;

use Coyote6\LaravelSettings\Contracts\SettingName;
use InvalidArgumentException;

/**
 * Thrown when a setting's enum is not listed in config/settings.php, or is used with the wrong settings.
 */
class UnregisteredSettingName extends InvalidArgumentException
{
    /**
     * Create an exception for a setting whose enum is not registered.
     *
     * @label For Name
     *
     * @param  SettingName  $name  The setting that was used, e.g. UserSetting::Theme
     */
    public static function forName(SettingName $name): self
    {
        return new self(sprintf(
            'The setting enum [%s] is not registered. Set it as the site "enum", or as the "enum" of an entry in "types", in config/settings.php. '
            .'"php artisan settings:install" creates the site enum.',
            $name::class,
        ));
    }

    /**
     * Create an exception for a setting used with settings named by another enum.
     *
     * @label Wrong Enum
     *
     * @param  SettingName  $name  The setting that was used, e.g. UserSetting::Theme
     * @param  string  $enum  The enum these settings use, e.g. App\Enums\Settings\ClientSetting
     */
    public static function wrongEnum(SettingName $name, string $enum): self
    {
        return new self(sprintf(
            'The setting [%s::%s] cannot be used here: these settings are named by [%s].',
            $name::class,
            $name->name,
            $enum,
        ));
    }
}
