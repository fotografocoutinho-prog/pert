<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Audit\Auditor;
use App\Billing\BillingNotifier;
use App\Billing\PaymentDetails;
use App\Enums\PlatformRole;
use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dados de pagamento mostrados às empresas (página Subscrição e email do pedido). A equipa de
 * faturação vê; só o super administrador altera, e cada troca de IBAN ou de titular é avisada
 * por email a toda a equipa de faturação (um IBAN trocado desvia os pagamentos).
 */
class PaymentDetailsController extends Controller
{
    public function __construct(private readonly Auditor $auditor, private readonly BillingNotifier $notifier) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->merge([
            'iban' => PaymentDetails::normalizeIban($request->input('iban')),
            'bic' => is_string($request->input('bic')) ? strtoupper((string) preg_replace('/\s+/', '', $request->input('bic'))) : $request->input('bic'),
        ]);
        $data = $request->validate([
            'account_holder' => ['nullable', 'string', 'max:120', 'required_with:iban'],
            'iban' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! PaymentDetails::validIban((string) $value)) {
                    $fail('O IBAN (ou NIB) não é válido: confirme os algarismos.');
                }
            }],
            'bank' => ['nullable', 'string', 'max:120'],
            'bic' => ['nullable', 'string', 'regex:/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/'],
            'proof_email' => ['nullable', 'email', 'max:190'],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ], [
            'account_holder.required_with' => 'Indique o titular da conta.',
            'bic.regex' => 'O BIC/SWIFT tem 8 ou 11 letras e algarismos (ex.: CGDIPTPL).',
        ]);

        /** @var User $user */
        $user = $request->user();
        [$before, $after, $changed] = PaymentDetails::save($data, $user);

        if ($changed !== []) {
            $this->auditor->record('platform.payment_details_updated', null, [
                'changed' => $changed,
                'iban' => ['old' => PaymentDetails::mask($before['iban']), 'new' => PaymentDetails::mask($after['iban'])],
            ], tenantId: false);

            if (array_intersect($changed, ['iban', 'account_holder']) !== []) {
                $this->notifier->paymentDetailsChanged($user, $before, $after);
            }
        }

        return response()->json(['data' => $this->payload($user) + ['changed' => $changed]]);
    }

    private function payload(User $user): array
    {
        $d = PaymentDetails::current();
        $setting = PlatformSetting::for(PaymentDetails::KEY)?->load('updatedBy');

        return [
            'account_holder' => $d['account_holder'],
            'iban' => PaymentDetails::format($d['iban']),
            'bank' => $d['bank'],
            'bic' => $d['bic'],
            'proof_email' => $d['proof_email'],
            'instructions' => $d['instructions'],
            'default_instructions' => PaymentDetails::defaultInstructions(),
            'preview' => PaymentDetails::forCompanies(),
            'updated_at' => $setting?->updated_at?->toIso8601String(),
            'updated_by' => $setting?->updatedBy?->name,
            'can_edit' => $user->platform_role === PlatformRole::SuperAdmin,
        ];
    }
}
