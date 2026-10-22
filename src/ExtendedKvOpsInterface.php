<?php

declare(strict_types=1);

namespace Ephpm\Cache\Laravel;

/**
 * Optional backend capabilities backed by `ephpm_kv_*` functions newer than
 * the original SAPI surface: multi-key `mget`/`mset` and compare-and-set
 * `set_if_eq` (ePHPm v0.13.0+) and compare-and-delete `del_if_eq`
 * (ePHPm v0.11+).
 *
 * Kept separate from {@see KvOpsInterface} so existing custom backends keep
 * compiling. {@see EphpmStore} and {@see EphpmLock} call a method here only
 * after {@see supports()} confirmed it; otherwise they use their original
 * code path.
 */
interface ExtendedKvOpsInterface extends KvOpsInterface
{
    /**
     * Operation names {@see supports()} may be asked about — each is the
     * suffix of the backing SAPI function (`mget` = `ephpm_kv_mget`).
     */
    public const OPS = ['mget', 'mset', 'set_if_eq', 'del_if_eq'];

    /**
     * Whether the operation named `$op` (one of {@see OPS}) is available.
     */
    public function supports(string $op): bool;

    /**
     * `ephpm_kv_mget`.
     *
     * @param list<string> $keys
     *
     * @return list<string|null>|false values aligned with `$keys` (null =
     *                                 missing); false on failure
     */
    public function mget(array $keys): array|false;

    /**
     * `ephpm_kv_mset`: write every pair with the same TTL (0 = no expiry).
     * On a refusal (memory limit) it stops and returns false; earlier keys
     * may already be stored.
     *
     * @param array<array-key, string> $values key => value
     */
    public function mset(array $values, int $ttlSeconds = 0): bool;

    /**
     * `ephpm_kv_set_if_eq`: replace the value only while it equals
     * `$expected`; never creates the key. `$ttlSeconds` > 0 = new TTL,
     * 0 = no TTL, -1 = keep the current TTL. True if set.
     */
    public function setIfEq(string $key, string $expected, string $value, int $ttlSeconds = 0): bool;

    /**
     * `ephpm_kv_del_if_eq`: delete the key only while its value equals
     * `$expected`, atomically. True if deleted.
     */
    public function delIfEq(string $key, string $expected): bool;
}
