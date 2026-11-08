<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\PartnerKind;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Concerns\GuardsProjectWrites;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Worker;
use App\Rules\ValidPortugueseNif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Trabalhadores da empresa ou de subempreiteiros, com o custo/hora usado nas horas. O NIF só se
 * mostra a quem gere os trabalhadores. Consultar: workforce.view ou registar horas da equipa;
 * gerir: workforce.manage. Com horas registadas não se apaga: desativa-se.
 */
class WorkerController extends Controller
{
    use GuardsProjectWrites;

    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny(['workforce.view', 'workforce.manage', 'time.register_team', 'time.approve']);
        $manage = Gate::allows('workforce.manage');

        return response()->json(['data' => Worker::query()->with(['partner:id,name', 'user:id,name,email'])->withCount('timeEntries')
            ->orderByDesc('active')->orderBy('name')->get()
            ->map(fn (Worker $w) => $this->present($w, $manage))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('workforce.manage');
        $worker = Worker::query()->create($this->validated($request));
        $this->auditor->record('worker.created', $worker, ['name' => $worker->name]);

        return response()->json(['data' => $this->present($worker->load(['partner:id,name', 'user:id,name,email'])->loadCount('timeEntries'), true)], 201);
    }

    public function update(Request $request, Worker $worker): JsonResponse
    {
        $this->authorize('workforce.manage');
        $worker->fill($this->validated($request, $worker))->save();
        $changes = $this->auditor->changes($worker);
        if ($changes['new'] !== []) {
            unset($changes['old']['nif'], $changes['new']['nif']);
            $this->auditor->record('worker.updated', $worker, $changes);
        }

        return response()->json(['data' => $this->present($worker->load(['partner:id,name', 'user:id,name,email'])->loadCount('timeEntries'), true)]);
    }

    public function destroy(Worker $worker): Response
    {
        $this->authorize('workforce.manage');
        if ($worker->timeEntries()->exists()) {
            throw ApiException::conflict('Este trabalhador tem horas registadas. Desative-o em vez de o apagar.', 'WORKER_IN_USE');
        }
        $worker->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Worker $worker = null): array
    {
        $tenant = $this->tenantContext()->id();
        $members = Membership::query()->pluck('user_id')->all();

        $data = $request->validate([
            'name' => [$worker ? 'sometimes' : 'required', 'string', 'max:150'],
            'nif' => ['sometimes', 'nullable', 'string', new ValidPortugueseNif],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'hourly_cost_cents' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'partner_id' => ['sometimes', 'nullable', 'integer', Rule::exists('partners', 'id')->where('tenant_id', $tenant)->where('kind', PartnerKind::Subcontractor->value)],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::in($members), Rule::unique('workers', 'user_id')->where('tenant_id', $tenant)->ignore($worker?->id)],
            'active' => ['sometimes', 'boolean'],
        ], [
            'user_id.in' => 'Essa pessoa não é utilizadora da empresa.',
            'user_id.unique' => 'Essa pessoa já está ligada a outro trabalhador.',
        ], ['name' => 'nome', 'hourly_cost_cents' => 'custo/hora', 'partner_id' => 'subempreiteiro', 'user_id' => 'utilizador']);
        if (isset($data['nif'])) {
            $data['nif'] = preg_replace('/\D/', '', $data['nif']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Worker $w, bool $manage): array
    {
        return [
            'id' => $w->id,
            'name' => $w->name,
            'nif' => $manage ? $w->nif : null,
            'category' => $w->category,
            'hourly_cost_cents' => $manage || Gate::allows('workforce.view') ? $w->hourly_cost_cents : null,
            'partner_id' => $w->partner_id,
            'partner' => $w->partner?->name,
            'user_id' => $w->user_id,
            'user' => $w->user ? ['name' => $w->user->name, 'email' => $w->user->email] : null,
            'active' => $w->active,
            'time_entries_count' => (int) ($w->time_entries_count ?? 0),
        ];
    }
}
