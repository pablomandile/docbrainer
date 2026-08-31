<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Services\Extraction\DocxExtractor;
use App\Services\Extraction\ExtractionFailedException;
use App\Services\Extraction\JsonExtractor;
use App\Services\Extraction\MarkdownExtractor;
use App\Services\Extraction\PlainTextExtractor;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class ExtractionTest extends TestCase
{
    private function fakeDocument(string $extension): Document
    {
        return new Document(['extension' => $extension, 'title' => 'Prueba']);
    }

    private function tempFile(string $extension, string $content): string
    {
        $path = sys_get_temp_dir().'/docbrainer_test_'.uniqid().'.'.$extension;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_markdown_extractor_builds_sections_from_headings(): void
    {
        $md = <<<'MD'
        Introducción sin título.

        # Capítulo 1

        Texto del capítulo uno con acentos: ñandú, corazón.

        ## Sección 1.1

        Detalle de la sección.

        ```php
        # esto es un comentario, no un título
        $x = 1;
        ```

        # Capítulo 2

        Texto final.
        MD;

        $path = $this->tempFile('md', $md);

        try {
            $result = (new MarkdownExtractor)->extract($this->fakeDocument('md'), $path);
        } finally {
            unlink($path);
        }

        $this->assertCount(4, $result->sections);
        $this->assertNull($result->sections[0]->title);
        $this->assertSame('Capítulo 1', $result->sections[1]->title);
        $this->assertSame(1, $result->sections[1]->level);
        $this->assertSame('Sección 1.1', $result->sections[2]->title);
        $this->assertSame(2, $result->sections[2]->level);
        $this->assertStringContainsString('# esto es un comentario', $result->sections[2]->text);
        $this->assertSame('Capítulo 2', $result->sections[3]->title);
        $this->assertStringContainsString('ñandú', $result->sections[1]->text);
    }

    public function test_plain_text_extractor_handles_windows_1252(): void
    {
        $latin = mb_convert_encoding('Año con eñes y acentuación', 'Windows-1252', 'UTF-8');
        $path = $this->tempFile('txt', $latin);

        try {
            $result = (new PlainTextExtractor)->extract($this->fakeDocument('txt'), $path);
        } finally {
            unlink($path);
        }

        $this->assertStringContainsString('Año con eñes y acentuación', $result->sections[0]->text);
    }

    public function test_json_extractor_pretty_prints(): void
    {
        $path = $this->tempFile('json', '{"obra":{"presupuesto":5000000,"moneda":"ARS"}}');

        try {
            $result = (new JsonExtractor)->extract($this->fakeDocument('json'), $path);
        } finally {
            unlink($path);
        }

        $this->assertStringContainsString('"presupuesto": 5000000', $result->sections[0]->text);
    }

    public function test_json_extractor_rejects_invalid_json(): void
    {
        $path = $this->tempFile('json', '{esto no es json}');

        try {
            $this->expectException(ExtractionFailedException::class);
            (new JsonExtractor)->extract($this->fakeDocument('json'), $path);
        } finally {
            unlink($path);
        }
    }

    public function test_docx_round_trip_preserves_heading_structure(): void
    {
        $phpWord = new PhpWord;
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 13]);

        $section = $phpWord->addSection();
        $section->addTitle('Capítulo 1: Arquitectura', 1);
        $section->addText('La casa tendrá dos plantas con quincho.');
        $section->addTitle('Materiales', 2);
        $section->addText('Ladrillo hueco del 18, losa premoldeada.');

        $path = sys_get_temp_dir().'/docbrainer_test_'.uniqid().'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        try {
            $result = (new DocxExtractor)->extract($this->fakeDocument('docx'), $path);
        } finally {
            unlink($path);
        }

        $titles = array_map(fn ($s) => $s->title, $result->sections);

        $this->assertContains('Capítulo 1: Arquitectura', $titles);
        $this->assertContains('Materiales', $titles);

        $chapterSection = collect($result->sections)->firstWhere('title', 'Capítulo 1: Arquitectura');
        $this->assertStringContainsString('dos plantas con quincho', $chapterSection->text);

        $materialsSection = collect($result->sections)->firstWhere('title', 'Materiales');
        $this->assertSame(2, $materialsSection->level);
        $this->assertStringContainsString('Ladrillo hueco', $materialsSection->text);
    }
}
