<?php

declare(strict_types=1);

/*
 * Test bootstrap for tangible/wp-appearance.
 *
 * The package has no dependencies, so rather than a per-package composer
 * install it autoloads its own src/ here and stubs the handful of
 * WordPress style-registry functions it reaches. The stubs mirror
 * WP_Dependencies closely enough for what the tests assert — registering
 * an existing handle is a no-op returning false — and are guarded so the
 * file no-ops should a future bootstrap load real WordPress.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tangible\\WP\\Appearance\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($file)) {
        require $file;
    }
});

if (!function_exists('wp_style_is')) {
    function wp_style_is(string $handle, string $status = 'enqueued'): bool {
        return 'registered' === $status && isset($GLOBALS['__wp_test_styles'][$handle]);
    }
}

if (!function_exists('wp_register_style')) {
    function wp_register_style(string $handle, string|false $src, array $deps = [], string|bool|null $ver = false, string $media = 'all'): bool {
        if (isset($GLOBALS['__wp_test_styles'][$handle])) {
            return false;
        }

        $GLOBALS['__wp_test_styles'][$handle] = ['src' => $src, 'deps' => $deps, 'ver' => $ver, 'media' => $media];

        return true;
    }
}

if (!function_exists('doing_action')) {
    function doing_action(?string $hook = null): bool {
        return in_array($hook, $GLOBALS['__wp_test_doing_actions'] ?? [], true);
    }
}

if (!function_exists('_doing_it_wrong')) {
    function _doing_it_wrong(string $function, string $message, string $version): void {
        $GLOBALS['__wp_test_doing_it_wrong'][] = $function;
    }
}

if (!function_exists('wp_deregister_style')) {
    function wp_deregister_style(string $handle): void {
        unset($GLOBALS['__wp_test_styles'][$handle]);
    }
}
