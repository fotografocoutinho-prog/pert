<?php

namespace Tests\Feature\Economics;

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    private function teamMember(User $owner, Tenant $tenant, int $project, string $role): User
    {
        $user = $this->addMember($tenant, $role);
        $membership = app(TenantContext::class)->runAs($tenant, fn () => (int) Membership::query()->where('user_id', $user->id)->value('id'));
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/members", ['membership_id' => $membership])->assertCreated();

        return $user;
    }

    /** Biblioteca com betão (material), pedreiro (mão de obra) e um artigo composto. */
    private function library(): array
    {
        $concrete = $this->postJson('/api/v1/price-library/resources', ['kind' => 'material', 'code' => 'MAT-BET', 'name' => 'Betão C25/30', 'unit' => 'm3', 'unit_cost_cents' => 9000])->assertCreated()->json('data.id');
        $mason = $this->postJson('/api/v1/price-library/resources', ['kind' => 'labor', 'code' => 'MO-PED', 'name' => 'Pedreiro', 'unit' => 'h', 'unit_cost_cents' => 1500])->assertCreated()->json('data.id');
        $article = $this->postJson('/api/v1/price-library/articles', [
            'code' => 'EST-01', 'description' => 'Betão em lajes', 'unit' => 'm3',
            'components' => [['resource_id' => $concrete, 'quantity' => 1.05], ['resource_id' => $mason, 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('data.unit_cost_cents', 12450)->assertJsonPath('data.composed', true)->json('data.id');

        return [$concrete, $mason, $article];
    }

    public function test_budget_with_composed_prices_totals_approval_and_revision(): void
    {
        [$owner] = $this->createCompany();
        $this->actingAs($owner);
        [, , $article] = $this->library();
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $base = "/api/v1/projects/{$project}/budgets";

        $budget = $this->postJson($base, ['name' => 'Proposta', 'overhead_pct' => 10, 'profit_pct' => 20, 'vat_rate' => 23])->assertCreated()
            ->assertJsonPath('data.code', 'ORC-01')->assertJsonPath('data.status', 'draft')->json('data.id');
        $chapter = $this->postJson("{$base}/{$budget}/chapters", ['name' => 'Estrutura'])->assertCreated()->json('data.chapters.0.id');
        $sub = $this->postJson("{$base}/{$budget}/chapters", ['name' => 'Lajes', 'parent_id' => $chapter])->assertCreated()->json('data.chapters.1');
        $this->assertSame('1.1', $sub['code']);

        // Do artigo composto da biblioteca: custo 124,50 €/m3; venda = custo × 1,10 × 1,20 = 164,34 €.
        $data = $this->postJson("{$base}/{$budget}/items", ['chapter_id' => $sub['id'], 'article_id' => $article, 'quantity' => 10])->assertCreated()->json('data');
        $this->assertSame(12450, $data['items'][0]['unit_cost_cents']);
        $this->assertSame(16434, $data['items'][0]['sale_unit_cents']);
        $this->assertCount(2, $data['items'][0]['components']);
        // Artigo simples com preço de venda escrito à mão.
        $data = $this->postJson("{$base}/{$budget}/items", ['chapter_id' => $chapter, 'description' => 'Estaleiro', 'unit' => 'vg', 'quantity' => 1, 'unit_cost_cents' => 100000, 'unit_price_cents' => 150000])->json('data');

        $this->assertSame(124500 + 100000, $data['cost_cents']);
        $this->assertSame(164340 + 150000, $data['sale_cents']);
        $this->assertSame((int) round(314340 * 0.23), $data['vat_cents']);
        // O capítulo de cima soma o subcapítulo.
        $this->assertSame(314340, collect($data['chapters'])->firstWhere('id', $chapter)['sale_cents']);
        // Natureza do custo previsto: material 1,05 × 90 × 10; mão de obra 2 × 15 × 10; o simples conta como "outros".
        $this->assertSame(94500, $data['by_category']['material']);
        $this->assertSame(30000, $data['by_category']['labor']);
        $this->assertSame(100000, $data['by_category']['other']);

        // Aprovado não se altera; a revisão copia tudo numa versão nova.
        $this->postJson("{$base}/{$budget}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("{$base}/{$budget}/items", ['chapter_id' => $chapter, 'description' => 'x', 'unit' => 'un'])->assertConflict()->assertJsonPath('code', 'BUDGET_STATUS');
        $revision = $this->postJson("{$base}/{$budget}/revise")->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.items_count', 2)->json('data');
        $this->assertSame(314340, $revision['sale_cents']);
        $this->postJson("{$base}/{$budget}/revise")->assertConflict()->assertJsonPath('code', 'REVISION_EXISTS');
        $this->postJson("{$base}/{$revision['id']}/approve")->assertOk();
        $this->getJson("{$base}/{$budget}")->assertJsonPath('data.status', 'superseded');

        // Recurso usado não se apaga; exportação CSV.
        $this->deleteJson('/api/v1/price-library/resources/'.$this->getJson('/api/v1/price-library')->json('data.resources.0.id'))->assertConflict();
        $csv = $this->get("{$base}/{$revision['id']}/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('Betão em lajes', $csv);
        $this->assertStringContainsString('Total com IVA', $csv);
    }

    public function test_import_from_csv_and_permissions(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $base = "/api/v1/projects/{$project}/budgets";
        $budget = $this->postJson($base, [])->json('data.id');

        $csv = "Código;Descrição;Unidade;Quantidade;Custo unitário\n1;Movimento de terras;;;\n1.1;Escavação geral;m3;120;8,50\n1.2;Aterro compactado;m3;40,5;12,00\n2;Estrutura;;;\n2.1;Betão em sapatas;m3;18;1.234,56\n";
        $file = UploadedFile::fake()->createWithContent('orcamento.csv', $csv);
        $data = $this->post("{$base}/{$budget}/import", ['file' => $file], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('meta.imported.chapters', 2)->assertJsonPath('meta.imported.items', 3)->json('data');
        $this->assertSame(['1', '2'], array_column($data['chapters'], 'code'));
        $this->assertSame(123456, collect($data['items'])->firstWhere('code', '2.1')['unit_cost_cents']);
        $this->assertSame(40.5, collect($data['items'])->firstWhere('code', '1.2')['quantity']);

        $bad = UploadedFile::fake()->createWithContent('x.csv', "Nome;Valor\nA;1\n");
        $this->post("{$base}/{$budget}/import", ['file' => $bad], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');

        // Encarregado não vê orçamentos; o medidor (todas as obras) gere; o administrativo só consulta.
        $foreman = $this->teamMember($owner, $tenant, $project, 'encarregado');
        $this->actingAs($foreman)->getJson($base)->assertForbidden();
        $this->actingAs($this->addMember($tenant, 'medidor_orcamentista'))->postJson($base, ['name' => 'Variante'])->assertCreated();
        $clerk = $this->addMember($tenant, 'administrativo');
        $this->actingAs($clerk)->getJson($base)->assertOk();
        $this->actingAs($clerk)->postJson($base, [])->assertForbidden();

        [$ownerB] = $this->createCompany('B');
        $this->actingAs($ownerB)->getJson("{$base}/{$budget}")->assertNotFound();
    }

    public function test_measurements_and_owner_certificates_accumulate(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $base = "/api/v1/projects/{$project}";

        // Sem orçamento aprovado não há medições.
        $this->postJson("{$base}/measurements", ['budget_item_id' => 1, 'measured_on' => now()->toDateString(), 'quantity' => 1])->assertConflict()->assertJsonPath('code', 'NO_APPROVED_BUDGET');

        $budget = $this->postJson("{$base}/budgets", ['profit_pct' => 0])->json('data.id');
        $chapter = $this->postJson("{$base}/budgets/{$budget}/chapters", ['name' => 'Alvenarias'])->json('data.chapters.0.id');
        $item = $this->postJson("{$base}/budgets/{$budget}/items", ['chapter_id' => $chapter, 'description' => 'Parede de tijolo 15', 'unit' => 'm2', 'quantity' => 100, 'unit_cost_cents' => 2500])->json('data.items.0.id');
        $this->postJson("{$base}/budgets/{$budget}/approve")->assertOk();

        $day = fn (int $d) => now('Europe/Lisbon')->subDays($d)->toDateString();
        // Por dimensões: 2 paredes × 5 m × 3 m = 30 m2.
        $this->postJson("{$base}/measurements", ['budget_item_id' => $item, 'measured_on' => $day(10), 'count' => 2, 'length' => 5, 'height' => 3])->assertCreated()->assertJsonPath('data.quantity', 30);
        $this->postJson("{$base}/measurements", ['budget_item_id' => $item, 'measured_on' => $day(9), 'quantity' => 10])->assertCreated();
        $this->postJson("{$base}/measurements", ['budget_item_id' => $item, 'measured_on' => now()->addDay()->toDateString(), 'quantity' => 1])->assertUnprocessable();

        // Auto 1: 40 m2 × 25 € = 1000 €; as medições ficam presas ao auto.
        $first = $this->postJson("{$base}/certificates", ['period_start' => $day(30), 'period_end' => $day(5)])->assertCreated()
            ->assertJsonPath('data.number', 1)->assertJsonPath('data.amount_cents', 100000)->assertJsonPath('data.lines.0.qty_period', 40)->json('data.id');
        $measurement = $this->getJson("{$base}/measurements")->json('data.measurements.0.id');
        $this->putJson("{$base}/measurements/{$measurement}", ['quantity' => 99])->assertConflict()->assertJsonPath('code', 'MEASUREMENT_CERTIFIED');

        // Fiscal aprova (submetido antes); encarregado não.
        $inspector = $this->teamMember($owner, $tenant, $project, 'fiscal');
        $this->postJson("{$base}/certificates/{$first}/submit")->assertOk();
        $this->actingAs($this->teamMember($owner, $tenant, $project, 'encarregado'))->postJson("{$base}/certificates/{$first}/approve")->assertForbidden();
        $this->actingAs($inspector)->postJson("{$base}/certificates/{$first}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        // Auto 2: medições novas; o anterior aparece como "anterior".
        $this->actingAs($owner)->postJson("{$base}/measurements", ['budget_item_id' => $item, 'measured_on' => $day(2), 'quantity' => 20])->assertCreated();
        $second = $this->postJson("{$base}/certificates", ['period_start' => $day(4), 'period_end' => $day(0)])->assertCreated()
            ->assertJsonPath('data.number', 2)->assertJsonPath('data.lines.0.qty_previous', 40)->assertJsonPath('data.lines.0.qty_period', 20)
            ->assertJsonPath('data.lines.0.qty_accumulated', 60)->assertJsonPath('data.previous_cents', 100000)->json('data.id');
        $progress = $this->getJson("{$base}/measurements")->json('data.progress.0');
        $this->assertSame(60.0, (float) $progress['measured_quantity']);
        $this->assertSame(40.0, (float) $progress['certified_quantity']);

        // Rascunho apagado: as medições voltam a ficar livres.
        $this->deleteJson("{$base}/certificates/{$second}")->assertNoContent();
        $this->postJson("{$base}/certificates", ['period_start' => $day(4), 'period_end' => $day(0)])->assertCreated()->assertJsonPath('data.number', 2)->assertJsonPath('data.amount_cents', 50000);
    }
}
