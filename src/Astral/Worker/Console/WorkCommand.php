<?php

declare(strict_types=1);

namespace Astral\Worker\Console;

use Astral\Worker\Worker;
use Astral\Worker\WorkerOptions;
use Core\Console\CommandInterface;
use Core\Console\Console;

/**
 * php bin/console worker:work [--queue=default] [--sleep=3] [--max-jobs=0] [--max-time=0]
 *                             [--stop-when-empty] [--once] [--memory=128] [--tries=3] [--backoff=30]
 */
final class WorkCommand implements CommandInterface
{
    public function __construct(private Worker $worker)
    {
    }

    public function getName(): string
    {
        return 'worker:work';
    }

    public function getDescription(): string
    {
        return 'Traite les jobs de la file (--stop-when-empty pour cron, --once pour un seul job)';
    }

    public function execute(array $args, Console $console): int
    {
        $a     = ArgParser::parse($args);
        $once  = isset($a['once']);
        $queue = is_string($a['queue'] ?? null) ? $a['queue'] : 'default';

        $options = new WorkerOptions(
            sleep: (int) ($a['sleep'] ?? 3),
            maxJobs: $once ? 1 : (int) ($a['max-jobs'] ?? 0),
            maxTime: (int) ($a['max-time'] ?? 0),
            stopWhenEmpty: $once || isset($a['stop-when-empty']),
            memoryLimitMb: (int) ($a['memory'] ?? 128),
            tries: (int) ($a['tries'] ?? 3),
            backoff: (int) ($a['backoff'] ?? 30),
        );

        echo "Worker démarré (file « {$queue} »)." . PHP_EOL;
        $processed = $this->worker->work($queue, $options);
        $console->success("Worker arrêté — {$processed} job(s) traité(s).");

        return 0;
    }
}
