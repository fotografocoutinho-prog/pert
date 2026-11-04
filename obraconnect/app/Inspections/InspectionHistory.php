<?php

namespace App\Inspections;

use App\Enums\InspectionStatus;
use App\Models\Inspection;
use App\Models\InspectionEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Histórico encadeado das inspeções. Cada linha leva o hash da anterior e o seu próprio hash
 * (SHA-256 de todos os campos); alterar ou retirar uma linha parte a cadeia a partir dela.
 */
final class InspectionHistory
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Acrescenta uma linha. Chamar dentro da transação que alterou a inspeção, com a linha da
     * inspeção bloqueada (lockForUpdate), para a sequência não se repetir.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function record(
        Inspection $inspection,
        string $action,
        ?InspectionStatus $from,
        InspectionStatus $to,
        User $by,
        ?string $reason = null,
        ?array $payload = null,
    ): InspectionEvent {
        $last = InspectionEvent::query()->where('inspection_id', $inspection->id)->orderByDesc('sequence')->first();

        $event = new InspectionEvent;
        $event->forceFill([
            'tenant_id' => $inspection->tenant_id,
            'inspection_id' => $inspection->id,
            'sequence' => ($last?->sequence ?? 0) + 1,
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $by->id,
            'user_name' => Str::limit($by->name, 150, ''),
            'reason' => $reason,
            'payload' => $payload === null ? null : self::encode($payload),
            'prev_hash' => $last?->hash ?? self::GENESIS,
            // Ao segundo: é o que a base de dados guarda, e o hash tem de bater com o que se relê.
            'created_at' => now()->startOfSecond(),
        ]);
        $event->hash = self::hashOf($event);
        $event->save();

        return $event;
    }

    /**
     * Verifica a cadeia de uma inspeção. Devolve a sequência da primeira linha que não bate
     * (null = íntegra).
     *
     * @param  Collection<int, InspectionEvent>  $events  por ordem de sequência
     */
    public static function firstBroken(Collection $events): ?int
    {
        $prev = self::GENESIS;
        $expected = 1;

        foreach ($events as $event) {
            if ($event->sequence !== $expected || $event->prev_hash !== $prev || ! hash_equals($event->hash, self::hashOf($event))) {
                return $event->sequence;
            }
            $prev = $event->hash;
            $expected++;
        }

        return null;
    }

    public static function hashOf(InspectionEvent $event): string
    {
        return hash('sha256', self::encode([
            'tenant_id' => (int) $event->tenant_id,
            'inspection_id' => (int) $event->inspection_id,
            'sequence' => (int) $event->sequence,
            'action' => $event->action,
            'from_status' => $event->from_status,
            'to_status' => $event->to_status,
            'user_id' => $event->user_id === null ? null : (int) $event->user_id,
            'user_name' => $event->user_name,
            'reason' => $event->reason,
            'payload' => $event->payload,
            'prev_hash' => $event->prev_hash,
            'created_at' => $event->created_at->format('Y-m-d H:i:s'),
        ]));
    }

    /**
     * JSON com as chaves ordenadas, para o mesmo conteúdo dar sempre o mesmo texto.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function encode(array $data): string
    {
        return json_encode(self::sortKeys($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }

        return array_map(fn ($v) => is_array($v) ? self::sortKeys($v) : $v, $data);
    }
}
