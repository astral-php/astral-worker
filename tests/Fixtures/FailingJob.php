<?php

declare(strict_types=1);

namespace Astral\Worker\Tests\Fixtures;

use Astral\Worker\Job\JobInterface;

final class FailingJob implements JobInterface
{
    public const TRIES = 2;
    public const BACKOFF = 0;

    public static int $calls = 0;

    public function handle(array $payload): void
    {
        self::$calls++;

        throw new \RuntimeException('boom');
    }
}
