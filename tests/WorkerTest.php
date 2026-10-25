<?php

declare(strict_types=1);

namespace Astral\Worker\Tests;

use Astral\Worker\Database\Schema;
use Astral\Worker\Job\CallableJobResolver;
use Astral\Worker\Queue\DatabaseQueue;
use Astral\Worker\Tests\Fixtures\FailingJob;
use Astral\Worker\Tests\Fixtures\RecordingJob;
use Astral\Worker\Worker;
use Astral\Worker\WorkerOptions;
use PDO;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    private DatabaseQueue $queue;
    private Worker $worker;

    protected function setUp(): void
    {
        RecordingJob::$handled = [];
        FailingJob::$calls     = 0;

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::createJobsTable($pdo);
        Schema::createFailedJobsTable($pdo);

        $this->queue  = new DatabaseQueue($pdo);
        $this->worker = new Worker($this->queue, new CallableJobResolver(fn (string $c): object => new $c()));
    }

    public function testSuccessfulJobIsHandledAndDeleted(): void
    {
        $this->queue->push(RecordingJob::class, ['n' => 1]);

        $processed = $this->worker->work('default', new WorkerOptions(stopWhenEmpty: true));

        $this->assertSame(1, $processed);
        $this->assertSame([['n' => 1]], RecordingJob::$handled);
        $this->assertSame(0, $this->queue->size());
        $this->assertSame([], $this->queue->failed());
    }

    public function testFailingJobIsRetriedThenMovedToFailed(): void
    {
        $this->queue->push(FailingJob::class);

        $this->worker->work('default', new WorkerOptions(stopWhenEmpty: true));

        $this->assertSame(2, FailingJob::$calls, 'TRIES = 2 → deux exécutions.');
        $this->assertSame(0, $this->queue->size());
        $this->assertCount(1, $this->queue->failed());
    }

    public function testUnknownJobClassFailsImmediately(): void
    {
        $this->queue->push('Nope\\MissingJob');

        $this->worker->work('default', new WorkerOptions(stopWhenEmpty: true));

        $failed = $this->queue->failed();
        $this->assertCount(1, $failed);
        $this->assertSame(1, $failed[0]['attempts']);
    }

    public function testMaxJobsStopsTheWorker(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->queue->push(RecordingJob::class, ['i' => $i]);
        }

        $processed = $this->worker->work('default', new WorkerOptions(maxJobs: 2));

        $this->assertSame(2, $processed);
        $this->assertSame(1, $this->queue->size());
    }

    public function testJobThatKeepsCrashingIsAbandonedWithoutRunning(): void
    {
        // retryAfter = 0 : chaque pop() « reprend » le job comme si le worker précédent était mort
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::createJobsTable($pdo);
        Schema::createFailedJobsTable($pdo);
        $queue  = new DatabaseQueue($pdo, retryAfter: 0);
        $worker = new Worker($queue, new CallableJobResolver(fn (string $c): object => new $c()));

        $queue->push(RecordingJob::class);
        $queue->pop();
        $queue->pop();
        $queue->pop(); // attempts = 3 (= TRIES par défaut), jamais terminé

        $worker->runNextJob(); // 4e prise : dépasse TRIES

        $this->assertSame([], RecordingJob::$handled);
        $this->assertCount(1, $queue->failed());
    }
}
