<?php

namespace App\Support;

class IdempotencyHasher
{
    /**
     * @param  mixed  $payload
     */
    public static function hash(mixed $payload): string
    {
        $normalized = self::normalize($payload);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $json);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::normalize($item), $value);
        }

        ksort($value);

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalize($item);
        }

        return $normalized;
    }
}
