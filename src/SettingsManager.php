<?php

namespace Coyote6\LaravelSettings;

use Closure;
use Coyote6\LaravelSettings\Contracts\SettingName;
use Coyote6\LaravelSettings\Exceptions\InvalidSettingsType;
use Coyote6\LaravelSettings\Exceptions\MissingSettingsOwner;
use Coyote6\LaravelSettings\Exceptions\UnregisteredSettingName;
use Coyote6\LaravelSettings\Exceptions\UnregisteredSettingsOwner;
use Coyote6\LaravelSettings\Models\SettingValue;
use Illuminate\Database\Eloquent\Model;

/**
 * Read and save settings, choosing the right table from the setting's enum.
 *
 * Bound as a scoped singleton: one instance per request or queued job, so
 * values loaded in one request are never served to the next.
 */
class SettingsManager
{
    /**
     * The repository for site-wide settings, once created.
     */
    protected ?SettingsRepository $site = null;

    /**
     * The repositories for owned settings created so far, keyed by type and owner key.
     *
     * @var array<string, SettingsRepository>
     */
    protected array $owned = [];

    /**
     * The settings types from config, once read, keyed by type.
     *
     * @var array<string, SettingsType>|null
     */
    protected ?array $types = null;

    /**
     * Create a new settings manager.
     *
     * @label Construct
     *
     * @param  OwnerResolvers  $owners  The functions that find each type's current owner
     */
    public function __construct(protected OwnerResolvers $owners) {}

    /**
     * Get a setting's value, for the current owner when the setting is owned.
     *
     * With no current owner (a guest, a queued job, a command), the stored
     * values are skipped and the defaults are returned.
     *
     * @label Get
     *
     * @param  SettingName  $name  The setting to read, e.g. Setting::SiteName
     * @param  mixed  $default  The value to use when none is stored; null means "use the enum's default"
     *
     * @throws UnregisteredSettingName
     * @throws InvalidSettingsType
     * @throws UnregisteredSettingsOwner
     * @throws MissingSettingsOwner
     */
    public function get(SettingName $name, mixed $default = null): mixed
    {
        $repository = $this->currentRepositoryFor($name);

        if ($repository === null) {
            return $default ?? $name->default();
        }

        return $repository->get($name, $default);
    }

    /**
     * Save a setting's value, for the current owner when the setting is owned.
     *
     * @label Set
     *
     * @param  SettingName  $name  The setting to save, e.g. Setting::SiteName
     * @param  mixed  $value  Anything json_encode() accepts, e.g. 'Scorebook'
     *
     * @throws UnregisteredSettingName
     * @throws InvalidSettingsType
     * @throws UnregisteredSettingsOwner
     * @throws MissingSettingsOwner When the setting is owned and there is no current owner
     */
    public function set(SettingName $name, mixed $value): SettingValue
    {
        return $this->requireCurrentRepositoryFor($name)->set($name, $value);
    }

    /**
     * Determine whether a value is stored for a setting.
     *
     * Always false for an owned setting when there is no current owner.
     *
     * @label Has
     *
     * @param  SettingName  $name  The setting to check, e.g. Setting::SiteName
     *
     * @throws UnregisteredSettingName
     * @throws InvalidSettingsType
     * @throws UnregisteredSettingsOwner
     * @throws MissingSettingsOwner
     */
    public function has(SettingName $name): bool
    {
        return $this->currentRepositoryFor($name)?->has($name) ?? false;
    }

    /**
     * Delete a setting's stored value, so reads fall back to the defaults.
     *
     * @label Forget
     *
     * @param  SettingName  $name  The setting to delete, e.g. Setting::SiteName
     *
     * @throws UnregisteredSettingName
     * @throws InvalidSettingsType
     * @throws UnregisteredSettingsOwner
     * @throws MissingSettingsOwner When the setting is owned and there is no current owner
     */
    public function forget(SettingName $name): void
    {
        $this->requireCurrentRepositoryFor($name)->forget($name);
    }

    /**
     * Get the settings of a specific owner, e.g. setting()->for($client)->get(ClientSetting::Theme).
     *
     * @label For
     *
     * @param  Model  $owner  A saved model with a settings type, e.g. a Client
     *
     * @throws InvalidSettingsType
     * @throws UnregisteredSettingsOwner
     * @throws MissingSettingsOwner When the owner has not been saved
     */
    public function for(Model $owner): SettingsRepository
    {
        return $this->repository($this->typeForOwner($owner), $owner);
    }

    /**
     * Register the function that finds the current owner of a model's settings.
     *
     * Call it in a service provider's boot(). Without one, the current owner
     * is the logged-in user, if it is an instance of the owner model.
     *
     * @label Resolve Owner Using
     *
     * @param  class-string<Model>  $owner  The owner model, e.g. App\Models\Client
     * @param  Closure(): ?Model  $resolver  Returns the current owner, or null when there is none, e.g. fn () => auth()->user()?->client
     */
    public function resolveOwnerUsing(string $owner, Closure $resolver): void
    {
        $this->owners->register($owner, $resolver);
    }

