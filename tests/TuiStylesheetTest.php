<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance\Tests;

use PHPUnit\Framework\TestCase;
use Tangible\WP\Appearance\TuiProfile;
use Tangible\WP\Appearance\TuiStylesheet;

final class TuiStylesheetTest extends TestCase {
    private const LMS = 'https://example.test/wp-content/plugins/tangible-lms/build/tangible-ui.css';
    private const QUIZ = 'https://example.test/wp-content/plugins/tangible-quiz/build/tangible-ui.css';

    /** @var list<string> temp dirs to remove */
    private array $tempDirs = [];

    protected function setUp(): void {
        TuiStylesheet::reset();
        wp_deregister_style(TuiStylesheet::HANDLE);
    }

    protected function tearDown(): void {
        TuiStylesheet::reset();
        wp_deregister_style(TuiStylesheet::HANDLE);

        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir.'/build/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($dir.'/build');
            @rmdir($dir);
        }
    }

    public function testHandleIsTheSharedName(): void {
        $this->assertSame('tangible-ui', TuiStylesheet::HANDLE);
    }

    /**
     * Nothing offered — a fresh checkout before any plugin has built — must
     * still let callers enqueue, just without the dependency, rather than
     * point them at a stylesheet that 404s.
     */
    public function testWithoutOffersTheDependencyIsOmitted(): void {
        $this->assertSame([], TuiStylesheet::dependencies());
        $this->assertSame(['wp-components'], TuiStylesheet::dependencies(['wp-components']));
        $this->assertFalse(wp_style_is(TuiStylesheet::HANDLE, 'registered'));
        $this->assertNull(TuiStylesheet::serving());
    }

    /**
     * When there is a build the shared handle goes FIRST. WordPress prints
     * dependencies in order, and the cascade needs TUI's components down
     * before the caller's own stylesheet adjusts them.
     */
    public function testASingleOfferIsRegisteredAndLeadsTheDependencyList(): void {
        TuiStylesheet::offer(self::LMS, '/srv/lms/build/tangible-ui.css', 'abc123', TuiProfile::Full, '0.2.19');

        $this->assertSame(
            [TuiStylesheet::HANDLE, 'wp-components', 'wp-block-library'],
            TuiStylesheet::dependencies(['wp-components', 'wp-block-library']),
        );

        $registered = $GLOBALS['__wp_test_styles'][TuiStylesheet::HANDLE];
        $this->assertSame(self::LMS, $registered['src']);
        $this->assertSame('abc123', $registered['ver']);
        $this->assertSame(self::LMS, TuiStylesheet::serving()?->url);
    }

    /**
     * The proposal's headline case: a subset that registered first would
     * starve every other plugin's components. Order of offering must not
     * matter, and neither must the subset being the newer TUI.
     */
    public function testAFullBuildWinsOverASubsetOfferedFirstWithANewerTui(): void {
        TuiStylesheet::offer(self::QUIZ, '/srv/quiz/build/tangible-ui.css', 'q', TuiProfile::Subset, '0.3.0');
        TuiStylesheet::offer(self::LMS, '/srv/lms/build/tangible-ui.css', 'l', TuiProfile::Full, '0.2.19');

        TuiStylesheet::dependencies();

        $this->assertSame(self::LMS, TuiStylesheet::serving()?->url);
        $this->assertSame(TuiProfile::Full, TuiStylesheet::serving()?->profile);
    }

    public function testBetweenFullBuildsTheNewestTuiServes(): void {
        TuiStylesheet::offer(self::LMS, '/srv/lms/build/tangible-ui.css', 'l', TuiProfile::Full, '0.2.19');
        TuiStylesheet::offer(self::QUIZ, '/srv/quiz/build/tangible-ui.css', 'q', TuiProfile::Full, '0.2.20');

        TuiStylesheet::dependencies();

        $this->assertSame(self::QUIZ, TuiStylesheet::serving()?->url);
    }

    public function testADeadHeatKeepsTheFirstOffer(): void {
        TuiStylesheet::offer(self::QUIZ, '/srv/quiz/build/tangible-ui.css', 'q', TuiProfile::Full, '0.2.19');
        TuiStylesheet::offer(self::LMS, '/srv/lms/build/tangible-ui.css', 'l', TuiProfile::Full, '0.2.19');

        TuiStylesheet::dependencies();

        $this->assertSame(self::QUIZ, TuiStylesheet::serving()?->url);
    }

    /**
     * A subset alone is better than nothing: on a single-plugin install it
     * is exactly the CSS that plugin's markup needs.
     */
    public function testASubsetServesWhenItIsTheOnlyOffer(): void {
        TuiStylesheet::offer(self::QUIZ, '/srv/quiz/build/tangible-ui.css', 'q', TuiProfile::Subset, '0.2.19');

        $this->assertSame([TuiStylesheet::HANDLE], TuiStylesheet::dependencies());
        $this->assertSame(self::QUIZ, TuiStylesheet::serving()?->url);
    }

    /**
     * The first ask decides. An offer arriving afterwards cannot re-point
     * a handle other stylesheets may already have been enqueued against.
     */
    public function testOffersAfterTheFirstAskAreTooLate(): void {
        TuiStylesheet::offer(self::LMS, '/srv/lms/build/tangible-ui.css', 'l', TuiProfile::Full, '0.2.19');
        TuiStylesheet::dependencies();

        TuiStylesheet::offer(self::QUIZ, '/srv/quiz/build/tangible-ui.css', 'q', TuiProfile::Full, '9.0.0');
        TuiStylesheet::dependencies();

        $this->assertSame(self::LMS, TuiStylesheet::serving()?->url);
        $this->assertSame(self::LMS, $GLOBALS['__wp_test_styles'][TuiStylesheet::HANDLE]['src']);
    }

    /**
     * A plugin bundling the pre-package TuiStylesheet still registers the
     * handle directly from its own constant. That registration is honoured
     * — the handle is what callers depend on, whoever put it there — and
     * the package does not claim to be serving.
     */
    public function testAHandleRegisteredElsewhereIsHonouredNotReplaced(): void {
        wp_register_style(TuiStylesheet::HANDLE, 'https://example.test/legacy/tangible-ui.css', [], 'legacy');
        TuiStylesheet::offer(self::LMS, '/srv/lms/build/tangible-ui.css', 'l', TuiProfile::Full, '0.2.19');

        $this->assertSame([TuiStylesheet::HANDLE], TuiStylesheet::dependencies());
        $this->assertSame('https://example.test/legacy/tangible-ui.css', $GLOBALS['__wp_test_styles'][TuiStylesheet::HANDLE]['src']);
        $this->assertNull(TuiStylesheet::serving());
    }

    /**
     * A consumer asking mid-plugins_loaded still gets an answer — it
     * needs the handle now — but the ask is flagged, because it decided
     * the arbitration before every plugin had offered.
     */
    public function testAskingDuringPluginsLoadedIsAnsweredButFlagged(): void {
        TuiStylesheet::offer(self::LMS, '/srv/lms/build/tangible-ui.css', 'l', TuiProfile::Full, '0.2.19');
        $GLOBALS['__wp_test_doing_actions'] = ['plugins_loaded'];
        $GLOBALS['__wp_test_doing_it_wrong'] = [];

        try {
            $this->assertSame([TuiStylesheet::HANDLE], TuiStylesheet::dependencies());
            $this->assertSame([TuiStylesheet::class.'::ensureRegistered'], $GLOBALS['__wp_test_doing_it_wrong']);
        } finally {
            unset($GLOBALS['__wp_test_doing_actions'], $GLOBALS['__wp_test_doing_it_wrong']);
        }
    }

    public function testOfferFromBuildDeclinesWhenTheBuildOutputIsAbsent(): void {
        $plugin = $this->pluginDir(withBuild: false);

        $this->assertFalse(TuiStylesheet::offerFromBuild($plugin, 'https://example.test/plugin/', TuiProfile::Full));
        $this->assertSame([], TuiStylesheet::dependencies());
    }

    public function testOfferFromBuildReadsTheHashAndTheTuiVersionFromTheSidecars(): void {
        $plugin = $this->pluginDir(withBuild: true, version: 'deadbeef', tuiVersion: '0.2.19');

        $this->assertTrue(TuiStylesheet::offerFromBuild($plugin, 'https://example.test/plugin/', TuiProfile::Full));
        TuiStylesheet::dependencies();

        $serving = TuiStylesheet::serving();
        $this->assertNotNull($serving);
        $this->assertSame('https://example.test/plugin/build/tangible-ui.css', $serving->url);
        $this->assertSame($plugin.'/build/tangible-ui.css', $serving->path);
        $this->assertSame('deadbeef', $serving->version);
        $this->assertSame('0.2.19', $serving->tuiVersion);
        $this->assertSame(TuiProfile::Full, $serving->profile);
    }

    /**
     * Build output from before the meta sidecar existed competes as TUI
     * '0' — still offered, still able to serve alone, but losing to any
     * build that recorded its version.
     */
    public function testOfferFromBuildWithoutTheMetaSidecarOffersTheOldestPossibleTui(): void {
        $plugin = $this->pluginDir(withBuild: true, version: 'old', tuiVersion: null);

        $this->assertTrue(TuiStylesheet::offerFromBuild($plugin, 'https://example.test/plugin', TuiProfile::Full));
        TuiStylesheet::dependencies();

        $this->assertSame('0', TuiStylesheet::serving()?->tuiVersion);
        // And the URL joins cleanly whether or not the plugin URL had a slash.
        $this->assertSame('https://example.test/plugin/build/tangible-ui.css', TuiStylesheet::serving()?->url);
    }

    /**
     * @param string|null $tuiVersion null writes no meta sidecar at all
     */
    private function pluginDir(bool $withBuild, string $version = 'hash', ?string $tuiVersion = '0.2.19'): string {
        $dir = sys_get_temp_dir().'/tangible-wp-appearance-'.uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;

        if (!$withBuild) {
            return $dir;
        }

        mkdir($dir.'/build');
        file_put_contents($dir.'/build/tangible-ui.css', '.tui-interface{}');
        file_put_contents(
            $dir.'/build/tangible-ui.asset.php',
            "<?php return array('dependencies' => array(), 'version' => '".$version."');",
        );
        if (null !== $tuiVersion) {
            file_put_contents(
                $dir.'/build/tangible-ui.meta.php',
                "<?php return array('tui_version' => '".$tuiVersion."');",
            );
        }

        return $dir;
    }
}
