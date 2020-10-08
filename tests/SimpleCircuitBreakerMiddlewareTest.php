<?php

namespace Dronezzzko\Tests;

use Closure;
use Desarrolla2\Cache\Memory;
use Dronezzzko\SimpleCircuitBreakerExcepion;
use Dronezzzko\SimpleCircuitBreakerMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SimpleCircuitBreakerMiddlewareTest extends TestCase
{
    public function testExponentialDelay(): void
    {
        /** @var SimpleCircuitBreakerMiddleware $middleware */
        $middleware = $this->createMock(SimpleCircuitBreakerMiddleware::class);

        $delayFn = (fn(int $retries): int => $this->exponentialDelay($retries))->bindTo($middleware, SimpleCircuitBreakerMiddleware::class);

        self::assertSame(5, $delayFn(1));
        self::assertSame(10, $delayFn(2));
        self::assertSame(20, $delayFn(3));
        self::assertSame(40, $delayFn(4));
        self::assertSame(80, $delayFn(5));
    }

    /**
     * @param Closure $asserts
     * @param Response ...$responses
     * @dataProvider circuitBreakerDataProvider
     */
    public function testCircuitBreaker(Closure $asserts, Response ...$responses): void
    {
        $cache = new Memory();
        $cacheGetter = (fn() => $this->cache)->bindTo($cache, Memory::class);
        $middleware = SimpleCircuitBreakerMiddleware::factory($cache);
        $handler = new MockHandler($responses);
        $client = new Client(['handler' => $middleware($handler)]);

        $promise = $client->sendAsync(new Request('GET', 'http://example.com'), []);
        $promise->wait();

        $asserts($cacheGetter, $promise, $client);

    }

    /**
     * @return array[]
     */
    public function circuitBreakerDataProvider(): array
    {
        return [
            'test successful response is not a trigger' => [
                static function (Closure $cache, $promise) {
                    self::assertEmpty($cache());
                    self::assertSame(200, $promise->wait()->getStatusCode());
                },
                new Response(200),
            ],
            'test that Unauthorized response is ignored' => [
                static function (Closure $cache, $promise) {
                    self::assertEmpty($cache());
                    self::assertSame(401, $promise->wait()->getStatusCode());
                },
                new Response(401),
            ],
            'test failed request (500)' => [
                function (Closure $cache, $promise, $client) {
                    self::assertNotEmpty($cache());
                    self::assertSame(500, $promise->wait()->getStatusCode());

                    $client->sendAsync(new Request('GET', 'http://example.com'), []);
                    $this->expectException(SimpleCircuitBreakerExcepion::class);
                },
                new Response(500),
                new Response(500),
            ]
        ];
    }
}