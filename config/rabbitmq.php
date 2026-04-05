<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    */
    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    /*
    |--------------------------------------------------------------------------
    | Default Exchange
    |--------------------------------------------------------------------------
    | The default exchange used when publishing without specifying one.
    | Type: direct, fanout, topic, headers
    */
    'exchange' => [
        'name' => env('RABBITMQ_EXCHANGE', 'app'),
        'type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
        'durable' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Defaults
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'durable' => true,
        'auto_delete' => false,
        'exclusive' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer
    |--------------------------------------------------------------------------
    */
    'consumer' => [
        'prefetch_count' => (int) env('RABBITMQ_PREFETCH', 1),
        'timeout' => (int) env('RABBITMQ_CONSUMER_TIMEOUT', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead Letter
    |--------------------------------------------------------------------------
    | Failed messages are routed here after max retries.
    */
    'dead_letter' => [
        'enabled' => env('RABBITMQ_DLX_ENABLED', true),
        'exchange' => env('RABBITMQ_DLX_EXCHANGE', 'dlx'),
        'queue_suffix' => '.dlq',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    */
    'retry' => [
        'enabled' => env('RABBITMQ_RETRY_ENABLED', true),
        'max_attempts' => (int) env('RABBITMQ_RETRY_MAX', 3),
        'delay_ms' => (int) env('RABBITMQ_RETRY_DELAY', 5000),
    ],

];
