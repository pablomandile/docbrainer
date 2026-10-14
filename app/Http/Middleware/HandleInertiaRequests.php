<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\GoogleLogin;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'isAdmin' => (bool) $request->user()?->isAdmin(),
                // Las cuentas creadas con Google no tienen contraseña
                'hasPassword' => filled($request->user()?->getAuthPassword()),
            ],
            // Badge del menú "Usuarios": cuentas que esperan activación
            'pendingUsersCount' => fn () => $request->user()?->isAdmin()
                ? User::where('is_active', false)->count()
                : 0,
            // Sin credenciales en el .env el botón de Google no se dibuja
            'googleLoginEnabled' => GoogleLogin::enabled(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
