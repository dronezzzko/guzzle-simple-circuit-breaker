# Guzzle Simple Circuit Breaker Middleware
Implementation of the [Circuit Breaker](https://martinfowler.com/bliki/CircuitBreaker.html) pattern for Guzzle that prevents sending failed requests in a row.  

### How To Install
```bash
composer require dronezzzko/guzzle-simple-circuit-breaker
```


### How To Use
```php
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Client;

use Dronezzzko\SimpleCircuitBreakerMiddleware;

$stack = HandlerStack::create();
$stack->push(SimpleCircuitBreakerMiddleware::factory($cache));
$client = new Client(['handler' => $stack]);
```
