<?php

namespace App\Inspections;

use App\Enums\InspectionResult;
use App\Models\Inspection;
use App\Models\InspectionEvent;
use App\Models\InspectionItem;
use App\Models\Media;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Relatório da inspeção em PDF (A4), gerado no momento a partir do que está guardado: pontos,
 * resultados, fotografias (miniaturas), índice de evidências, decisão, aprovações e o histórico
 * encadeado com o código de verificação (hash do último passo). Não é guardado em lado nenhum.
 */
final class InspectionReport
{
    /** Fotografias por ponto no relatório (as restantes são indicadas pelo número). */
    private const PHOTOS_PER_ITEM = 6;

    public function __construct(private readonly TenantContext $tenants) {}

    public function render(Project $project, Inspection $inspection, User $by): string
    {
        $html = view('reports.inspection', $this->data($project, $inspection, $by))->render();

        $dir = storage_path('app/dompdf');
        File::ensureDirectoryExists($dir);
        $options = new Options;
        // Nada de fora: as imagens vão embutidas, não há PHP nem endereços remotos no modelo.
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

    public function filename(Project $project, Inspection $inspection): string
    {
        return 'inspecao-'.preg_replace('/[^A-Za-z0-9-]+/', '-', $project->code).'-'.$inspection->number.'.pdf';
    }

    /** @return array<string, mixed> */
    private function data(Project $project, Inspection $inspection, User $by): array
    {
        $inspection->loadMissing([
            'items.photos', 'items.answeredBy:id,name', 'items.issues', 'events', 'specialty', 'template:id,name',
            'requestedBy:id,name', 'inspector:id,name', 'decidedBy:id,name', 'holdReleasedBy:id,name', 'reinspectionOf:id,number',
            'location', 'element',
        ]);
        $tenant = $this->tenants->tenantOrFail();
        $events = $inspection->events;
        $broken = InspectionHistory::firstBroken($events);
        $evidence = EvidenceIndex::evaluate($inspection);

        return [
            'company' => [
                'name' => $tenant->legal_name ?: $tenant->name,
                'nif' => $tenant->nif,
                'address' => collect([$tenant->address_line1, $tenant->address_line2, trim(($tenant->postal_code ?? '').' '.($tenant->city ?? ''))])->filter()->implode(', '),
                'logo' => $this->logo($tenant->logo_path),
            ],
            'project' => [
                'code' => $project->code,
                'name' => $project->name,
                'address' => collect([$project->address, $project->city])->filter()->implode(', '),
            ],
            'inspection' => $inspection,
            'closed' => $inspection->status->isClosed(),
            'place' => $this->place($inspection),
            'dates' => array_filter([
                'Pedida' => $this->who($inspection->requestedBy?->name, $inspection->requested_at),
                'Marcada para' => $inspection->scheduled_for ? $this->date($inspection->scheduled_for) : null,
                'Feita por' => $this->who($inspection->inspector?->name, $inspection->started_at),
                'Submetida' => $inspection->submitted_at ? $this->date($inspection->submitted_at) : null,
                'Decidida' => $this->who($inspection->decidedBy?->name, $inspection->decided_at),
                'Ponto de paragem libertado' => $this->who($inspection->holdReleasedBy?->name, $inspection->hold_released_at),
            ]),
            'evidence' => $evidence,
            'items' => $inspection->items->values()->map(fn (InspectionItem $item, int $i) => [
                'number' => $i + 1,
                'text' => $item->text,
                'guidance' => $item->guidance,
                'requirements' => $this->requirements($item),
                'result' => $item->result,
                'result_label' => $item->result?->label() ?? 'Por verificar',
                'result_class' => match ($item->result) {
                    InspectionResult::Ok => 'ok',
                    InspectionResult::Nok => 'nok',
                    default => 'na',
                },
                'measure' => $item->measure_value === null ? null : $this->number($item->measure_value).($item->measure_unit ? " {$item->measure_unit}" : ''),
                'out_of_tolerance' => $item->isOutOfTolerance(),
                'notes' => $item->notes,
                'answered' => $this->who($item->answeredBy?->name, $item->answered_at),
                'photos' => $item->photos->take(self::PHOTOS_PER_ITEM)->map(fn (Media $m) => $this->image($m))->filter()->values()->all(),
                'photos_total' => $item->photos->count(),
                'issues' => $item->issues->map(fn ($issue) => "{$issue->reference()} ({$issue->status->label()})")->all(),
            ])->all(),
            'approvals' => Approvals::currentRound($inspection)->map(fn (InspectionEvent $e) => [
                'who' => $e->user_name,
                'when' => $this->date($e->created_at),
                'reservations' => $e->action === 'approve_with_reservations',
                'reason' => $e->reason,
            ])->all(),
            'events' => $events->map(fn (InspectionEvent $e) => [
                'label' => InspectionWorkflow::eventLabel($e->action)
                    .(($e->payloadData()['of'] ?? 1) > 1 ? " ({$e->payloadData()['step']} de {$e->payloadData()['of']})" : ''),
                'who' => $e->user_name,
                'when' => $this->date($e->created_at),
                'reason' => $e->reason,
                'hash' => $e->hash,
            ])->all(),
            'chain' => ['valid' => $broken === null, 'broken_at' => $broken, 'last_hash' => $events->last()?->hash],
            'generated' => ['at' => $this->date(now()), 'by' => $by->name],
        ];
    }

    private function place(Inspection $inspection): ?string
    {
        $location = $inspection->location;
        $path = [];
        $guard = 0;
        while ($location instanceof ProjectLocation && $guard++ < 10) {
            array_unshift($path, $location->name);
            $location = $location->parent_id ? ProjectLocation::query()->find($location->parent_id) : null;
        }
        $element = $inspection->element ? trim($inspection->element->code.' · '.($inspection->element->name ?? $inspection->element->element_type->label())) : null;

        return collect([$element, $path ? implode(' › ', $path) : null])->filter()->implode(' — ') ?: null;
    }

    /** @return list<string> */
    private function requirements(InspectionItem $item): array
    {
        $out = [];
        if ($item->requires_photo) {
            $out[] = $item->min_photos > 1 ? "{$item->min_photos} fotografias" : 'Fotografia';
        }
        if ($item->requires_measure) {
            $min = $item->measure_min !== null ? $this->number($item->measure_min) : null;
            $max = $item->measure_max !== null ? $this->number($item->measure_max) : null;
            $range = $min !== null && $max !== null ? ": {$min} a {$max}" : ($min !== null ? ": mínimo {$min}" : ($max !== null ? ": máximo {$max}" : ''));
            $out[] = 'Medição'.($item->measure_unit ? " ({$item->measure_unit})" : '').$range;
        }

        return $out;
    }

    /** Miniatura embutida (sem miniatura ainda: fica de fora e conta no total). */
    private function image(Media $media): ?string
    {
        $path = $media->thumb_path;
        $disk = Storage::disk($media->disk);
        if ($path === null || ! $disk->exists($path)) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($disk->get($path));
    }

    private function logo(?string $path): ?string
    {
        $disk = Storage::disk(config('obraconnect.files.disk'));
        if ($path === null || ! $disk->exists($path)) {
            return null;
        }
        $mime = $disk->mimeType($path) ?: 'image/png';

        // O dompdf não lê WebP: sem logótipo em vez de um erro.
        return in_array($mime, ['image/png', 'image/jpeg'], true) ? "data:{$mime};base64,".base64_encode($disk->get($path)) : null;
    }

    private function number(string|float $value): string
    {
        $formatted = number_format((float) $value, 4, ',', ' ');

        return rtrim(rtrim($formatted, '0'), ',');
    }

    private function date(CarbonInterface $date): string
    {
        return $date->copy()->timezone('Europe/Lisbon')->format('d/m/Y H:i');
    }

    private function who(?string $name, ?CarbonInterface $at): ?string
    {
        if ($name === null && $at === null) {
            return null;
        }

        return trim(($name ?? '—').($at ? ', '.$this->date($at) : ''));
    }
}
