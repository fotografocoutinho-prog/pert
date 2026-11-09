<?php

namespace Tests\Feature\Economics;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ForecastTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function teamMember(User $owner, Tenant $tenant, int $project, string $role): User
    {
        $user = $this->addMember($tenant, $role);
        $membership = app(TenantContext::class)->runAs($tenant, fn () => (int) Membership::query()->where('user_id', $user->id)->value('id'));
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/members", ['membership_id' => $membership])->assertCreated();

        return $user;
    }

    /** Sem espaços inquebráveis, para comparar o texto das razões. */
    private static function texts(array $reasons): string
    {
        return str_replace("\u{00A0}", ' ', implode("\n", array_column($reasons, 'text')));
    }

    public function test_cost_forecast_from_measured_work_cost_rate_and_commitments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00', 'Europe/Lisbon'));
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia', 'status' => 'active', 'starts_on' => '2026-11-02'])->assertCreated()->json('data.id');
        $base = "/api/v1/projects/{$project}";

        // Sem orçamento aprovado não há previsão de custo.
        $this->getJson("{$base}/forecast")->assertOk()->assertJsonPath('data.cost.available', false)
            ->assertJsonPath('data.schedule.available', false);

        // Orçamento: paredes 100 m2 × 100 € (material 60 + mão de obra 40) e pilares 10 un × 50 € = 10 500 € de custo, venda +25 %.
        $budget = $this->postJson("{$base}/budgets", ['profit_pct' => 25])->json('data.id');
        $chapter = $this->postJson("{$base}/budgets/{$budget}/chapters", ['name' => 'Obra'])->json('data.chapters.0.id');
        $this->postJson("{$base}/budgets/{$budget}/items", [
            'chapter_id' => $chapter, 'code' => '1.1', 'description' => 'Paredes', 'unit' => 'm2', 'quantity' => 100,
            'components' => [['kind' => 'material', 'name' => 'Tijolo', 'unit' => 'm2', 'quantity' => 1, 'unit_cost_cents' => 6000], ['kind' => 'labor', 'name' => 'Pedreiro', 'unit' => 'h', 'quantity' => 1, 'unit_cost_cents' => 4000]],
        ])->assertCreated();
        $items = $this->postJson("{$base}/budgets/{$budget}/items", ['chapter_id' => $chapter, 'code' => '1.2', 'description' => 'Pilares', 'unit' => 'un', 'quantity' => 10, 'unit_cost_cents' => 5000])
            ->assertCreated()->json('data.items');
        $this->postJson("{$base}/budgets/{$budget}/approve")->assertOk();
        $walls = collect($items)->firstWhere('code', '1.1')['id'];
        $pillars = collect($items)->firstWhere('code', '1.2')['id'];

        // Ainda nada medido nem gasto: a previsão é o orçamento, com confiança baixa.
        $cost = $this->getJson("{$base}/forecast")->assertOk()->json('data.cost');
        $this->assertTrue($cost['available']);
        $this->assertSame(1050000, $cost['forecast_cents']);
        $this->assertSame('budget', $cost['method']);
        $this->assertSame('low', $cost['confidence']);
        $this->assertStringContainsString('Ainda não há medições nem custos', self::texts($cost['reasons']));

        // Custos: material 5000 € a 10/11, mão de obra 2320 € a 18/11; compra de 600 € recebida a 19/11 (por faturar);
        // 1000 € comprometidos à mão; 500 € de outros a 25/11 (depois da medição).
        $this->postJson("{$base}/cost-entries", ['category' => 'material', 'kind' => 'actual', 'description' => 'Tijolo', 'amount_cents' => 500000, 'incurred_on' => '2026-11-10'])->assertCreated();
        $cost = $this->getJson("{$base}/forecast")->json('data.cost');
        $this->assertStringContainsString('Já há 5 000,00 € de custos, mas nenhuma medição', self::texts($cost['reasons']));

        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00', 'Europe/Lisbon'));
        $supplier = $this->postJson('/api/v1/partners', ['kind' => 'supplier', 'name' => 'Materiais do Lis, Lda.'])->assertCreated()->json('data.id');
        $po = $this->postJson("{$base}/purchase-orders", ['partner_id' => $supplier, 'lines' => [['description' => 'Cimento', 'unit' => 'saco', 'quantity' => 100, 'unit_price_cents' => 600]]])->assertCreated()->json('data.id');
        $this->postJson("{$base}/purchase-orders/{$po}/actions/approve")->assertOk();
        $this->postJson("{$base}/purchase-orders/{$po}/actions/receive")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00', 'Europe/Lisbon'));
        $this->postJson("{$base}/cost-entries", ['category' => 'labor', 'kind' => 'actual', 'description' => 'Pedreiros', 'amount_cents' => 232000, 'incurred_on' => '2026-11-18'])->assertCreated();
        $this->postJson("{$base}/cost-entries", ['category' => 'material', 'kind' => 'committed', 'description' => 'Reboco encomendado', 'amount_cents' => 100000, 'incurred_on' => '2026-11-20'])->assertCreated();
        $this->postJson("{$base}/cost-entries", ['category' => 'other', 'kind' => 'actual', 'description' => 'Licença', 'amount_cents' => 50000, 'incurred_on' => '2026-11-25'])->assertCreated();

        // Medições a 20/11: 60 m2 de paredes e 12 pilares (2 acima do orçamentado) = 6600 € de trabalho (62,9 %).
        $this->postJson("{$base}/measurements", ['budget_item_id' => $walls, 'measured_on' => '2026-11-20', 'quantity' => 60])->assertCreated();
        $this->postJson("{$base}/measurements", ['budget_item_id' => $pillars, 'measured_on' => '2026-11-20', 'quantity' => 12])->assertCreated();

        $cost = $this->getJson("{$base}/forecast")->assertOk()->json('data.cost');
        $this->assertSame(1050000, $cost['bac_cents']);
        $this->assertSame(660000, $cost['earned_cents']);
        $this->assertEquals(62.9, $cost['progress_pct']);
        $this->assertSame(400000, $cost['remaining_cents']);
        $this->assertSame('2026-11-20', $cost['last_measured_on']);
        // Custo até à medição: 5000 + 2320 + 600 recebidos = 7920 € → 1,20 € por cada euro orçamentado.
        $this->assertSame(792000, $cost['incurred_to_measurement_cents']);
        $this->assertEquals(1.2, $cost['cost_per_euro']);
        $this->assertSame(782000, $cost['actual_cents']);
        $this->assertSame(160000, $cost['committed_cents']);
        // Previsão pelo ritmo: 7920 + 4000 × 1,2 = 12 720 €; ao preço do orçamento: 7920 + 4000 = 11 920 €.
        $this->assertSame('trend', $cost['method']);
        $this->assertSame(1272000, $cost['forecast_cents']);
        $this->assertSame(1272000, $cost['at_trend_cents']);
        $this->assertSame(1192000, $cost['at_budget_cents']);
        $this->assertFalse($cost['floor_binds']);
        $this->assertSame('over', $cost['alert']);
        $this->assertSame(1312500 - 1272000, $cost['margin_cents']);
        $this->assertSame('high', $cost['confidence']);

        $texts = self::texts($cost['reasons']);
        $this->assertSame('danger', $cost['reasons'][0]['level']);
        $this->assertStringContainsString('passa o teto da obra (10 500,00 €) em 2 220,00 €', $texts);
        $this->assertStringContainsString('A margem prevista desce de 20,0 % (no orçamento) para 3,1 %', $texts);
        $this->assertStringContainsString('cada 1,00 € orçamentado está a custar 1,20 €', $texts);
        $this->assertStringContainsString('com 600,00 € de material recebido ainda por faturar', $texts);
        $this->assertStringContainsString('custa 4 800,00 € em vez de 4 000,00 €', $texts);
        $this->assertStringContainsString('1.2 Pilares: medidos 12 un de 10 orçamentados (+20 %) — mais 100,00 € de custo', $texts);
        $this->assertStringContainsString('Materiais: realizado mais comprometido 6 600,00 €, acima do previsto no orçamento (6 000,00 €, +10 %)', $texts);
        $this->assertStringNotContainsString('última medição é de', $texts);

        // Um mês e meio depois sem medir: a confiança desce e a razão aponta para as medições.
        Carbon::setTestNow(Carbon::parse('2027-01-11 10:00', 'Europe/Lisbon'));
        $cost = $this->getJson("{$base}/forecast")->json('data.cost');
        $this->assertSame('medium', $cost['confidence']);
        $this->assertStringContainsString('A última medição é de 20/11/2026 (há 52 dias). Desde então entraram 500,00 € de custos', self::texts($cost['reasons']));

        // O que já está gasto e comprometido é o mínimo da previsão.
        $this->postJson("{$base}/cost-entries", ['category' => 'subcontract', 'kind' => 'committed', 'description' => 'Cobertura adjudicada', 'amount_cents' => 600000, 'incurred_on' => '2027-01-11'])->assertCreated();
        $cost = $this->getJson("{$base}/forecast")->json('data.cost');
        $this->assertTrue($cost['floor_binds']);
        $this->assertSame(782000 + 760000, $cost['forecast_cents']);
        $this->assertStringContainsString('a previsão não pode ficar abaixo do que já está gasto ou contratado', self::texts($cost['reasons']));

        // O painel financeiro mostra a previsão no fim e a margem com ela.
        $row = $this->getJson('/api/v1/finance')->assertOk()->json('data.projects.0');
        $this->assertSame(1542000, $row['outlook']['eac_cents']);
        $this->assertSame(1312500 - 1542000, $row['outlook']['margin_cents']);
        $this->assertSame('over', $row['outlook']['alert']);
        $this->assertSame(1542000, $this->getJson('/api/v1/finance')->json('data.totals.eac_cents'));
    }

    public function test_schedule_forecast_reschedules_from_today_and_names_the_late_critical_activity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'Europe/Lisbon'));
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Armazém', 'status' => 'active', 'starts_on' => '2026-11-02', 'planned_end_on' => '2026-11-27'])->assertCreated()->json('data.id');
        $base = "/api/v1/projects/{$project}";

        // A (5 d) → B (10 d) → C (5 d): 2/11 a 27/11. D (3 d) sem precedências, com folga.
        $ids = [];
        foreach ([['A', 'Fundações', 5], ['B', 'Estrutura', 10], ['C', 'Acabamentos', 5], ['D', 'Arranjos exteriores', 3]] as [$code, $name, $days]) {
            $plan = $this->postJson("{$base}/activities", ['code' => $code, 'name' => $name, 'duration_days' => $days])->assertCreated()->json('data');
            $ids[$code] = collect($plan['activities'])->firstWhere('code', $code)['id'];
        }
        $this->postJson("{$base}/dependencies", ['predecessor_id' => $ids['A'], 'successor_id' => $ids['B']])->assertCreated();
        $this->postJson("{$base}/dependencies", ['predecessor_id' => $ids['B'], 'successor_id' => $ids['C']])->assertCreated();

        // Antes de começar: o fim previsto é o do plano.
        $schedule = $this->getJson("{$base}/forecast")->assertOk()->json('data.schedule');
        $this->assertTrue($schedule['available']);
        $this->assertFalse($schedule['started']);
        $this->assertSame('2026-11-27', $schedule['forecast_finish_on']);
        $this->assertSame(0, $schedule['delay_contract_days']);
        $this->assertStringContainsString('A obra começa a 02/11/2026', self::texts($schedule['reasons']));

        // Segunda, 16/11 (10 dias úteis depois do início): A feita, B a 40 % (devia estar a 50 %), D por começar.
        Carbon::setTestNow(Carbon::parse('2026-11-16 09:00', 'Europe/Lisbon'));
        $this->postJson("{$base}/activities/{$ids['A']}/progress", ['progress_pct' => 100])->assertOk();
        $this->postJson("{$base}/activities/{$ids['B']}/progress", ['progress_pct' => 40])->assertOk();

        $schedule = $this->getJson("{$base}/forecast")->assertOk()->json('data.schedule');
        $this->assertTrue($schedule['started']);
        $this->assertSame('2026-11-27', $schedule['planned_finish_on']);
        // B: falta 60 % de 10 dias = 6 dias desde hoje (até 23/11); C: 24/11 a 30/11.
        $this->assertSame('2026-11-30', $schedule['forecast_finish_on']);
        $this->assertSame(1, $schedule['delay_plan_days']);
        $this->assertSame(3, $schedule['delay_contract_days']);
        $this->assertSame('late', $schedule['status']);
        // Pesos pelas durações (5 + 10 + 5 + 3): planeado 13/23, feito 9/23.
        $this->assertEquals(56.5, $schedule['planned_progress_pct']);
        $this->assertEquals(39.1, $schedule['progress_pct']);

        $texts = self::texts($schedule['reasons']);
        $this->assertSame('danger', $schedule['reasons'][0]['level']);
        $this->assertStringContainsString('Fim previsto a 30/11/2026: 3 dias depois do prazo contratual (27/11/2026)', $texts);
        $this->assertStringContainsString('acaba 1 dia útil mais tarde', $texts);
        $this->assertStringContainsString('‘B Estrutura’ está a 40% e devia estar a 50%; está no caminho crítico', $texts);
        $this->assertStringContainsString('1 atividade atrasada fora do caminho crítico', $texts);
        $this->assertStringContainsString('Para hoje estava planeado 57 % do trabalho', $texts);
        $this->assertStringNotContainsString('‘C Acabamentos’', $texts);
        $late = collect($schedule['activities']);
        $this->assertSame(['B', 'D', 'C'], $late->pluck('code')->all());
        $this->assertTrue($late->firstWhere('code', 'D')['late_start']);
        $this->assertFalse($late->firstWhere('code', 'D')['critical']);

        // Tudo feito: a obra está concluída e compara-se a data real.
        foreach (['B', 'C', 'D'] as $code) {
            $this->postJson("{$base}/activities/{$ids[$code]}/progress", ['progress_pct' => 100])->assertOk();
        }
        $schedule = $this->getJson("{$base}/forecast")->json('data.schedule');
        $this->assertSame('finished', $schedule['status']);
        $this->assertSame('2026-11-16', $schedule['forecast_finish_on']);
    }

    public function test_each_part_needs_its_own_permission_and_other_companies_get_404(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia', 'starts_on' => '2026-11-02'])->assertCreated()->json('data.id');

        // O encarregado vê o planeamento mas não os custos; o administrativo vê os custos mas não o planeamento.
        $foreman = $this->teamMember($owner, $tenant, $project, 'encarregado');
        $this->actingAs($foreman)->getJson("/api/v1/projects/{$project}/forecast")->assertOk()
            ->assertJsonPath('data.cost', null)->assertJsonPath('data.schedule.available', false);
        $clerk = $this->addMember($tenant, 'administrativo');
        $this->actingAs($clerk)->getJson("/api/v1/projects/{$project}/forecast")->assertOk()
            ->assertJsonPath('data.schedule', null)->assertJsonPath('data.cost.available', false);
        $this->actingAs($clerk)->getJson('/api/v1/finance')->assertOk()->assertJsonPath('data.projects.0.outlook.schedule_available', false);

        [$other] = $this->createCompany();
        $this->actingAs($other)->getJson("/api/v1/projects/{$project}/forecast")->assertNotFound();
    }
}
