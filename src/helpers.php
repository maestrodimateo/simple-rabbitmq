<?php

use Maestrodimateo\SimpleRabbitMQ\RabbitMQManager;

if (! function_exists('rabbitmq')) {
    /**
     * Get the RabbitMQ manager instance.
     *
     * @example rabbitmq()->publish('orders.created', $data)
     */
    function rabbitmq(): RabbitMQManager
    {
        return app(RabbitMQManager::class);
    }
}
