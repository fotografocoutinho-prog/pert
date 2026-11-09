<?php

namespace Tests\Feature\Purchasing;

use App\Models\Membership;
use App\Models\Project;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierDocumentsTest extends TestCase
{
    private const SUPPLIER_NIF = '505767252';

    private const OWN_NIF = '501442600';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('obraconnect.files.disk'));
        config(['obraconnect.docintel.url' => null, 'obraconnect.docintel.key' => null]);
    }

    private function enableReader(): void
    {
        config(['obraconnect.docintel.url' => 'https://docintel.test', 'obraconnect.docintel.key' => 'dik_teste']);
    }

    private function pdf(string $name = 'fatura.pdf', string $salt = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% {$name} {$salt}\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");
    }

    /** Resultado do DocIntel (formato de /documents/analyze), abreviado ao que o ObraConnect usa. */
    private function reading(array $overrides = []): array
    {
        $f = fn ($value, $source = 'qr') => ['value' => $value, 'confidence' => 0.99, 'source' => $source];

        return ['document_id' => '3f5a0000-0000-4000-8000-000000000001', 'status' => 'completed', 'result' => array_replace_recursive([
            'document_id' => '3f5a0000-0000-4000-8000-000000000001',
            'document_type' => 'invoice',
            'document_confidence' => 0.9755,
            'needs_review' => false,
            'review_reasons' => [],
            'supplier' => ['name' => $f('MATERMARQUES — MATERIAIS DE CONSTRUÇÃO, LDA', 'ocr'), 'tax_id' => $f('PT'.self::SUPPLIER_NIF)],
            'customer' => ['tax_id' => $f(self::OWN_NIF)],
            'invoice' => [
                'number' => $f('FT 2026/1234'), 'atcud' => $f('JFHT7T4L-1234'), 'date' => $f('2026-11-03'), 'due_date' => $f('2026-12-03', 'ocr'),
                'cancelled' => false, 'references' => ['order_number' => $f('V/ Encomenda n.º 1', 'ocr'), 'delivery_note_numbers' => []],
            ],
            'items' => [[
                'line_number' => 1, 'code' => $f('CIM25'), 'description' => $f('Cimento Portland 25 kg'), 'quantity' => $f(100.0),
                'unit' => $f('saco'), 'unit_price' => $f('10.00'), 'tax_rate' => $f(23.0), 'line_total' => $f(1000.0), 'arithmetic_ok' => true,
            ]],
            'totals' => ['net' => $f('1000.00'), 'tax' => $f('230.00'), 'gross' => $f('1230.00')],
        ], $overrides)];
    }

    /** Empresa (com NIF), obra, fornecedor e uma compra aprovada de 1000 € (n.º 1). */
    private function setupPurchase(): array
    {
        [$owner, $tenant] = $this->createCompany();
        $tenant->forceFill(['nif' => self::OWN_NIF])->save();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia', 'starts_on' => '2026-11-02'])->assertCreated()->json('data.id');
        $base = "/api/v1/projects/{$project}";
        $supplier = $this->postJson('/api/v1/partners', ['kind' => 'supplier', 'name' => 'MaterMarquês, Lda.', 'nif' => self::SUPPLIER_NIF])->assertCreated()->json('data.id');
        $po = $this->postJson("{$base}/purchase-orders", ['partner_id' => $supplier, 'lines' => [['description' => 'Cimento 25 kg', 'unit' => 'saco', 'quantity' => 100, 'unit_price_cents' => 1000]]])
            ->assertCreated()->assertJsonPath('data.number', 1)->json('data.id');
        $this->postJson("{$base}/purchase-orders/{$po}/actions/approve")->assertOk();

        return [$owner, $tenant, $project, $base, $supplier, $po];
    }

    public function test_docintel_reading_proposes_the_purchase_and_posting_invoices_it(): void
    {
        $this->enableReader();
        Http::fake(['docintel.test/*' => Http::response($this->reading())]);
        [$owner, $tenant, $project, $base, $supplier, $po] = $this->setupPurchase();

        $doc = $this->post('/api/v1/supplier-documents', ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status', 'to_review')->assertJsonPath('data.reader', 'docintel')
            ->assertJsonPath('data.kind', 'invoice')->assertJsonPath('data.supplier_nif', self::SUPPLIER_NIF)
            ->assertJsonPath('data.number', 'FT 2026/1234')->assertJsonPath('data.issue_date', '2026-11-03')
            ->assertJsonPath('data.net_cents', 100000)->assertJsonPath('data.vat_cents', 23000)->assertJsonPath('data.gross_cents', 123000)
            ->assertJsonPath('data.partner_id', $supplier)->assertJsonPath('data.purchase_order_id', $po)->assertJsonPath('data.project_id', $project)
            ->assertJsonPath('data.lines.0.description', 'Cimento Portland 25 kg')->assertJsonPath('data.warnings', [])
            ->assertJsonPath('data.suggestions.purchase_orders.0.reason', 'o número da encomenda é o da referência lida')
            ->assertJsonPath('data.can.post', true)
            ->json('data');
        $this->assertEqualsWithDelta(0.9755, $doc['confidence'], 0.0001);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://docintel.test/api/v1/documents/analyze'
            && $r->header('X-API-Key') === ['dik_teste']
            && str_contains($r->body(), "obraconnect:{$tenant->id}:{$doc['id']}"));

        // Lançar: a compra fica faturada pelo valor sem IVA e conta como custo realizado.
        $this->postJson("/api/v1/supplier-documents/{$doc['id']}/actions/post")->assertOk()->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.can.unpost', true)->assertJsonPath('data.can.edit', false);
        $this->getJson("{$base}/purchase-orders")->assertJsonPath('data.0.status', 'invoiced')
            ->assertJsonPath('data.0.invoice_number', 'FT 2026/1234')->assertJsonPath('data.0.invoiced_cents', 100000);
        $this->getJson("{$base}/costs")->assertJsonPath('data.actual_cents', 100000)->assertJsonPath('data.committed_cents', 0);
        $this->putJson("/api/v1/supplier-documents/{$doc['id']}", ['notes' => 'x'])->assertConflict()->assertJsonPath('code', 'SUPPLIER_DOC_STATUS');
        $this->deleteJson("/api/v1/supplier-documents/{$doc['id']}")->assertConflict();

        // O mesmo ficheiro não entra duas vezes; a mesma fatura noutro ficheiro é avisada e não se lança.
        $this->post('/api/v1/supplier-documents', ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertConflict()
            ->assertJsonPath('code', 'SUPPLIER_DOC_DUPLICATE_FILE')->assertJsonPath('meta.id', $doc['id']);
        $copy = $this->post('/api/v1/supplier-documents', ['file' => $this->pdf('fatura-digitalizada.pdf', 'outra')], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.purchase_order_id', null)->json('data');
        $this->assertStringContainsString("documento n.º {$doc['id']}", implode(' ', $copy['warnings']));
        $this->postJson("/api/v1/supplier-documents/{$copy['id']}/actions/post")->assertUnprocessable()->assertJsonPath('code', 'SUPPLIER_DOC_PROJECT');
        $this->putJson("/api/v1/supplier-documents/{$copy['id']}", ['project_id' => $project])->assertOk();
        $this->postJson("/api/v1/supplier-documents/{$copy['id']}/actions/post")->assertConflict()->assertJsonPath('code', 'SUPPLIER_DOC_DUPLICATE');
        $this->postJson("/api/v1/supplier-documents/{$copy['id']}/actions/reject", ['reason' => 'Duplicado'])->assertOk()->assertJsonPath('data.status', 'rejected');

        // Desfazer: a compra volta a aprovada, sem os dados da fatura; pode voltar a ser lançada.
        $this->postJson("/api/v1/supplier-documents/{$doc['id']}/actions/unpost")->assertOk()->assertJsonPath('data.status', 'to_review');
        $this->getJson("{$base}/purchase-orders")->assertJsonPath('data.0.status', 'approved')->assertJsonPath('data.0.invoice_number', null);
        $this->getJson("{$base}/costs")->assertJsonPath('data.actual_cents', 0)->assertJsonPath('data.committed_cents', 100000);
        $this->postJson("/api/v1/supplier-documents/{$doc['id']}/actions/post")->assertOk();

        // Caixa da empresa: contagens por estado e o original abre no browser.
        $this->getJson('/api/v1/supplier-documents?status=posted')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.counts.posted', 1)->assertJsonPath('meta.counts.rejected', 1)->assertJsonPath('meta.reader_enabled', true);
        $file = $this->get("/api/v1/supplier-documents/{$doc['id']}/file")->assertOk();
        $this->assertSame('application/pdf', $file->headers->get('Content-Type'));
        // Mostra-se dentro da própria app (página de revisão), nunca noutro site; o resto continua DENY.
        $this->assertSame('SAMEORIGIN', $file->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'self'", (string) $file->headers->get('Content-Security-Policy'));
        $this->assertSame('DENY', $this->getJson('/api/v1/supplier-documents')->headers->get('X-Frame-Options'));
    }

    public function test_manual_entry_cost_entry_credit_note_delivery_note_and_guards(): void
    {
        [$owner, $tenant, $project, $base, $supplier, $po] = $this->setupPurchase();
        $upload = fn (string $name) => $this->post('/api/v1/supplier-documents', ['file' => $this->pdf($name), 'project_id' => $project], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'to_review')->assertJsonPath('data.reader', null)->json('data.id');

        // Sem DocIntel: preenche-se à mão. Sem compra → custo realizado pelo valor sem IVA (total − IVA).
        $invoice = $upload('talao.pdf');
        $this->getJson('/api/v1/supplier-documents')->assertJsonPath('meta.reader_enabled', false);
        $this->postJson("/api/v1/supplier-documents/{$invoice}/actions/read")->assertConflict()->assertJsonPath('code', 'SUPPLIER_DOC_READER_OFF');
        $this->postJson("/api/v1/supplier-documents/{$invoice}/actions/post")->assertUnprocessable()->assertJsonPath('code', 'SUPPLIER_DOC_INCOMPLETE');
        $this->putJson("/api/v1/supplier-documents/{$invoice}", [
            'kind' => 'receipt', 'category' => 'other', 'supplier_name' => 'Ferragens Pombal', 'supplier_nif' => 'PT 503 504 564',
            'number' => 'FS 1/20', 'issue_date' => '2026-11-05', 'gross_cents' => 12300, 'vat_cents' => 2300,
        ])->assertOk()->assertJsonPath('data.supplier_nif', '503504564')->assertJsonPath('data.net_cents', null)->assertJsonPath('data.net_effective_cents', 10000);
        $this->postJson("/api/v1/supplier-documents/{$invoice}/actions/post")->assertOk();
        $costs = $this->getJson("{$base}/costs")->assertJsonPath('data.actual_cents', 10000)->json('data');
        $entry = collect($costs['entries'])->firstWhere('supplier_document_id', $invoice);
        $this->assertSame('FS 1/20 · Ferragens Pombal', $entry['description']);
        $this->deleteJson("{$base}/cost-entries/{$entry['id']}")->assertConflict()->assertJsonPath('code', 'COST_FROM_SUPPLIER_DOC');

        // Nota de crédito: custo negativo; não se lança numa compra.
        $credit = $upload('nc.pdf');
        $this->putJson("/api/v1/supplier-documents/{$credit}", ['kind' => 'credit_note', 'partner_id' => $supplier, 'purchase_order_id' => $po, 'number' => 'NC 2026/7', 'issue_date' => '2026-11-06', 'net_cents' => 2000])->assertOk();
        $this->postJson("/api/v1/supplier-documents/{$credit}/actions/post")->assertUnprocessable()->assertJsonPath('code', 'SUPPLIER_DOC_PURCHASE');
        $this->putJson("/api/v1/supplier-documents/{$credit}", ['purchase_order_id' => null])->assertOk();
        $this->postJson("/api/v1/supplier-documents/{$credit}/actions/post")->assertOk();
        $this->getJson("{$base}/costs")->assertJsonPath('data.actual_cents', 8000);

        // Guia com a compra aprovada → a compra fica recebida (sem custo).
        $note = $upload('guia.pdf');
        $this->putJson("/api/v1/supplier-documents/{$note}", ['kind' => 'delivery_note', 'purchase_order_id' => $po, 'number' => 'GR 2026/55'])->assertOk()
            ->assertJsonPath('data.partner_id', $supplier);
        $this->postJson("/api/v1/supplier-documents/{$note}/actions/post")->assertOk();
        $this->getJson("{$base}/purchase-orders")->assertJsonPath('data.0.status', 'received')->assertJsonPath('data.0.lines.0.received_quantity', 100);
        $this->getJson("{$base}/costs")->assertJsonPath('data.actual_cents', 8000)->assertJsonPath('data.committed_cents', 100000);

        // Fornecedor com faturas não se apaga; rejeitar, reabrir e apagar devolve o espaço.
        $this->deleteJson("/api/v1/partners/{$supplier}")->assertConflict()->assertJsonPath('code', 'PARTNER_IN_USE');
        $other = $upload('outro.pdf');
        $used = (int) $tenant->refresh()->storage_used_bytes;
        $this->postJson("/api/v1/supplier-documents/{$other}/actions/reject")->assertOk();
        $this->postJson("/api/v1/supplier-documents/{$other}/actions/reopen")->assertOk()->assertJsonPath('data.status', 'to_review');
        $this->deleteJson("/api/v1/supplier-documents/{$other}")->assertNoContent();
        $this->assertLessThan($used, (int) $tenant->refresh()->storage_used_bytes);
        $this->assertSame([], Storage::disk(config('obraconnect.files.disk'))->allFiles("tenants/{$tenant->id}/supplier-documents/{$other}"));

        // Obra arquivada: nada se lança nem se altera.
        $last = $upload('ultimo.pdf');
        app(TenantContext::class)->runAs($tenant, fn () => Project::query()->whereKey($project)->update(['status' => 'archived']));
        $this->putJson("/api/v1/supplier-documents/{$last}", ['number' => 'X'])->assertConflict()->assertJsonPath('code', 'PROJECT_ARCHIVED');
        $this->postJson("/api/v1/supplier-documents/{$last}/actions/post")->assertConflict()->assertJsonPath('code', 'PROJECT_ARCHIVED');
        $this->deleteJson("/api/v1/supplier-documents/{$last}")->assertConflict();
        $this->getJson("/api/v1/supplier-documents/{$last}")->assertOk()->assertJsonPath('data.can.edit', false)->assertJsonPath('data.can.delete', false);
    }

    public function test_reading_errors_permissions_by_project_and_tenant_isolation(): void
    {
        $this->enableReader();
        $answer = 'refused';
        // Por referência: a mesma "instalação" do DocIntel recusa primeiro e responde depois.
        Http::fake(['docintel.test/*' => function () use (&$answer) {
            return $answer === 'refused'
                ? Http::response(['error' => ['code' => 'unauthenticated', 'message' => 'Chave inválida']], 401)
                : Http::response($this->reading(['invoice' => ['cancelled' => true]]));
        }]);
        [$owner, $tenant, $project, $base, $supplier, $po] = $this->setupPurchase();

        // A leitura falhou: o documento fica por rever, com o erro à vista, e preenche-se à mão.
        $doc = $this->post('/api/v1/supplier-documents', ['file' => $this->pdf(), 'project_id' => $project], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status', 'to_review')->assertJsonPath('data.read_error', 'O DocIntel recusou a chave (DOCINTEL_KEY).')->json('data');
        $this->assertStringContainsString('Não foi possível ler automaticamente', $doc['warnings'][0]);
        $inbox = $this->post('/api/v1/supplier-documents', ['file' => $this->pdf('caixa.pdf')], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        // Ler de novo, agora com o DocIntel a responder: anulada no QR → fica o aviso.
        $answer = 'ok';
        $this->postJson("/api/v1/supplier-documents/{$doc['id']}/actions/read")->assertOk()->assertJsonPath('data.read_error', null)
            ->assertJsonPath('data.review_reasons.0', 'O código QR diz que o documento foi anulado pelo fornecedor.');

        // Diretor de obra da equipa: vê e trata os documentos da obra (lança na compra), não a caixa da empresa.
        $director = $this->addMember($tenant, 'diretor_obra');
        $membership = app(TenantContext::class)->runAs($tenant, fn () => (int) Membership::query()->where('user_id', $director->id)->value('id'));
        $this->actingAs($owner)->postJson("{$base}/members", ['membership_id' => $membership])->assertCreated();
        $this->actingAs($director);
        $this->getJson('/api/v1/supplier-documents')->assertForbidden();
        $this->getJson("/api/v1/supplier-documents?project_id={$project}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.can_upload', true);
        $this->getJson("/api/v1/supplier-documents/{$inbox}")->assertForbidden();
        $this->putJson("/api/v1/supplier-documents/{$doc['id']}", ['project_id' => null])->assertForbidden();
        $this->getJson("/api/v1/supplier-documents/{$doc['id']}")->assertOk()->assertJsonPath('data.purchase_order_id', $po)->assertJsonPath('data.can.post', true);
        $this->postJson("/api/v1/supplier-documents/{$doc['id']}/actions/post")->assertOk();
        // Sem compra seria custo: o diretor de obra não tem costs.manage.
        $this->postJson("/api/v1/supplier-documents/{$doc['id']}/actions/unpost")->assertOk();
        $this->putJson("/api/v1/supplier-documents/{$doc['id']}", ['purchase_order_id' => null])->assertOk()->assertJsonPath('data.can.post', false);
        $this->postJson("/api/v1/supplier-documents/{$doc['id']}/actions/post")->assertForbidden();

        // Encarregado: nada. Outra empresa: não existe.
        $this->actingAs($this->addMember($tenant, 'encarregado'))->getJson("/api/v1/supplier-documents?project_id={$project}")->assertForbidden();
        [$otherOwner] = $this->createCompany('Empresa B');
        $this->actingAs($otherOwner)->getJson("/api/v1/supplier-documents/{$doc['id']}")->assertNotFound();
        $this->actingAs($otherOwner)->getJson('/api/v1/supplier-documents')->assertOk()->assertJsonCount(0, 'data');
    }
}
