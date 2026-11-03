<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\Auditor;
use App\Enums\DiaryEntryStatus;
use App\Enums\DiaryItemKind;
use App\Http\Controllers\Api\V1\Concerns\GuardsDiaryWrites;
use App\Http\Controllers\Controller;
use App\Http\Resources\DiaryEntryResource;
use App\Media\MediaStorage;
use App\Models\DiaryEntry;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Diário de obra: um registo por dia. Quem tem diary.write cria e altera rascunhos;
 * quem tem diary.submit fecha-os. Um registo submetido não volta a ser alterado.
 */
class DiaryEntryController extends Controller
{
    use GuardsDiaryWrites;

    private const RELATIONS = ['items', 'photos.uploadedBy:id,name', 'createdBy:id,name', 'updatedBy:id,name', 'submittedBy:id,name'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly MediaStorage $media,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorize('diary.view', $project);

        $month = preg_match('/^(\d{4})-(\d{2})$/', (string) $request->query('month'), $m) ? $m : null;

        $entries = $project->diaryEntries()
            ->with(['createdBy:id,name', 'submittedBy:id,name'])
            ->withCount(['items', 'photos'])
            ->withSum(['items as workforce_total' => fn ($q) => $q->where('kind', DiaryItemKind::Workforce->value)], 'quantity')
            ->when($month, fn ($q) => $q->whereYear('entry_date', (int) $month[1])->whereMonth('entry_date', (int) $month[2]))
            ->orderByDesc('entry_date')
            ->paginate(min((int) $request->query('per_page', 31), 100));

        return DiaryEntryResource::collection($entries);
    }

    public function store(Request $request, Project $project): DiaryEntryResource
    {
        $this->authorize('diary.write', $project);
        $this->assertProjectOpen($project);

        $data = $this->validated($request, creating: true);
        $this->assertDateIsFree($project, $data['entry_date']);

        $entry = DB::transaction(function () use ($project, $data, $request): DiaryEntry {
            $entry = $project->diaryEntries()->make($this->entryFields($data));
            $entry->created_by_user_id = $request->user()->id;
            $entry->updated_by_user_id = $request->user()->id;
            $entry->save();

            $this->syncItems($entry, $data['items'] ?? []);

            return $entry;
        });

        $this->auditor->record('diary.created', $entry, ['project' => $project->code, 'date' => $data['entry_date']]);

        return new DiaryEntryResource($entry->load(self::RELATIONS));
    }

    public function show(Project $project, DiaryEntry $diaryEntry): DiaryEntryResource
    {
        $this->authorize('diary.view', $project);

        return new DiaryEntryResource($diaryEntry->load(self::RELATIONS));
    }

    public function update(Request $request, Project $project, DiaryEntry $diaryEntry): DiaryEntryResource
    {
        $this->authorize('diary.write', $project);
        $this->assertProjectOpen($project);
        $this->assertDraft($diaryEntry);

        $data = $this->validated($request, creating: false);

        DB::transaction(function () use ($diaryEntry, $data, $request): void {
            $diaryEntry->fill($this->entryFields($data));
            $diaryEntry->updated_by_user_id = $request->user()->id;
            $diaryEntry->save();

            if (array_key_exists('items', $data)) {
                $this->syncItems($diaryEntry, $data['items'] ?? []);
            }
        });

        $this->auditor->record('diary.updated', $diaryEntry, [
            'project' => $project->code,
            'date' => $diaryEntry->entry_date->toDateString(),
        ]);

        return new DiaryEntryResource($diaryEntry->load(self::RELATIONS));
    }

    public function destroy(Project $project, DiaryEntry $diaryEntry): Response
    {
        $this->authorize('diary.write', $project);
        $this->assertProjectOpen($project);
        $this->assertDraft($diaryEntry);

        $date = $diaryEntry->entry_date->toDateString();
        // Os ficheiros não seguem a cascata da base de dados: apagam-se primeiro.
        $diaryEntry->photos->each(fn ($photo) => $this->media->delete($photo));
        $diaryEntry->delete();

        $this->auditor->record('diary.deleted', $project, ['date' => $date]);

        return response()->noContent();
    }

