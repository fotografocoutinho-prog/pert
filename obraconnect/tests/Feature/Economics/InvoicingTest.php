<?php

namespace Tests\Feature\Economics;

use Tests\TestCase;

class InvoicingTest extends TestCase
{
    /** Obra com cliente, orçamento aprovado (custo 10 000 €, venda 12 500 €) e um auto aprovado de 40%. */
    private function approvedCertificate(string $vatMode = 'normal'): array
    {
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $client = $this->postJson('/api/v1/clients', [
            'type' => 'company', 'name' => 'Imobiliária do Mondego, S.A.', 'nif' => '503504564',
            'address' => 'Rua Larga, 10', 'postal_code' => '3000-000', 'city' => 'Coimbra', 'email' => 'obras@mondego.test',
        ])->assertCreated()->json('data.id');
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia', 'client_id' => $client, 'starts_on' => now()->subMonth()->toDateString()])->assertCreated()->json('data.id');
        $base = "/api/v1/projects/{$project}";
        $budget = $this->postJson("{$base}/budgets", ['profit_pct' => 25, 'vat_mode' => $vatMode])->assertCreated()->json('data.id');
        $chapter = $this->postJson("{$base}/budgets/{$budget}/chapters", ['name' => 'Obra'])->json('data.chapters.0.id');
        $item = $this->postJson("{$base}/budgets/{$budget}/items", [
            'chapter_id' => $chapter, 'description' => 'Paredes', 'unit' => 'm2', 'quantity' => 100, 'unit_cost_cents' => 10000,
        ])->assertCreated()->json('data.items.0.id');
        $this->postJson("{$base}/budgets/{$budget}/approve")->assertOk();
        $this->postJson("{$base}/measurements", ['budget_item_id' => $item, 'measured_on' => now()->toDateString(), 'quantity' => 40])->assertCreated();
        $cert = $this->postJson("{$base}/certificates", ['period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->toDateString()])->assertCreated()->json('data.id');
        $this->postJson("{$base}/certificates/{$cert}/submit")->assertOk();
        $this->postJson("{$base}/certificates/{$cert}/approve")->assertOk();

        return [$owner, $tenant, $project, $base, $cert, $client];
    }

    public function test_pre_invoice_from_certificate_numbering_export_and_official_invoice(): void
    {
        [$owner, $tenant, $project, $base, $cert, $client] = $this->approvedCertificate();
        $this->getJson("{$base}/certificates/{$cert}")->assertJsonPath('data.can.invoice', true)->assertJsonPath('data.invoice_draft', null);

        $draft = $this->postJson('/api/v1/invoice-drafts', ['certificate_id' => $cert])->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.code', null)
            ->assertJsonPath('data.client_name', 'Imobiliária do Mondego, S.A.')->assertJsonPath('data.client_nif', '503504564')
            ->assertJsonPath('data.client_city', 'Coimbra')->assertJsonPath('data.project.id', $project)
            ->assertJsonPath('data.lines.0.quantity', 40)->assertJsonPath('data.lines.0.unit_price_cents', 12500)
            ->assertJsonPath('data.lines.0.vat_rate', 23)
            ->assertJsonPath('data.net_cents', 500000)->assertJsonPath('data.vat_cents', 115000)->assertJsonPath('data.total_cents', 615000)
            ->json('data');
        $this->assertStringContainsString('Auto de medição n.º 1', $draft['reference']);
        $this->postJson('/api/v1/invoice-drafts', ['certificate_id' => $cert])->assertConflict()->assertJsonPath('code', 'INVOICE_EXISTS');
        $this->getJson("{$base}/certificates/{$cert}")->assertJsonPath('data.can.invoice', false)->assertJsonPath('data.invoice_draft.id', $draft['id']);

        // Rascunho editável: vencimento e uma linha de revisão de preços com desconto.
        $lines = array_map(fn ($l) => array_intersect_key($l, array_flip(['code', 'description', 'unit', 'quantity', 'unit_price_cents', 'discount_pct', 'vat_rate'])), $draft['lines']);
        $lines[] = ['description' => 'Revisão de preços', 'unit' => 'vg', 'quantity' => '1', 'unit_price_cents' => 20000, 'discount_pct' => '10', 'vat_rate' => 23];
        $this->putJson("/api/v1/invoice-drafts/{$draft['id']}", ['due_date' => now()->addDays(30)->toDateString(), 'lines' => $lines])->assertOk()
            ->assertJsonPath('data.net_cents', 518000)->assertJsonPath('data.vat_cents', 115000 + 4140);

        // Pronta: número interno por ano, sem buracos entre rascunhos apagados.
        $manual = $this->postJson('/api/v1/invoice-drafts', ['project_id' => $project, 'lines' => [['description' => 'Adiantamento', 'unit' => 'vg', 'quantity' => 1, 'unit_price_cents' => 100000, 'vat_rate' => 23]]])
            ->assertCreated()->assertJsonPath('data.client_name', 'Imobiliária do Mondego, S.A.')->json('data.id');
        $year = now('Europe/Lisbon')->year;
        $this->postJson("/api/v1/invoice-drafts/{$draft['id']}/actions/ready")->assertOk()->assertJsonPath('data.code', sprintf('PF %d/0001', $year))->assertJsonPath('data.status', 'ready');
        $this->postJson("/api/v1/invoice-drafts/{$manual}/actions/ready")->assertOk()->assertJsonPath('data.code', sprintf('PF %d/0002', $year));
        $this->putJson("/api/v1/invoice-drafts/{$draft['id']}", ['notes' => 'x'])->assertConflict()->assertJsonPath('code', 'INVOICE_STATUS');
        $this->deleteJson("/api/v1/invoice-drafts/{$manual}")->assertConflict();

        // Exportação das prontas a faturar.
        $csv = $this->get('/api/v1/invoice-drafts/export?format=csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Documento;Estado;Data;', $csv);
        $this->assertStringContainsString(sprintf('"PF %d/0001";"Pronta a faturar";', $year), $csv);
        $this->assertStringContainsString(';503504564;', $csv);
        $this->assertStringContainsString(';"Revisão de preços";vg;1;200;10;23;;;180;41,4;221,4;', $csv);
        $xlsx = $this->get('/api/v1/invoice-drafts/export?format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('Content-Type'));
        $json = $this->getJson('/api/v1/invoice-drafts/export?format=json')->assertOk()->json('data');
        $this->assertCount(2, $json);
        $this->assertFalse($json[0]['fiscal']);
        $this->assertSame(5180.0, (float) $json[0]['totals']['net']);

        $pdf = $this->get("/api/v1/invoice-drafts/{$draft['id']}/pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Fatura oficial registada: fecha o ciclo; já não sai nas exportações "prontas".
        $this->postJson("/api/v1/invoice-drafts/{$draft['id']}/actions/invoice", ['official_number' => 'FT A/2026/57'])->assertUnprocessable()->assertJsonValidationErrors('official_date');
        $this->postJson("/api/v1/invoice-drafts/{$draft['id']}/actions/invoice", ['official_number' => 'FT A/2026/57', 'official_date' => now()->toDateString()])->assertOk()
            ->assertJsonPath('data.status', 'invoiced')->assertJsonPath('data.official_number', 'FT A/2026/57')->assertJsonPath('data.can.cancel', false);
        $this->postJson("/api/v1/invoice-drafts/{$draft['id']}/actions/cancel")->assertConflict();
        $this->assertCount(1, $this->getJson('/api/v1/invoice-drafts/export?format=json')->json('data'));
        $this->getJson('/api/v1/invoice-drafts?status=invoiced')->assertOk()->assertJsonCount(1, 'data');

        // Cliente com pré-faturas não se apaga.
        $this->deleteJson("/api/v1/clients/{$client}")->assertConflict()->assertJsonPath('code', 'CLIENT_IN_USE');
    }

    public function test_reverse_charge_vat_reason_and_validation(): void
    {
        [$owner, $tenant, $project, $base, $cert] = $this->approvedCertificate('reverse_charge');
        $draft = $this->postJson('/api/v1/invoice-drafts', ['certificate_id' => $cert])->assertCreated()
            ->assertJsonPath('data.lines.0.vat_rate', 0)->assertJsonPath('data.vat_cents', 0)
            ->assertJsonPath('data.vat_exemption_reason', 'IVA - autoliquidação')->json('data.id');
        $this->postJson("/api/v1/invoice-drafts/{$draft}/actions/ready")->assertOk();

        // Manual a 0% sem motivo não fica pronta; NIF português inválido é recusado.
        $manual = $this->postJson('/api/v1/invoice-drafts', ['client_name' => 'Particular', 'lines' => [['description' => 'Serviço', 'unit' => 'un', 'quantity' => 1, 'unit_price_cents' => 1000, 'vat_rate' => 0]]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/invoice-drafts/{$manual}/actions/ready")->assertUnprocessable()->assertJsonPath('code', 'VAT_REASON_REQUIRED');
        $this->postJson('/api/v1/invoice-drafts', ['client_name' => 'X', 'client_nif' => '123456780'])->assertUnprocessable()->assertJsonValidationErrors('client_nif');
        $this->postJson('/api/v1/invoice-drafts', ['client_name' => 'X', 'lines' => [['description' => 'a', 'unit' => 'un', 'quantity' => 0, 'unit_price_cents' => 1, 'vat_rate' => 23]]])->assertUnprocessable();
        $this->postJson("/api/v1/invoice-drafts/{$manual}/actions/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Auto de subempreitada ou por aprovar não se fatura.
        $this->postJson("{$base}/certificates", ['period_start' => now()->toDateString(), 'period_end' => now()->toDateString()])->assertCreated();
        $pending = $this->getJson("{$base}/certificates")->json('data.0.id');
        $this->postJson('/api/v1/invoice-drafts', ['certificate_id' => $pending])->assertConflict()->assertJsonPath('code', 'CERTIFICATE_STATUS');
    }

    public function test_permissions_and_isolation(): void
    {
        [$owner, $tenant, $project, $base, $cert] = $this->approvedCertificate();
        $draft = $this->postJson('/api/v1/invoice-drafts', ['certificate_id' => $cert])->assertCreated()->json('data.id');

        $dt = $this->addMember($tenant, 'diretor_tecnico');
        $this->actingAs($dt)->getJson('/api/v1/invoice-drafts')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($dt)->getJson("/api/v1/invoice-drafts/{$draft}")->assertOk()->assertJsonPath('data.can.edit', false);
        $this->actingAs($dt)->postJson("/api/v1/invoice-drafts/{$draft}/actions/ready")->assertForbidden();

        $adv = $this->addMember($tenant, 'administrativo');
        $this->actingAs($adv)->postJson("/api/v1/invoice-drafts/{$draft}/actions/ready")->assertOk();

        $enc = $this->addMember($tenant, 'encarregado');
        $this->actingAs($enc)->getJson('/api/v1/invoice-drafts')->assertForbidden();
        $this->actingAs($enc)->get('/api/v1/invoice-drafts/export')->assertForbidden();

        [$other] = $this->createCompany('Empresa B');
        $this->actingAs($other)->getJson("/api/v1/invoice-drafts/{$draft}")->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/invoice-drafts')->assertOk()->assertJsonCount(0, 'data');
    }
}
