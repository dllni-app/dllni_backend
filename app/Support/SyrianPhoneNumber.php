<?php

declare(strict_types=1);

namespace App\Support;

/** Canonicalizes Syrian phone formats while leaving international numbers unchanged. */
final class SyrianPhoneNumber
{
    public static function normalize(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }
        $trimmed = trim($number);
        $digits = preg_replace('/[^0-9]/', '', $trimmed);
        if (preg_match('/^(?:00963|963|0)?(9[0-9]{8})$/', $digits, $matches)) {
            return '+963'.$matches[1];
        }
        return $trimmed;
    }

    /** @return list<string> Legacy stored formats accepted for existing accounts. */
    public static function aliases(string $number): array
    {
        $canonical = self::normalize($number) ?? '';
        if (preg_match('/^\+963(9[0-9]{8})$/', $canonical, $matches)) {
            $local = $matches[1];
            return array_values(array_unique([$canonical, '0'.$local, $local, '963'.$local, $number]));
        }
        return array_values(array_unique([$canonical, $number]));
    }
}
