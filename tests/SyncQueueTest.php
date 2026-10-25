<?php

declare(strict_types=1);

namespace Astral\Worker\Tests;

use Astral\Worker\Job\CallableJobResolver;
use Astral\Worker\Queue\SyncQueue;
use Astral\Worker\Tests\Fixtures\RecordingJob;
use PHPUnit\Framework\TestCase;

final class SyncQueueTest extends TestCase
{
    public function testPushRunsTheJobImmediately(): void
    {
        RecordingJob::$handled = [];
        $queue = new SyncQueue(new CallableJobResolver(fn (string $c): object => new $c()));

        $queue->push(RecordingJob::class, ['x' => 1]);

        $this->assertSame([['x' => 1]], RecordingJob::$handled);
        $this->assertNull($queue->pop());
    }
}
