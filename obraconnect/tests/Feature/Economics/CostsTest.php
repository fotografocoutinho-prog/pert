<?php

namespace Tests\Feature\Economics;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CostsTest extends TestCase
{
    private function teamMember(User $owner, Tenant $tenant, int $project, string $role): User
    {
        $user = $this->addMember($tenant, $role);
        $membership = app(TenantContext::class)->runAs($tenant, fn () => (int) Membership::query()->where('user_id', $user->id)->value('id'));
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/members", ['membership_id' => $membership])->assertCreated();

        return $user;
    }

    /** Obra com orçamento aprovado de 10 000 € de custo (material 6000, mão de obra 4000). */
    private function projectWithBudget(): array
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia', 'starts_on' => '2026-11-02'])->json('data.id');
        $base = "/api/v1/projects/{$project}";
        $budget = $this->postJson("{$base}/budgets", ['profit_pct' => 25])->json('data.id');
        $chapter = $this->postJson("{$base}/budgets/{$budget}/chapters", ['name' => 'Obra'])->json('data.chapters.0.id');
        $this->postJson("{$base}/budgets/{$budget}/items", [
            'chapter_id' => $chapter, 'description' => 'Paredes', 'unit' => 'm2', 'quantity' => 100,
            'components' => [['kind' => 'material', 'name' => 'Tijolo', 'unit' => 'm2', 'quantity' => 1, 'unit_cost_cents' => 6000], ['kind' => 'labor', 'name' => 'Pedreiro', 'unit' => 'h', 'quantity' => 1, 'unit_cost_cents' => 4000]],
        ])->assertCreated()->assertJsonPath('data.cost_cents', 1000000);
        $this->postJson("{$base}/budgets/{$budget}/approve")->assertOk();

