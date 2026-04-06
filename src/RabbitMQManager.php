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
use Throwable;

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

    /** @var array{name: string, type: string}|null Override exchange for the next publish */
    private ?array $targetExchange = null;

    // =========================================================================
    // Connection (lazy — connects only on first use)
    // =========================================================================

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

    private function channel(): AMQPChannel
    {
        if (! $this->channel || ! $this->channel->is_open()) {
            $this->channel = $this->connection()->channel();
            $this->channel->basic_qos(0, config('rabbitmq.consumer.prefetch_count', 1), false);
        }

        return $this->channel;
    }

    // =========================================================================
    // Publishing
    // =========================================================================

    /**
     * Set the target exchange for the next publish call.
     *
     * @param  string  $exchange  Exchange name
     * @param  string  $type  Exchange type (direct, fanout, topic, headers)
     */
    public function to(string $exchange, string $type = 'topic'): static
    {
        $this->targetExchange = ['name' => $exchange, 'type' => $type];

        return $this;
    }

    /** Target a direct exchange for the next publish */
    public function direct(string $exchange): static
    {
        return $this->to($exchange, 'direct');
    }

    /** Target a fanout exchange for the next publish */
    public function fanout(string $exchange): static
    {
        return $this->to($exchange, 'fanout');
    }

    /** Target a topic exchange for the next publish */
    public function topic(string $exchange): static
    {
        return $this->to($exchange, 'topic');
    }

    /** Target a headers exchange for the next publish */
    public function headers(string $exchange): static
    {
        return $this->to($exchange, 'headers');
    }

    /**
     * Publish a JSON-encoded message to RabbitMQ.
     *
     * @param  string  $routingKey  Routing key
     * @param  array  $payload  Data (will be JSON-encoded)
     * @param  array  $headers  Optional AMQP headers
     */
    public function publish(string $routingKey, array $payload = [], array $headers = []): void
    {
        $exchangeName = $this->targetExchange['name'] ?? config('rabbitmq.exchange.name', 'app');
        $exchangeType = $this->targetExchange['type'] ?? config('rabbitmq.exchange.type', 'topic');
        $this->targetExchange = null;

        $this->ensureExchangeExists($exchangeName, $exchangeType);

        $message = $this->buildAmqpMessage($payload, $headers);

        $this->channel()->basic_publish($message, $exchangeName, $routingKey);
    }

    /**
     * Publish a typed message object.
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
     * Register a listener for a routing key (called from routes/rabbitmq.php).
     *
     * @param  string  $routingKey  Routing key pattern (supports topic wildcards: *, #)
     * @param  string  $handlerClass  Fully qualified class name extending MessageHandler
     */
    public function listen(string $routingKey, string $handlerClass): void
    {
        $this->listeners[$routingKey] = $handlerClass;
    }

    /**
     * Consume messages for a single routing key using a callback.
     * Blocks until the channel is closed.
     *
     * @param  string  $routingKey  Routing key
     * @param  Closure  $callback  Receives the decoded payload array
     */
    public function consume(string $routingKey, Closure $callback): void
    {
        $queueName = $this->toQueueName($routingKey);
        $exchangeName = config('rabbitmq.exchange.name', 'app');
        $exchangeType = config('rabbitmq.exchange.type', 'topic');

        $this->ensureExchangeExists($exchangeName, $exchangeType);
        $this->ensureQueueExists($queueName, $exchangeName, $routingKey);

        $this->channel()->basic_consume(
            $queueName, '', false, false, false, false,
            function (AMQPMessage $amqpMessage) use ($callback) {
                $incoming = new IncomingMessage($amqpMessage);

                try {
                    $callback($incoming->payload());
                    $amqpMessage->ack();
                } catch (Throwable $e) {
                    Log::error('RabbitMQ: callback consumer error', [
                        'routing_key' => $incoming->routingKey(),
                        'error' => $e->getMessage(),
                    ]);
                    $amqpMessage->nack(requeue: false);
                }
            },
        );

        $this->waitForMessages();
    }

    /**
     * Start consuming all registered listeners.
     * Called by the rabbitmq:consume artisan command.
     */
    public function consumeAll(): void
    {
        $exchangeName = config('rabbitmq.exchange.name', 'app');
        $exchangeType = config('rabbitmq.exchange.type', 'topic');
        $this->ensureExchangeExists($exchangeName, $exchangeType);

        foreach ($this->listeners as $routingKey => $handlerClass) {
            $queueName = $this->toQueueName($routingKey);
            $this->ensureQueueExists($queueName, $exchangeName, $routingKey);

            $this->channel()->basic_consume(
                $queueName, '', false, false, false, false,
                fn (AMQPMessage $msg) => $this->dispatchToHandler($msg, $handlerClass),
            );

            Log::info("RabbitMQ: listening on [{$queueName}] → {$handlerClass}");
        }

        $this->waitForMessages();
    }

    /**
     * @return array<string, string> All registered listeners
     */
    public function getListeners(): array
    {
        return $this->listeners;
    }

    // =========================================================================
    // Message dispatching (ack / nack / retry)
    // =========================================================================

    /**
     * Dispatch a received AMQP message to the appropriate handler.
     * Handles ack on success, retry or DLQ on failure.
     */
    private function dispatchToHandler(AMQPMessage $amqpMessage, string $handlerClass): void
    {
        $incoming = new IncomingMessage($amqpMessage);

        try {
            /** @var MessageHandler $handler */
            $handler = app($handlerClass);
            $handler->handle($incoming);
            $amqpMessage->ack();
        } catch (Throwable $exception) {
            $this->handleFailedMessage($amqpMessage, $incoming, $exception);
        }
    }

    /**
     * Handle a message that failed processing — retry or route to DLQ.
     */
    private function handleFailedMessage(AMQPMessage $amqpMessage, IncomingMessage $incoming, Throwable $exception): void
    {
        $maxAttempts = config('rabbitmq.retry.max_attempts', 3);
        $currentAttempt = $incoming->retryCount() + 1;
        $retryEnabled = config('rabbitmq.retry.enabled', true);

        Log::warning('RabbitMQ: message processing failed', [
            'routing_key' => $incoming->routingKey(),
            'attempt' => $currentAttempt,
            'max_attempts' => $maxAttempts,
            'error' => $exception->getMessage(),
        ]);

        $shouldRequeue = $retryEnabled && $currentAttempt < $maxAttempts;
        $amqpMessage->nack(requeue: $shouldRequeue);
    }

    // =========================================================================
    // Infrastructure (exchange, queue, dead-letter)
    // =========================================================================

    /**
     * Declare an exchange if not already declared in this process.
     */
    private function ensureExchangeExists(string $name, string $type = 'topic'): void
    {
        if (in_array($name, $this->declaredExchanges, true)) {
            return;
        }

        $durable = config('rabbitmq.exchange.durable', true);
        $this->channel()->exchange_declare($name, $type, false, $durable, false);
        $this->declaredExchanges[] = $name;

        $this->ensureDeadLetterExchangeExists();
    }

    /**
     * Declare the dead-letter exchange if DLX is enabled.
     */
    private function ensureDeadLetterExchangeExists(): void
    {
        if (! config('rabbitmq.dead_letter.enabled', true)) {
            return;
        }

        $dlxName = config('rabbitmq.dead_letter.exchange', 'dlx');

        if (in_array($dlxName, $this->declaredExchanges, true)) {
            return;
        }

        $this->channel()->exchange_declare($dlxName, 'topic', false, true, false);
        $this->declaredExchanges[] = $dlxName;
    }

    /**
     * Declare a queue, bind it to an exchange, and set up dead-letter routing.
     */
    private function ensureQueueExists(string $queueName, string $exchange, string $routingKey): void
    {
        if (in_array($queueName, $this->declaredQueues, true)) {
            return;
        }

        $arguments = $this->buildQueueArguments($routingKey);
        $table = $arguments ? new AMQPTable($arguments) : null;

        $this->channel()->queue_declare(
            $queueName,
            passive: false,
            durable: config('rabbitmq.queue.durable', true),
            exclusive: config('rabbitmq.queue.exclusive', false),
            auto_delete: config('rabbitmq.queue.auto_delete', false),
            nowait: false,
            arguments: $table,
        );

        $this->channel()->queue_bind($queueName, $exchange, $routingKey);
        $this->declaredQueues[] = $queueName;
    }

    /**
     * Build queue arguments including dead-letter routing if enabled.
     */
    private function buildQueueArguments(string $routingKey): array
    {
        if (! config('rabbitmq.dead_letter.enabled', true)) {
            return [];
        }

        $dlxExchange = config('rabbitmq.dead_letter.exchange', 'dlx');

        // Declare the DLQ
        $dlqName = $this->toQueueName($routingKey).config('rabbitmq.dead_letter.queue_suffix', '.dlq');
        $this->channel()->queue_declare($dlqName, false, true, false, false);
        $this->channel()->queue_bind($dlqName, $dlxExchange, $routingKey);

        return [
            'x-dead-letter-exchange' => $dlxExchange,
            'x-dead-letter-routing-key' => $routingKey,
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a persistent JSON AMQP message with optional headers.
     */
    private function buildAmqpMessage(array $payload, array $headers = []): AMQPMessage
    {
        $properties = [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        if ($headers) {
            $properties['application_headers'] = new AMQPTable($headers);
        }

        return new AMQPMessage(json_encode($payload), $properties);
    }

    /**
     * Block and wait for incoming messages until the channel closes.
     */
    private function waitForMessages(): void
    {
        while ($this->channel()->is_consuming()) {
            $this->channel()->wait();
        }
    }

    /**
     * Convert a routing key to a valid queue name.
     *
     *   "orders.created" → "orders.created"
     *   "orders.*"       → "orders._star_"
     *   "logs.#"         → "logs._hash_"
     */
    private function toQueueName(string $routingKey): string
    {
        return str_replace(['*', '#'], ['_star_', '_hash_'], $routingKey);
    }

    /**
     * Close the channel and connection cleanly.
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
