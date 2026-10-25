<?php

declare(strict_types=1);

namespace Astral\Worker\Queue;

use Astral\Worker\Job\JobResolverInterface;

/**
 * Exécute le job immédiatement, dans la requête (dev / tests).
 * Les exceptions remontent : on voit les erreurs tout de suite.
 */
final class SyncQueue implements QueueInterface
{
    public function __construct(private JobResolverInterface $resolver)
    {
    }

    public function push(string $jobClass, array $payload = [], int $delay = 0, string $queue = 'default'): string|int
    {
        $this->resolver->resolve($jobClass)->handle($payload);

        return 'sync';
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        return null;
    }

    public function delete(QueuedJob $job): void
    {
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
    }

    public function fail(QueuedJob $job, \Throwable $e): void
    {
    }

    public function size(string $queue = 'default'): int
    {
        return 0;
    }

    public function failed(int $limit = 50): array
    {
        return [];
    }

    public function retry(string|int $id): bool
    {
        return false;
    }

    public function retryAll(): int
    {
        return 0;
    }

    public function flushFailed(): int
    {
        return 0;
    }
}
