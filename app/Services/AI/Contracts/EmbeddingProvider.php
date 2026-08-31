<?php

namespace App\Services\AI\Contracts;

/**
 * Proveedor de embeddings (vectorización).
 *
 * Cada implementación encapsula SU formato de prompt/prefijo:
 * los espacios vectoriales de modelos distintos son incompatibles,
 * por eso modelId() y dimensions() se guardan junto a cada vector.
 */
interface EmbeddingProvider
{
    /**
     * Vectoriza fragmentos para INDEXAR (lado documento).
     *
     * @param  list<array{title: string, text: string}>  $items  título contextual + texto del chunk
     * @return list<list<float>> vectores L2-normalizados, en el mismo orden
     */
    public function embedForIndex(array $items): array;

    /**
     * Vectoriza una consulta para BUSCAR (lado pregunta).
     *
     * @return list<float> vector L2-normalizado
     */
    public function embedForQuery(string $query): array;

    /**
     * Identificador de modelo que se persiste junto a cada vector.
     */
    public function modelId(): string;

    public function dimensions(): int;

    /**
     * Cantidad máxima de items por llamada de indexación.
     */
    public function batchSize(): int;
}
