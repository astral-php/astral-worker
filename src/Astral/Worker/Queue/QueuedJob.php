<?php

declare(strict_types=1);

namespace Astral\Worker\Queue;

/** Job réservé par un worker (valeur immuable). */
final class QueuedJob
{
    /**
     * @param array<string, mixed> $payload
     * @param string               $raw     Représentation brute (utilisée par le driver Redis)
     */
    public function __construct(
        public readonly string|int $id,
        public readonly string $queue,
        public readonly string $class,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly string $raw = '',
    ) {
    }
}
