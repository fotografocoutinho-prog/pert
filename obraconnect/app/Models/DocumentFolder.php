<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'parent_id'])]
class DocumentFolder extends Model
{
    use BelongsToTenant;

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'folder_id');
    }

    /**
     * Da raiz até esta pasta (para o caminho de navegação). Poucos níveis: um pedido por nível.
     *
     * @return list<self>
     */
    public function ancestry(): array
    {
        $chain = [$this];
        $current = $this;
        while ($current->parent_id !== null && count($chain) < 20) {
            $current = $current->parent()->first();
            if ($current === null) {
                break;
            }
            array_unshift($chain, $current);
        }

        return $chain;
    }
}
