<?php

namespace App\Console\Commands;

use App\Models\WebhookDelivery;
use App\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('obraconnect:prune-webhooks {--days=30}')]
#[Description('Apaga o registo de envios de webhooks já terminados com mais de 30 dias')]
class PruneWebhookDeliveries extends Command
{
    public function handle(TenantContext $tenants): int
    {
        $days = max(1, (int) $this->option('days'));
        $count = $tenants->runWithoutTenant(function () use ($days): int {
            $total = 0;
            do {
                $ids = WebhookDelivery::query()->where('status', '!=', 'pending')
                    ->where('created_at', '<', now()->subDays($days))->limit(1000)->pluck('id');
                $total += $ids->isEmpty() ? 0 : WebhookDelivery::query()->whereIn('id', $ids)->delete();
            } while ($ids->count() === 1000);

            return $total;
        });

        $this->info("Envios de webhooks antigos apagados: {$count}");

        return self::SUCCESS;
    }
}
