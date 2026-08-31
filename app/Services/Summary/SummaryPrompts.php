<?php

namespace App\Services\Summary;

use App\Models\Summary;

class SummaryPrompts
{
    public function systemInstruction(): string
    {
        return 'Sos un asistente experto en resumir documentos con fidelidad. '
            .'Nunca inventás datos: solo condensás la información provista, '
            .'preservando cifras, nombres propios y fechas exactas.';
    }

    /**
     * Prompt "map": resumen parcial estructurado de una porción del texto fuente.
     */
    public function mapPrompt(string $label, string $sourceText): string
    {
        return <<<PROMPT
        Resumí la siguiente parte de un documento ("{$label}") como RESUMEN PARCIAL estructurado en Markdown:

        - **Ideas principales**: viñetas concisas con las afirmaciones clave.
        - **Datos**: cifras, montos, fechas, nombres propios exactos (si los hay).

        No agregues introducciones ni conclusiones: solo el resumen parcial. Mantené el idioma original del texto.

        Texto:

        {$sourceText}
        PROMPT;
    }

    /**
     * Prompt "reduce" intermedio: consolida varios resúmenes parciales en uno.
     */
    public function reducePrompt(string $partials): string
    {
        return <<<PROMPT
        A continuación hay varios RESÚMENES PARCIALES consecutivos de un mismo material. Consolidalos en UN único resumen parcial estructurado en Markdown, manteniendo:

        - **Ideas principales**: sin repetir; fusioná las que se solapen.
        - **Datos**: preservá todas las cifras, montos, fechas y nombres propios.

        No agregues introducciones ni conclusiones. Mantené el idioma original.

        Resúmenes parciales:

        {$partials}
        PROMPT;
    }

    /**
     * Prompt final: produce el resumen definitivo con los parámetros pedidos.
     *
     * @param  string  $material  texto fuente (si el documento es corto) o resúmenes parciales
     */
    public function finalPrompt(Summary $summary, string $material, bool $fromPartials): string
    {
        $params = $summary->params;

        $length = match ($params['length'] ?? 'medio') {
            'corto' => 'CORTO: entre 150 y 250 palabras',
            'extenso' => 'EXTENSO: entre 800 y 1200 palabras, con secciones tituladas',
            default => 'MEDIO: entre 400 y 600 palabras',
        };

        $style = match ($params['style'] ?? 'divulgativo') {
            'ejecutivo' => 'EJECUTIVO: directo, orientado a decisiones, con viñetas de puntos clave al inicio',
            'academico' => 'ACADÉMICO: formal y preciso, con terminología técnica cuando corresponda',
            default => 'DIVULGATIVO: claro y accesible para cualquier lector',
        };

        $language = match ($params['language'] ?? 'es') {
            'en' => 'inglés',
            default => 'español',
        };

        $scope = $summary->document_id !== null
            ? 'del documento "'.($summary->document?->title ?? '').'"'
            : 'del proyecto completo "'.($summary->project?->name ?? '').'" (todos sus documentos)';

        $sourceNote = $fromPartials
            ? 'El material de abajo son resúmenes parciales ya consolidados del texto original.'
            : 'El material de abajo es el texto original.';

        return <<<PROMPT
        Escribí el RESUMEN FINAL {$scope}.

        Requisitos:
        - Largo {$length}.
        - Estilo {$style}.
        - Idioma: {$language}.
        - Formato Markdown (títulos, viñetas o tablas si mejoran la lectura).
        - Fidelidad absoluta: preservá cifras, montos, fechas y nombres exactos. No inventes nada.

        {$sourceNote}

        Material:

        {$material}
        PROMPT;
    }
}
