<?php

use Maestrodimateo\SimpleRabbitMQ\Facades\RabbitMQ;
use Maestrodimateo\SimpleRabbitMQ\Messages\RabbitMQMessage;
use Maestrodimateo\SimpleRabbitMQ\RabbitMQManager;

// =============================================================================
// Service provider & binding
// =============================================================================

it('registers the RabbitMQManager as a singleton', function () {
    $a = app(RabbitMQManager::class);
    $b = app(RabbitMQManager::class);

    expect($a)->toBeInstanceOf(RabbitMQManager::class)->and($a)->toBe($b);
});

it('resolves via the Facade', function () {
    expect(RabbitMQ::getFacadeRoot())->toBeInstanceOf(RabbitMQManager::class);
});

it('resolves via the helper', function () {
    expect(rabbitmq())->toBeInstanceOf(RabbitMQManager::class);
});

// =============================================================================
// Listener registration
// =============================================================================

it('can register listeners', function () {
    RabbitMQ::listen('orders.created', 'App\\Handlers\\OrderHandler');
    RabbitMQ::listen('orders.cancelled', 'App\\Handlers\\CancelHandler');

    $listeners = RabbitMQ::getListeners();

    expect($listeners)->toHaveCount(2)
        ->and($listeners['orders.created'])->toBe('App\\Handlers\\OrderHandler')
        ->and($listeners['orders.cancelled'])->toBe('App\\Handlers\\CancelHandler');
});

// =============================================================================
// Message object
// =============================================================================

it('can create a message object with routing key and payload', function () {
    $message = new class extends RabbitMQMessage
    {
        public string $routingKey = 'test.message';

        public function payload(): array
        {
            return ['key' => 'value'];
        }
    };

    expect($message->routingKey)->toBe('test.message')
        ->and($message->payload())->toBe(['key' => 'value'])
        ->and($message->exchange)->toBeNull()
        ->and($message->headers())->toBe([]);
});

it('supports custom exchange and headers on message objects', function () {
    $message = new class extends RabbitMQMessage
    {
        public string $routingKey = 'custom.msg';

        public ?string $exchange = 'my-exchange';

        public function payload(): array
        {
            return ['data' => true];
        }

        public function headers(): array
        {
            return ['x-priority' => 'high'];
        }
    };

    expect($message->exchange)->toBe('my-exchange')
        ->and($message->headers())->toBe(['x-priority' => 'high']);
});

// =============================================================================
// Config
// =============================================================================

it('loads default config values', function () {
    expect(config('rabbitmq.host'))->toBe('127.0.0.1')
        ->and(config('rabbitmq.port'))->toBe(5672)
        ->and(config('rabbitmq.exchange.name'))->toBe('app')
        ->and(config('rabbitmq.exchange.type'))->toBe('topic')
        ->and(config('rabbitmq.dead_letter.enabled'))->toBeTrue()
        ->and(config('rabbitmq.retry.max_attempts'))->toBe(3);
});
