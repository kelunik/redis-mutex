<?php

namespace Amp\Redis;

use Amp\Failure;
use Amp\Promise;
use Amp\Reactor;
use Amp\Success;
use Exception;
use function Amp\pipe;

class Mutex {
    const LOCK = <<<LOCK
if redis.call("llen",KEYS[1]) > 0 and redis.call("ttl",KEYS[1]) >= 0 or redis.call("llen",KEYS[2]) > 0 then
    return 0
else
    redis.call("del",KEYS[1])
    redis.call("lpush",KEYS[1],ARGV[1])
    redis.call("pexpire",KEYS[1],ARGV[2])
    return 1
end
LOCK;


    const UNLOCK = <<<UNLOCK
if redis.call("lindex",KEYS[1],0) == ARGV[1] then
    redis.call("del",KEYS[1])
    return redis.call("lpush",KEYS[2],"")
else
    return 0
end
UNLOCK;

    const RENEW = <<<RENEW
if redis.call("lindex",KEYS[1],0) == ARGV[1] then
    return redis.call("pexpire",KEYS[1],ARGV[1])
else
    return 0
end
RENEW;

    private $uri;
    private $options;
    private $reactor;

    /** @var Client */
    private $std;
    /** @var Client[] */
    private $connections;
    /** @var Client[] */
    private $readyConnections;
    /** @var bool int */
    private $maxConnections;

    public function __construct (string $uri, array $options, Reactor $reactor = null) {
        $this->uri = $uri;
        $this->options = $options;
        $this->reactor = $reactor;

        $this->std = new Client($uri, $options["password"] ?? null, $reactor);
        $this->maxConnections = $options["max_connections"] ?? 0;
        $this->connections = [];
        $this->readyConnections = [];
    }

    protected function getReadyConnection () {
        $connection = array_pop($this->readyConnections);

        if (!$connection) {
            if ($this->maxConnections && count($this->connections) + 1 === $this->maxConnections) {
                throw new Exception;
            }

            $connection = new Client($this->uri, $this->options["password"] ?? null, $this->reactor);
            $this->connections[] = $connection;
        }

        return $connection;
    }

    public function lock ($id, $token, $ttl = 1000, $timeout = 0): Promise {
        return pipe($this->std->eval(self::LOCK, ["lock:{$id}", "queue:{$id}"], [$token, $ttl]), function ($result) use ($id, $ttl, $timeout) {
            if ($result) {
                return true;
            } else {
                $connection = $this->getReadyConnection();
                $promise = $connection->brPoplPush("queue:{$id}", "lock:{$id}", $timeout);
                $promise->when(function () use ($connection) {
                    $this->readyConnections[] = $connection;
                });

                return pipe($promise, function ($result) use ($id, $ttl) {
                    if ($result === null) {
                        return new Failure(new Exception);
                    }

                    return $this->std->expire("lock:{$id}", $ttl, true);
                });
            }
        });
    }

    public function unlock ($id, $token): Promise {
        return $this->std->eval(self::UNLOCK, ["lock:{$id}", "queue:{$id}"], [$token]);
    }

    public function renew ($id, $token, $ttl = 1000): Promise {
        return $this->std->eval(self::RENEW, ["lock:{$id}"], [$token, $ttl]);
    }

    public function stopAll () {
        $promises = [$this->std->close()];

        foreach ($this->connections as $connection) {
            $promises[] = $connection->close();
        }

        return \Amp\any($promises);
    }
}