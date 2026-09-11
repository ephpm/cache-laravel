<?php

declare(strict_types=1);

namespace Ephpm\Cache\Laravel\Tests;

use Ephpm\Cache\Laravel\EphpmLock;
use Ephpm\Cache\Laravel\EphpmStore;
use Ephpm\Cache\Laravel\InMemoryKvOps;
use Illuminate\Contracts\Cache\Lock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EphpmLock::class)]
#[CoversClass(EphpmStore::class)]
final class EphpmLockTest extends TestCase
{
    public function test_store_lock_returns_a_lock_contract(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        self::assertInstanceOf(Lock::class, $store->lock('job', 10));
    }

    public function test_acquire_succeeds_then_blocks_a_second_acquirer(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        $held = $store->lock('job', 10, 'owner-1');
        $rival = $store->lock('job', 10, 'owner-2');

        self::assertTrue($held->acquire());
        // The lock is held atomically — a second acquirer cannot take it.
        self::assertFalse($rival->acquire());
    }

    public function test_release_frees_the_lock_for_the_next_acquirer(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        $held = $store->lock('job', 10, 'owner-1');
        $rival = $store->lock('job', 10, 'owner-2');

        self::assertTrue($held->acquire());
        self::assertFalse($rival->acquire());

        self::assertTrue($held->release());
        // Freed — the rival can now take it.
        self::assertTrue($rival->acquire());
    }

    public function test_release_by_a_non_owner_does_not_free_the_lock(): void
    {
        $ops = new InMemoryKvOps();
        $store = new EphpmStore('', $ops);

        self::assertTrue($store->lock('job', 10, 'owner-1')->acquire());

        // A different owner's release() is a no-op (best-effort ownership
        // check) — the lock stays held.
        $notOwner = $store->lock('job', 10, 'owner-2');
        self::assertFalse($notOwner->release());
        self::assertTrue($ops->exists('job'));
    }

    public function test_force_release_frees_regardless_of_owner(): void
    {
        $ops = new InMemoryKvOps();
        $store = new EphpmStore('', $ops);

        self::assertTrue($store->lock('job', 10, 'owner-1')->acquire());

        // forceRelease() deletes the key without an owner check.
        $store->lock('job', 10, 'someone-else')->forceRelease();
        self::assertFalse($ops->exists('job'));
    }

    public function test_restore_lock_can_release_a_lock_taken_elsewhere(): void
    {
        $ops = new InMemoryKvOps();
        $store = new EphpmStore('', $ops);

        // Acquire in one "request"...
        self::assertTrue($store->lock('job', 10, 'token-abc')->acquire());

        // ...and release in another via the owner token.
        $restored = $store->restoreLock('job', 'token-abc');
        self::assertTrue($restored->release());
        self::assertFalse($ops->exists('job'));
    }

    public function test_ttl_expiry_frees_the_lock(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        $held = $store->lock('job', 1, 'owner-1');
        $rival = $store->lock('job', 1, 'owner-2');

        self::assertTrue($held->acquire());
        self::assertFalse($rival->acquire());

        // Backstop for a crashed owner: the TTL lapses and the lock reopens.
        \usleep(1_100_000);
        self::assertTrue($rival->acquire());
    }

    public function test_zero_seconds_acquires_without_expiry(): void
    {
        $ops = new InMemoryKvOps();
        $store = new EphpmStore('', $ops);

        self::assertTrue($store->lock('job', 0, 'owner-1')->acquire());
        // 0 seconds => no TTL on the underlying key.
        self::assertSame(-1, $ops->pttl('job'));
    }

    public function test_lock_key_is_prefixed_with_the_store_prefix(): void
    {
        $ops = new InMemoryKvOps();
        $store = new EphpmStore('app1:', $ops);

        self::assertTrue($store->lock('job', 10, 'owner-1')->acquire());
        self::assertTrue($ops->exists('app1:job'));
        self::assertFalse($ops->exists('job'));
    }
}
