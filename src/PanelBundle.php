<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance;

/**
 * The compiled Appearance panel — `build/tangible-appearance.{js,css}` —
 * as offered by the plugins that bundle it.
 *
 * The PHP package cannot ship built JavaScript (Packagist is not a CDN,
 * and build output does not belong in git), so each plugin compiles the
 * panel from @tangible/wp-appearance into its own build directory and
 * offers it here at boot. The canonical settings page then enqueues ONE:
 * the newest package version, then whoever offered first — the
 * TuiStylesheet arbitration minus the profile axis, since a panel build
 * is never a subset of itself.
 */
final class PanelBundle {
    public const HANDLE = 'tangible-appearance';

    /** @var list<array{url:string,path:string,version:string,package_version:string,dependencies:list<string>}> */
    private static array $offers = [];

    /**
     * Offer a plugin's `build/tangible-appearance.*` output. False — and no
     * offer — when the build output is absent.
     *
     * @param string $pluginPath the plugin directory (plugin_dir_path())
     * @param string $pluginUrl the plugin URL (plugins_url())
     */
    public static function offerFromBuild(string $pluginPath, string $pluginUrl): bool {
        $build = rtrim($pluginPath, '/\\').'/build/';
        $js = $build.self::HANDLE.'.js';
        $asset_file = $build.self::HANDLE.'.asset.php';

        if (!file_exists($js) || !file_exists($asset_file)) {
            return false;
        }

        $asset = require $asset_file;
        $meta_file = $build.self::HANDLE.'.meta.php';
        $meta = file_exists($meta_file) ? require $meta_file : [];

        self::$offers[] = [
            'url' => rtrim($pluginUrl, '/').'/build/',
            'path' => $build,
            'version' => (string) ($asset['version'] ?? ''),
            'package_version' => (string) ($meta['version'] ?? '0'),
            'dependencies' => \is_array($asset['dependencies'] ?? null) ? $asset['dependencies'] : [],
        ];

        return true;
    }

    /**
     * The build that serves the page, or null when no plugin has offered one.
     *
     * @return array{url:string,path:string,version:string,package_version:string,dependencies:list<string>}|null
     */
    public static function winner(): ?array {
        $best = null;
        foreach (self::$offers as $offer) {
            if (null === $best || version_compare($offer['package_version'], $best['package_version'], '>')) {
                $best = $offer;
            }
        }

        return $best;
    }

    /**
     * Enqueue the winning build's script and stylesheet.
     *
     * @param string[] $styleDependencies handles the stylesheet depends on
     *
     * @return bool false when nothing was offered
     */
    public static function enqueue(array $styleDependencies = []): bool {
        $bundle = self::winner();
        if (null === $bundle) {
            return false;
        }

        wp_enqueue_script(
            self::HANDLE,
            $bundle['url'].self::HANDLE.'.js',
            array_values(array_unique(array_merge($bundle['dependencies'], ['wp-api-fetch']))),
            $bundle['version'],
            true,
        );

        if (file_exists($bundle['path'].self::HANDLE.'.css')) {
            wp_enqueue_style(
                self::HANDLE,
                $bundle['url'].self::HANDLE.'.css',
                $styleDependencies,
                $bundle['version'],
            );
        }

        return true;
    }

    /**
     * Forget every offer. Tests only.
     *
     * @internal
     */
    public static function reset(): void {
        self::$offers = [];
    }
}
