<?php

namespace Amp\Redis;

use Amp\Failure;
use Amp\Promise;
use Amp\Reactor;
use Exception;
use function Amp\pipe;

class Mutex {
    const LOCK = <<<LOCK
if redis.call("llen",KEYS[1]) > 0 and redis.call("ttl",KEYS[1]) >= 0 then
    return redis.call("lindex",KEYS[1],0) == ARGV[1]
elseif redis.call("ttl",KEYS[1]) == -1 then
    redis.call("pexpire",KEYS[1],ARGV[2])
    return 0
else
    redis.call("del",KEYS[1])
    redis.call("lpush",KEYS[1],ARGV[1])
    redis.call("pexpire",KEYS[1],ARGV[2])
    return 1
end
LOCK;

    const TOKEN = <<<TOKEN
if redis.call("lindex",KEYS[1],0) == "%" then
    redis.call("del",KEYS[1])
    redis.call("lpush",KEYS[1],ARGV[1])
    redis.call("pexpire",KEYS[1],ARGV[2])
    return 1
else
    return {err="Redis lock error"}
end
TOKEN;

    const UNLOCK = <<<UNLOCK
if redis.call("lindex",KEYS[1],0) == ARGV[1] then
    redis.call("del",KEYS[1])
    redis.call("lpush",KEYS[2],"%")
    redis.call("pexpire",KEYS[2],ARGV[2])
    return redis.call("llen",KEYS[2])
end
UNLOCK;

    const RENEW = <<<RENEW
if redis.call("lindex",KEYS[1],0) == ARGV[1] then
    return redis.call("pexpire",KEYS[1],ARGV[2])
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
    /** @var int */
    private $maxConnections;
    /** @var int */
    private $ttl;
    /** @var int */
    private $timeout;

    public function __construct (string $uri, array $options, Reactor $reactor = null) {
        $this->uri = $uri;
        $this->options = $options;
        $this->reactor = $reactor;

        $this->std = new Client($uri, $options["password"] ?? null, $reactor);
        $this->maxConnections = $options["max_connections"] ?? 0;
        $this->ttl = $options["ttl"] ?? 1000;
        $this->timeout = (int) ($options["timeout"] / 1000 ?? 1);
        $this->readyConnections = [];
        $this->connections = [];
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

    public function lock ($id, $token): Promise {
        return pipe($this->std->eval(self::LOCK, ["lock:{$id}", "queue:{$id}"], [$token, $this->ttl]), function ($result) use ($id, $token) {
            if ($result) {
                return true;
            } else {
                return pipe($this->std->expire("queue:{$id}", $this->timeout * 2, true), function () use ($id, $token) {
                    $connection = $this->getReadyConnection();
                    $promise = $connection->brPoplPush("queue:{$id}", "lock:{$id}", $this->timeout);
                    $promise->when(function () use ($connection) {
                        $this->readyConnections[] = $connection;
                    });

                    return pipe($promise, function ($result) use ($id, $token) {
                        if ($result === null) {
                            return new Failure(new Exception);
                        }

                        return $this->std->eval(self::TOKEN, ["lock:{$id}"], [$token, $this->ttl]);
                    });
                });
            }
        });
    }

    public function unlock ($id, $token): Promise {
        return $this->std->eval(self::UNLOCK, ["lock:{$id}", "queue:{$id}"], [$token, 2 * $this->timeout]);
    }

    public function renew ($id, $token): Promise {
        return $this->std->eval(self::RENEW, ["lock:{$id}"], [$token, $this->ttl]);
    }

    public function stopAll () {
        $promises = [$this->std->close()];

        foreach ($this->connections as $connection) {
            $promises[] = $connection->close();
        }

        return \Amp\any($promises);
    }

    public function getTTL () {
        return $this->ttl;
    }

    public function getTimeout () {
        return $this->timeout;
    }
}