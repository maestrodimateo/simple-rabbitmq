<?php

namespace Maestrodimateo\SimpleRabbitMQ\Facades;

use Illuminate\Support\Facades\Facade;
use Maestrodimateo\SimpleRabbitMQ\RabbitMQManager;

/**
 * @method static static to(string $exchange)
 * @method static void publish(string $routingKey, array $payload = [], array $headers = [])
 * @method static void send(\Maestrodimateo\SimpleRabbitMQ\Messages\RabbitMQMessage $message)
 * @method static void listen(string $routingKey, string $handlerClass)
 * @method static void consume(string $routingKey, \Closure $callback)
 * @method static void consumeAll()
 * @method static array getListeners()
 * @method static void disconnect()
 *
 * @see RabbitMQManager
 */
class RabbitMQ extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RabbitMQManager::class;
    }
}
