<?php

namespace Maestrodimateo\SimpleRabbitMQ\Console;

use Illuminate\Console\Command;
use Maestrodimateo\SimpleRabbitMQ\RabbitMQManager;
use Throwable;

class ConsumeCommand extends Command
{
    protected $signature = 'rabbitmq:consume';

    protected $description = 'Start consuming messages from all registered RabbitMQ listeners';

    public function handle(RabbitMQManager $manager): int
    {
        $listeners = $manager->getListeners();

        if (empty($listeners)) {
            $this->warn('No listeners registered. Define them in routes/rabbitmq.php.');

            return self::FAILURE;
        }

        $this->info('Starting RabbitMQ consumer...');
        $this->table(
            ['Routing Key', 'Handler'],
            collect($listeners)->map(fn ($handler, $key) => [$key, $handler])->values()->all(),
        );
        $this->newLine();

        try {
            $manager->consumeAll();
        } catch (Throwable $e) {
            $this->error('Consumer stopped: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
