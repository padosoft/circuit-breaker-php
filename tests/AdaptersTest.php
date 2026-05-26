<?php declare(strict_types=1);

use LeoCarmo\CircuitBreaker\Adapters\AdapterInterface;
use LeoCarmo\CircuitBreaker\Adapters\RedisAdapter;
use LeoCarmo\CircuitBreaker\Adapters\RedisClusterAdapter;
use LeoCarmo\CircuitBreaker\Adapters\SwooleTableAdapter;
use LeoCarmo\CircuitBreaker\CircuitBreaker;
use PHPUnit\Framework\TestCase;

class AdaptersTest extends TestCase
{
    public function testCreateRedisAdapter()
    {
        $adapter = $this->createRedisAdapter();

        $this->assertInstanceOf(AdapterInterface::class, $adapter);

        return $adapter;
    }

    public function testCreateRedisClusterAdapter()
    {
        $adapter = $this->createRedisClusterAdapter();

        $this->assertInstanceOf(AdapterInterface::class, $adapter);

        return $adapter;
    }

    public function testCreateSwooleTableAdapter()
    {
        $adapter = $this->createSwooleTableAdapter();
        $this->assertInstanceOf(AdapterInterface::class, $adapter);
        return $adapter;
    }

    public function provideAdapters()
    {
        return [
            'redis' => ['redis'],
            'redis-cluster' => ['redis-cluster'],
            'swoole-table' => ['swoole-table'],
        ];
    }

    /**
     * @dataProvider provideAdapters
     */
    public function testSetAdapter(string $adapterName)
    {
        $adapter = $this->createAdapter($adapterName);
        $circuitBreaker = new CircuitBreaker($adapter, 'testSetAdapter');

        $this->assertInstanceOf(AdapterInterface::class, $circuitBreaker->getAdapter());
    }

    /**
     * @dataProvider provideAdapters
     */
    public function testOpenCircuit(string $adapterName)
    {
        $adapter = $this->createAdapter($adapterName);
        $circuitBreaker = new CircuitBreaker($adapter, 'testOpenCircuit');

        $circuitBreaker->setSettings([
            'timeWindow' => 20,
            'failureRateThreshold' => 5,
            'intervalToHalfOpen' => 10,
        ]);

        $circuitBreaker->failure();
        $circuitBreaker->failure();
        $circuitBreaker->failure();
        $circuitBreaker->failure();
        $circuitBreaker->failure();

        $this->assertFalse($circuitBreaker->isAvailable());
    }

    /**
     * @dataProvider provideAdapters
     */
    public function testReachFailureRateAfterTimeWindow(string $adapterName)
    {
        $adapter = $this->createAdapter($adapterName);
        $circuitBreaker = new CircuitBreaker($adapter, 'testReachFailureRateAfterTimeWindow');

        $circuitBreaker->setSettings([
            'timeWindow' => 2,
            'failureRateThreshold' => 2,
            'intervalToHalfOpen' => 10,
        ]);

        $circuitBreaker->failure();
        $circuitBreaker->failure();
        $circuitBreaker->failure();

        sleep(3);

        $this->assertTrue($circuitBreaker->isAvailable());
    }

    /**
     * @dataProvider provideAdapters
     */
    public function testCloseCircuitSuccess(string $adapterName)
    {
        $adapter = $this->createAdapter($adapterName);
        $circuitBreaker = new CircuitBreaker($adapter, 'testCloseCircuitSuccess');

        $circuitBreaker->setSettings([
            'timeWindow' => 20,
            'failureRateThreshold' => 1,
            'intervalToHalfOpen' => 10,
        ]);

        $circuitBreaker->failure();
        $circuitBreaker->failure();

        $this->assertFalse($circuitBreaker->isAvailable());

        $circuitBreaker->success();

        $this->assertTrue($circuitBreaker->isAvailable());
    }

    /**
     * @dataProvider provideAdapters
     */
    public function testHalfOpenFailAndOpenCircuit(string $adapterName)
    {
        $adapter = $this->createAdapter($adapterName);
        $circuitBreaker = new CircuitBreaker($adapter, 'testHalfOpenFailAndOpenCircuit');

        $circuitBreaker->setSettings([
            'timeWindow' => 1,
            'failureRateThreshold' => 3,
            'intervalToHalfOpen' => 15,
        ]);

        $circuitBreaker->failure();
        $circuitBreaker->failure();
        $circuitBreaker->failure();

        // Check if is available for open circuit
        $this->assertFalse($circuitBreaker->isAvailable());

        // Sleep for half open
        sleep(2);

        // Register new failure
        $circuitBreaker->failure();

        // Check if is open
        $this->assertFalse($circuitBreaker->isAvailable());
    }

    private function createAdapter(string $adapterName): AdapterInterface
    {
        if ($adapterName === 'redis') {
            return $this->createRedisAdapter();
        }

        if ($adapterName === 'redis-cluster') {
            return $this->createRedisClusterAdapter();
        }

        return $this->createSwooleTableAdapter();
    }

    private function createRedisAdapter(): RedisAdapter
    {
        return new RedisAdapter($this->createRedisConnection(), 'my-product');
    }

    private function createRedisClusterAdapter(): RedisClusterAdapter
    {
        return new RedisClusterAdapter($this->createRedisConnection(), 'my-product');
    }

    private function createRedisConnection(): \Redis
    {
        if (! extension_loaded('redis')) {
            $this->markTestSkipped('Extension redis is required for Redis adapter integration tests.');
        }

        $host = getenv('REDIS_HOST') ?: null;
        if ($host === null) {
            $this->markTestSkipped('REDIS_HOST is required for Redis adapter integration tests.');
        }

        $redis = new \Redis();
        $redis->connect($host, (int) (getenv('REDIS_PORT') ?: 6379));

        return $redis;
    }

    private function createSwooleTableAdapter(): SwooleTableAdapter
    {
        if (! extension_loaded('swoole')) {
            $this->markTestSkipped('Extension swoole is required for Swoole Table adapter integration tests.');
        }

        return new SwooleTableAdapter();
    }
}
