<?php

namespace App\Services\AI;

/**
 * La API de Gemini devolvió 429 y se agotaron los reintentos internos.
 * Los jobs deben hacer release() y reintentar más tarde.
 */
class GeminiRateLimitedException extends GeminiException {}
