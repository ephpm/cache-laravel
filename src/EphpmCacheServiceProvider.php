<?php

declare(strict_types=1);

namespace Ephpm\Cache\Laravel;

use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered Laravel service provider that registers the `ephpm`
 * cache driver. Apps can then add a store under `config/cache.php`:
 *
 *   'stores' => [
 *       'ephpm' => ['driver' => 'ephpm', 'prefix' => 'cache'],
 *   ],
 *
 * and resolve it with `Cache::store('ephpm')` (or set
 * `CACHE_DRIVER=ephpm` to make it the default).
 */
final class EphpmCacheServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register the custom driver in boot(), not register(): the
        // `cache` manager this resolves is bound by the framework's own
        // CacheServiceProvider, which may not have registered yet during
        // this provider's register() phase (provider ordering is not
        // guaranteed). Calling Cache::extend() there throws
        // "Target class [cache] does not exist".
        Cache::extend('ephpm', function ($app, array $config): Repository {
            $store = new EphpmStore($config['prefix'] ?? '');

            // Build the Repository through the cache manager, not with
            // `new Repository($store)` directly. The manager's repository()
            // attaches the framework event dispatcher, so CacheHit,
            // CacheMissed, KeyWritten and KeyForgotten events fire for this
            // store just like they do for redis/memcached. A bare
            // `new Repository($store)` has no dispatcher, silently dropping
            // every cache event (breaking telemetry, cache-event listeners,
            // and Telescope's cache tab).
            return $app['cache']->repository($store);
        });
    }
}
