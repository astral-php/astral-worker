<?php

declare(strict_types=1);

namespace Astral\Worker\Queue;

interface QueueInterface
{
    /**
     * @param array<string, mixed> $payload
     * @param int                  $delay   Secondes avant disponibilité
     * @return string|int Identifiant du job
     */
    public function push(string $jobClass, array $payload = [], int $delay = 0, string $queue = 'default'): string|int;

    /** Réserve le prochain job disponible (attempts déjà incrémenté), ou null. */
    public function pop(string $queue = 'default'): ?QueuedJob;

    /** Job terminé avec succès : le supprime. */
    public function delete(QueuedJob $job): void;

    /** Remet le job en file, disponible après $delay secondes. */
    public function release(QueuedJob $job, int $delay = 0): void;

    /** Échec définitif : déplace le job vers les jobs en échec. */
    public function fail(QueuedJob $job, \Throwable $e): void;

    public function size(string $queue = 'default'): int;

    /**
     * @return list<array{id: string|int, queue: string, job_class: string, attempts: int, exception: string, failed_at: int}>
     */
    public function failed(int $limit = 50): array;

    /** Remet un job en échec dans la file (tentatives remises à zéro). */
    public function retry(string|int $id): bool;

    /** @return int Nombre de jobs remis en file */
    public function retryAll(): int;

    /** @return int Nombre de jobs en échec supprimés */
    public function flushFailed(): int;
}
