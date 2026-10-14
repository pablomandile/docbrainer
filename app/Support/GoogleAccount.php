<?php

namespace App\Support;

use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Lo único que DocBrainer necesita de una cuenta de Google, ya normalizado.
 *
 * Desacopla el alta de usuarios de Socialite y permite probarla sin hablar
 * con Google.
 */
final readonly class GoogleAccount
{
    public function __construct(
        public string $id,
        public string $email,
        public ?string $name,
        public ?string $avatar,
        public bool $emailVerified,
    ) {}

    public static function fromSocialite(SocialiteUser $user): self
    {
        return new self(
            id: (string) $user->getId(),
            email: mb_strtolower(trim((string) $user->getEmail())),
            name: $user->getName() ?: $user->getNickname(),
            avatar: $user->getAvatar() ?: null,
            emailVerified: self::emailVerified($user),
        );
    }

    /**
     * El flag viene en el payload crudo, que solo expone AbstractUser.
     *
     * Si no viene, el email NO se da por verificado: si no, cualquiera podría
     * declarar la dirección de otro en una cuenta de Google y entrar a la suya.
     */
    private static function emailVerified(SocialiteUser $user): bool
    {
        if (! $user instanceof AbstractUser) {
            return false;
        }

        $raw = $user->getRaw();

        // La clave cambia según qué endpoint de Google haya respondido
        foreach (['verified_email', 'email_verified'] as $key) {
            if (array_key_exists($key, $raw)) {
                return filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return false;
    }
}
