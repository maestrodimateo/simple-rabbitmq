<?php

namespace Maestrodimateo\SimpleRabbitMQ;

use Closure;
use Illuminate\Support\Facades\Log;
use Maestrodimateo\SimpleRabbitMQ\Contracts\MessageHandler;
use Maestrodimateo\SimpleRabbitMQ\Messages\IncomingMessage;
use Maestrodimateo\SimpleRabbitMQ\Messages\RabbitMQMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * RabbitMQManager — Dev-friendly interface for RabbitMQ.
 *
 * Handles connection lifecycle, exchange/queue declaration, publishing,
 * consuming, retries, and dead-letter routing.
 */
class RabbitMQManager
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    /** @var array<string, string> Registered listeners: routing_key => handler_class */
    private array $listeners = [];

    /** @var array<string> Exchanges already declared in this process */
    private array $declaredExchanges = [];

    /** @var array<string> Queues already declared in this process */
    private array $declaredQueues = [];

    /** @var array|null Override exchange for the next publish { name, type } */
    private ?array $targetExchange = null;

    // =========================================================================
    // Connection (lazy)
    // =========================================================================

    /**
     * Get or create the AMQP connection.
     */
    private function connection(): AMQPStreamConnection
    {
        if (! $this->connection || ! $this->connection->isConnected()) {
            $this->connection = new AMQPStreamConnection(
                config('rabbitmq.host', '127.0.0.1'),
                config('rabbitmq.port', 5672),
                config('rabbitmq.user', 'guest'),
                config('rabbitmq.password', 'guest'),
                config('rabbitmq.vhost', '/'),
            );
        }

        return $this->connection;
    }

    /**
     * Get or create the AMQP channel.
     */
    private function channel(): AMQPChannel
    {
        if (! $this->channel || ! $this->channel->is_open()) {
            $this->channel = $this->connection()->channel();

            $prefetch = config('rabbitmq.consumer.prefetch_count', 1);
            $this->channel->basic_qos(0, $prefetch, false);
        }

        return $this->channel;
    }

    // =========================================================================
    // Publishing
    // =========================================================================

    /**
     * Set the exchange for the next publish call.
     *
     *     RabbitMQ::to('my-exchange')->publish('key', $data);
     *     RabbitMQ::to('my-exchange', 'fanout')->publish('key', $data);
     *     RabbitMQ::direct('my-exchange')->publish('key', $data);
     *     RabbitMQ::fanout('my-exchange')->publish('key', $data);
     *     RabbitMQ::topic('my-exchange')->publish('key', $data);
     *
     * @param  string  $exchange  Exchange name
     * @param  string  $type  Exchange type (direct, fanout, topic, headers)
     * @return $this
     */
    public function to(string $exchange, string $type = 'topic'): static
    {
        $this->targetExchange = ['name' => $exchange, 'type' => $type];

        return $this;
    }

    /** Shortcut for a direct exchange */
    public function direct(string $exchange): static
    {
        return $this->to($exchange, 'direct');
    }

    /** Shortcut for a fanout exchange */
    public function fanout(string $exchange): static
    {
        return $this->to($exchange, 'fanout');
    }

    /** Shortcut for a topic exchange */
    public function topic(string $exchange): static
    {
        return $this->to($exchange, 'topic');
    }

    /** Shortcut for a headers exchange */
    public function headers(string $exchange): static
    {
        return $this->to($exchange, 'headers');
    }

    /**
     * Publish a message to RabbitMQ.
     *
     *     RabbitMQ::publish('orders.created', ['order_id' => 123]);
     *
     * @param  string  $routingKey  Routing key
     * @param  array  $payload  Message data (will be JSON-encoded)
     * @param  array  $headers  Optional AMQP headers
     */
    public function publish(string $routingKey, array $payload = [], array $headers = []): void
    {
        $exchangeName = $this->targetExchange['name'] ?? config('rabbitmq.exchange.name', 'app');
        $exchangeType = $this->targetExchange['type'] ?? config('rabbitmq.exchange.type', 'topic');
        $this->targetExchange = null;

        $this->ensureExchange($exchangeName, $exchangeType);

        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        if ($headers) {
            $properties['application_headers'] = new AMQPTable($headers);
        }

        $message = new AMQPMessage(json_encode($payload), $properties);

        $this->channel()->basic_publish($message, $exchangeName, $routingKey);
    }

    /**
     * Publish a typed message object.
     *
     *     RabbitMQ::send(new OrdreAchatMessage($ordre));
     *
     * @param  RabbitMQMessage  $message  Message object
     */
    public function send(RabbitMQMessage $message): void
    {
        if ($message->exchange) {
            $this->to($message->exchange);
        }

        $this->publish($message->routingKey, $message->payload(), $message->headers());
    }

    // =========================================================================
    // Consuming
    // =========================================================================

    /**
     * Register a listener for a routing key.
     * Called from routes/rabbitmq.php.
     *
     *     RabbitMQ::listen('orders.created', ProcessOrderHandler::class);
     *
     * @param  string  $routingKey  Routing key pattern (supports topic wildcards: * and #)
     * @param  string  $handlerClass  Class extending MessageHandler
     */
    public function listen(string $routingKey, string $handlerClass): void
    {
        $this->listeners[$routingKey] = $handlerClass;
    }

    /**
     * Consume messages for a specific routing key with a callback.
     * Blocks until stopped. Useful for simple one-off consumers.
     *
     *     RabbitMQ::consume('orders.created', function (array $data) {
     *         // process
     *     });
     *
     * @param  string  $routingKey  Routing key
     * @param  Closure  $callback  Receives the decoded payload array
     */
    public function consume(string $routingKey, Closure $callback): void
    {
        $queueName = $this->queueNameFromKey($routingKey);
        $exchange = config('rabbitmq.exchange.name', 'app');

        $this->ensureExchange($exchange);
        $this->ensureQueue($queueName, $exchange, $routingKey);

        $this->channel()->basic_consume(
            $queueName,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $amqpMessage) use ($callback) {
                $incoming = new IncomingMessage($amqpMessage);

                try {
                    $callback($incoming->payload());
                    $amqpMessage->ack();
                } catch (\Throwable $e) {
                    Log::error('RabbitMQ consumer error', [
                        'error' => $e->getMessage(),
                        'routing_key' => $incoming->routingKey(),
                    ]);
                    $amqpMessage->nack(false, false);
                }
            },
        );

        while ($this->channel()->is_consuming()) {
            $this->channel()->wait();
        }
    }

    /**
     * Start consuming all registered listeners.
     * Called by the rabbitmq:consume artisan command.
     */
    public function consumeAll(): void
    {
        $exchange = config('rabbitmq.exchange.name', 'app');
        $this->ensureExchange($exchange);

        foreach ($this->listeners as $routingKey => $handlerClass) {
            $queueName = $this->queueNameFromKey($routingKey);
            $this->ensureQueue($queueName, $exchange, $routingKey);

            $this->channel()->basic_consume(
                $queueName,
                '',
                false,
                false,
                false,
                false,
                function (AMQPMessage $amqpMessage) use ($handlerClass) {
                    $this->handleMessage($amqpMessage, $handlerClass);
                },
            );

            Log::info("RabbitMQ: listening on [{$queueName}] → {$handlerClass}");
        }

        while ($this->channel()->is_consuming()) {
            $this->channel()->wait();
        }
    }

    /**
     * Get all registered listeners.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return $this->listeners;
    }

    // =========================================================================
    // Infrastructure (exchange, queue, DLX)
    // =========================================================================

    /**
     * Declare an exchange if not already declared.
     *
     * @param  string  $name  Exchange name
     * @param  string  $type  Exchange type (direct, fanout, topic, headers)
     */
    private function ensureExchange(string $name, string $type = 'topic'): void
    {
        if (in_array($name, $this->declaredExchanges, true)) {
            return;
        }

        $durable = config('rabbitmq.exchange.durable', true);

        $this->channel()->exchange_declare($name, $type, false, $durable, false);
        $this->declaredExchanges[] = $name;

        // Declare DLX if enabled
        if (config('rabbitmq.dead_letter.enabled', true)) {
            $dlxName = config('rabbitmq.dead_letter.exchange', 'dlx');
            if (! in_array($dlxName, $this->declaredExchanges, true)) {
                $this->channel()->exchange_declare($dlxName, 'topic', false, true, false);
                $this->declaredExchanges[] = $dlxName;
            }
        }
    }

    /**
     * Declare a queue and bind it to an exchange.
     * Sets up dead-letter routing if enabled.
     */
    private function ensureQueue(string $queueName, string $exchange, string $routingKey): void
    {
        if (in_array($queueName, $this->declaredQueues, true)) {
            return;
        }

        $durable = config('rabbitmq.queue.durable', true);
        $autoDelete = config('rabbitmq.queue.auto_delete', false);
        $exclusive = config('rabbitmq.queue.exclusive', false);

        $arguments = [];

        // Dead-letter routing
        if (config('rabbitmq.dead_letter.enabled', true)) {
            $dlxExchange = config('rabbitmq.dead_letter.exchange', 'dlx');
            $arguments['x-dead-letter-exchange'] = $dlxExchange;
            $arguments['x-dead-letter-routing-key'] = $routingKey;

            // Declare DLQ
            $dlqName = $queueName.config('rabbitmq.dead_letter.queue_suffix', '.dlq');
            $this->channel()->queue_declare($dlqName, false, true, false, false);
            $this->channel()->queue_bind($dlqName, $dlxExchange, $routingKey);
        }

        $table = $arguments ? new AMQPTable($arguments) : null;

        $this->channel()->queue_declare($queueName, false, $durable, $exclusive, $autoDelete, false, $table);
        $this->channel()->queue_bind($queueName, $exchange, $routingKey);

        $this->declaredQueues[] = $queueName;
    }

    // =========================================================================
    // Message handling (ack, nack, retry)
    // =========================================================================

    /**
     * Process a received message through a handler with auto ack/nack/retry.
     */
    private function handleMessage(AMQPMessage $amqpMessage, string $handlerClass): void
    {
        $incoming = new IncomingMessage($amqpMessage);

        try {
            /** @var MessageHandler $handler */
            $handler = app($handlerClass);
            $handler->handle($incoming);

            $amqpMessage->ack();
        } catch (\Throwable $e) {
            $this->handleFailure($amqpMessage, $incoming, $e);
        }
    }

    /**
     * Handle a failed message — retry or send to DLQ.
     */
    private function handleFailure(AMQPMessage $amqpMessage, IncomingMessage $incoming, \Throwable $e): void
    {
        $retryEnabled = config('rabbitmq.retry.enabled', true);
        $maxAttempts = config('rabbitmq.retry.max_attempts', 3);
        $currentAttempt = $incoming->retryCount() + 1;

        Log::warning('RabbitMQ: message handling failed', [
            'routing_key' => $incoming->routingKey(),
            'attempt' => $currentAttempt,
            'max_attempts' => $maxAttempts,
            'error' => $e->getMessage(),
        ]);

        if ($retryEnabled && $currentAttempt < $maxAttempts) {
            // Nack and requeue for retry
            $amqpMessage->nack(false, true);
        } else {
            // Send to DLQ (nack without requeue)
            $amqpMessage->nack(false, false);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Convert a routing key to a queue name.
     * e.g., "orders.created" → "orders.created"
     * e.g., "orders.*" → "orders._star_"
     */
    private function queueNameFromKey(string $routingKey): string
    {
        return str_replace(['*', '#'], ['_star_', '_hash_'], $routingKey);
    }

    /**
     * Disconnect cleanly.
     */
    public function disconnect(): void
    {
        if ($this->channel?->is_open()) {
            $this->channel->close();
        }
        if ($this->connection?->isConnected()) {
            $this->connection->close();
        }
        $this->channel = null;
        $this->connection = null;
        $this->declaredExchanges = [];
        $this->declaredQueues = [];
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
