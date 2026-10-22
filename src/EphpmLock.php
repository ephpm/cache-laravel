<?php

declare(strict_types=1);

namespace Ephpm\Cache\Laravel;

use Illuminate\Cache\Lock;

/**
 * A TTL-bounded atomic lock over ePHPm's KV store, modelled on Laravel's
 * {@see \Illuminate\Cache\RedisLock} (and, on older runtimes, its
 * {@see \Illuminate\Cache\MemcachedLock}).
 *
 * Acquisition is atomic: {@see acquire()} routes through the SAPI's
 * `ephpm_kv_setnx`, whose insert-or-fail runs under the KV store's per-shard
 * lock, so exactly one concurrent caller can win a contended lock.
 *
 * Release is **atomic on ePHPm v0.11+**: {@see release()} uses
 * `ephpm_kv_del_if_eq`, which deletes the key only while it still holds this
 * lock's owner token, in one step. On runtimes without that function it falls
 * back to the original best-effort release — read the current owner, delete
 * if it matches — the same non-atomic read-then-delete `MemcachedLock`
 * performs, where a lock re-acquired by someone else after a TTL expiry could
 * in principle be deleted in between.
 *
 * {@see refresh()} (Laravel 12's `Lock::refresh()`) is supported on ePHPm
 * v0.13+ via the compare-and-set `ephpm_kv_set_if_eq`; it extends the TTL only
 * while this owner still holds the lock. Older runtimes throw, as Laravel's
 * base lock does for drivers without refresh support.
 *
 * Even with the atomic release, this is a TTL lock, not a fencing primitive:
 * an owner that outlives its TTL has lost the lock. Always pair the lock with
 * a sensible TTL (`$seconds`) so a crashed owner that never calls
 * {@see release()} cannot wedge the lock forever — expiry is the backstop.
 */
final class EphpmLock extends Lock
{
    private KvOpsInterface $ops;

    /**
     * @param string      $name    the (already prefixed) lock key
     * @param int         $seconds TTL in seconds; 0 means no expiry
     * @param string|null $owner   owner token; a random one is generated when null
     */
    public function __construct(KvOpsInterface $ops, string $name, int $seconds, ?string $owner = null)
    {
        parent::__construct($name, $seconds, $owner);
        $this->ops = $ops;
    }

    /**
     * Attempt to acquire the lock. Atomic via `ephpm_kv_setnx`: true only when
     * this call inserted the key. A false result means the lock is already
     * held (or the store is out of memory) — either way, not acquired.
     */
    public function acquire(): bool
    {
        return $this->ops->setnx($this->name, $this->owner, $this->seconds);
    }

    /**
     * Release the lock **only if we still own it**. Atomic via
     * `ephpm_kv_del_if_eq` when available; otherwise the best-effort
     * read-then-delete described on the class. Returns false when the lock is
     * not owned by the current process.
     */
    public function release(): bool
    {
        if ($this->ops instanceof ExtendedKvOpsInterface && $this->ops->supports('del_if_eq')) {
            return $this->ops->delIfEq($this->name, $this->owner);
        }

        if ($this->isOwnedByCurrentProcess()) {
            return $this->ops->del($this->name) > 0;
        }

        return false;
    }

    /**
     * Extend the lock's TTL to `$seconds` (default: the TTL it was created
     * with; 0 = no expiry), only while this owner still holds it — the same
     * contract as Laravel's RedisLock::refresh(). Atomic via
     * `ephpm_kv_set_if_eq` (ePHPm v0.13+). Returns false when the lock is not
     * held by this owner.
     *
     * @param int|null $seconds
     *
     * @throws \RuntimeException on ePHPm runtimes without `ephpm_kv_set_if_eq`
     */
    public function refresh($seconds = null): bool
    {
        if (!$this->ops instanceof ExtendedKvOpsInterface || !$this->ops->supports('set_if_eq')) {
            throw new \RuntimeException('This lock driver does not support refreshing locks.');
        }

        $seconds ??= $this->seconds;

        return $this->ops->setIfEq($this->name, $this->owner, $this->owner, \max(0, (int) $seconds));
    }

    /**
     * Delete the lock key regardless of owner. Used by
     * `Lock::forceRelease()` / `Cache::restoreLock(...)->forceRelease()`.
     */
    public function forceRelease(): void
    {
        $this->ops->del($this->name);
    }

    /**
     * The owner token currently stored for this lock, or null when the lock
     * is not held (or has expired).
     */
    protected function getCurrentOwner(): ?string
    {
        return $this->ops->get($this->name);
    }
}
