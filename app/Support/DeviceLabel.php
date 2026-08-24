<?php

namespace App\Support;

/**
 * Turns a raw User-Agent string into a short "OS Browser" label for the
 * Sessions & Devices UI (e.g. "Mac Safari", "iPhone Chrome"). Heuristic,
 * not exhaustive — good enough for a human-readable device list.
 */
class DeviceLabel
{
    public static function parse(?string $userAgent): string
    {
        if (!$userAgent) {
            return 'Unknown device';
        }

        $ua = $userAgent;

        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS X') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'CriOS') => 'Chrome', // Chrome on iOS
            str_contains($ua, 'FxiOS') => 'Firefox', // Firefox on iOS
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            // Safari's UA also contains "Chrome"/"CriOS" for other browsers,
            // so it must be checked last, and only counts if not already
            // matched by one of the Chromium-based browsers above.
            str_contains($ua, 'Safari/') => 'Safari',
            default => null,
        };

        $label = trim(($os ?? '') . ' ' . ($browser ?? ''));

        return $label !== '' ? $label : 'Unknown device';
    }
}
