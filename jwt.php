<?php
declare(strict_types=1);

/**
 * Minimal HS256 JWT implementation for the mobile API.
 * Requires JWT_SECRET (and optionally JWT_EXPIRE) from config.php.
 */

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder > 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    return $decoded === false ? '' : $decoded;
}

function createJWT(array $payload): string
{
    $header = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_UNICODE));
    $payloadB64 = base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $signature = base64UrlEncode(hash_hmac('sha256', $header . '.' . $payloadB64, JWT_SECRET, true));

    return $header . '.' . $payloadB64 . '.' . $signature;
}

function verifyJWT(string $token): array|false
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }

    [$headerB64, $payloadB64, $signatureB64] = $parts;

    $expected = base64UrlEncode(hash_hmac('sha256', $headerB64 . '.' . $payloadB64, JWT_SECRET, true));
    if (!hash_equals($expected, $signatureB64)) {
        return false;
    }

    $payloadJson = base64UrlDecode($payloadB64);
    if ($payloadJson === '') {
        return false;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return false;
    }

    if (isset($payload['exp']) && (int)$payload['exp'] < time()) {
        return false;
    }

    return $payload;
}
