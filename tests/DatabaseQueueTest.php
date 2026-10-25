<?php

declare(strict_types=1);

namespace Astral\Worker\Tests;

use Astral\Worker\Database\Schema;
use Astral\Worker\Queue\DatabaseQueue;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseQueueTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        Schema::createJobsTable($this->pdo);
        Schema::createFailedJobsTable($this->pdo);
    }

    public function testPushThenPopReturnsTheJobOnce(): void
    {
        $queue = new DatabaseQueue($this->pdo);
        $queue->push('App\\Jobs\\Foo', ['order_id' => 12]);

        $job = $queue->pop();

        $this->assertNotNull($job);
        $this->assertSame('App\\Jobs\\Foo', $job->class);
        $this->assertSame(['order_id' => 12], $job->payload);
        $this->assertSame(1, $job->attempts);
        $this->assertNull($queue->pop(), 'Un job réservé ne doit pas être servi deux fois.');
    }

    public function testDelayedJobIsNotAvailableYet(): void
    {
        $queue = new DatabaseQueue($this->pdo);
        $queue->push('Foo', [], 3600);

        $this->assertNull($queue->pop());
        $this->assertSame(1, $queue->size());
    }

    public function testDeleteRemovesTheJob(): void
    {
        $queue = new DatabaseQueue($this->pdo);
        $queue->push('Foo');

        $queue->delete($queue->pop());

        $this->assertSame(0, $queue->size());
    }

    public function testReleaseMakesTheJobAvailableAgain(): void
    {
        $queue = new DatabaseQueue($this->pdo);
        $queue->push('Foo');

        $queue->release($queue->pop(), 0);
        $again = $queue->pop();

        $this->assertNotNull($again);
        $this->assertSame(2, $again->attempts);
    }

    public function testExpiredReservationIsReclaimed(): void
    {
        $queue = new DatabaseQueue($this->pdo, retryAfter: 0);
        $queue->push('Foo');

        $first  = $queue->pop();
        $second = $queue->pop();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame(2, $second->attempts);
    }

    public function testQueuesAreIsolated(): void
    {
        $queue = new DatabaseQueue($this->pdo);
        $queue->push('Foo', [], 0, 'emails');

        $this->assertNull($queue->pop('default'));
        $this->assertNotNull($queue->pop('emails'));
    }

    public function testFailMovesTheJobToFailedAndRetryRestoresIt(): void
    {
        $queue = new DatabaseQueue($this->pdo);
        $queue->push('Foo', ['a' => 1]);

        $queue->fail($queue->pop(), new \RuntimeException('boom'));

        $this->assertSame(0, $queue->size());
        $failed = $queue->failed();
        $this->assertCount(1, $failed);
        $this->assertSame('Foo', $failed[0]['job_class']);
        $this->assertStringContainsString('boom', $failed[0]['exception']);

        $this->assertTrue($queue->retry($failed[0]['id']));
        $this->assertSame([], $queue->failed());

        $job = $queue->pop();
        $this->assertNotNull($job);
        $this->assertSame(['a' => 1], $job->payload);
        $this->assertSame(1, $job->attempts, 'Les tentatives repartent de zéro après un retry.');
    }

    public function testRetryAllAndFlush(): void
    {
        $queue = new DatabaseQueue($this->pdo);
        $queue->push('A');
        $queue->push('B');
        $queue->fail($queue->pop(), new \RuntimeException('x'));
        $queue->fail($queue->pop(), new \RuntimeException('y'));

        $this->assertSame(2, $queue->retryAll());
        $this->assertSame(2, $queue->size());

        $queue->fail($queue->pop(), new \RuntimeException('z'));
        $this->assertSame(1, $queue->flushFailed());
        $this->assertSame([], $queue->failed());
    }

    public function testInvalidTableNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DatabaseQueue($this->pdo, 90, 'jobs; DROP TABLE users');
    }
}
