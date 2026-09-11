<?php

declare(strict_types=1);

namespace Ephpm\Cache\Laravel;

use Illuminate\Cache\Lock;

/**
 * A TTL-bounded, best-effort atomic lock over ePHPm's KV store, modelled
 * directly on Laravel's {@see \Illuminate\Cache\MemcachedLock}.
 *
 * Acquisition is genuinely atomic: {@see acquire()} routes through the SAPI's
 * `ephpm_kv_setnx`, whose insert-or-fail runs under the KV store's per-shard
 * lock, so exactly one concurrent caller can win a contended lock.
 *
 * Release is **best-effort, not fenced**. The ePHPm SAPI has no
 * compare-and-delete / CAS primitive, so {@see release()} cannot atomically
 * verify the owner token and delete in one step — it reads the current owner
 * and deletes if it matches, exactly the non-atomic read-then-delete that
 * `MemcachedLock` performs. Between the read and the delete another process
 * whose lock was acquired after a TTL expiry could, in principle, have its
 * key deleted. In practice this window is the same one Laravel's Memcached
 * driver ships with and is acceptable for the coordination use cases the
 * cache lock is intended for (cron de-duplication, `Cache::lock(...)->get()`,
 * `funcWithoutOverlapping`), **but this lock must not be relied on as a hard
 * mutual-exclusion / fencing primitive**. A fully fenced implementation is
 * gated on a future CAS / compare-and-delete SAPI primitive.
 *
 * Always pair the lock with a sensible TTL (`$seconds`) so a crashed owner
 * that never calls {@see release()} cannot wedge the lock forever — expiry is
 * the backstop.
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
     * Release the lock **only if we still appear to own it**. Best-effort:
     * the owner check and the delete are not atomic (no CAS in the SAPI), so
     * this is the same non-fenced release `MemcachedLock` performs. Returns
     * false when the lock is not owned by the current process.
     */
    public function release(): bool
    {
        if ($this->isOwnedByCurrentProcess()) {
            return $this->ops->del($this->name) > 0;
        }

        return false;
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
