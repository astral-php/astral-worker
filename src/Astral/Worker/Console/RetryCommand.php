<?php

declare(strict_types=1);

namespace Astral\Worker\Console;

use Astral\Worker\Queue\QueueInterface;
use Core\Console\CommandInterface;
use Core\Console\Console;

final class RetryCommand implements CommandInterface
{
    public function __construct(private QueueInterface $queue)
    {
    }

    public function getName(): string
    {
        return 'queue:retry';
    }

    public function getDescription(): string
    {
        return 'Remet un job en échec dans la file : queue:retry <id> | queue:retry all';
    }

    public function execute(array $args, Console $console): int
    {
        $target = ArgParser::parse($args)['_'][0] ?? null;

        if ($target === null) {
            echo 'Usage : queue:retry <id>|all' . PHP_EOL;

            return 1;
        }

        if ($target === 'all') {
            $console->success($this->queue->retryAll() . ' job(s) remis en file.');

            return 0;
        }

        if (!$this->queue->retry($target)) {
            echo "Job en échec introuvable : {$target}" . PHP_EOL;

            return 1;
        }

        $console->success("Job {$target} remis en file.");

        return 0;
    }
}