        return [$owner, $tenant, $project, $base];
    }

    public function test_purchases_contracts_and_cost_report_with_ceiling(): void
    {
        [$owner, $tenant, $project, $base] = $this->projectWithBudget();
        $supplier = $this->postJson('/api/v1/partners', ['kind' => 'supplier', 'name' => 'Materiais do Lis, Lda.', 'nif' => '503504564'])->assertCreated()->json('data.id');
        $sub = $this->postJson('/api/v1/partners', ['kind' => 'subcontractor', 'name' => 'Eletro Pombal', 'documents' => []])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/partners/{$sub}/documents", ['title' => 'Alvará', 'expires_on' => now()->subDay()->toDateString()])->assertCreated()
            ->assertJsonPath('data.documents.0.state', 'expired');
        $this->postJson('/api/v1/partners', ['kind' => 'supplier', 'name' => 'Repetido', 'nif' => '503504564'])->assertUnprocessable()->assertJsonValidationErrors('nif');

        // Compra: rascunho não conta; aprovada = comprometido; faturada = realizado pelo valor da fatura.
        $po = $this->postJson("{$base}/purchase-orders", ['partner_id' => $supplier, 'lines' => [['description' => 'Tijolo 30×20×15', 'unit' => 'm2', 'quantity' => 50, 'unit_price_cents' => 5800]]])
            ->assertCreated()->assertJsonPath('data.number', 1)->assertJsonPath('data.total_cents', 290000)->json('data.id');
        $this->postJson("{$base}/purchase-orders", ['partner_id' => $sub, 'lines' => []])->assertUnprocessable()->assertJsonValidationErrors('partner_id');
        $this->getJson("{$base}/costs")->assertJsonPath('data.committed_cents', 0)->assertJsonPath('data.planned_cents', 1000000)->assertJsonPath('data.ceiling_cents', 1000000);
        $this->postJson("{$base}/purchase-orders/{$po}/actions/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson("{$base}/costs")->assertJsonPath('data.committed_cents', 290000)->assertJsonPath('data.actual_cents', 0);
        $this->postJson("{$base}/purchase-orders/{$po}/actions/receive")->assertOk()->assertJsonPath('data.lines.0.received_quantity', 50);
        $this->postJson("{$base}/purchase-orders/{$po}/actions/invoice", ['invoice_number' => 'FT 2026/118', 'invoiced_on' => now()->toDateString(), 'invoiced_cents' => 295000])->assertOk();
        $this->putJson("{$base}/purchase-orders/{$po}", ['notes' => 'x'])->assertConflict();

        // Subempreitada: em curso compromete o valor; auto aprovado passa a realizado, com retenção.
        $contract = $this->postJson("{$base}/contracts", ['partner_id' => $sub, 'title' => 'Instalação elétrica', 'retention_pct' => 5, 'items' => [['description' => 'Instalação elétrica', 'unit' => 'vg', 'quantity' => 1, 'unit_price_cents' => 400000]]])
            ->assertCreated()->assertJsonPath('data.code', 'SUB-01')->assertJsonPath('data.value_cents', 400000)->json('data.id');
        $this->postJson("{$base}/contracts/{$contract}/actions/activate")->assertOk();
        $cert = $this->postJson("{$base}/certificates", ['contract_id' => $contract, 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->toDateString()])->assertCreated()->json('data');
        $this->putJson("{$base}/certificates/{$cert['id']}/lines/{$cert['lines'][0]['id']}", ['qty_period' => 0.5])->assertOk()
            ->assertJsonPath('data.amount_cents', 200000)->assertJsonPath('data.retention_cents', 10000)->assertJsonPath('data.net_cents', 190000);
        $this->postJson("{$base}/certificates/{$cert['id']}/submit")->assertOk();
        $this->postJson("{$base}/certificates/{$cert['id']}/approve")->assertOk();

        // Custo manual e teto: previsão 2950 + 2000 + 2000 (falta do contrato) + 4000 = 10950 > 10000.
        $this->postJson("{$base}/cost-entries", ['category' => 'other', 'kind' => 'actual', 'description' => 'Licença de ocupação da via', 'amount_cents' => 400000, 'incurred_on' => now()->toDateString()])->assertCreated();
        $report = $this->getJson("{$base}/costs")->assertOk()->json('data');
        $this->assertSame(295000 + 200000 + 400000, $report['actual_cents']);
        $this->assertSame(200000, $report['committed_cents']);
        $this->assertSame('over', $report['alert']);
        $this->assertSame(295000, collect($report['by_category'])->firstWhere('category', 'material')['actual_cents']);
        $this->assertSame(200000, collect($report['by_category'])->firstWhere('category', 'subcontract')['committed_cents']);
        // Venda 12 500 €; margem prevista = 12 500 − 10 950.
        $this->assertSame(1250000 - 1095000, $report['margin_forecast_cents']);
        $this->putJson("{$base}/costs/ceiling", ['cost_ceiling_cents' => 1200000])->assertOk()->assertJsonPath('data.alert', 'warning')->assertJsonPath('data.ceiling_is_custom', true);

        // Painel financeiro e permissões: o administrativo vê; o encarregado não.
        $this->actingAs($this->addMember($tenant, 'administrativo'))->getJson('/api/v1/finance')->assertOk()->assertJsonPath('data.projects.0.actual_cents', 895000);
        $foreman = $this->teamMember($owner, $tenant, $project, 'encarregado');
        $this->actingAs($foreman)->getJson("{$base}/costs")->assertForbidden();
        $this->actingAs($foreman)->getJson('/api/v1/finance')->assertForbidden();
    }

    public function test_time_entries_equipment_and_payroll(): void
    {
        Storage::fake('private');
        [$owner, $tenant, $project, $base] = $this->projectWithBudget();
        $foreman = $this->teamMember($owner, $tenant, $project, 'encarregado');
        $worker = $this->postJson('/api/v1/workers', ['name' => 'Rui Costa', 'nif' => '232012610', 'category' => 'Pedreiro de 1.ª', 'hourly_cost_cents' => 1200])->assertCreated()->json('data.id');
        $own = $this->postJson('/api/v1/workers', ['name' => $foreman->name, 'hourly_cost_cents' => 1500, 'user_id' => $foreman->id])->assertCreated()->json('data.id');

        // O encarregado regista a equipa; o NIF não lhe aparece.
        $this->actingAs($foreman)->getJson('/api/v1/workers')->assertOk()->assertJsonPath('data.0.nif', null);
        $day = now('Europe/Lisbon')->subDay()->toDateString();
        $this->postJson("{$base}/time-entries", ['work_date' => $day, 'entries' => [['worker_id' => $worker, 'hours' => 8, 'overtime_hours' => 2], ['worker_id' => $own, 'hours' => 8]]])->assertCreated()->assertJsonPath('data.saved', 2);
        $this->postJson("{$base}/time-entries", ['work_date' => now()->addDay()->toDateString(), 'entries' => [['worker_id' => $worker, 'hours' => 8]]])->assertUnprocessable();
        $week = $this->getJson("{$base}/time-entries?week={$day}")->assertOk()->json('data');
        $this->assertCount(2, $week['entries']);
        // 8 h + 2 h × 150 % = 11 h × 12 € = 132 €.
        $this->assertSame(13200, collect($week['entries'])->firstWhere('worker_id', $worker)['cost_cents']);

        // Só depois de aprovadas entram no custo; aprovadas não se alteram.
        $this->actingAs($owner)->getJson("{$base}/costs")->assertJsonPath('data.actual_cents', 0);
        $this->postJson("{$base}/time-entries/approve", ['ids' => array_column($week['entries'], 'id')])->assertOk()->assertJsonPath('data.updated', 2);
        $this->getJson("{$base}/costs")->assertJsonPath('data.actual_cents', 13200 + 12000);
        $this->actingAs($foreman)->postJson("{$base}/time-entries", ['work_date' => $day, 'entries' => [['worker_id' => $worker, 'hours' => 4]]])->assertUnprocessable();

        // Trabalhador com conta: só as próprias horas.
        $worker2 = $this->teamMember($owner, $tenant, $project, 'trabalhador');
        $w2 = $this->actingAs($owner)->postJson('/api/v1/workers', ['name' => $worker2->name, 'hourly_cost_cents' => 1000, 'user_id' => $worker2->id])->json('data.id');
        $this->actingAs($worker2)->postJson("{$base}/time-entries", ['work_date' => $day, 'entries' => [['worker_id' => $worker, 'hours' => 8]]])->assertUnprocessable();
        $this->postJson("{$base}/time-entries", ['work_date' => $day, 'entries' => [['worker_id' => $w2, 'hours' => 6]]])->assertCreated();
        $this->getJson("{$base}/time-entries?week={$day}")->assertJsonCount(1, 'data.entries');

        // Exportação para salários (só aprovadas) pelo administrativo.
        $csv = $this->actingAs($this->addMember($tenant, 'administrativo'))->get('/api/v1/time-entries/payroll?month='.substr($day, 0, 7))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Rui Costa";232012610', $csv);
        $this->assertStringNotContainsString($worker2->name, $csv);

        // Equipamento: em obra, horas com custo, QR e manutenção.
        $this->actingAs($owner);
        $eq = $this->postJson('/api/v1/equipment', ['code' => 'GR-01', 'name' => 'Grua torre', 'hourly_cost_cents' => 4500])->assertCreated()->assertJsonPath('data.status', 'available')->json('data');
        $this->putJson("/api/v1/equipment/{$eq['id']}", ['project_id' => $project])->assertOk()->assertJsonPath('data.status', 'in_use');
        $this->actingAs($foreman)->postJson("{$base}/equipment-usages", ['equipment_id' => $eq['id'], 'used_on' => $day, 'hours' => 6])->assertCreated()->assertJsonPath('data.cost_cents', 27000);
        $this->actingAs($owner)->getJson("{$base}/costs")->assertJsonPath('data.by_category.2.actual_cents', 27000);
        $this->get("/api/v1/equipment/{$eq['id']}/qr")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $token = basename($eq['qr_url']);
        $this->getJson("/api/v1/equipment/qr/{$token}")->assertOk()->assertJsonPath('data.code', 'GR-01');
        $this->postJson("/api/v1/equipment/{$eq['id']}/maintenance", ['performed_on' => $day, 'description' => 'Revisão anual', 'next_due_on' => now()->addYear()->toDateString()])->assertCreated()
            ->assertJsonPath('data.maintenance.0.description', 'Revisão anual');
        $this->deleteJson("/api/v1/equipment/{$eq['id']}")->assertConflict();
        $this->deleteJson("/api/v1/workers/{$worker}")->assertConflict()->assertJsonPath('code', 'WORKER_IN_USE');
    }

    public function test_planning_critical_path_pert_and_hold_points(): void
    {
        [$owner, , $project, $base] = $this->projectWithBudget();

        // A (5) → B (3, FS) ; A → C (2, SS +1) ; B, C → D (4).
        $add = fn (array $data) => collect($this->postJson("{$base}/activities", $data)->assertCreated()->json('data.activities'))->last()['id'];
        $a = $add(['name' => 'Fundações', 'duration_days' => 5, 'optimistic_days' => 4, 'likely_days' => 5, 'pessimistic_days' => 9]);
        $b = $add(['name' => 'Estrutura', 'duration_days' => 3]);
        $c = $add(['name' => 'Redes enterradas', 'duration_days' => 2]);
        $d = $add(['name' => 'Laje térrea', 'duration_days' => 4]);
        $this->postJson("{$base}/dependencies", ['predecessor_id' => $a, 'successor_id' => $b])->assertCreated();
        $this->postJson("{$base}/dependencies", ['predecessor_id' => $a, 'successor_id' => $c, 'type' => 'SS', 'lag_days' => 1])->assertCreated();
        $this->postJson("{$base}/dependencies", ['predecessor_id' => $b, 'successor_id' => $d])->assertCreated();
        $plan = $this->postJson("{$base}/dependencies", ['predecessor_id' => $c, 'successor_id' => $d])->assertCreated()->json('data');
        $this->postJson("{$base}/dependencies", ['predecessor_id' => $d, 'successor_id' => $a])->assertUnprocessable()->assertJsonValidationErrors('successor_id');

        $rows = collect($plan['activities'])->keyBy('id');
        $this->assertSame(12, $plan['duration_days']);
        $this->assertSame([0, 5], [$rows[$a]['es'], $rows[$a]['ef']]);
        $this->assertSame(8, $rows[$d]['es']);
        $this->assertTrue($rows[$a]['critical'] && $rows[$b]['critical'] && $rows[$d]['critical']);
        $this->assertFalse($rows[$c]['critical']);
        $this->assertSame(5, $rows[$c]['total_float']);
        // Começa no primeiro dia útil (2026-11-02 é segunda); A: 2 a 6 de novembro.
        $this->assertSame(['2026-11-02', '2026-11-06'], [$rows[$a]['start_on'], $rows[$a]['finish_on']]);
        // PERT: A = (4 + 20 + 9) / 6 = 5,5; B e D sem PERT contam pela duração.
        $this->assertSame(12.5, $plan['pert']['expected_days']);

        // Resumo cobre as filhas.
        $phase = $add(['name' => 'Fase 1']);
        $this->putJson("{$base}/activities/{$a}", ['parent_id' => $phase])->assertOk();
        $this->putJson("{$base}/activities/{$b}", ['parent_id' => $phase])->assertOk();
        $rows = collect($this->getJson("{$base}/planning")->json('data.activities'))->keyBy('id');
        $this->assertSame([0, 8], [$rows[$phase]['es'], $rows[$phase]['ef']]);
        $this->assertTrue($rows[$phase]['summary']);

        // Ponto de paragem por libertar: a atividade não se conclui.
        $inspections = "{$base}/inspections";
        $insp = $this->postJson($inspections, ['hold_point' => true, 'activity_id' => $b, 'items' => [['text' => 'Armaduras']]])->assertCreated()->assertJsonPath('data.activity_id', $b)->json('data.id');
        $this->postJson("{$base}/activities/{$b}/progress", ['progress_pct' => 60])->assertOk();
        $this->postJson("{$base}/activities/{$b}/progress", ['progress_pct' => 100])->assertConflict()->assertJsonPath('code', 'HOLD_POINT_PENDING');
        $this->getJson("{$base}/planning")->assertJsonPath('data.activities', fn ($list) => collect($list)->firstWhere('id', $b)['holds'][0]['id'] === $insp);
        $this->postJson("{$inspections}/{$insp}/actions/request");
        $item = $this->postJson("{$inspections}/{$insp}/actions/start")->json('data.items.0.id');
        $this->patchJson("{$inspections}/{$insp}/items/{$item}", ['result' => 'ok']);
        $this->postJson("{$inspections}/{$insp}/actions/submit");
        $this->postJson("{$inspections}/{$insp}/actions/approve")->assertOk();
        $this->postJson("{$inspections}/{$insp}/release-hold")->assertOk();
        $this->postJson("{$base}/activities/{$b}/progress", ['progress_pct' => 100])->assertOk();
        $this->deleteJson("{$base}/activities/{$b}")->assertConflict()->assertJsonPath('code', 'ACTIVITY_IN_USE');
    }
}
