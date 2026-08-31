<?php

namespace App\Services\Extraction;

use App\Models\Document;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\PreserveText;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use Throwable;

class DocxExtractor implements ExtractorInterface
{
    /** @var list<ExtractedSection> */
    private array $sections = [];

    private ?string $currentTitle = null;

    private int $currentLevel = 1;

    /** @var list<string> */
    private array $buffer = [];

    public function supports(Document $document): bool
    {
        return strtolower($document->extension) === 'docx';
    }

    public function extract(Document $document, string $absolutePath): ExtractedDocument
    {
        $this->sections = [];
        $this->currentTitle = null;
        $this->currentLevel = 1;
        $this->buffer = [];

        try {
            $phpWord = IOFactory::load($absolutePath, 'Word2007');
        } catch (Throwable $e) {
            throw new ExtractionFailedException(
                'No se pudo leer el archivo Word: '.$e->getMessage()
            );
        }

        foreach ($phpWord->getSections() as $section) {
            $this->walkContainer($section);
        }

        $this->flushSection();

        if ($this->sections === []) {
            throw new ExtractionFailedException(
                'El documento Word no contiene texto extraíble.'
            );
        }

        return new ExtractedDocument(sections: $this->sections);
    }

    /**
     * Recorre recursivamente los elementos de PHPWord acumulando texto,
     * y abre una nueva sección en cada Title (Heading 1..n).
     */
    private function walkContainer(AbstractContainer $container): void
    {
        foreach ($container->getElements() as $element) {
            match (true) {
                $element instanceof Title => $this->handleTitle($element),
                $element instanceof TextRun => $this->handleTextRun($element),
                $element instanceof Text => $this->appendText($element->getText()),
                $element instanceof Link => $this->appendText($element->getText()),
                $element instanceof PreserveText => $this->appendText(implode(' ', (array) $element->getText())),
                $element instanceof ListItem => $this->appendText('• '.$element->getTextObject()->getText()),
                $element instanceof TextBreak => $this->appendText(''),
                $element instanceof Table => $this->handleTable($element),
                $element instanceof AbstractContainer => $this->walkContainer($element),
                default => null,
            };
        }
    }

    private function handleTitle(Title $title): void
    {
        $text = $title->getText();

        if ($text instanceof TextRun) {
            $text = $this->textRunToString($text);
        }

        $text = trim((string) $text);

        if ($text === '') {
            return;
        }

        $this->flushSection();

        $this->currentTitle = $text;
        $this->currentLevel = max(1, (int) $title->getDepth());
    }

    private function handleTextRun(TextRun $run): void
    {
        $this->appendText($this->textRunToString($run));
    }

    private function textRunToString(TextRun $run): string
    {
        $parts = [];

        foreach ($run->getElements() as $element) {
            if ($element instanceof Text || $element instanceof Link) {
                $parts[] = $element->getText();
            }
        }

        return implode('', $parts);
    }

    private function handleTable(Table $table): void
    {
        foreach ($table->getRows() as $row) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                $cellText = [];

                foreach ($cell->getElements() as $element) {
                    if ($element instanceof TextRun) {
                        $cellText[] = $this->textRunToString($element);
                    } elseif ($element instanceof Text) {
                        $cellText[] = $element->getText();
                    }
                }

                $cells[] = trim(implode(' ', $cellText));
            }

            // Fila de tabla como línea "col1 | col2 | col3"
            $this->appendText(implode(' | ', array_filter($cells, fn ($c) => $c !== '')));
        }
    }

    private function appendText(string $text): void
    {
        $this->buffer[] = $text;
    }

    private function flushSection(): void
    {
        $text = trim(implode("\n", $this->buffer));

        if ($text !== '') {
            $this->sections[] = new ExtractedSection(
                title: $this->currentTitle,
                level: $this->currentLevel,
                text: $text,
            );
        }

        $this->buffer = [];
    }
}
