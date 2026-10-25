<?php

declare(strict_types=1);

namespace Astral\Worker\Console;

use Astral\Worker\Queue\QueueInterface;
use Core\Console\CommandInterface;
use Core\Console\Console;

final class FailedCommand implements CommandInterface
{
    public function __construct(private QueueInterface $queue)
    {
    }

    public function getName(): string
    {
        return 'queue:failed';
    }

    public function getDescription(): string
    {
        return 'Liste les jobs en échec (--limit=50)';
    }

    public function execute(array $args, Console $console): int
    {
        $a    = ArgParser::parse($args);
        $rows = $this->queue->failed((int) ($a['limit'] ?? 50));

        if ($rows === []) {
            $console->success('Aucun job en échec.');

            return 0;
        }

        foreach ($rows as $row) {
            $firstLine = strtok($row['exception'], "\n");
            printf(
                "#%s  %s  [%s]  tentatives=%d  %s%s    %s%s",
                $row['id'],
                $row['job_class'],
                $row['queue'],
                $row['attempts'],
                date('Y-m-d H:i:s', $row['failed_at']),
                PHP_EOL,
                $firstLine === false ? '' : mb_substr($firstLine, 0, 160),
                PHP_EOL,
            );
        }

        return 0;
    }
}
