<?php

namespace App\Billing;

use App\Enums\PlatformRole;
use App\Mail\SubscriptionNoticeMail;
use App\Models\SubscriptionOrder;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Emails dos pedidos de subscrição: à equipa de faturação da plataforma (faturação e super
 * administradores) e a quem fez o pedido (ou ao email da empresa).
 */
final class BillingNotifier
{
    public static function money(int $cents): string
    {
        // Como no ecrã (pt-PT): separador de milhares só a partir de 10 000 €.
        return number_format($cents / 100, 2, ',', abs($cents) >= 1_000_000 ? ' ' : '').' €';
    }

    public static function cycle(string $cycle): string
    {
        return $cycle === 'yearly' ? 'anual' : 'mensal';
    }

    public function orderCreated(SubscriptionOrder $order): void
    {
        $order->loadMissing(['tenant', 'plan', 'coupon', 'requestedBy']);
        $what = $this->what($order);

        $this->toStaff($order, "Pedido de subscrição n.º {$order->id} — {$order->tenant->name}", 'Novo pedido de subscrição', [
            "A {$order->tenant->name} pediu {$what}.",
            'Total: '.self::money($order->total_cents).' sem IVA'.($order->discount_cents > 0 ? ' (preço '.self::money($order->list_price_cents).", cupão {$order->coupon?->code}: −".self::money($order->discount_cents).')' : '').'.',
            'Pedido por '.($order->requestedBy?->name ?? '—').' ('.($order->requestedBy?->email ?? $order->tenant->email ?? '—').').',
            'Depois de emitir a fatura no programa certificado e de receber o pagamento, confirme o pedido no backoffice: o período só começa aí.',
        ]);

        $this->toRequester($order, "Recebemos o seu pedido n.º {$order->id}", 'Pedido de subscrição recebido', [
            "Pediu {$what}, no valor de ".self::money($order->total_cents).' sem IVA.',
            ...PaymentDetails::lines($order),
        ]);
    }

    public function upgradeApplied(SubscriptionOrder $order): void
    {
        $order->loadMissing(['tenant', 'plan', 'fromPlan', 'requestedBy']);
        $until = $order->period_ends_at?->timezone('Europe/Lisbon')->format('d/m/Y');

        $this->toStaff($order, "Subida de plano a faturar — {$order->tenant->name}", 'Subida de plano a faturar', [
            "A {$order->tenant->name} passou do plano {$order->fromPlan?->name} para o {$order->plan->name}.",
            'Diferença a faturar até '.$until.': '.self::money($order->total_cents).' sem IVA (pedido n.º '.$order->id.').',
            'Depois de faturar, marque o pedido como confirmado no backoffice.',
        ]);

        $this->toRequester($order, "Plano alterado para {$order->plan->name}", 'Plano alterado', [
            "A {$order->tenant->name} passou para o plano {$order->plan->name}, já em vigor.",
            'A diferença até '.$until.', '.self::money($order->total_cents).' sem IVA, vai ser faturada pela equipa ObraConnect.',
            ...PaymentDetails::lines($order),
        ]);
    }

    public function orderConfirmed(SubscriptionOrder $order): void
    {
        $order->loadMissing(['tenant', 'plan']);
        $from = $order->period_starts_at?->timezone('Europe/Lisbon')->format('d/m/Y');
        $to = $order->period_ends_at?->timezone('Europe/Lisbon')->format('d/m/Y');
        if (! $order->needsPayment()) {
            return;
        }

        $this->toRequester($order, "Subscrição {$order->plan->name} confirmada", 'Subscrição confirmada', [
            "O pagamento do pedido n.º {$order->id} foi confirmado.",
            "Plano {$order->plan->name} (".self::cycle($order->billing_cycle).") de {$from} a {$to}.",
        ]);
    }

    public function orderCanceled(SubscriptionOrder $order, ?string $reason): void
    {
        $order->loadMissing(['tenant', 'plan']);
        $this->toRequester($order, "Pedido n.º {$order->id} cancelado", 'Pedido cancelado', array_values(array_filter([
            "O pedido n.º {$order->id} ({$this->what($order)}) foi cancelado pela equipa ObraConnect.",
            $reason ? "Motivo: {$reason}" : null,
        ])));
    }

    /**
     * Aviso de segurança: o IBAN ou o titular mostrados às empresas mudou.
     *
     * @param  array<string, ?string>  $before
     * @param  array<string, ?string>  $after
     */
    public function paymentDetailsChanged(User $by, array $before, array $after): void
    {
        $lines = array_values(array_filter([
            "{$by->name} ({$by->email}) alterou os dados de pagamento mostrados às empresas a ".now()->timezone('Europe/Lisbon')->format('d/m/Y H:i').'.',
            $before['iban'] !== $after['iban'] ? 'IBAN: antes '.PaymentDetails::mask($before['iban']).', agora '.PaymentDetails::mask($after['iban']).'.' : null,
            $before['account_holder'] !== $after['account_holder'] ? 'Titular: antes '.($before['account_holder'] ?? '—').', agora '.($after['account_holder'] ?? '—').'.' : null,
            'Se não foi alguém da equipa, reponha os dados e mude as palavras-passe: um IBAN trocado desvia os pagamentos das empresas.',
        ]));
        foreach ($this->staffEmails() as $email) {
            Mail::to($email)->queue(new SubscriptionNoticeMail('Dados de pagamento alterados', 'Dados de pagamento alterados', $lines,
                url('/plataforma/pagamentos'), 'Ver os dados de pagamento', 'Recebe este aviso por fazer parte da equipa de faturação da plataforma.'));
        }
    }

    /** @return Collection<int, string> */
    private function staffEmails(): Collection
    {
        return User::query()->whereIn('platform_role', [PlatformRole::Billing->value, PlatformRole::SuperAdmin->value])->pluck('email');
    }

    private function what(SubscriptionOrder $order): string
    {
        $kind = $order->kind === 'renewal' ? 'a renovação' : ($order->kind === 'upgrade' ? 'a subida' : 'a ativação');

        return "{$kind} do plano {$order->plan->name} (".self::cycle($order->billing_cycle).')';
    }

    /** @param  list<string>  $lines */
    private function toStaff(SubscriptionOrder $order, string $subject, string $heading, array $lines): void
    {
        foreach ($this->staffEmails() as $email) {
            Mail::to($email)->queue(new SubscriptionNoticeMail($subject, $heading, $lines, url('/plataforma/pedidos'), 'Abrir os pedidos',
                'Recebe este aviso por fazer parte da equipa de faturação da plataforma.'));
        }
    }

    /** @param  list<string>  $lines */
    private function toRequester(SubscriptionOrder $order, string $subject, string $heading, array $lines): void
    {
        $email = $order->requestedBy?->email ?? $order->tenant->email;
        if (blank($email)) {
            return;
        }
        Mail::to($email)->queue(new SubscriptionNoticeMail($subject, $heading, $lines, url('/app/subscricao'), 'Ver a subscrição',
            "Recebe este aviso sobre a subscrição da {$order->tenant->name}."));
    }
}
