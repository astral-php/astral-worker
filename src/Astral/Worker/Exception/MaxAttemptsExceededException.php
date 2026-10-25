<?php

declare(strict_types=1);

namespace Astral\Worker\Exception;

/** Le job a dépassé son nombre de tentatives (ex. : worker tué en cours de traitement). */
final class MaxAttemptsExceededException extends \RuntimeException
{
}
