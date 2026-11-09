<?php

namespace Tests\Feature\Economics;

use App\Mail\ProjectAlertsMail;
use App\Models\Membership;
use App\Models\ProjectAlert;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ForecastWatchTest extends TestCase
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

    /** @return array<string, string> tipo => nível dos alertas abertos */
    private function openAlerts(): array
    {
        return collect($this->getJson('/api/v1/alerts')->assertOk()->json('data'))->mapWithKeys(fn ($a) => [$a['type'] => $a['level']])->sortKeys()->all();
    }

    private function watch(): void
    {
        $this->artisan('obraconnect:forecast-watch')->assertSuccessful();
    }

    public function test_weekly_history_alerts_that_open_escalate_and_close_and_one_email_per_change(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-16 08:00', 'Europe/Lisbon'));
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia', 'status' => 'active', 'starts_on' => '2026-11-02', 'planned_end_on' => '2026-11-20'])
            ->assertCreated()->json('data.id');
        $base = "/api/v1/projects/{$project}";

        // Orçamento de 10 000 € de custo (o teto) e 9 500 € já comprometidos; uma atividade de 10 dias que ainda não começou.
        $budget = $this->postJson("{$base}/budgets", ['profit_pct' => 25])->json('data.id');
        $chapter = $this->postJson("{$base}/budgets/{$budget}/chapters", ['name' => 'Obra'])->json('data.chapters.0.id');
        $this->postJson("{$base}/budgets/{$budget}/items", ['chapter_id' => $chapter, 'code' => '1.1', 'description' => 'Paredes', 'unit' => 'm2', 'quantity' => 100, 'unit_cost_cents' => 10000])->assertCreated();
        $this->postJson("{$base}/budgets/{$budget}/approve")->assertOk();
        $this->postJson("{$base}/cost-entries", ['category' => 'subcontract', 'kind' => 'committed', 'description' => 'Alvenarias adjudicadas', 'amount_cents' => 950000, 'incurred_on' => '2026-11-10'])->assertCreated();
        $this->postJson("{$base}/activities", ['code' => 'A', 'name' => 'Alvenarias', 'duration_days' => 10])->assertCreated();

        // Primeira volta: previsão no teto (aviso) e fim a 27/11, depois do prazo de 20/11 (grave). Um email ao administrador (não há diretor de obra).
        $this->watch();
        $this->assertSame(['cost_ceiling' => 'warning', 'finish_contract' => 'danger'], $this->openAlerts());
        $this->assertSame('finish_contract', $this->getJson('/api/v1/alerts')->json('data.0.type'));
        $this->assertStringContainsString('depois do prazo contratual (20/11/2026)', str_replace("\u{00A0}", ' ', $this->getJson('/api/v1/alerts')->json('data.0.detail')));
        Mail::assertQueued(ProjectAlertsMail::class, fn (ProjectAlertsMail $m) => $m->hasTo($owner->email) && count($m->alerts) === 2);
        $this->assertSame(1, DB::table('forecast_snapshots')->count());

        // O mesmo dia outra vez: atualiza a fotografia, não repete emails.
        $this->watch();
        Mail::assertQueuedCount(1);
        $this->assertSame(1, DB::table('forecast_snapshots')->count());

        // Semana seguinte: mais 2 000 € comprometidos (acima do teto) e a atividade continua por começar
        // (agora acaba a 07/12: 1/12 é feriado).
        Carbon::setTestNow(Carbon::parse('2026-11-23 08:00', 'Europe/Lisbon'));
        $this->postJson("{$base}/cost-entries", ['category' => 'material', 'kind' => 'committed', 'description' => 'Tijolo', 'amount_cents' => 200000, 'incurred_on' => '2026-11-20'])->assertCreated();
        $this->watch();
        $this->assertSame(['cost_ceiling' => 'danger', 'cost_worse' => 'warning', 'finish_contract' => 'danger', 'finish_worse' => 'warning'], $this->openAlerts());
        $texts = str_replace("\u{00A0}", ' ', collect($this->getJson('/api/v1/alerts')->json('data'))->pluck('detail')->implode("\n"));
        $this->assertStringContainsString('passou de 10 000,00 € para 11 500,00 € (+1 500,00 €, +15,0 %) desde 16/11/2026', $texts);
        $this->assertStringContainsString('O fim previsto passou de 27/11/2026 para 07/12/2026 (+10 dias)', $texts);
        // O teto passou a grave (volta a avisar) e há dois alertas novos; o do prazo já tinha sido enviado.
        Mail::assertQueued(ProjectAlertsMail::class, fn (ProjectAlertsMail $m) => count($m->alerts) === 3 && $m->alerts[0]['level'] === 'danger');
        Mail::assertQueuedCount(2);

        // Histórico no separador Previsões e diferença no painel financeiro.
        $history = $this->getJson("{$base}/forecast")->assertOk()->json('data.history');
        $this->assertSame([1000000, 1150000], array_column($history['cost'], 'forecast_cents'));
        $this->assertSame([false, true], array_column($history['cost'], 'current'));
        $this->assertSame(['2026-11-27', '2026-12-07'], array_column($history['schedule'], 'forecast_finish_on'));
        $previous = $this->getJson('/api/v1/finance')->assertOk()->json('data.projects.0.outlook.previous');
        $this->assertSame(['taken_on' => '2026-11-16', 'eac_cents' => 1000000, 'forecast_finish_on' => '2026-11-27'], $previous);

        // Regras da empresa: sem o teto e sem o prazo contratual, esses alertas fecham-se na volta seguinte.
        $settings = $this->getJson('/api/v1/company/alerts')->assertOk()->json('data');
        $this->assertSame(90, $settings['rules']['cost_ceiling']['pct']);
        $settings['rules']['cost_ceiling']['pct'] = 120;
        $this->putJson('/api/v1/company/alerts', $settings)->assertUnprocessable()->assertJsonValidationErrors('rules.cost_ceiling.pct');
        $settings['rules']['cost_ceiling'] = ['on' => false, 'pct' => 95];
        $settings['rules']['finish_contract']['on'] = false;
        $this->putJson('/api/v1/company/alerts', $settings)->assertOk()->assertJsonPath('data.rules.cost_ceiling.pct', 95);
        $this->watch();
        $this->assertSame(['cost_worse' => 'warning', 'finish_worse' => 'warning'], $this->openAlerts());
        app(TenantContext::class)->runAs($tenant, fn () => $this->assertSame(2, ProjectAlert::query()->whereNotNull('resolved_at')->count()));

        // Cada pessoa só vê os alertas da parte da obra que vê: o encarregado o prazo, o administrativo o custo.
        $foreman = $this->teamMember($owner, $tenant, $project, 'encarregado');
        $this->actingAs($foreman);
        $this->assertSame(['finish_worse' => 'warning'], $this->openAlerts());
        $clerk = $this->addMember($tenant, 'administrativo');
        $this->actingAs($clerk);
        $this->assertSame(['cost_worse' => 'warning'], $this->openAlerts());
        // As regras mudam-se com company.update (administrador, administrativo); o encarregado só as vê.
        $this->actingAs($foreman)->getJson('/api/v1/company/alerts')->assertOk();
        $this->actingAs($foreman)->putJson('/api/v1/company/alerts', $settings)->assertForbidden();
        [$other] = $this->createCompany('Outra');
        $this->actingAs($other)->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data');

        // Alertas desligados: fecha tudo; o histórico continua.
        $this->actingAs($owner);
        $settings['enabled'] = false;
        $this->putJson('/api/v1/company/alerts', $settings)->assertOk();
        $this->watch();
        $this->assertSame([], $this->openAlerts());
        $this->assertSame(2, DB::table('forecast_snapshots')->count());
    }

    public function test_hold_points_and_supplier_documents_waiting_too_long(): void
    {
        Mail::fake();
        Storage::fake(config('obraconnect.files.disk'));
        config(['obraconnect.docintel.url' => '', 'obraconnect.docintel.key' => null]);
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00', 'Europe/Lisbon'));
        [$owner] = $this->createCompany();
        $this->actingAs($owner);
        $project = $this->postJson('/api/v1/projects', ['name' => 'Pavilhão', 'status' => 'active', 'starts_on' => '2026-11-02'])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/projects/{$project}/inspections", ['title' => 'Armaduras da laje', 'hold_point' => true, 'items' => [['text' => 'Armaduras']]])->assertCreated();
        $pdf = UploadedFile::fake()->createWithContent('fatura.pdf', "%PDF-1.4\n% fatura\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");
        $this->post('/api/v1/supplier-documents', ['file' => $pdf], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'to_review');

        // Ainda dentro dos prazos (5 dias para o ponto de paragem, 7 para as faturas).
        $this->watch();
        $this->assertSame([], $this->openAlerts());

        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00', 'Europe/Lisbon'));
        $this->watch();
        $alerts = collect($this->getJson('/api/v1/alerts')->json('data'))->keyBy('type');
        $this->assertSame(['hold_point', 'supplier_docs'], $alerts->keys()->sort()->values()->all());
        $this->assertStringContainsString('segura os trabalhos desde 02/11/2026 (há 8 dias)', str_replace("\u{00A0}", ' ', $alerts['hold_point']['detail']));
        $this->assertSame("/app/obras/{$project}?separador=inspecoes", $alerts['hold_point']['path']);
        $this->assertNull($alerts['supplier_docs']['project']);
        $this->assertSame('/app/faturas-fornecedores', $alerts['supplier_docs']['path']);
        $this->assertStringContainsString('1 documento está à espera de revisão há mais de 7 dias', str_replace("\u{00A0}", ' ', $alerts['supplier_docs']['detail']));
        Mail::assertQueued(ProjectAlertsMail::class, fn (ProjectAlertsMail $m) => $m->hasTo($owner->email) && count($m->alerts) === 2);
    }
}
