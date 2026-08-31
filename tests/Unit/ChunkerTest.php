<?php

namespace Tests\Unit;

use App\Services\Chunking\Chunker;
use App\Services\Extraction\ExtractedDocument;
use App\Services\Extraction\ExtractedSection;
use Tests\TestCase;

class ChunkerTest extends TestCase
{
    private function makeChunker(): Chunker
    {
        return new Chunker;
    }

    public function test_small_section_becomes_single_chunk(): void
    {
        $doc = new ExtractedDocument(sections: [
            new ExtractedSection(title: 'Intro', level: 1, text: 'Texto corto.', pageStart: 1, pageEnd: 1),
        ]);

        $chunks = $this->makeChunker()->chunk($doc);

        $this->assertCount(1, $chunks);
        $this->assertSame('Texto corto.', $chunks[0]['content']);
        $this->assertSame('Intro', $chunks[0]['section_title']);
        $this->assertSame('Intro', $chunks[0]['section_path']);
        $this->assertSame(1, $chunks[0]['page_start']);
    }

    public function test_long_section_is_split_with_overlap(): void
    {
        // ~200 párrafos de ~30 tokens → varios chunks de ~450 tokens
        $paragraphs = [];
        for ($i = 0; $i < 200; $i++) {
            $paragraphs[] = "Párrafo número {$i} con contenido de relleno para alcanzar el tamaño objetivo del fragmento.";
        }

        $doc = new ExtractedDocument(sections: [
            new ExtractedSection(title: 'Capítulo 1', level: 1, text: implode("\n\n", $paragraphs)),
        ]);

        $chunks = $this->makeChunker()->chunk($doc);

        $this->assertGreaterThan(5, count($chunks));

        $target = (int) config('docbrainer.chunking.target_tokens');

        foreach ($chunks as $chunk) {
            // Ningún chunk excede el objetivo por más del margen de una pieza
            $this->assertLessThanOrEqual($target * 1.3, $chunk['token_estimate']);
        }

        // Solapamiento: el último párrafo del chunk N aparece al inicio del chunk N+1
        $firstChunkParagraphs = explode("\n\n", $chunks[0]['content']);
        $lastParagraph = end($firstChunkParagraphs);
        $this->assertStringContainsString($lastParagraph, $chunks[1]['content']);

        // Índices consecutivos
        $this->assertSame(range(0, count($chunks) - 1), array_column($chunks, 'chunk_index'));
    }

    public function test_breadcrumb_follows_section_hierarchy(): void
    {
        $doc = new ExtractedDocument(sections: [
            new ExtractedSection(title: 'Capítulo 1', level: 1, text: 'Texto del capítulo.'),
            new ExtractedSection(title: '1.1 Detalle', level: 2, text: 'Texto de la subsección.'),
            new ExtractedSection(title: 'Capítulo 2', level: 1, text: 'Otro capítulo.'),
        ]);

        $chunks = $this->makeChunker()->chunk($doc);

        $this->assertSame('Capítulo 1', $chunks[0]['section_path']);
        $this->assertSame('Capítulo 1 > 1.1 Detalle', $chunks[1]['section_path']);
        // Un nivel 1 nuevo resetea el breadcrumb
        $this->assertSame('Capítulo 2', $chunks[2]['section_path']);
    }

    public function test_text_without_punctuation_gets_hard_split(): void
    {
        // 20.000 caracteres sin puntuación ni párrafos
        $doc = new ExtractedDocument(sections: [
            new ExtractedSection(title: null, level: 1, text: str_repeat('palabra ', 2500)),
        ]);

        $chunks = $this->makeChunker()->chunk($doc);

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(500, $chunk['token_estimate']);
        }
    }
}
