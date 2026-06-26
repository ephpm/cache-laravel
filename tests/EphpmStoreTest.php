<?php

declare(strict_types=1);

namespace Ephpm\Cache\Laravel\Tests;

use Ephpm\Cache\Laravel\EphpmCacheServiceProvider;
use Ephpm\Cache\Laravel\EphpmStore;
use Ephpm\Cache\Laravel\InMemoryKvOps;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EphpmStore::class)]
#[CoversClass(EphpmCacheServiceProvider::class)]
final class EphpmStoreTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [EphpmCacheServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.stores.ephpm', [
            'driver' => 'ephpm',
            'prefix' => '',
        ]);
    }

    public function test_put_and_get_round_trips_a_string(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        self::assertTrue($store->put('greeting', 'hello', 60));
        self::assertSame('hello', $store->get('greeting'));
    }

    public function test_put_and_get_round_trips_an_array(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        $payload = ['a' => 1, 'b' => ['nested' => true]];
        self::assertTrue($store->put('arr', $payload, 60));
        self::assertSame($payload, $store->get('arr'));
    }

    public function test_put_and_get_round_trips_an_object(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        $obj = (object) ['name' => 'Alice', 'tags' => ['admin', 'beta']];
        self::assertTrue($store->put('obj', $obj, 60));
        $back = $store->get('obj');
        self::assertIsObject($back);
        self::assertSame('Alice', $back->name);
        self::assertSame(['admin', 'beta'], $back->tags);
    }

    public function test_int_round_trips_as_int(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        $store->put('n', 42, 60);
        $back = $store->get('n');
        self::assertSame(42, $back);
        self::assertIsInt($back);
    }

    public function test_float_round_trips_as_float(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        $store->put('pi', 3.14, 60);
        $back = $store->get('pi');
        self::assertSame(3.14, $back);
        self::assertIsFloat($back);
    }

    public function test_get_returns_null_for_missing_key(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        self::assertNull($store->get('missing'));
    }

    public function test_put_with_seconds_applies_ttl(): void
    {
        $ops = new InMemoryKvOps();
        $store = new EphpmStore('', $ops);
        $store->put('k', 'v', 30);
        $pttl = $ops->pttl('k');
        self::assertGreaterThan(0, $pttl);
        self::assertLessThanOrEqual(30_000, $pttl);
    }

    public function test_put_with_zero_seconds_floors_to_one_second(): void
    {
        // Laravel's RedisStore floors seconds to a minimum of 1; we mirror
        // that behavior. Use forever() for a no-expiry write.
        $ops = new InMemoryKvOps();
        $store = new EphpmStore('', $ops);
        $store->put('k', 'v', 0);
        $pttl = $ops->pttl('k');
        self::assertGreaterThan(0, $pttl);
        self::assertLessThanOrEqual(1_000, $pttl);
    }

    public function test_forever_sets_without_ttl(): void
    {
        $ops = new InMemoryKvOps();
        $store = new EphpmStore('', $ops);
        $store->forever('k', 'v');
        self::assertSame(-1, $ops->pttl('k'));
        self::assertSame('v', $store->get('k'));
    }

    public function test_forget_returns_true_when_present_false_when_missing(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        $store->put('k', 'v', 60);
        self::assertTrue($store->forget('k'));
        self::assertFalse($store->forget('k'));
    }

    public function test_increment_and_decrement_return_new_int_value(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        self::assertSame(1, $store->increment('hits'));
        self::assertSame(6, $store->increment('hits', 5));
        self::assertSame(4, $store->decrement('hits', 2));
        $back = $store->get('hits');
        self::assertSame(4, $back);
        self::assertIsInt($back);
    }

    public function test_many_returns_array_with_null_for_misses(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        $store->put('a', 'one', 60);
        $store->put('c', 'three', 60);
        $result = $store->many(['a', 'b', 'c']);
        self::assertSame(['a' => 'one', 'b' => null, 'c' => 'three'], $result);
    }

    public function test_put_many_writes_all_keys(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        self::assertTrue($store->putMany(['a' => 1, 'b' => 'two', 'c' => [3]], 60));
        self::assertSame(1, $store->get('a'));
        self::assertSame('two', $store->get('b'));
        self::assertSame([3], $store->get('c'));
    }

    public function test_flush_clears_all_entries(): void
    {
        $store = new EphpmStore('', new InMemoryKvOps());
        $store->put('a', 'one', 60);
        $store->put('b', 'two', 60);
        self::assertTrue($store->flush());
        // Every key is gone — flush now drops the whole store via the SAPI.
        self::assertNull($store->get('a'));
        self::assertNull($store->get('b'));
    }

    public function test_prefix_is_honoured_by_every_method(): void
    {
        $ops = new InMemoryKvOps();
        $store = new EphpmStore('app1:', $ops);

        $store->put('k', 'v', 60);
        // The store serializes non-numeric values before writing, so the
        // raw KV holds the serialized form under the prefixed key. Read it
        // back through the store to compare the logical value.
        self::assertSame('v', $store->get('k'));
        self::assertNotNull($ops->get('app1:k'));
        self::assertNull($ops->get('k'));

        $store->forever('f', 'forever-v');
        self::assertSame('forever-v', $store->get('f'));

        self::assertSame(1, $store->increment('counter'));
        self::assertSame('1', $ops->get('app1:counter'));

        self::assertTrue($store->forget('k'));
        self::assertNull($ops->get('app1:k'));

        self::assertSame('app1:', $store->getPrefix());
    }

    public function test_service_provider_registers_ephpm_driver(): void
    {
        if (!\function_exists('ephpm_kv_get')) {
            // SapiKvOps refuses to construct without the SAPI present, so
            // resolving the store from the container must throw. That this
            // throws (rather than "driver not found") proves the closure
            // was registered and reached SapiKvOps's constructor guard.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('ephpm KV SAPI functions are not available');
            $this->app->make('cache')->store('ephpm');
            return;
        }

        $repo = Cache::store('ephpm');
        self::assertInstanceOf(Repository::class, $repo);
        self::assertInstanceOf(EphpmStore::class, $repo->getStore());
    }
}
