<?php

namespace Maestrodimateo\SimpleRabbitMQ\Messages;

/**
 * Base class for outgoing message objects.
 *
 * Extend this class to create typed, reusable messages:
 *
 *     class OrdreAchatMessage extends RabbitMQMessage
 *     {
 *         public string $routingKey = 'boursier.achat';
 *
 *         public function __construct(public readonly Ordre $ordre) {}
 *
 *         public function payload(): array
 *         {
 *             return ['ticker' => $this->ordre->ticker, 'prix' => $this->ordre->prix];
 *         }
 *     }
 */
abstract class RabbitMQMessage
{
    /** The routing key used when publishing this message */
    public string $routingKey = '';

    /** The exchange to publish to (null = use default from config) */
    public ?string $exchange = null;

    /**
     * The message payload as an array (will be JSON-encoded).
     */
    abstract public function payload(): array;

    /**
     * Optional message headers.
     */
    public function headers(): array
    {
        return [];
    }
}
