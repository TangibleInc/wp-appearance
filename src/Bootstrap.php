<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance;

/**
 * Wires the appearance into WordPress. Every Tangible plugin calls
 * register() from its boot; the first call wins and the rest are no-ops,
 * so N plugins bundling this package produce one REST route and one
 * inline style block, not N.
 *
 * Front end only, decided rather than recommended: wp-admin keeps the
 * plugins' WP-chrome theme, and applying a site's brand there would be
 * wrong. wp_enqueue_scripts never fires in admin, and the is_admin()
 * check says so explicitly for anyone reading.
 */
final class Bootstrap {
    private static bool $registered = false;

    public static function register(): void {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        add_action('rest_api_init', [new AppearanceRestController(), 'registerRoutes']);

        // The canonical settings page. Its panel bundle is whichever
        // plugin's build wins PanelBundle's arbitration — offered by each
        // plugin's boot alongside its TUI build.
        (new AppearancePage())->register();

        // Priority 100: after every enqueue site has had its say. The
        // theme is attached to the handle as registered inline data, and
        // WordPress prints it only if something on the page enqueues the
        // handle — so a page with no Tangible surface still prints nothing.
        add_action('wp_enqueue_scripts', [self::class, 'printTheme'], 100);
    }

    /** @internal hook callback */
    public static function printTheme(): void {
        // Ask for the handle rather than check for it: registration is on
        // demand, and whether some other feature happened to ask first is
        // not something the theme should depend on. An empty answer means
        // no plugin offered a build — nothing to attach to.
        if (is_admin() || [] === TuiStylesheet::dependencies()) {
            return;
        }

        $css = CssEmitter::css(Appearance::load());
        if ('' === $css) {
            return;
        }

        wp_add_inline_style(TuiStylesheet::HANDLE, $css);
    }

    /**
     * Forget the single-run guard. Tests only.
     *
     * @internal
     */
    public static function reset(): void {
        self::$registered = false;
    }
}
