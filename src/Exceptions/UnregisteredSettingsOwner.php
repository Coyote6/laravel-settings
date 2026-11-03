<?php

namespace Coyote6\LaravelSettings\Exceptions;

use Coyote6\LaravelSettings\SettingsType;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Thrown when a model does not match exactly one settings type in config/settings.php.
 */
class UnregisteredSettingsOwner extends InvalidArgumentException
{
    /**
     * Create an exception for a model that has no settings type.
     *
     * @label For Owner
     *
     * @param  Model  $owner  The model that was used, e.g. a Team
     */
    public static function forOwner(Model $owner): self
    {
        return new self(sprintf(
            'The model [%s] has no settings type. Add one to "types" in config/settings.php.',
            $owner::class,
        ));
    }

    /**
     * Create an exception for a model that matches more than one settings type.
     *
     * @label Ambiguous
     *
     * @param  Model  $owner  The model that was used, e.g. a User
     * @param  list<string>  $types  The keys of the matching types, e.g. ['user', 'admin']
     */
    public static function ambiguous(Model $owner, array $types): self
    {
        return new self(sprintf(
            'The model [%s] matches more than one settings type ([%s]) in config/settings.php. Each model can have one settings type.',
            $owner::class,
            implode('], [', $types),
        ));
    }

    /**
     * Create an exception for a resolver that returned the wrong kind of model.
     *
     * @label From Resolver
     *
     * @param  Model  $owner  The model the resolver returned, e.g. a User
     * @param  SettingsType  $type  The settings type the resolver was asked for
     */
    public static function fromResolver(Model $owner, SettingsType $type): self
    {
        return new self(sprintf(
            'The current owner of [%s] settings must be a [%s], but the resolver returned a [%s]. Check Settings::resolveOwnerUsing(%s::class, ...).',
            $type->key,
            $type->owner,
            $owner::class,
            class_basename($type->owner),
        ));
    }
}
