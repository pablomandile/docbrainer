<?php

namespace App\Services\Vector;

class VectorMath
{
    /**
     * Normaliza L2 un vector. Con vectores normalizados,
     * la similitud coseno se reduce a un producto punto.
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn (float $v) => $v * $v, $vector)));

        if ($norm == 0.0) {
            return $vector;
        }

        return array_map(fn (float $v) => $v / $norm, $vector);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $count = min(count($a), count($b));

        for ($i = 0; $i < $count; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /**
     * Empaqueta un vector como binario little-endian de floats de 4 bytes.
     *
     * @param  list<float>  $vector
     */
    public static function pack(array $vector): string
    {
        return pack('f*', ...$vector);
    }

    /**
     * @return list<float>
     */
    public static function unpack(string $binary): array
    {
        return array_values(unpack('f*', $binary) ?: []);
    }
}
