<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Patch 62: the short pass a browser shows the video relay (MediaMTX) to publish or watch one
 * camera. Signed with CAMERAS_SECRET; the relay asks the mapper (relay-auth) whether it's good.
 * Format: base64url(json payload) "." base64url(hmac-sha256).
 */
final class CameraPass
{
    public const array ACTIONS = ['publish', 'read'];

    public static function enabled(): bool
    {
        return is_string(config('cameras.secret')) && config('cameras.secret') !== ''
            && is_string(config('cameras.rtc_url')) && config('cameras.rtc_url') !== '';
    }

    public static function make(string $path, string $action, string $who, ?int $now = null): string
    {
        $payload = json_encode([
            'p' => $path,
            'a' => $action,
            'w' => $who,
            'e' => ($now ?? time()) + (int) config('cameras.pass_seconds', 120),
        ], JSON_THROW_ON_ERROR);
        $body = self::encode($payload);

        return $body.'.'.self::encode(hash_hmac('sha256', $body, self::secret(), true));
    }

    /**
     * The pass's holder when it is good for this path and action, else null.
     */
    public static function check(string $pass, string $path, string $action, ?int $now = null): ?string
    {
        if (! self::enabled() || substr_count($pass, '.') !== 1) {
            return null;
        }
        [$body, $signature] = explode('.', $pass);
        if (! hash_equals(self::encode(hash_hmac('sha256', $body, self::secret(), true)), $signature)) {
            return null;
        }
        $data = json_decode(self::decode($body), true);
        if (! is_array($data) || ($data['p'] ?? null) !== $path || ($data['a'] ?? null) !== $action || ! is_int($data['e'] ?? null) || $data['e'] < ($now ?? time())) {
            return null;
        }

        return is_string($data['w'] ?? null) ? $data['w'] : null;
    }

    private static function secret(): string
    {
        return (string) config('cameras.secret');
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), true);
    }
}
