<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance;

/**
 * One plugin's candidate build of the Tangible UI stylesheet.
 *
 * Every Tangible plugin ships its own compiled copy of @tangible/ui, but
 * the page only wants one: the `tangible-ui` handle is shared, and
 * whichever build it points at serves every plugin's components. The
 * builds are not interchangeable, though — a plugin may compile a subset,
 * and two plugins released weeks apart pin different TUI versions — so
 * the plugins offer and the package decides (TuiStylesheet::offer()).
 */
final class TuiOffer {
    public function __construct(
        /** Where the browser fetches the stylesheet. */
        public readonly string $url,
        /** Where it sits on disk — for anything that later needs to read or inline it. */
        public readonly string $path,
        /** The cache-busting version handed to wp_register_style(); the build hash. */
        public readonly string $version,
        public readonly TuiProfile $profile,
        /** The @tangible/ui version the build compiled, as a semver string. */
        public readonly string $tuiVersion,
    ) {
    }

    /**
     * Whether this build should serve the page in preference to $other.
     *
     * A full build beats a subset regardless of version: a subset that won
     * would starve every other plugin's components of their CSS, and the
     * failure mode — unstyled output — is one no test asserts against.
     * Between equal profiles the newer @tangible/ui wins, because a
     * plugin's markup only ever assumes its own TUI version or older. A
     * dead heat is not a win, so the earlier offer stays.
     */
    public function beats(self $other): bool {
        if ($this->profile !== $other->profile) {
            return TuiProfile::Full === $this->profile;
        }

        return version_compare($this->tuiVersion, $other->tuiVersion, '>');
    }
}
