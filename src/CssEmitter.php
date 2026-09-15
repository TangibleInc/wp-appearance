<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance;

/**
 * A stored theme, as the CSS custom properties that apply it.
 *
 * The output is attached to the shared `tangible-ui` stylesheet with
 * wp_add_inline_style(), so it prints immediately after TUI's own token
 * blocks. Winning against those is then a question of specificity and
 * source order, and TUI is not uniform about specificity:
 *
 * - Colour roles are emitted at `:where(.tui-interface)` — 0,0,0. Dark
 *   mode re-emits them at `:where(.tui-interface)[data-theme="dark"]` —
 *   0,1,0, the attribute sitting outside the :where(). So the light
 *   override goes at `:where(.tui-interface)` too: equal to the light
 *   roles and later, so it wins them; lower than the dark roles, so dark
 *   mode survives. A dark override, when a theme carries one, goes at
 *   TUI's own dark selector and beats the light block the same way.
 *
 * - Layout — the spacing base, the font-size multiplier, border widths,
 *   the radius scale — is emitted at `:where(.tui-interface)` too, as a
 *   second block so a reader can see the two concerns apart. Until
 *   @tangible/ui 0.2.21 the scale variables sat at bare `.tui-interface`
 *   (0,1,0) and a zero-specificity override lost to them; 0.2.21 moved
 *   them to :where() to match the colour roles, which is what lets one
 *   uniform theme block after the sheet override everything.
 *
 * The rule this imposes on plugin stylesheets: never set TUI variables
 * at raised specificity. They print after `tangible-ui` and would beat
 * both the site's theme and TUI's dark mode.
 *
 * Values are re-checked here, not only when saved: the option can be
 * written by anything that can call update_option(), and what fails the
 * colour grammar or is not a number is dropped rather than printed.
 *
 * `$scope` reserves per-surface branding. Site level writes the bare
 * selectors above; a scope writes `[data-tangible-theme="<scope>"]` onto
 * the interface, one attribute higher, so a scoped theme wins the site
 * theme by the same mechanism the site theme wins the defaults.
 */
final class CssEmitter {
    private const PREFIX = '--tui-';

    /**
     * @param array<string, mixed> $theme a stored theme (Appearance::load())
     * @param string|null $scope a `data-tangible-theme` value, or null for the site
     *
     * @return string CSS, or '' when the theme has nothing to say
     */
    public static function css(array $theme, ?string $scope = null): string {
        $interface = '.tui-interface'.self::scopeSelector($scope);
        $blocks = [];

        $light = self::colorDeclarations($theme['colors'] ?? []);
        if ([] !== $light) {
            $blocks[] = self::block(":where({$interface})", $light);
        }

        $layout = self::layoutDeclarations($theme['layout'] ?? []);
        if ([] !== $layout) {
            $blocks[] = self::block(":where({$interface})", $layout);
        }

        $dark = self::colorDeclarations($theme['colors_dark'] ?? []);
        if ([] !== $dark) {
            $blocks[] = self::block(":where({$interface})[data-theme=\"dark\"]", $dark);
        }

        return implode("\n", $blocks);
    }

    /**
     * @param array<string, mixed> $colors
     *
     * @return array<string, string> property => value
     */
    private static function colorDeclarations(array $colors): array {
        $out = [];

        foreach (Appearance::LADDERS as $name) {
            $ladder = $colors[$name]['ladder'] ?? [];
            foreach (Appearance::RUNGS as $rung) {
                if (Appearance::isColor($ladder[$rung] ?? null)) {
                    $out[self::PREFIX."theme-{$name}-{$rung}"] = $ladder[$rung];
                }
            }
        }

        $neutral = $colors['neutral'] ?? [];
        foreach (Appearance::NEUTRALS as $role) {
            if (Appearance::isColor($neutral[$role] ?? null)) {
                $out[self::PREFIX.'color-'.str_replace('_', '-', $role)] = $neutral[$role];
            }
        }

        if (Appearance::isColor($colors['focus_ring'] ?? null)) {
            $out[self::PREFIX.'focus-ring-color'] = $colors['focus_ring'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $layout
     *
     * @return array<string, string>
     */
    private static function layoutDeclarations(array $layout): array {
        $out = [];

        $spacing = self::px($layout['spacing_base'] ?? null);
        if (null !== $spacing) {
            $out[self::PREFIX.'spacing-base'] = $spacing;
        }

        // TUI's size aliases are `calc(<px> * multiplier)` with the
        // multiplier defaulting to calc(1rem / 16): sixteen CSS pixels of
        // design size per rem. A base of 18 therefore means 18px per 16.
        $fontSize = self::px($layout['base_font_size'] ?? null);
        if (null !== $fontSize) {
            $out[self::PREFIX.'typography-size-multiplier'] = "calc({$fontSize} / 16)";
        }

        $border = self::px($layout['border_width'] ?? null);
        if (null !== $border) {
            $out[self::PREFIX.'border-width'] = $border;
        }

        $borderBold = self::px($layout['border_width_bold'] ?? null);
        if (null !== $borderBold) {
            $out[self::PREFIX.'border-width-bold'] = $borderBold;
        }

        $radius = \is_array($layout['radius'] ?? null) ? $layout['radius'] : [];
        foreach (Appearance::RADIUS_STEPS as $step) {
            $value = self::px($radius[$step] ?? null);
            if (null !== $value) {
                $out[self::PREFIX."radius-{$step}"] = $value;
            }
        }

        return $out;
    }

    private static function scopeSelector(?string $scope): string {
        if (null === $scope || '' === $scope) {
            return '';
        }

        if (1 !== preg_match('/^[a-z0-9_-]+$/i', $scope)) {
            throw new \InvalidArgumentException("Theme scope \"{$scope}\" must be [a-z0-9_-]+");
        }

        return "[data-tangible-theme=\"{$scope}\"]";
    }

    /**
     * @param array<string, string> $declarations
     */
    private static function block(string $selector, array $declarations): string {
        $lines = [];
        foreach ($declarations as $property => $value) {
            $lines[] = "  {$property}: {$value};";
        }

        return $selector." {\n".implode("\n", $lines)."\n}";
    }

    /** A pixel length from a stored number, or null for anything that is not one. */
    private static function px(mixed $value): ?string {
        if (\is_bool($value) || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;
        $text = floor($number) === $number
            ? (string) (int) $number
            : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');

        return $text.'px';
    }
}
