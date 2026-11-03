<?php

namespace Tests\Feature\Projects;

use App\Models\Membership;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Tenancy\TenantContext;
use Tests\TestCase;

class WorkCalendarTest extends TestCase
{
    private const SIX_DAYS = ['1' => 8, '2' => 8, '3' => 8, '4' => 8, '5' => 8, '6' => 8, '7' => 0];

    public function test_every_company_gets_a_default_calendar_with_national_holidays(): void
    {
        $this->travelTo('2026-11-03 10:00');
        [$owner, $tenant] = $this->createCompany();

        $this->actingAs($owner)->getJson('/api/v1/work-calendars')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Calendário geral')
            ->assertJsonPath('data.0.is_default', true)
            ->assertJsonPath('data.0.weekly_total', 40)
            ->assertJsonPath('data.0.national_holiday_years', [2026, 2027]);

        $id = $this->getJson('/api/v1/work-calendars')->json('data.0.id');
        $this->getJson("/api/v1/work-calendars/{$id}/holidays?year=2026")->assertOk()
            ->assertJsonCount(13, 'data')
            ->assertJsonPath('data.0.name', 'Ano Novo')
            ->assertJsonPath('data.0.kind_label', 'Feriado nacional');

        // Empresas anteriores a esta versão: o calendário é criado quando é preciso.
        app(TenantContext::class)->runAs($tenant, fn () => WorkCalendar::query()->delete());
        $this->getJson('/api/v1/work-calendars')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_default', true);
    }

