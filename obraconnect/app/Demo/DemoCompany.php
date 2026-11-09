<?php

namespace App\Demo;

use App\Actions\Tenancy\RegisterCompany;
use App\Alerts\ForecastWatch;
use App\Billing\StorageUsage;
use App\Calendar\PortugueseHolidays;
use App\Calendar\WorkCalendars;
use App\Documents\DocumentStorage;
use App\Economics\BudgetCalculator;
use App\Economics\CertificateBuilder;
use App\Economics\CostReport;
use App\Enums\InspectionStatus as S;
use App\Enums\SubscriptionStatus;
use App\Forecasting\CostForecast;
use App\Forecasting\ScheduleForecast;
use App\Inspections\EvidenceIndex;
use App\Inspections\InspectionHistory;
use App\Invoicing\InvoiceDrafts;
use App\Media\MediaStorage;
use App\Models\Activity;
use App\Models\ActivityDependency;
use App\Models\Article;
use App\Models\ArticleComponent;
use App\Models\Budget;
use App\Models\BudgetChapter;
use App\Models\BudgetItem;
use App\Models\Certificate;
use App\Models\ChecklistTemplate;
use App\Models\Client;
use App\Models\ConstructionElement;
use App\Models\Contract;
use App\Models\ContractItem;
use App\Models\CostCenter;
use App\Models\CostEntry;
use App\Models\Department;
use App\Models\DiaryEntry;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\Equipment;
use App\Models\EquipmentUsage;
use App\Models\ForecastSnapshot;
use App\Models\Inspection;
use App\Models\InvoiceDraft;
use App\Models\Issue;
use App\Models\IssueEvent;
use App\Models\MaintenanceRecord;
use App\Models\Measurement;
use App\Models\Membership;
use App\Models\Partner;
use App\Models\Plan;
use App\Models\PriceResource;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\ProjectMember;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Role;
use App\Models\Specialty;
use App\Models\Subscription;
use App\Models\SupplierDocument;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Worker;
use App\Purchasing\SupplierDocuments;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Empresa de demonstração "Atlântico Construções" com dados fictícios em todas as áreas: equipa
 * (emails em domínio reservado .test, que nunca recebem emails), departamentos, centros de custo,
 * clientes, biblioteca de preços, quatro obras em fases diferentes com estrutura, diário com
 * fotografias, documentos, inspeções (com pontos de paragem), ocorrências e NC, orçamentos,
 * medições e autos, compras, subempreitadas, horas, equipamentos, custos com teto e planeamento.
 *
 * As datas são relativas ao dia em que se corre: a obra principal está sempre "a meio".
 */
final class DemoCompany
{
    public const DOMAIN = 'atlantico-demo.test';

    public const EMAIL = 'geral@'.self::DOMAIN;

    public const NAME = 'Atlântico Construções (demonstração)';

    private CarbonImmutable $today;

    private CarbonImmutable $realNow;

    private Tenant $tenant;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Membership> */
    private array $memberships = [];

    /** @var array<string, Role> */
    private array $roles = [];

    /** @var array<string, int> */
    private array $departments = [];

    /** @var array<string, int> */
    private array $costCenters = [];

    /** @var array<string, int> */
    private array $specialties = [];

    /** @var array<string, int> */
    private array $clients = [];

    /** @var array<string, PriceResource> */
    private array $resources = [];

    /** @var array<string, Article> */
    private array $articles = [];

    /** @var array<string, ChecklistTemplate> */
    private array $templates = [];

    /** @var array<string, Partner> */
    private array $partners = [];

    /** @var array<string, Worker> */
    private array $workers = [];

    /** @var array<string, Equipment> */
    private array $equipment = [];

    /** @var array<string, bool> */
    private array $holidays = [];

    /** @var list<string> */
    private array $tempFiles = [];

    private int $photoSeed = 1;

    /** @var list<array{0: Certificate, 1: CarbonImmutable, 2: string|null, 3: CarbonImmutable|null}> */
    private array $pendingInvoices = [];

    /** @var callable(string): void */
    private $log;

    public function __construct(
        private readonly RegisterCompany $register,
        private readonly TenantContext $tenants,
        private readonly WorkCalendars $calendars,
        private readonly MediaStorage $media,
        private readonly DocumentStorage $documents,
        private readonly InspectionHistory $history,
    ) {}

    public static function find(): ?Tenant
    {
        return Tenant::query()->where('email', self::EMAIL)->first();
    }

    /**
     * @param  list<string>  $adminEmails  utilizadores já existentes que ficam administradores
     * @param  callable(string): void  $log
     */
    public function build(array $adminEmails, callable $log): Tenant
    {
        $this->log = $log;
        $this->today = CarbonImmutable::today('Europe/Lisbon');
        $this->realNow = CarbonImmutable::now();
        foreach ([$this->today->year - 1, $this->today->year, $this->today->year + 1] as $year) {
            foreach (array_keys(PortugueseHolidays::national($year)) as $date) {
                $this->holidays[$date] = true;
            }
        }
        // As pré-visualizações das fotografias fazem-se já, em vez de ficarem na fila.
        config(['queue.default' => 'sync']);

        try {
            return Model::unguarded(function () use ($adminEmails): Tenant {
                $owner = $this->user('helena.marques', 'Helena Marques');
                $this->at($this->today->subMonths(8)->setTime(9, 30));
                $tenant = $this->register->handle($owner, ['name' => self::NAME, 'legal_name' => 'Atlântico Construções, Lda.']);
                $settings = $tenant->settings ?? [];
                $tenant->forceFill([
                    'email' => self::EMAIL,
                    'phone' => '234 000 000',
                    'postal_code' => '3800-000',
                    'city' => 'Aveiro',
                    'settings' => $settings + ['demo' => true],
                ])->save();
                $this->tenant = $tenant;

                $this->tenants->runAs($tenant, function () use ($adminEmails, $owner): void {
                    $this->subscription();
                    $this->roles = Role::query()->get()->keyBy(fn (Role $r) => $r->template_key ?? $r->key)->all();
                    $this->memberships['helena.marques'] = Membership::query()->where('user_id', $owner->id)->firstOrFail();
                    $this->organization();
                    $this->team();
                    $this->addAdmins($adminEmails);
                    $this->library();
                    $this->partnersWorkersEquipment();
                    ($this->log)('Obra principal (moradia)…');
                    $this->mainProject();
                    ($this->log)('Reabilitação, pavilhão e loja…');
                    $this->rehabProject();
                    $this->plannedProject();
                    $this->finishedProject();
                    $this->flushInvoices();
                    $this->forecastHistory();
                });

                return $tenant;
            });
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            foreach ($this->tempFiles as $file) {
                @unlink($file);
            }
        }
    }

    /** @param  list<string>  $emails  @return list<string> os que ficaram administradores */
    public function attachAdmins(Tenant $tenant, array $emails): array
    {
        $this->tenant = $tenant;

        return $this->tenants->runAs($tenant, function () use ($emails): array {
            $this->roles = Role::query()->get()->keyBy(fn (Role $r) => $r->template_key ?? $r->key)->all();

            return $this->addAdmins($emails);
        });
    }

