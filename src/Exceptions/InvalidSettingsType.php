<?php

namespace Coyote6\LaravelSettings\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when an entry in config('settings.types') is not usable.
 */
class InvalidSettingsType extends InvalidArgumentException
{
    /**
     * Create an exception for a type entry that is not an array.
     *
     * @label Not An Array
     *
     * @param  string  $type  The type's key in config('settings.types'), e.g. client
     */
    public static function notAnArray(string $type): self
    {
        return new self(sprintf(
            'The settings type [%s] in config/settings.php must be an array with "owner", "enum" and "model" keys.',
            $type,
        ));
    }

    /**
     * Create an exception for a type option that does not name a suitable class.
     *
     * @label Invalid Class
     *
     * @param  string  $type  The type's key in config('settings.types'), e.g. client
     * @param  string  $option  The option that is wrong, e.g. model
     * @param  string  $parent  The class or interface it must extend or implement, e.g. OwnedSettingValue
     * @param  mixed  $given  The value found in config, e.g. App\Models\Client
     */
    public static function invalidClass(string $type, string $option, string $parent, mixed $given): self
    {
        return new self(sprintf(
            'The settings type [%s] in config/settings.php needs "%s" to be a class that extends or implements [%s]; got [%s].',
            $type,
            $option,
            $parent,
            is_string($given) ? $given : get_debug_type($given),
        ));
    }

    /**
     * Create an exception for an owner key that is not a column name.
     *
     * @label Invalid Owner Key
     *
     * @param  string  $type  The type's key in config('settings.types'), e.g. client
     */
    public static function invalidOwnerKey(string $type): self
    {
        return new self(sprintf(
            'The settings type [%s] in config/settings.php needs "owner_key" to be a column name, or left out.',
            $type,
        ));
    }

    /**
     * Create an exception for an enum used by more than one set of settings.
     *
     * @label Shared Enum
     *
     * @param  string  $enum  The enum class, e.g. App\Enums\Settings\UserSetting
     * @param  string  $first  The first user of the enum, e.g. type [user]
     * @param  string  $second  The second user of the enum, e.g. type [admin]
     */
    public static function sharedEnum(string $enum, string $first, string $second): self
    {
        return new self(sprintf(
            'The enum [%s] is used by both %s and %s in config/settings.php. Each set of settings needs its own enum.',
            $enum,
            $first,
            $second,
        ));
    }
}
