<?php

namespace App\Services\AI\Contracts;

/**
 * Proveedor de generación de texto (chat y resúmenes).
 */
interface ChatProvider
{
    /**
     * Generación completa (sin streaming).
     *
     * @param  string  $systemInstruction  instrucción de sistema
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @return array{text: string, prompt_tokens: ?int, completion_tokens: ?int, model: string}
     */
    public function generate(string $systemInstruction, array $messages): array;

    /**
     * Generación con streaming: invoca $onDelta con cada fragmento de texto.
     *
     * @param  callable(string): void  $onDelta
     * @return array{text: string, prompt_tokens: ?int, completion_tokens: ?int, model: string}
     */
    public function streamGenerate(string $systemInstruction, array $messages, callable $onDelta): array;

    public function modelId(): string;
}
