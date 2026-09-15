<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance;

/**
 * The site's Tangible theme: which TUI tokens differ from the defaults.
 *
 * Stored SPARSE — only the keys a site owner has changed. Two things
 * fall out of that. A TUI upgrade that improves a default flows through
 * instead of being frozen into the option at whatever version was
 * installed; and the per-row reset control in the settings panel is
 * literally "delete this key", which is why it can be a plain icon with
 * no confirmation. PHP never knows what the defaults ARE: it prints the
 * deltas as CSS custom properties (CssEmitter) and TUI's own stylesheet
 * supplies everything else.
 *
 * Colour ladders and the radius steps are caches of a derivation the
 * settings panel performs — `mode`/`base` and `radius_preset` record the
 * author's intent so the panel can re-derive, but the emitter prints the
 * literal rungs and steps only. No colour maths on the server.
 *
 * The stored shape, all optional except `version`:
 *
 *   version        1
 *   color_scheme   light | auto | dark — which data-theme plugins stamp
 *                  on their .tui-interface roots. Reserved: nothing reads
 *                  it yet.
 *   colors         { primary|secondary|info|success|warning|danger:
 *                      { mode: derived|custom, base?: colour,
 *                        ladder: { strongest … subtlest: colour } },
 *                    neutral: { bg, bg_surface, …, border, divider: colour },
 *                    focus_ring: colour }
 *   colors_dark    the same shape, for retheming TUI's dark palette.
 *                  Reserved: accepted and emitted, no UI writes it yet.
 *   layout         { spacing_base, base_font_size, border_width,
 *                    border_width_bold: px number,
 *                    radius_preset: squared|rounded|pill|custom,
 *                    radius: { xs, sm, md, lg, xl: px number } }
 *
 * snake_case throughout: this is the REST shape too.
 */
final class Appearance {
    public const OPTION = 'tangible_appearance';
    public const VERSION = 1;

    public const LADDERS = ['primary', 'secondary', 'info', 'success', 'warning', 'danger'];
    public const RUNGS = ['strongest', 'stronger', 'strong', 'base', 'soft', 'subtle', 'subtlest'];
    public const NEUTRALS = [
        'bg', 'bg_surface', 'bg_muted', 'bg_elevated', 'bg_inverted', 'bg_overlay',
        'fg', 'fg_secondary', 'fg_muted', 'fg_on_accent', 'fg_inverted',
        'border', 'divider',
    ];
    public const RADIUS_STEPS = ['xs', 'sm', 'md', 'lg', 'xl'];
    public const COLOR_SCHEMES = ['light', 'auto', 'dark'];
    public const LADDER_MODES = ['derived', 'custom'];
    public const RADIUS_PRESETS = ['squared', 'rounded', 'pill', 'custom'];

    /** Pixel ranges: [min, max]. Wide on purpose — taste is the panel's job, sanity is ours. */
    private const PX_RANGES = [
        'spacing_base' => [1, 16],
        'base_font_size' => [8, 48],
        'border_width' => [0, 8],
        'border_width_bold' => [0, 12],
        'radius' => [0, 64],
    ];

    /**
     * A CSS colour we are willing to print verbatim: hex, or one of the
     * functional notations with nothing but numbers, separators and
     * units inside the parentheses. Deliberately narrower than CSS — the
     * value goes into a stylesheet unescaped, so the grammar IS the escape.
     */
    private const COLOR_PATTERN = '/^(?:#[0-9a-f]{3}(?:[0-9a-f]{1,5})?|(?:rgba?|hsla?|oklch|oklab|hwb|lab|lch)\([0-9a-z .,%\/+-]+\)|transparent)$/i';

    /**
     * The stored theme. Always carries `version`; an empty theme is just
     * that.
     *
     * @return array<string, mixed>
     */
    public static function load(): array {
        $stored = get_option(self::OPTION, []);

        return \is_array($stored)
            ? ['version' => self::VERSION] + $stored
            : ['version' => self::VERSION];
    }

    /**
     * Validate, strip to the sparse shape, and store.
     *
     * The whole theme is replaced: the panel owns the draft and sends
     * its complete sparse state, so "reset this row" is simply the row
     * being absent from what arrives.
     *
     * @param array<string, mixed> $raw
     *
     * @throws InvalidAppearance
     *
     * @return array<string, mixed> what was stored
     */
    public static function save(array $raw): array {
        $theme = self::normalize($raw);
        update_option(self::OPTION, $theme, true);

        return $theme;
    }

