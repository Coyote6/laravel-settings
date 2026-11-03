<?php

namespace Coyote6\LaravelSettings\Exceptions;

use Coyote6\LaravelSettings\Contracts\SettingName;
use Coyote6\LaravelSettings\SettingsType;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown when owned settings are saved or deleted without an owner to store them under.
 */
class MissingSettingsOwner extends RuntimeException
{
    /**
     * Create an exception for a save or delete with no current owner.
     *
     * @label For Name
     *
     * @param  SettingName  $name  The setting being saved or deleted, e.g. ClientSetting::Theme
     * @param  SettingsType  $type  The settings type the setting belongs to
     */
    public static function forName(SettingName $name, SettingsType $type): self
    {
        return new self(sprintf(
            'There is no current [%s] to store [%s::%s] for (a guest, a queued job or an Artisan command). '
            .'Call it on the owner instead, e.g. $owner->saveSetting(...), or register how to find the current owner '
            .'with Settings::resolveOwnerUsing(%s::class, fn () => ...) in a service provider.',
            $type->owner,
            $name::class,
            $name->name,
            class_basename($type->owner),
        ));
    }

    /**
     * Create an exception for an owner that has not been saved yet.
     *
     * @label Unsaved
     *
     * @param  Model  $owner  The owner without a key, e.g. new Client
     */
    public static function unsaved(Model $owner): self
    {
        return new self(sprintf(
            'The [%s] has not been saved, so it has no key to store settings under. Save it first.',
            $owner::class,
        ));
    }
}
