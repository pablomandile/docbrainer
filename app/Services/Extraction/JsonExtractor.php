<?php

namespace App\Services\Extraction;

use App\Models\Document;

class JsonExtractor implements ExtractorInterface
{
    public function supports(Document $document): bool
    {
        return strtolower($document->extension) === 'json';
    }

    public function extract(Document $document, string $absolutePath): ExtractedDocument
    {
        $content = file_get_contents($absolutePath);

        if ($content === false || trim($content) === '') {
            throw new ExtractionFailedException('El archivo JSON está vacío o no se pudo leer.');
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ExtractionFailedException(
                'El archivo no es un JSON válido: '.json_last_error_msg()
            );
        }

        // Re-serializa legible: el texto plano indentado se trocea y vectoriza bien
        $pretty = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return new ExtractedDocument(sections: [
            new ExtractedSection(title: null, level: 1, text: (string) $pretty),
        ]);
    }
}
