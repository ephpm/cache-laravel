<?php

declare(strict_types=1);

namespace Ephpm\Cache\Laravel;

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
 */
final class EphpmStore implements Store
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