    /** Apaga a empresa de demonstração, os seus ficheiros e os utilizadores fictícios que só lá estão. */
    public function destroy(Tenant $tenant): void
    {
        $id = $tenant->id;
        // Só as tabelas desta base de dados (em MySQL/MariaDB a listagem pode trazer outras bases visíveis ao utilizador).
        $connection = DB::connection();
        $database = $connection->getDriverName() === 'sqlite' ? null : $connection->getDatabaseName();
        $tables = collect(Schema::getTables())
            ->filter(fn (array $t) => $database === null || ($t['schema'] ?? $database) === $database)
            ->pluck('name')->unique()
            ->filter(fn (string $t) => $t !== 'tenants' && Schema::hasColumn($t, 'tenant_id'))->values();
        $roleIds = DB::table('roles')->where('tenant_id', $id)->pluck('id');
        $demoUsers = DB::table('users')->where('email', 'like', '%@'.self::DOMAIN)->pluck('id');

        Schema::disableForeignKeyConstraints();
        try {
            DB::table('permission_role')->whereIn('role_id', $roleIds)->delete();
            // Com as chaves desligadas (MySQL) sai tudo à primeira. Onde não se podem desligar (SQLite dentro
            // de uma transação), repete: em cada volta saem as tabelas de que já nada depende.
            $remaining = $tables->all();
            for ($pass = 0; $remaining !== [] && $pass < 15; $pass++) {
                foreach ($remaining as $k => $table) {
                    try {
                        DB::table($table)->where('tenant_id', $id)->delete();
                        unset($remaining[$k]);
                    } catch (QueryException) {
                        // Ainda há linhas noutras tabelas a apontar para esta; fica para a próxima volta.
                    }
                }
            }
            if ($remaining !== []) {
                throw new RuntimeException('Não foi possível apagar a empresa de demonstração: '.implode(', ', $remaining));
            }
            DB::table('users')->where('current_tenant_id', $id)->update(['current_tenant_id' => null]);
            DB::table('tenants')->where('id', $id)->delete();

            $gone = $demoUsers->diff(DB::table('memberships')->whereIn('user_id', $demoUsers)->pluck('user_id'))->values();
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $gone)->delete();
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->whereIn('user_id', $gone)->delete();
            }
            DB::table('users')->whereIn('id', $gone)->delete();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
        Storage::disk(config('obraconnect.files.disk'))->deleteDirectory("tenants/{$id}");
    }

    // ------------------------------------------------------------------ empresa e equipa

    private function subscription(): void
    {
        $plan = Plan::query()->where('code', 'business')->first() ?? Plan::query()->where('is_active', true)->orderByDesc('sort_order')->first();
        Subscription::query()->where('tenant_id', $this->tenant->id)->update([
            'plan_id' => $plan?->id,
            'status' => SubscriptionStatus::Active->value,
            'trial_ends_at' => null,
            'current_period_starts_at' => $this->today->subMonths(8),
            'current_period_ends_at' => null,
            'notes' => 'Empresa de demonstração (dados fictícios).',
        ]);
    }

    private function organization(): void
    {
        foreach ([
            'direcao' => ['Direção', 'Gerência e direção técnica'],
            'producao' => ['Produção', 'Direção de obra, encarregados e equipas'],
            'orcamentacao' => ['Orçamentação e Compras', 'Medições, orçamentos, consultas e encomendas'],
            'qsa' => ['Qualidade, Segurança e Ambiente', 'Inspeções, não conformidades e segurança em obra'],
            'administrativo' => ['Administrativo e Financeiro', 'Faturação, pagamentos e recursos humanos'],
            'estaleiro' => ['Estaleiro e Equipamentos', 'Estaleiro central, frota e manutenção'],
        ] as $key => [$name, $description]) {
            $this->departments[$key] = Department::query()->create(['name' => $name, 'description' => $description])->id;
        }

        $owner = $this->users['helena.marques']->id;
        foreach ([
            ['100', 'Produção', null], ['110', 'Obras de habitação', '100'], ['120', 'Reabilitação', '100'], ['130', 'Obras industriais e comerciais', '100'],
            ['200', 'Estrutura central', null], ['210', 'Estaleiro central e equipamentos', '200'], ['220', 'Serviços administrativos', '200'],
        ] as [$code, $name, $parent]) {
            $this->costCenters[$code] = CostCenter::query()->create([
                'code' => $code, 'name' => $name, 'parent_id' => $parent ? $this->costCenters[$parent] : null, 'created_by_user_id' => $owner,
            ])->id;
        }

        $this->specialties = Specialty::query()->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        $calendar = $this->calendars->default();
        foreach ([$this->today->year, $this->today->year + 1] as $year) {
            $this->calendars->addNationalHolidays($calendar, $year);
        }
        $calendar->holidays()->firstOrCreate(['date' => $this->today->year.'-05-12'], ['name' => 'Feriado municipal (Aveiro)', 'kind' => 'municipal']);

        foreach ([
            'tavares' => ['private', 'Luís e Ana Tavares', 'luis.tavares@'.self::DOMAIN, '910 000 001', null, 'Rua das Glicínias, 14', '3810-000', 'Aveiro'],
            'ria' => ['company', 'Ria Investimentos Imobiliários, S.A.', 'obras@ria-investimentos.'.self::DOMAIN, '234 000 101', 'Eng. Jorge Matos', 'Avenida do Cais, 200', '3800-000', 'Aveiro'],
            'vale' => ['company', 'Metalúrgica do Vale, Lda.', 'geral@metalurgica-vale.'.self::DOMAIN, '234 000 202', 'Dr.ª Patrícia Leal', 'Zona Industrial, lote 7', '3770-000', 'Oliveira do Bairro'],
            'otica' => ['company', 'Ótica Central, Lda.', 'loja@otica-central.'.self::DOMAIN, '234 000 303', 'Sr. Vasco Pinho', 'Praça da República, 3', '3800-000', 'Aveiro'],
            'municipio' => ['public', 'Município de Vila Exemplo', 'obras@cm-vila-exemplo.'.self::DOMAIN, '234 000 404', 'Divisão de Obras', 'Paços do Concelho', '3700-000', 'Vila Exemplo'],
        ] as $key => [$type, $name, $email, $phone, $contact, $address, $postal, $city]) {
            $this->clients[$key] = Client::query()->create([
                'type' => $type, 'name' => $name, 'email' => $email, 'phone' => $phone, 'contact_name' => $contact,
                'address' => $address, 'postal_code' => $postal, 'city' => $city, 'country_code' => 'PT',
                'notes' => 'Cliente fictício (demonstração).', 'created_by_user_id' => $owner,
            ])->id;
        }
    }

    private function team(): void
    {
        $this->memberships['helena.marques']->forceFill(['job_title' => 'Gerente', 'department_id' => $this->departments['direcao']])->save();
        foreach ([
            ['rui.barbosa', 'Rui Barbosa', 'diretor_tecnico', 'direcao', 'Diretor técnico'],
            ['ricardo.sousa', 'Ricardo Sousa', 'diretor_obra', 'producao', 'Diretor de obra'],
            ['marta.lopes', 'Marta Lopes', 'diretor_obra', 'producao', 'Diretora de obra'],
            ['joaquim.pires', 'Joaquim Pires', 'encarregado', 'producao', 'Encarregado geral'],
            ['antonio.ramos', 'António Ramos', 'encarregado', 'producao', 'Encarregado'],
            ['sofia.carvalho', 'Sofia Carvalho', 'preparador', 'producao', 'Preparadora de obra'],
            ['pedro.almeida', 'Pedro Almeida', 'medidor_orcamentista', 'orcamentacao', 'Medidor-orçamentista'],
            ['teresa.monteiro', 'Teresa Monteiro', 'administrativo', 'administrativo', 'Responsável administrativa e financeira'],
            ['carla.mendes', 'Carla Mendes', 'tecnico_qualidade', 'qsa', 'Técnica de qualidade'],
            ['nuno.teixeira', 'Nuno Teixeira', 'coordenador_seguranca', 'qsa', 'Coordenador de segurança em obra'],
            ['ines.costa', 'Inês Costa', 'fiscal', null, 'Fiscal (dono de obra)'],
            ['manuel.rocha', 'Manuel Rocha', 'trabalhador', 'producao', 'Pedreiro de 1.ª'],
            ['rui.gaspar', 'Rui Gaspar', 'subempreiteiro', null, 'Eletro Vouga (subempreiteiro)'],
            ['paula.reis', 'Paula Reis', 'fornecedor', null, 'Betões do Vouga (fornecedor)'],
        ] as [$handle, $name, $role, $department, $title]) {
            $this->member($handle, $name, $role, $department, $title);
        }
    }

    private function user(string $handle, string $name): User
    {
        $email = "{$handle}@".self::DOMAIN;
        $user = User::query()->where('email', $email)->first() ?? new User(['email' => $email, 'password' => Str::random(48)]);
        $user->forceFill(['name' => $name, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

        return $this->users[$handle] = $user;
    }

    private function member(string $handle, string $name, string $role, ?string $department, string $title): Membership
    {
        $user = $this->user($handle, $name);
        $membership = Membership::query()->firstOrNew(['user_id' => $user->id]);
        $membership->forceFill([
            'role_id' => $this->roles[$role]->id,
            'status' => 'active',
            'job_title' => $title,
            'joined_at' => $this->today->subMonths(7),
            'department_id' => $department ? $this->departments[$department] : null,
        ])->save();
        if ($user->current_tenant_id === null) {
            $user->forceFill(['current_tenant_id' => $this->tenant->id])->save();
        }

        return $this->memberships[$handle] = $membership;
    }

    /** @param  list<string>  $emails  @return list<string> */
    private function addAdmins(array $emails): array
    {
        $added = [];
        foreach (array_unique(array_filter(array_map('trim', $emails))) as $email) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                continue;
            }
            $membership = Membership::query()->firstOrNew(['user_id' => $user->id]);
            $membership->forceFill([
                'role_id' => $this->roles[Role::ADMIN]->id, 'status' => 'active', 'job_title' => $membership->job_title ?? 'Administrador (demonstração)',
                'joined_at' => $membership->joined_at ?? now(),
            ])->save();
            if ($user->current_tenant_id === null) {
                $user->forceFill(['current_tenant_id' => $this->tenant->id])->save();
            }
            $added[] = $email;
        }

        return $added;
    }

    /** @param  array<string, string>  $team  handle => perfil na obra */
    private function projectTeam(Project $project, array $team): void
    {
        foreach ($team as $handle => $role) {
            ProjectMember::query()->firstOrCreate(
                ['project_id' => $project->id, 'membership_id' => $this->memberships[$handle]->id],
                ['role_id' => $this->roles[$role]->id, 'added_by_user_id' => $this->users['helena.marques']->id],
            );
        }
    }

    // ------------------------------------------------------------------ biblioteca, parceiros, pessoas

    private function library(): void
    {
        foreach ([
            ['labor', 'MO.01', 'Pedreiro de 1.ª', 'h', 1450], ['labor', 'MO.02', 'Servente', 'h', 1100], ['labor', 'MO.03', 'Carpinteiro de cofragens', 'h', 1500],
            ['labor', 'MO.04', 'Armador de ferro', 'h', 1500], ['labor', 'MO.05', 'Pintor', 'h', 1350], ['labor', 'MO.06', 'Ladrilhador', 'h', 1450],
            ['material', 'MT.01', 'Betão C25/30 XC2 S3 Dmáx 22', 'm3', 9200], ['material', 'MT.02', 'Aço A500 NR em varão', 'kg', 98],
            ['material', 'MT.03', 'Bloco térmico 30x20x25', 'un', 115], ['material', 'MT.04', 'Argamassa pré-doseada', 'kg', 9],
            ['material', 'MT.05', 'Cofragem (amortização de painéis)', 'm2', 650], ['material', 'MT.06', 'Painel sandwich 40 mm', 'm2', 2400],
            ['material', 'MT.07', 'Tela betuminosa 4 kg/m2', 'm2', 780], ['material', 'MT.08', 'Reboco projetado', 'kg', 21],
            ['material', 'MT.09', 'Ladrilho cerâmico 60x60', 'm2', 1650], ['material', 'MT.10', 'Cimento-cola C2TE', 'kg', 42],
            ['material', 'MT.11', 'Tinta aquosa interior', 'l', 520], ['material', 'MT.12', 'Perfis de aço S275', 'kg', 165],
            ['equipment', 'EQ.01', 'Retroescavadora com operador', 'h', 3800], ['equipment', 'EQ.02', 'Grua torre', 'h', 2200],
            ['equipment', 'EQ.03', 'Autobomba de betão', 'h', 11000], ['equipment', 'EQ.04', 'Vibrador de agulha', 'h', 350],
            ['subcontract', 'SE.01', 'Montagem de estrutura metálica (subempreitada)', 'kg', 85],
        ] as [$kind, $code, $name, $unit, $cents]) {
            $this->resources[$code] = PriceResource::query()->create(['kind' => $kind, 'code' => $code, 'name' => $name, 'unit' => $unit, 'unit_cost_cents' => $cents]);
        }

        foreach ([
            'ESC' => ['A.01', 'Escavação em abertura de caboucos em terreno compacto, incluindo remoção dos produtos a vazadouro', 'm3', ['EQ.01' => 0.12, 'MO.02' => 0.25]],
            'BSAP' => ['A.02', 'Betão C25/30 em sapatas e lintéis, incluindo vibração e cura', 'm3', ['MT.01' => 1.05, 'MO.01' => 0.5, 'MO.02' => 0.8, 'EQ.04' => 0.3]],
            'ACO' => ['A.03', 'Aço A500 NR em armaduras, incluindo corte, dobragem, montagem e espaçadores', 'kg', ['MT.02' => 1.08, 'MO.04' => 0.018]],
            'COF' => ['A.04', 'Cofragem de pilares, vigas e lajes, incluindo descofragem', 'm2', ['MT.05' => 1, 'MO.03' => 0.85, 'MO.02' => 0.45]],
            'BLAJ' => ['A.05', 'Betão C25/30 em pilares, vigas e lajes, incluindo bombagem e vibração', 'm3', ['MT.01' => 1.05, 'EQ.03' => 0.06, 'MO.01' => 0.45, 'MO.02' => 0.6, 'EQ.04' => 0.2]],
            'ALV' => ['A.06', 'Alvenaria exterior em bloco térmico de 30 cm, assente com argamassa', 'm2', ['MT.03' => 12.5, 'MT.04' => 28, 'MO.01' => 0.75, 'MO.02' => 0.5]],
            'IMP' => ['A.07', 'Impermeabilização de muros enterrados com tela betuminosa, incluindo primário', 'm2', ['MT.07' => 1.15, 'MO.01' => 0.3]],
            'COB' => ['A.08', 'Cobertura em painel sandwich de 40 mm, incluindo fixações e remates', 'm2', ['MT.06' => 1.08, 'MO.03' => 0.4, 'MO.02' => 0.3]],
            'REB' => ['A.09', 'Reboco projetado em paredes interiores, 15 mm, acabamento areado', 'm2', ['MT.08' => 18, 'MO.01' => 0.35, 'MO.02' => 0.2]],
            'PAV' => ['A.10', 'Pavimento em ladrilho cerâmico 60x60, colado, incluindo betumação', 'm2', ['MT.09' => 1.05, 'MT.10' => 5, 'MO.06' => 0.6, 'MO.02' => 0.2]],
            'PINT' => ['A.11', 'Pintura de paredes e tetos com primário e duas demãos de tinta aquosa', 'm2', ['MT.11' => 0.3, 'MO.05' => 0.22]],
            'METAL' => ['A.12', 'Estrutura metálica em perfis S275, fornecida, montada e pintada', 'kg', ['MT.12' => 1.05, 'SE.01' => 1]],
        ] as $key => [$code, $description, $unit, $components]) {
            $article = Article::query()->create(['code' => $code, 'description' => $description, 'unit' => $unit, 'unit_cost_cents' => 0]);
            $order = 0;
            foreach ($components as $resource => $quantity) {
                ArticleComponent::query()->create(['article_id' => $article->id, 'resource_id' => $this->resources[$resource]->id, 'quantity' => $quantity, 'sort_order' => $order++]);
            }
            $article->forceFill(['unit_cost_cents' => $article->load('components.resource')->unitCostCents()])->save();
            $this->articles[$key] = $article;
        }

        $by = $this->users['carla.mendes']->id;
        foreach ([
            'laje' => ['Betonagem de lajes e vigas', 'EST', true, 'Ponto de paragem: não se betona sem a inspeção aprovada.', [
                ['Cofragem limpa, estanque e travada', null, false, 0, false],
                ['Armaduras conforme o projeto (diâmetros, espaçamentos, amarrações)', 'Fotografar as zonas de maior densidade.', true, 2, false],
                ['Recobrimento das armaduras', 'Medir com régua junto aos espaçadores.', false, 0, ['mm', 25, 40]],
                ['Negativos, atravessamentos e caixas de instalações colocados', null, false, 0, false],
                ['Guia de remessa do betão confere com a classe especificada', null, true, 1, false],
            ]],
            'sapatas' => ['Armaduras de sapatas', 'EST', true, null, [
                ['Fundo dos caboucos limpo e à cota de projeto', null, true, 1, false],
                ['Betão de limpeza com 10 cm', null, false, 0, ['cm', 8, 15]],
                ['Armaduras e arranques de pilares conforme o projeto', null, true, 1, false],
            ]],
            'alvenaria' => ['Alvenarias de bloco', 'ARQ', false, null, [
                ['Primeira fiada nivelada e com corte de capilaridade', null, true, 1, false],
                ['Juntas desencontradas e preenchidas', null, false, 0, false],
                ['Ligação à estrutura com ferrolhos', null, false, 0, false],
            ]],
            'impermeabilizacao' => ['Impermeabilização de muros enterrados', 'EST', false, null, [
                ['Superfície regularizada e seca', null, false, 0, false],
                ['Sobreposição das telas', null, true, 1, ['cm', 10, 20]],
                ['Proteção mecânica e dreno colocados', null, true, 1, false],
            ]],
            'seguranca' => ['Segurança do estaleiro e andaimes', null, false, null, [
                ['Andaimes com guarda-corpos, rodapés e ancoragens', null, true, 1, false],
                ['Proteções coletivas em bordaduras de lajes', null, true, 1, false],
                ['Equipamentos de proteção individual em uso', null, false, 0, false],
                ['Sinalização e vedação do estaleiro', null, false, 0, false],
            ]],
        ] as $key => [$name, $specialty, $holdPoint, $description, $items]) {
            $template = ChecklistTemplate::query()->create([
                'name' => $name, 'description' => $description, 'specialty_id' => $specialty ? $this->specialties[$specialty] : null,
                'hold_point' => $holdPoint, 'approvals_required' => 1, 'version' => 1, 'created_by_user_id' => $by,
            ]);
            foreach ($items as $i => [$text, $guidance, $photo, $minPhotos, $measure]) {
                $template->items()->create([
                    'sort_order' => $i, 'text' => $text, 'guidance' => $guidance,
                    'requires_photo' => $photo, 'min_photos' => $minPhotos,
                    'requires_measure' => $measure !== false, 'measure_unit' => $measure ? $measure[0] : null,
                    'measure_min' => $measure ? $measure[1] : null, 'measure_max' => $measure ? $measure[2] : null,
                ]);
            }
            $this->templates[$key] = $template->load('items');
        }
    }

    private function partnersWorkersEquipment(): void
    {
        foreach ([
            'betoes' => ['supplier', 'Betões do Vouga, S.A.', 'Paula Reis', ['EST'], []],
            'ferros' => ['supplier', 'Ferros e Aços da Bairrada, Lda.', 'Sr. Armando Cruz', ['EST'], []],
            'materiais' => ['supplier', 'Materiais de Construção Ria, Lda.', 'D. Fernanda Luz', [], []],
            'ceramica' => ['supplier', 'Cerâmica Litoral, Lda.', 'Sr. Hélder Mota', ['ARQ'], []],
            'aluguer' => ['supplier', 'Aluguer de Equipamentos Centro, Lda.', 'Eng. Tiago Brás', [], []],
            'madeiras' => ['supplier', 'Madeiras e Derivados do Norte, Lda.', 'Sr. Óscar Faria', ['EST'], []],
            'eletro' => ['subcontractor', 'Eletro Vouga – Instalações Elétricas, Lda.', 'Rui Gaspar', ['ELE', 'ITED'], [
                ['Alvará de empreiteiro (classe 3)', 'ALV-0000-PUB', 400], ['Seguro de acidentes de trabalho', 'AT-2026-0001', 20], ['Certidão de não dívida (AT)', null, 75],
            ]],
            'canalizacoes' => ['subcontractor', 'Canalizações Aveiro, Lda.', 'Sr. Domingos Neves', ['AGU', 'PLU', 'GAS'], [
                ['Alvará de empreiteiro (classe 2)', 'ALV-0000-CAN', 300], ['Seguro de acidentes de trabalho', 'AT-2025-0099', -12],
            ]],
            'cofragens' => ['subcontractor', 'Cofragens Silva & Filhos, Lda.', 'Sr. Vítor Silva', ['EST'], [
                ['Seguro de acidentes de trabalho', 'AT-2026-0420', 160],
            ]],
            'caixilharia' => ['subcontractor', 'Alumínios da Ria, Lda.', 'Sr. Nélson Vaz', ['ARQ', 'TER'], [
                ['Alvará de empreiteiro (classe 2)', 'ALV-0000-ALU', 500], ['Seguro de acidentes de trabalho', 'AT-2026-0777', 220],
            ]],
        ] as $key => [$kind, $name, $contact, $specialties, $documents]) {
            $slug = Str::slug(Str::before($name, ','));
            $partner = Partner::query()->create([
                'kind' => $kind, 'name' => $name, 'contact_name' => $contact, 'email' => "geral@{$slug}.".self::DOMAIN,
                'phone' => '234 '.str_pad((string) (100000 + crc32($key) % 899999), 6, '0', STR_PAD_LEFT),
                'address' => 'Distrito de Aveiro', 'notes' => 'Entidade fictícia (demonstração).',
            ]);
            foreach ($specialties as $code) {
                $partner->specialties()->attach($this->specialties[$code], ['tenant_id' => $this->tenant->id]);
            }
            foreach ($documents as [$title, $reference, $days]) {
                $partner->documents()->create(['title' => $title, 'reference' => $reference, 'expires_on' => $this->today->addDays($days)->toDateString()]);
            }
            $this->partners[$key] = $partner;
        }

        foreach ([
            'manuel' => ['Manuel Rocha', 'Pedreiro de 1.ª', 1450, null, 'manuel.rocha'],
            'jose' => ['José Martins', 'Pedreiro de 1.ª', 1450, null, null],
            'carlos' => ['Carlos Nunes', 'Pedreiro de 2.ª', 1300, null, null],
            'paulo' => ['Paulo Ferreira', 'Servente', 1100, null, null],
            'bruno' => ['Bruno Silva', 'Servente', 1100, null, null],
            'fabio' => ['Fábio Gomes', 'Carpinteiro de cofragens', 1500, null, null],
            'hugo' => ['Hugo Correia', 'Armador de ferro', 1500, null, null],
            'joaquim' => ['Joaquim Pires', 'Encarregado', 1800, null, 'joaquim.pires'],
            'antonio' => ['António Ramos', 'Encarregado', 1800, null, 'antonio.ramos'],
            'daniel' => ['Daniel Sousa', 'Servente', 1100, null, null],
            'vitor' => ['Vítor Silva', 'Carpinteiro (subempreiteiro)', 1650, 'cofragens', null],
            'luis' => ['Luís Abreu', 'Pintor', 1350, null, null],
        ] as $key => [$name, $category, $cents, $partner, $user]) {
            $this->workers[$key] = Worker::query()->create([
                'name' => $name, 'category' => $category, 'hourly_cost_cents' => $cents, 'active' => true,
                'partner_id' => $partner ? $this->partners[$partner]->id : null, 'user_id' => $user ? $this->users[$user]->id : null,
            ]);
        }
        Worker::query()->create(['name' => 'Rogério Antunes', 'category' => 'Servente', 'hourly_cost_cents' => 1100, 'active' => false]);

        foreach ([
            'grua' => ['GR-01', 'Grua torre 40 m', 'Elevação', 'GT40-2019-117', 2200, 'in_use'],
            'retro' => ['RE-01', 'Retroescavadora', 'Movimento de terras', 'RX-3C-55821', 3800, 'available'],
            'mini' => ['MG-01', 'Mini-giratória 2,5 t', 'Movimento de terras', 'MG25-00931', 2600, 'maintenance'],
            'betoneira' => ['BT-01', 'Betoneira 350 l', 'Betão', null, 400, 'in_use'],
            'vibrador' => ['VB-01', 'Vibrador de agulha', 'Betão', null, 350, 'in_use'],
            'carrinha' => ['CR-01', 'Carrinha de caixa aberta', 'Transporte', 'AA-00-AA', 900, 'available'],
            'andaime' => ['AN-01', 'Andaime de fachada (200 m2)', 'Acesso', null, 150, 'available'],
        ] as $key => [$code, $name, $category, $serial, $cents, $status]) {
            $this->equipment[$key] = Equipment::query()->create([
                'code' => $code, 'name' => $name, 'category' => $category, 'serial_number' => $serial,
                'hourly_cost_cents' => $cents, 'status' => $status, 'notes' => null,
            ]);
        }
        foreach ([
            ['grua', -60, 'Inspeção periódica e lubrificação', 18000, 30],
            ['retro', -200, 'Revisão das 500 horas', 42000, -10],
            ['mini', -2, 'Substituição de lagartas', 136000, 178],
            ['carrinha', -90, 'Revisão anual e inspeção', 31000, 275],
            ['betoneira', -120, 'Substituição da correia', 4500, null],
        ] as [$key, $days, $description, $cents, $next]) {
            MaintenanceRecord::query()->create([
                'equipment_id' => $this->equipment[$key]->id, 'performed_on' => $this->today->addDays($days)->toDateString(),
                'description' => $description, 'cost_cents' => $cents, 'next_due_on' => $next === null ? null : $this->today->addDays($next)->toDateString(),
            ]);
        }
    }

    // ------------------------------------------------------------------ obra principal

    private function mainProject(): void
    {
        $start = $this->today->subWeeks(11)->startOfWeek();
        $this->at($start->subDays(20)->setTime(10, 0));
        $p = Project::query()->create([
            'code' => 'ATL-26-01', 'name' => 'Moradia T4 em Esgueira', 'client_id' => $this->clients['tavares'], 'owner_name' => 'Luís e Ana Tavares',
            'type' => 'new_build', 'address' => 'Rua das Glicínias, 14', 'postal_code' => '3810-000', 'city' => 'Aveiro',
            'starts_on' => $start->toDateString(), 'planned_end_on' => $start->addMonths(7)->toDateString(),
            'cost_center_id' => $this->costCenters['110'], 'status' => 'active',
            'description' => 'Moradia unifamiliar T4 com cave, R/C e piso 1. Estrutura em betão armado, alvenarias em bloco térmico e cobertura em painel sandwich.',
            'created_by_user_id' => $this->users['helena.marques']->id,
        ]);
        $this->projectTeam($p, [
            'rui.barbosa' => 'diretor_tecnico', 'ricardo.sousa' => 'diretor_obra', 'joaquim.pires' => 'encarregado', 'sofia.carvalho' => 'preparador',
            'carla.mendes' => 'tecnico_qualidade', 'nuno.teixeira' => 'coordenador_seguranca', 'ines.costa' => 'fiscal',
            'manuel.rocha' => 'trabalhador', 'rui.gaspar' => 'subempreiteiro', 'paula.reis' => 'fornecedor',
        ]);

        // Estrutura
        $building = ProjectLocation::query()->create(['project_id' => $p->id, 'kind' => 'building', 'name' => 'Moradia', 'sort_order' => 0]);
        $floors = [];
        foreach (['Cave', 'R/C', 'Piso 1', 'Cobertura'] as $i => $name) {
            $floors[$name] = ProjectLocation::query()->create(['project_id' => $p->id, 'kind' => 'floor', 'name' => $name, 'parent_id' => $building->id, 'sort_order' => $i]);
        }
        $elements = [];
        foreach ([
            ['Cave', 'footing', 'S1', 'Sapata S1'], ['Cave', 'footing', 'S2', 'Sapata S2'], ['Cave', 'footing', 'S3', 'Sapata S3'], ['Cave', 'footing', 'S4', 'Sapata S4'],
            ['Cave', 'retaining_wall', 'M1', 'Muro de cave (norte)'], ['Cave', 'retaining_wall', 'M2', 'Muro de cave (nascente)'],
            ['R/C', 'column', 'P1', 'Pilar P1'], ['R/C', 'column', 'P4', 'Pilar P4'], ['R/C', 'slab', 'L0', 'Laje do R/C'], ['R/C', 'beam', 'V0.3', 'Viga V0.3'],
            ['Piso 1', 'slab', 'L1', 'Laje do piso 1'], ['Piso 1', 'beam', 'V1.3', 'Viga V1.3'], ['Piso 1', 'stair', 'E1', 'Escada interior'],
            ['Cobertura', 'roof', 'C1', 'Cobertura'],
        ] as [$floor, $type, $code, $name]) {
            $elements[$code] = ConstructionElement::query()->create([
                'project_id' => $p->id, 'location_id' => $floors[$floor]->id, 'element_type' => $type, 'code' => $code, 'name' => $name,
                'specialty_id' => $this->specialties['EST'], 'created_by_user_id' => $this->users['sofia.carvalho']->id,
            ]);
        }

        // Planeamento
        $acts = $this->plan($p, $start, [
            ['1', 'Preparação', null, 0, null],
            ['1.1', 'Montagem de estaleiro', '1', 5, null],
            ['1.2', 'Implantação e piquetagem', '1', 2, [['1.1', 'FS', 0]]],
            ['2', 'Fundações', null, 0, null],
            ['2.1', 'Escavação de caboucos', '2', 6, [['1.2', 'FS', 0]]],
            ['2.2', 'Armaduras e betonagem de sapatas', '2', 8, [['2.1', 'FS', 0]]],
            ['2.3', 'Muros de cave e impermeabilização', '2', 10, [['2.2', 'FS', 0]]],
            ['3', 'Estrutura', null, 0, null],
            ['3.1', 'Pilares e laje do R/C', '3', 15, [['2.3', 'FS', 0]]],
            ['3.2', 'Pilares e laje do piso 1', '3', 15, [['3.1', 'FS', 0]]],
            ['3.3', 'Laje de cobertura', '3', 12, [['3.2', 'FS', 0]]],
            ['4', 'Alvenarias e cobertura', null, 0, null],
            ['4.1', 'Alvenarias exteriores', '4', 20, [['3.2', 'SS', 10]]],
            ['4.2', 'Cobertura em painel sandwich', '4', 10, [['3.3', 'FS', 0]]],
            ['5', 'Instalações (subempreitadas)', null, 0, null],
            ['5.1', 'Instalação elétrica e ITED', '5', 25, [['4.1', 'SS', 5]]],
            ['5.2', 'Redes de águas e esgotos', '5', 20, [['4.1', 'SS', 5]]],
            ['6', 'Acabamentos', null, 0, null],
            ['6.1', 'Rebocos interiores', '6', 20, [['4.1', 'FS', 0], ['5.1', 'SS', 10]]],
            ['6.2', 'Pavimentos e revestimentos', '6', 25, [['6.1', 'FS', 0]]],
            ['6.3', 'Pinturas', '6', 15, [['6.2', 'SS', 10]]],
            ['6.4', 'Limpeza final', '6', 3, [['6.3', 'FS', 0], ['4.2', 'FS', 0]]],
            ['7', 'Receção provisória', null, 0, [['6.4', 'FS', 0]]],
        ], ['1.1' => 100, '1.2' => 100, '2.1' => 100, '2.2' => 100, '2.3' => 100, '3.1' => 100, '3.2' => 55]);
        // A receção é um marco (duração 0) e não um resumo.
        $acts['3.2']->forceFill(['location_id' => $floors['Piso 1']->id, 'element_id' => $elements['L1']->id])->save();

        // Biblioteca → orçamento
        $budget = $this->budget($p, 'ORC-01', 'Orçamento de adjudicação', 8, 12, 'normal', 23, [
            ['1', 'Estaleiro', null, [
                ['1.1', null, 'Montagem, manutenção e desmontagem de estaleiro', 'vg', 1, 'other', 480000],
                ['1.2', null, 'Sinalização e proteções coletivas', 'vg', 1, 'material', 210000],
            ]],
            ['2', 'Movimento de terras', null, [
                ['2.1', 'ESC', null, null, 180, null, null],
                ['2.2', null, 'Aterro e compactação em camadas de 20 cm', 'm3', 95, 'equipment', 980],
            ]],
            ['3', 'Fundações e estrutura', null, []],
            ['3.1', 'Fundações', '3', [
                ['3.1.1', 'BSAP', null, null, 62, null, null],
                ['3.1.2', 'ACO', 'Aço A500 NR em armaduras de fundações', null, 5400, null, null],
                ['3.1.3', 'IMP', null, null, 160, null, null],
            ]],
            ['3.2', 'Estrutura elevada', '3', [
                ['3.2.1', 'COF', null, null, 640, null, null],
                ['3.2.2', 'BLAJ', null, null, 118, null, null],
                ['3.2.3', 'ACO', 'Aço A500 NR em armaduras de pilares, vigas e lajes', null, 13800, null, null],
            ]],
            ['4', 'Alvenarias', null, [['4.1', 'ALV', null, null, 410, null, null]]],
            ['5', 'Cobertura', null, [['5.1', 'COB', null, null, 185, null, null]]],
            ['6', 'Instalações elétricas e ITED', null, [['6.1', null, 'Instalação elétrica e de telecomunicações conforme projeto', 'vg', 1, 'subcontract', 1850000, 'ELE']]],
            ['7', 'Redes de águas e esgotos', null, [['7.1', null, 'Redes prediais de águas, esgotos e pluviais conforme projeto', 'vg', 1, 'subcontract', 1240000, 'AGU']]],
            ['8', 'Revestimentos e acabamentos', null, [
                ['8.1', 'REB', null, null, 980, null, null],
                ['8.2', 'PAV', null, null, 260, null, null],
                ['8.3', 'PINT', null, null, 1450, null, null],
            ]],
        ], approvedAt: $start->subDays(10)->setTime(16, 0), approvedBy: 'helena.marques');
        $items = $budget->items->keyBy('code');
        $totals = BudgetCalculator::totals($budget);
        $p->forceFill(['contract_value_cents' => $totals['sale_cents']])->save();
        $this->revision($budget, 'Trabalhos a mais pedidos pelo dono de obra (muro de vedação).');

        // Medições e autos ao dono de obra
        $d = fn (int $n) => $this->workday($start, $n);
        foreach ([
            [$d(3), '1.1', 'Estaleiro montado (50%)', null, null, null, null, 0.5],
            [$d(9), '2.1', 'Caboucos S1 a S4', 4, 3.2, 3.2, 1.6, null],
            [$d(11), '2.1', 'Lintéis de fundação', 1, 54, 0.8, 0.9, null],
            [$d(16), '3.1.1', 'Betonagem das sapatas S1 a S4', null, null, null, null, 61.2],
            [$d(15), '3.1.2', 'Armaduras das sapatas e lintéis', null, null, null, null, 5350],
            [$d(28), '3.1.3', 'Muros M1 e M2', 1, 58, 2.7, null, null],
            [$d(30), '2.1', 'Remate da escavação junto ao muro M2', null, null, null, null, 9.4],
            [$d(36), '3.2.1', 'Cofragem de pilares e laje do R/C', null, null, null, null, 380],
            [$d(41), '3.2.2', 'Betonagem da laje do R/C', 1, 14.2, 11.6, 0.25, null],
            [$d(41), '3.2.2', 'Pilares e vigas do R/C', null, null, null, null, 17.4],
            [$d(40), '3.2.3', 'Armaduras de pilares e laje do R/C', null, null, null, null, 6900],
            [$d(42), '1.1', 'Manutenção de estaleiro', null, null, null, null, 0.2],
            [$d(50), '3.2.1', 'Cofragem de pilares e laje do piso 1', null, null, null, null, 210],
            [$d(52), '3.2.3', 'Armaduras dos pilares do piso 1', null, null, null, null, 3100],
            [$d(53), '2.2', 'Aterro junto aos muros de cave', 1, 40, 1, 1, null],
        ] as [$date, $code, $description, $count, $length, $width, $height, $qty]) {
            $quantity = $qty ?? round(array_product(array_filter([$count, $length, $width, $height], fn ($v) => $v !== null)), 3);
            $this->at($date->setTime(17, 30));
            Measurement::query()->create([
                'project_id' => $p->id, 'budget_item_id' => $items[$code]->id, 'measured_on' => $date->toDateString(), 'description' => $description,
                'count' => $count, 'length' => $length, 'width' => $width, 'height' => $height, 'quantity' => $quantity,
                'created_by_user_id' => $this->users['pedro.almeida']->id,
            ]);
        }
        $auto1 = $this->ownerCertificate($p, $budget, $start, $d(22), 'approved');
        $this->ownerCertificate($p, $budget, $d(23), $d(44), 'submitted');
        $this->invoice($auto1, $d(28), 'FT A/2026/0412', $d(29));

        // Subempreitadas
        $eletro = $this->contract($p, 'SUB-01', 'eletro', 'Instalações elétricas e ITED', 5, $d(20), $d(140), 'active', [
            [$items['6.1']->id, 'Tubagens e caixas embebidas em lajes e paredes', 'vg', 1, 620000],
            [$items['6.1']->id, 'Quadros elétricos e cablagem', 'vg', 1, 790000],
            [$items['6.1']->id, 'Aparelhagem, iluminação e ITED', 'vg', 1, 340000],
        ]);
        $this->contractCertificate($p, $eletro, $d(25), $d(45), [0.35, 0, 0], 'approved');
        $this->contractCertificate($p, $eletro, $d(46), $d(54), [0.25, 0, 0], 'draft');
        $this->contract($p, 'SUB-02', 'canalizacoes', 'Redes prediais de águas e esgotos', 5, $d(56), $d(120), 'active', [
            [$items['7.1']->id, 'Rede de esgotos enterrada em PVC', 'm', 85, 4200],
            [$items['7.1']->id, 'Rede de águas em multicamada', 'vg', 1, 480000],
            [$items['7.1']->id, 'Assentamento de loiças sanitárias', 'un', 9, 9500],
        ]);
        $this->contract($p, 'SUB-03', 'cofragens', 'Cofragem da laje de cobertura', 0, $d(62), $d(75), 'draft', [
            [$items['3.2.1']->id, 'Cofragem e descofragem da laje de cobertura', 'm2', 140, 1850],
        ]);

        // Compras
        $this->purchase($p, 'ferros', 'material', $d(12), 'invoiced', [['MT.02', '3.1.2', 'Aço A500 NR (fundações)', 'kg', 5500, 98]], ['FA 3310', $d(19)]);
        $footingConcrete = $this->purchase($p, 'betoes', 'material', $d(14), 'invoiced', [['MT.01', '3.1.1', 'Betão C25/30 XC2 S3 para sapatas', 'm3', 62, 9200]], ['FT 2026/1184', $d(20)]);
        $this->purchase($p, 'aluguer', 'equipment', $d(18), 'invoiced', [[null, null, 'Aluguer de andaimes de fachada (3 meses)', 'vg', 1, 240000]], ['FT A/552', $d(24)]);
        $this->purchase($p, 'betoes', 'material', $d(39), 'invoiced', [['MT.01', '3.2.2', 'Betão C25/30 para a laje do R/C', 'm3', 60, 9200]], ['FT 2026/1530', $d(45)]);
        $this->purchase($p, 'ferros', 'material', $d(46), 'received', [['MT.02', '3.2.3', 'Aço A500 NR (estrutura elevada)', 'kg', 7200, 98]]);
        $this->purchase($p, 'ferros', 'material', $d(46), 'cancelled', [['MT.02', '3.2.3', 'Aço A500 NR (encomenda em duplicado)', 'kg', 7200, 98]]);
        $slabConcrete = $this->purchase($p, 'betoes', 'material', $d(52), 'approved', [['MT.01', '3.2.2', 'Betão C25/30 para a laje do piso 1', 'm3', 58, 9200], [null, '3.2.2', 'Autobomba (meio dia)', 'vg', 1, 55000]]);
        $this->purchase($p, 'materiais', 'material', $d(53), 'approved', [['MT.03', '4.1', 'Bloco térmico 30x20x25', 'un', 5200, 115], ['MT.04', '4.1', 'Argamassa pré-doseada', 'kg', 11000, 9]]);
        $this->purchase($p, 'ceramica', 'material', $d(54), 'draft', [['MT.09', '8.2', 'Ladrilho cerâmico 60x60 (amostra aprovada)', 'm2', 275, 1650], ['MT.10', '8.2', 'Cimento-cola C2TE', 'kg', 1300, 42]]);

        // Faturas e guias de fornecedores: uma lançada na compra, uma fatura e uma guia por rever (com a compra
        // proposta), um talão lançado como custo e um duplicado rejeitado.
        $this->supplierDocument($p, 'betoes', 'invoice', 'FT 2026/1184', $d(20), [['Betão C25/30 XC2 S3 para sapatas', 'm3', 62, 9200]], 'posted', ['order' => $footingConcrete, 'confidence' => 0.9812]);
        $this->supplierDocument($p, 'betoes', 'invoice', 'FT 2026/1184', $d(21), [['Betão C25/30 XC2 S3 para sapatas', 'm3', 62, 9200]], 'rejected', ['confidence' => 0.9655, 'reason' => 'Duplicado (a mesma fatura chegou também por email).']);
        $this->supplierDocument($p, null, 'receipt', 'FS 12/3391', $d(33), [['Discos de corte para rebarbadora', 'un', 10, 290], ['Fita sinalizadora', 'rolo', 4, 450], ['Silicone neutro', 'un', 3, 650]], 'posted', [
            'supplier_name' => 'Drogaria Central de Aveiro', 'category' => 'other', 'confidence' => 0.8120,
        ]);
        $this->supplierDocument($p, 'ferros', 'invoice', 'FA 3488', $this->lastWorkdays(2)[0], [['Aço A500 NR (estrutura elevada)', 'kg', 7200, 98]], 'to_review', ['confidence' => 0.9734]);
        $this->supplierDocument($p, 'betoes', 'delivery_note', 'GR 2026/2210', $this->lastWorkdays(1)[0], [['Betão C25/30 para a laje do piso 1', 'm3', 58, null]], 'to_review', [
            'confidence' => 0.9105, 'order_reference' => "N/ encomenda n.º {$slabConcrete->number}",
        ]);

        foreach ([
            [$d(1), 'other', 'actual', 'Taxas municipais da licença de construção', 385000],
            [$d(1), 'other', 'actual', 'Seguro de obra (todos os riscos)', 192000],
            [$d(17), 'other', 'actual', 'Ensaios de compressão do betão (laboratório)', 64000],
            [$d(38), 'other', 'committed', 'Projeto de alterações de arquitetura', 150000],
        ] as [$date, $category, $kind, $description, $cents]) {
            $this->at($date->setTime(12, 0));
            CostEntry::query()->create([
                'project_id' => $p->id, 'cost_center_id' => $this->costCenters['110'], 'category' => $category, 'kind' => $kind,
                'description' => $description, 'amount_cents' => $cents, 'incurred_on' => $date->toDateString(), 'created_by_user_id' => $this->users['teresa.monteiro']->id,
            ]);
        }

        // Horas e equipamentos (últimas três semanas)
        $this->timesheets($p, ['manuel', 'jose', 'carlos', 'paulo', 'bruno', 'fabio', 'hugo', 'joaquim', 'vitor'], 15, $acts['3.2']->id, 'joaquim.pires', 'ricardo.sousa');
        $this->equipmentOnSite($p, ['grua' => 6, 'vibrador' => 2.5], 15);
        $this->equipmentUsage($p, 'retro', $d(6), 6, 7.5, 'Escavação de caboucos');
        $this->equipmentUsage($p, 'retro', $d(53), 1, 4, 'Aterro junto aos muros');
        $this->equipment['retro']->forceFill(['project_id' => null, 'status' => 'available'])->save();

        $p->forceFill(['cost_ceiling_cents' => (int) round(BudgetCalculator::totals($budget)['cost_cents'] * 1.03 / 100) * 100])->save();

        // Diário, documentos, inspeções e ocorrências
        $this->diary($p, 12, [
            'Cofragem da laje do piso 1 (zona A) e montagem de escoramentos.',
            'Montagem de armaduras das vigas do piso 1. Receção de aço (EC 5).',
            'Betonagem dos pilares do piso 1. Descofragem dos pilares do R/C.',
            'Cofragem da laje do piso 1 (zona B). Limpeza do estaleiro.',
            'Armaduras da laje do piso 1. Colocação de negativos das instalações pela Eletro Vouga.',
            'Paragem parcial por chuva forte a partir das 14h. Proteção das armaduras.',
            'Continuação das armaduras da laje do piso 1 e espaçadores.',
            'Montagem de guarda-corpos na bordadura da laje do R/C.',
            'Escada interior: cofragem e armaduras.',
            'Preparação para a betonagem da laje do piso 1. Pedido de inspeção (ponto de paragem).',
            'Correção do recobrimento na viga V1.3 após inspeção.',
            'Arrumação e limpeza da laje. Aguarda libertação do ponto de paragem.',
        ], $floors, $elements);
        $this->projectDocuments($p);
        $this->mainInspectionsAndIssues($p, $acts, $floors, $elements, $start);
    }

    // ------------------------------------------------------------------ outras obras

    private function rehabProject(): void
    {
        $start = $this->today->subWeeks(7)->startOfWeek();
        $this->at($start->subDays(15)->setTime(11, 0));
        $p = Project::query()->create([
            'code' => 'ATL-26-02', 'name' => 'Reabilitação de edifício na Rua Direita', 'client_id' => $this->clients['ria'], 'owner_name' => 'Ria Investimentos Imobiliários, S.A.',
            'type' => 'renovation', 'address' => 'Rua Direita, 88', 'postal_code' => '3800-000', 'city' => 'Aveiro',
            'starts_on' => $start->toDateString(), 'planned_end_on' => $start->addMonths(7)->toDateString(),
            'cost_center_id' => $this->costCenters['120'], 'status' => 'active',
            'description' => 'Reabilitação de edifício de três pisos: reforço de pavimentos de madeira, cobertura nova, caixilharias e acabamentos.',
            'created_by_user_id' => $this->users['helena.marques']->id,
        ]);
        $this->projectTeam($p, ['rui.barbosa' => 'diretor_tecnico', 'marta.lopes' => 'diretor_obra', 'antonio.ramos' => 'encarregado', 'ines.costa' => 'fiscal', 'nuno.teixeira' => 'coordenador_seguranca', 'carla.mendes' => 'tecnico_qualidade']);
        $building = ProjectLocation::query()->create(['project_id' => $p->id, 'kind' => 'building', 'name' => 'Edifício', 'sort_order' => 0]);
        $floors = [];
        foreach (['R/C', 'Piso 1', 'Piso 2', 'Cobertura'] as $i => $name) {
            $floors[$name] = ProjectLocation::query()->create(['project_id' => $p->id, 'kind' => 'floor', 'name' => $name, 'parent_id' => $building->id, 'sort_order' => $i]);
        }

        $acts = $this->plan($p, $start, [
            ['1', 'Demolições e remoção de amianto', null, 10, null],
            ['2', 'Reforço dos pavimentos de madeira', null, 20, [['1', 'FS', 0]]],
            ['3', 'Cobertura nova', null, 15, [['1', 'FS', 5]]],
            ['4', 'Caixilharias', null, 15, [['3', 'FS', 0]]],
            ['5', 'Rebocos e pinturas', null, 25, [['2', 'FS', 0], ['4', 'SS', 5]]],
            ['6', 'Receção provisória', null, 0, [['5', 'FS', 0]]],
        ], ['1' => 100, '2' => 60, '3' => 40]);

        $budget = $this->budget($p, 'ORC-01', 'Orçamento de adjudicação', 7, 10, 'normal', 6, [
            ['1', 'Demolições e remoções', null, [['1.1', null, 'Demolição de revestimentos, tabiques e cobertura existente', 'vg', 1, 'labor', 650000]]],
            ['2', 'Reforço estrutural', null, [
                ['2.1', null, 'Substituição de vigas de madeira por madeira lamelada colada', 'm3', 9, 'material', 145000],
                ['2.2', null, 'Reforço de ligações com chapas e ferragens metálicas', 'kg', 1200, 'material', 420],
            ]],
            ['3', 'Cobertura', null, [['3.1', null, 'Cobertura em telha cerâmica com subtelha e isolamento', 'm2', 210, 'material', 3800]]],
            ['4', 'Caixilharias', null, [['4.1', null, 'Caixilharia em alumínio com corte térmico e vidro duplo', 'vg', 1, 'subcontract', 2100000, 'TER']]],
            ['5', 'Acabamentos', null, [['5.1', 'REB', null, null, 620, null, null], ['5.2', 'PINT', null, null, 900, null, null]]],
        ], approvedAt: $start->subDays(5)->setTime(15, 0), approvedBy: 'helena.marques', notes: 'IVA à taxa reduzida: confirmar o enquadramento com a contabilidade.');
        $items = $budget->items->keyBy('code');
        $p->forceFill(['contract_value_cents' => BudgetCalculator::totals($budget)['sale_cents']])->save();
        $d = fn (int $n) => $this->workday($start, $n);

        $this->contract($p, 'SUB-01', 'caixilharia', 'Caixilharias em alumínio', 5, $d(25), $d(60), 'active', [
            [$items['4.1']->id, 'Caixilharia em alumínio com corte térmico e vidro duplo (24 vãos)', 'vg', 1, 2480000],
        ]);
        $this->purchase($p, 'madeiras', 'material', $d(8), 'invoiced', [[null, '2.1', 'Madeira lamelada colada GL24h', 'm3', 10.5, 152000]], ['FT 2026/887', $d(14)]);
        $this->purchase($p, 'ferros', 'material', $d(12), 'invoiced', [[null, '2.2', 'Chapas e ferragens galvanizadas', 'kg', 1300, 460]], ['FA 3402', $d(18)]);
        $this->purchase($p, 'materiais', 'material', $d(26), 'approved', [[null, '3.1', 'Telha cerâmica, subtelha e isolamento', 'm2', 230, 4100]]);
        foreach ([
            [$d(3), 'subcontract', 'actual', 'Remoção de fibrocimento com amianto (empresa certificada)', 780000],
            [$d(9), 'other', 'actual', 'Transporte de resíduos a vazadouro', 190000],
            [$d(1), 'other', 'actual', 'Licença de ocupação da via pública', 86000],
        ] as [$date, $category, $kind, $description, $cents]) {
            $this->at($date->setTime(12, 0));
            CostEntry::query()->create([
                'project_id' => $p->id, 'cost_center_id' => $this->costCenters['120'], 'category' => $category, 'kind' => $kind,
                'description' => $description, 'amount_cents' => $cents, 'incurred_on' => $date->toDateString(), 'created_by_user_id' => $this->users['teresa.monteiro']->id,
            ]);
        }
        foreach ([[$d(6), '1.1', 'Demolições do R/C e piso 1', 0.6], [$d(12), '1.1', 'Demolições do piso 2 e cobertura', 0.4], [$d(20), '2.1', 'Vigas do piso 1', 4.5], [$d(24), '2.2', 'Ferragens do piso 1', 610]] as [$date, $code, $description, $qty]) {
            $this->at($date->setTime(17, 0));
            Measurement::query()->create(['project_id' => $p->id, 'budget_item_id' => $items[$code]->id, 'measured_on' => $date->toDateString(), 'description' => $description, 'quantity' => $qty, 'created_by_user_id' => $this->users['pedro.almeida']->id]);
        }
        $auto = $this->ownerCertificate($p, $budget, $start, $d(15), 'approved');
        $this->invoice($auto, $d(21), null, null);

        $this->timesheets($p, ['antonio', 'daniel', 'luis'], 10, $acts['2']->id, 'antonio.ramos', 'marta.lopes');
        $this->equipment['betoneira']->forceFill(['project_id' => $p->id])->save();
        $this->equipmentOnSite($p, ['betoneira' => 3], 10);

        $this->diary($p, 5, [
            'Reforço das vigas do piso 1: escoramento e substituição de duas vigas.',
            'Montagem de ferragens de ligação nas vigas do piso 1.',
            'Desmontagem da cobertura existente na ala sul. Proteção provisória com lonas.',
            'Visita do dono de obra e da fiscalização.',
            'Continuação do reforço do piso 1. Receção da madeira lamelada.',
        ], $floors, []);

        $this->at($d(18)->setTime(10, 0));
        $this->issue($p, [
            'kind' => 'nonconformity', 'severity' => 'major', 'title' => 'Madeira lamelada entregue com humidade acima do especificado',
            'description' => 'Medição com higrómetro: 19% (especificado ≤ 15%). Lote separado e identificado.', 'status' => 'in_progress',
            'by' => 'antonio.ramos', 'assignee' => 'marta.lopes', 'due' => $this->today->addDays(4), 'location' => $floors['Piso 1']->id,
            'specialty' => 'EST', 'steps' => [['start', 'marta.lopes', null, 1]], 'comments' => [['marta.lopes', 'Pedida a substituição do lote ao fornecedor.', 1]], 'photos' => 1,
        ]);
        // Sem teto escrito: conta o custo do orçamento, e a previsão já o ultrapassa ("acima do teto").
    }

    private function plannedProject(): void
    {
        $start = $this->today->addWeeks(3)->startOfWeek();
        $this->at($this->today->subDays(12)->setTime(9, 0));
        $p = Project::query()->create([
            'code' => 'ATL-26-03', 'name' => 'Pavilhão industrial em Oiã', 'client_id' => $this->clients['vale'], 'owner_name' => 'Metalúrgica do Vale, Lda.',
            'type' => 'new_build', 'address' => 'Zona Industrial de Oiã, lote 7', 'postal_code' => '3770-000', 'city' => 'Oliveira do Bairro',
            'starts_on' => $start->toDateString(), 'planned_end_on' => $start->addMonths(6)->toDateString(),
            'cost_center_id' => $this->costCenters['130'], 'status' => 'planning',
            'description' => 'Pavilhão em estrutura metálica com 1 200 m2, fundações diretas e cobertura em painel sandwich.',
            'created_by_user_id' => $this->users['helena.marques']->id,
        ]);
        $this->projectTeam($p, ['marta.lopes' => 'diretor_obra', 'sofia.carvalho' => 'preparador', 'rui.barbosa' => 'diretor_tecnico']);
        $this->at($this->today->subDays(1)->setTime(15, 0));
        $advance = new InvoiceDraft(InvoiceDrafts::clientFields(Client::query()->find($this->clients['vale']), $p) + [
            'project_id' => $p->id, 'reference' => 'Adiantamento de 10% na adjudicação', 'due_date' => $this->today->addDays(15)->toDateString(),
            'notes' => 'A deduzir nos autos de medição.',
        ]);
        $advance->created_by_user_id = $this->users['teresa.monteiro']->id;
        $advance->save();
        $advance->lines()->create(['description' => 'Adiantamento por conta da empreitada', 'unit' => 'vg', 'quantity' => 1, 'unit_price_cents' => 6500000, 'discount_pct' => 0, 'vat_rate' => 23, 'sort_order' => 0]);
        $this->budget($p, 'ORC-01', 'Proposta (em preparação)', 9, 14, 'normal', 23, [
            ['1', 'Movimento de terras', null, [['1.1', 'ESC', null, null, 420, null, null]]],
            ['2', 'Fundações', null, [['2.1', 'BSAP', null, null, 96, null, null], ['2.2', 'ACO', 'Aço A500 NR em fundações', null, 7600, null, null]]],
            ['3', 'Estrutura metálica', null, [['3.1', 'METAL', null, null, 38500, null, null]]],
            ['4', 'Cobertura e fachadas', null, [['4.1', 'COB', 'Cobertura e fachadas em painel sandwich de 40 mm', null, 2350, null, null]]],
            ['5', 'Pavimento industrial', null, [['5.1', null, 'Pavimento em betão afagado com fibras, 15 cm', 'm2', 1200, 'subcontract', 2450]]],
        ]);
        $this->plan($p, $start, [
            ['1', 'Montagem de estaleiro', null, 5, null],
            ['2', 'Escavação e fundações', null, 18, [['1', 'FS', 0]]],
            ['3', 'Estrutura metálica', null, 20, [['2', 'FS', 3]]],
            ['4', 'Cobertura e fachadas', null, 18, [['3', 'SS', 10]]],
            ['5', 'Pavimento industrial', null, 10, [['4', 'FS', 0]]],
            ['6', 'Instalações e acabamentos', null, 20, [['4', 'FS', 0]]],
            ['7', 'Receção provisória', null, 0, [['5', 'FS', 0], ['6', 'FS', 0]]],
        ], [], pert: ['3' => [16, 20, 28], '4' => [15, 18, 25]]);
    }

    private function finishedProject(): void
    {
        $start = $this->today->subWeeks(24)->startOfWeek();
        $this->at($start->subDays(10)->setTime(10, 0));
        $p = Project::query()->create([
            'code' => 'ATL-26-00', 'name' => 'Remodelação de loja na Praça da República', 'client_id' => $this->clients['otica'], 'owner_name' => 'Ótica Central, Lda.',
            'type' => 'renovation', 'address' => 'Praça da República, 3', 'postal_code' => '3800-000', 'city' => 'Aveiro',
            'starts_on' => $start->toDateString(), 'planned_end_on' => $start->addWeeks(9)->toDateString(),
            'cost_center_id' => $this->costCenters['130'], 'status' => 'active',
            'description' => 'Remodelação interior de loja de rua: divisórias, pavimento, iluminação e pintura.',
            'created_by_user_id' => $this->users['helena.marques']->id,
        ]);
        $this->projectTeam($p, ['ricardo.sousa' => 'diretor_obra', 'antonio.ramos' => 'encarregado']);
        $budget = $this->budget($p, 'ORC-01', 'Orçamento de adjudicação', 8, 15, 'normal', 23, [
            ['1', 'Demolições', null, [['1.1', null, 'Demolição de divisórias e pavimento existente', 'vg', 1, 'labor', 280000]]],
            ['2', 'Divisórias e tetos', null, [['2.1', null, 'Divisórias em gesso cartonado com isolamento', 'm2', 85, 'material', 3200], ['2.2', null, 'Teto falso em gesso cartonado', 'm2', 120, 'material', 2600]]],
            ['3', 'Pavimentos', null, [['3.1', 'PAV', null, null, 120, null, null]]],
            ['4', 'Instalação elétrica e iluminação', null, [['4.1', null, 'Instalação elétrica e iluminação LED da loja', 'vg', 1, 'subcontract', 980000, 'ELE']]],
            ['5', 'Pinturas', null, [['5.1', 'PINT', null, null, 420, null, null]]],
        ], approvedAt: $start->subDays(7)->setTime(15, 0), approvedBy: 'helena.marques');
        $items = $budget->items->keyBy('code');
        $p->forceFill(['contract_value_cents' => BudgetCalculator::totals($budget)['sale_cents']])->save();
        $d = fn (int $n) => $this->workday($start, $n);

        $contract = $this->contract($p, 'SUB-01', 'eletro', 'Instalação elétrica e iluminação da loja', 5, $d(5), $d(35), 'active', [
            [$items['4.1']->id, 'Instalação elétrica e iluminação LED', 'vg', 1, 905000],
        ]);
        $this->contractCertificate($p, $contract, $d(5), $d(20), [0.6], 'approved');
        $this->contractCertificate($p, $contract, $d(21), $d(40), [0.4], 'approved');
        $contract->forceFill(['status' => 'closed'])->save();
        $this->purchase($p, 'materiais', 'material', $d(4), 'invoiced', [[null, '2.1', 'Placas de gesso cartonado, perfis e isolamento', 'vg', 1, 520000]], ['FT 2026/301', $d(9)]);
        $this->purchase($p, 'ceramica', 'material', $d(14), 'invoiced', [['MT.09', '3.1', 'Ladrilho cerâmico 60x60', 'm2', 128, 1650], ['MT.10', '3.1', 'Cimento-cola C2TE', 'kg', 600, 42]], ['FT 2026/415', $d(19)]);
        foreach ([[$d(8), '1.1', 1], [$d(18), '2.1', 85], [$d(20), '2.2', 120], [$d(26), '3.1', 120], [$d(38), '4.1', 1], [$d(40), '5.1', 420]] as [$date, $code, $qty]) {
            $this->at($date->setTime(17, 0));
            Measurement::query()->create(['project_id' => $p->id, 'budget_item_id' => $items[$code]->id, 'measured_on' => $date->toDateString(), 'description' => 'Medição final', 'quantity' => $qty, 'created_by_user_id' => $this->users['pedro.almeida']->id]);
        }
        $first = $this->ownerCertificate($p, $budget, $start, $d(21), 'approved');
        $last = $this->ownerCertificate($p, $budget, $d(22), $d(42), 'approved');
        $this->invoice($first, $d(26), 'FT A/2026/0198', $d(27));
        $this->invoice($last, $d(46), 'FT A/2026/0287', $d(47));
        $this->timesheets($p, ['daniel', 'luis', 'carlos'], 20, null, 'antonio.ramos', 'ricardo.sousa', from: $start->addWeeks(1), approveAll: true);
        $this->plan($p, $start, [
            ['1', 'Demolições', null, 5, null], ['2', 'Divisórias e tetos', null, 12, [['1', 'FS', 0]]], ['3', 'Instalação elétrica', null, 20, [['1', 'FS', 0]]],
            ['4', 'Pavimentos', null, 8, [['2', 'FS', 0]]], ['5', 'Pinturas', null, 8, [['4', 'FS', 0], ['3', 'FS', 0]]], ['6', 'Entrega', null, 0, [['5', 'FS', 0]]],
        ], ['1' => 100, '2' => 100, '3' => 100, '4' => 100, '5' => 100, '6' => 100]);
        $p->forceFill(['status' => 'completed'])->save();
    }

    // ------------------------------------------------------------------ inspeções e ocorrências da obra principal

    /**
     * @param  array<string, Activity>  $acts
     * @param  array<string, ProjectLocation>  $floors
     * @param  array<string, ConstructionElement>  $elements
     */
    private function mainInspectionsAndIssues(Project $p, array $acts, array $floors, array $elements, CarbonImmutable $start): void
    {
        $d = fn (int $n) => $this->workday($start, $n);

        $sapatas = $this->inspection($p, 'sapatas', $d(13)->setTime(9, 0), [
            'status' => S::Approved, 'by' => 'joaquim.pires', 'inspector' => 'ines.costa', 'activity' => $acts['2.2']->id,
            'location' => $floors['Cave']->id, 'title' => 'Armaduras das sapatas S1 a S4', 'release' => 'ines.costa',
        ]);
        $muros = $this->inspection($p, 'impermeabilizacao', $d(27)->setTime(9, 0), [
            'status' => S::Rejected, 'by' => 'joaquim.pires', 'inspector' => 'carla.mendes', 'decider' => 'rui.barbosa', 'element' => $elements['M1']->id,
            'answers' => [1 => ['nok', 6, 'Sobreposição de 6 cm em várias juntas.']], 'note' => 'Refazer as sobreposições antes do aterro.',
        ]);
        $this->inspection($p, 'impermeabilizacao', $d(29)->setTime(9, 0), [
            'status' => S::Approved, 'by' => 'joaquim.pires', 'inspector' => 'carla.mendes', 'decider' => 'rui.barbosa', 'element' => $elements['M1']->id,
            'reinspection_of' => $muros->id, 'title' => 'Impermeabilização do muro M1 (reinspeção)',
        ]);
        $lajeRc = $this->inspection($p, 'laje', $d(40)->setTime(8, 30), [
            'status' => S::ApprovedWithReservations, 'by' => 'ricardo.sousa', 'inspector' => 'ines.costa', 'element' => $elements['L0']->id, 'activity' => $acts['3.1']->id,
            'title' => 'Betonagem da laje do R/C', 'answers' => [2 => ['nok', 21, 'Recobrimento de 21 mm na viga V0.3.']],
            'note' => 'Aprovada com reserva: corrigir os espaçadores da viga V0.3 antes da betonagem.', 'release' => 'ines.costa',
        ]);
        $lajeP1 = $this->inspection($p, 'laje', $d(54)->setTime(15, 0), [
            'status' => S::Scheduled, 'by' => 'ricardo.sousa', 'inspector' => 'ines.costa', 'element' => $elements['L1']->id, 'activity' => $acts['3.2']->id,
            'title' => 'Betonagem da laje do piso 1', 'scheduled' => $this->nextWorkday()->setTime(9, 30),
        ]);
        $this->inspection($p, 'seguranca', $this->today->subDays(3)->setTime(10, 0), [
            'status' => S::Submitted, 'by' => 'nuno.teixeira', 'inspector' => 'nuno.teixeira', 'title' => 'Andaimes e proteções coletivas (semana)',
            'answers' => [1 => ['nok', null, 'Falta guarda-corpos no lado poente da laje do piso 1.']],
        ]);
        $this->inspection($p, 'alvenaria', $this->today->subDays(1)->setTime(16, 0), [
            'status' => S::Requested, 'by' => 'joaquim.pires', 'title' => 'Primeira fiada das alvenarias do R/C', 'location' => $floors['R/C']->id,
        ]);
        $this->inspection($p, 'alvenaria', $this->today->setTime(8, 0), [
            'status' => S::Draft, 'by' => 'sofia.carvalho', 'title' => 'Alvenarias exteriores do piso 1 (preparação)', 'location' => $floors['Piso 1']->id,
        ]);
        unset($sapatas, $lajeP1);

        $nokItem = $lajeRc->items->firstWhere('result', 'nok');
        $this->at($d(40)->setTime(12, 0));
        $this->issue($p, [
            'kind' => 'nonconformity', 'severity' => 'major', 'title' => 'Recobrimento insuficiente na viga V0.3',
            'description' => 'Recobrimento medido de 21 mm (mínimo 25 mm). Espaçadores deslocados durante a montagem.', 'status' => 'closed',
            'by' => 'ines.costa', 'assignee' => 'joaquim.pires', 'due' => $d(41), 'element' => $elements['V0.3']->id, 'specialty' => 'EST',
            'inspection' => $lajeRc, 'item' => $nokItem,
            'steps' => [['start', 'joaquim.pires', null, 0], ['resolve', 'joaquim.pires', 'Espaçadores de 30 mm recolocados em toda a viga; verificado com régua.', 1], ['close', 'ines.costa', null, 1]],
            'photos' => 1,
        ]);
        $this->at($d(43)->setTime(9, 0));
        $this->issue($p, [
            'kind' => 'nonconformity', 'severity' => 'minor', 'title' => 'Ninhos de brita na face do pilar P4',
            'description' => 'Pequenos ninhos de brita após a descofragem, sem armaduras à vista.', 'status' => 'resolved',
            'by' => 'carla.mendes', 'assignee' => 'joaquim.pires', 'due' => $d(47), 'element' => $elements['P4']->id, 'specialty' => 'EST',
            'steps' => [['resolve', 'joaquim.pires', 'Reparado com argamassa de reparação estrutural (R4) conforme a ficha técnica.', 3]], 'photos' => 2,
        ]);
        $this->at($d(30)->setTime(14, 0));
        $this->issue($p, [
            'kind' => 'occurrence', 'severity' => 'minor', 'title' => 'Atraso na entrega do aço da estrutura', 'description' => 'O fornecedor adiou a entrega dois dias.',
            'status' => 'closed', 'by' => 'ricardo.sousa', 'assignee' => 'sofia.carvalho', 'due' => $d(32),
            'steps' => [['resolve', 'sofia.carvalho', 'Entrega feita; plano de trabalhos ajustado sem impacto no prazo.', 2], ['close', 'ricardo.sousa', null, 2]],
        ]);
        $this->at($this->today->subDays(9)->setTime(11, 0));
        $this->issue($p, [
            'kind' => 'nonconformity', 'severity' => 'critical', 'title' => 'Guarda-corpos em falta na bordadura da laje do piso 1',
            'description' => 'Lado poente sem proteção coletiva. Risco de queda em altura.', 'status' => 'open',
            'by' => 'nuno.teixeira', 'assignee' => 'joaquim.pires', 'due' => $this->today->subDays(6), 'location' => $floors['Piso 1']->id,
            'comments' => [['nuno.teixeira', 'Zona interditada com fita até à colocação dos guarda-corpos.', 0]], 'photos' => 1,
        ]);
        $this->at($this->today->subDays(4)->setTime(15, 0));
        $this->issue($p, [
            'kind' => 'occurrence', 'severity' => 'major', 'title' => 'Fissura no muro do vizinho (lado nascente)',
            'description' => 'O vizinho reportou uma fissura no muro de vedação. Feito registo fotográfico com o vizinho presente.', 'status' => 'in_progress',
            'by' => 'ricardo.sousa', 'assignee' => 'rui.barbosa', 'due' => $this->today->addDays(10),
            'steps' => [['start', 'rui.barbosa', null, 1]], 'comments' => [['rui.barbosa', 'Participado à seguradora. Colocadas testemunhas de gesso para acompanhar.', 1]], 'photos' => 1,
        ]);
        $this->at($this->today->subDays(2)->setTime(8, 30));
        $this->issue($p, [
            'kind' => 'occurrence', 'severity' => 'minor', 'title' => 'Contentor de resíduos cheio', 'description' => 'Pedida a recolha ao operador de resíduos.',
            'status' => 'open', 'by' => 'joaquim.pires', 'assignee' => 'teresa.monteiro', 'due' => $this->today->addDays(2),
        ]);
    }

    // ------------------------------------------------------------------ peças reutilizáveis

    /**
     * @param  list<array{0: string, 1: string, 2: string|null, 3: int, 4: list<array{0: string, 1: string, 2: int}>|null}>  $rows  código, nome, pai, duração, precedências
     * @param  array<string, int>  $progress
     * @param  array<string, array{0: float, 1: float, 2: float}>  $pert
     * @return array<string, Activity>
     */
    private function plan(Project $p, CarbonImmutable $start, array $rows, array $progress, array $pert = []): array
    {
        $parents = collect($rows)->pluck(2)->filter()->unique()->all();
        $acts = [];
        foreach ($rows as $i => [$code, $name, $parent, $duration, $deps]) {
            $pct = $progress[$code] ?? 0;
            $acts[$code] = Activity::query()->create([
                'project_id' => $p->id, 'parent_id' => $parent ? $acts[$parent]->id : null, 'code' => $code, 'name' => $name,
                'duration_days' => in_array($code, $parents, true) ? 0 : $duration, 'progress_pct' => $pct,
                'completed_at' => $pct === 100 ? $start->addDays(10 + $i * 4)->min($this->today)->setTime(18, 0) : null,
                'optimistic_days' => $pert[$code][0] ?? null, 'likely_days' => $pert[$code][1] ?? null, 'pessimistic_days' => $pert[$code][2] ?? null,
                'sort_order' => $i,
            ]);
        }
        foreach ($rows as [$code, , , , $deps]) {
            foreach ($deps ?? [] as [$predecessor, $type, $lag]) {
                ActivityDependency::query()->create(['predecessor_id' => $acts[$predecessor]->id, 'successor_id' => $acts[$code]->id, 'type' => $type, 'lag_days' => $lag]);
            }
        }

        return $acts;
    }

    /**
     * Capítulos: [código, nome, código do pai, artigos]. Artigo: [código, artigo da biblioteca, descrição, unidade, quantidade, natureza, custo unitário em cêntimos, especialidade].
     *
     * @param  list<array<int, mixed>>  $chapters
     */
    private function budget(Project $p, string $code, string $name, float $overhead, float $profit, string $vatMode, float $vatRate, array $chapters, ?CarbonImmutable $approvedAt = null, ?string $approvedBy = null, ?string $notes = null): Budget
    {
        $budget = Budget::query()->create([
            'project_id' => $p->id, 'code' => $code, 'name' => $name, 'version' => 1, 'status' => 'draft', 'vat_mode' => $vatMode, 'vat_rate' => $vatRate,
            'overhead_pct' => $overhead, 'profit_pct' => $profit, 'notes' => $notes, 'created_by_user_id' => $this->users['pedro.almeida']->id,
        ]);
        $ids = [];
        $sort = 0;
        $itemSort = 0;
        foreach ($chapters as [$chapterCode, $chapterName, $parent, $rows]) {
            $chapter = BudgetChapter::query()->create(['budget_id' => $budget->id, 'parent_id' => $parent ? $ids[$parent] : null, 'code' => $chapterCode, 'name' => $chapterName, 'sort_order' => $sort++]);
            $ids[$chapterCode] = $chapter->id;
            foreach ($rows as $row) {
                [$itemCode, $articleKey, $description, $unit, $quantity, $kind, $cents] = $row;
                $specialty = $row[7] ?? null;
                $article = $articleKey ? $this->articles[$articleKey] : null;
                $item = BudgetItem::query()->create([
                    'budget_id' => $budget->id, 'chapter_id' => $chapter->id, 'article_id' => $article?->id,
                    'specialty_id' => $specialty ? $this->specialties[$specialty] : null, 'code' => $itemCode,
                    'description' => $description ?? $article?->description ?? '', 'unit' => $unit ?? $article?->unit ?? 'un',
                    'quantity' => $quantity, 'unit_cost_cents' => $cents ?? 0, 'unit_price_cents' => null, 'sort_order' => $itemSort++,
                ]);
                $components = $article
                    ? $article->components->map(fn (ArticleComponent $c) => [
                        'resource_id' => $c->resource_id, 'kind' => $c->resource->kind->value, 'name' => $c->resource->name, 'unit' => $c->resource->unit,
                        'quantity' => (float) $c->quantity, 'unit_cost_cents' => (int) $c->resource->unit_cost_cents,
                    ])->all()
                    : [['resource_id' => null, 'kind' => $kind === 'other' ? 'labor' : $kind, 'name' => $description, 'unit' => $unit, 'quantity' => 1, 'unit_cost_cents' => $cents]];
                foreach (array_values($components) as $i => $component) {
                    $item->components()->create($component + ['sort_order' => $i]);
                }
                $item->forceFill(['unit_cost_cents' => BudgetCalculator::unitCostOf($components)])->save();
            }
        }
        if ($approvedAt !== null) {
            $budget->forceFill(['status' => 'approved', 'approved_by_user_id' => $this->users[$approvedBy ?? 'helena.marques']->id, 'approved_at' => $approvedAt])->save();
        }

        return $budget->load(['items', 'chapters']);
    }

    /** Versão 2 em rascunho, cópia da aprovada, com um capítulo de trabalhos a mais. */
    private function revision(Budget $budget, string $notes): void
    {
        $this->at($this->today->subDays(2)->setTime(11, 0));
        $copy = $budget->replicate(['status', 'approved_by_user_id', 'approved_at']);
        $copy->forceFill(['version' => 2, 'status' => 'draft', 'revision_of_id' => $budget->id, 'notes' => $notes])->save();
        $map = [];
        foreach ($budget->chapters()->orderBy('sort_order')->get() as $chapter) {
            $new = $chapter->replicate();
            $new->forceFill(['budget_id' => $copy->id, 'parent_id' => $chapter->parent_id ? $map[$chapter->parent_id] : null])->save();
            $map[$chapter->id] = $new->id;
        }
        foreach ($budget->items()->with('components')->get() as $item) {
            $new = $item->replicate();
            $new->forceFill(['budget_id' => $copy->id, 'chapter_id' => $map[$item->chapter_id]])->save();
            foreach ($item->components as $component) {
                $c = $component->replicate();
                $c->forceFill(['budget_item_id' => $new->id])->save();
            }
        }
        $chapter = BudgetChapter::query()->create(['budget_id' => $copy->id, 'parent_id' => null, 'code' => '9', 'name' => 'Trabalhos a mais', 'sort_order' => 99]);
        $article = $this->articles['ALV'];
        $item = BudgetItem::query()->create([
            'budget_id' => $copy->id, 'chapter_id' => $chapter->id, 'article_id' => $article->id, 'code' => '9.1',
            'description' => 'Muro de vedação em bloco térmico, incluindo sapata corrida', 'unit' => 'm2', 'quantity' => 48,
            'unit_cost_cents' => $article->unitCostCents(), 'sort_order' => 999,
        ]);
        foreach ($article->components as $i => $c) {
            $item->components()->create(['resource_id' => $c->resource_id, 'kind' => $c->resource->kind->value, 'name' => $c->resource->name, 'unit' => $c->resource->unit, 'quantity' => (float) $c->quantity, 'unit_cost_cents' => (int) $c->resource->unit_cost_cents, 'sort_order' => $i]);
        }
    }

    /** Pré-fatura do auto, pronta a faturar e (com número oficial) já faturada no programa certificado. */
    private function invoice(Certificate $certificate, CarbonImmutable $readyOn, ?string $official, ?CarbonImmutable $officialOn): void
    {
        // Fica para o fim, para os números internos saírem pela ordem das datas.
        $this->pendingInvoices[] = [$certificate, $readyOn, $official, $officialOn];
    }

    private function flushInvoices(): void
    {
        usort($this->pendingInvoices, fn ($a, $b) => $a[1] <=> $b[1]);
        foreach ($this->pendingInvoices as [$certificate, $readyOn, $official, $officialOn]) {
            $this->issueInvoice($certificate, $readyOn, $official, $officialOn);
        }
        $this->pendingInvoices = [];
    }

    private function issueInvoice(Certificate $certificate, CarbonImmutable $readyOn, ?string $official, ?CarbonImmutable $officialOn): void
    {
        $by = $this->users['teresa.monteiro'];
        $this->at($readyOn->setTime(10, 30));
        $draft = InvoiceDrafts::fromCertificate($certificate->refresh(), $by->id);
        $draft->forceFill(['due_date' => $readyOn->addDays(30)->toDateString()])->save();
        InvoiceDrafts::markReady($draft, $readyOn->toDateString());
        if ($official !== null && $officialOn !== null) {
            $this->at($officialOn->setTime(16, 0));
            InvoiceDrafts::markInvoiced($draft, $official, $officialOn->min($this->today)->toDateString(), $by->id);
        }
    }

    private function ownerCertificate(Project $p, Budget $budget, CarbonImmutable $from, CarbonImmutable $to, string $status): Certificate
    {
        $this->at($to->addDays(1)->setTime(10, 0));
        $certificate = CertificateBuilder::create($p, ['period_start' => $from->toDateString(), 'period_end' => $to->toDateString()], null, $budget, $this->users['pedro.almeida']->id);
        $this->decideCertificate($certificate, $to, $status);

        return $certificate;
    }

    /** @param  list<float>  $quantities  quantidade deste período por artigo do contrato */
    private function contractCertificate(Project $p, Contract $contract, CarbonImmutable $from, CarbonImmutable $to, array $quantities, string $status): Certificate
    {
        $this->at($to->addDays(1)->setTime(10, 0));
        $certificate = CertificateBuilder::create($p, ['period_start' => $from->toDateString(), 'period_end' => $to->toDateString()], $contract->load('items'), null, $this->users['sofia.carvalho']->id);
        foreach ($certificate->lines()->orderBy('sort_order')->get() as $i => $line) {
            $qty = $quantities[$i] ?? 0;
            $line->forceFill(['qty_period' => $qty, 'amount_cents' => (int) round($qty * $line->unit_price_cents)])->save();
        }
        CertificateBuilder::recompute($certificate);
        $this->decideCertificate($certificate, $to, $status);

        return $certificate;
    }

    private function decideCertificate(Certificate $certificate, CarbonImmutable $to, string $status): void
    {
        if ($status === 'draft') {
            return;
        }
        $submitted = $to->addDays(2)->min($this->today)->setTime(11, 0);
        $certificate->forceFill(['status' => 'submitted', 'submitted_at' => $submitted])->save();
        if ($status === 'approved') {
            $approved = $to->addDays(5)->min($this->today)->setTime(16, 0);
            $certificate->forceFill(['status' => 'approved', 'approved_by_user_id' => $this->users['ines.costa']->id, 'approved_at' => $approved])->save();
        }
    }

    /** @param  list<array{0: int|null, 1: string, 2: string, 3: float, 4: int}>  $items */
    private function contract(Project $p, string $code, string $partner, string $title, float $retention, CarbonImmutable $startsOn, CarbonImmutable $endsOn, string $status, array $items): Contract
    {
        $this->at($startsOn->subDays(7)->setTime(10, 0));
        $contract = Contract::query()->create([
            'project_id' => $p->id, 'partner_id' => $this->partners[$partner]->id, 'code' => $code, 'title' => $title, 'retention_pct' => $retention,
            'starts_on' => $startsOn->toDateString(), 'ends_on' => $endsOn->toDateString(), 'status' => $status, 'created_by_user_id' => $this->users['sofia.carvalho']->id,
        ]);
        foreach ($items as $i => [$budgetItem, $description, $unit, $quantity, $cents]) {
            ContractItem::query()->create(['contract_id' => $contract->id, 'budget_item_id' => $budgetItem, 'description' => $description, 'unit' => $unit, 'quantity' => $quantity, 'unit_price_cents' => $cents, 'sort_order' => $i]);
        }

        return $contract;
    }

    /**
     * @param  list<array{0: string|null, 1: string|null, 2: string, 3: string, 4: float, 5: int}>  $lines  recurso, artigo do orçamento, descrição, unidade, quantidade, preço
     * @param  array{0: string, 1: CarbonImmutable}|null  $invoice
     */
    private function purchase(Project $p, string $partner, string $category, CarbonImmutable $orderedOn, string $status, array $lines, ?array $invoice = null): PurchaseOrder
    {
        $this->at($orderedOn->subDay()->setTime(15, 0));
        $budgetItems = $p->budgets()->where('status', 'approved')->latest('approved_at')->first()?->items()->pluck('id', 'code') ?? collect();
        $total = (int) round(collect($lines)->sum(fn ($l) => $l[4] * $l[5]));
        $order = PurchaseOrder::query()->create([
            'project_id' => $p->id, 'partner_id' => $this->partners[$partner]->id, 'number' => (int) $p->purchaseOrders()->max('number') + 1,
            'status' => $status, 'category' => $category, 'cost_center_id' => $p->cost_center_id,
            'ordered_on' => $status === 'draft' ? null : $orderedOn->toDateString(), 'expected_on' => $orderedOn->addDays(5)->toDateString(),
            'approved_by_user_id' => in_array($status, ['approved', 'received', 'invoiced'], true) ? $this->users['ricardo.sousa']->id : null,
            'approved_at' => in_array($status, ['approved', 'received', 'invoiced'], true) ? $orderedOn->setTime(9, 0) : null,
            'received_at' => in_array($status, ['received', 'invoiced'], true) ? $orderedOn->addDays(4)->min($this->today)->setTime(11, 0) : null,
            'invoice_number' => $invoice[0] ?? null, 'invoiced_on' => isset($invoice[1]) ? $invoice[1]->toDateString() : null,
            'invoiced_cents' => $invoice ? $total : null, 'created_by_user_id' => $this->users['sofia.carvalho']->id,
        ]);
        foreach ($lines as $i => [$resource, $budgetCode, $description, $unit, $quantity, $cents]) {
            PurchaseOrderLine::query()->create([
                'purchase_order_id' => $order->id, 'resource_id' => $resource ? $this->resources[$resource]->id : null,
                'budget_item_id' => $budgetCode ? ($budgetItems[$budgetCode] ?? null) : null, 'description' => $description, 'unit' => $unit,
                'quantity' => $quantity, 'unit_price_cents' => $cents, 'received_quantity' => in_array($status, ['received', 'invoiced'], true) ? $quantity : 0, 'sort_order' => $i,
            ]);
        }

        return $order;
    }

    /**
     * Documento de fornecedor como se o DocIntel o tivesse lido (o PDF é gerado aqui). Lançado: na compra
     * (que já está faturada com o mesmo número) ou como custo; por rever: com a compra proposta pelas regras.
     *
     * @param  list<array{0: string, 1: string, 2: float|int, 3: ?int}>  $lines  descrição, unidade, quantidade, preço (cêntimos, sem IVA)
     * @param  array{order?: PurchaseOrder, confidence?: float, reason?: string, supplier_name?: string, category?: string, order_reference?: string}  $o
     */
    private function supplierDocument(Project $p, ?string $partner, string $kind, string $number, CarbonImmutable $date, array $lines, string $status, array $o = []): SupplierDocument
    {
        $this->at($date->setTime(16, 30));
        $partnerModel = $partner ? $this->partners[$partner] : null;
        $supplierName = $partnerModel?->name ?? $o['supplier_name'];
        $net = $kind === 'delivery_note' ? null : (int) round(collect($lines)->sum(fn ($l) => $l[2] * $l[3]));
        $vat = $net === null ? null : (int) collect($lines)->sum(fn ($l) => (int) round($l[2] * $l[3] * 0.23));

        $file = $this->supplierPdf($kind, $supplierName, $number, $date, $lines, $net, $vat, $o['order_reference'] ?? null);
        $doc = new SupplierDocument;
        $doc->forceFill([
            'project_id' => $p->id, 'partner_id' => $partnerModel?->id, 'cost_center_id' => $p->cost_center_id,
            'kind' => $kind, 'status' => 'to_review', 'category' => $o['category'] ?? 'material',
            'disk' => config('obraconnect.files.disk'), 'path' => '', 'original_name' => $file->getClientOriginalName(),
            'mime' => 'application/pdf', 'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
            'reader' => 'docintel', 'reader_document_id' => (string) Str::uuid(), 'confidence' => $o['confidence'] ?? 0.95,
            'review_reasons' => [], 'read_at' => now()->addMinute(),
            'lines' => array_map(fn ($l) => [
                'code' => null, 'description' => $l[0], 'quantity' => (float) $l[2], 'unit' => $l[1],
                'unit_price' => $l[3] === null ? null : $l[3] / 100, 'vat_rate' => $l[3] === null ? null : 23.0,
                'total' => $l[3] === null ? null : round($l[2] * $l[3] / 100, 2), 'arithmetic_ok' => $l[3] === null ? null : true,
            ], $lines),
            'supplier_name' => $supplierName, 'number' => $number, 'issue_date' => $date->toDateString(),
            'due_date' => $kind === 'invoice' ? $date->addDays(30)->toDateString() : null,
            'order_reference' => $o['order_reference'] ?? null,
            'net_cents' => $net, 'vat_cents' => $vat, 'gross_cents' => $net === null ? null : $net + $vat,
            'created_by_user_id' => $this->users['sofia.carvalho']->id,
        ])->save();
        $disk = Storage::disk($doc->disk);
        $doc->forceFill(['path' => $disk->putFileAs($doc->directory(), $file, 'original-'.Str::lower(Str::random(8)).'.pdf')])->save();
        app(StorageUsage::class)->add($this->tenant, $doc->size_bytes);

        if ($status === 'to_review') {
            SupplierDocuments::guessLinks($doc);
            $doc->save();
        } elseif ($status === 'rejected') {
            $doc->forceFill(['status' => 'rejected', 'notes' => 'Rejeitado: '.$o['reason']])->save();
        } elseif ($status === 'posted') {
            $this->at($date->setTime(17, 45));
            if (isset($o['order'])) {
                // A compra já foi faturada (purchase()) com o mesmo número e valor: só se liga.
                $doc->purchase_order_id = $o['order']->id;
            } else {
                $entry = new CostEntry([
                    'category' => $doc->category, 'kind' => 'actual', 'description' => mb_substr($doc->label(), 0, 255),
                    'amount_cents' => $net, 'incurred_on' => $date->toDateString(), 'cost_center_id' => $p->cost_center_id,
                ]);
                $entry->project_id = $p->id;
                $entry->created_by_user_id = $this->users['sofia.carvalho']->id;
                $entry->save();
                $doc->cost_entry_id = $entry->id;
            }
            $doc->forceFill(['status' => 'posted', 'posted_at' => now(), 'posted_by_user_id' => $this->users['sofia.carvalho']->id])->save();
        }

        return $doc;
    }

    /** @param  list<array{0: string, 1: string, 2: float|int, 3: ?int}>  $lines */
    private function supplierPdf(string $kind, string $supplier, string $number, CarbonImmutable $date, array $lines, ?int $net, ?int $vat, ?string $orderReference): UploadedFile
    {
        $money = fn (?int $c) => $c === null ? '' : number_format($c / 100, 2, ',', '.').' €';
        $title = ['invoice' => 'Fatura', 'receipt' => 'Fatura simplificada', 'credit_note' => 'Nota de crédito', 'delivery_note' => 'Guia de remessa'][$kind] ?? 'Documento';
        $rows = implode('', array_map(fn ($l) => '<tr><td>'.e($l[0]).'</td><td class="r">'.e(str_replace('.', ',', (string) $l[2])).' '.e($l[1]).'</td>'
            .'<td class="r">'.($l[3] === null ? '' : $money($l[3])).'</td><td class="r">'.($l[3] === null ? '' : '23%').'</td>'
            .'<td class="r">'.($l[3] === null ? '' : $money((int) round($l[2] * $l[3]))).'</td></tr>', $lines));
        $totals = $net === null ? '' : '<table class="tot"><tr><td>Total sem IVA</td><td class="r">'.$money($net).'</td></tr>'
            .'<tr><td>IVA 23%</td><td class="r">'.$money($vat).'</td></tr><tr><td><strong>Total</strong></td><td class="r"><strong>'.$money($net + $vat).'</strong></td></tr></table>';
        $html = '<html><head><meta charset="utf-8"><style>
            body{font-family:"DejaVu Sans",sans-serif;font-size:9.5pt;color:#1e293b;margin:28px}
            h1{font-size:15pt;margin:0 0 2px} .muted{color:#64748b}
            table{border-collapse:collapse;width:100%;margin-top:16px} th,td{border-bottom:1px solid #cbd5e1;padding:5px;text-align:left}
            .r{text-align:right} .tot{width:45%;margin-left:55%}
            .stamp{margin-top:36px;font-size:8.5pt;color:#b91c1c;border:1px dashed #b91c1c;padding:8px;text-align:center}
            </style></head><body>'
            .'<h1>'.e($supplier).'</h1><div class="muted">Fornecedor fictício · Distrito de Aveiro</div>'
            .'<p><strong>'.e($title).' '.e($number).'</strong><br>Data: '.e($date->format('d/m/Y'))
            .'<br>Cliente: Atlântico Construções, Lda.'.($orderReference ? '<br>'.e($orderReference) : '').'</p>'
            .'<table><tr><th>Descrição</th><th class="r">Quantidade</th><th class="r">Preço</th><th class="r">IVA</th><th class="r">Valor</th></tr>'.$rows.'</table>'
            .$totals
            .'<div class="stamp">Documento fictício, gerado para a demonstração do ObraConnect. Não tem valor fiscal.</div>'
            .'</body></html>';

        $dir = storage_path('app/dompdf');
        File::ensureDirectoryExists($dir);
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setTempDir($dir);
        $options->setFontCache($dir);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $path = $this->tempPath('pdf');
        file_put_contents($path, $pdf->output());

        return new UploadedFile($path, Str::slug($title.' '.$number).'.pdf', 'application/pdf', null, true);
    }

    /** @param  list<string>  $workers */
    private function timesheets(Project $p, array $workers, int $days, ?int $activityId, string $by, string $approver, ?CarbonImmutable $from = null, bool $approveAll = false): void
    {
        $dates = $from ? array_map(fn (int $n) => $this->workday($from, $n), range(0, $days - 1)) : $this->lastWorkdays($days);
        $monday = $this->today->startOfWeek();
        foreach ($dates as $n => $date) {
            if ($date->greaterThan($this->today)) {
                continue;
            }
            $rainy = $n % 9 === 5;
            $approved = $approveAll || $date->lessThan($monday);
            $this->at($date->setTime(18, 15));
            foreach ($workers as $w => $key) {
                $worker = $this->workers[$key];
                $overtime = ! $rainy && ($n + $w) % 7 === 3 ? 1.5 : 0;
                TimeEntry::query()->create([
                    'project_id' => $p->id, 'worker_id' => $worker->id, 'activity_id' => $activityId, 'work_date' => $date->toDateString(),
                    'hours' => $rainy ? 4 : 8, 'overtime_hours' => $overtime, 'description' => $rainy ? 'Paragem por chuva à tarde' : null,
                    'hourly_cost_cents' => $worker->hourly_cost_cents, 'status' => $approved ? 'approved' : 'pending',
                    'approved_by_user_id' => $approved ? $this->users[$approver]->id : null,
                    'approved_at' => $approved ? $date->addDays(3)->min($this->today)->setTime(9, 0) : null,
                    'created_by_user_id' => $this->users[$by]->id,
                ]);
            }
        }
    }

    /** @param  array<string, float>  $hours  equipamento => horas por dia */
    private function equipmentOnSite(Project $p, array $hours, int $days): void
    {
        foreach ($hours as $key => $perDay) {
            $this->equipment[$key]->forceFill(['project_id' => $p->id, 'status' => 'in_use'])->save();
            foreach ($this->lastWorkdays($days) as $date) {
                $this->equipmentUsage($p, $key, $date, 1, $perDay, null);
            }
        }
    }

    private function equipmentUsage(Project $p, string $key, CarbonImmutable $from, int $days, float $hours, ?string $notes): void
    {
        $equipment = $this->equipment[$key];
        for ($i = 0; $i < $days; $i++) {
            $date = $this->workday($from, $i);
            if ($date->greaterThan($this->today)) {
                break;
            }
            $this->at($date->setTime(18, 0));
            EquipmentUsage::query()->create([
                'equipment_id' => $equipment->id, 'project_id' => $p->id, 'used_on' => $date->toDateString(), 'hours' => $hours,
                'hourly_cost_cents' => $equipment->hourly_cost_cents, 'notes' => $notes, 'created_by_user_id' => $this->users['joaquim.pires']->id,
            ]);
        }
    }

    /**
     * @param  list<string>  $summaries  um por dia, do mais antigo para o mais recente
     * @param  array<string, ProjectLocation>  $floors
     * @param  array<string, ConstructionElement>  $elements
     */
    private function diary(Project $p, int $days, array $summaries, array $floors, array $elements): void
    {
        $weathers = ['limpo', 'limpo', 'nublado', 'limpo', 'nublado', 'chuva_forte', 'nublado', 'limpo', 'limpo', 'nevoeiro', 'limpo', 'nublado'];
        $author = $p->code === 'ATL-26-01' ? 'joaquim.pires' : 'antonio.ramos';
        foreach ($this->lastWorkdays($days) as $n => $date) {
            $isToday = $date->isSameDay($this->today);
            $weather = $weathers[$n % count($weathers)];
            $this->at($date->setTime(17, 45));
            $entry = DiaryEntry::query()->create([
                'project_id' => $p->id, 'entry_date' => $date->toDateString(), 'status' => $isToday ? 'draft' : 'submitted',
                'weather' => ['morning' => $weather === 'chuva_forte' ? 'nublado' : $weather, 'afternoon' => $weather, 'temp_min' => 7 + $n % 5, 'temp_max' => 15 + $n % 6],
                'work_summary' => $summaries[$n] ?? 'Trabalhos conforme o plano.',
                'notes' => $weather === 'chuva_forte' ? 'Betonagem adiada por chuva forte.' : null,
                'created_by_user_id' => $this->users[$author]->id, 'updated_by_user_id' => $this->users[$author]->id,
                'submitted_at' => $isToday ? null : $date->setTime(18, 5), 'submitted_by_user_id' => $isToday ? null : $this->users[$author]->id,
            ]);
            $sort = 0;
            $rows = $p->code === 'ATL-26-01'
                ? [
                    ['workforce', 'Pedreiros', 3, 'un', null], ['workforce', 'Serventes', 2, 'un', null], ['workforce', 'Carpinteiro de cofragens', 2, 'un', 'Cofragens Silva & Filhos'],
                    ['workforce', 'Armadores de ferro', 1, 'un', null], ['equipment', 'Grua torre GR-01', 6, 'h', null],
                ]
                : [['workforce', 'Pedreiros', 2, 'un', null], ['workforce', 'Pintor', 1, 'un', null], ['equipment', 'Betoneira BT-01', 3, 'h', null]];
            if ($n % 4 === 1) {
                $rows[] = ['material', $p->code === 'ATL-26-01' ? 'Aço A500 NR (guia 77812)' : 'Madeira lamelada (guia 3341)', $p->code === 'ATL-26-01' ? 2.4 : 5.2, $p->code === 'ATL-26-01' ? 't' : 'm3', $p->code === 'ATL-26-01' ? 'Ferros e Aços da Bairrada' : 'Madeiras e Derivados do Norte'];
            }
            if ($weather === 'chuva_forte') {
                $rows[] = ['incident', 'Paragem de 4 h por chuva forte', null, null, null];
            }
            if ($n % 5 === 3) {
                $rows[] = ['visit', 'Visita da fiscalização (Eng.ª Inês Costa)', null, null, null];
            }
            foreach ($rows as [$kind, $description, $quantity, $unit, $company]) {
                $entry->items()->create(['kind' => $kind, 'description' => $description, 'quantity' => $quantity, 'unit' => $unit, 'company' => $company, 'sort_order' => $sort++]);
            }
            if ($n % 2 === 0 || $isToday) {
                $captions = ['Vista geral da obra', 'Pormenor das armaduras', 'Cofragem e escoramentos', 'Trabalhos do dia'];
                $caption = $captions[$n % count($captions)];
                $this->media->storeDiaryPhoto($p, $entry, $this->photo("{$p->code} · {$caption} · {$date->format('d/m/Y')}"), 'image/jpeg', $caption, $this->users[$author]);
            }
        }
        unset($floors, $elements);
    }

    private function projectDocuments(Project $p): void
    {
        $by = $this->users['sofia.carvalho'];
        $folders = [];
        foreach (['01 Projeto', '02 Segurança e saúde', '03 Licenciamento', '04 Fichas técnicas e certificados'] as $name) {
            $folders[$name] = DocumentFolder::query()->create(['project_id' => $p->id, 'name' => $name, 'created_by_user_id' => $by->id]);
        }
        foreach ([
            ['01 Projeto', 'Arquitetura — plantas dos pisos', 'ARQ', [
                ['Versão do licenciamento.', ['Planta da cave, R/C e piso 1 à escala 1:100.', 'Quadro de áreas: cave 98 m2; R/C 142 m2; piso 1 128 m2.']],
                ['Alteração da escada interior pedida pelo dono de obra.', ['Revisão B: escada interior com patamar intermédio.', 'Quadro de áreas atualizado: piso 1 131 m2.']],
            ]],
            ['01 Projeto', 'Estabilidade — memória descritiva e justificativa', 'EST', [[null, ['Estrutura em betão armado C25/30, aço A500 NR.', 'Fundações diretas por sapatas isoladas e lintéis de travamento.', 'Recobrimento nominal: 30 mm em elementos interiores, 35 mm em fundações.']]]],
            ['01 Projeto', 'Instalações elétricas — esquemas dos quadros', 'ELE', [[null, ['Quadro geral e quadros parciais por piso.', 'Potência contratada prevista: 10,35 kVA.']]]],
            ['02 Segurança e saúde', 'Plano de segurança e saúde (desenvolvimento)', null, [[null, ['Riscos principais: quedas em altura, soterramento, movimentação de cargas.', 'Medidas: guarda-corpos em bordaduras, entivação acima de 1,20 m, sinaleiro para a grua.']]]],
            ['03 Licenciamento', 'Alvará de licença de construção', null, [[null, ['Documento fictício para demonstração.', 'Prazo de execução: 12 meses.']]]],
            ['04 Fichas técnicas e certificados', 'Betão C25/30 — ficha técnica e declaração de desempenho', 'EST', [[null, ['Classe de resistência C25/30, exposição XC2, consistência S3, Dmáx 22 mm.']]]],
            ['04 Fichas técnicas e certificados', 'Bloco térmico 30 cm — ficha técnica', 'TER', [[null, ['Condutibilidade térmica equivalente e resistência mecânica declaradas pelo fabricante (valores fictícios).']]]],
        ] as $n => [$folder, $title, $specialty, $versions]) {
            $this->at($this->today->subDays(70 - $n * 6)->setTime(10, 0));
            $document = Document::query()->create([
                'project_id' => $p->id, 'folder_id' => $folders[$folder]->id, 'title' => $title, 'description' => null,
                'specialty_id' => $specialty ? $this->specialties[$specialty] : null, 'created_by_user_id' => $by->id,
            ]);
            foreach ($versions as $v => [$note, $paragraphs]) {
                $this->at($this->today->subDays(70 - $n * 6 - $v * 20)->setTime(11, 0));
                $this->documents->storeVersion($document, $this->pdf($title, $p, $paragraphs, $v + 1), $note, $by);
            }
        }
    }

    /**
     * Uma inspeção com o histórico completo até ao estado pedido (cada passo com a sua data, para a cadeia de hash bater).
     *
     * @param  array<string, mixed>  $o
     */
    private function inspection(Project $p, string $templateKey, CarbonImmutable $at, array $o): Inspection
    {
        $template = $this->templates[$templateKey];
        $target = $o['status'];
        $by = $this->users[$o['by']];
        $inspector = $this->users[$o['inspector'] ?? 'carla.mendes'];
        $decider = $this->users[$o['decider'] ?? ($o['inspector'] ?? 'ines.costa')];
        $rank = match ($target) {
            S::Draft => 0, S::Requested => 1, S::Scheduled => 2, S::InProgress => 3, S::Submitted => 4, default => 5,
        };

        $this->at($at);
        $inspection = Inspection::query()->create([
            'project_id' => $p->id, 'number' => (int) $p->inspections()->max('number') + 1, 'title' => $o['title'] ?? $template->name,
            'checklist_template_id' => $template->id, 'template_version' => $template->version, 'reinspection_of_id' => $o['reinspection_of'] ?? null,
            'location_id' => $o['location'] ?? null, 'element_id' => $o['element'] ?? null, 'specialty_id' => $template->specialty_id,
            'status' => S::Draft, 'remote' => false, 'hold_point' => $template->hold_point, 'approvals_required' => 1,
            'activity_id' => $o['activity'] ?? null, 'created_by_user_id' => $by->id,
        ]);
        foreach ($template->items as $item) {
            $inspection->items()->create($item->only(['sort_order', 'text', 'guidance', 'requires_photo', 'min_photos', 'requires_measure', 'measure_unit', 'measure_min', 'measure_max']));
        }
        $inspection->load('items');
        $this->history->record($inspection, 'created', null, S::Draft, $by, null, ['number' => $inspection->number, 'title' => $inspection->title, 'template' => ['id' => $template->id, 'name' => $template->name, 'version' => $template->version]]);

        $t = $at;
        $step = function (string $action, S $to, User $user, CarbonImmutable $when, array $fields = [], ?string $reason = null, ?array $payload = null) use ($inspection): void {
            $this->at($when);
            $from = $inspection->status;
            $inspection->forceFill($fields + ['status' => $to])->save();
            $this->history->record($inspection, $action, $from, $to, $user, $reason, $payload);
        };
        if ($rank >= 1) {
            $t = $t->addMinutes(20);
            $step('request', S::Requested, $by, $t, ['requested_by_user_id' => $by->id, 'requested_at' => $t], null, ['hold_point' => $inspection->hold_point, 'approvals_required' => 1]);
        }
        if ($rank >= 2) {
            $t = $t->addHours(2);
            $when = $o['scheduled'] ?? $this->workday($at->addDay(), 0)->setTime(9, 0);
            $step('schedule', S::Scheduled, $inspector, $t, ['scheduled_for' => $when, 'inspector_user_id' => $inspector->id], null, ['scheduled_for' => $when->format('Y-m-d H:i')]);
            $t = $when;
        }
        if ($rank >= 3) {
            $step('start', S::InProgress, $inspector, $t, ['started_at' => $t, 'inspector_user_id' => $inspector->id]);
            $answers = $o['answers'] ?? [];
            foreach ($inspection->items as $i => $item) {
                $t = $t->addMinutes(6);
                $this->at($t);
                [$result, $measure, $notes] = $answers[$i] ?? ['ok', null, null];
                if ($item->requires_measure && $measure === null) {
                    $measure = round(((float) $item->measure_min + (float) $item->measure_max) / 2, 1);
                }
                $item->forceFill(['result' => $result, 'measure_value' => $item->requires_measure ? $measure : null, 'notes' => $notes, 'answered_by_user_id' => $inspector->id, 'answered_at' => $t])->save();
                for ($k = 0; $k < max($item->requires_photo ? 1 : 0, (int) $item->min_photos); $k++) {
                    $this->media->storeInspectionPhoto($p, $item, $this->photo("{$p->code} · Inspeção {$inspection->number} · ponto ".($i + 1)), 'image/jpeg', $item->text, $inspector);
                }
            }
        }
        if ($rank >= 4) {
            $t = $t->addMinutes(30);
            $this->at($t);
            $score = EvidenceIndex::evaluate($inspection->load('items.photos'))['score'] ?? null;
            $step('submit', S::Submitted, $inspector, $t, ['submitted_at' => $t, 'evidence_score' => $score], null, ['evidence_score' => $score]);
        }
        if ($rank >= 5) {
            $t = $t->addHours(3);
            $action = match ($target) {
                S::ApprovedWithReservations => 'approve_with_reservations', S::Rejected => 'reject', default => 'approve'
            };
            $note = $o['note'] ?? null;
            $step($action, $target, $decider, $t, ['decided_by_user_id' => $decider->id, 'decided_at' => $t, 'decision_note' => $note], $note, ['evidence_score' => $inspection->evidence_score, 'step' => 1, 'of' => 1]);
            if (isset($o['release']) && $inspection->hold_point && $target !== S::Rejected) {
                $t = $t->addMinutes(15);
                $releaser = $this->users[$o['release']];
                $this->at($t);
                $inspection->forceFill(['hold_released_at' => $t, 'hold_released_by_user_id' => $releaser->id])->save();
                $this->history->record($inspection, 'hold_released', $inspection->status, $inspection->status, $releaser, 'Pode avançar.');
            }
        }

        return $inspection->refresh()->load('items');
    }

    /** @param  array<string, mixed>  $o */
    private function issue(Project $p, array $o): Issue
    {
        $created = Carbon::now()->toImmutable();
        $by = $this->users[$o['by']];
        $assignee = isset($o['assignee']) ? $this->users[$o['assignee']] : null;
        $issue = Issue::query()->create([
            'project_id' => $p->id, 'number' => (int) $p->issues()->max('number') + 1, 'kind' => $o['kind'], 'severity' => $o['severity'], 'status' => 'open',
            'title' => $o['title'], 'description' => $o['description'] ?? null, 'location_id' => $o['location'] ?? null, 'element_id' => $o['element'] ?? null,
            'specialty_id' => isset($o['specialty']) ? $this->specialties[$o['specialty']] : null,
            'inspection_id' => isset($o['inspection']) ? $o['inspection']->id : null, 'inspection_item_id' => isset($o['item']) ? $o['item']->id : null,
            'assignee_user_id' => $assignee?->id, 'due_on' => isset($o['due']) ? $o['due']->toDateString() : null, 'created_by_user_id' => $by->id,
        ]);
        IssueEvent::add($issue, 'created', $by, null, array_filter([
            'inspection' => isset($o['inspection']) ? $o['inspection']->number : null, 'assignee' => $assignee?->name, 'due_on' => $issue->due_on?->toDateString(),
        ]));
        for ($k = 0; $k < ($o['photos'] ?? 0); $k++) {
            $this->media->storeIssuePhoto($p, $issue, $this->photo("{$p->code} · {$issue->kind->short()} {$issue->number} · foto ".($k + 1)), 'image/jpeg', null, $by);
        }
        $events = [];
        foreach ($o['steps'] ?? [] as [$action, $user, $reason, $days]) {
            $events[] = [$created->addDays($days)->addHours(2 + count($events)), 'step', $action, $user, $reason];
        }
        foreach ($o['comments'] ?? [] as [$user, $body, $days]) {
            $events[] = [$created->addDays($days)->addHours(1), 'comment', null, $user, $body];
        }
        usort($events, fn ($a, $b) => $a[0] <=> $b[0]);
        foreach ($events as [$when, $type, $action, $handle, $text]) {
            $when = $when->min($this->today->setTime(18, 0))->max($created);
            $this->at($when);
            $user = $this->users[$handle];
            if ($type === 'comment') {
                IssueEvent::add($issue, 'comment', $user, $text);

                continue;
            }
            $fields = match ($action) {
                'start' => ['status' => 'in_progress'],
                'resolve' => ['status' => 'resolved', 'resolution_note' => $text, 'resolved_by_user_id' => $user->id, 'resolved_at' => $when],
                'close' => ['status' => 'closed', 'closed_by_user_id' => $user->id, 'closed_at' => $when],
                default => [],
            };
            $issue->forceFill($fields)->save();
            IssueEvent::add($issue, $action, $user, $text);
        }
        $this->at($created);

        return $issue;
    }

    // ------------------------------------------------------------------ datas, ficheiros

    /** Faz de conta que agora é $when (datas de criação e cadeia de hash coerentes); nunca no futuro. */
    /**
     * Histórico das previsões e alertas. As seis semanas anteriores das duas obras em curso são
     * fictícias (como tudo nesta empresa) e caminham para os valores de hoje: a moradia quase estável,
     * a reabilitação a piorar todas as semanas. Depois corre o vigia de hoje, que guarda a semana atual
     * e abre os alertas (sem emails: é a empresa de demonstração).
     */
    private function forecastHistory(): void
    {
        $this->at($this->realNow);
        $calendars = app(WorkCalendars::class);
        $week = ForecastWatch::weekStart($this->today);
        // semanas para trás => [fator do custo previsto, dias a menos no fim previsto, pontos a menos de avanço]
        $paths = [
            'ATL-26-01' => [6 => [0.976, 14, 15], 5 => [0.980, 12, 12], 4 => [0.984, 10, 10], 3 => [0.988, 8, 7], 2 => [0.992, 5, 5], 1 => [0.996, 3, 2]],
            'ATL-26-02' => [6 => [0.80, 21, 16], 5 => [0.82, 18, 13], 4 => [0.85, 14, 10], 3 => [0.88, 11, 8], 2 => [0.92, 9, 5], 1 => [0.95, 7, 3]],
        ];

        foreach ($paths as $code => $weeks) {
            $p = Project::query()->where('code', $code)->firstOrFail();
            $now = ForecastWatch::snapshot(CostForecast::for($p, $this->today), ScheduleForecast::for($p, $this->today, $calendars->forProject($p)), $this->today);
            foreach ($weeks as $back => [$factor, $daysEarlier, $points]) {
                $start = $week->subWeeks($back);
                $row = $now;
                $row['taken_on'] = $start->addDays(4)->toDateString();
                if ($now['cost_available']) {
                    $row['forecast_cents'] = (int) round($now['forecast_cents'] * $factor);
                    $row['actual_cents'] = (int) round($now['actual_cents'] * (1 - 0.07 * $back));
                    $row['cost_progress_pct'] = max(0, round($now['cost_progress_pct'] - $points, 1));
                    $row['margin_pct'] = $now['sale_cents'] ? round(($now['sale_cents'] - $row['forecast_cents']) * 100 / $now['sale_cents'], 2) : null;
                    $row['cost_alert'] = $now['ceiling_cents'] ? CostReport::alert($row['forecast_cents'], $now['ceiling_cents']) : null;
                }
                if ($now['schedule_available'] && $now['forecast_finish_on']) {
                    $finish = CarbonImmutable::parse($now['forecast_finish_on'])->subDays($daysEarlier);
                    while (! $this->isWorkday($finish)) {
                        $finish = $finish->subDay();
                    }
                    $row['forecast_finish_on'] = $finish->toDateString();
                    $row['schedule_progress_pct'] = max(0, round($now['schedule_progress_pct'] - $points, 1));
                    $row['planned_progress_pct'] = max(0, round($now['planned_progress_pct'] - 4 * $back, 1));
                    $row['delay_contract_days'] = $now['contract_end_on']
                        ? (int) CarbonImmutable::parse($now['contract_end_on'])->diffInDays(CarbonImmutable::parse($row['forecast_finish_on']), false) : null;
                }
                ForecastSnapshot::query()->create($row + ['project_id' => $p->id, 'week_start' => $start->toDateString()]);
            }
        }

        app(ForecastWatch::class)->run($this->tenant, $this->today, email: false);
    }

    private function at(CarbonInterface $when): void
    {
        if (isset($this->realNow) && $when->greaterThan($this->realNow)) {
            $when = $this->realNow;
        }
        Carbon::setTestNow($when);
        CarbonImmutable::setTestNow($when);
    }

    private function isWorkday(CarbonImmutable $date): bool
    {
        return ! $date->isWeekend() && ! isset($this->holidays[$date->toDateString()]);
    }

    /** O n-ésimo dia útil a contar de $start (0 = o próprio, se for útil). */
    private function workday(CarbonImmutable $start, int $n): CarbonImmutable
    {
        $date = $start->startOfDay();
        while (! $this->isWorkday($date)) {
            $date = $date->addDay();
        }
        for ($i = 0; $i < $n; $i++) {
            do {
                $date = $date->addDay();
            } while (! $this->isWorkday($date));
        }

        return $date;
    }

    /** Os últimos $n dias úteis até hoje (inclusive), do mais antigo para o mais recente. @return list<CarbonImmutable> */
    private function lastWorkdays(int $n): array
    {
        $out = [];
        $date = $this->today;
        while (count($out) < $n) {
            if ($this->isWorkday($date)) {
                $out[] = $date;
            }
            $date = $date->subDay();
        }

        return array_reverse($out);
    }

    private function nextWorkday(): CarbonImmutable
    {
        $date = $this->today->addDay();
        while (! $this->isWorkday($date)) {
            $date = $date->addDay();
        }

        return $date;
    }

    /** Fotografia gerada (céu, terreno, estrutura e legenda): não há fotografias reais na demonstração. */
    private function photo(string $label): UploadedFile
    {
        $seed = $this->photoSeed++;
        mt_srand($seed * 7919);
        $w = 1280;
        $h = 960;
        $img = imagecreatetruecolor($w, $h);
        $horizon = (int) ($h * 0.58);
        for ($y = 0; $y < $horizon; $y++) {
            $t = $y / $horizon;
            imageline($img, 0, $y, $w, $y, imagecolorallocate($img, (int) (105 + 70 * $t), (int) (160 + 55 * $t), (int) (220 + 25 * $t)));
        }
        imagefilledrectangle($img, 0, $horizon, $w, $h, imagecolorallocate($img, 140 + mt_rand(-15, 15), 118 + mt_rand(-10, 10), 92));
        $concrete = imagecolorallocate($img, 190, 188, 182);
        $shadow = imagecolorallocate($img, 118, 116, 110);
        $floors = mt_rand(1, 3);
        $x0 = mt_rand(120, 360);
        $x1 = $x0 + mt_rand(560, 760);
        $base = $horizon + 40;
        for ($f = 0; $f <= $floors; $f++) {
            $y = $base - $f * 165;
            imagefilledrectangle($img, $x0 - 25, $y - 20, $x1 + 25, $y, $concrete);
        }
        for ($x = $x0; $x <= $x1; $x += 150) {
            imagefilledrectangle($img, $x, $base - $floors * 165, $x + 24, $base, $shadow);
        }
        if ($seed % 2 === 1) {
            $yellow = imagecolorallocate($img, 232, 178, 32);
            $cx = min($w - 60, $x1 + 110);
            imagefilledrectangle($img, $cx, 110, $cx + 18, $base, $yellow);
            imagefilledrectangle($img, $cx - 440, 100, $cx + 150, 118, $yellow);
            imageline($img, $cx - 300, 118, $cx - 300, 300, $shadow);
        }
        $orange = imagecolorallocate($img, 240, 120, 30);
        for ($i = 0; $i < 4; $i++) {
            $px = mt_rand(40, $w - 80);
            imagefilledpolygon($img, [$px, $h - 120, $px + 16, $h - 160, $px + 32, $h - 120], $orange);
        }
        imagefilledrectangle($img, 0, $h - 64, $w, $h, imagecolorallocatealpha($img, 0, 0, 0, 45));
        imagestring($img, 5, 24, $h - 44, Str::ascii($label).'  (imagem ilustrativa)', imagecolorallocate($img, 255, 255, 255));
        $path = $this->tempPath('jpg');
        imagejpeg($img, $path, 82);
        imagedestroy($img);

        return new UploadedFile($path, Str::slug(Str::limit($label, 60, '')).'.jpg', 'image/jpeg', null, true);
    }

    /** @param  list<string>  $paragraphs */
    private function pdf(string $title, Project $p, array $paragraphs, int $version): UploadedFile
    {
        $body = implode('', array_map(fn (string $t) => '<p>'.e($t).'</p>', $paragraphs));
        $html = '<html><head><meta charset="utf-8"><style>
            body{font-family:"DejaVu Sans",sans-serif;font-size:10.5pt;color:#1e293b;margin:30px}
            .top{font-size:8.5pt;color:#64748b;border-bottom:1px solid #cbd5e1;padding-bottom:6px}
            h1{font-size:17pt;color:#0f766e;margin:18px 0 4px} .meta{color:#475569;margin-bottom:16px}
            table{border-collapse:collapse;width:100%;margin-top:18px} td{border:1px solid #cbd5e1;padding:6px;font-size:9pt}
            .stamp{margin-top:40px;font-size:8.5pt;color:#b91c1c;border:1px dashed #b91c1c;padding:8px;text-align:center}
            </style></head><body>'
            .'<div class="top">Atlântico Construções, Lda. · '.e($p->code).' · '.e($p->name).'</div>'
            .'<h1>'.e($title).'</h1><div class="meta">Revisão '.chr(64 + $version).' · '.e(now()->format('d/m/Y')).'</div>'
            .$body
            .'<table><tr><td>Dono de obra</td><td>'.e((string) $p->owner_name).'</td></tr><tr><td>Local</td><td>'.e(trim($p->address.', '.$p->city, ', ')).'</td></tr></table>'
            .'<div class="stamp">Documento fictício, gerado para a demonstração do ObraConnect.</div>'
            .'</body></html>';

        $dir = storage_path('app/dompdf');
        File::ensureDirectoryExists($dir);
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setTempDir($dir);
        $options->setFontCache($dir);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $path = $this->tempPath('pdf');
        file_put_contents($path, $pdf->output());

        return new UploadedFile($path, Str::slug($title).'-rev-'.chr(96 + $version).'.pdf', 'application/pdf', null, true);
    }

    private function tempPath(string $extension): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oc-demo-'.Str::random(12).'.'.$extension;

        return $this->tempFiles[] = $path;
    }
}
