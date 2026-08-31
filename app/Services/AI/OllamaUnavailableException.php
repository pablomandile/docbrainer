<?php

namespace App\Services\AI;

use RuntimeException;

/**
 * Ollama no responde (no está corriendo o la URL es incorrecta).
 * Los jobs deben hacer release() y avisar; nunca fallar definitivamente.
 */
class OllamaUnavailableException extends RuntimeException {}
