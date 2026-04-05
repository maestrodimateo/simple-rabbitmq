<?php

namespace Maestrodimateo\SimpleRabbitMQ;

use Illuminate\Support\ServiceProvider;
use Maestrodimateo\SimpleRabbitMQ\Console\ConsumeCommand;
use Maestrodimateo\SimpleRabbitMQ\Console\MakeHandlerCommand;
use Maestrodimateo\SimpleRabbitMQ\Console\MakeMessageCommand;

class SimpleRabbitMQServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rabbitmq.php', 'rabbitmq');

        $this->app->singleton(RabbitMQManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConsumeCommand::class,
                MakeHandlerCommand::class,
                MakeMessageCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/rabbitmq.php' => config_path('rabbitmq.php'),
            ], 'rabbitmq-config');
        }

        // Load listener routes if the file exists
        $routesFile = base_path('routes/rabbitmq.php');
        if (file_exists($routesFile)) {
            require $routesFile;
        }
    }
}
