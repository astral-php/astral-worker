<?php

declare(strict_types=1);

namespace Astral\Worker;

final class WorkerOptions
{
    /**
     * @param int  $sleep         Pause (s) quand la file est vide
     * @param int  $maxJobs       Arrêt après N jobs (0 = illimité)
     * @param int  $maxTime       Arrêt après N secondes (0 = illimité)
     * @param bool $stopWhenEmpty Quitter dès que la file est vide (mode cron)
     * @param int  $memoryLimitMb Arrêt si la mémoire dépasse N Mo (0 = pas de limite)
     * @param int  $tries         Tentatives par défaut (surchargeable par job : const TRIES)
     * @param int  $backoff       Délai de base en s avant nouvelle tentative (const BACKOFF)
     */
    public function __construct(
        public readonly int $sleep = 3,
        public readonly int $maxJobs = 0,
        public readonly int $maxTime = 0,
        public readonly bool $stopWhenEmpty = false,
        public readonly int $memoryLimitMb = 128,
        public readonly int $tries = 3,
        public readonly int $backoff = 30,
    ) {
    }
}
