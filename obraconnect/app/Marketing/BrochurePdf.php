<?php

namespace App\Marketing;

use App\Http\Controllers\LandingController;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/** A apresentação comercial em PDF (A4), com o mesmo conteúdo da página pública e os preços do backoffice. */
final class BrochurePdf
{
    public function render(): string
    {
        $html = view('reports.brochure', LandingController::data() + ['site' => url('/')])->render();

        $dir = storage_path('app/dompdf');
        File::ensureDirectoryExists($dir);
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setTempDir($dir);
        $options->setFontCache($dir);
        $options->setChroot([resource_path('views/reports')]);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $pdf->getCanvas()->page_text(520, 815, '{PAGE_NUM} / {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.45, 0.45, 0.45]);

        return (string) $pdf->output();
    }
}
