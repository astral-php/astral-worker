<?php

declare(strict_types=1);

namespace Astral\Worker\Tests\Fixtures;

use Astral\Worker\Job\JobInterface;

final class RecordingJob implements JobInterface
{
    /** @var list<array<string, mixed>> */
    public static array $handled = [];

    public function handle(array $payload): void
    {
        self::$handled[] = $payload;
    }
}
