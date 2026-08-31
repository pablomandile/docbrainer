<?php

namespace App\Services\Extraction;

use RuntimeException;

/**
 * Falla de extracción con mensaje apto para mostrar al usuario (en español).
 */
class ExtractionFailedException extends RuntimeException {}
