<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\ClientType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Rules\ValidPortugueseNif;
use App\Support\PortugueseNif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Clientes da empresa. Um cliente com obras não se apaga: arquiva-se (deixa de aparecer
 * para escolher, mas as obras continuam a mostrá-lo).
 */
class ClientController extends Controller
{
    public function __construct(private readonly Auditor $auditor) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('clients.view');

        $search = trim((string) $request->query('search'));
        $like = '%'.addcslashes($search, '%_\\').'%';
        $status = $request->query('status', 'active');

        $clients = Client::query()
            ->withCount('projects')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', $like)
                ->orWhere('nif', 'like', $like)
                ->orWhere('contact_name', 'like', $like)
                ->orWhere('city', 'like', $like)))
            ->when($status === 'active', fn ($q) => $q->whereNull('archived_at'))
            ->when($status === 'archived', fn ($q) => $q->whereNotNull('archived_at'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return ClientResource::collection($clients);
    }

    /** Para escolher o cliente de uma obra: só nome e tipo. Um arquivado só vem se for pedido (o atual da obra). */
    public function options(Request $request): JsonResponse
    {
        abort_unless(Gate::any(['clients.view', 'projects.create', 'projects.update']), 403);

        $include = $request->integer('include');

        return response()->json(['data' => Client::query()
            ->where(fn ($q) => $q->whereNull('archived_at')->when($include, fn ($w) => $w->orWhere('id', $include)))
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'archived_at'])
            ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name, 'type' => $c->type->value, 'archived' => $c->isArchived()])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('clients.manage');

        $client = new Client($this->validated($request));
        $client->created_by_user_id = $request->user()->id;
        $client->save();

        $this->auditor->record('client.created', $client, ['name' => $client->name, 'type' => $client->type->value]);

        return (new ClientResource($client->loadCount('projects')))->response()->setStatusCode(201);
    }

    public function show(Client $client): ClientResource
    {
        $this->authorize('clients.view');

        return new ClientResource($client->loadCount('projects'));
    }

    public function update(Request $request, Client $client): ClientResource
    {
        $this->authorize('clients.manage');

        $data = $this->validated($request, $client);
        $archive = array_key_exists('archived', $data) ? (bool) $data['archived'] : null;
        unset($data['archived']);

        $client->fill($data);
        if ($archive !== null && $archive !== $client->isArchived()) {
            $client->archived_at = $archive ? now() : null;
        }
        $client->save();

        $changes = $this->auditor->changes($client);
        if (array_key_exists('archived_at', $changes['new'])) {
            $this->auditor->record($client->isArchived() ? 'client.archived' : 'client.restored', $client, ['name' => $client->name]);
            unset($changes['new']['archived_at'], $changes['old']['archived_at']);
        }
        if ($changes['new'] !== []) {
            $this->auditor->record('client.updated', $client, $changes);
        }

        return new ClientResource($client->loadCount('projects'));
    }

    public function destroy(Client $client): Response
    {
        $this->authorize('clients.manage');

        if ($client->projects()->exists()) {
            throw ApiException::conflict('Este cliente tem obras. Arquive-o em vez de o apagar.', 'CLIENT_IN_USE');
        }

        $client->delete();
        $this->auditor->record('client.deleted', null, ['id' => $client->id, 'name' => $client->name]);

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Client $client = null): array
    {
        $required = $client ? 'sometimes' : 'required';
        $country = strtoupper((string) $request->input('country_code', $client?->country_code ?? 'PT'));
        $isPortugal = $country === 'PT';

        $data = $request->validate([
            'type' => [$required, Rule::enum(ClientType::class)],
            'name' => [$required, 'string', 'max:150'],
            'nif' => array_filter(['sometimes', 'nullable', 'string', 'max:20', $isPortugal ? new ValidPortugueseNif : 'regex:/^[A-Za-z0-9 .\-]{2,20}$/']),
            'country_code' => ['sometimes', 'string', 'size:2', 'alpha'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9 +()\-]{6,30}$/'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => array_filter(['sometimes', 'nullable', 'string', 'max:20', $isPortugal ? 'regex:/^\d{4}-\d{3}$/' : null]),
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'archived' => $client ? ['sometimes', 'boolean'] : ['prohibited'],
        ], [], [
            'type' => 'tipo de cliente',
            'name' => 'nome',
            'nif' => 'NIF',
            'phone' => 'telefone',
            'contact_name' => 'pessoa de contacto',
            'postal_code' => 'código postal',
            'city' => 'localidade',
        ]);

        if (isset($data['country_code'])) {
            $data['country_code'] = strtoupper($data['country_code']);
        }
        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']);
        }

        if (filled($data['nif'] ?? null)) {
            $data['nif'] = $isPortugal ? PortugueseNif::normalize($data['nif']) : strtoupper(preg_replace('/[\s.\-]+/', '', $data['nif']));
            $other = Client::query()->where('nif', $data['nif'])->when($client, fn ($q) => $q->whereKeyNot($client->id))->first();
            if ($other) {
                throw ValidationException::withMessages(['nif' => "Já existe um cliente com este NIF: {$other->name}."]);
            }
        } elseif (array_key_exists('nif', $data)) {
            $data['nif'] = null;
        }

        return $data;
    }
}
