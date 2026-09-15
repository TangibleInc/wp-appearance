<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance\Tests;

use PHPUnit\Framework\TestCase;
use Tangible\WP\Appearance\Appearance;
use Tangible\WP\Appearance\InvalidAppearance;

final class AppearanceTest extends TestCase {
    protected function setUp(): void {
        unset($GLOBALS['__wp_test_options'], $GLOBALS['__wp_test_option_autoload']);
    }

    protected function tearDown(): void {
        unset($GLOBALS['__wp_test_options'], $GLOBALS['__wp_test_option_autoload']);
    }

    public function testAnEmptySiteHasAnEmptyVersionedTheme(): void {
        $this->assertSame(['version' => 1], Appearance::load());
    }

    public function testACorruptOptionReadsAsEmpty(): void {
        $GLOBALS['__wp_test_options'][Appearance::OPTION] = 'not a theme';

        $this->assertSame(['version' => 1], Appearance::load());
    }

    public function testTheProposalsExampleRoundTrips(): void {
        $theme = [
            'version' => 1,
            'color_scheme' => 'light',
            'colors' => [
                'primary' => ['mode' => 'derived', 'base' => '#2942d1', 'ladder' => ['strongest' => '#0b1352', 'base' => '#2942d1']],
                'danger' => ['mode' => 'custom', 'ladder' => ['strongest' => '#450a0a', 'subtlest' => '#fef2f2']],
                'neutral' => ['bg_surface' => '#fafafa', 'border' => '#e5e7eb'],
                'focus_ring' => '#2563eb',
            ],
            'layout' => [
                'spacing_base' => 4,
                'base_font_size' => 16,
                'border_width' => 1,
                'border_width_bold' => 2,
                'radius_preset' => 'rounded',
                'radius' => ['xs' => 2, 'sm' => 4, 'md' => 6, 'lg' => 12, 'xl' => 16],
            ],
        ];

        $stored = Appearance::save($theme);

        $this->assertSame($theme, $stored);
        $this->assertSame($theme, Appearance::load());
        $this->assertTrue($GLOBALS['__wp_test_option_autoload'][Appearance::OPTION], 'the option is autoloaded — it is read on every front-end request');
    }

    /**
     * Sparse means sparse: nothing the schema does not know, nothing
     * empty, and `version` is ours to set, not the client's.
     */
    public function testUnknownKeysAndEmptyBranchesArePrunedAndVersionIsPinned(): void {
        $stored = Appearance::normalize([
            'version' => 99,
            'mystery' => true,
            'colors' => [
                'primary' => ['mode' => 'derived'],
                'neutral' => [],
                'octarine' => ['ladder' => ['base' => '#000']],
            ],
            'layout' => ['radius' => [], 'gutter' => 12],
        ]);

        $this->assertSame(['version' => 1], $stored);
    }

    public function testNumbersArriveAsNumbersWhateverTheTransportSent(): void {
        $stored = Appearance::normalize(['layout' => [
            'spacing_base' => '5',
            'base_font_size' => 17.5,
            'radius' => ['md' => '8.0'],
        ]]);

        $this->assertSame(['spacing_base' => 5, 'base_font_size' => 17.5, 'radius' => ['md' => 8]], $stored['layout']);
    }

    public function testFunctionalColoursAreAcceptedForTheOverlay(): void {
        $stored = Appearance::normalize(['colors' => ['neutral' => [
            'bg_overlay' => 'rgba(0, 0, 0, 0.6)',
            'bg' => 'oklch(98% 0.01 260)',
            'bg_elevated' => 'transparent',
        ]]]);

        $this->assertSame(
            ['bg' => 'oklch(98% 0.01 260)', 'bg_elevated' => 'transparent', 'bg_overlay' => 'rgba(0, 0, 0, 0.6)'],
            $stored['colors']['neutral'],
        );
    }

    /**
     * The values go into a stylesheet verbatim; the grammar is the escape.
     */
    public function testAnythingThatCouldEscapeTheDeclarationIsRefused(): void {
        $this->expectException(InvalidAppearance::class);

        Appearance::normalize(['colors' => ['focus_ring' => '#fff; } body { display: none']]);
    }

    public function testEveryProblemIsReportedAtOnceByPath(): void {
        try {
            Appearance::normalize([
                'color_scheme' => 'sepia',
                'colors' => [
                    'primary' => ['mode' => 'derived', 'ladder' => ['base' => '#2942d1', 'loudest' => '#000']],
                    'success' => ['mode' => 'vivid', 'base' => 'green'],
                    'neutral' => ['fg' => 12],
                ],
                'layout' => [
                    'spacing_base' => 0,
                    'base_font_size' => 'large',
                    'radius_preset' => 'blobby',
                    'radius' => ['xs' => -1, 'xl' => 10000],
                ],
            ]);
            $this->fail('expected InvalidAppearance');
        } catch (InvalidAppearance $e) {
            $this->assertSame([
                'color_scheme',
                'colors.primary.base',
                'colors.success.mode',
                'colors.success.base',
                'colors.neutral.fg',
                'layout.spacing_base',
                'layout.base_font_size',
                'layout.radius_preset',
                'layout.radius.xs',
                'layout.radius.xl',
            ], array_keys($e->errors));
            $this->assertSame('is required when mode is derived', $e->errors['colors.primary.base']);
            $this->assertSame('must be between 1 and 16', $e->errors['layout.spacing_base']);
        }
    }

    public function testACustomLadderNeedsNoBase(): void {
        $stored = Appearance::normalize(['colors' => ['danger' => [
            'mode' => 'custom',
            'ladder' => ['base' => '#dc2626'],
        ]]]);

        $this->assertSame(['mode' => 'custom', 'ladder' => ['base' => '#dc2626']], $stored['colors']['danger']);
    }

    public function testTheDarkSlotAcceptsTheSameShape(): void {
        $stored = Appearance::normalize([
            'color_scheme' => 'auto',
            'colors_dark' => ['neutral' => ['bg' => '#0b0b0f'], 'focus_ring' => '#93c5fd'],
        ]);

        $this->assertSame('auto', $stored['color_scheme']);
        $this->assertSame(['neutral' => ['bg' => '#0b0b0f'], 'focus_ring' => '#93c5fd'], $stored['colors_dark']);
        $this->assertArrayNotHasKey('colors', $stored);
    }
}
