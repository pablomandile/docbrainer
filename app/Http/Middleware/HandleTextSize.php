<?php

namespace App\Http\Middleware;

use App\Enums\TextSize;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve el tamaño de letra antes de renderizar, para que el atributo ya
 * viaje en el HTML de arranque.
 *
 * A diferencia del modo oscuro no hace falta un script inline: no hay opción
 * "según el sistema", el servidor ya sabe la respuesta y la escribe en <html>.
 * Cero parpadeo y cero JavaScript.
 */
class HandleTextSize
{
    public function handle(Request $request, Closure $next): Response
    {
        View::share('textSize', $this->resolve($request));

        return $next($request);
    }

    /**
     * Manda la cuenta y después la cookie: la cuenta es la que viaja entre
     * dispositivos; la cookie cubre a quien todavía no inició sesión.
     */
    private function resolve(Request $request): TextSize
    {
        $user = $request->user();

        if ($user !== null) {
            return $user->text_size;
        }

        // Una cookie puede llegar como array si la mandan con corchetes
        $cookie = $request->cookie('text_size');

        return TextSize::fromValue(is_string($cookie) ? $cookie : null);
    }
}
