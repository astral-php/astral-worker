<?php

declare(strict_types=1);

// À placer dans app/Services/DompdfRenderer.php   (composer require dompdf/dompdf)
//
// Enregistrement (AppServiceProvider ou provider dédié) :
//   $container->singleton(
//       \Astral\Worker\Job\PdfRendererInterface::class,
//       fn ($c) => new \App\Services\DompdfRenderer($c->make(\Core\View::class))
//   );

namespace App\Services;

use Astral\Worker\Job\PdfRendererInterface;
use Core\View;
use Dompdf\Dompdf;

final class DompdfRenderer implements PdfRendererInterface
{
    public function __construct(private View $view)
    {
    }

    public function render(string $template, array $data = []): string
    {
        // renderPartial() : HTML sans layout (cf. README Astral)
        $html = $this->view->renderPartial($template, $data);

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }
}
