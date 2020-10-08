# guzzle-simple-circuit-breaker
An implementation of the Circuit Breaker pattern for Guzzle that prevents sending failed requests in a row.  

## How To Use
```php
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Client;

use Dronezzzko\SimpleCircuitBreakerMiddleware;

$stack = HandlerStack::create();
$stack->push(SimpleCircuitBreakerMiddleware::factory());
$client = new Client(['handler' => $stack]);
```
