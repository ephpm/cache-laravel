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
    public function register(): void
    {
        Cache::extend('ephpm', function ($app, array $config): Repository {
            $store = new EphpmStore($config['prefix'] ?? '');
            return new Repository($store);
        });
    }
}
