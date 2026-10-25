<?php

declare(strict_types=1);

namespace Astral\Worker\Exception;

/** Classe de job introuvable ou invalide : l'échec est immédiat (pas de retry). */
final class JobNotFoundException extends \RuntimeException
{
}
