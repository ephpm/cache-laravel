<?php

declare(strict_types=1);

namespace Ephpm\Cache\Laravel;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;

/**
 * Laravel cache {@see Store} implementation backed by ePHPm's in-process
 * KV store. All operations route through the {@see KvOpsInterface}
 * abstraction so tests can swap in the {@see InMemoryKvOps} backend
 * without the SAPI present.
 *
 * Serialization mirrors Laravel's RedisStore: numeric values are stored
 * as their string representation so atomic counter ops keep working,
 * everything else is PHP-serialized.
 *
 * Implements {@see LockProvider}, so `Cache::store('ephpm')->lock(...)`,
 * `Cache::lock(...)`, and features built on atomic locks (job overlap
 * prevention, scheduled-task de-duplication) work. Acquisition is atomic
 * via `ephpm_kv_setnx`; release is atomic via `ephpm_kv_del_if_eq` where the
 * runtime has it and best-effort otherwise — see {@see EphpmLock}.
 *
 * On ePHPm v0.13+ {@see many()} and {@see putMany()} use the multi-key
 * `ephpm_kv_mget` / `ephpm_kv_mset` (feature-detected; older runtimes keep
 * the per-key loop).
 */
final class EphpmStore implements LockProvider, Store
{
    private KvOpsInterface $ops;

    public function __construct(
        private string $prefix = '',
        ?KvOpsInterface $ops = null,
    ) {
        $this->ops = $ops ?? new SapiKvOps();
    }

    /**
     * @param string $key
     */
    public function get($key): mixed
    {
        $value = $this->ops->get($this->prefix . $key);
        return $value !== null ? $this->unserialize($value) : null;
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        // ePHPm v0.13+: one ephpm_kv_mget call instead of one get per key.
        if ($this->ops instanceof ExtendedKvOpsInterface && $this->ops->supports('mget')) {
            $keys = \array_values($keys);
            $values = $this->ops->mget(\array_map(fn ($key): string => $this->prefix . $key, $keys));
            if (\is_array($values)) {
                $result = [];
                foreach ($keys as $i => $key) {
                    $value = $values[$i] ?? null;
                    $result[$key] = $value !== null ? $this->unserialize($value) : null;
                }
                return $result;
            }
            // false = the call failed as a whole; fall through so each key
            // gets the per-key behavior it had before.
        }

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        return $result;
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $seconds
     */
    public function put($key, $value, $seconds): bool
    {
        return $this->ops->set(
            $this->prefix . $key,
            $this->serialize($value),
            (int) max(1, $seconds),
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param int                  $seconds
     */
    public function putMany(array $values, $seconds): bool
    {
        if ($values === []) {
            return true;
        }

        // ePHPm v0.13+: one ephpm_kv_mset call; Laravel gives every item the
        // same TTL, which is exactly mset's contract.
        if ($this->ops instanceof ExtendedKvOpsInterface && $this->ops->supports('mset')) {
            $serialized = [];
            foreach ($values as $key => $value) {
                $serialized[$this->prefix . $key] = $this->serialize($value);
            }
            return $this->ops->mset($serialized, (int) max(1, $seconds));
        }

        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->put((string) $key, $value, $seconds) && $ok;
        }
        return $ok;
    }

    /**
     * @param string    $key
     * @param int|float $value
     */
    public function increment($key, $value = 1): int|bool
    {
        return $this->ops->incrBy($this->prefix . $key, (int) $value);
    }

    /**
     * @param string    $key
     * @param int|float $value
     */
    public function decrement($key, $value = 1): int|bool
    {
        return $this->ops->incrBy($this->prefix . $key, -(int) $value);
    }

    /**
     * @param string $key
     * @param mixed  $value
     */
    public function forever($key, $value): bool
    {
        return $this->ops->set($this->prefix . $key, $this->serialize($value), 0);
    }

    /**
     * @param string $key
     */
    public function forget($key): bool
    {
        return $this->ops->del($this->prefix . $key) > 0;
    }

    /**
     * Clear the entire effective KV store via the SAPI's
     * `ephpm_kv_flush_all()` (ePHPm v0.1.2+), making `Cache::flush()` and
     * `php artisan cache:clear` work. This drops *every* key in the store,
     * not just this driver's prefix — matching how Laravel's RedisStore
     * flushes the whole database. On pre-v0.1.2 runtimes the SAPI function
     * is absent and this returns false (the guard in {@see SapiKvOps}); the
     * fallback there is to bump the prefix in config and let old entries
     * age out via TTL.
     */
    public function flush(): bool
    {
        return $this->ops->flush();
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get a lock instance. Atomic acquisition via `ephpm_kv_setnx`; the lock
     * key is prefixed with this store's prefix so locks share the tenant/env
     * namespace with cached values.
     *
     * @param string      $name
     * @param int         $seconds TTL in seconds; 0 means no expiry
     * @param string|null $owner
     */
    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return new EphpmLock($this->ops, $this->prefix . $name, (int) $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier, so a lock acquired
     * in one request can be released in another (Laravel's
     * `Cache::restoreLock(...)` flow).
     *
     * @param string $name
     * @param string $owner
     */
    public function restoreLock($name, $owner): Lock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Mirror Laravel\RedisStore's serialization: numeric values pass
     * through unchanged so counters stay native ints (and increment()
     * keeps working). Everything else goes through PHP serialize().
     */
    private function serialize(mixed $value): string
    {
        return is_numeric($value) && !in_array($value, [INF, -INF], true) && !(is_float($value) && is_nan($value))
            ? (string) $value
            : serialize($value);
    }

    private function unserialize(string $value): mixed
    {
        return is_numeric($value) ? $value + 0 : unserialize($value);
    }
}
