<?php

declare(strict_types=1);

namespace Ephpm\Cache\Laravel\Tests;

use Ephpm\Cache\Laravel\EphpmLock;
use Ephpm\Cache\Laravel\EphpmStore;
use Ephpm\Cache\Laravel\InMemoryKvOps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The paths that use the newer `ephpm_kv_*` functions (mget/mset on
 * ePHPm v0.13+, set_if_eq for lock refresh, del_if_eq for release) and their
 * fallbacks when the running ePHPm lacks them.
 */
#[CoversClass(EphpmStore::class)]
#[CoversClass(EphpmLock::class)]
#[CoversClass(InMemoryKvOps::class)]
final class ExtendedOpsTest extends TestCase
{
    // ── many() ───────────────────────────────────────────────────────────────

    public function test_many_uses_one_mget_with_prefixed_keys(): void
    {
        $ops = new RecordingKvOps();
        $store = new EphpmStore('app:', $ops);
        $store->put('a', ['x' => 1], 60);
        $store->put('n', 42, 60);
        $ops->calls = [];

        self::assertSame(['a' => ['x' => 1], 'missing' => null, 'n' => 42], $store->many(['a', 'missing', 'n']));
        self::assertSame(['mget'], $ops->calls);
    }

    public function test_many_falls_back_to_per_key_get_without_mget(): void
    {
        $ops = new RecordingKvOps([]);
        $store = new EphpmStore('', $ops);
        $store->put('a', 'one', 60);
        $ops->calls = [];

        self::assertSame(['a' => 'one', 'b' => null], $store->many(['a', 'b']));
        self::assertSame(['get', 'get'], $ops->calls);
    }

    public function test_many_falls_back_when_mget_fails(): void
    {
        $ops = new RecordingKvOps();
        $store = new EphpmStore('', $ops);
        $store->put('a', 'one', 60);
        $ops->failMulti = true;
        $ops->calls = [];

        self::assertSame(['a' => 'one'], $store->many(['a']));
        self::assertSame(['mget', 'get'], $ops->calls);
    }

    public function test_many_of_nothing_makes_no_call(): void
    {
        $ops = new RecordingKvOps();
        self::assertSame([], (new EphpmStore('', $ops))->many([]));
        self::assertSame([], $ops->calls);
    }

    // ── putMany() ────────────────────────────────────────────────────────────

    public function test_put_many_uses_one_mset_with_the_shared_ttl(): void
    {
        $ops = new RecordingKvOps();
        $store = new EphpmStore('app:', $ops);

        self::assertTrue($store->putMany(['a' => 'one', 'b' => [1, 2], 'n' => 7], 120));
        self::assertSame(['mset'], $ops->calls);
        self::assertSame('one', $store->get('a'));
        self::assertSame([1, 2], $store->get('b'));
        self::assertSame(7, $store->get('n'));
        self::assertSame(120, $ops->inner->ttl('app:a'));
        self::assertSame(120, $ops->inner->ttl('app:n'));
    }

    public function test_put_many_floors_ttl_to_one_second_like_put(): void
    {
        $ops = new RecordingKvOps();
        (new EphpmStore('', $ops))->putMany(['a' => 'x'], 0);
        self::assertSame(1, $ops->inner->ttl('a'));
    }

    public function test_put_many_reports_a_refused_mset(): void
    {
        $ops = new RecordingKvOps();
        $ops->failMulti = true;
        self::assertFalse((new EphpmStore('', $ops))->putMany(['a' => 'x'], 60));
    }

    public function test_put_many_falls_back_to_per_key_put_without_mset(): void
    {
        $ops = new RecordingKvOps([]);
        $store = new EphpmStore('', $ops);

        self::assertTrue($store->putMany(['a' => 'one', 'b' => 'two'], 60));
        self::assertSame(['set', 'set'], $ops->calls);
        self::assertSame('two', $store->get('b'));
    }

    // ── locks ────────────────────────────────────────────────────────────────

