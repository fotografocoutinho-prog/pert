<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Linha do historial de uma ocorrência: comentário, mudança de estado, de responsável ou de
 * prazo, escalonamento. Só se acrescenta (sai apenas com a ocorrência).
 */
class IssueEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const LABELS = [
        'created' => 'Registada',
        'comment' => 'Comentário',
        'start' => 'Em resolução',
        'resolve' => 'Resolvida',
        'close' => 'Validada e fechada',
        'reopen' => 'Reaberta',
        'cancel' => 'Anulada',
        'assigned' => 'Responsável alterado',
        'due_changed' => 'Prazo alterado',
        'escalated' => 'Prazo ultrapassado: escalonada',
    ];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('O historial das ocorrências não se altera.'));
        static::deleting(fn () => throw new LogicException('O historial das ocorrências não se apaga.'));
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function add(Issue $issue, string $type, ?User $by, ?string $body = null, ?array $payload = null): self
    {
        $event = new self;
        $event->forceFill([
            'tenant_id' => $issue->tenant_id,
            'issue_id' => $issue->id,
            'type' => $type,
            'user_id' => $by?->id,
            'user_name' => $by?->name,
            'body' => $body,
            'payload' => $payload,
            'created_at' => now(),
        ])->save();

        return $event;
    }
}
