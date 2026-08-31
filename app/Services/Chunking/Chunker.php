<?php

namespace App\Services\Chunking;

use App\Services\Extraction\ExtractedDocument;

/**
 * Trocea un documento extraído en fragmentos de ~450 tokens respetando
 * la estructura: sección → párrafo → oración → corte duro.
 *
 * Los tokens se estiman con la heurística mb_strlen/4 (funciona bien
 * para español); todos los umbrales tienen margen para absorber el error.
 */
class Chunker
{
    /**
     * @return list<array{
     *     chunk_index: int,
     *     section_title: ?string,
     *     section_path: ?string,
     *     page_start: ?int,
     *     page_end: ?int,
     *     content: string,
     *     token_estimate: int,
     * }>
     */
    public function chunk(ExtractedDocument $document): array
    {
        $targetTokens = (int) config('docbrainer.chunking.target_tokens');
        $overlapTokens = (int) ceil($targetTokens * config('docbrainer.chunking.overlap_pct') / 100);

        $chunks = [];
        $index = 0;

        /** @var array<int, string> $breadcrumb nivel => título */
        $breadcrumb = [];

        foreach ($document->sections as $section) {
            if (trim($section->text) === '') {
                continue;
            }

            if ($section->title !== null) {
                // Un título de nivel N reinicia los niveles más profundos
                $breadcrumb = array_filter(
                    $breadcrumb,
                    fn (int $level) => $level < $section->level,
                    ARRAY_FILTER_USE_KEY,
                );
                $breadcrumb[$section->level] = $section->title;
            }

            $path = $breadcrumb === [] ? null : implode(' > ', $breadcrumb);

            foreach ($this->splitText($section->text, $targetTokens, $overlapTokens) as $piece) {
                $chunks[] = [
                    'chunk_index' => $index++,
                    'section_title' => $section->title,
                    'section_path' => $path !== null ? mb_substr($path, 0, 500) : null,
                    'page_start' => $section->pageStart,
                    'page_end' => $section->pageEnd,
                    'content' => $piece,
                    'token_estimate' => $this->estimateTokens($piece),
                ];
            }
        }

        return $chunks;
    }

    public function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Divide un texto en fragmentos de hasta ~$targetTokens, con solapamiento.
     *
     * @return list<string>
     */
    private function splitText(string $text, int $targetTokens, int $overlapTokens): array
    {
        $text = trim($text);

        if ($this->estimateTokens($text) <= $targetTokens) {
            return [$text];
        }

        $pieces = $this->atomicPieces($text, $targetTokens);

        // Empaquetado goloso con solapamiento entre fragmentos consecutivos
        $chunks = [];
        $current = [];
        $currentTokens = 0;

        foreach ($pieces as $piece) {
            $pieceTokens = $this->estimateTokens($piece);

            if ($current !== [] && $currentTokens + $pieceTokens > $targetTokens) {
                $chunks[] = implode("\n\n", $current);

                // El solapamiento arrastra las últimas piezas del chunk anterior
                [$current, $currentTokens] = $this->overlapTail($current, $overlapTokens);
            }

            $current[] = $piece;
            $currentTokens += $pieceTokens;
        }

        if ($current !== []) {
            $tail = implode("\n\n", $current);

            // Evita un último chunk que sea puro solapamiento repetido
            if ($chunks === [] || ! str_ends_with(end($chunks), $tail)) {
                $chunks[] = $tail;
            }
        }

        return $chunks;
    }

    /**
     * Piezas indivisibles: párrafos; párrafos gigantes → oraciones; y si una
     * oración sigue enorme (texto sin puntuación), corte duro por caracteres.
     *
     * @return list<string>
     */
    private function atomicPieces(string $text, int $targetTokens): array
    {
        $maxPieceTokens = (int) ceil($targetTokens * 0.9);
        $pieces = [];

        foreach (preg_split("/\n{2,}/", $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if ($this->estimateTokens($paragraph) <= $maxPieceTokens) {
                $pieces[] = $paragraph;

                continue;
            }

            // Párrafo demasiado grande → separar por oraciones
            $sentences = preg_split('/(?<=[.!?…])\s+/u', $paragraph) ?: [$paragraph];

            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);

                if ($sentence === '') {
                    continue;
                }

                if ($this->estimateTokens($sentence) <= $maxPieceTokens) {
                    $pieces[] = $sentence;

                    continue;
                }

                // Oración interminable → corte duro
                $maxChars = $maxPieceTokens * 4;

                foreach (mb_str_split($sentence, $maxChars) as $slice) {
                    $pieces[] = $slice;
                }
            }
        }

        return $pieces;
    }

    /**
     * Toma las últimas piezas de un chunk hasta cubrir el presupuesto de solapamiento.
     *
     * @param  list<string>  $pieces
     * @return array{0: list<string>, 1: int}
     */
    private function overlapTail(array $pieces, int $overlapTokens): array
    {
        if ($overlapTokens <= 0) {
            return [[], 0];
        }

        $tail = [];
        $tokens = 0;

        foreach (array_reverse($pieces) as $piece) {
            $pieceTokens = $this->estimateTokens($piece);

            if ($tokens + $pieceTokens > $overlapTokens) {
                break;
            }

            array_unshift($tail, $piece);
            $tokens += $pieceTokens;
        }

        return [$tail, $tokens];
    }
}
