<?php

namespace Coyote6\LaravelSettings;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The functions that find the "current" owner of each kind of owned settings.
 *
 * Bound as a singleton, unlike the settings manager. Resolvers are
 * registered once while the application boots, so they must outlive the
 * scoped instances that Laravel throws away between queued jobs and
 * Octane requests.
 */
class OwnerResolvers
{
    /**
     * The registered resolvers, keyed by owner model class.
     *
     * @var array<string, Closure(): ?Model>
     */
    protected array $resolvers = [];

    /**
     * Create a new owner resolver registry.
     *
     * @label Construct
     *
     * @param  AuthFactory  $auth  Laravel's auth manager, used to find the logged-in user
     */
    public function __construct(protected AuthFactory $auth) {}

    /**
     * Register the function that finds the current owner of a model's settings.
     *
     * @label Register
     *
     * @param  class-string<Model>  $owner  The owner model, e.g. App\Models\Client
     * @param  Closure(): ?Model  $resolver  Returns the current owner, or null when there is none, e.g. fn () => auth()->user()?->client
     */
    public function register(string $owner, Closure $resolver): void
    {
        $this->resolvers[ltrim($owner, '\\')] = $resolver;
    }

    /**
     * Find the current owner of a model's settings.
     *
     * Without a registered resolver, the current owner is the logged-in
     * user, if it is an instance of the owner model.
     *
     * @label Resolve
     *
     * @ai The logged-in user comes from the default guard, which Laravel's
     *     auth middleware switches per request (auth:api makes it the api
     *     guard), the same default Pennant uses for its scope. It is not
     *     remembered, so a login or logout during the request is seen.
     *
     * @param  class-string<Model>  $owner  The owner model, e.g. App\Models\User
     * @return Model|null The current owner, or null for a guest, a queued job or an Artisan command
     */
    public function resolve(string $owner): ?Model
    {
        if (isset($this->resolvers[$owner])) {
            return ($this->resolvers[$owner])();
        }

        $user = $this->auth->guard()->user();

        return $user instanceof $owner ? $user : null;
    }
}
