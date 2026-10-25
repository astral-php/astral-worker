<?php

declare(strict_types=1);

namespace Astral\Worker\Console;

use Astral\Worker\Queue\QueueInterface;
use Astral\Worker\Worker;
use Core\Console\CommandInterface;

final class CommandFactory
{
    /**
     * @param callable(string): object $make Ex. : fn(string $id) => $container->make($id)
     * @return list<CommandInterface>
     */
    public static function all(callable $make): array
    {
        return [
            new WorkCommand($make(Worker::class)),
            new FailedCommand($make(QueueInterface::class)),
            new RetryCommand($make(QueueInterface::class)),
            new FlushCommand($make(QueueInterface::class)),
        ];
    }
}
