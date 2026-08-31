<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Services\Vector\MySqlVectorStore;
use App\Services\Vector\VectorMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VectorStoreTest extends TestCase
{
    use RefreshDatabase;

    private function makeChunk(Document $document, int $index, string $content): int
    {
        return $document->chunks()->create([
            'project_id' => $document->project_id,
            'chunk_index' => $index,
            'content' => $content,
            'token_estimate' => 10,
        ])->id;
    }

    public function test_store_and_search_ranks_by_cosine_similarity(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $document = $project->documents()->create([
            'title' => 'Doc', 'original_name' => 'doc.txt', 'stored_path' => 'x',
            'extension' => 'txt', 'size_bytes' => 1,
        ]);

        $store = new MySqlVectorStore;

        // Tres vectores 4D normalizados: a apunta a X, b a Y, c diagonal XY
        $a = $this->makeChunk($document, 0, 'eje x');
        $b = $this->makeChunk($document, 1, 'eje y');
        $c = $this->makeChunk($document, 2, 'diagonal');

        $store->store($a, VectorMath::normalize([1, 0, 0, 0]), 'test-model', 4);
        $store->store($b, VectorMath::normalize([0, 1, 0, 0]), 'test-model', 4);
        $store->store($c, VectorMath::normalize([1, 1, 0, 0]), 'test-model', 4);

        // Consulta cerca del eje X → a primero, c segundo, b último
        $query = VectorMath::normalize([1, 0.1, 0, 0]);
        $hits = $store->search($project->id, $query, 'test-model', 3);

        $this->assertCount(3, $hits);
        $this->assertSame($a, $hits[0]->chunkId);
        $this->assertSame($c, $hits[1]->chunkId);
        $this->assertSame($b, $hits[2]->chunkId);
        $this->assertGreaterThan($hits[1]->score, $hits[0]->score);
    }

    public function test_search_filters_by_model_and_document(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user)->create();
        $document = $project->documents()->create([
            'title' => 'Doc', 'original_name' => 'doc.txt', 'stored_path' => 'x',
            'extension' => 'txt', 'size_bytes' => 1,
        ]);
        $otherDoc = $project->documents()->create([
            'title' => 'Otro', 'original_name' => 'otro.txt', 'stored_path' => 'y',
            'extension' => 'txt', 'size_bytes' => 1,
        ]);

        $store = new MySqlVectorStore;

        $a = $this->makeChunk($document, 0, 'modelo actual');
        $b = $this->makeChunk($document, 1, 'modelo viejo');
        $c = $this->makeChunk($otherDoc, 0, 'otro documento');

        $vector = VectorMath::normalize([1, 0, 0, 0]);
        $store->store($a, $vector, 'model-nuevo', 4);
        $store->store($b, $vector, 'model-viejo', 4);
        $store->store($c, $vector, 'model-nuevo', 4);

        // Filtra por modelo: el chunk del modelo viejo no aparece
        $hits = $store->search($project->id, $vector, 'model-nuevo', 10);
        $this->assertEqualsCanonicalizing([$a, $c], array_map(fn ($h) => $h->chunkId, $hits));

        // Filtra por documento
        $hits = $store->search($project->id, $vector, 'model-nuevo', 10, documentId: $document->id);
        $this->assertSame([$a], array_map(fn ($h) => $h->chunkId, $hits));
    }

    public function test_pack_unpack_roundtrip_preserves_precision(): void
    {
        $vector = VectorMath::normalize(array_map(fn () => mt_rand(-1000, 1000) / 1000, range(1, 768)));

        $unpacked = VectorMath::unpack(VectorMath::pack($vector));

        $this->assertCount(768, $unpacked);
        // float de 4 bytes: precisión ~1e-7 relativa
        $this->assertEqualsWithDelta(1.0, VectorMath::dot($unpacked, $unpacked), 0.0001);
        $this->assertEqualsWithDelta($vector[0], $unpacked[0], 0.0001);
    }
}
