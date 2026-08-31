<?php

namespace App\Services\Extraction;

use App\Models\Document;

class ExtractorManager
{
    /** @var list<ExtractorInterface> */
    private array $extractors;

    public function __construct(
        PdfExtractor $pdf,
        DocxExtractor $docx,
        MarkdownExtractor $markdown,
        PlainTextExtractor $plainText,
        JsonExtractor $json,
    ) {
        $this->extractors = [$pdf, $docx, $markdown, $plainText, $json];
    }

    /**
     * @throws ExtractionFailedException si no hay extractor para el tipo de archivo
     */
    public function extract(Document $document, string $absolutePath): ExtractedDocument
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($document)) {
                return $extractor->extract($document, $absolutePath);
            }
        }

        throw new ExtractionFailedException(
            "No hay extractor disponible para archivos .{$document->extension}."
        );
    }
}
