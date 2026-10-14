<?php

namespace App\Services\Auth;

use App\Exceptions\GoogleLoginRejected;
use App\Models\User;
use App\Support\GoogleAccount;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Features;

/**
 * Resuelve una cuenta de Google en un usuario de DocBrainer.
 *
 * El email manda: Google ya lo verificó, así que si coincide con una cuenta
 * existente es la misma persona y se le vincula el google_id en vez de crear
 * otra (dos cuentas con el mismo email partirían sus proyectos en dos).
 *
 * Las cuentas nuevas siguen la misma regla que el registro con email: quedan
 * pendientes hasta que un admin las active, salvo el email de admin.
 */
class GoogleLogin
{
    /**
     * Sin credenciales, el botón no se muestra y las rutas dan 404: el código
     * puede estar desplegado antes de que exista el cliente en Google Cloud.
     */
    public static function enabled(): bool
    {
        // Config::get y no Config::string: sin las variables el valor es null
        // y Config::string tiraría excepción justo en el caso que esto cubre.
        return filled(Config::get('services.google.client_id'))
            && filled(Config::get('services.google.client_secret'));
    }

    /**
     * @throws GoogleLoginRejected
     */
    public function resolve(GoogleAccount $account): User
    {
        if (blank($account->id) || blank($account->email)) {
            throw new GoogleLoginRejected('Google no devolvió el email de la cuenta.');
        }

        if (! $account->emailVerified) {
            throw new GoogleLoginRejected('Esa cuenta de Google no tiene el email verificado.');
        }

        return DB::transaction(function () use ($account) {
            // 1. Ya entró antes con esta cuenta de Google. Se reconoce por el
            //    id y no por el email, que en Google se puede cambiar.
            $user = User::where('google_id', $account->id)->first();

            if ($user) {
                $user->email = $account->email;

                return $this->refresh($user, $account);
            }

            // 2. Existe por email (se registró con contraseña): se vinculan.
            $user = User::where('email', $account->email)->first();

            if ($user) {
                $user->google_id = $account->id;

                return $this->refresh($user, $account);
            }

            // 3. Cuenta nueva: solo si el registro está abierto
            if (! Features::enabled(Features::registration())) {
                throw new GoogleLoginRejected(
                    "La cuenta {$account->email} no está registrada en DocBrainer."
                );
            }

            return $this->create($account);
        });
    }

    private function refresh(User $user, GoogleAccount $account): User
    {
        // La foto de Google cambia cada tanto: se refresca en cada ingreso
        $user->avatar = $account->avatar;
        // Si se había registrado por email sin confirmarlo, Google lo confirmó
        $user->email_verified_at ??= now();
        $user->save();

        return $user;
    }

    private function create(GoogleAccount $account): User
    {
        $isAdmin = $account->email === mb_strtolower(config('docbrainer.admin_email'));

        // forceFill: ninguno de estos campos es fillable a propósito
        $user = new User;
        $user->forceFill([
            'name' => $account->name ?: str($account->email)->before('@')->toString(),
            'email' => $account->email,
            'google_id' => $account->id,
            'avatar' => $account->avatar,
            'password' => null,
            'role' => $isAdmin ? User::ROLE_ADMIN : User::ROLE_USER,
            'is_active' => $isAdmin,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }
}
