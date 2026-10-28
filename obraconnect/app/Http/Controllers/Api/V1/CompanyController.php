<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Support\PortugueseNif;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function show(): CompanyResource
    {
        $this->authorize('company.view');

        return new CompanyResource($this->tenantContext()->tenantOrFail());
    }

    public function update(UpdateCompanyRequest $request): CompanyResource
    {
        $this->authorize('company.update');

        $tenant = $this->tenantContext()->tenantOrFail();
        $data = $request->validated();

        if (filled($data['nif'] ?? null)) {
            $data['nif'] = PortugueseNif::normalize($data['nif']);
        }

        $timezone = $data['timezone'] ?? null;
        unset($data['timezone']);

        $tenant->fill($data);
        if ($timezone) {
            $tenant->settings = array_replace($tenant->settings ?? [], ['timezone' => $timezone]);
        }
        $tenant->save();

        $this->auditor->record('company.updated', $tenant, $this->auditor->changes($tenant));

        return new CompanyResource($tenant);
    }

    public function updateSecurity(Request $request): CompanyResource
    {
        $this->authorize('security.manage');

        $data = $request->validate(['require_mfa' => ['required', 'boolean']]);
        $tenant = $this->tenantContext()->tenantOrFail();

        // Evita que o administrador fique bloqueado pela regra que acabou de ativar.
        if ($data['require_mfa'] && ! $request->user()->hasTwoFactorEnabled()) {
            throw ApiException::unprocessable('Ative primeiro a autenticação de dois fatores na sua conta.', 'MFA_REQUIRED_FOR_ADMIN');
        }

        $tenant->settings = array_replace($tenant->settings ?? [], ['require_mfa' => $data['require_mfa']]);
        $tenant->save();

        $this->auditor->record('company.security_updated', $tenant, ['require_mfa' => $data['require_mfa']]);

        return new CompanyResource($tenant);
    }

    /** Qualquer membro pode ver o logótipo da sua empresa. */
    public function logo(): StreamedResponse
    {
        $tenant = $this->tenantContext()->tenantOrFail();
        $disk = Storage::disk(config('obraconnect.files.disk'));

        abort_if($tenant->logo_path === null || ! $disk->exists($tenant->logo_path), 404);

        return $disk->response($tenant->logo_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadLogo(Request $request): CompanyResource
    {
        $this->authorize('company.update');

        // SVG excluído de propósito (pode conter scripts).
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:'.config('obraconnect.files.logo_max_kb'), 'dimensions:max_width=4000,max_height=4000'],
        ]);

        $tenant = $this->tenantContext()->tenantOrFail();
        $disk = Storage::disk(config('obraconnect.files.disk'));
        $file = $request->file('logo');

        $path = $disk->putFileAs(
            "tenants/{$tenant->id}/branding",
            $file,
            'logo-'.Str::random(16).'.'.$file->extension(),
        );

        $previousSize = 0;
        if ($tenant->logo_path && $disk->exists($tenant->logo_path)) {
            $previousSize = $disk->size($tenant->logo_path);
            $disk->delete($tenant->logo_path);
        }

        $tenant->logo_path = $path;
        $tenant->storage_used_bytes = max(0, $tenant->storage_used_bytes - $previousSize + $file->getSize());
        $tenant->save();

        $this->auditor->record('company.logo_updated', $tenant, ['size' => $file->getSize()]);

        return new CompanyResource($tenant);
    }

    public function deleteLogo(): CompanyResource
    {
        $this->authorize('company.update');

        $tenant = $this->tenantContext()->tenantOrFail();
        $disk = Storage::disk(config('obraconnect.files.disk'));

        if ($tenant->logo_path && $disk->exists($tenant->logo_path)) {
            $tenant->storage_used_bytes = max(0, $tenant->storage_used_bytes - $disk->size($tenant->logo_path));
            $disk->delete($tenant->logo_path);
        }

        $tenant->logo_path = null;
        $tenant->save();

        $this->auditor->record('company.logo_removed', $tenant);

        return new CompanyResource($tenant);
    }
}
