<?php

namespace App\Services\Extraction;

/**
 * Una sección estructural del documento (capítulo, encabezado, etc.).
 * Es la unidad sobre la que luego trabajan el Chunker y el SummaryPlanner.
 */
readonly class ExtractedSection
{
    public function __construct(
        public ?string $title,
        public int $level,          // 1 = capítulo/encabezado principal, 2 = subsección...
        public string $text,
        public ?int $pageStart = null,
        public ?int $pageEnd = null,
    ) {}

    /**
     * @return array{title: ?string, level: int, text: string, page_start: ?int, page_end: ?int}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'level' => $this->level,
            'text' => $this->text,
            'page_start' => $this->pageStart,
            'page_end' => $this->pageEnd,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'] ?? null,
            level: (int) ($data['level'] ?? 1),
            text: (string) ($data['text'] ?? ''),
            pageStart: isset($data['page_start']) ? (int) $data['page_start'] : null,
            pageEnd: isset($data['page_end']) ? (int) $data['page_end'] : null,
        );
    }
}
