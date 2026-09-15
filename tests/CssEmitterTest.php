<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance\Tests;

use PHPUnit\Framework\TestCase;
use Tangible\WP\Appearance\CssEmitter;

final class CssEmitterTest extends TestCase {
    /**
     * The one thing every Tangible front end says before any theme: read
     * as the site. Zero specificity, so a theme editing typography wins.
     */
    public function testTheFrontEndBaseInheritsTheHostsType(): void {
        $this->assertSame(<<<'CSS'
            :where(.tui-interface) {
              --tui-typography-font-family: inherit;
              --tui-typography-size: inherit;
            }
            CSS, CssEmitter::frontEndBase());
    }

    public function testAnEmptyThemePrintsNothing(): void {
        $this->assertSame('', CssEmitter::css(['version' => 1]));
        $this->assertSame('', CssEmitter::css(['version' => 1, 'color_scheme' => 'dark']));
    }

    /**
     * Intent keys (mode, base, radius_preset) are the panel's; the emitter
     * prints only literal rungs and steps. No colour maths on the server.
     */
    public function testOnlyLiteralValuesAreEmittedNeverIntent(): void {
        $css = CssEmitter::css(['version' => 1,
            'colors' => ['primary' => ['mode' => 'derived', 'base' => '#2942d1']],
            'layout' => ['radius_preset' => 'pill'],
        ]);

        $this->assertSame('', $css);
    }

    /**
     * TUI emits its colour roles at :where(.tui-interface) — 0,0,0 — and
     * its dark roles at :where(.tui-interface)[data-theme="dark"] — 0,1,0.
     * The light override must sit exactly between: equal to the first and
     * later, lower than the second.
     */
    public function testColoursAreEmittedAtZeroSpecificity(): void {
        $css = CssEmitter::css(['version' => 1, 'colors' => [
            'primary' => ['mode' => 'custom', 'ladder' => ['strongest' => '#0b1352', 'base' => '#2942d1', 'subtlest' => '#eef0fb']],
            'danger' => ['ladder' => ['base' => '#dc2626']],
            'neutral' => ['bg_surface' => '#fafafa', 'fg_on_accent' => '#fff'],
            'focus_ring' => '#2563eb',
        ]]);

        $this->assertSame(<<<'CSS'
            :where(.tui-interface) {
              --tui-theme-primary-strongest: #0b1352;
              --tui-theme-primary-base: #2942d1;
              --tui-theme-primary-subtlest: #eef0fb;
              --tui-theme-danger-base: #dc2626;
              --tui-color-bg-surface: #fafafa;
              --tui-color-fg-on-accent: #fff;
              --tui-focus-ring-color: #2563eb;
            }
            CSS, $css);
    }

    /**
     * Layout rides the same zero-specificity selector as the colours (TUI
     * 0.2.21 moved its scale variables to :where() to match the colour
     * roles), as its own block so the two concerns read apart.
     */
    public function testLayoutIsEmittedAtZeroSpecificityAsItsOwnBlock(): void {
        $css = CssEmitter::css(['version' => 1, 'layout' => [
            'spacing_base' => 5,
            'base_font_size' => 18,
            'border_width' => 1.5,
            'border_width_bold' => 3,
            'radius_preset' => 'custom',
            'radius' => ['xs' => 0, 'md' => 6, 'xl' => 20],
        ]]);

        $this->assertSame(<<<'CSS'
            :where(.tui-interface) {
              --tui-spacing-base: 5px;
              --tui-typography-size-multiplier: calc(18px / 16);
              --tui-border-width: 1.5px;
              --tui-border-width-bold: 3px;
              --tui-radius-xs: 0px;
              --tui-radius-md: 6px;
              --tui-radius-xl: 20px;
            }
            CSS, $css);
    }

    public function testADarkMapGoesAtTuisDarkSelectorAfterTheLightBlock(): void {
        $css = CssEmitter::css(['version' => 1,
            'colors' => ['neutral' => ['bg' => '#fff']],
            'layout' => ['spacing_base' => 4],
            'colors_dark' => ['neutral' => ['bg' => '#0b0b0f'], 'focus_ring' => '#93c5fd'],
        ]);

        $this->assertSame(<<<'CSS'
            :where(.tui-interface) {
              --tui-color-bg: #fff;
            }
            :where(.tui-interface) {
              --tui-spacing-base: 4px;
            }
            :where(.tui-interface)[data-theme="dark"] {
              --tui-color-bg: #0b0b0f;
              --tui-focus-ring-color: #93c5fd;
            }
            CSS, $css);
    }

    /**
     * Per-surface branding, reserved now so it costs nothing later: a
     * scope adds one attribute to every selector, so a scoped theme wins
     * the site theme exactly as the site theme wins TUI's defaults.
     */
    public function testAScopeAddsOneAttributeToEverySelector(): void {
        $css = CssEmitter::css(['version' => 1,
            'colors' => ['focus_ring' => '#f59e0b'],
            'layout' => ['border_width' => 2],
            'colors_dark' => ['focus_ring' => '#fcd34d'],
        ], 'course-123');

        $this->assertSame(<<<'CSS'
            :where(.tui-interface[data-tangible-theme="course-123"]) {
              --tui-focus-ring-color: #f59e0b;
            }
            :where(.tui-interface[data-tangible-theme="course-123"]) {
              --tui-border-width: 2px;
            }
            :where(.tui-interface[data-tangible-theme="course-123"])[data-theme="dark"] {
              --tui-focus-ring-color: #fcd34d;
            }
            CSS, $css);
    }

    /**
     * The option can be written by anything that can call update_option(),
     * so what was validated on save is checked again on print: a tampered
     * colour is dropped, a non-number never reaches the px formatter.
     */
    public function testStoredValuesAreReCheckedAtThePrintBoundary(): void {
        $css = CssEmitter::css(['version' => 1,
            'colors' => [
                'primary' => ['ladder' => ['base' => '#2942d1', 'soft' => '#fff; } </style><script>alert(1)</script>', 'loudest' => '#000']],
                'neutral' => ['bg' => 'url(javascript:alert(1))', 'fg' => '#111'],
                'focus_ring' => ['not' => 'a string'],
            ],
            'layout' => ['spacing_base' => '5; } body { display: none', 'border_width' => true, 'radius' => 'round', 'base_font_size' => '18'],
        ]);

        $this->assertSame(<<<'CSS'
            :where(.tui-interface) {
              --tui-theme-primary-base: #2942d1;
              --tui-color-fg: #111;
            }
            :where(.tui-interface) {
              --tui-typography-size-multiplier: calc(18px / 16);
            }
            CSS, $css);
    }

    public function testAScopeThatCouldEscapeTheSelectorIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);

        CssEmitter::css(['version' => 1, 'colors' => ['focus_ring' => '#000']], 'x"] body { display:none } [x="');
    }
}
