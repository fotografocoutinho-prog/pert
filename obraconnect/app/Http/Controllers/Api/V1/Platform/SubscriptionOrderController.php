<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Billing\SubscriptionChanges;
use App\Http\Controllers\Api\V1\SubscriptionChangeController;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pedidos de subscrição das empresas (equipa de faturação). Confirmar = a fatura foi emitida no programa
 * certificado e o pagamento recebido: ativa ou prolonga o período (as subidas de plano ficam só faturadas).
 */
class SubscriptionOrderController extends Controller
{
    public function __construct(private readonly SubscriptionChanges $changes) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'confirmed', 'canceled', 'expired', 'all'])]])['status'] ?? 'pending';
        $page = SubscriptionOrder::query()->with(['tenant:id,name,nif,email,status', 'plan', 'fromPlan', 'coupon', 'requestedBy:id,name,email', 'confirmedBy:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw("case when status = 'pending' then 0 else 1 end")->latest('id')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (SubscriptionOrder $o) => self::present($o))->all(),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'pending' => SubscriptionOrder::query()->where('status', 'pending')->count()],
        ]);
    }

    public function confirm(Request $request, SubscriptionOrder $order): JsonResponse
    {
        $data = $request->validate([
            'invoice_reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['invoice_reference' => 'n.º da fatura']);
        $order = $this->changes->confirmOrder($order, $request->user(), $data['invoice_reference'] ?? null, $data['notes'] ?? null);

        return response()->json(['data' => self::present($order->load(['tenant', 'plan', 'fromPlan', 'coupon', 'requestedBy', 'confirmedBy']))]);
    }

    public function cancel(Request $request, SubscriptionOrder $order): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']], [], ['reason' => 'motivo'])['reason'];
        $order = $this->changes->cancelOrder($order, $reason, byPlatform: true);

        return response()->json(['data' => self::present($order->load(['tenant', 'plan', 'fromPlan', 'coupon', 'requestedBy', 'confirmedBy']))]);
    }

    private static function present(SubscriptionOrder $o): array
    {
        return SubscriptionChangeController::order($o) + [
            'tenant' => $o->tenant ? ['id' => $o->tenant->id, 'name' => $o->tenant->name, 'nif' => $o->tenant->nif, 'email' => $o->tenant->email, 'status' => $o->tenant->status->value] : null,
            'from_plan' => $o->fromPlan?->name,
            'requested_by' => $o->requestedBy ? ['name' => $o->requestedBy->name, 'email' => $o->requestedBy->email] : null,
            'confirmed_by' => $o->confirmedBy?->name,
            'notes' => $o->notes,
        ];
    }
}
