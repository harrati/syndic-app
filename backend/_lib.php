<?php

declare(strict_types=1);

/**
 * Pure helpers shared by the API: string length, money conversion, UUIDs and
 * base64url encoding. No database, no HTTP, no output — safe to include from a
 * test script or a CLI tool.
 */

/**
 * UTF-8 aware length (mbstring is optional in this PHP build).
 */
function str_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $count = preg_match_all('/./us', $value);

    return $count === false ? strlen($value) : $count;
}

/**
 * Convert a numeric amount (12, "12.50", 12.5) to centimes. Null when invalid.
 */
function amount_to_cents(mixed $value): ?int
{
    if (is_int($value)) {
        return $value * 100;
    }

    if (is_float($value)) {
        if (!is_finite($value)) {
            return null;
        }

        $value = number_format($value, 2, '.', '');
    }

    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);

    // DECIMAL(10,2) ceiling: at most 9 integer digits and 2 decimals.
    if (preg_match('/^(-)?(\d{1,9})(?:[.,](\d{1,2}))?$/', $value, $matches) !== 1) {
        return null;
    }

    $negative = ($matches[1] ?? '') === '-';
    $whole = (int) $matches[2];
    $fraction = str_pad($matches[3] ?? '0', 2, '0');
    $cents = $whole * 100 + (int) $fraction;

    return $negative ? -$cents : $cents;
}

/**
 * Format centimes as a DECIMAL string ("450.00").
 */
function cents_to_decimal(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $absolute = abs($cents);

    return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
}

/**
 * RFC 4122 version 4 UUID.
 */
function uuid4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function base64url_decode(string $value): string
{
    $padded = str_pad(strtr($value, '-_', '+/'), (int) (ceil(strlen($value) / 4) * 4), '=');

    $decoded = base64_decode($padded, true);

    return $decoded === false ? '' : $decoded;
}
