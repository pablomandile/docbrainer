<?php

namespace App\Services\AI;

use RuntimeException;

/**
 * Se alcanzó el tope diario propio (docbrainer.gemini.*_daily_limit).
 * Los jobs deben hacer release() hasta el próximo reset, nunca insistir.
 */
class QuotaExceededException extends RuntimeException {}
