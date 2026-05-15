# ephpm/cache-laravel

[Laravel Cache](https://laravel.com/docs/cache) store backed by
[ePHPm](https://ephpm.dev)'s in-process KV store via the `ephpm_kv_*`
SAPI functions. The same `Cache::get`/`Cache::put`/`Cache::remember`
API your app already uses, zero socket round-trips, zero RESP parsing,
zero serialization beyond what userland code already does.

```php
// php artisan tinker
Cache::store('ephpm')->put('user:42', ['name' => 'Alice'], 3600);
Cache::store('ephpm')->get('user:42');     // ['name' => 'Alice']
Cache::store('ephpm')->increment('hits');  // 1
Cache::store('ephpm')->increment('hits');  // 2
```

Each call resolves to a direct C function call into the Rust DashMap
backing ePHPm's KV store. There's no Redis daemon, no Memcached
daemon, and there's no socket even in-process; this is the same code
path a Rust handler would take.

---

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [End-to-end: a fresh Laravel project](#end-to-end-a-fresh-laravel-project)
- [`config/cache.php` snippet](#configcachephp-snippet)
- [Setting it as the default cache driver](#setting-it-as-the-default-cache-driver)
- [Common usage patterns](#common-usage-patterns)
- [Verifying the connection is live](#verifying-the-connection-is-live)
- [Supported behavior and limitations](#supported-behavior-and-limitations)
- [Testing without ePHPm](#testing-without-ephpm)
- [Troubleshooting](#troubleshooting)
- [How it works](#how-it-works)
- [License](#license)

---

## Requirements

- **PHP 8.2+**
- **Laravel 10.x, 11.x, or 12.x** (`illuminate/contracts` and
  `illuminate/support` constraints are `^10.0 || ^11.0 || ^12.0`).
- **The ePHPm runtime** — the global `ephpm_kv_*` SAPI functions are
  registered by ePHPm's embedded PHP. If you're running your code
  under PHP-FPM, Apache mod_php, or the stock PHP CLI, those functions
  don't exist and `SapiKvOps::__construct()` throws on instantiation.
  For development without ePHPm running, see
  [Testing without ePHPm](#testing-without-ephpm).

You can confirm the SAPI is present from any PHP file with:

```php
var_dump(function_exists('ephpm_kv_get'));   // expect bool(true)
```

If you get `false`, you're not running inside ePHPm and the store will
refuse to construct.

---

## Install

```bash
composer require ephpm/cache-laravel
```

Laravel package discovery picks up `EphpmCacheServiceProvider`
automatically — there is **no `config/app.php` edit required**. The
provider calls `Cache::extend('ephpm', …)` during boot, and the rest
is configuration in `config/cache.php`.

---

## End-to-end: a fresh Laravel project

The shortest possible setup, from `composer create-project` to a live
cache hit served from in-process memory.

### 1. Create the project

```bash
composer create-project laravel/laravel my-app
cd my-app
composer require ephpm/cache-laravel
```

### 2. Add an `ephpm` store to `config/cache.php`

```php
'stores' => [
    // … other stores …

    'ephpm' => [
        'driver' => 'ephpm',
        'prefix' => 'cache',
    ],
],
```

### 3. Make it the default cache driver

In `.env`:

```dotenv
CACHE_DRIVER=ephpm
```

(Laravel 11+ uses `CACHE_STORE` — both names point at the same
config; check your `config/cache.php` for the env() call to be sure.)

### 4. `ephpm.toml`

Point ePHPm at the docroot:

```toml
[server]
listen = "127.0.0.1:8080"
document_root = "./public"
```

### 5. Run it

```bash
ephpm serve --config ephpm.toml
```

Then `curl http://127.0.0.1:8080/` and any `Cache::*` call now goes
through ePHPm's in-process KV store. No Redis, no Memcached, no
external daemon, no socket round-trip.

---

## `config/cache.php` snippet

Add this entry to the `stores` array:

```php
'stores' => [
    'ephpm' => [
        'driver' => 'ephpm',
        'prefix' => 'cache',
    ],
],
```

The `prefix` is prepended to every key passed to the store — useful
for isolating environments or for the "bump the prefix to flush"
pattern (see [Limitations](#supported-behavior-and-limitations)).

---

## Setting it as the default cache driver

Either set the env var:

```dotenv
CACHE_DRIVER=ephpm
```

Or hard-code it in `config/cache.php`:

```php
'default' => env('CACHE_DRIVER', 'ephpm'),
```

After that, the `Cache` facade with no `store()` call resolves to
the ephpm driver:

```php
Cache::put('key', 'value', 60);
Cache::get('key');
```

---

## Common usage patterns

### Basic put / get / forget

```php
use Illuminate\Support\Facades\Cache;

Cache::put('user:42:name', 'Alice', now()->addHour());
Cache::get('user:42:name');     // 'Alice'
Cache::forget('user:42:name');
```

### `remember` (the idiom most apps actually use)

```php
$user = Cache::remember('user:42', 60, function () use ($id) {
    return User::find($id);
});
```

`remember` is implemented by Laravel's `Repository` on top of `get` +
`put`, so it works with this store unchanged.

### Atomic counters

```php
Cache::increment('hits:home');         // 1
Cache::increment('hits:home', 5);      // 6
Cache::decrement('hits:home', 2);      // 4
```

The counter ops use the SAPI's atomic `ephpm_kv_incr_by` — no
read-modify-write race even under concurrent requests.

### Multi-store apps

```php
// Use ephpm explicitly even if it isn't the default
Cache::store('ephpm')->put('foo', 'bar', 60);
Cache::store('redis')->put('queued', $payload, 30);
```

### Sessions backed by cache

In `.env`:

```dotenv
SESSION_DRIVER=cache
CACHE_DRIVER=ephpm
```

Laravel's `cache` session driver writes through whatever the default
cache store is, so sessions land in ePHPm's KV without any extra
config.

### Rate limiting

```php
use Illuminate\Support\Facades\RateLimiter;

RateLimiter::attempt(
    'send-message:' . $user->id,
    5,
    fn () => $this->sendMessage(),
    60,
);
```

`RateLimiter` is built on top of the cache, so it works through this
store automatically — including the throttle middleware that protects
your routes.

---

## Verifying the connection is live

A two-line health check from `php artisan tinker`:

```php
Cache::store('ephpm')->put('hello', 'world', 5);
Cache::store('ephpm')->get('hello');     // 'world'
```

If this round-trips successfully you've confirmed:

- ePHPm's SAPI is loaded (`ephpm_kv_*` functions exist)
- The KV store is up
- TTL parsing works
- The driver is registered and Laravel is routing through it

---

## Supported behavior and limitations

### Supported

| Cache method                        | Backed by ePHPm KV |
|-------------------------------------|--------------------|
| `Cache::get`, `Cache::put`          | yes                |
| `Cache::remember`, `Cache::rememberForever` | yes        |
| `Cache::forever`                    | yes                |
| `Cache::forget`                     | yes                |
| `Cache::increment`, `Cache::decrement` | yes (atomic)    |
| `Cache::many`, `Cache::putMany`     | yes (loops `get`/`put`) |
| `Cache::has`, `Cache::missing`      | yes                |
| `Cache::add`                        | yes (via `Repository`) |
| `RateLimiter` / throttle middleware | yes                |
| Session driver = `cache` (with this store as default) | yes  |

### Limitations

- **`Cache::flush()` returns `false`.** The SAPI doesn't expose key
  enumeration, so we can't drop all entries. The recommended pattern
  is to bump the `prefix` in `config/cache.php` and let the old keys
  age out via TTL — or to track invalidation explicitly with versioned
  keys (`'user:' . $id . ':v' . $version`).

- **No tags.** `Cache::tags(['posts', 'comments'])->…` will throw
  `BadMethodCallException` because `EphpmStore` does not implement
  `Illuminate\Contracts\Cache\TaggableStore`. Apps that need tag
  invalidation should keep a Redis store available for tagged caches
  and use `Cache::store('redis')->tags(...)` for those call sites.

- **TTL minimum is 1 second.** Laravel's contract treats
  `seconds = 0` as "expire immediately", which we floor to 1 second
  to match `RedisStore`'s behavior. Use `Cache::forever()` for
  persistent keys.

- **In-process state means restart loses cache.** This is a design
  choice — ePHPm's KV is an embedded DashMap, not a durable store.
  Treat it as a tier-1 cache. See the
  [ePHPm KV docs](https://ephpm.dev/architecture/kv-store/) for the
  durability/replication story.

---

## Testing without ePHPm

`EphpmStore` takes an optional `KvOpsInterface`, so you can swap in a
fake backend that runs anywhere — including standard PHPUnit suites
on plain `php-cli`:

```php
use Ephpm\Cache\Laravel\EphpmStore;
use Ephpm\Cache\Laravel\InMemoryKvOps;
use Illuminate\Cache\Repository;

$store = new EphpmStore('', new InMemoryKvOps());
$cache = new Repository($store);

$cache->put('foo', 'bar', 60);
assert($cache->get('foo') === 'bar');
```

`InMemoryKvOps` is for tests only — values live in PHP arrays, there
is no eviction policy, no memory limit, and TTL is best-effort lazy
expiry. Don't use it in production.

---

## Troubleshooting

### `RuntimeException: ephpm KV SAPI functions are not available`

You're not running under the ePHPm runtime. Either run your app
through the `ephpm` binary (production), or wire `EphpmStore` directly
with `InMemoryKvOps` for tests (see above).

### `BadMethodCallException` from `Cache::tags(...)`

Tags aren't supported — see [Limitations](#supported-behavior-and-limitations).
Either drop the tag scoping for that call site or route tagged caches
through a real Redis store (`Cache::store('redis')->tags(...)`).

### `Cache::flush()` returns `false` and the cache isn't cleared

This is by design — see [Limitations](#supported-behavior-and-limitations).
Bump the `prefix` in `config/cache.php` to force a clean namespace,
or rely on TTLs.

### Cache values gone after `ephpm serve` restart

Also by design — the KV store is in-process and not persisted to disk.
If you need durability, compose this driver with a write-through to a
persistent store, or pick a different cache backend for that specific
data.

---

## How it works

ePHPm runs PHP inside the same OS process as the KV store via the
embed SAPI. The store itself is a Rust [`DashMap`](https://docs.rs/dashmap/)
plus TTL management. ePHPm registers a small set of host functions
(`ephpm_kv_get`, `ephpm_kv_set`, `ephpm_kv_incr_by`, `ephpm_kv_expire`,
`ephpm_kv_ttl`, `ephpm_kv_pttl`, `ephpm_kv_del`, `ephpm_kv_exists`)
into PHP's global function table. Calling one is a direct C function
call into Rust — no socket, no protocol parser, no value serialization
beyond what userland code already does.

This package wraps those functions in a Laravel
`Illuminate\Contracts\Cache\Store` and registers it under the `ephpm`
driver name via the auto-discovered `EphpmCacheServiceProvider`. The
rest of Laravel's cache stack — `Repository`, `remember`, the `Cache`
facade, the `RateLimiter`, the throttle middleware, cache-backed
sessions — keeps working unchanged because they all build on the
`Store` contract.

See [ephpm.dev/architecture/kv-store/](https://ephpm.dev/architecture/kv-store/)
for the architecture and [ephpm.dev/guides/kv-from-php/](https://ephpm.dev/guides/kv-from-php/)
for the underlying SAPI surface.

---

## License

MIT — see [LICENSE](LICENSE).
