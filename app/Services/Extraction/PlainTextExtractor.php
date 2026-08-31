<?php

namespace App\Services\Extraction;

use App\Models\Document;

class PlainTextExtractor implements ExtractorInterface
{
    public function supports(Document $document): bool
    {
        return strtolower($document->extension) === 'txt';
    }

    public function extract(Document $document, string $absolutePath): ExtractedDocument
    {
        $content = file_get_contents($absolutePath);

        if ($content === false || trim($content) === '') {
            throw new ExtractionFailedException('El archivo de texto está vacío o no se pudo leer.');
        }

        // Normaliza a UTF-8 si viene en otra codificación (típico: Windows-1252)
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return new ExtractedDocument(sections: [
            new ExtractedSection(title: null, level: 1, text: trim($content)),
        ]);
    }
}
