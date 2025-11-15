<?php

declare(strict_types=1);

namespace App\Support;

final class MemoCspDefaults
{
    /**
     * @return array<string, string>
     */
    public static function directives(): array
    {
        return [
            'default-src' => "'self' cdn.jsdelivr.net https://cdnjs.cloudflare.com https://static.cloudflareinsights.com",
            'img-src' => "'self' data: blob: https://tile.openstreetmap.org https://*.basemaps.cartocdn.com https://stamen-tiles.a.ssl.fastly.net https://stamen-tiles-b.a.ssl.fastly.net https://stamen-tiles-c.a.ssl.fastly.net https://stamen-tiles-d.a.ssl.fastly.net",
            'style-src' => "'self' 'unsafe-inline' cdn.jsdelivr.net https://fonts.googleapis.com https://cdnjs.cloudflare.com",
            'font-src' => "'self' data: https://fonts.gstatic.com",
            'script-src' => "'self' 'unsafe-inline' cdn.jsdelivr.net https://cdnjs.cloudflare.com https://static.cloudflareinsights.com",
            'connect-src' => "'self' https://fonts.gstatic.com cdn.jsdelivr.net https://cdnjs.cloudflare.com https://tile.openstreetmap.org https://*.basemaps.cartocdn.com https://stamen-tiles.a.ssl.fastly.net https://stamen-tiles-b.a.ssl.fastly.net https://stamen-tiles-c.a.ssl.fastly.net https://stamen-tiles-d.a.ssl.fastly.net https://static.cloudflareinsights.com",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'frame-ancestors' => "'self'",
        ];
    }

    /**
     * @return list<string>
     */
    public static function headerDirectives(): array
    {
        $result = [];
        foreach (self::directives() as $directive => $value) {
            $directive = trim($directive);
            $value = trim($value);
            if ($directive === '' || $value === '') {
                continue;
            }
            $result[] = $directive . ' ' . $value;
        }

        return $result;
    }
}