    /**
     * Get the repository for site-wide settings.
     *
     * @label Site
     */
    public function site(): SettingsRepository
    {
        return $this->site ??= new SettingsRepository(new SettingValue, $this->siteEnum());
    }

    /**
     * Get the repository that stores the given setting for its current owner.
     *
     * @label Current Repository For
     *
     * @param  SettingName  $name  The setting being read or saved, e.g. ClientSetting::Theme
     * @return SettingsRepository|null The repository, or null when the setting is owned and there is no current owner
     *
     * @throws UnregisteredSettingName
     * @throws InvalidSettingsType
     * @throws UnregisteredSettingsOwner
     * @throws MissingSettingsOwner
     */
    protected function currentRepositoryFor(SettingName $name): ?SettingsRepository
    {
        if ($name::class === $this->siteEnum()) {
            return $this->site();
        }

        $type = $this->typeForName($name);
        $owner = $this->owners->resolve($type->owner);

        return $owner === null ? null : $this->repository($type, $owner);
    }

    /**
     * Get the repository that stores the given setting, failing when there is no current owner.
     *
     * @label Require Current Repository For
     *
     * @param  SettingName  $name  The setting being saved or deleted, e.g. ClientSetting::Theme
     *
     * @throws UnregisteredSettingName
     * @throws InvalidSettingsType
     * @throws UnregisteredSettingsOwner
     * @throws MissingSettingsOwner
     */
    protected function requireCurrentRepositoryFor(SettingName $name): SettingsRepository
    {
        return $this->currentRepositoryFor($name)
            ?? throw MissingSettingsOwner::forName($name, $this->typeForName($name));
    }

    /**
     * Get one owner's repository for a settings type, creating it once per request.
     *
     * @label Repository
     *
     * @ai One repository per owner is kept for the rest of the request, so
     *     each owner's settings are read once. A job that loops over many
     *     owners keeps one small repository for each until it ends.
     *
     * @param  SettingsType  $type  The settings type, e.g. the client type
     * @param  Model  $owner  The owner, e.g. a Client
     *
     * @throws UnregisteredSettingsOwner When a resolver returned a model of the wrong class
     * @throws MissingSettingsOwner When the owner has not been saved
     */
    protected function repository(SettingsType $type, Model $owner): SettingsRepository
    {
        if (! $owner instanceof $type->owner) {
            throw UnregisteredSettingsOwner::fromResolver($owner, $type);
        }

        $key = $owner->getKey() ?? throw MissingSettingsOwner::unsaved($owner);

        return $this->owned[$type->key.':'.$key] ??= new SettingsRepository(
            new $type->model,
            $type->enum,
            $owner,
            $type->ownerKey,
        );
    }

    /**
     * Get the settings type whose enum names the given setting.
     *
     * @label Type For Name
     *
     * @param  SettingName  $name  The setting, e.g. ClientSetting::Theme
     *
     * @throws UnregisteredSettingName
     * @throws InvalidSettingsType
     */
    protected function typeForName(SettingName $name): SettingsType
    {
        foreach ($this->types() as $type) {
            if ($type->enum === $name::class) {
                return $type;
            }
        }

        throw UnregisteredSettingName::forName($name);
    }

    /**
     * Get the one settings type owned by the given model.
     *
     * @label Type For Owner
     *
     * @param  Model  $owner  The owner, e.g. a Client
     *
     * @throws InvalidSettingsType
     * @throws UnregisteredSettingsOwner
     */
    protected function typeForOwner(Model $owner): SettingsType
    {
        $matches = array_filter($this->types(), fn (SettingsType $type) => $owner instanceof $type->owner);

        if (count($matches) > 1) {
            throw UnregisteredSettingsOwner::ambiguous($owner, array_keys($matches));
        }

        return array_values($matches)[0] ?? throw UnregisteredSettingsOwner::forOwner($owner);
    }

    /**
     * Get every settings type from config, checked, keyed by type.
     *
     * @label Types
     *
     * @return array<string, SettingsType>
     *
     * @throws InvalidSettingsType
     */
    protected function types(): array
    {
        if ($this->types !== null) {
            return $this->types;
        }

        $types = [];
        $enums = [$this->siteEnum() => 'the site "enum"'];

        foreach (config()->array('settings.types', []) as $key => $config) {
            $type = SettingsType::fromConfig((string) $key, $config);

            if (isset($enums[$type->enum])) {
                throw InvalidSettingsType::sharedEnum($type->enum, $enums[$type->enum], "type [{$type->key}]");
            }

            $enums[$type->enum] = "type [{$type->key}]";
            $types[$type->key] = $type;
        }

        return $this->types = $types;
    }

    /**
     * Get the class of the enum that names site-wide settings.
     *
     * @label Site Enum
     */
    protected function siteEnum(): string
    {
        return ltrim(config()->string('settings.enum'), '\\');
    }
}
