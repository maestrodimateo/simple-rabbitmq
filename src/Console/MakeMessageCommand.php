<?php

namespace Maestrodimateo\SimpleRabbitMQ\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;

class MakeMessageCommand extends GeneratorCommand
{
    protected $name = 'make:rabbitmq-message';

    protected $description = 'Create a new RabbitMQ message class';

    protected $type = 'RabbitMQMessage';

    protected function getStub(): string
    {
        return __DIR__.'/stubs/message.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\RabbitMQ\\Messages';
    }

    protected function buildClass($name): string
    {
        $class = parent::buildClass($name);

        $baseName = class_basename($name);
        $routingKey = Str::of($baseName)
            ->replaceLast('Message', '')
            ->snake()
            ->replace('_', '.')
            ->toString();

        return str_replace('{{ routingKey }}', $routingKey, $class);
    }
}
