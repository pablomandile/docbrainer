<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un usuario registrado no puede usar la app hasta que un admin lo active.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(403, 'Tu cuenta todavía no fue activada.');
            }

            return redirect()->route('pending-approval');
        }

        return $next($request);
    }
}
