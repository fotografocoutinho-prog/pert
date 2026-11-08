<?php

namespace App\Economics;

use App\Models\Budget;
use App\Models\BudgetChapter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Importa capítulos e artigos de uma folha Excel (.xlsx) ou CSV para um orçamento em rascunho.
 *
 * Primeira linha com os títulos (por esta ordem ou outra): Código, Descrição, Unidade, Quantidade,
 * Custo unitário (em euros). Uma linha sem unidade é um capítulo; o número de pontos do código diz a
 * que capítulo pertence (1 → capítulo; 1.1 → subcapítulo de 1). Os artigos ficam no último capítulo
 * cujo código seja o início do seu (ou no último capítulo lido).
 */
final class BudgetImporter
{
    private const MAX_ROWS = 5000;

    private const HEADERS = [
        'code' => ['codigo', 'cod', 'art', 'artigo', 'n', 'numero'],
        'description' => ['descricao', 'designacao', 'descricao do trabalho', 'trabalho'],
        'unit' => ['unidade', 'un', 'und', 'unid'],
        'quantity' => ['quantidade', 'qt', 'qtd', 'quant'],
        'unit_cost' => ['custo unitario', 'preco unitario', 'preco', 'custo', 'p unitario', 'pu'],
    ];

    /** @return array{chapters: int, items: int} */
    public function import(Budget $budget, string $path, string $extension): array
    {
        $rows = $this->rows($path, $extension);
        $header = array_shift($rows) ?? [];
        $map = $this->map($header);
        foreach (['description', 'unit', 'quantity'] as $required) {
            if (! isset($map[$required])) {
                throw ValidationException::withMessages(['file' => 'Faltam colunas: a primeira linha tem de ter Código, Descrição, Unidade, Quantidade e Custo unitário.']);
            }
        }

        $chapters = [];
        $last = null;
        $counts = ['chapters' => 0, 'items' => 0];
        $order = (int) $budget->chapters()->max('sort_order');
        $itemOrder = (int) $budget->items()->max('sort_order');

        foreach ($rows as $n => $row) {
            $get = fn (string $key) => isset($map[$key]) ? trim((string) ($row[$map[$key]] ?? '')) : '';
            $code = $get('code');
            $description = $get('description');
            if ($description === '' && $code === '') {
                continue;
            }
            $unit = $get('unit');

            if ($unit === '') {
                // Capítulo: o pai é o capítulo com o código sem o último segmento.
                $parentCode = str_contains($code, '.') ? Str::beforeLast($code, '.') : null;
                $chapter = $budget->chapters()->create([
                    'parent_id' => $parentCode !== null ? ($chapters[$parentCode]->id ?? null) : null,
                    'code' => $code !== '' ? $code : (string) (count($chapters) + 1),
                    'name' => Str::limit($description !== '' ? $description : $code, 200, ''),
                    'sort_order' => ++$order,
                ]);
                $chapters[$chapter->code] = $chapter;
                $last = $chapter;
                $counts['chapters']++;

                continue;
            }

            $chapter = $this->chapterFor($code, $chapters) ?? $last;
            if ($chapter === null) {
                $chapter = $last = $budget->chapters()->create(['code' => '1', 'name' => 'Geral', 'sort_order' => ++$order]);
                $chapters['1'] = $chapter;
                $counts['chapters']++;
            }

            $quantity = $this->number($get('quantity'), $n + 2, 'quantidade');
            $cost = $this->number($get('unit_cost'), $n + 2, 'custo unitário');
            $budget->items()->create([
                'chapter_id' => $chapter->id,
                'code' => Str::limit($code !== '' ? $code : $chapter->code.'.'.($counts['items'] + 1), 30, ''),
                'description' => Str::limit($description, 1000, ''),
                'unit' => Str::limit($unit, 20, ''),
                'quantity' => $quantity,
                'unit_cost_cents' => (int) round($cost * 100),
                'sort_order' => ++$itemOrder,
            ]);
            $counts['items']++;
        }

        if ($counts['items'] === 0) {
            throw ValidationException::withMessages(['file' => 'Não encontrei artigos (linhas com unidade) no ficheiro.']);
        }

        return $counts;
    }

    /** @return list<list<mixed>> */
    private function rows(string $path, string $extension): array
    {
        if ($extension === 'csv' || $extension === 'txt') {
            // O Excel português grava CSV com ";".
            $first = (string) fgets(fopen($path, 'r'));
            $options = new CsvOptions;
            $options->FIELD_DELIMITER = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
            $reader = new CsvReader($options);
        } else {
            $reader = new XlsxReader;
        }

        $rows = [];
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
                if (count($rows) > self::MAX_ROWS) {
                    throw ValidationException::withMessages(['file' => 'O ficheiro tem mais de '.self::MAX_ROWS.' linhas.']);
                }
            }
            break; // só a primeira folha
        }
        $reader->close();

        return $rows;
    }

    /** @param  list<mixed>  $header @return array<string, int> */
    private function map(array $header): array
    {
        $map = [];
        foreach ($header as $i => $title) {
            $norm = trim(preg_replace('/[^a-z ]+/', ' ', Str::lower(Str::ascii((string) $title))));
            $norm = preg_replace('/\s+/', ' ', $norm);
            foreach (self::HEADERS as $key => $names) {
                if (! isset($map[$key]) && in_array($norm, $names, true)) {
                    $map[$key] = $i;
                }
            }
        }

        return $map;
    }

    /** @param  array<string, BudgetChapter>  $chapters */
    private function chapterFor(string $code, array $chapters): ?BudgetChapter
    {
        $best = null;
        foreach ($chapters as $chapterCode => $chapter) {
            if ($code !== '' && str_starts_with($code, $chapterCode.'.') && ($best === null || strlen($chapterCode) > strlen($best->code))) {
                $best = $chapter;
            }
        }

        return $best;
    }

    /** "1.234,56", "1234,56", "1234.56" ou número do Excel. */
    private function number(mixed $value, int $line, string $field): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $text = preg_replace('/[\s€]/u', '', (string) $value);
        if ($text === '') {
            return 0.0;
        }
        if (str_contains($text, ',')) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        }
        if (! is_numeric($text)) {
            throw ValidationException::withMessages(['file' => "Linha {$line}: a {$field} «{$value}» não é um número."]);
        }

        return (float) $text;
    }
}
