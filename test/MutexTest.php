<?php

namespace Amp\Redis;

use Amp\NativeReactor;
use Amp\Pause;
use PHPUnit_Framework_TestCase;
use function Amp\stop;

class MutexTest extends PHPUnit_Framework_TestCase {
    /**
     * @before
     */
    public function setUp () {
        (new NativeReactor())->run(function ($reactor) {
            $client = new Client("tcp://127.0.0.1:6379", null, $reactor);
            yield $client->flushAll();
            yield $client->close();
        });
    }

    /**
     * @test
     */
    public function timeout () {
        (new NativeReactor())->run(function ($reactor) {
            $mutex = new Mutex("tcp://127.0.0.1:6379", [], $reactor);

            yield $mutex->lock("foo1", "123456789");

            try {
                yield $mutex->lock("foo1", "234567891");
            } catch (\Exception $e) {
                return;
            } finally {
                $mutex->shutdown();
            }

            $this->fail("lock must throw");
        });
    }

    /**
     * @test
     */
    public function free () {
        (new NativeReactor())->run(function ($reactor) {
            $mutex = new Mutex("tcp://127.0.0.1:6379", [], $reactor);

            yield $mutex->lock("foo2", "123456789");

            $pause = new Pause(500, $reactor);
            $pause->when(function () use ($mutex) {
                $mutex->unlock("foo2", "123456789");
            });

            yield $pause;

            yield $mutex->lock("foo2", "234567891");

            $mutex->shutdown();
        });
    }

    /**
     * @test
     */
    public function renew () {
        (new NativeReactor())->run(function ($reactor) {
            $mutex = new Mutex("tcp://127.0.0.1:6379", [], $reactor);

            yield $mutex->lock("foo3", "123456789");

            for ($i = 0; $i < 5; $i++) {
                $pause = new Pause(500, $reactor);
                $pause->when(function () use ($mutex) {
                    $mutex->renew("foo3", "123456789");
                });

                yield $pause;
            }

            try {
                yield $mutex->lock("foo3", "234567891");
            } catch (\Exception $e) {
                return;
            } finally {
                $mutex->shutdown();
            }

            $this->fail("lock must throw");
        });
    }
}