<?php

declare(strict_types=1);

namespace Astral\Worker\Job;

/**
 * Génère un PDF et l'écrit dans $outputDir.
 * Payload : ['template' => 'invoices/pdf', 'data' => [...], 'output' => 'invoices/2026-0001.pdf']
 *
 * Pour une facture, préférez un job applicatif qui reçoit un order_id, recharge les données
 * via les DAOs puis appelle PdfRendererInterface (données toujours à jour).
 */
final class GeneratePdfJob implements JobInterface
{
    public const TRIES = 3;
    public const BACKOFF = 30;

    public function __construct(
        private PdfRendererInterface $renderer,
        private string $outputDir,
    ) {
    }

    public function handle(array $payload): void
    {
        $template = (string) ($payload['template'] ?? '');
        $output   = (string) ($payload['output'] ?? '');
        $data     = (array) ($payload['data'] ?? []);

        if ($template === '' || $output === '') {
            throw new \InvalidArgumentException('GeneratePdfJob : template et output requis.');
        }

        if (
            str_contains($output, '..')
            || str_contains($output, '\\')
            || str_starts_with($output, '/')
            || !str_ends_with(strtolower($output), '.pdf')
        ) {
            throw new \InvalidArgumentException("GeneratePdfJob : chemin de sortie refusé ({$output}).");
        }

        $path = rtrim($this->outputDir, '/\\') . '/' . $output;
        $dir  = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("GeneratePdfJob : impossible de créer {$dir}.");
        }

        $pdf = $this->renderer->render($template, $data);

        // Écriture atomique : pas de PDF à moitié écrit visible par le site
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $pdf) === false || !rename($tmp, $path)) {
            throw new \RuntimeException("GeneratePdfJob : écriture impossible ({$path}).");
        }
    }
}
