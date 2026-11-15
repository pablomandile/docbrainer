<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En la demo (APP_DEMO=true) hay un solo usuario, compartido por todos los que
 * la prueban.
 *
 * - **Nadie entra por otro lado**: sin registro, sin Google, sin recuperar la
 *   clave. Esas pantallas van al login, donde está el botón de la demo.
 * - **La cuenta no se toca**: ni la clave, ni el email, ni el 2FA, ni borrarla.
 *   Un visitante que le pone 2FA deja afuera a todos los demás hasta el reset.
 * - **El proveedor de IA tampoco**: la demo usa solo Gemini; pasarla a Ollama
 *   (que en el server no existe) dejaría el chat roto para todos.
 *
 * Todo lo demás —crear proyectos, subir documentos, preguntar, pedir
 * resúmenes, borrar— sí: es lo que se quiere mostrar, y se resiembra cada noche.
 *
 * Con el flag apagado (la productiva) no hace nada.
 */
class ProtegerDemo
{
    /** Pantallas que no existen en la demo: van al login. */
    private const PANTALLAS = [
        'register', 'register.store',
        'google.redirect', 'google.callback',
        'password.request', 'password.email', 'password.reset', 'password.update',
    ];

    /** Acciones sobre la cuenta compartida: vuelven atrás con un aviso. */
    private const ACCIONES = [
        'profile.update', 'profile.destroy', 'user-password.update',
        'two-factor.enable', 'two-factor.confirm', 'two-factor.disable',
        'two-factor.regenerate-recovery-codes',
        'ai-settings.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('demo.enabled')) {
            return $next($request);
        }

        $ruta = $request->route()?->getName();

        // El POST de /register de Fortify puede no tener nombre.
        if (in_array($ruta, self::PANTALLAS, true) || $request->is('register')) {
            return redirect()->route('login');
        }

        if (in_array($ruta, self::ACCIONES, true)) {
            // El flash `error` es el que el front muestra como toast.
            return back()->with('error', 'Eso no se puede en la demo: la cuenta es compartida.');
        }

        return $next($request);
    }
}
