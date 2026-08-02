<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `public/lcars.css` is the original design system. Nothing loads it any more —
 * Phase 0 mapped its four brand colours onto the Filament palette and copied
 * `.lcars-log` into `resources/css/filament/admin/theme.css`, so by Phase 8 it
 * was, strictly, an unreferenced file.
 *
 * It was kept anyway, and this is why: it is the source those copies were taken
 * from, and the theme copy HARDCODES two values that lcars.css declares as
 * custom properties. Equivalent today only because `--color-log-bg` and
 * `--color-log-text` are defined once in `:root` and are not overridden by the
 * `[data-theme="dark"]` or `prefers-color-scheme` blocks. Add a dark override
 * there and the deploy log viewer silently stops matching the design system.
 *
 * So the file is not decoration and not dead weight: it is the thing this test
 * compares against. Deleting it fails here rather than quietly stranding the
 * provenance.
 */
class LcarsThemeTest extends TestCase
{
    private const LCARS = __DIR__.'/../../public/lcars.css';

    private const THEME = __DIR__.'/../../resources/css/filament/admin/theme.css';

    public function test_the_filament_themes_log_colours_still_match_the_lcars_source(): void
    {
        $lcars = $this->read(self::LCARS);
        $theme = $this->read(self::THEME);

        foreach (['--color-log-bg' => 'background', '--color-log-text' => 'color'] as $variable => $property) {
            $hex = $this->customProperty($lcars, $variable);

            $this->assertMatchesRegularExpression(
                '/\.lcars-log\s*\{[^}]*\b'.$property.':\s*'.preg_quote($hex, '/').'\s*;/i',
                $theme,
                "theme.css's .lcars-log {$property} has drifted from lcars.css's {$variable} ({$hex}).",
            );
        }
    }

    public function test_the_log_colours_are_declared_exactly_once_so_the_hardcoded_copies_stay_equivalent(): void
    {
        $lcars = $this->read(self::LCARS);

        // A second declaration means a media-query or [data-theme] override,
        // which the theme's hardcoded copies cannot follow.
        foreach (['--color-log-bg', '--color-log-text'] as $variable) {
            $this->assertSame(
                1,
                preg_match_all('/'.preg_quote($variable, '/').':/', $lcars),
                "{$variable} is declared more than once in lcars.css; theme.css copies a single fixed value "
                .'and would no longer track it.',
            );
        }
    }

    public function test_the_panel_palette_is_the_lcars_palette(): void
    {
        // The four brand colours Phase 0 mapped onto Filament's primary /
        // danger / info / warning. Same drift risk, same fix.
        $lcars = $this->read(self::LCARS);
        $provider = $this->read(__DIR__.'/../../app/Providers/Filament/AdminPanelProvider.php');

        foreach ([
            'primary' => '--color-brand',
            'danger' => '--color-command',
            'info' => '--color-sciences',
            'warning' => '--color-ops',
        ] as $role => $variable) {
            $hex = $this->customProperty($lcars, $variable);

            $this->assertStringContainsString(
                "'{$role}' => Color::hex('{$hex}')",
                $provider,
                "The panel's {$role} colour no longer matches lcars.css's {$variable}.",
            );
        }
    }

    private function customProperty(string $css, string $variable): string
    {
        preg_match('/'.preg_quote($variable, '/').':\s*(#[0-9A-Fa-f]{3,8})\s*;/', $css, $match);

        $this->assertNotEmpty($match, "lcars.css no longer declares {$variable}.");

        return strtoupper($match[1]);
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);

        $this->assertIsString($contents, "Missing file: {$path}");

        return $contents;
    }
}
