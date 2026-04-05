<?php

namespace Maestrodimateo\SimpleRabbitMQ\Contracts;

use Maestrodimateo\SimpleRabbitMQ\Messages\IncomingMessage;

abstract class MessageHandler
{
    /**
     * Handle an incoming RabbitMQ message.
     * No need to ack — it's handled automatically after this method returns.
     * Throw an exception to nack and trigger retry/dead-letter.
     */
    abstract public function handle(IncomingMessage $message): void;
}
