<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance;

/**
 * The canonical Appearance settings page —
 * `options-general.php?page=tangible_appearance`.
 *
 * One page, not one tab per plugin: at two plugins "a tab each" reads as
 * two doors to one room, at six it reads as six places to set the same
 * value, and someone will assume the quiz one only themes quizzes. Each
 * plugin's own settings page carries a short Appearance tab that links
 * here instead. Under core Settings because that is where the plugins'
 * settings pages already live and the URL is the stable identity.
 *
 * The page is a mount point; the panel (@tangible/wp-appearance) owns the
 * form and saves over REST, so there is no POST round-trip and no
 * "Settings saved." — the panel toasts.
 */
final class AppearancePage {
    public const SLUG = 'tangible_appearance';
    public const HOOK = 'settings_page_'.self::SLUG;
    public const ROOT_ID = 'tangible-appearance-root';

    public function register(): void {
        add_action('admin_menu', [$this, 'addPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /** @internal hook callback */
    public function addPage(): void {
        add_options_page(
            __('Appearance', 'tangible-wp-appearance'),
            __('Tangible Appearance', 'tangible-wp-appearance'),
            'manage_options',
            self::SLUG,
            [$this, 'render'],
        );
    }

    /** @internal hook callback */
    public function render(): void {
        echo '<div class="wrap tangible-appearance-wrap">';
        echo '<h1>'.esc_html__('Tangible Appearance', 'tangible-wp-appearance').'</h1>';
        echo '<div id="'.esc_attr(self::ROOT_ID).'" class="tui-interface"></div>';
        echo '</div>';
    }

    /** @internal hook callback */
    public function enqueue(string $hook): void {
        if ($hook !== self::HOOK) {
            return;
        }

        if (!PanelBundle::enqueue(TuiStylesheet::dependencies(['wp-components']))) {
            // No plugin has built the panel — a fresh checkout. Say so on
            // the page rather than leave an empty mount point.
            add_action('admin_notices', static function (): void {
                echo '<div class="notice notice-warning"><p>';
                echo esc_html__('The Appearance panel has not been built. Run the plugin build to enable it.', 'tangible-wp-appearance');
                echo '</p></div>';
            });

            return;
        }

        // The stored theme rides along so the panel renders without a GET
        // first; the panel still saves over REST.
        wp_add_inline_script(
            PanelBundle::HANDLE,
            'window.tangibleAppearance = '.wp_json_encode([
                'theme' => Appearance::load(),
                'root_id' => self::ROOT_ID,
                'docs_url' => apply_filters('tangible_appearance_docs_url', 'https://docs.tangiblelms.com/'),
            ]).';',
            'before',
        );

        wp_set_script_translations(PanelBundle::HANDLE, 'tangible-wp-appearance');
    }

    /** The canonical URL, for the plugins' link-through tabs. */
    public static function url(): string {
        return admin_url('options-general.php?page='.self::SLUG);
    }
}