    public function test_release_uses_del_if_eq_and_only_frees_its_own_lock(): void
    {
        $ops = new RecordingKvOps();
        $store = new EphpmStore('', $ops);
        $held = $store->lock('job', 10, 'owner-1');
        self::assertTrue($held->acquire());
        $ops->calls = [];

        self::assertFalse($store->restoreLock('job', 'intruder')->release());
        self::assertSame('owner-1', $ops->inner->get('job'));
        self::assertTrue($held->release());
        self::assertNull($ops->inner->get('job'));
        self::assertSame(['del_if_eq', 'del_if_eq'], $ops->calls);
    }

    public function test_release_falls_back_to_read_then_delete_without_del_if_eq(): void
    {
        $ops = new RecordingKvOps([]);
        $store = new EphpmStore('', $ops);
        $held = $store->lock('job', 10, 'owner-1');
        $held->acquire();
        $ops->calls = [];

        self::assertTrue($held->release());
        self::assertSame(['get', 'del'], $ops->calls);
    }

    public function test_refresh_extends_only_the_owners_lock(): void
    {
        $ops = new RecordingKvOps();
        $store = new EphpmStore('', $ops);
        $held = $store->lock('job', 10, 'owner-1');
        $held->acquire();

        self::assertTrue($held->refresh(60));
        self::assertSame(60, $ops->inner->ttl('job'));
        self::assertSame('owner-1', $ops->inner->get('job'));

        // Default: the TTL the lock was created with.
        self::assertTrue($held->refresh());
        self::assertSame(10, $ops->inner->ttl('job'));

        // Someone else's token cannot extend it.
        self::assertFalse($store->restoreLock('job', 'intruder')->refresh(60));
        self::assertSame(10, $ops->inner->ttl('job'));
    }

    public function test_refresh_with_zero_seconds_removes_the_expiry(): void
    {
        $ops = new RecordingKvOps();
        $held = (new EphpmStore('', $ops))->lock('job', 10, 'owner-1');
        $held->acquire();

        self::assertTrue($held->refresh(0));
        self::assertSame(-1, $ops->inner->ttl('job'));
    }

    public function test_refresh_of_a_lock_not_held_returns_false(): void
    {
        $held = (new EphpmStore('', new RecordingKvOps()))->lock('job', 10, 'owner-1');
        self::assertFalse($held->refresh());
    }

    public function test_refresh_without_set_if_eq_throws_like_laravel(): void
    {
        $held = (new EphpmStore('', new RecordingKvOps([])))->lock('job', 10, 'owner-1');
        $held->acquire();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not support refreshing locks');
        $held->refresh();
    }

    // ── a backend implementing only the original interface ───────────────────

    public function test_store_works_with_a_backend_without_the_extended_interface(): void
    {
        $inner = new InMemoryKvOps();
        $legacy = new class ($inner) implements \Ephpm\Cache\Laravel\KvOpsInterface {
            public function __construct(private InMemoryKvOps $i)
            {
            }

            public function get(string $key): ?string
            {
                return $this->i->get($key);
            }

            public function set(string $key, string $value, int $ttlSeconds = 0): bool
            {
                return $this->i->set($key, $value, $ttlSeconds);
            }

            public function setnx(string $key, string $value, int $ttlSeconds = 0): bool
            {
                return $this->i->setnx($key, $value, $ttlSeconds);
            }

            public function del(string $key): int
            {
                return $this->i->del($key);
            }

            public function exists(string $key): bool
            {
                return $this->i->exists($key);
            }

            public function incrBy(string $key, int $delta): int
            {
                return $this->i->incrBy($key, $delta);
            }

            public function expire(string $key, int $ttlSeconds): bool
            {
                return $this->i->expire($key, $ttlSeconds);
            }

            public function ttl(string $key): int
            {
                return $this->i->ttl($key);
            }

            public function pttl(string $key): int
            {
                return $this->i->pttl($key);
            }

            public function flush(): bool
            {
                return $this->i->flush();
            }
        };
        $store = new EphpmStore('', $legacy);

        self::assertTrue($store->putMany(['a' => 1, 'b' => 2], 60));
        self::assertSame(['a' => 1, 'b' => 2], $store->many(['a', 'b']));
        $lock = $store->lock('job', 10, 'me');
        self::assertTrue($lock->acquire());
        self::assertTrue($lock->release());
    }
}
