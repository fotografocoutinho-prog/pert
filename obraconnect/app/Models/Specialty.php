<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Especialidade (Arquitetura, Estruturas, AVAC...). Classifica elementos construtivos, documentos,
 * listas de verificação e inspeções; mais tarde, subempreiteiros. Com ligações não se apaga: arquiva-se.
 */
#[Fillable(['code', 'name', 'sort_order'])]
class Specialty extends Model
{
    use BelongsToTenant;

    /**
     * Lista habitual em obras de edifícios em Portugal. É só um ponto de partida: cada empresa
     * edita, arquiva ou junta as suas.
     */
    public const DEFAULTS = [
        'ARQ' => 'Arquitetura',
        'EST' => 'Estruturas',
        'AGU' => 'Águas e esgotos',
        'PLU' => 'Águas pluviais',
        'ELE' => 'Instalações elétricas',
        'ITED' => 'Telecomunicações (ITED)',
        'GAS' => 'Gás',
        'AVAC' => 'AVAC (climatização e ventilação)',
        'TER' => 'Comportamento térmico',
        'ACU' => 'Acústica',
        'SCIE' => 'Segurança contra incêndio',
        'ELV' => 'Elevadores',
        'ARR' => 'Arranjos exteriores',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    public function elements(): HasMany
    {
        return $this->hasMany(ConstructionElement::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function checklistTemplates(): HasMany
    {
        return $this->hasMany(ChecklistTemplate::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isReferenced(): bool
    {
        return $this->elements()->exists() || $this->documents()->exists()
            || $this->checklistTemplates()->exists() || $this->inspections()->exists() || $this->issues()->exists();
    }

    /**
     * Regras para o campo specialty_id de elementos e documentos. Uma arquivada fica onde já estava,
     * mas não se escolhe de novo.
     *
     * @return list<mixed>
     */
    public static function rules(?int $current = null): array
    {
        return ['sometimes', 'nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail) use ($current): void {
            if ($value === null || (int) $value === $current) {
                return;
            }
            $specialty = self::query()->find((int) $value);
            if ($specialty === null) {
                $fail('A especialidade escolhida não existe.');
            } elseif ($specialty->isArchived()) {
                $fail('Essa especialidade está arquivada.');
            }
        }];
    }

    /** Junta à empresa ativa as da lista habitual que lhe faltam (pelo código). Devolve quantas juntou. */
    public static function addDefaults(): int
    {
        $existing = self::query()->pluck('code')->map(fn (string $c) => mb_strtoupper($c))->all();
        $order = (int) self::query()->max('sort_order');
        $added = 0;

        foreach (self::DEFAULTS as $code => $name) {
            if (! in_array($code, $existing, true)) {
                self::query()->create(['code' => $code, 'name' => $name, 'sort_order' => ++$order]);
                $added++;
            }
        }

        return $added;
    }
}
