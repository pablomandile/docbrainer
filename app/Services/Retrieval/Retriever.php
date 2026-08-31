<?php

namespace App\Services\Retrieval;

use App\Enums\DocumentStatus;
use App\Models\DocumentChunk;
use App\Models\Project;
use App\Services\AI\ProviderManager;
use App\Services\Vector\VectorHit;
use App\Services\Vector\VectorStore;
use Illuminate\Support\Facades\DB;

/**
 * Recupera los chunks más relevantes de un proyecto para una consulta.
 *
 * Búsqueda HÍBRIDA:
 *  - Vectorial (semántica): encuentra por significado.
 *  - FULLTEXT booleano de MySQL (léxica): encuentra términos exactos raros
 *    (códigos, nombres propios) que el vector puro puede perder.
 *
 * Multi-espacio: si el proyecto tiene documentos indexados con proveedores
 * distintos (Gemini / GPU local), la consulta se vectoriza con CADA proveedor.
 * Los rankings (espacios vectoriales + léxico) se fusionan con RRF — los
 * scores de fuentes distintas no son comparables entre sí, los rankings sí.
 */
class Retriever
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly VectorStore $store,
    ) {}

    /**
     * @return list<RetrievedChunk> hasta $k chunks, numerados [1..k]
     */
    public function retrieve(Project $project, string $query, ?int $documentId = null): array
    {
        $k = (int) config('docbrainer.retrieval.top_k');

        $providerNames = $project->documents()
            ->where('status', DocumentStatus::Ready)
            ->distinct()
            ->pluck('embedding_provider')
            ->all();

        if ($providerNames === []) {
            return [];
        }

        /** @var array<string, list<VectorHit>> $resultsBySpace */
        $resultsBySpace = [];

        foreach ($providerNames as $name) {
            $provider = $this->providers->embedding($name);
            $queryVector = $provider->embedForQuery($query);

            $hits = $this->store->search(
                projectId: $project->id,
                queryVector: $queryVector,
                modelId: $provider->modelId(),
                k: $k,
                documentId: $documentId,
            );

            if ($hits !== []) {
                $resultsBySpace[$name] = $hits;
            }
        }

        if ($resultsBySpace === []) {
            return [];
        }

        // Ranking léxico (solo MySQL/MariaDB; en otros drivers se omite)
        $lexicalRanking = $this->fullTextRanking($project, $query, $k, $documentId);

        if (count($resultsBySpace) === 1 && $lexicalRanking === []) {
            $ranked = $this->scoresFromSingleSpace(reset($resultsBySpace));
        } else {
            $rankings = array_map(
                fn (array $hits) => array_map(fn ($hit) => $hit->chunkId, $hits),
                array_values($resultsBySpace),
            );

            if ($lexicalRanking !== []) {
                $rankings[] = $lexicalRanking;
            }

            $ranked = $this->fuseWithRrf($rankings);
        }

        arsort($ranked);
        $topIds = array_slice(array_keys($ranked), 0, $k);

        // Hidratar y numerar [1..k] preservando el orden del ranking
        $chunks = DocumentChunk::with('document:id,title')
            ->whereIn('id', $topIds)
            ->get()
            ->keyBy('id');

        $retrieved = [];
        $n = 1;

        foreach ($topIds as $id) {
            if ($chunks->has($id)) {
                $retrieved[] = new RetrievedChunk(n: $n++, chunk: $chunks[$id], score: $ranked[$id]);
            }
        }

        return $retrieved;
    }

    /**
     * @param  list<VectorHit>  $hits
     * @return array<int, float> chunk_id => score
     */
    private function scoresFromSingleSpace(array $hits): array
    {
        $scores = [];

        foreach ($hits as $hit) {
            $scores[$hit->chunkId] = $hit->score;
        }

        return $scores;
    }

    /**
     * Ranking léxico con MATCH ... AGAINST en modo booleano (MySQL/MariaDB).
     *
     * @return list<int> ids de chunks ordenados por relevancia
     */
    private function fullTextRanking(Project $project, string $query, int $k, ?int $documentId): array
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return [];
        }

        // Sanitizar operadores booleanos de FULLTEXT y armar términos
        $clean = preg_replace('/[+\-<>~*"()@]/u', ' ', $query) ?? '';

        $terms = collect(preg_split('/\s+/u', $clean) ?: [])
            ->filter(fn (string $w) => mb_strlen($w) >= 3)
            ->take(12)
            ->implode(' ');

        if ($terms === '') {
            return [];
        }

        $builder = DocumentChunk::query()
            ->where('project_id', $project->id)
            ->whereNotNull('embedded_at')
            ->whereFullText('content', $terms, ['mode' => 'boolean'])
            ->orderByRaw(
                'MATCH (content) AGAINST (? IN BOOLEAN MODE) DESC',
                [$terms],
            )
            ->limit($k);

        if ($documentId !== null) {
            $builder->where('document_id', $documentId);
        }

        return $builder->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Reciprocal Rank Fusion: score(d) = Σ 1 / (k + rank_i(d)).
     *
     * @param  list<list<int>>  $rankings  listas de chunk_ids ordenadas por relevancia
     * @return array<int, float> chunk_id => score RRF
     */
    private function fuseWithRrf(array $rankings): array
    {
        $rrfK = (int) config('docbrainer.retrieval.rrf_k');
        $scores = [];

        foreach ($rankings as $ranking) {
            foreach ($ranking as $rank => $chunkId) {
                $scores[$chunkId] = ($scores[$chunkId] ?? 0.0) + 1 / ($rrfK + $rank + 1);
            }
        }

        return $scores;
    }
}
