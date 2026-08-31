<?php

namespace App\Services\Extraction;

use App\Models\Document;

class MarkdownExtractor implements ExtractorInterface
{
    public function supports(Document $document): bool
    {
        return strtolower($document->extension) === 'md';
    }

    public function extract(Document $document, string $absolutePath): ExtractedDocument
    {
        $content = file_get_contents($absolutePath);

        if ($content === false || trim($content) === '') {
            throw new ExtractionFailedException('El archivo Markdown está vacío o no se pudo leer.');
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $sections = [];
        $currentTitle = null;
        $currentLevel = 1;
        $buffer = [];
        $inCodeBlock = false;

        foreach (explode("\n", $content) as $line) {
            // No interpretar "# comentario" dentro de bloques de código
            if (preg_match('/^```/', $line)) {
                $inCodeBlock = ! $inCodeBlock;
                $buffer[] = $line;

                continue;
            }

            if (! $inCodeBlock && preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                $text = trim(implode("\n", $buffer));

                if ($text !== '') {
                    $sections[] = new ExtractedSection(
                        title: $currentTitle,
                        level: $currentLevel,
                        text: $text,
                    );
                }

                $currentTitle = trim($m[2]);
                $currentLevel = strlen($m[1]);
                $buffer = [];

                continue;
            }

            $buffer[] = $line;
        }

        $text = trim(implode("\n", $buffer));

        if ($text !== '') {
            $sections[] = new ExtractedSection(
                title: $currentTitle,
                level: $currentLevel,
                text: $text,
            );
        }

        if ($sections === []) {
            throw new ExtractionFailedException('El archivo Markdown no contiene texto.');
        }

        return new ExtractedDocument(sections: $sections);
    }
}
