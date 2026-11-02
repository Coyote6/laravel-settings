<?php

namespace Coyote6\LaravelSettings\Exceptions;

use Coyote6\LaravelSettings\Contracts\SettingName;
use InvalidArgumentException;

/**
 * Thrown when a setting's enum is not listed in config/settings.php.
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
            'The setting enum [%s] is not registered. Set it as "enum" in config/settings.php, or run "php artisan settings:install".',
            $name::class,
        ));
    }
}
