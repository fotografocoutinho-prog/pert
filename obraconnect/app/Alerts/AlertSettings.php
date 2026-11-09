<?php

namespace App\Alerts;

use App\Models\Tenant;

/**
 * Regras dos alertas automáticos de cada empresa (em tenants.settings.alerts). O que não estiver
 * guardado vale o valor por omissão.
 */
final class AlertSettings
{
    public const DEFAULTS = [
        'enabled' => true,
        'email' => true,
        'rules' => [
            'cost_ceiling' => ['on' => true, 'pct' => 90],
            'cost_worse' => ['on' => true, 'pct' => 2.0],
            'finish_contract' => ['on' => true],
            'finish_worse' => ['on' => true, 'days' => 5],
            'measurements_stale' => ['on' => true, 'days' => 30],
            'hold_point' => ['on' => true, 'days' => 5],
            'supplier_docs' => ['on' => true, 'days' => 7],
        ],
    ];

    /** @return array{enabled: bool, email: bool, rules: array<string, array<string, bool|int|float>>} */
    public static function for(Tenant $tenant): array
    {
        $saved = $tenant->setting('alerts', []);

        return array_replace_recursive(self::DEFAULTS, is_array($saved) ? $saved : []);
    }

    /** @return array<string, list<string>> */
    public static function validationRules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'email' => ['required', 'boolean'],
            'rules' => ['required', 'array'],
            'rules.cost_ceiling.on' => ['required', 'boolean'],
            'rules.cost_ceiling.pct' => ['required', 'integer', 'min:50', 'max:100'],
            'rules.cost_worse.on' => ['required', 'boolean'],
            'rules.cost_worse.pct' => ['required', 'numeric', 'min:0.5', 'max:50'],
            'rules.finish_contract.on' => ['required', 'boolean'],
            'rules.finish_worse.on' => ['required', 'boolean'],
            'rules.finish_worse.days' => ['required', 'integer', 'min:1', 'max:60'],
            'rules.measurements_stale.on' => ['required', 'boolean'],
            'rules.measurements_stale.days' => ['required', 'integer', 'min:7', 'max:120'],
            'rules.hold_point.on' => ['required', 'boolean'],
            'rules.hold_point.days' => ['required', 'integer', 'min:1', 'max:60'],
            'rules.supplier_docs.on' => ['required', 'boolean'],
            'rules.supplier_docs.days' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }

    /**
     * Só as chaves conhecidas, com os tipos certos.
     *
     * @param  array<string, mixed>  $data  já validado
     * @return array<string, mixed>
     */
    public static function clean(array $data): array
    {
        $out = ['enabled' => (bool) $data['enabled'], 'email' => (bool) $data['email'], 'rules' => []];
        foreach (self::DEFAULTS['rules'] as $type => $defaults) {
            foreach ($defaults as $key => $default) {
                $value = $data['rules'][$type][$key];
                $out['rules'][$type][$key] = is_bool($default) ? (bool) $value : (is_int($default) ? (int) $value : round((float) $value, 1));
            }
        }

        return $out;
    }
}
