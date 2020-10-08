<?php

namespace Dronezzzko;

use Closure;
use DateTime;
use Exception;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use JsonException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Middleware that prevents sending failed requests
 */
class SimpleCircuitBreakerMiddleware
{
    /**
     * @var callable
     */
    private $nextHandler;

    private CacheInterface $cache;

    private const EXCEPT_CODES = [401];

    /**
     * The number of minutes to delay
     */
    private const DEFAULT_DELAY = 5;

    /**
     * The duration in seconds before the retry state will reset
     */
    private const RETRY_STATE_TTL = 86400;

    /**
     * @param callable $nextHandler next handler to invoke
     * @param CacheInterface $cache
     */
    public function __construct(callable $nextHandler, CacheInterface $cache)
    {
        $this->nextHandler = $nextHandler;
        $this->cache = $cache;
    }

    /**
     * Provides a closure that can be pushed onto the handler stack
     *
     * Example: $handlerStack->push(SimpleCircuitBreakerMiddleware::factory($cache));
     *
     * @param CacheInterface $cache
     * @return Closure
     */
    public static function factory(CacheInterface $cache): Closure
    {
        return static fn(callable $handler): self => new static($handler, $cache);
    }

    /**
     * Returns the number of minutes to delay
     *
     * @param int $retries
     *
     * @return int minutes
     */
    private function exponentialDelay(int $retries = 1): int
    {
        return 2 ** ($retries - 1) * self::DEFAULT_DELAY;
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     *
     * @return PromiseInterface
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    public function __invoke(RequestInterface $request, array $options): ?PromiseInterface
    {
        if (!$this->decider($request)) {
            $this->throwRequestException($request);
        }

        $fn = $this->nextHandler;
        return $fn($request, $options)->then(
            $this->onFulfilled($request),
            $this->onRejected($request)
        );
    }

    /**
     * Executed when request is fulfilled
     *
     * @param RequestInterface $request
     * @return callable
     */
    private function onFulfilled(RequestInterface $request): callable
    {
        return function (ResponseInterface $response) use ($request) {
            if ($response->getStatusCode() >= 400
                && !in_array($response->getStatusCode(), self::EXCEPT_CODES)
            ) {
                $this->handleFailure($request, $response);
            } else {
                $this->resetRequestRetries($request);
            }
            return $response;
        };
    }

    /**
     * Executed when request is rejected
     *
     * @param RequestInterface $request
     * @return callable
     */
    private function onRejected(RequestInterface $request): callable
    {
        return fn($rejectionReason) => $this->handleFailure($request, null, $rejectionReason);
    }

    /**
     * Determines if a request should be sent or rejected
     *
     * @param RequestInterface $request
     * @return bool
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    private function decider(RequestInterface $request): bool
    {
        $retriesState = $this->getRequestRetriesState($request);

        if (empty($retriesState['retries'])) {
            return true;
        }

        if (time() >= $retriesState['nextTry']) {
            return true;
        }

        return false;
    }

    /**
     * @param RequestInterface $request
     * @param ResponseInterface|null $response
     * @param Exception|null $exception
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    private function handleFailure(
        RequestInterface $request,
        ResponseInterface $response = null,
        Exception $exception = null
    ): void
    {
        $retriesState = $this->getRequestRetriesState($request);

        $retriesState['retries'] ??= 0;

        $retriesState['retries']++;
        $retriesState['nextTry'] = $this->getNextRetryDateTime($retriesState['retries'])->getTimestamp();

        $retriesState['response'] ??= null;
        if ($response) {
            $retriesState['response'] = [
                'code' => $response->getStatusCode(),
                'body' => (string)$response->getBody()
            ];
        }

        $this->cache->set($this->getRequestKey($request), $retriesState, self::RETRY_STATE_TTL);
    }

    /**
     * @param int $retries
     * @return DateTime
     */
    private function getNextRetryDateTime(int $retries = 1): DateTime
    {
        return (new DateTime())->modify('+ ' . $this->exponentialDelay($retries) . ' minutes');
    }

    /**
     * @param RequestInterface $request
     * @return string
     * @throws JsonException if unable to encode key
     */
    private function getRequestKey(RequestInterface $request): string
    {
        $key = [
            'method' => $request->getMethod(),
            'URI' => (string)$request->getUri(),
            'body' => (string)$request->getBody()
        ];
        return 'circuit_breaker_middleware_' . md5(json_encode(array_filter($key), JSON_THROW_ON_ERROR));
    }

    /**
     * @param RequestInterface $request
     * @return array
     * @throws JsonException|InvalidArgumentException
     */
    private function getRequestRetriesState(RequestInterface $request): array
    {
        return $this->cache->get($this->getRequestKey($request), []);
    }

    /**
     * Resets the retries counter
     *
     * @param RequestInterface $request
     * @throws JsonException
     * @throws InvalidArgumentException
     */
    private function resetRequestRetries(RequestInterface $request): void
    {
        $this->cache->delete($this->getRequestKey($request));
    }

    /**
     * @param RequestInterface $request
     * @throws InvalidArgumentException
     * @throws JsonException
     */
    private function throwRequestException(RequestInterface $request): void
    {
        $retriesState = $this->getRequestRetriesState($request);
        $response = null;
        if ($retriesState['response']) {
            $response = new Response($retriesState['response']['code'], [], $retriesState['response']['body']);
        }

        throw new SimpleCircuitBreakerExcepion('Circuit Breaker Request Exception', $request, $response);
    }
}
