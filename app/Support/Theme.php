<?php

namespace App\Support;

/**
 * Resolves a club's chosen primary color into the CSS-variable ramp that
 * Tailwind's `primary` reads. A pick is stored on WebsiteConfig.branding:
 *   - branding.theme   = preset key (e.g. "blue") or "custom"
 *   - branding.primary = "#rrggbb" when theme is "custom"
 */
class Theme
{
    /** Preset definitions straight from config/themes.php. */
    public static function presets(): array
    {
        return config('themes.presets', []);
    }

    /** Shape the presets for the front-end picker (key + name + hex ramp). */
    public static function presetsForFrontend(): array
    {
        return collect(static::presets())
            ->map(fn ($p, $key) => [
                'key' => $key,
                'name' => $p['name'] ?? ucfirst($key),
                'scale' => $p['scale'] ?? [],
            ])
            ->values()
            ->all();
    }

    /** The active preset key (or "custom"), falling back to the default. */
    public static function activeKey(?array $branding): string
    {
        $branding ??= [];
        $key = $branding['theme'] ?? config('themes.default', 'emerald');

        if ($key === 'custom' && static::isValidHex($branding['primary'] ?? null)) {
            return 'custom';
        }

        return isset(static::presets()[$key]) ? $key : config('themes.default', 'emerald');
    }

    /** Active ramp as hex stops: [50 => '#…', …, 950 => '#…']. */
    public static function activeScale(?array $branding): array
    {
        $branding ??= [];
        $key = static::activeKey($branding);

        if ($key === 'custom') {
            return static::generateScale($branding['primary']);
        }

        return static::presets()[$key]['scale'] ?? static::presets()[config('themes.default', 'emerald')]['scale'];
    }

    /** Renders the `--color-primary-*: r g b;` declarations for inlining in <style>. */
    public static function cssVars(?array $branding): string
    {
        $lines = [];
        foreach (static::activeScale($branding) as $stop => $hex) {
            $lines[] = "--color-primary-{$stop}: ".static::hexToTriplet($hex).';';
        }

        return implode(' ', $lines);
    }

    /** The 600 stop as a "#rrggbb" string — handy for <meta theme-color>. */
    public static function themeColor(?array $branding): string
    {
        $scale = static::activeScale($branding);

        return $scale[600] ?? '#059669';
    }

    public static function isValidHex(?string $hex): bool
    {
        return is_string($hex) && preg_match('/^#?[0-9a-fA-F]{6}$/', $hex) === 1;
    }

    /** "#10b981" | "10b981" -> "16 185 129" (space-separated for rgb(var() / a)). */
    public static function hexToTriplet(string $hex): string
    {
        [$r, $g, $b] = static::hexToRgb($hex);

        return "{$r} {$g} {$b}";
    }

    /**
     * Builds a balanced 11-stop ramp from one base color: 500 is the base,
     * lighter stops mix toward white, darker stops toward black.
     */
    public static function generateScale(string $hex): array
    {
        [$r, $g, $b] = static::hexToRgb($hex);

        // Fraction toward white (negative) or black (positive) per stop. 0 = base.
        $mix = [
            50 => -0.95, 100 => -0.90, 200 => -0.78, 300 => -0.62, 400 => -0.32,
            500 => 0.0,
            600 => 0.12, 700 => 0.28, 800 => 0.44, 900 => 0.58, 950 => 0.74,
        ];

        $scale = [];
        foreach ($mix as $stop => $f) {
            if ($f < 0) {
                $t = -$f; // toward white
                $nr = $r + (255 - $r) * $t;
                $ng = $g + (255 - $g) * $t;
                $nb = $b + (255 - $b) * $t;
            } else {
                $t = 1 - $f; // toward black
                $nr = $r * $t;
                $ng = $g * $t;
                $nb = $b * $t;
            }
            $scale[$stop] = sprintf('#%02x%02x%02x', round($nr), round($ng), round($nb));
        }

        return $scale;
    }

    /** @return array{0:int,1:int,2:int} */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
