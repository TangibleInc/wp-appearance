<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance\Tests;

use PHPUnit\Framework\TestCase;
use Tangible\WP\Appearance\TuiOffer;
use Tangible\WP\Appearance\TuiProfile;

final class TuiOfferTest extends TestCase {
    public function testAFullBuildBeatsASubsetEvenWhenTheSubsetIsNewer(): void {
        $full = self::offer(TuiProfile::Full, '0.1.0');
        $subset = self::offer(TuiProfile::Subset, '9.9.9');

        $this->assertTrue($full->beats($subset));
        $this->assertFalse($subset->beats($full));
    }

    public function testBetweenEqualProfilesTheNewerTuiWins(): void {
        $older = self::offer(TuiProfile::Full, '0.2.19');
        $newer = self::offer(TuiProfile::Full, '0.2.20');

        $this->assertTrue($newer->beats($older));
        $this->assertFalse($older->beats($newer));
    }

    public function testVersionsCompareAsSemverNotAsStrings(): void {
        $tenth = self::offer(TuiProfile::Full, '0.10.0');
        $ninth = self::offer(TuiProfile::Full, '0.9.0');

        $this->assertTrue($tenth->beats($ninth));
    }

    /**
     * A dead heat is not a win: whichever was offered first stays, and
     * that only holds if neither side claims to beat the other.
     */
    public function testADeadHeatIsNotAWinInEitherDirection(): void {
        $a = self::offer(TuiProfile::Full, '0.2.19');
        $b = self::offer(TuiProfile::Full, '0.2.19');

        $this->assertFalse($a->beats($b));
        $this->assertFalse($b->beats($a));
    }

    /**
     * Build output predating the meta sidecar offers itself as '0' — the
     * oldest possible TUI — so any build that recorded its version wins.
     */
    public function testAnUnrecordedVersionLosesToAnyRecordedOne(): void {
        $unrecorded = self::offer(TuiProfile::Full, '0');
        $recorded = self::offer(TuiProfile::Full, '0.0.1');

        $this->assertTrue($recorded->beats($unrecorded));
        $this->assertFalse($unrecorded->beats($recorded));
    }

    private static function offer(TuiProfile $profile, string $tuiVersion): TuiOffer {
        return new TuiOffer(
            'https://example.test/build/tangible-ui.css',
            '/srv/build/tangible-ui.css',
            'hash',
            $profile,
            $tuiVersion,
        );
    }
}
