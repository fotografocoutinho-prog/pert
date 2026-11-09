<?php

namespace App\Alerts;

use App\Access\AccessResolver;
use App\Calendar\WorkCalendars;
use App\Enums\MembershipStatus;
use App\Enums\ProjectStatus;
use App\Enums\SupplierDocumentStatus;
use App\Forecasting\CostForecast;
use App\Forecasting\Explain;
use App\Forecasting\ScheduleForecast;
use App\Mail\ProjectAlertsMail;
use App\Models\ForecastSnapshot;
use App\Models\Inspection;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectAlert;
use App\Models\ProjectMember;
use App\Models\SupplierDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Vigia diário das obras de uma empresa: atualiza a fotografia semanal das previsões, abre e fecha os
 * alertas conforme as regras da empresa (AlertSettings) e envia um email por pessoa com os alertas
 * novos. Os alertas fecham-se sozinhos quando a condição desaparece; um alerta aberto não volta a
 * gerar email, a não ser que passe de aviso a grave.
 */
final class ForecastWatch
{
    /** Obras vigiadas (as concluídas e arquivadas ficam com o histórico que tinham). */
    public const WATCHED = [ProjectStatus::Planning, ProjectStatus::Active, ProjectStatus::Suspended];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly WorkCalendars $calendars,
        private readonly AccessResolver $access,
    ) {}

    public static function weekStart(CarbonInterface $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day->toDateString())->startOfWeek(CarbonInterface::MONDAY);
    }

    /**
     * @param  bool  $email  false = não envia agora (os alertas novos ficam para o próximo envio)
     * @return array{snapshots: int, open: int, opened: int, resolved: int, emails: int}
     */
    public function run(Tenant $tenant, CarbonImmutable $today, bool $email = true): array
    {
        return $this->tenants->runAs($tenant, function () use ($tenant, $today, $email): array {
            $settings = AlertSettings::for($tenant);
            $rules = $settings['rules'];
            $week = self::weekStart($today)->toDateString();
            $found = [];
            $snapshots = 0;

            $projects = Project::query()->whereIn('status', array_map(fn (ProjectStatus $s) => $s->value, self::WATCHED))->orderBy('id')->get();
            foreach ($projects as $project) {
                $cost = CostForecast::for($project, $today);
                $schedule = ScheduleForecast::for($project, $today, $this->calendars->forProject($project));
                $previous = ForecastSnapshot::query()->where('project_id', $project->id)->where('week_start', '<', $week)->orderByDesc('week_start')->first();
                // whereDate: no SQLite as datas ficam com hora.
                $snapshot = ForecastSnapshot::query()->where('project_id', $project->id)->whereDate('week_start', $week)->first()
                    ?? new ForecastSnapshot(['project_id' => $project->id, 'week_start' => $week]);
                $snapshot->fill(self::snapshot($cost, $schedule, $today))->save();
                $snapshots++;

                if ($settings['enabled']) {
                    foreach ($this->projectConditions($project, $cost, $schedule, $previous, $rules, $today) as $condition) {
                        $found[$this->key($tenant, $project->id, $condition['type'])] = $condition + ['project_id' => $project->id];
                    }
                }
            }

            if ($settings['enabled'] && $rules['supplier_docs']['on']) {
                if ($condition = $this->supplierDocs((int) $rules['supplier_docs']['days'], $today)) {
                    $found[$this->key($tenant, null, $condition['type'])] = $condition + ['project_id' => null];
                }
            }

            [$opened, $resolved] = $this->sync($found);

            $emails = 0;
            if (! $settings['enabled'] || ! $settings['email'] || $tenant->setting('demo')) {
                // Sem email (desligado na empresa, ou a empresa de demonstração): ficam dados por avisados.
                ProjectAlert::query()->open()->whereNull('notified_at')->update(['notified_at' => now()]);
            } elseif ($email) {
                $emails = $this->notify($tenant);
            }

            return ['snapshots' => $snapshots, 'open' => count($found), 'opened' => $opened, 'resolved' => $resolved, 'emails' => $emails];
        });
    }

    /** @return array<string, mixed> */
    public static function snapshot(array $cost, array $schedule, CarbonImmutable $today): array
    {
        $c = (bool) ($cost['available'] ?? false);
        $s = (bool) ($schedule['available'] ?? false);

        return [
            'taken_on' => $today->toDateString(),
            'cost_available' => $c,
            'bac_cents' => $c ? $cost['bac_cents'] : null,
            'forecast_cents' => $c ? $cost['forecast_cents'] : null,
            'ceiling_cents' => $c ? $cost['ceiling_cents'] : null,
            'actual_cents' => $c ? $cost['actual_cents'] : null,
            'committed_cents' => $c ? $cost['committed_cents'] : null,
            'sale_cents' => $c ? $cost['sale_cents'] : null,
            'cost_progress_pct' => $c ? $cost['progress_pct'] : null,
            'margin_pct' => $c ? $cost['margin_pct'] : null,
            'method' => $c ? $cost['method'] : null,
            'confidence' => $c ? $cost['confidence'] : null,
            'cost_alert' => $c ? $cost['alert'] : null,
            'schedule_available' => $s,
            'schedule_status' => $s ? $schedule['status'] : null,
            'forecast_finish_on' => $s ? $schedule['forecast_finish_on'] : null,
            'planned_finish_on' => $s ? $schedule['planned_finish_on'] : null,
            'contract_end_on' => $s ? $schedule['contract_end_on'] : null,
            'delay_plan_days' => $s ? $schedule['delay_plan_days'] : null,
            'delay_contract_days' => $s ? $schedule['delay_contract_days'] : null,
            'schedule_progress_pct' => $s ? $schedule['progress_pct'] : null,
            'planned_progress_pct' => $s ? $schedule['planned_progress_pct'] : null,
        ];
    }

    /** @return list<array{type: AlertType, level: string, title: string, detail: string, data: array<string, mixed>}> */
    private function projectConditions(Project $project, array $cost, array $schedule, ?ForecastSnapshot $previous, array $rules, CarbonImmutable $today): array
    {
        $out = [];
        $m = Explain::money(...);
        $d = Explain::date(...);

        if ($cost['available'] ?? false) {
            $forecast = (int) $cost['forecast_cents'];
            $ceiling = (int) ($cost['ceiling_cents'] ?? 0);
            if ($rules['cost_ceiling']['on'] && $ceiling > 0) {
                $used = $forecast * 100 / $ceiling;
                if ($forecast > $ceiling) {
                    $out[] = self::condition(AlertType::CostCeiling, 'danger', 'Custo previsto acima do teto',
                        "A previsão de custo no fim ({$m($forecast)}) passa o teto da obra ({$m($ceiling)}) em {$m($forecast - $ceiling)}.",
                        ['forecast_cents' => $forecast, 'ceiling_cents' => $ceiling]);
                } elseif ($used >= (float) $rules['cost_ceiling']['pct']) {
                    $out[] = self::condition(AlertType::CostCeiling, 'warning', 'Custo previsto perto do teto',
                        "A previsão de custo no fim ({$m($forecast)}) já vai em ".Explain::pct($used, 1)." do teto da obra ({$m($ceiling)}).",
                        ['forecast_cents' => $forecast, 'ceiling_cents' => $ceiling]);
                }
            }

            if ($rules['cost_worse']['on'] && $previous?->cost_available && $previous->forecast_cents > 0) {
                $delta = $forecast - $previous->forecast_cents;
                $pct = $delta * 100 / $previous->forecast_cents;
                if ($pct >= (float) $rules['cost_worse']['pct']) {
                    $out[] = self::condition(AlertType::CostWorse, 'warning', 'Custo previsto subiu numa semana',
                        "A previsão de custo no fim passou de {$m($previous->forecast_cents)} para {$m($forecast)} (+{$m($delta)}, +".Explain::pct($pct, 1).") desde {$d($previous->taken_on)}.",
                        ['from_cents' => $previous->forecast_cents, 'to_cents' => $forecast, 'since' => $previous->taken_on->toDateString()]);
                }
            }

            $age = $cost['measurement_age_days'] ?? null;
            if ($rules['measurements_stale']['on'] && $project->status === ProjectStatus::Active && $age !== null && $age > (int) $rules['measurements_stale']['days']) {
                $out[] = self::condition(AlertType::MeasurementsStale, 'warning', 'Medições atrasadas',
                    "A última medição é de {$d($cost['last_measured_on'])} (há ".Explain::days($age).'). Sem medir o que está feito, a previsão de custo perde confiança.',
                    ['last_measured_on' => $cost['last_measured_on'], 'age_days' => $age]);
            }
        }

        if (($schedule['available'] ?? false) && $schedule['status'] !== 'finished') {
            $finish = $schedule['forecast_finish_on'];
            if ($rules['finish_contract']['on'] && ($schedule['delay_contract_days'] ?? 0) > 0) {
                $out[] = self::condition(AlertType::FinishContract, 'danger', 'Fim previsto depois do prazo contratual',
                    "A obra acaba a {$d($finish)}, ".Explain::days((int) $schedule['delay_contract_days'])." depois do prazo contratual ({$d($schedule['contract_end_on'])}).",
                    ['forecast_finish_on' => $finish, 'contract_end_on' => $schedule['contract_end_on'], 'days' => $schedule['delay_contract_days']]);
            }

            if ($rules['finish_worse']['on'] && $previous?->forecast_finish_on && $finish) {
                $slip = (int) $previous->forecast_finish_on->diffInDays(CarbonImmutable::parse($finish), false);
                if ($slip >= (int) $rules['finish_worse']['days']) {
                    $out[] = self::condition(AlertType::FinishWorse, 'warning', 'Fim previsto atrasou numa semana',
                        "O fim previsto passou de {$d($previous->forecast_finish_on)} para {$d($finish)} (+".Explain::days($slip).") desde {$d($previous->taken_on)}.",
                        ['from' => $previous->forecast_finish_on->toDateString(), 'to' => $finish, 'days' => $slip, 'since' => $previous->taken_on->toDateString()]);
                }
            }
        }

        if ($rules['hold_point']['on']) {
            $limit = $today->subDays((int) $rules['hold_point']['days'])->endOfDay();
            $holds = Inspection::query()->where('project_id', $project->id)->holdingWork()
                ->where('created_at', '<=', $limit->utc())
                ->orderBy('number')->get(['id', 'number', 'title', 'created_at']);
            if ($holds->isNotEmpty()) {
                $first = $holds->first();
                $since = CarbonImmutable::parse($holds->min('created_at'))->timezone('Europe/Lisbon');
                $days = (int) $since->startOfDay()->diffInDays($today);
                $detail = $holds->count() === 1
                    ? "A inspeção n.º {$first->number}, «{$first->title}», é um ponto de paragem e segura os trabalhos desde {$d($since)} (há ".Explain::days($days).').'
                    : $holds->count().' pontos de paragem seguram os trabalhos há mais de '.Explain::days((int) $rules['hold_point']['days']).': inspeções '
                        .$holds->map(fn (Inspection $i) => "n.º {$i->number}")->implode(', ').'.';
                $out[] = self::condition(AlertType::HoldPoint, 'warning', 'Ponto de paragem por libertar', $detail,
                    ['inspections' => $holds->pluck('number')->all(), 'since' => $since->toDateString()]);
            }
        }

        return $out;
    }

    private function supplierDocs(int $days, CarbonImmutable $today): ?array
    {
        $limit = $today->subDays($days)->endOfDay()->utc();
        $waiting = SupplierDocument::query()->where('status', SupplierDocumentStatus::ToReview->value)->where('created_at', '<=', $limit);
        $count = (clone $waiting)->count();
        if ($count === 0) {
            return null;
        }
        $oldest = CarbonImmutable::parse((clone $waiting)->min('created_at'))->timezone('Europe/Lisbon');

        return self::condition(AlertType::SupplierDocs, 'warning', 'Faturas de fornecedores por rever',
            ($count === 1 ? '1 documento está' : "{$count} documentos estão").' à espera de revisão há mais de '.Explain::days($days)
            .' (o mais antigo chegou a '.Explain::date($oldest).'). Enquanto não forem lançados, os custos das obras ficam por contar.',
            ['count' => $count, 'oldest' => $oldest->toDateString()]);
    }

    private static function condition(AlertType $type, string $level, string $title, string $detail, array $data): array
    {
        return ['type' => $type, 'level' => $level, 'title' => $title, 'detail' => $detail, 'data' => $data];
    }

    private function key(Tenant $tenant, ?int $projectId, AlertType $type): string
    {
        return $tenant->id.':'.($projectId ?? 0).':'.$type->value;
    }

    /** @return array{0: int, 1: int} abertos, fechados */
    private function sync(array $found): array
    {
        $open = ProjectAlert::query()->open()->get()->keyBy('open_key');
        $opened = 0;
        $resolved = 0;

        foreach ($found as $key => $c) {
            $attributes = [
                'level' => $c['level'],
                'title' => $c['title'],
                'detail' => $c['detail'],
                'tab' => $c['type']->tab(),
                'data' => $c['data'],
                'last_seen_at' => now(),
            ];
            /** @var ProjectAlert|null $alert */
            $alert = $open->get($key);
            if ($alert) {
                $escalated = $alert->level === 'warning' && $c['level'] === 'danger';
                $alert->fill($attributes);
                if ($escalated) {
                    $alert->notified_at = null;
                }
                $alert->save();
            } else {
                ProjectAlert::query()->create($attributes + [
                    'project_id' => $c['project_id'],
                    'type' => $c['type'],
                    'open_key' => $key,
                    'opened_at' => now(),
                ]);
                $opened++;
            }
        }

        foreach ($open as $key => $alert) {
            if (! isset($found[$key])) {
                $alert->forceFill(['resolved_at' => now(), 'open_key' => null])->save();
                $resolved++;
            }
        }

        return [$opened, $resolved];
    }

    /** Um email por pessoa com os alertas novos (ou agravados) que lhe dizem respeito. */
    private function notify(Tenant $tenant): int
    {
        $pending = ProjectAlert::query()->open()->whereNull('notified_at')->with('project:id,code,name')->orderBy('id')->get();
        if ($pending->isEmpty()) {
            return 0;
        }

        /** @var array<int, array{user: User, alerts: list<ProjectAlert>}> $byUser */
        $byUser = [];
        foreach ($pending as $alert) {
            foreach ($this->recipients($alert) as $user) {
                $byUser[$user->id] ??= ['user' => $user, 'alerts' => []];
                $byUser[$user->id]['alerts'][] = $alert;
            }
            $alert->forceFill(['notified_at' => now()])->save();
        }

        foreach ($byUser as ['user' => $user, 'alerts' => $alerts]) {
            Mail::to($user->email)->queue(new ProjectAlertsMail($tenant->name, collect($alerts)
                ->sortBy(fn (ProjectAlert $a) => $a->level === 'danger' ? 0 : 1)->values()
                ->map(fn (ProjectAlert $a) => [
                    'level' => $a->level,
                    'title' => $a->title,
                    'detail' => $a->detail,
                    'project' => $a->project ? "{$a->project->code} · {$a->project->name}" : null,
                    'url' => url($a->path()),
                ])->all()));
        }

        return count($byUser);
    }

    /**
     * Diretores de obra da equipa que vêem essa parte da obra; sem nenhum, os administradores da
     * empresa. Os alertas da empresa (faturas por rever) vão para quem tem essa permissão.
     *
     * @return Collection<int, User>
     */
    private function recipients(ProjectAlert $alert): Collection
    {
        $permissions = $alert->type->permissions();
        $canSee = fn (Membership $m, ?Project $p) => collect($permissions)->contains(fn (string $perm) => $this->access->can($m, $perm, $p));
        $active = Membership::query()->where('status', MembershipStatus::Active->value)->with(['user:id,name,email', 'role'])->get();

        if ($alert->project === null) {
            return $active->filter(fn (Membership $m) => $canSee($m, null))->map(fn (Membership $m) => $m->user)->unique('id')->values();
        }

        $directors = ProjectMember::query()->where('project_id', $alert->project_id)
            ->whereHas('role', fn ($q) => $q->where('template_key', 'diretor_obra'))
            ->with(['membership.user:id,name,email', 'membership.role'])->get()
            ->map(fn (ProjectMember $pm) => $pm->membership)
            ->filter(fn (?Membership $m) => $m && $m->isActive() && $canSee($m, $alert->project));
        if ($directors->isNotEmpty()) {
            return $directors->map(fn (Membership $m) => $m->user)->unique('id')->values();
        }

        return $active->filter(fn (Membership $m) => $m->role?->template_key === 'admin')->map(fn (Membership $m) => $m->user)->unique('id')->values();
    }
}
