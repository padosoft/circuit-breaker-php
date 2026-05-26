<?php declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LeoCarmo\CircuitBreaker\Adapters\AdapterInterface;
use LeoCarmo\CircuitBreaker\CircuitBreaker;
use LeoCarmo\CircuitBreaker\CircuitBreakerException;
use LeoCarmo\CircuitBreaker\GuzzleMiddleware;
use PHPUnit\Framework\TestCase;

class GuzzleMiddlewareTest extends TestCase
{
    public function testSuccessRequest()
    {
        $circuit = new CircuitBreaker($this->createMemoryAdapter(), 'testSuccessRequest');

        // Set the first failure and the failure threshold
        $circuit->setSettings(['failureRateThreshold' => 2]);
        $circuit->failure();

        $handler = new GuzzleMiddleware($circuit);
        $handlers = HandlerStack::create(new MockHandler([
            new Response(200),
        ]));
        $handlers->push($handler);

        $client = new Client(['handler' => $handlers]);
        $response = $client->get('https://example.com');

        // After a success response the failures must be reset and the circuit is available
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($circuit->isAvailable());

        // Set another failure to ensure that the previous failure was reset and a new fail will not open the circuit
        $circuit->failure();
        $this->assertTrue($circuit->isAvailable());
    }

    public function testSuccessRequestWithCustomStatusCode()
    {
        $circuit = new CircuitBreaker($this->createMemoryAdapter(), 'testRequestWithCustomStatusCode');

        // Set the first failure and the failure threshold
        $circuit->setSettings(['failureRateThreshold' => 2]);
        $circuit->failure();

        $handler = new GuzzleMiddleware($circuit);
        $handler->setCustomSuccessCodes([403]);

        $handlers = HandlerStack::create(new MockHandler([
            new Response(403),
        ]));
        $handlers->push($handler);

        $client = new Client(['handler' => $handlers, 'http_errors' => false]);

        // After a success response the failures must be reset and the circuit is available
        $this->assertEquals(1, $circuit->getFailuresCounter());
        $response = $client->get('https://example.com');
        $this->assertEquals(403, $response->getStatusCode());
        $this->assertEquals(0, $circuit->getFailuresCounter());
        $this->assertTrue($circuit->isAvailable());

        // Set another failure to ensure that the previous failure was reset and a new fail will not open the circuit
        $circuit->failure();
        $this->assertTrue($circuit->isAvailable());
    }

    public function testRequestWithIgnoredStatusCode()
    {
        $circuit = new CircuitBreaker($this->createMemoryAdapter(), 'testRequestWithCustomStatusCode');

        // Set the first failure and the failure threshold
        $circuit->setSettings(['failureRateThreshold' => 2]);
        $circuit->failure();

        $handler = new GuzzleMiddleware($circuit);
        $handler->setCustomIgnoreCodes([412]);

        $handlers = HandlerStack::create(new MockHandler([
            new Response(412),
        ]));
        $handlers->push($handler);

        $client = new Client(['handler' => $handlers, 'http_errors' => false]);

        // After an ignored status code, nothing will change on failure counter
        $this->assertEquals(1, $circuit->getFailuresCounter());
        $response = $client->get('https://example.com');
        $this->assertEquals(412, $response->getStatusCode());
        $this->assertEquals(1, $circuit->getFailuresCounter());
        $this->assertTrue($circuit->isAvailable());
    }

    public function testCircuitIsNotAvailable()
    {
        $circuit = new CircuitBreaker($this->createMemoryAdapter(), 'testCircuitIsNotAvailable');

        $circuit->setSettings(['failureRateThreshold' => 2]);
        $circuit->failure();
        $circuit->failure();

        $this->assertEquals(2, $circuit->getFailuresCounter());

        $handler = new GuzzleMiddleware($circuit);
        $handlers = HandlerStack::create(new MockHandler([
            new Response(200),
        ]));
        $handlers->push($handler);

        $client = new Client(['handler' => $handlers]);

        $this->expectException(CircuitBreakerException::class);

        $client->get('https://example.com');
    }

    public function testFailureRequest()
    {
        $circuit = new CircuitBreaker($this->createMemoryAdapter(), 'testFailureRequest');

        $circuit->setSettings(['failureRateThreshold' => 2]);
        $circuit->failure();

        $handler = new GuzzleMiddleware($circuit);
        $handlers = HandlerStack::create(new MockHandler([
            new Response(404),
        ]));
        $handlers->push($handler);

        $client = new Client(['handler' => $handlers]);

        $this->expectException(\GuzzleHttp\Exception\ClientException::class);

        $client->get('https://example.com/undefined');

        $this->assertEquals(2, $circuit->getFailuresCounter());
        $this->assertFalse($circuit->isAvailable());
    }

    public function testFailureRequestToUnknownHost()
    {
        $circuit = new CircuitBreaker($this->createMemoryAdapter(), 'testFailureRequest');

        $circuit->setSettings(['failureRateThreshold' => 2]);
        $circuit->failure();

        $handler = new GuzzleMiddleware($circuit);
        $handlers = HandlerStack::create(new MockHandler([
            Create::rejectionFor(new ConnectException('Connection failed', new Request('GET', 'https://undefined-host.test'))),
        ]));
        $handlers->push($handler);

        $client = new Client(['handler' => $handlers]);

        try {
            $client->get('https://undefined-host.test');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(ConnectException::class, $exception);
        }

        $this->assertEquals(2, $circuit->getFailuresCounter());
        $this->assertFalse($circuit->isAvailable());
    }

    private function createMemoryAdapter(): AdapterInterface
    {
        return new class implements AdapterInterface {
            private array $failures = [];

            private array $open = [];

            private array $halfOpen = [];

            public function isOpen(string $service): bool
            {
                return isset($this->open[$service]) && time() < $this->open[$service];
            }

            public function reachRateLimit(string $service, int $failureRateThreshold): bool
            {
                return ($this->failures[$service] ?? 0) >= $failureRateThreshold;
            }

            public function setOpenCircuit(string $service, int $timeWindow): void
            {
                $this->open[$service] = time() + $timeWindow;
                unset($this->failures[$service]);
            }

            public function setHalfOpenCircuit(string $service, int $timeWindow, int $intervalToHalfOpen): void
            {
                $this->halfOpen[$service] = time() + $timeWindow + $intervalToHalfOpen;
            }

            public function isHalfOpen(string $service): bool
            {
                return isset($this->halfOpen[$service]) && time() < $this->halfOpen[$service];
            }

            public function incrementFailure(string $service, int $timeWindow): bool
            {
                $this->failures[$service] = ($this->failures[$service] ?? 0) + 1;

                return true;
            }

            public function setSuccess(string $service): void
            {
                unset($this->failures[$service], $this->open[$service], $this->halfOpen[$service]);
            }

            public function getFailuresCounter(string $service): int
            {
                return $this->failures[$service] ?? 0;
            }
        };
    }
}
