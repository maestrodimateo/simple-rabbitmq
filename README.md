# Simple RabbitMQ

[![Latest Version on Packagist](https://img.shields.io/packagist/v/maestrodimateo/simple-rabbitmq.svg)](https://packagist.org/packages/maestrodimateo/simple-rabbitmq)
[![License](https://img.shields.io/packagist/l/maestrodimateo/simple-rabbitmq.svg)](https://packagist.org/packages/maestrodimateo/simple-rabbitmq)

A simple, dev-friendly Laravel wrapper for [RabbitMQ](https://www.rabbitmq.com/).

Built on top of [php-amqplib](https://github.com/php-amqplib/php-amqplib), this package provides a clean Facade for publishing and consuming messages — with auto JSON, typed message objects, dead-letter queues, and retry out of the box.

## Installation

```bash
composer require maestrodimateo/simple-rabbitmq
```

Publish the config:

```bash
php artisan vendor:publish --tag=rabbitmq-config
```

Add to your `.env`:

```env
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
```

---

## Quick Start

### Publish a message

```php
use Maestrodimateo\SimpleRabbitMQ\Facades\RabbitMQ;

// Simple publish
RabbitMQ::publish('orders.created', ['order_id' => 123, 'total' => 99.99]);

// Publish to a specific exchange
RabbitMQ::to('billing.events')->publish('invoice.paid', $data);

// Via a typed Message Object
RabbitMQ::send(new OrdreAchatMessage($ordre));
```

### Consume messages

Define listeners in `routes/rabbitmq.php` (create this file):

```php
use Maestrodimateo\SimpleRabbitMQ\Facades\RabbitMQ;
use App\RabbitMQ\Handlers\ProcessOrderHandler;
use App\RabbitMQ\Handlers\ProcessPaymentHandler;

RabbitMQ::listen('orders.created', ProcessOrderHandler::class);
RabbitMQ::listen('payments.*', ProcessPaymentHandler::class);
```

Start the consumer:

```bash
php artisan rabbitmq:consume
```

---

## Message Objects

Generate a typed message:

```bash
php artisan make:rabbitmq-message OrdreAchatMessage
```

This creates `app/RabbitMQ/Messages/OrdreAchatMessage.php`:

```php
use Maestrodimateo\SimpleRabbitMQ\Messages\RabbitMQMessage;

class OrdreAchatMessage extends RabbitMQMessage
{
    public string $routingKey = 'ordre.achat';

    public function __construct(public readonly Ordre $ordre) {}

    public function payload(): array
    {
        return [
            'ticker' => $this->ordre->ticker,
            'prix' => $this->ordre->prix,
        ];
    }
}
```

Publish it:

```php
RabbitMQ::send(new OrdreAchatMessage($ordre));
```

---

## Message Handlers

Generate a handler:

```bash
php artisan make:rabbitmq-handler ProcessOrderHandler
```

This creates `app/RabbitMQ/Handlers/ProcessOrderHandler.php`:

```php
use Maestrodimateo\SimpleRabbitMQ\Contracts\MessageHandler;
use Maestrodimateo\SimpleRabbitMQ\Messages\IncomingMessage;

class ProcessOrderHandler extends MessageHandler
{
    public function handle(IncomingMessage $message): void
    {
        $data = $message->payload();       // Decoded array
        $key  = $message->routingKey();    // 'orders.created'
        $raw  = $message->raw();           // Raw JSON string

        // Your processing logic here
        // No need to ack — handled automatically
        // Throw an exception to trigger retry/DLQ
    }
}
```

### IncomingMessage API

| Method | Returns | Description |
|---|---|---|
| `payload()` | `array` | Decoded JSON payload |
| `get($key, $default)` | `mixed` | Get a specific payload value |
| `routingKey()` | `string` | The routing key |
| `exchange()` | `string` | The exchange name |
| `raw()` | `string` | Raw JSON body |
| `header($key)` | `mixed` | Get a specific header |
| `headers()` | `array` | All headers |
| `retryCount()` | `int` | Number of retry attempts |

---

## Simple Callback Consumer

For quick one-off consumers without a handler class:

```php
RabbitMQ::consume('orders.created', function (array $data) {
    // Process the order
    Order::create($data);
});
```

---

## Dead Letter Queue

Failed messages are automatically routed to a dead-letter queue after max retries. Enabled by default.

```env
RABBITMQ_DLX_ENABLED=true
RABBITMQ_DLX_EXCHANGE=dlx
```

Each queue gets a corresponding `.dlq` queue (e.g., `orders.created` → `orders.created.dlq`).

---

## Retry

Messages that throw an exception are retried automatically:

```env
RABBITMQ_RETRY_ENABLED=true
RABBITMQ_RETRY_MAX=3
RABBITMQ_RETRY_DELAY=5000
```

After `max_attempts`, the message goes to the DLQ.

---

## Exchanges

The default exchange is `app` with type `topic`. Configure in `.env`:

```env
RABBITMQ_EXCHANGE=my-exchange
RABBITMQ_EXCHANGE_TYPE=topic    # direct, fanout, topic, headers
```

Publish to a different exchange on-the-fly:

```php
RabbitMQ::to('billing.events')->publish('invoice.paid', $data);
```

---

## Configuration

```php
// config/rabbitmq.php
return [
    'host'     => env('RABBITMQ_HOST', '127.0.0.1'),
    'port'     => (int) env('RABBITMQ_PORT', 5672),
    'user'     => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost'    => env('RABBITMQ_VHOST', '/'),

    'exchange' => [
        'name'    => env('RABBITMQ_EXCHANGE', 'app'),
        'type'    => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
        'durable' => true,
    ],

    'queue' => [
        'durable'     => true,
        'auto_delete' => false,
        'exclusive'   => false,
    ],

    'consumer' => [
        'prefetch_count' => (int) env('RABBITMQ_PREFETCH', 1),
    ],

    'dead_letter' => [
        'enabled'      => true,
        'exchange'     => 'dlx',
        'queue_suffix' => '.dlq',
    ],

    'retry' => [
        'enabled'      => true,
        'max_attempts' => 3,
        'delay_ms'     => 5000,
    ],
];
```

---

## Artisan Commands

| Command | Description |
|---|---|
| `rabbitmq:consume` | Start consuming all registered listeners |
| `make:rabbitmq-handler` | Generate a new message handler class |
| `make:rabbitmq-message` | Generate a new typed message class |

---

## Helper

```php
rabbitmq()->publish('key', $data);
rabbitmq()->to('exchange')->publish('key', $data);
```

---

## Testing

```bash
./vendor/bin/pest tests/Unit
```

---

## License

MIT

## Credits

- [Noel Mebale](https://github.com/maestrodimateo)
- Built on [php-amqplib/php-amqplib](https://github.com/php-amqplib/php-amqplib)
