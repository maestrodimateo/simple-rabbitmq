<?php

namespace Maestrodimateo\SimpleRabbitMQ\Console;

use Illuminate\Console\GeneratorCommand;

class MakeHandlerCommand extends GeneratorCommand
{
    protected $name = 'make:rabbitmq-handler';

    protected $description = 'Create a new RabbitMQ message handler';

    protected $type = 'MessageHandler';

    protected function getStub(): string
    {
        return __DIR__.'/stubs/handler.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\RabbitMQ\\Handlers';
    }
}
