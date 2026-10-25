<?php

declare(strict_types=1);

// À placer dans app/Jobs/GenerateInvoicePdfJob.php
// Job applicatif : reçoit un order_id, recharge la commande, génère le PDF dans storage/pdf/invoices/.
// Adaptez OrderDao, le template et la numérotation à votre site.

namespace App\Jobs;

use App\Dao\OrderDao;
use Astral\Worker\Job\JobInterface;
use Astral\Worker\Job\PdfRendererInterface;

final class GenerateInvoicePdfJob implements JobInterface
{
    public const TRIES   = 3;
    public const BACKOFF = 30;

    public function __construct(
        private OrderDao $orders,
        private PdfRendererInterface $renderer,
        private string $storageDir = '', // ex. : bind explicite dans le provider (voir README)
    ) {
    }

    public function handle(array $payload): void
    {
        $order = $this->orders->findById((int) $payload['order_id']);
        if ($order === null) {
            return;
        }

        $pdf  = $this->renderer->render('invoices/pdf', ['order' => $order]);
        $path = rtrim($this->storageDir, '/\\') . '/invoices/' . $order->id . '.pdf';

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path . '.tmp', $pdf);
        rename($path . '.tmp', $path); // écriture atomique
    }
}
