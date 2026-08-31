<?php

namespace App\Services\Extraction;

use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser as SmalotParser;
use Spatie\PdfToText\Pdf;
use Throwable;

class PdfExtractor implements ExtractorInterface
{
    public function supports(Document $document): bool
    {
        return strtolower($document->extension) === 'pdf';
    }

    public function extract(Document $document, string $absolutePath): ExtractedDocument
    {
        $pages = $this->extractPages($absolutePath);

        if ($pages === []) {
            throw new ExtractionFailedException(
                'No se pudo extraer texto del PDF. El archivo podría estar dañado o protegido.'
            );
        }

        $this->guardAgainstScannedPdf($pages);

        return new ExtractedDocument(
            sections: $this->buildSections($pages),
            pageCount: count($pages),
        );
    }

    /**
     * Devuelve el texto por página (índice 0 = página 1).
     * Intenta pdftotext (rápido, streaming) y cae a smalot/pdfparser (PHP puro).
     *
     * @return list<string>
     */
    private function extractPages(string $absolutePath): array
    {
        try {
            $binary = config('docbrainer.extraction.pdftotext_path');

            // pdftotext separa páginas con \f (form feed)
            $text = Pdf::getText($absolutePath, $binary, ['enc UTF-8']);

            return $this->splitPages($text);
        } catch (Throwable $e) {
            Log::warning('pdftotext falló, probando smalot/pdfparser', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);
        }

        $maxBytes = config('docbrainer.extraction.parser_fallback_max_mb') * 1024 * 1024;

        if (filesize($absolutePath) > $maxBytes) {
            throw new ExtractionFailedException(
                'No se pudo procesar el PDF: pdftotext no está disponible y el archivo es '
                .'demasiado grande para el parser alternativo. Verificá PDFTOTEXT_PATH en .env.'
            );
        }

        try {
            $pdf = (new SmalotParser)->parseFile($absolutePath);

            $pages = [];
            foreach ($pdf->getPages() as $page) {
                $pages[] = $this->normalize($page->getText());
            }

            return $pages;
        } catch (Throwable $e) {
            throw new ExtractionFailedException(
                'No se pudo extraer texto del PDF: '.$e->getMessage()
            );
        }
    }

    /**
     * @return list<string>
     */
    private function splitPages(string $text): array
    {
        $pages = explode("\f", $text);

        // pdftotext suele dejar un \f final → última "página" vacía
        if ($pages !== [] && trim(end($pages)) === '') {
            array_pop($pages);
        }

        return array_map(fn (string $p) => $this->normalize($p), $pages);
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Colapsa 3+ saltos en 2 (separador de párrafo)
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        // Espacios/tabs colgantes por línea
        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * PDF escaneado = imágenes sin capa de texto. Sin OCR en esta versión.
     *
     * @param  list<string>  $pages
     */
    private function guardAgainstScannedPdf(array $pages): void
    {
        $threshold = (int) config('docbrainer.extraction.scanned_pdf_chars_per_page');

        $totalChars = array_sum(array_map(fn (string $p) => mb_strlen(trim($p)), $pages));

        if ($totalChars / max(count($pages), 1) < $threshold) {
            throw new ExtractionFailedException(
                'El PDF parece escaneado (imágenes sin texto seleccionable). '
                .'El reconocimiento óptico (OCR) no está soportado en esta versión: '
                .'convertí el documento a texto antes de subirlo.'
            );
        }
    }

    /**
     * Construye secciones por página, arrastrando el último título detectado.
     * Las secciones nunca cruzan páginas → las citas por página son exactas.
     *
     * @param  list<string>  $pages
     * @return list<ExtractedSection>
     */
    private function buildSections(array $pages): array
    {
        $sections = [];
        $currentTitle = null;
        $currentLevel = 1;

        foreach ($pages as $index => $pageText) {
            $pageNumber = $index + 1;

            if (trim($pageText) === '') {
                continue;
            }

            $buffer = [];

            foreach (explode("\n", $pageText) as $line) {
                $heading = $this->detectHeading(trim($line));

                if ($heading !== null) {
                    // Cierra el bloque acumulado bajo el título anterior
                    if (trim(implode("\n", $buffer)) !== '') {
                        $sections[] = new ExtractedSection(
                            title: $currentTitle,
                            level: $currentLevel,
                            text: trim(implode("\n", $buffer)),
                            pageStart: $pageNumber,
                            pageEnd: $pageNumber,
                        );
                    }

                    [$currentTitle, $currentLevel] = $heading;
                    $buffer = [];

                    continue;
                }

                $buffer[] = $line;
            }

            if (trim(implode("\n", $buffer)) !== '') {
                $sections[] = new ExtractedSection(
                    title: $currentTitle,
                    level: $currentLevel,
                    text: trim(implode("\n", $buffer)),
                    pageStart: $pageNumber,
                    pageEnd: $pageNumber,
                );
            }
        }

        return $sections;
    }

    /**
     * Heurísticas de detección de títulos en texto plano de PDF.
     *
     * @return array{0: string, 1: int}|null [título, nivel] o null si no es título
     */
    private function detectHeading(string $line): ?array
    {
        if ($line === '' || mb_strlen($line) > 90) {
            return null;
        }

        // "CAPÍTULO 3", "Capítulo IV", "PARTE II", "ANEXO A", "APÉNDICE B"...
        if (preg_match('/^(CAP[ÍI]TULO|CHAPTER|PARTE|PART|ANEXO|AP[ÉE]NDICE|SECCI[ÓO]N)\s+([0-9IVXLCDM]+|[A-Z])\b.*$/iu', $line)) {
            return [$line, 1];
        }

        // Numeración jerárquica: "2. Título", "3.1 Métodos", "4.2.1 Detalle"
        if (preg_match('/^(\d+(?:\.\d+)*)[.)]?\s+\p{L}/u', $line, $m) && ! str_ends_with($line, '.')) {
            $depth = substr_count($m[1], '.') + 1;

            return [$line, min($depth, 3)];
        }

        // Línea corta TODA EN MAYÚSCULAS (mínimo 4 letras, sin punto final)
        if (
            mb_strlen($line) >= 4 && mb_strlen($line) <= 70
            && ! str_ends_with($line, '.')
            && preg_match('/\p{Lu}/u', $line)
            && ! preg_match('/\p{Ll}/u', $line)
            && preg_match_all('/\p{L}/u', $line) >= 4
        ) {
            return [$line, 1];
        }

        return null;
    }
}
