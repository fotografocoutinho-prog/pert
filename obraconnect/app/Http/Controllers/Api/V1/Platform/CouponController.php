<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Audit\Auditor;
use App\Billing\Coupons;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Cupões (equipa de faturação). Não se apagam depois de usados: desativam-se. O desconto de um cupão já
 * usado não muda (os pedidos guardam o valor, mas assim o histórico fica coerente).
 */
class CouponController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Coupon::query()->withCount('redemptions')->latest('id')->limit(200)->get()->map(fn (Coupon $c) => self::present($c))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $coupon = new Coupon($data);
        $coupon->forceFill(['created_by_user_id' => $request->user()->id])->save();
        $this->auditor->record('platform.coupon_created', $coupon, ['code' => $coupon->code, 'what' => $coupon->describe()], tenantId: false);

        return response()->json(['data' => self::present($coupon->loadCount('redemptions'))], 201);
    }

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $this->validated($request, $coupon);
        $used = $coupon->redemptions()->exists();
        foreach (['code', 'percent_off', 'amount_off_cents', 'trial_extension_days'] as $field) {
            if ($used && array_key_exists($field, $data) && $data[$field] != $coupon->{$field}) {
                throw ApiException::conflict('Este cupão já foi usado: o código e o desconto não mudam. Desative-o e crie outro.', 'COUPON_IN_USE');
            }
        }
        $coupon->fill($data)->save();
        $this->auditor->record('platform.coupon_updated', $coupon, $this->auditor->changes($coupon), tenantId: false);

        return response()->json(['data' => self::present($coupon->loadCount('redemptions'))]);
    }

    public static function present(Coupon $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'description' => $c->description,
            'percent_off' => $c->percent_off,
            'amount_off_cents' => $c->amount_off_cents,
            'trial_extension_days' => $c->trial_extension_days,
            'summary' => $c->describe(),
            'plan_ids' => $c->plan_ids ?? [],
            'billing_cycles' => $c->billing_cycles ?? [],
            'max_redemptions' => $c->max_redemptions,
            'redemptions_count' => $c->redemptions_count ?? $c->redemptions()->count(),
            'valid_from' => $c->valid_from?->toIso8601String(),
            'valid_until' => $c->valid_until?->toIso8601String(),
            'is_active' => $c->is_active,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function validated(Request $request, ?Coupon $coupon): array
    {
        $request->merge(['code' => Coupons::normalize((string) $request->input('code', ''))]);
        $validator = validator($request->all(), [
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'description' => ['nullable', 'string', 'max:200'],
            'percent_off' => ['nullable', 'integer', 'min:1', 'max:100'],
            'amount_off_cents' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'trial_extension_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'plan_ids' => ['nullable', 'array'],
            'plan_ids.*' => ['integer', Rule::exists('plans', 'id')],
            'billing_cycles' => ['nullable', 'array'],
            'billing_cycles.*' => [Rule::in(['monthly', 'yearly'])],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['required', 'boolean'],
        ], [], [
            'code' => 'código', 'percent_off' => 'percentagem', 'amount_off_cents' => 'valor', 'trial_extension_days' => 'dias extra',
            'max_redemptions' => 'número de usos', 'valid_from' => 'início', 'valid_until' => 'fim',
        ]);
        $validator->after(function (Validator $v) use ($request): void {
            $set = collect(['percent_off', 'amount_off_cents', 'trial_extension_days'])->filter(fn ($f) => filled($request->input($f)));
            if ($set->count() !== 1) {
                $v->errors()->add('percent_off', 'Escolha um só: percentagem, valor de desconto ou dias extra de período experimental.');
            }
        });
        $data = $validator->validate();
        foreach (['percent_off', 'amount_off_cents', 'trial_extension_days', 'max_redemptions', 'valid_from', 'valid_until', 'description'] as $f) {
            $data[$f] = filled($data[$f] ?? null) ? $data[$f] : null;
        }
        $data['plan_ids'] = array_values(array_unique(array_map('intval', $data['plan_ids'] ?? []))) ?: null;
        $data['billing_cycles'] = array_values(array_unique($data['billing_cycles'] ?? [])) ?: null;
        if ($data['valid_until'] !== null) {
            $data['valid_until'] = Carbon::parse($data['valid_until'], 'Europe/Lisbon')->endOfDay()->utc();
        }
        if ($data['valid_from'] !== null) {
            $data['valid_from'] = Carbon::parse($data['valid_from'], 'Europe/Lisbon')->startOfDay()->utc();
        }

        return $data;
    }
}
