<?php

namespace App\Services\Vector;

/**
 * Almacén de vectores. La implementación por defecto usa MySQL con
 * fuerza bruta en PHP — suficiente para miles de chunks por proyecto.
 * Esta interfaz es la vía de escape hacia pgvector/Qdrant si algún
 * proyecto crece más allá de ~50k chunks.
 */
interface VectorStore
{
    /**
     * Persiste el vector de un chunk (normalizado L2).
     *
     * @param  list<float>  $vector
     */
    public function store(int $chunkId, array $vector, string $modelId, int $dimensions): void;

    /**
     * Busca los $k chunks más similares a la consulta dentro de un proyecto,
     * SOLO entre vectores del mismo modelo (espacios incompatibles entre sí).
     *
     * @param  list<float>  $queryVector  vector normalizado L2
     * @return list<VectorHit> ordenados por score descendente
     */
    public function search(int $projectId, array $queryVector, string $modelId, int $k, ?int $documentId = null): array;
}
