<?php

declare(strict_types=1);

namespace Astral\Worker\Job;

/**
 * Un job = une classe avec un constructeur autowiré par le Container
 * et une méthode handle() qui reçoit le payload (JSON décodé).
 *
 * Constantes optionnelles :
 *   public const TRIES   = 3;   // nombre max de tentatives
 *   public const BACKOFF = 30;  // secondes ; délai = BACKOFF × numéro de tentative
 */
interface JobInterface
{
    /** @param array<string, mixed> $payload Données scalaires/IDs uniquement */
    public function handle(array $payload): void;
}