    public function test_days_off_one_day_or_a_period(): void
    {
        $this->travelTo('2026-11-03 10:00');
        [$owner] = $this->createCompany();
        $this->actingAs($owner);
        $calendar = $this->getJson('/api/v1/work-calendars')->json('data.0.id');

        // Férias coletivas de 3 a 14 de agosto: só os 10 dias de semana; o 15 (feriado) e fins de semana ficam como estão.
        $this->postJson("/api/v1/work-calendars/{$calendar}/holidays", ['date' => '2026-08-03', 'until' => '2026-08-16', 'name' => 'Férias coletivas', 'kind' => 'company'])
            ->assertCreated()->assertJsonCount(10, 'data');
        $this->postJson("/api/v1/work-calendars/{$calendar}/holidays", ['date' => '2026-08-03', 'until' => '2026-08-07', 'name' => 'Repetido', 'kind' => 'company'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->postJson("/api/v1/work-calendars/{$calendar}/holidays", ['date' => '2026-01-01', 'until' => '2026-04-01', 'name' => 'Longo', 'kind' => 'company'])
            ->assertUnprocessable()->assertJsonValidationErrors('until');
        $municipal = $this->postJson("/api/v1/work-calendars/{$calendar}/holidays", ['date' => '2026-06-24', 'name' => 'São João', 'kind' => 'municipal'])
            ->assertCreated()->json('data.0.id');

        $august = collect($this->getJson("/api/v1/work-calendars/{$calendar}/holidays?year=2026")->json('data'))
            ->filter(fn ($h) => $h['name'] === 'Férias coletivas')->pluck('id')->all();
        $this->deleteJson("/api/v1/work-calendars/{$calendar}/holidays", ['ids' => [...$august, $municipal]])->assertNoContent();
        $this->getJson("/api/v1/work-calendars/{$calendar}/holidays?year=2026")->assertJsonCount(13, 'data');

        $this->postJson("/api/v1/work-calendars/{$calendar}/national-holidays", ['year' => 2028, 'carnival' => true])
            ->assertOk()->assertJsonPath('data.added', 14);
        $this->postJson("/api/v1/work-calendars/{$calendar}/national-holidays", ['year' => 2028])->assertJsonPath('data.added', 0);
        $this->postJson("/api/v1/work-calendars/{$calendar}/national-holidays", ['year' => 2015])
            ->assertUnprocessable()->assertJsonValidationErrors('year');
    }

    public function test_project_calendar_month_and_term_in_working_days(): void
    {
        $this->travelTo('2026-04-29 10:00');
        [$owner, $tenant] = $this->createCompany();
        $this->actingAs($owner);

        // 20/4 a 8/5/2026: sem o 25 de Abril (sábado) e o 1 de Maio (sexta).
        $project = $this->postJson('/api/v1/projects', ['name' => 'Moradia', 'starts_on' => '2026-04-20', 'planned_end_on' => '2026-05-08'])->json('data.id');

        $data = $this->getJson("/api/v1/projects/{$project}/calendar?month=2026-04")->assertOk()->json('data');
        $this->assertSame('Calendário geral', $data['calendar']['name']);
        $this->assertFalse($data['calendar']['chosen']);
        $this->assertSame(21, $data['working_days']);
        $this->assertSame('Sexta-feira Santa', collect($data['days'])->firstWhere('date', '2026-04-03')['holiday']['name']);
        $this->assertFalse(collect($data['days'])->firstWhere('date', '2026-04-03')['working']);
        $this->assertSame(['calendar_days' => 19, 'working_days' => 14, 'working_days_done' => 8, 'working_days_left' => 6, 'missing_holiday_years' => []], $data['term']);

        // Calendário próprio com sábados: o 2 de maio passa a contar.
        $saturdays = $this->postJson('/api/v1/work-calendars', ['name' => 'Com sábados', 'weekly_hours' => self::SIX_DAYS])
            ->assertCreated()->assertJsonPath('data.weekly_total', 48)->json('data.id');
        $this->putJson("/api/v1/projects/{$project}", ['work_calendar_id' => $saturdays])->assertOk()
            ->assertJsonPath('data.work_calendar.name', 'Com sábados');
        $this->assertSame(15, $this->getJson("/api/v1/projects/{$project}/calendar")->json('data.term.working_days'));
        $this->assertSame([2030], $this->getJson("/api/v1/projects/{$project}/calendar?month=2030-01")->json('data.missing_holiday_years'));

        $this->deleteJson("/api/v1/work-calendars/{$saturdays}")->assertConflict()->assertJsonPath('code', 'CALENDAR_IN_USE');
        $general = app(TenantContext::class)->runAs($tenant, fn () => WorkCalendar::query()->where('is_default', true)->value('id'));
        $this->deleteJson("/api/v1/work-calendars/{$general}")->assertConflict()->assertJsonPath('code', 'CALENDAR_DEFAULT');

        $this->putJson("/api/v1/work-calendars/{$saturdays}", ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true);
        $this->getJson('/api/v1/work-calendars')->assertJsonPath('data.0.id', $saturdays)->assertJsonPath('data.1.is_default', false);
        $this->deleteJson("/api/v1/work-calendars/{$general}")->assertNoContent();

        $this->putJson("/api/v1/work-calendars/{$saturdays}", ['weekly_hours' => ['1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0, '6' => 0, '7' => 0]])
            ->assertUnprocessable()->assertJsonValidationErrors('weekly_hours');
        $this->putJson("/api/v1/work-calendars/{$saturdays}", ['weekly_hours' => ['1' => 8, '8' => 4]])->assertUnprocessable();
    }

    public function test_permissions_and_isolation(): void
    {
        [$owner, $tenant] = $this->createCompany();
        $project = $this->actingAs($owner)->postJson('/api/v1/projects', ['name' => 'Moradia'])->json('data.id');
        $calendar = $this->getJson('/api/v1/work-calendars')->json('data.0.id');

        $foreman = $this->addMember($tenant, 'encarregado');
        $this->actingAs($foreman)->getJson('/api/v1/work-calendars')->assertOk();
        $this->actingAs($foreman)->putJson("/api/v1/work-calendars/{$calendar}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($foreman)->postJson("/api/v1/work-calendars/{$calendar}/holidays", ['date' => '2026-06-24', 'name' => 'São João', 'kind' => 'municipal'])->assertForbidden();

        // Externo chamado para a obra: vê o calendário da obra, não os calendários da empresa.
        $sub = $this->addMember($tenant, 'subempreiteiro');
        $this->actingAs($sub)->getJson('/api/v1/work-calendars')->assertForbidden();
        $this->actingAs($sub)->getJson("/api/v1/projects/{$project}/calendar")->assertForbidden();
        $this->joinProject($owner, $tenant, $sub, $project);
        $this->actingAs($sub)->getJson("/api/v1/projects/{$project}/calendar")->assertOk();

        $this->actingAs($this->addMember($tenant, 'administrativo'))
            ->postJson("/api/v1/work-calendars/{$calendar}/holidays", ['date' => '2026-06-24', 'name' => 'São João', 'kind' => 'municipal'])->assertCreated();

        [$ownerB] = $this->createCompany('B');
        $this->actingAs($ownerB)->putJson("/api/v1/work-calendars/{$calendar}", ['name' => 'X'])->assertNotFound();
        $this->actingAs($ownerB)->postJson('/api/v1/projects', ['name' => 'Obra B', 'work_calendar_id' => $calendar])
            ->assertUnprocessable()->assertJsonValidationErrors('work_calendar_id');
    }

    private function joinProject(User $owner, $tenant, User $user, int $project): void
    {
        $id = app(TenantContext::class)->runAs($tenant, fn () => Membership::query()->where('user_id', $user->id)->value('id'));
        $this->actingAs($owner)->postJson("/api/v1/projects/{$project}/members", ['membership_id' => $id])->assertCreated();
    }
}
