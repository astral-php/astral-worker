<?php

declare(strict_types=1);

namespace Astral\Worker;

use Astral\Worker\Job\CallableJobResolver;
use Astral\Worker\Job\GeneratePdfJob;
use Astral\Worker\Job\JobResolverInterface;
use Astral\Worker\Job\PdfRendererInterface;
use Astral\Worker\Queue\DatabaseQueue;
use Astral\Worker\Queue\QueueInterface;
use Astral\Worker\Queue\RedisQueue;
use Astral\Worker\Queue\SyncQueue;
use Core\Container;
use Core\Logger;
use Core\ServiceProviderInterface;
use PDO;

/**
 * Enregistre la file, le worker et le résolveur de jobs.
 * Rien n'est instancié tant que le service n'est pas demandé (pas de connexion Redis inutile).
 *
 * .env : QUEUE_DRIVER=database|redis|sync (défaut : database)
 */
final class WorkerServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container, array $appConfig, array $dbConfig): void
    {
        $container->singleton(
            JobResolverInterface::class,
            fn (Container $c): JobResolverInterface => new CallableJobResolver(
                fn (string $class): object => $c->make($class)
            )
        );

        $container->singleton(QueueInterface::class, function (Container $c): QueueInterface {
            $driver     = strtolower((string) ($_ENV['QUEUE_DRIVER'] ?? 'database'));
            $retryAfter = (int) ($_ENV['QUEUE_RETRY_AFTER'] ?? 90);

            return match ($driver) {
                'sync'     => new SyncQueue($c->make(JobResolverInterface::class)),
                'database' => new DatabaseQueue($c->make(PDO::class), $retryAfter),
                'redis'    => $this->redisQueue($retryAfter),
                default    => throw new \InvalidArgumentException(
                    "QUEUE_DRIVER inconnu : {$driver} (database | redis | sync)"
                ),
            };
        });

        $container->singleton(Worker::class, function (Container $c): Worker {
            $logger = $c->make(Logger::class);

            return new Worker(
                $c->make(QueueInterface::class),
                $c->make(JobResolverInterface::class),
                static fn (string $level, string $message, array $context = []): mixed => match ($level) {
                    'error'   => $logger->error($message, $context),
                    'warning' => $logger->warning($message, $context),
                    default   => $logger->info($message, $context),
                },
            );
        });

        // Nécessite qu'une implémentation de PdfRendererInterface soit enregistrée par l'application
        $container->bind(
            GeneratePdfJob::class,
            fn (Container $c): GeneratePdfJob => new GeneratePdfJob(
                $c->make(PdfRendererInterface::class),
                (string) ($_ENV['WORKER_PDF_DIR'] ?? (defined('BASE_PATH')
                    ? BASE_PATH . '/storage/pdf'
                    : getcwd() . '/storage/pdf')),
            )
        );
    }

    private function redisQueue(int $retryAfter): RedisQueue
    {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException('QUEUE_DRIVER=redis nécessite l\'extension PHP redis (ext-redis).');
        }

        $redis = new \Redis();
        $redis->connect((string) ($_ENV['REDIS_HOST'] ?? '127.0.0.1'), (int) ($_ENV['REDIS_PORT'] ?? 6379));

        $password = (string) ($_ENV['REDIS_PASSWORD'] ?? '');
        if ($password !== '') {
            $redis->auth($password);
        }

        $db = (int) ($_ENV['REDIS_DB'] ?? 0);
        if ($db > 0) {
            $redis->select($db);
        }

        return new RedisQueue($redis, (string) ($_ENV['QUEUE_PREFIX'] ?? 'astral:queue:'), $retryAfter);
    }
}
