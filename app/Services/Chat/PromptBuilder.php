<?php

namespace App\Services\Chat;

use App\Services\Retrieval\RetrievedChunk;

class PromptBuilder
{
    /**
     * Instrucción de sistema fija del asistente RAG.
     */
    public function systemInstruction(): string
    {
        return <<<'PROMPT'
        Sos DocBrainer, un asistente que responde preguntas usando EXCLUSIVAMENTE los fragmentos de documentos provistos como contexto.

        Reglas:
        - Respondé en el idioma de la pregunta (por defecto, español).
        - Basate SOLO en los fragmentos numerados [1], [2], ... del contexto. No uses conocimiento externo.
        - Citá las fuentes con su número entre corchetes, por ejemplo: "El presupuesto es de 5 millones [2]." Citá cada afirmación relevante.
        - Si el contexto no alcanza para responder, decí claramente: "No encuentro esa información en los documentos del proyecto." y sugerí qué documento podría faltar. No inventes.
        - Usá formato Markdown (listas, negritas, tablas) cuando mejore la lectura.
        PROMPT;
    }

    /**
     * Construye el turno de usuario final: bloques de contexto + pregunta.
     *
     * @param  list<RetrievedChunk>  $retrieved
     */
    public function userTurn(array $retrieved, string $question): string
    {
        $blocks = array_map(function (RetrievedChunk $r) {
            $meta = array_filter([
                'Documento: '.($r->chunk->document?->title ?? 'desconocido'),
                $r->chunk->section_path !== null ? 'Sección: '.$r->chunk->section_path : null,
                $r->chunk->page_start !== null
                    ? 'Págs: '.$r->chunk->page_start.($r->chunk->page_end !== null && $r->chunk->page_end !== $r->chunk->page_start ? '–'.$r->chunk->page_end : '')
                    : null,
            ]);

            return "[{$r->n}] (".implode(' | ', $meta).")\n".$r->chunk->content;
        }, $retrieved);

        return "Contexto:\n\n".implode("\n\n---\n\n", $blocks)
            ."\n\nPregunta: ".$question;
    }
}
