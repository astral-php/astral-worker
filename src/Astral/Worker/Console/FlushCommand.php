<?php

declare(strict_types=1);

namespace Astral\Worker\Console;

use Astral\Worker\Queue\QueueInterface;
use Core\Console\CommandInterface;
use Core\Console\Console;

final class FlushCommand implements CommandInterface
{
    public function __construct(private QueueInterface $queue)
    {
    }

    public function getName(): string
    {
        return 'queue:flush';
    }

    public function getDescription(): string
    {
        return 'Supprime tous les jobs en échec';
    }

    public function execute(array $args, Console $console): int
    {
        $console->success($this->queue->flushFailed() . ' job(s) en échec supprimé(s).');

        return 0;
    }
}
