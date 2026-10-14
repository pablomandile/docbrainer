<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * La cuenta de Google es válida pero no puede entrar. El mensaje se le
 * muestra al usuario tal cual.
 */
class GoogleLoginRejected extends RuntimeException {}
