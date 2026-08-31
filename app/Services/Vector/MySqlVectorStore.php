<?php

namespace App\Services\Vector;

use Illuminate\Support\Facades\DB;

/**
 * Vectores como BLOB en `document_chunks` + similitud coseno en PHP.
 *
 * Los vectores se guardan normalizados L2, así el coseno se reduce a un
 * producto punto. Barrer unos miles de vectores de 768 dims toma pocos
 * milisegundos; el cursor evita cargar todo en memoria.
 */
class MySqlVectorStore implements VectorStore
{
    public function store(int $chunkId, array $vector, string $modelId, int $dimensions): void
    {
        DB::table('document_chunks')
            ->where('id', $chunkId)
            ->update([
                'embedding' => VectorMath::pack($vector),
                'embedding_model' => $modelId,
                'embedding_dimensions' => $dimensions,
                'embedded_at' => now(),
            ]);
    }

    public function search(int $projectId, array $queryVector, string $modelId, int $k, ?int $documentId = null): array
    {
        // Min-heap acotado: mantiene los k mejores sin ordenar todo
        $heap = new \SplMinHeap;

        $query = DB::table('document_chunks')
            ->select(['id', 'embedding'])
            ->where('project_id', $projectId)
            ->where('embedding_model', $modelId)
            ->whereNotNull('embedding');

        if ($documentId !== null) {
            $query->where('document_id', $documentId);
        }

        foreach ($query->cursor() as $row) {
            $score = VectorMath::dot($queryVector, VectorMath::unpack($row->embedding));

            if ($heap->count() < $k) {
                $heap->insert([$score, $row->id]);
            } elseif ($score > $heap->top()[0]) {
                $heap->extract();
                $heap->insert([$score, $row->id]);
            }
        }

        $hits = [];

        while (! $heap->isEmpty()) {
            [$score, $id] = $heap->extract();
            $hits[] = new VectorHit(chunkId: (int) $id, score: (float) $score);
        }

        // El heap extrae de menor a mayor → invertir para score descendente
        return array_reverse($hits);
    }
}
