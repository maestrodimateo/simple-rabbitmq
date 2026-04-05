<?php

namespace Maestrodimateo\SimpleRabbitMQ\Tests;

use Maestrodimateo\SimpleRabbitMQ\Facades\RabbitMQ;
use Maestrodimateo\SimpleRabbitMQ\SimpleRabbitMQServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [SimpleRabbitMQServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'RabbitMQ' => RabbitMQ::class,
        ];
    }
}
