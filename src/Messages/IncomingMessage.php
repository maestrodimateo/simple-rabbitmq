<?php

namespace Maestrodimateo\SimpleRabbitMQ\Messages;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * A received RabbitMQ message — wraps AMQPMessage with a clean API.
 */
class IncomingMessage
{
    private readonly array $decodedPayload;

    public function __construct(private readonly AMQPMessage $amqpMessage)
    {
        $this->decodedPayload = json_decode($amqpMessage->getBody(), true) ?? [];
    }

    /** Get the decoded payload as an array */
    public function payload(): array
    {
        return $this->decodedPayload;
    }

    /** Get a specific value from the payload */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->decodedPayload[$key] ?? $default;
    }

    /** Get the routing key */
    public function routingKey(): string
    {
        return $this->amqpMessage->getRoutingKey();
    }

    /** Get the exchange name */
    public function exchange(): string
    {
        return $this->amqpMessage->getExchange();
    }

    /** Get the raw JSON body */
    public function raw(): string
    {
        return $this->amqpMessage->getBody();
    }

    /** Get a message header */
    public function header(string $key, mixed $default = null): mixed
    {
        $headers = $this->amqpMessage->get('application_headers')?->getNativeData() ?? [];

        return $headers[$key] ?? $default;
    }

    /** Get all headers */
    public function headers(): array
    {
        return $this->amqpMessage->get('application_headers')?->getNativeData() ?? [];
    }

    /** Get the retry count (from x-death header) */
    public function retryCount(): int
    {
        $deaths = $this->header('x-death', []);

        if (empty($deaths)) {
            return 0;
        }

        return (int) ($deaths[0]['count'] ?? 0);
    }

    /** Get the underlying AMQPMessage */
    public function amqpMessage(): AMQPMessage
    {
        return $this->amqpMessage;
    }
}
