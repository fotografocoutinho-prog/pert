<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha da composição de um artigo: um recurso e o seu rendimento.
 */
#[Fillable(['resource_id', 'quantity', 'sort_order'])]
class ArticleComponent extends Model
{
    use BelongsToTenant;

    protected $table = 'article_components';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(PriceResource::class, 'resource_id');
    }
}
