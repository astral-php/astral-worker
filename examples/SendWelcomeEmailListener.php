<?php

declare(strict_types=1);

// À placer dans app/Listeners/SendWelcomeEmail.php (remplace la version synchrone).
//
// ⚠ Reprenez les `use` de EventInterface / ListenerInterface / UserRegistered
//   tels qu'ils figurent dans VOTRE listener existant : seuls le constructeur et handle() changent.

namespace App\Listeners;

use App\Jobs\SendMailJob; // ou Astral\Worker\Job\SendMailJob
use Astral\Worker\Queue\QueueInterface;

final class SendWelcomeEmail /* implements ListenerInterface */
{
    public function __construct(private QueueInterface $queue)
    {
    }

    public function handle(/* EventInterface */ $event): void
    {
        // Avant : $this->mailer->send(...) → bloquait la requête HTTP pendant le SMTP.
        // Après : une simple insertion en file (quelques ms).
        $this->queue->push(\Astral\Worker\Job\SendMailJob::class, [
            'to'      => $event->user->email,
            'subject' => 'Bienvenue !',
            'body'    => '…',
        ]);
    }
}
