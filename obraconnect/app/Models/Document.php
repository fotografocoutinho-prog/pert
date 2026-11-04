<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Documento da obra. O conteúdo está nas versões (imutáveis); a atual é a de número mais alto.
 * Criar versões e apagar passa por App\Documents\DocumentStorage (quota da empresa).
 */
#[Fillable(['title', 'description', 'folder_id', 'location_id', 'element_id', 'specialty_id'])]
class Document extends Model
{
    use BelongsToTenant;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('number');
    }

    public function currentVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class)->ofMany('number', 'max');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ProjectLocation::class, 'location_id');
    }

    public function element(): BelongsTo
    {
        return $this->belongsTo(ConstructionElement::class, 'element_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Diretório com todas as versões. */
    public function directory(): string
    {
        return "tenants/{$this->tenant_id}/projects/{$this->project_id}/documents/{$this->id}";
    }
}
