<?php

namespace App\Models;

use App\Enums\MediaStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fotografia de obra. Ficheiros no disco privado, servidos só por MediaController
 * depois de verificar o acesso. Criar e apagar passa sempre por App\Media\MediaStorage,
 * que mantém o espaço ocupado da empresa certo.
 */
#[Fillable(['caption', 'sort_order', 'location_id', 'element_id'])]
class Media extends Model
{
    use BelongsToTenant;

    protected $table = 'media';

    protected $attributes = [
        'kind' => 'photo',
        'status' => 'processing',
    ];

    protected function casts(): array
    {
        return [
            'status' => MediaStatus::class,
            'size_bytes' => 'integer',
            'derived_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'taken_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function diaryEntry(): BelongsTo
    {
        return $this->belongsTo(DiaryEntry::class);
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function inspectionItem(): BelongsTo
    {
        return $this->belongsTo(InspectionItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ProjectLocation::class, 'location_id');
    }

    public function element(): BelongsTo
    {
        return $this->belongsTo(ConstructionElement::class, 'element_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** Pasta com o original e as versões reduzidas. */
    public function directory(): string
    {
        return dirname($this->original_path);
    }
}
