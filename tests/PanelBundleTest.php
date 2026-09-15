<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance\Tests;

use PHPUnit\Framework\TestCase;
use Tangible\WP\Appearance\PanelBundle;

final class PanelBundleTest extends TestCase {
    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void {
        PanelBundle::reset();
    }

    protected function tearDown(): void {
        PanelBundle::reset();
        foreach ($this->tempDirs as $dir) {
            foreach (glob($dir.'/build/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($dir.'/build');
            @rmdir($dir);
        }
    }

    public function testNothingOfferedMeansNoWinner(): void {
        $this->assertNull(PanelBundle::winner());
    }

    public function testABuildlessPluginDeclines(): void {
        $this->assertFalse(PanelBundle::offerFromBuild($this->pluginDir(withBuild: false), 'https://example.test/p/'));
        $this->assertNull(PanelBundle::winner());
    }

    public function testTheOfferCarriesTheHashDependenciesAndPackageVersion(): void {
        $dir = $this->pluginDir(withBuild: true, hash: 'abc', packageVersion: '0.1.0', deps: ['wp-element', 'wp-i18n']);

        $this->assertTrue(PanelBundle::offerFromBuild($dir, 'https://example.test/p'));

        $winner = PanelBundle::winner();
        $this->assertSame('https://example.test/p/build/', $winner['url']);
        $this->assertSame($dir.'/build/', $winner['path']);
        $this->assertSame('abc', $winner['version']);
        $this->assertSame('0.1.0', $winner['package_version']);
        $this->assertSame(['wp-element', 'wp-i18n'], $winner['dependencies']);
    }

    public function testTheNewestPackageVersionWinsWhateverTheOrder(): void {
        PanelBundle::offerFromBuild($this->pluginDir(true, 'old', '0.1.0'), 'https://example.test/lms/');
        PanelBundle::offerFromBuild($this->pluginDir(true, 'new', '0.2.0'), 'https://example.test/quiz/');

        $this->assertSame('https://example.test/quiz/build/', PanelBundle::winner()['url']);
    }

    public function testADeadHeatKeepsTheFirstOffer(): void {
        PanelBundle::offerFromBuild($this->pluginDir(true, 'a', '0.1.0'), 'https://example.test/quiz/');
        PanelBundle::offerFromBuild($this->pluginDir(true, 'b', '0.1.0'), 'https://example.test/lms/');

        $this->assertSame('https://example.test/quiz/build/', PanelBundle::winner()['url']);
    }

    public function testAMissingMetaSidecarCompetesAsVersionZero(): void {
        PanelBundle::offerFromBuild($this->pluginDir(true, 'a', null), 'https://example.test/quiz/');
        PanelBundle::offerFromBuild($this->pluginDir(true, 'b', '0.0.1'), 'https://example.test/lms/');

        $this->assertSame('https://example.test/lms/build/', PanelBundle::winner()['url']);
    }

    /**
     * @param string[] $deps
     */
    private function pluginDir(bool $withBuild, string $hash = 'hash', ?string $packageVersion = '0.1.0', array $deps = []): string {
        $dir = sys_get_temp_dir().'/tangible-wp-appearance-panel-'.uniqid('', true);
        mkdir($dir);
        $this->tempDirs[] = $dir;

        if (!$withBuild) {
            return $dir;
        }

        mkdir($dir.'/build');
        file_put_contents($dir.'/build/tangible-appearance.js', '// panel');
        file_put_contents($dir.'/build/tangible-appearance.css', '.tangible-appearance{}');
        file_put_contents(
            $dir.'/build/tangible-appearance.asset.php',
            '<?php return array(\'dependencies\' => '.var_export($deps, true).', \'version\' => \''.$hash.'\');',
        );
        if (null !== $packageVersion) {
            file_put_contents(
                $dir.'/build/tangible-appearance.meta.php',
                "<?php return array('package' => '@tangible/wp-appearance', 'version' => '".$packageVersion."');",
            );
        }

        return $dir;
    }
}
