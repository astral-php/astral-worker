<?php

declare(strict_types=1);

namespace Astral\Worker;

use Astral\Worker\Exception\JobNotFoundException;
use Astral\Worker\Exception\MaxAttemptsExceededException;
use Astral\Worker\Job\JobResolverInterface;
use Astral\Worker\Queue\QueuedJob;
use Astral\Worker\Queue\QueueInterface;
use Closure;
use Throwable;

final class Worker
{
    private bool $shouldStop = false;

    /** @var Closure(string, string, array<string, mixed>): mixed|null */
    private ?Closure $logger;

    /**
     * @param callable(string, string, array<string, mixed>): mixed|null $logger fn($level, $message, $context)
     */
    public function __construct(
        private QueueInterface $queue,
        private JobResolverInterface $resolver,
        ?callable $logger = null,
    ) {
        $this->logger = $logger === null ? null : Closure::fromCallable($logger);
    }

    /** @return int Nombre de jobs traités */
    public function work(string $queue = 'default', ?WorkerOptions $options = null): int
    {
        $options ??= new WorkerOptions();
        $this->listenForSignals();

        $startedAt = time();
        $processed = 0;

        while (!$this->shouldStop) {
            if ($this->runNextJob($queue, $options)) {
                $processed++;
            } elseif ($options->stopWhenEmpty) {
                break;
            } else {
                $this->pause($options->sleep);
            }

            if ($options->maxJobs > 0 && $processed >= $options->maxJobs) {
                break;
            }

            if ($options->maxTime > 0 && (time() - $startedAt) >= $options->maxTime) {
                break;
            }

            if ($options->memoryLimitMb > 0 && memory_get_usage(true) >= $options->memoryLimitMb * 1048576) {
                $this->log('warning', 'Worker arrêté : limite mémoire atteinte.');
                break;
            }
        }

        return $processed;
    }

    /** Traite au plus un job. @return bool true si un job a été pris en charge */
    public function runNextJob(string $queue = 'default', ?WorkerOptions $options = null): bool
    {
        $options ??= new WorkerOptions();

        $job = $this->queue->pop($queue);
        if ($job === null) {
            return false;
        }

        $maxTries = $this->setting($job->class, 'TRIES', $options->tries);
        $backoff  = $this->setting($job->class, 'BACKOFF', $options->backoff);

        // Le job a déjà été tenté $maxTries fois sans se terminer (ex. : worker tué) : on abandonne
        if ($job->attempts > $maxTries) {
            $this->queue->fail($job, new MaxAttemptsExceededException(
                "{$job->class} : {$maxTries} tentative(s) dépassée(s)."
            ));
            $this->log('error', 'Job abandonné (tentatives dépassées)', ['job' => $job->class, 'id' => $job->id]);

            return true;
        }

        try {
            $this->resolver->resolve($job->class)->handle($job->payload);
        } catch (JobNotFoundException $e) {
            $this->queue->fail($job, $e);
            $this->log('error', 'Job introuvable', ['job' => $job->class, 'id' => $job->id, 'error' => $e->getMessage()]);

            return true;
        } catch (Throwable $e) {
            $this->handleFailure($job, $e, $maxTries, $backoff);

            return true;
        }

        $this->queue->delete($job);
        $this->log('info', 'Job traité', ['job' => $job->class, 'id' => $job->id, 'attempts' => $job->attempts]);

        return true;
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    private function handleFailure(QueuedJob $job, Throwable $e, int $maxTries, int $backoff): void
    {
        if ($job->attempts >= $maxTries) {
            $this->queue->fail($job, $e);
            $this->log('error', 'Job en échec définitif', [
                'job' => $job->class, 'id' => $job->id, 'attempts' => $job->attempts, 'error' => $e->getMessage(),
            ]);

            return;
        }

        $delay = max(0, $backoff) * $job->attempts; // backoff linéaire
        $this->queue->release($job, $delay);
        $this->log('warning', 'Job en échec, nouvelle tentative programmée', [
            'job' => $job->class, 'id' => $job->id, 'attempts' => $job->attempts, 'retry_in' => $delay, 'error' => $e->getMessage(),
        ]);
    }

    /** Lit une constante optionnelle du job (TRIES, BACKOFF). */
    private function setting(string $class, string $name, int $default): int
    {
        $constant = $class . '::' . $name;

        return defined($constant) ? (int) constant($constant) : $default;
    }

    private function pause(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    private function listenForSignals(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return; // Windows / pcntl absent : utiliser --max-time ou --stop-when-empty
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->shouldStop = true;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }

    /** @param array<string, mixed> $context */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }
}
