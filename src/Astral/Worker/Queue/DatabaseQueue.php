<?php

declare(strict_types=1);

namespace Astral\Worker\Queue;

use Astral\Worker\Database\Schema;
use PDO;
use Throwable;

/**
 * File d'attente en base (SQLite ou MySQL) via PDO.
 *
 * Réservation « optimiste » portable : on repère un candidat, puis on le réserve avec
 * un UPDATE conditionnel. Si rowCount() != 1, un autre worker l'a pris : on retente.
 * Aucun verrou, pas de dépendance à SKIP LOCKED (MySQL 8+ / MariaDB 10.6+).
 *
 * Un job réservé depuis plus de $retryAfter secondes (worker tué, timeout) redevient
 * disponible ; `attempts` permet alors au Worker de l'abandonner après N crashs.
 *
 * Le PDO doit être en PDO::ERRMODE_EXCEPTION.
 */
final class DatabaseQueue implements QueueInterface
{
    private const CLAIM_RETRIES = 5;

    public function __construct(
        private PDO $pdo,
        private int $retryAfter = 90,
        private string $table = 'jobs',
        private string $failedTable = 'failed_jobs',
    ) {
        Schema::assertIdentifier($table);
        Schema::assertIdentifier($failedTable);
    }

    public function push(string $jobClass, array $payload = [], int $delay = 0, string $queue = 'default'): string|int
    {
        $now  = time();
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (queue, job_class, payload, attempts, available_at, reserved_at, created_at)
             VALUES (:queue, :class, :payload, 0, :available, NULL, :created)"
        );
        $stmt->execute([
            'queue'     => $queue,
            'class'     => $jobClass,
            'payload'   => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'available' => $now + max(0, $delay),
            'created'   => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function pop(string $queue = 'default'): ?QueuedJob
    {
        for ($try = 0; $try < self::CLAIM_RETRIES; $try++) {
            $now     = time();
            $expired = $now - $this->retryAfter;

            $select = $this->pdo->prepare(
                "SELECT id FROM {$this->table}
                 WHERE queue = :queue AND available_at <= :now AND (reserved_at IS NULL OR reserved_at <= :expired)
                 ORDER BY id ASC LIMIT 1"
            );
            $select->execute(['queue' => $queue, 'now' => $now, 'expired' => $expired]);
            $id = $select->fetchColumn();

            if ($id === false) {
                return null;
            }

            $claim = $this->pdo->prepare(
                "UPDATE {$this->table} SET reserved_at = :now, attempts = attempts + 1
                 WHERE id = :id AND (reserved_at IS NULL OR reserved_at <= :expired)"
            );
            $claim->execute(['now' => $now, 'id' => $id, 'expired' => $expired]);

            if ($claim->rowCount() !== 1) {
                continue; // pris par un autre worker entre-temps
            }

            $fetch = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id");
            $fetch->execute(['id' => $id]);
            $row = $fetch->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                continue;
            }

            $payload = json_decode((string) $row['payload'], true);

            return new QueuedJob(
                id: (int) $row['id'],
                queue: (string) $row['queue'],
                class: (string) $row['job_class'],
                payload: is_array($payload) ? $payload : [],
                attempts: (int) $row['attempts'],
            );
        }

        return null;
    }

    public function delete(QueuedJob $job): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id");
        $stmt->execute(['id' => $job->id]);
    }

    public function release(QueuedJob $job, int $delay = 0): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->table} SET reserved_at = NULL, available_at = :available WHERE id = :id"
        );
        $stmt->execute(['available' => time() + max(0, $delay), 'id' => $job->id]);
    }

    public function fail(QueuedJob $job, Throwable $e): void
    {
        $this->transaction(function () use ($job, $e): void {
            $insert = $this->pdo->prepare(
                "INSERT INTO {$this->failedTable} (queue, job_class, payload, attempts, exception, failed_at)
                 VALUES (:queue, :class, :payload, :attempts, :exception, :failed)"
            );
            $insert->execute([
                'queue'     => $job->queue,
                'class'     => $job->class,
                'payload'   => json_encode($job->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'attempts'  => $job->attempts,
                'exception' => substr((string) $e, 0, 10000),
                'failed'    => time(),
            ]);

            $this->delete($job);
        });
    }

    public function size(string $queue = 'default'): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table} WHERE queue = :queue");
        $stmt->execute(['queue' => $queue]);

        return (int) $stmt->fetchColumn();
    }

    public function failed(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, queue, job_class, attempts, exception, failed_at
             FROM {$this->failedTable} ORDER BY id DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id'        => (int) $row['id'],
                'queue'     => (string) $row['queue'],
                'job_class' => (string) $row['job_class'],
                'attempts'  => (int) $row['attempts'],
                'exception' => (string) $row['exception'],
                'failed_at' => (int) $row['failed_at'],
            ];
        }

        return $rows;
    }

    public function retry(string|int $id): bool
    {
        $select = $this->pdo->prepare(
            "SELECT queue, job_class, payload FROM {$this->failedTable} WHERE id = :id"
        );
        $select->execute(['id' => $id]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return false;
        }

        $this->transaction(function () use ($row, $id): void {
            $now    = time();
            $insert = $this->pdo->prepare(
                "INSERT INTO {$this->table} (queue, job_class, payload, attempts, available_at, reserved_at, created_at)
                 VALUES (:queue, :class, :payload, 0, :available, NULL, :created)"
            );
            $insert->execute([
                'queue'     => $row['queue'],
                'class'     => $row['job_class'],
                'payload'   => $row['payload'],
                'available' => $now,
                'created'   => $now,
            ]);

            $delete = $this->pdo->prepare("DELETE FROM {$this->failedTable} WHERE id = :id");
            $delete->execute(['id' => $id]);
        });

        return true;
    }

    public function retryAll(): int
    {
        $ids = $this->pdo->query("SELECT id FROM {$this->failedTable} ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;

        foreach ($ids as $id) {
            if ($this->retry((int) $id)) {
                $count++;
            }
        }

        return $count;
    }

    public function flushFailed(): int
    {
        return (int) $this->pdo->exec("DELETE FROM {$this->failedTable}");
    }

    private function transaction(callable $callback): void
    {
        $owns = !$this->pdo->inTransaction();

        if ($owns) {
            $this->pdo->beginTransaction();
        }

        try {
            $callback();

            if ($owns) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
