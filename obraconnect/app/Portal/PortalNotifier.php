<?php

namespace App\Portal;

use App\Mail\PortalNoticeMail;
use App\Models\Certificate;
use App\Models\PartnerPortalUser;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;

/** Avisos às pessoas do subempreiteiro com acesso ao portal (auto aprovado ou devolvido). */
final class PortalNotifier
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function certificateDecided(Certificate $certificate, string $decision): void
    {
        $contract = $certificate->contract()->with('partner:id,name', 'project:id,code,name')->first();
        if ($contract === null) {
            return;
        }
        $people = PartnerPortalUser::query()->where('partner_id', $contract->partner_id)->whereNotNull('accepted_at')->whereNull('revoked_at')->get();
        if ($people->isEmpty()) {
            return;
        }

        $company = $this->tenants->tenantOrFail()->name;
        $amount = number_format($certificate->amount_cents / 100, 2, ',', ' ').' €';
        $approved = $decision === 'approved';
        foreach ($people as $person) {
            Mail::to($person->email)->queue(new PortalNoticeMail(
                $company,
                $approved ? "Auto n.º {$certificate->number} aprovado — {$contract->code}" : "Auto n.º {$certificate->number} devolvido — {$contract->code}",
                $approved ? 'Auto aprovado' : 'Auto devolvido para corrigir',
                [
                    "O auto n.º {$certificate->number} do contrato {$contract->code} · {$contract->title} (obra {$contract->project->code} · {$contract->project->name}), de "
                        .$certificate->period_start->format('d/m/Y').' a '.$certificate->period_end->format('d/m/Y').", no valor de {$amount} sem IVA,",
                    $approved
                        ? 'foi aprovado pela '.$company.'. Pode emitir a fatura deste auto.'
                        : 'foi devolvido pela '.$company.' para corrigir. A empresa vai rever as quantidades; se precisar, fale com o diretor de obra.',
                ],
                url("/portal/{$person->id}/contratos/{$contract->id}"),
                'Abrir no portal',
            ));
        }
    }
}
