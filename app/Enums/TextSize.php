<?php

namespace App\Enums;

/**
 * Tamaño de letra de la app: tres opciones, con la del medio por defecto.
 *
 * Los valores son porcentajes y no píxeles: un `font-size: 18px` en la raíz
 * pisa el tamaño que la persona ya configuró en su navegador. Con un
 * porcentaje las dos preferencias se multiplican en vez de pelearse.
 *
 * Tailwind mide en rem, así que mover la raíz escala tipografía y espaciado.
 * Lo que NO escala son los breakpoints (`md:`), que se miden en px contra el
 * viewport: por eso la revisión de desborde se hace por ancho × tamaño.
 */
enum TextSize: string
{
    /**
     * Duplica a default() porque el `$attributes` del modelo solo admite
     * expresiones constantes.
     */
    public const string DEFAULT = 'normal';

    case Small = 'small';
    case Normal = 'normal';
    case Large = 'large';

    public static function default(): self
    {
        return self::from(self::DEFAULT);
    }

    /**
     * Una cookie la escribe el cliente y puede traer cualquier cosa: un valor
     * desconocido cae en el default y no rompe la página.
     */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::default();
    }

    /**
     * Porcentaje como número (el CSS lo repite en app.css).
     */
    public function scale(): float
    {
        return match ($this) {
            self::Small => 87.5,
            self::Normal => 100.0,
            self::Large => 112.5,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Small => 'Pequeño',
            self::Normal => 'Normal',
            self::Large => 'Grande',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Small => 'Entra más contenido en pantalla.',
            self::Normal => 'El tamaño habitual. Es el que viene puesto.',
            self::Large => 'Más fácil de leer. Entra menos en pantalla.',
        };
    }

    /**
     * Para el selector de Apariencia, de menor a mayor.
     *
     * @return list<array{value: string, label: string, description: string, scale: float}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
            'scale' => $case->scale(),
        ], self::cases());
    }
}
