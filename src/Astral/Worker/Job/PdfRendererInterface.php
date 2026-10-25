<?php

declare(strict_types=1);

namespace Astral\Worker\Job;

/** À implémenter côté application (dompdf, mpdf…) — voir examples/DompdfRenderer.php. */
interface PdfRendererInterface
{
    /**
     * @param array<string, mixed> $data
     * @return string Contenu binaire du PDF
     */
    public function render(string $template, array $data = []): string;
}
