<?php

namespace Tests\Feature\Demo;

use App\Demo\DemoCompany;
use App\Economics\CostReport;
use App\Inspections\InspectionHistory;
use App\Models\Inspection;
use App\Models\Media;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoCompanyTest extends TestCase
{
    public function test_builds_the_demo_company_with_every_area_and_recreates_it(): void
    {
        Storage::fake(config('obraconnect.files.disk'));
        Mail::fake();
        [$owner, $realCompany] = $this->createCompany('Empresa real');

        $this->artisan('obraconnect:demo', ['--admin' => [$owner->email, 'ninguem@exemplo.pt']])
            ->expectsOutputToContain('Administrador: '.$owner->email)
            ->expectsOutputToContain('Não existe nenhum utilizador com o email ninguem@exemplo.pt')
            ->assertSuccessful();

        $demo = DemoCompany::find();
        $this->assertNotNull($demo);
        // O administrador real fica com acesso, mas continua na sua empresa.
        $this->assertSame($realCompany->id, $owner->refresh()->current_tenant_id);
        $this->actingAs($owner)->getJson('/api/v1/me')->assertOk()->assertJsonFragment(['name' => DemoCompany::NAME]);

        app(TenantContext::class)->runAs($demo, function (): void {
            $alerts = Project::query()->orderBy('code')->get()->mapWithKeys(fn (Project $p) => [$p->code => CostReport::for($p)['alert']])->all();
            $this->assertSame(['ATL-26-00' => 'warning', 'ATL-26-01' => 'ok', 'ATL-26-02' => 'over', 'ATL-26-03' => null], $alerts);

            foreach (Inspection::query()->with('events')->get() as $inspection) {
                $this->assertNull(InspectionHistory::firstBroken($inspection->events->sortBy('sequence')->values()), "Cadeia da inspeção {$inspection->number}");
            }
            $this->assertSame(0, Media::query()->where('status', '!=', 'ready')->count());
            $this->assertGreaterThan(20, Media::query()->count());
            foreach (['site_diary_entries', 'documents', 'issues', 'budgets', 'measurements', 'certificates', 'contracts', 'purchase_orders', 'time_entries', 'equipment_usages', 'activities', 'activity_dependencies'] as $table) {
                $this->assertGreaterThan(0, DB::table($table)->count(), $table);
            }
        });
        $team = User::query()->where('email', 'like', '%@'.DemoCompany::DOMAIN)->count();
        $this->assertSame(15, $team);
        Mail::assertNothingSent();

        // Sem --fresh não mexe; com --fresh apaga tudo (dados, ficheiros, equipa fictícia) e volta a criar.
        $this->artisan('obraconnect:demo')->expectsOutputToContain('já existe')->assertSuccessful();
        $this->assertSame($demo->id, DemoCompany::find()->id);

        $this->artisan('obraconnect:demo', ['--fresh' => true, '--admin' => [$owner->email]])->assertSuccessful();
        $new = DemoCompany::find();
        $this->assertNotSame($demo->id, $new->id);
        $this->assertNull(Tenant::query()->find($demo->id));
        $this->assertSame(0, DB::table('projects')->where('tenant_id', $demo->id)->count());
        $this->assertSame([], Storage::disk(config('obraconnect.files.disk'))->allFiles("tenants/{$demo->id}"));
        $this->assertSame($team, User::query()->where('email', 'like', '%@'.DemoCompany::DOMAIN)->count());
        $this->assertNotNull(Tenant::query()->find($realCompany->id));
    }
}
