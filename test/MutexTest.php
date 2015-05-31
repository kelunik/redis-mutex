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
    public function setUp() {
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

            yield $mutex->lock("foo1", "123456789", 1000, 2);

            try {
                yield $mutex->lock("foo1", "234567891", 1000, 2);
            } catch (\Exception $e) {
                return;
            } finally {
                yield $mutex->stopAll();
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

            yield $mutex->lock("foo2", "123456789", 1000, 2);

            $pause = new Pause(500, $reactor);
            $pause->when(function() use ($mutex) {
                $mutex->unlock("foo2", "123456789");
            });

            yield $pause;

            yield $mutex->lock("foo2", "234567891", 1000, 2);

            $mutex->stopAll();
        });
    }

    /**
     * @test
     */
    public function renew () {
        (new NativeReactor())->run(function ($reactor) {
            $mutex = new Mutex("tcp://127.0.0.1:6379", [], $reactor);

            yield $mutex->lock("foo3", "123456789", 1000, 2);

            $pause = new Pause(500, $reactor);
            $pause->when(function() use ($mutex) {
                $mutex->renew("foo3", "123456789", 5000);
            });

            yield $pause;

            yield new Pause(3000, $reactor);

            try {
                yield $mutex->lock("foo3", "234567891", 1000, 2);
            } catch (\Exception $e) {
                return;
            } finally {
                yield $mutex->stopAll();
            }

            $this->fail("lock must throw");
        });
    }
}