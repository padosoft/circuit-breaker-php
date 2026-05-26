<?php declare(strict_types=1);

use LeoCarmo\CircuitBreaker\Adapters\RedisAdapter;
use PHPUnit\Framework\TestCase;

class RedisAdapterTest extends TestCase
{
    public function testIncrementFailure()
    {
        $redis = $this->createMock(\Redis::class);

        $adapter = new RedisAdapter($redis, 'test-failure');

        $redis->expects($this->once())
            ->method('ttl');

        $redis->expects($this->never())
            ->method('del');

        $redis->expects($this->once())
            ->method('get')
            ->willReturn(false);

        $redis->expects($this->once())
            ->method('multi');

        $redis->expects($this->once())
            ->method('incr');

        $redis->expects($this->once())
            ->method('expire');

        $redis->expects($this->once())
            ->method('exec');

        $adapter->incrementFailure('test-service', 30);
    }

    public function testIncrementFailureWithKeyWithoutTtl()
    {
        $redis = $this->createMock(\Redis::class);

        $adapter = new RedisAdapter($redis, 'test-failure');

        $redis->expects($this->once())
            ->method('ttl')
            ->willReturn(-1);

        $redis->expects($this->once())
            ->method('del');

        $redis->expects($this->once())
            ->method('get')
            ->willReturn(false);

        $redis->expects($this->once())
            ->method('multi');

        $redis->expects($this->once())
            ->method('incr');

        $redis->expects($this->once())
            ->method('expire');

        $redis->expects($this->once())
            ->method('exec');

        $adapter->incrementFailure('test-service', 30);
    }

    public function testIncrementFailureWithKeyWithTtl()
    {
        $redis = $this->createMock(\Redis::class);

        $adapter = new RedisAdapter($redis, 'test-failure');

        $redis->expects($this->once())
            ->method('ttl')
            ->willReturn(20);

        $redis->expects($this->never())
            ->method('del');

        $redis->expects($this->once())
            ->method('get')
            ->willReturn(true);

        $redis->expects($this->never())
            ->method('multi');

        $redis->expects($this->never())
            ->method('expire');

        $redis->expects($this->never())
            ->method('exec');

        $redis->expects($this->once())
            ->method('incr');

        $adapter->incrementFailure('test-service', 30);
    }

    public function testIncrementFailureWithKeyWithoutTtlIntegratedRedis()
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('Extension redis is required for Redis integration tests.');
        }

        $host = getenv('REDIS_HOST') ?: null;
        if ($host === null) {
            $this->markTestSkipped('REDIS_HOST is required for Redis integration tests.');
        }

        $redis = new \Redis();
        $redis->connect($host, (int) (getenv('REDIS_PORT') ?: 6379));

        $dummy_key = 'circuit-breaker:test-failure:test-service:failures';

        // set dummy key without expire
        $redis->set($dummy_key, 1);
        $ttl = $redis->ttl($dummy_key);

        $this->assertEquals(-1, $ttl, 'Dummy key without ttl');

        $adapter = new RedisAdapter($redis, 'test-failure');

        // here, we expect that the dummy key will be removed and a new with ttl will be set
        $adapter->incrementFailure('test-service', 30);

        $ttl = $redis->ttl($dummy_key);

        $this->assertNotEquals(-1, $ttl, 'Dummy key with ttl');
        $this->assertGreaterThan(1, $ttl, 'Dummy key more than 1s ttl');
    }
}
