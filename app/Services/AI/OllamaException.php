<?php

namespace App\Services\AI;

use RuntimeException;

/**
 * Error de Ollama distinto de "no disponible" (ej. modelo no descargado).
 */
class OllamaException extends RuntimeException {}
