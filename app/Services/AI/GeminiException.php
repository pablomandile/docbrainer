<?php

namespace App\Services\AI;

use RuntimeException;

/**
 * Error de la API de Gemini (respuesta inválida, 4xx/5xx agotados los reintentos).
 */
class GeminiException extends RuntimeException {}
