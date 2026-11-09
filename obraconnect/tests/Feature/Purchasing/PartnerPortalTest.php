<?php

namespace Tests\Feature\Purchasing;

use App\Mail\PortalInvitationMail;
use App\Mail\PortalNoticeMail;
use App\Mail\ProjectNoticeMail;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartnerPortalTest extends TestCase
{
    /**
     * Empresa com uma obra em curso, dois subempreiteiros (A e B), um contrato ativo de A com dois
     * artigos, um contrato de A ainda em preparação e um contrato ativo de B.
     *
     * @return array{owner: User, tenant: Tenant, project: int, a: int, b: int, contractA: int, draftA: int, contractB: int, items: list<int>}
     */
    private function scenario(): array
    {
        [$owner, $tenant] = $this->createCompany('Construções Lima');
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia em Ílhavo', 'status' => 'active', 'starts_on' => now()->subMonth()->toDateString()])->json('data.id');
        $base = "/api/v1/projects/{$project}";
        $a = $this->postJson('/api/v1/partners', ['kind' => 'subcontractor', 'name' => 'Eletro Vouga, Lda.', 'email' => 'geral@eletrovouga.pt'])->assertCreated()->json('data.id');
        $b = $this->postJson('/api/v1/partners', ['kind' => 'subcontractor', 'name' => 'Pinturas Ria, Lda.'])->assertCreated()->json('data.id');

        $contractA = $this->postJson("{$base}/contracts", ['partner_id' => $a, 'title' => 'Instalação elétrica', 'retention_pct' => 5, 'items' => [
            ['description' => 'Tubagem e cablagem', 'unit' => 'm', 'quantity' => 200, 'unit_price_cents' => 1000],
            ['description' => 'Quadro elétrico', 'unit' => 'un', 'quantity' => 1, 'unit_price_cents' => 150000],
        ]])->assertCreated()->json('data');
        $this->postJson("{$base}/contracts/{$contractA['id']}/actions/activate")->assertOk();
        $draftA = $this->postJson("{$base}/contracts", ['partner_id' => $a, 'title' => 'Iluminação exterior', 'items' => [['description' => 'Candeeiros', 'unit' => 'un', 'quantity' => 6, 'unit_price_cents' => 9000]]])->json('data.id');
        $contractB = $this->postJson("{$base}/contracts", ['partner_id' => $b, 'title' => 'Pinturas', 'items' => [['description' => 'Pintura interior', 'unit' => 'm2', 'quantity' => 300, 'unit_price_cents' => 800]]])->json('data.id');
        $this->postJson("{$base}/contracts/{$contractB}/actions/activate")->assertOk();

        return ['owner' => $owner, 'tenant' => $tenant, 'project' => $project, 'a' => $a, 'b' => $b, 'contractA' => $contractA['id'], 'draftA' => $draftA,
            'contractB' => $contractB, 'items' => array_column($contractA['items'], 'id')];
    }

    private function invite(User $owner, int $partner, string $email, string $name): string
    {
        $this->actingAs($owner)->postJson("/api/v1/partners/{$partner}/portal-users", ['email' => $email, 'name' => $name])->assertCreated();
        $token = null;
        Mail::assertQueued(PortalInvitationMail::class, function (PortalInvitationMail $m) use ($email, &$token) {
            if ($m->hasTo(strtolower($email))) {
                $token = $m->token;
            }

            return true;
        });

        return $token;
    }

    public function test_invitation_new_account_and_strict_isolation(): void
    {
        Mail::fake();
        $s = $this->scenario();
        $token = $this->invite($s['owner'], $s['a'], 'Rita@EletroVouga.pt', 'Rita Matos');

        // A página do convite diz a empresa e o subempreiteiro; a conta ainda não existe.
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/portal-invitations/{$token}")->assertOk()
            ->assertJson(['data' => ['company' => 'Construções Lima', 'partner' => 'Eletro Vouga, Lda.', 'email' => 'rita@eletrovouga.pt', 'account_exists' => false]]);

        // Cria a conta pelo convite: sem empresa própria, email confirmado, acesso ativo.
        $this->withHeaders($this->spaHeaders)->postJson('/auth/register', [
            'name' => 'Rita Matos', 'email' => 'rita@eletrovouga.pt', 'password' => 'Segura2026obra', 'password_confirmation' => 'Segura2026obra',
            'accept_terms' => true, 'portal_token' => $token,
        ])->assertCreated();
        // Daqui em diante, pedidos de API normais (sem a sessão do registo).
        $this->flushHeaders();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $rita = User::query()->where('email', 'rita@eletrovouga.pt')->firstOrFail();
        $this->assertNotNull($rita->email_verified_at);
        $this->assertSame(1, Tenant::query()->count());
        $this->getJson("/api/v1/portal-invitations/{$token}")->assertNotFound();

        $me = $this->actingAs($rita)->getJson('/api/v1/me')->assertOk()->json('data');
        $this->assertSame([], $me['tenants']);
        $this->assertSame([['company' => 'Construções Lima', 'partner' => 'Eletro Vouga, Lda.']], array_map(fn ($a) => array_intersect_key($a, ['company' => 1, 'partner' => 1]), $me['portal']));
        $access = $me['portal'][0]['id'];

        // Só os contratos de A em curso ou fechados (o em preparação não aparece; os de B nunca).
        $list = $this->getJson("/api/v1/portal/{$access}/contracts")->assertOk()->json('data');
        $this->assertSame([$s['contractA']], array_column($list['contracts'], 'id'));
        $this->assertSame(350000, $list['contracts'][0]['value_cents']);
        $this->getJson("/api/v1/portal/{$access}/contracts/{$s['contractB']}")->assertNotFound();
        $this->getJson("/api/v1/portal/{$access}/contracts/{$s['draftA']}")->assertNotFound();

        // Não é membro da empresa: nada das rotas da empresa.
        $this->getJson('/api/v1/projects')->assertForbidden();
        $this->getJson("/api/v1/projects/{$s['project']}/contracts")->assertForbidden();
        $this->getJson('/api/v1/partners')->assertForbidden();

        // O acesso de outra pessoa, ou um acesso revogado, não serve.
        [$intruder] = $this->createCompany('Outra');
        $this->actingAs($intruder)->getJson("/api/v1/portal/{$access}/contracts")->assertNotFound();
        $portalUser = $this->actingAs($s['owner'])->getJson("/api/v1/partners/{$s['a']}/portal-users")->assertOk()->json('data.0');
        $this->assertSame('active', $portalUser['status']);
        $this->deleteJson("/api/v1/partners/{$s['a']}/portal-users/{$portalUser['id']}")->assertOk()->assertJsonPath('data.0.status', 'revoked');
        $this->actingAs($rita)->getJson("/api/v1/portal/{$access}/contracts")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/me')->json('data.portal'));
    }

    public function test_proposed_certificate_goes_to_the_company_and_the_decision_back_to_the_subcontractor(): void
    {
        Mail::fake();
        $s = $this->scenario();
        // Um utilizador que já tem conta aceita o convite com a sessão iniciada.
        $rita = User::factory()->create(['email' => 'rita@eletrovouga.pt']);
        $token = $this->invite($s['owner'], $s['a'], 'rita@eletrovouga.pt', 'Rita Matos');
        $this->actingAs($rita)->postJson('/api/v1/portal-invitations/accept', ['token' => $token])->assertOk();
        $access = $this->getJson('/api/v1/me')->json('data.portal.0.id');
        $url = "/api/v1/portal/{$access}/contracts/{$s['contractA']}";
        [$cable, $board] = $s['items'];
        $period = ['period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->toDateString()];

        // Um rascunho que a empresa está a preparar é interno: no portal só se diz que está em preparação.
        $draft = $this->actingAs($s['owner'])->postJson("/api/v1/projects/{$s['project']}/certificates", ['contract_id' => $s['contractA']] + $period)->assertCreated()->json('data.id');
        $detail = $this->actingAs($rita)->getJson($url)->assertOk()->json('data');
        $this->assertSame([], $detail['certificates']);
        $this->assertFalse($detail['can_propose']);
        $this->assertSame('Em preparação na empresa', $detail['pending_certificate']['status_label']);
        $this->actingAs($s['owner'])->deleteJson("/api/v1/projects/{$s['project']}/certificates/{$draft}")->assertNoContent();
        $this->actingAs($rita);

        // Validações: período futuro, artigo de outro contrato, quantidade acima do contrato, tudo a zero.
        $this->postJson("{$url}/proposals", ['period_start' => now()->toDateString(), 'period_end' => now()->addDay()->toDateString(), 'lines' => [['contract_item_id' => $cable, 'qty_period' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('period_end');
        $this->postJson("{$url}/proposals", $period + ['lines' => [['contract_item_id' => 999999, 'qty_period' => 1]]])->assertUnprocessable()->assertJsonPath('code', 'CONTRACT_ITEM_INVALID');
        $this->postJson("{$url}/proposals", $period + ['lines' => [['contract_item_id' => $cable, 'qty_period' => 250]]])->assertUnprocessable()->assertJsonPath('code', 'CERTIFICATE_OVER_CONTRACT');
        $this->postJson("{$url}/proposals", $period + ['lines' => [['contract_item_id' => $cable, 'qty_period' => 0]]])->assertUnprocessable()->assertJsonPath('code', 'CERTIFICATE_EMPTY');

        // Proposta: 120 m de cablagem = 1200 €, retenção 5 %. Entra como submetida e avisa quem aprova autos na obra.
        $detail = $this->postJson("{$url}/proposals", $period + ['notes' => 'Piso 0 e piso 1', 'lines' => [['contract_item_id' => $cable, 'qty_period' => 120], ['contract_item_id' => $board, 'qty_period' => 0]]])
            ->assertCreated()->json('data');
        $this->assertFalse($detail['can_propose']);
        $this->assertSame(['number' => 1, 'status' => 'submitted', 'amount_cents' => 120000, 'retention_cents' => 6000, 'proposed_here' => true],
            array_intersect_key($detail['certificates'][0], ['number' => 1, 'status' => 1, 'amount_cents' => 1, 'retention_cents' => 1, 'proposed_here' => 1]));
        $this->postJson("{$url}/proposals", $period + ['lines' => [['contract_item_id' => $cable, 'qty_period' => 1]]])->assertConflict()->assertJsonPath('code', 'CERTIFICATE_PENDING');
        Mail::assertQueued(ProjectNoticeMail::class, fn (ProjectNoticeMail $m) => $m->hasTo($s['owner']->email) && str_contains($m->subjectLine, 'Auto proposto pelo subempreiteiro'));

        // A empresa vê quem propôs, devolve (avisa a Rita), corrige e aprova (avisa outra vez).
        $base = "/api/v1/projects/{$s['project']}";
        $this->actingAs($s['owner']);
        $cert = collect($this->getJson("{$base}/certificates")->assertOk()->json('data'))->firstWhere('kind', 'contract');
        $this->assertSame('Rita Matos', $cert['proposed_by']);
        $this->postJson("{$base}/certificates/{$cert['id']}/reopen")->assertOk();
        Mail::assertQueued(PortalNoticeMail::class, fn (PortalNoticeMail $m) => $m->hasTo('rita@eletrovouga.pt') && str_contains($m->subjectLine, 'devolvido'));
        $full = $this->getJson("{$base}/certificates/{$cert['id']}")->json('data');
        $line = collect($full['lines'])->firstWhere('qty_period', 120);
        $this->putJson("{$base}/certificates/{$cert['id']}/lines/{$line['id']}", ['qty_period' => 100])->assertOk();
        $this->postJson("{$base}/certificates/{$cert['id']}/submit")->assertOk();
        $this->postJson("{$base}/certificates/{$cert['id']}/approve")->assertOk();
        Mail::assertQueued(PortalNoticeMail::class, fn (PortalNoticeMail $m) => str_contains($m->subjectLine, 'aprovado') && str_contains($m->url, '/portal/'));

        // No portal: autado e o que falta por artigo; já pode propor o seguinte.
        $detail = $this->actingAs($rita)->getJson($url)->assertOk()->json('data');
        $this->assertSame(100000, $detail['certified_cents']);
        $this->assertSame(5000, $detail['retained_cents']);
        $this->assertEquals([100, 100], [$detail['items'][0]['certified_qty'], $detail['items'][0]['remaining_qty']]);
        $this->assertTrue($detail['can_propose']);
    }

    public function test_documents_from_the_portal_reach_the_company(): void
    {
        Mail::fake();
        Storage::fake(config('obraconnect.files.disk'));
        $s = $this->scenario();
        $rita = User::factory()->create(['email' => 'rita@eletrovouga.pt']);
        $token = $this->invite($s['owner'], $s['a'], 'rita@eletrovouga.pt', 'Rita Matos');
        $this->actingAs($rita)->postJson('/api/v1/portal-invitations/accept', ['token' => $token])->assertOk();
        $access = $this->getJson('/api/v1/me')->json('data.portal.0.id');

        $pdf = UploadedFile::fake()->createWithContent('seguro.pdf', "%PDF-1.4\n% seguro\n%%EOF\n");
        $this->post("/api/v1/portal/{$access}/documents", ['title' => 'Seguro de acidentes de trabalho', 'reference' => 'AP-2026-77', 'expires_on' => now()->addYear()->toDateString(), 'file' => $pdf], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.has_file', true)->assertJsonPath('data.from_portal', true);
        $this->post("/api/v1/portal/{$access}/documents", ['title' => 'Script', 'file' => UploadedFile::fake()->create('x.exe', 10)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $doc = $this->getJson("/api/v1/portal/{$access}/documents")->assertOk()->json('data.0');
        $this->get("/api/v1/portal/{$access}/documents/{$doc['id']}/file")->assertOk();

        // A empresa vê-o na ficha do subempreiteiro e descarrega o ficheiro; o B não o vê.
        $this->actingAs($s['owner']);
        $partner = collect($this->getJson('/api/v1/partners')->json('data'))->firstWhere('id', $s['a']);
        $this->assertSame(1, $partner['portal_users_count']);
        $this->assertTrue($partner['documents'][0]['from_portal']);
        $this->get("/api/v1/partners/{$s['a']}/documents/{$doc['id']}/file")->assertOk();
        $this->get("/api/v1/partners/{$s['b']}/documents/{$doc['id']}/file")->assertNotFound();

        // Convite para um fornecedor (não subempreiteiro): recusado.
        $supplier = $this->postJson('/api/v1/partners', ['kind' => 'supplier', 'name' => 'Materiais, Lda.'])->json('data.id');
        $this->postJson("/api/v1/partners/{$supplier}/portal-users", ['email' => 'x@exemplo.pt', 'name' => 'X'])->assertUnprocessable();
    }
}
