<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Escreve no registo de auditoria. Nunca grava segredos.
 */
class Auditor
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'current_password', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes', 'token', 'token_hash', 'secret',
    ];

    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  array<string, mixed>  $properties
     * @param  int|false|null  $tenantId  null = empresa do contexto; false = sem empresa
     */
    public function record(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        int|false|null $tenantId = null,
        ?User $actor = null,
        ?string $actorType = null,
    ): AuditLog {
        $actor ??= Auth::user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditLog::query()->create([
            'tenant_id' => $tenantId === false ? null : ($tenantId ?? $this->tenants->id()),
            'actor_user_id' => $actor?->getKey(),
            'actor_type' => $actorType ?? $this->actorType($actor),
            'event' => $event,
            'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'properties' => $this->sanitize($properties) ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'request_id' => Context::get('request_id'),
        ]);
    }

    /**
     * Diferenças entre valores antigos e novos de um modelo, sem campos sensíveis.
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public function changes(Model $model): array
    {
        $new = $model->getChanges();
        unset($new['updated_at']);
        $old = array_intersect_key($model->getOriginal(), $new);

        return ['old' => $this->sanitize($old), 'new' => $this->sanitize($new)];
    }

    private function actorType(?User $actor): string
    {
        if ($actor === null) {
            return 'system';
        }

        if ($this->tenants->isSupportSession()) {
            return 'support';
        }

        return request()?->is('api/v1/platform/*') ? 'platform' : 'user';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array((string) $key, self::SENSITIVE_KEYS, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            } elseif ($value instanceof \BackedEnum) {
                $data[$key] = $value->value;
            } elseif ($value instanceof \DateTimeInterface) {
                $data[$key] = $value->format(DATE_ATOM);
            }
        }

        return $data;
    }
}
