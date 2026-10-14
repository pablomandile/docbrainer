<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\GoogleLoginRejected;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\GoogleLogin;
use App\Support\GoogleAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SocialiteRedirect;
use Throwable;

class GoogleController extends Controller
{
    public function __construct(private readonly GoogleLogin $googleLogin) {}

    public function redirect(): SocialiteRedirect|RedirectResponse
    {
        abort_unless(GoogleLogin::enabled(), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(GoogleLogin::enabled(), 404);

        // Tocó "Cancelar" en Google: vuelve al login sin cartel de error
        if ($request->has('error')) {
            return redirect()->route('login');
        }

        try {
            $user = $this->googleLogin->resolve(
                GoogleAccount::fromSocialite(Socialite::driver('google')->user()),
            );
        } catch (GoogleLoginRejected $e) {
            Log::info('Ingreso con Google rechazado.', ['motivo' => $e->getMessage()]);

            return $this->backToLogin($e->getMessage());
        } catch (Throwable $e) {
            // El detalle va al log: los mensajes de Socialite traen partes de
            // la respuesta de Google que no le dicen nada al usuario
            Log::warning('Falló el ingreso con Google.', ['excepcion' => $e->getMessage()]);

            return $this->backToLogin('No pudimos entrar con Google. Probá de nuevo, o entrá con tu email y contraseña.');
        }

        // Google no reemplaza al segundo factor: si lo tiene activado, pasa
        // por la misma pantalla del código que el login con contraseña
        if ($this->requiresTwoFactor($user)) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => true,
            ]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // Si la cuenta está pendiente, el middleware `active` la manda a esperar
        return redirect()->intended(route('dashboard'));
    }

    private function requiresTwoFactor(User $user): bool
    {
        return filled($user->two_factor_secret) && $user->two_factor_confirmed_at !== null;
    }

    /**
     * El mensaje va por la sesión y lo muestra el login: la vuelta de Google es
     * una carga de página completa, no una visita de Inertia.
     */
    private function backToLogin(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message);
    }
}
