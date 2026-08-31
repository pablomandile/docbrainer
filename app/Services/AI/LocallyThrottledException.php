<?php

namespace App\Services\AI;

use RuntimeException;

/**
 * El gate de RPM del lado cliente está saturado en este minuto.
 * Los jobs deben hacer release(~65s) y reintentar.
 */
class LocallyThrottledException extends RuntimeException {}
