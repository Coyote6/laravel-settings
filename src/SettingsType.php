<?php

namespace Coyote6\LaravelSettings;

use Coyote6\LaravelSettings\Contracts\SettingName;
use Coyote6\LaravelSettings\Exceptions\InvalidSettingsType;
use Coyote6\LaravelSettings\Models\OwnedSettingValue;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of config('settings.types'): a model that owns its own settings.
 */
readonly class SettingsType
{
    /**
     * Create a new settings type.
     *
     * @label Construct
     *
     * @param  string  $key  The type's key in config('settings.types'), e.g. client
     * @param  class-string<Model>  $owner  The model that owns these settings, e.g. App\Models\Client
     * @param  class-string<SettingName>  $enum  The enum that names these settings, e.g. App\Enums\Settings\ClientSetting
     * @param  class-string<OwnedSettingValue>  $model  The model for one stored value, e.g. App\Models\Settings\ClientSettingValue
     * @param  string|null  $ownerKey  The settings table's column holding the owner's key; null means the owner's foreign key name, e.g. client_id
     */
    public function __construct(
        public string $key,
        public string $owner,
        public string $enum,
        public string $model,
        public ?string $ownerKey = null,
    ) {}

    /**
     * Create a settings type from its config entry, checking every class it names.
     *
     * @label From Config
     *
     * @param  string  $key  The type's key in config('settings.types'), e.g. client
     * @param  mixed  $config  The entry, e.g. ['owner' => Client::class, 'enum' => ClientSetting::class, 'model' => ClientSettingValue::class]
     *
     * @throws InvalidSettingsType
     */
    public static function fromConfig(string $key, mixed $config): self
    {
        if (! is_array($config)) {
            throw InvalidSettingsType::notAnArray($key);
        }

        $ownerKey = $config['owner_key'] ?? null;

        if ($ownerKey !== null && (! is_string($ownerKey) || $ownerKey === '')) {
            throw InvalidSettingsType::invalidOwnerKey($key);
        }

        return new self(
            key: $key,
            owner: static::classFrom($key, $config, 'owner', Model::class),
            enum: static::classFrom($key, $config, 'enum', SettingName::class),
            model: static::classFrom($key, $config, 'model', OwnedSettingValue::class),
            ownerKey: $ownerKey,
        );
    }

    /**
     * Get a class name from a type's config entry, checking what it extends.
     *
     * @label Class From
     *
     * @ai is_subclass_of() also accepts an interface as the parent, and is
     *     true for an enum that implements it, so one check covers the
     *     owner model, the enum and the value model. It autoloads the class,
     *     so a typo in config fails here with the type's name in the message.
     *
     * @template TParent of object
     *
     * @param  string  $key  The type's key in config('settings.types'), e.g. client
     * @param  array<mixed>  $config  The type's config entry
     * @param  string  $option  The option to read, e.g. model
     * @param  class-string<TParent>  $parent  The class or interface it must extend or implement, e.g. OwnedSettingValue::class
     * @return class-string<TParent>
     *
     * @throws InvalidSettingsType
     */
    protected static function classFrom(string $key, array $config, string $option, string $parent): string
    {
        $class = $config[$option] ?? null;

        if (is_string($class)) {
            $class = ltrim($class, '\\');
        }

        if (! is_string($class) || ! is_subclass_of($class, $parent)) {
            throw InvalidSettingsType::invalidClass($key, $option, $parent, $class);
        }

        return $class;
    }
}
