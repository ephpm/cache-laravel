<?php

declare(strict_types=1);

namespace Ephpm\Cache\Laravel;

/**
 * Backend that calls the global `ephpm_kv_*` functions registered by
 * the ePHPm SAPI. Refuses to construct if those functions aren't present
 * so we fail fast outside the runtime instead of producing
 * "Call to undefined function" errors at request time.
 *
 * The newer functions ({@see ExtendedKvOpsInterface}) are feature-detected
 * one by one with `function_exists()` in {@see supports()}, so the store
 * keeps working on older ePHPm runtimes.
 */
final class SapiKvOps implements ExtendedKvOpsInterface
{
    public function __construct()
    {
        if (!\function_exists('ephpm_kv_get')) {
            throw new \RuntimeException(
                'ephpm KV SAPI functions are not available. '
                . 'This store only works inside the ePHPm runtime; '
                . 'use Ephpm\\Cache\\Laravel\\InMemoryKvOps in tests.'
            );
        }
    }

    public function get(string $key): ?string
    {
        /** @var string|null */
        return \ephpm_kv_get($key);
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        return (bool) \ephpm_kv_set($key, $value, $ttlSeconds);
    }

    public function setnx(string $key, string $value, int $ttlSeconds = 0): bool
    {
        return (bool) \ephpm_kv_setnx($key, $value, $ttlSeconds);
    }

    public function del(string $key): int
    {
        return (int) \ephpm_kv_del($key);
    }

    public function exists(string $key): bool
    {
        return (bool) \ephpm_kv_exists($key);
    }

    public function incrBy(string $key, int $delta): int
    {
        // ephpm_kv_incr_by returns int on success, or **false** when the
        // stored value is not an integer. A naive `(int) $result` would cast
        // that false to 0 and silently swallow the error — see the interface
        // contract, which documents this method as throwing. Capture the raw
        // result and distinguish false explicitly.
        $result = \ephpm_kv_incr_by($key, $delta);
        if ($result === false) {
            throw new \RuntimeException(
                "value at key '{$key}' is not an integer and cannot be incremented"
            );
        }
        return (int) $result;
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        return (bool) \ephpm_kv_expire($key, $ttlSeconds);
    }

    public function ttl(string $key): int
    {
        return (int) \ephpm_kv_ttl($key);
    }

    public function pttl(string $key): int
    {
        return (int) \ephpm_kv_pttl($key);
    }

    public function flush(): bool
    {
        if (!\function_exists('ephpm_kv_flush_all')) {
            return false;
        }
        return (bool) \ephpm_kv_flush_all();
    }

    // ── newer functions: call only after supports() returned true ───────────

    public function supports(string $op): bool
    {
        return \in_array($op, self::OPS, true) && \function_exists('ephpm_kv_' . $op);
    }

    public function mget(array $keys): array|false
    {
        /** @var list<string|null>|false */
        return \ephpm_kv_mget($keys);
    }

    public function mset(array $values, int $ttlSeconds = 0): bool
    {
        return (bool) \ephpm_kv_mset($values, $ttlSeconds);
    }

    public function setIfEq(string $key, string $expected, string $value, int $ttlSeconds = 0): bool
    {
        return (bool) \ephpm_kv_set_if_eq($key, $expected, $value, $ttlSeconds);
    }

    public function delIfEq(string $key, string $expected): bool
    {
        return (bool) \ephpm_kv_del_if_eq($key, $expected);
    }
}