    /**
     * Validate and reduce a theme to its sparse stored shape: unknown
     * keys dropped, empty branches pruned, numbers as numbers, `version`
     * pinned. Every problem is collected before throwing so the caller
     * can report them all at once.
     *
     * @param array<string, mixed> $raw
     *
     * @throws InvalidAppearance
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array {
        $errors = [];
        $theme = ['version' => self::VERSION];

        if (\array_key_exists('color_scheme', $raw) && null !== $raw['color_scheme']) {
            if (\in_array($raw['color_scheme'], self::COLOR_SCHEMES, true)) {
                $theme['color_scheme'] = $raw['color_scheme'];
            } else {
                $errors['color_scheme'] = 'must be one of '.implode(', ', self::COLOR_SCHEMES);
            }
        }

        foreach (['colors', 'colors_dark'] as $key) {
            if (isset($raw[$key])) {
                $colors = self::normalizeColors($raw[$key], $key, $errors);
                if ([] !== $colors) {
                    $theme[$key] = $colors;
                }
            }
        }

        if (isset($raw['layout'])) {
            $layout = self::normalizeLayout($raw['layout'], $errors);
            if ([] !== $layout) {
                $theme['layout'] = $layout;
            }
        }

        if ([] !== $errors) {
            throw new InvalidAppearance($errors);
        }

        return $theme;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private static function normalizeColors(mixed $raw, string $path, array &$errors): array {
        if (!\is_array($raw)) {
            $errors[$path] = 'must be an object';

            return [];
        }

        $out = [];

        foreach (self::LADDERS as $name) {
            if (!isset($raw[$name])) {
                continue;
            }
            $ladder = self::normalizeLadder($raw[$name], "{$path}.{$name}", $errors);
            if ([] !== $ladder) {
                $out[$name] = $ladder;
            }
        }

        if (isset($raw['neutral'])) {
            if (!\is_array($raw['neutral'])) {
                $errors["{$path}.neutral"] = 'must be an object';
            } else {
                $neutral = [];
                foreach (self::NEUTRALS as $role) {
                    if (isset($raw['neutral'][$role])) {
                        $color = self::color($raw['neutral'][$role], "{$path}.neutral.{$role}", $errors);
                        if (null !== $color) {
                            $neutral[$role] = $color;
                        }
                    }
                }
                if ([] !== $neutral) {
                    $out['neutral'] = $neutral;
                }
            }
        }

        if (isset($raw['focus_ring'])) {
            $color = self::color($raw['focus_ring'], "{$path}.focus_ring", $errors);
            if (null !== $color) {
                $out['focus_ring'] = $color;
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private static function normalizeLadder(mixed $raw, string $path, array &$errors): array {
        if (!\is_array($raw)) {
            $errors[$path] = 'must be an object';

            return [];
        }

        $out = [];

        if (isset($raw['mode'])) {
            if (\in_array($raw['mode'], self::LADDER_MODES, true)) {
                $out['mode'] = $raw['mode'];
            } else {
                $errors["{$path}.mode"] = 'must be one of '.implode(', ', self::LADDER_MODES);
            }
        }

        if (isset($raw['base'])) {
            $color = self::color($raw['base'], "{$path}.base", $errors);
            if (null !== $color) {
                $out['base'] = $color;
            }
        }

        if (isset($raw['ladder'])) {
            if (!\is_array($raw['ladder'])) {
                $errors["{$path}.ladder"] = 'must be an object';
            } else {
                $rungs = [];
                foreach (self::RUNGS as $rung) {
                    if (isset($raw['ladder'][$rung])) {
                        $color = self::color($raw['ladder'][$rung], "{$path}.ladder.{$rung}", $errors);
                        if (null !== $color) {
                            $rungs[$rung] = $color;
                        }
                    }
                }
                if ([] !== $rungs) {
                    $out['ladder'] = $rungs;
                }
            }
        }

        // Intent without content is not a theme delta: a ladder that
        // neither sets a base nor any rung has nothing to say.
        if (!isset($out['base']) && !isset($out['ladder'])) {
            return [];
        }

        if ('derived' === ($out['mode'] ?? 'derived') && !isset($out['base'])) {
            $errors["{$path}.base"] = 'is required when mode is derived';
        }

        return $out;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, mixed>
     */
    private static function normalizeLayout(mixed $raw, array &$errors): array {
        if (!\is_array($raw)) {
            $errors['layout'] = 'must be an object';

            return [];
        }

        $out = [];

        foreach (['spacing_base', 'base_font_size', 'border_width', 'border_width_bold'] as $key) {
            if (isset($raw[$key])) {
                $px = self::px($raw[$key], self::PX_RANGES[$key], "layout.{$key}", $errors);
                if (null !== $px) {
                    $out[$key] = $px;
                }
            }
        }

        if (isset($raw['radius_preset'])) {
            if (\in_array($raw['radius_preset'], self::RADIUS_PRESETS, true)) {
                $out['radius_preset'] = $raw['radius_preset'];
            } else {
                $errors['layout.radius_preset'] = 'must be one of '.implode(', ', self::RADIUS_PRESETS);
            }
        }

        if (isset($raw['radius'])) {
            if (!\is_array($raw['radius'])) {
                $errors['layout.radius'] = 'must be an object';
            } else {
                $radius = [];
                foreach (self::RADIUS_STEPS as $step) {
                    if (isset($raw['radius'][$step])) {
                        $px = self::px($raw['radius'][$step], self::PX_RANGES['radius'], "layout.radius.{$step}", $errors);
                        if (null !== $px) {
                            $radius[$step] = $px;
                        }
                    }
                }
                if ([] !== $radius) {
                    $out['radius'] = $radius;
                }
            }
        }

        return $out;
    }

    /**
     * Whether a value is a colour we are willing to print verbatim. The
     * write path validates with it; the emitter re-checks with it, since
     * the option can be written by anything that can call update_option().
     */
    public static function isColor(mixed $value): bool {
        return \is_string($value) && 1 === preg_match(self::COLOR_PATTERN, $value);
    }

    /**
     * @param array<string, string> $errors
     */
    private static function color(mixed $value, string $path, array &$errors): ?string {
        $trimmed = \is_string($value) ? trim($value) : $value;
        if (self::isColor($trimmed)) {
            return $trimmed;
        }

        $errors[$path] = 'must be a hex or functional CSS colour';

        return null;
    }

    /**
     * @param array{0: int, 1: int} $range
     * @param array<string, string> $errors
     */
    private static function px(mixed $value, array $range, string $path, array &$errors): int|float|null {
        if (\is_bool($value) || !is_numeric($value)) {
            $errors[$path] = 'must be a number of pixels';

            return null;
        }

        $number = (float) $value;
        [$min, $max] = $range;
        if ($number < $min || $number > $max) {
            $errors[$path] = "must be between {$min} and {$max}";

            return null;
        }

        return floor($number) === $number ? (int) $number : $number;
    }
}
