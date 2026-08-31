<?php

namespace App\Services\Summary;

use App\Enums\DocumentStatus;
use App\Models\DocumentChunk;
use App\Models\Summary;

/**
 * Construye el árbol map-reduce de un resumen en `summary_nodes`.
 *
 * Nivel 0 ("map"): unidades de hasta ~3000 tokens de texto fuente, armadas
 * agrupando chunks consecutivos y respetando límites de documento.
 * Niveles siguientes ("reduce"): agrupan ≤10 nodos hasta un único nodo raíz.
 *
 * El árbol persistido hace el proceso reanudable: cada nodo se resuelve con
 * una llamada al modelo y guarda su resultado.
 */
class SummaryPlanner
{
    /**
     * @return int cantidad total de nodos creados (= progress_total)
     */
    public function plan(Summary $summary): int
    {
        $mapUnits = $this->buildMapUnits($summary);

        if ($mapUnits === []) {
            return 0;
        }

        $nodeIndex = 0;
        $levelZeroIds = [];

        foreach ($mapUnits as $unit) {
            $node = $summary->nodes()->create([
                'level' => 0,
                'node_index' => $nodeIndex++,
                'input_ref' => [
                    'label' => $unit['label'],
                    'chunk_ids' => $unit['chunk_ids'],
                ],
            ]);

            $levelZeroIds[] = $node->id;
        }

        $total = count($levelZeroIds);

        // Niveles reduce: agrupar de a ≤ fan_in hasta que quede un solo nodo
        $fanIn = max(2, (int) config('docbrainer.summary.reduce_fan_in'));
        $currentLevelIds = $levelZeroIds;
        $level = 0;

        while (count($currentLevelIds) > 1) {
            $level++;
            $nextLevelIds = [];
            $nodeIndex = 0;

            foreach (array_chunk($currentLevelIds, $fanIn) as $group) {
                $node = $summary->nodes()->create([
                    'level' => $level,
                    'node_index' => $nodeIndex++,
                    'input_ref' => ['node_ids' => $group],
                ]);

                $nextLevelIds[] = $node->id;
                $total++;
            }

            $currentLevelIds = $nextLevelIds;
        }

        return $total;
    }

    /**
     * Unidades map: chunks consecutivos agrupados hasta ~map_unit_tokens,
     * sin cruzar límites de documento. Etiquetadas con documento + secciones.
     *
     * @return list<array{label: string, chunk_ids: list<int>}>
     */
    private function buildMapUnits(Summary $summary): array
    {
        $maxTokens = (int) config('docbrainer.summary.map_unit_tokens');

        $query = DocumentChunk::query()
            ->join('documents', 'documents.id', '=', 'document_chunks.document_id')
            ->where('document_chunks.project_id', $summary->project_id)
            ->where('documents.status', DocumentStatus::Ready->value)
            ->orderBy('document_chunks.document_id')
            ->orderBy('document_chunks.chunk_index')
            ->select([
                'document_chunks.id',
                'document_chunks.document_id',
                'document_chunks.section_path',
                'document_chunks.token_estimate',
                'documents.title as document_title',
            ]);

        if ($summary->document_id !== null) {
            $query->where('document_chunks.document_id', $summary->document_id);
        }

        $units = [];
        $current = null;

        foreach ($query->cursor() as $chunk) {
            $startsNewUnit = $current === null
                || $current['document_id'] !== $chunk->document_id
                || $maxTokens < $current['tokens'] + $chunk->token_estimate;

            if ($startsNewUnit) {
                if ($current !== null) {
                    $units[] = $this->finishUnit($current);
                }

                $current = [
                    'document_id' => $chunk->document_id,
                    'document_title' => $chunk->document_title,
                    'sections' => [],
                    'chunk_ids' => [],
                    'tokens' => 0,
                ];
            }

            $current['chunk_ids'][] = $chunk->id;
            $current['tokens'] += $chunk->token_estimate;

            if ($chunk->section_path !== null) {
                $current['sections'][$chunk->section_path] = true;
            }
        }

        if ($current !== null) {
            $units[] = $this->finishUnit($current);
        }

        return $units;
    }

    private function finishUnit(array $unit): array
    {
        $sections = array_keys($unit['sections']);

        $label = $unit['document_title'];

        if ($sections !== []) {
            $label .= ' — '.implode(' / ', array_slice($sections, 0, 3));

            if (count($sections) > 3) {
                $label .= ' …';
            }
        }

        return [
            'label' => mb_substr($label, 0, 250),
            'chunk_ids' => $unit['chunk_ids'],
        ];
    }
}
