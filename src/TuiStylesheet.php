<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance;

/**
 * The shared Tangible UI stylesheet.
 *
 * Every Tangible plugin is built on TUI, and every one of them compiles
 * its own copy of the design system's CSS. A page that renders a course
 * with a quiz in it wants exactly one of those copies: registered once,
 * under one handle, so the components ship and cache at a single URL and
 * every plugin's own stylesheet lays its adjustments over the same base.
 * WordPress prints dependencies first, which is also what the cascade
 * wants — TUI lays down the components, the plugins adjust them.
 *
 * The handle used to be claimed by whichever plugin asked first, from a
 * plugin-local constant. That is fine while every build is identical and
 * wrong the moment one is not: a plugin compiling a component subset
 * would serve that subset to every other plugin on the page, and two
 * plugins released weeks apart pin different @tangible/ui versions. So
 * the plugins OFFER their builds and this class arbitrates — full beats
 * subset, then the newest TUI, then whoever offered first (TuiOffer).
 *
 * Offers belong at plugin boot (plugins_loaded), which is before any
 * enqueue site can ask for the dependency. Registration itself stays on
 * demand rather than hooked: enqueue sites run across several different
 * hooks (wp_enqueue_scripts, admin_enqueue_scripts, block registration at
 * init), and asking for the dependency is the one moment they all share.
 * The first ask decides; later offers are too late and are ignored.
 *
 * A plugin still bundling the pre-package TuiStylesheet registers the
 * handle directly from its own constant, before or after our arbitration
 * depending on who asks first. That is honoured, not fought — the handle
 * is what callers depend on, whoever put it there — so a half-updated
 * site degrades to the first-registrant behaviour it had before, and
 * serving() returning null while the handle is registered is the tell.
 */
final class TuiStylesheet {
    public const HANDLE = 'tangible-ui';

    /** @var list<TuiOffer> in offer order — the tiebreaker */
    private static array $offers = [];

    private static ?TuiOffer $serving = null;

    /**
     * Offer a compiled TUI stylesheet for the shared handle.
     *
     * @param string $url where the browser fetches the CSS
     * @param string $path the same file on disk
     * @param string $version cache-busting version for wp_register_style() — the build hash
     * @param TuiProfile $profile whether the build is TUI in full or a component subset
     * @param string $tuiVersion the @tangible/ui version compiled, e.g. '0.2.19'
     */
    public static function offer(
        string $url,
        string $path,
        string $version,
        TuiProfile $profile,
        string $tuiVersion,
    ): void {
        self::$offers[] = new TuiOffer($url, $path, $version, $profile, $tuiVersion);
    }

    /**
     * Offer a plugin's `build/tangible-ui.*` output, the shape the shared
     * webpack config emits: the CSS, the `.asset.php` carrying the build
     * hash, and the `.meta.php` carrying the compiled @tangible/ui version.
     *
     * False — and no offer — when the build output is absent: a fresh
     * checkout before `pnpm build`, or a unit bootstrap. Callers then
     * enqueue without the dependency rather than point at a 404.
     *
     * @param string $pluginPath the plugin directory (plugin_dir_path())
     * @param string $pluginUrl the plugin URL (plugins_url())
     */
    public static function offerFromBuild(string $pluginPath, string $pluginUrl, TuiProfile $profile): bool {
        $build = rtrim($pluginPath, '/\\').'/build/';
        $css = $build.'tangible-ui.css';
        $asset_file = $build.'tangible-ui.asset.php';

        if (!file_exists($css) || !file_exists($asset_file)) {
            return false;
        }

        $asset = require $asset_file;

        // Build output predating the meta sidecar still gets to compete —
        // as the oldest possible TUI, which is the truthful position for
        // a build whose version nobody recorded.
        $meta_file = $build.'tangible-ui.meta.php';
        $meta = file_exists($meta_file) ? require $meta_file : [];

        self::offer(
            url: rtrim($pluginUrl, '/').'/build/tangible-ui.css',
            path: $css,
            version: (string) ($asset['version'] ?? ''),
            profile: $profile,
            tuiVersion: (string) ($meta['tui_version'] ?? '0'),
        );

        return true;
    }

    /**
     * Name this stylesheet as a dependency, registering the winning offer
     * if the handle has not been registered already.
     *
     * @param string[] $also further handles to depend on
     *
     * @return string[] a dependency list ready for wp_enqueue_style()
     */
    public static function dependencies(array $also = []): array {
        return self::ensureRegistered()
            ? array_merge([self::HANDLE], $also)
            : $also;
    }

    /**
     * The offer registered under the handle — null before the first ask,
     * when nothing was offered, or when something outside this class
     * registered the handle first (a bundled copy of the pre-package
     * TuiStylesheet, say).
     */
    public static function serving(): ?TuiOffer {
        return self::$serving;
    }

    /**
     * Forget every offer and the registration decision. Tests only: the
     * state is static because the decision is per request.
     *
     * @internal
     */
    public static function reset(): void {
        self::$offers = [];
        self::$serving = null;
    }

    private static function ensureRegistered(): bool {
        if (wp_style_is(self::HANDLE, 'registered')) {
            return true;
        }

        // Offers arrive on plugins_loaded; a consumer asking during that
        // same action arbitrates over whichever plugins happen to have
        // booted so far. Not an error we can recover from here — the
        // asker needs the handle now — but one worth surfacing.
        if (doing_action('plugins_loaded')) {
            _doing_it_wrong(
                __METHOD__,
                'The shared tangible-ui stylesheet was asked for during plugins_loaded, before every plugin had offered its build. Ask from an enqueue hook or init instead.',
                '',
            );
        }

        $winner = self::winner();
        if (null === $winner) {
            return false;
        }

        wp_register_style(self::HANDLE, $winner->url, [], $winner->version);
        self::$serving = $winner;

        return true;
    }

    private static function winner(): ?TuiOffer {
        $best = null;
        foreach (self::$offers as $offer) {
            if (null === $best || $offer->beats($best)) {
                $best = $offer;
            }
        }

        return $best;
    }
}
