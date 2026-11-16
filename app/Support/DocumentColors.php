<?php

namespace App\Support;

use App\Services\SettingsService;

class DocumentColors
{
    public const HEADER = '#2F9FD4';

    public const ACCENT = '#E21878';

    /**
     * @return array{header: string, accent: string, tint: string}
     */
    public static function forCompany(?int $companyId): array
    {
        $header = self::HEADER;
        $accent = self::ACCENT;

        if ($companyId) {
            $settings = app(SettingsService::class);
            $header = self::hex($settings->get($companyId, 'document_header_color', self::HEADER), self::HEADER);
            $accent = self::hex($settings->get($companyId, 'document_accent_color', self::ACCENT), self::ACCENT);
        }

        return [
            'header' => $header,
            'accent' => $accent,
            'tint' => self::tint($header),
        ];
    }

    public static function hex($value, string $fallback): string
    {
        $value = strtoupper(trim((string) $value));

        return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : $fallback;
    }

    public static function tint(string $hex): string
    {
        $hex = ltrim(self::hex($hex, self::HEADER), '#');
        $mix = function (int $channel): int {
            return (int) round($channel + (255 - $channel) * 0.82);
        };

        return sprintf(
            '#%02X%02X%02X',
            $mix(hexdec(substr($hex, 0, 2))),
            $mix(hexdec(substr($hex, 2, 2))),
            $mix(hexdec(substr($hex, 4, 2)))
        );
    }
}
