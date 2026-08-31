<?php

namespace App\Services\Extraction;

/**
 * Resultado de la extracción de texto de un documento,
 * preservando la estructura (secciones) y metadata de páginas.
 */
readonly class ExtractedDocument
{
    /**
     * @param  list<ExtractedSection>  $sections
     */
    public function __construct(
        public array $sections,
        public ?int $pageCount = null,
    ) {}

    public function charCount(): int
    {
        return array_sum(array_map(
            fn (ExtractedSection $s) => mb_strlen($s->text),
            $this->sections,
        ));
    }

    public function isEmpty(): bool
    {
        foreach ($this->sections as $section) {
            if (trim($section->text) !== '') {
                return false;
            }
        }

        return true;
    }

    public function toArray(): array
    {
        return [
            'page_count' => $this->pageCount,
            'char_count' => $this->charCount(),
            'sections' => array_map(fn (ExtractedSection $s) => $s->toArray(), $this->sections),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            sections: array_map(
                fn (array $s) => ExtractedSection::fromArray($s),
                $data['sections'] ?? [],
            ),
            pageCount: isset($data['page_count']) ? (int) $data['page_count'] : null,
        );
    }
}
