<?php

namespace Maestrodimateo\SimpleRabbitMQ\Messages;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * A received RabbitMQ message — wraps AMQPMessage with a clean API.
 */
class IncomingMessage
{
    private readonly array $decodedPayload;

    private readonly array $parsedHeaders;

    public function __construct(private readonly AMQPMessage $amqpMessage)
    {
        $this->decodedPayload = json_decode($amqpMessage->getBody(), true) ?? [];
        $this->parsedHeaders = $this->extractHeaders();
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
        return $this->parsedHeaders[$key] ?? $default;
    }

    /** Get all headers */
    public function headers(): array
    {
        return $this->parsedHeaders;
    }

    /** Get the retry count (from x-death header set by RabbitMQ on DLX redelivery) */
    public function retryCount(): int
    {
        $deaths = $this->parsedHeaders['x-death'] ?? [];

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

    /**
     * Safely extract application headers from the AMQP message.
     * Returns an empty array if no headers are present (avoids OutOfBoundsException).
     */
    private function extractHeaders(): array
    {
        if (! $this->amqpMessage->has('application_headers')) {
            return [];
        }

        $headers = $this->amqpMessage->get('application_headers');

        return method_exists($headers, 'getNativeData') ? $headers->getNativeData() : [];
    }
}
