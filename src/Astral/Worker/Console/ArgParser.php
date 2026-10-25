<?php

declare(strict_types=1);

namespace Astral\Worker\Console;

/**
 * Analyse tolérante des arguments : `--name=value`, `--flag`, et positionnels (clé '_').
 * Accepte aussi un tableau déjà clé => valeur.
 */
final class ArgParser
{
    /**
     * @param array<int|string, mixed> $args
     * @return array<string, mixed>
     */
    public static function parse(array $args): array
    {
        $out = ['_' => []];

        foreach ($args as $key => $value) {
            if (is_string($key)) {
                $out[ltrim($key, '-')] = $value;
                continue;
            }

            $value = (string) $value;

            if (str_starts_with($value, '--')) {
                $value = substr($value, 2);

                if (str_contains($value, '=')) {
                    [$name, $val] = explode('=', $value, 2);
                    $out[$name] = $val;
                } else {
                    $out[$value] = true;
                }

                continue;
            }

            $out['_'][] = $value;
        }

        return $out;
    }
}