    public function submit(Request $request, Project $project, DiaryEntry $diaryEntry): DiaryEntryResource
    {
        $this->authorize('diary.submit', $project);
        $this->assertProjectOpen($project);
        $this->assertDraft($diaryEntry);

        $diaryEntry->forceFill([
            'status' => DiaryEntryStatus::Submitted,
            'submitted_at' => now(),
            'submitted_by_user_id' => $request->user()->id,
        ])->save();

        $this->auditor->record('diary.submitted', $diaryEntry, [
            'project' => $project->code,
            'date' => $diaryEntry->entry_date->toDateString(),
        ]);

        return new DiaryEntryResource($diaryEntry->load(self::RELATIONS));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $tenant = $this->tenantContext()->tenantOrFail();
        $today = now($tenant->settings['timezone'] ?? 'Europe/Lisbon')->toDateString();
        $weather = ['nullable', Rule::in(array_keys(DiaryEntry::WEATHER))];

        $data = $request->validate([
            // A data não muda depois de criado: para outro dia, cria-se outro registo.
            'entry_date' => $creating ? ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today] : ['prohibited'],
            'weather' => ['sometimes', 'nullable', 'array'],
            'weather.morning' => $weather,
            'weather.afternoon' => $weather,
            'weather.temp_min' => ['nullable', 'integer', 'between:-30,55'],
            'weather.temp_max' => ['nullable', 'integer', 'between:-30,55'],
            'work_summary' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'items' => ['sometimes', 'nullable', 'array', 'max:200'],
            'items.*.kind' => ['required', Rule::enum(DiaryItemKind::class)],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.company' => ['nullable', 'string', 'max:150'],
        ], [
            'entry_date.before_or_equal' => 'Não é possível registar dias futuros.',
        ], [
            'entry_date' => 'data',
            'work_summary' => 'trabalhos executados',
            'notes' => 'observações',
            'items.*.description' => 'descrição',
            'items.*.quantity' => 'quantidade',
        ]);

        $min = $data['weather']['temp_min'] ?? null;
        $max = $data['weather']['temp_max'] ?? null;
        if ($min !== null && $max !== null && $max < $min) {
            throw ValidationException::withMessages(['weather.temp_max' => 'A temperatura máxima não pode ser inferior à mínima.']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function entryFields(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(['entry_date', 'work_summary', 'notes']));

        if (array_key_exists('weather', $data)) {
            // Só as chaves conhecidas; vazio fica nulo.
            $weather = array_filter(
                array_intersect_key($data['weather'] ?? [], array_flip(['morning', 'afternoon', 'temp_min', 'temp_max'])),
                fn ($v) => $v !== null && $v !== '',
            );
            $fields['weather'] = $weather ?: null;
        }

        return $fields;
    }

    /** @param  list<array<string, mixed>>  $items */
    private function syncItems(DiaryEntry $entry, array $items): void
    {
        $entry->items()->delete();

        foreach (array_values($items) as $i => $item) {
            $entry->items()->create([
                'kind' => $item['kind'],
                'description' => $item['description'],
                'quantity' => $item['quantity'] ?? null,
                'unit' => $item['unit'] ?? null,
                'company' => $item['company'] ?? null,
                'sort_order' => $i,
            ]);
        }
    }

    /** whereDate funciona igual em MySQL e SQLite (onde a data fica guardada com hora). */
    private function assertDateIsFree(Project $project, string $date): void
    {
        if ($project->diaryEntries()->whereDate('entry_date', $date)->exists()) {
            throw ValidationException::withMessages(['entry_date' => 'Já existe um registo do diário para este dia.']);
        }
    }
}
