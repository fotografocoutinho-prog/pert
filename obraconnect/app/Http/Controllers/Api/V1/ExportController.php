<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\ExportStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExportResource;
use App\Jobs\ExportTenantData;
use App\Models\TenantExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportação completa dos dados da empresa (portabilidade, RGPD).
 * Disponível mesmo com a empresa em modo só leitura.
 */
class ExportController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('data.export');

        return ExportResource::collection(TenantExport::query()->with('requestedBy')->latest('id')->limit(20)->get());
    }

    public function store(Request $request): ExportResource
    {
        $this->authorize('data.export');

        $running = TenantExport::query()
            ->whereIn('status', [ExportStatus::Pending->value, ExportStatus::Processing->value])
            ->exists();

        if ($running) {
            throw ApiException::conflict('Já existe uma exportação em curso.', 'EXPORT_RUNNING');
        }

        $export = TenantExport::query()->create([
            'requested_by_user_id' => $request->user()->id,
            'status' => ExportStatus::Pending,
            'disk' => config('obraconnect.files.disk'),
        ]);

        $this->auditor->record('data.export_requested', $export);

        ExportTenantData::dispatch($export->id);

        return new ExportResource($export->refresh()->load('requestedBy'));
    }

    public function download(TenantExport $tenantExport): StreamedResponse
    {
        $this->authorize('data.export');

        abort_unless($tenantExport->isDownloadable(), 404);

        $this->auditor->record('data.export_downloaded', $tenantExport);

        return Storage::disk($tenantExport->disk)->download(
            $tenantExport->path,
            'obraconnect-exportacao-'.$tenantExport->created_at->format('Ymd-His').'.zip',
        );
    }
}
