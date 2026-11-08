<?php

namespace App\Invoicing;

use App\Models\InvoiceDraft;
use App\Models\Tenant;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/** PDF da pré-fatura, sempre marcado como documento interno sem valor fiscal. */
final class InvoiceDraftPdf
{
    public function render(Tenant $tenant, InvoiceDraft $draft): string
    {
        $draft->loadMissing(['lines', 'project']);
        $html = view('reports.invoice-draft', [
            'draft' => $draft,
            'totals' => $draft->totals(),
            'company' => [
                'name' => $tenant->legal_name ?: $tenant->name,
                'nif' => $tenant->nif,
                'address' => collect([$tenant->address_line1, $tenant->address_line2, trim(($tenant->postal_code ?? '').' '.($tenant->city ?? ''))])->filter()->implode(', '),
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'logo' => $this->logo($tenant->logo_path),
            ],
            'money' => fn (int $cents) => number_format($cents / 100, 2, ',', ' ').' €',
            'number' => fn ($v, int $digits = 3) => rtrim(rtrim(number_format((float) $v, $digits, ',', ' '), '0'), ','),
        ])->render();

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
        $pdf->getCanvas()->page_text(500, 815, 'Página {PAGE_NUM} de {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.4, 0.4, 0.4]);

        return (string) $pdf->output();
    }

    public function filename(InvoiceDraft $draft): string
    {
        return 'pre-fatura-'.($draft->number ? $draft->series_year.'-'.str_pad((string) $draft->number, 4, '0', STR_PAD_LEFT) : 'rascunho-'.$draft->id).'.pdf';
    }

    private function logo(?string $path): ?string
    {
        $disk = Storage::disk(config('obraconnect.files.disk'));
        if ($path === null || ! $disk->exists($path)) {
            return null;
        }
        $mime = $disk->mimeType($path) ?: 'image/png';

        return in_array($mime, ['image/png', 'image/jpeg'], true) ? "data:{$mime};base64,".base64_encode($disk->get($path)) : null;
    }
}
