# Redis Mutex [![Build Status](https://travis-ci.org/kelunik/redis-mutex.svg?branch=master)](https://travis-ci.org/kelunik/redis-mutex)

Distributed mutual exclusion built upon the [Amp concurrency framework](https://github.com/amphp/amp) and [Redis](http://redis.io).

## Basic Example

```php
$mutex = new Mutex(...);

// ...

try {
    $token = uniqid("", true);
    yield $mutex->lock($sessionId, $token);

    // Code here will only be executed in one client at a time.
    // If it takes longer than your specified TTL, you have to
    // renew the lock, see next example.

    yield $mutex->unlock($sessionId, $token);
} catch (MutexException $e) {
    // ...
}
```

## Renew Example

```php
$mutex = new Mutex(...);
$locks = [];

$reactor->repeat(function () use ($mutex, $locks) {
    foreach ($locks as $id => $token) {
        $mutex->renew($id, $token);
    }
}, 1000);

// ...

try {
    $token = uniqid("", true);
    yield $mutex->lock($sessionId, $token);
    $locks[$sessionId] = $token;

    // Code here will only be executed in one client at a time.
    // Your lock will automatically be renewed by the reactor
    // repeat above. Don't do blocking things here (you should never
    // do that with Amp anyway), otherwise the reactor will not
    // be able to schedule the renewal.

    unset($locks[$sessionId]);
    yield $mutex->unlock($sessionId, $token);
} catch (MutexException $e) {
    // ...
}
```