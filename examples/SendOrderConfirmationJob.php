<?php

declare(strict_types=1);

// À placer dans app/Jobs/SendOrderConfirmationJob.php
// Exemple pour astral-shop : le payload ne contient que l'ID, les données sont rechargées au traitement.
// Adaptez OrderDao / les champs d'Order à votre modèle.

namespace App\Jobs;

use App\Dao\OrderDao;
use Astral\Worker\Job\JobInterface;
use Core\Mailer\Mailer;

final class SendOrderConfirmationJob implements JobInterface
{
    public const TRIES   = 5;
    public const BACKOFF = 60; // 60 s, 120 s, 180 s…

    public function __construct(
        private OrderDao $orders,
        private Mailer $mailer,
    ) {
    }

    public function handle(array $payload): void
    {
        $order = $this->orders->findById((int) $payload['order_id']);

        if ($order === null) {
            return; // commande supprimée entre-temps : rien à envoyer
        }

        $this->mailer->send($order->email, 'Confirmation de commande #' . $order->id, '…');
    }
}

// Dispatch (dans un listener d'OrderPlaced) :
//   $this->queue->push(SendOrderConfirmationJob::class, ['order_id' => $event->order->id]);
