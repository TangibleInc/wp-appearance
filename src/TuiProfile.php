<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance;

/**
 * How much of @tangible/ui a plugin's `tangible-ui` build compiles.
 *
 * TUI supports subsetting first-class (`@use '@tangible/ui/styles/index'
 * with ($components: (...))`), and a front-end-only plugin may well ship
 * one. But the `tangible-ui` handle is shared across every Tangible
 * plugin on the page, so a subset can only be allowed to serve when no
 * full build is present — see TuiOffer::beats().
 */
enum TuiProfile: string {
    /** Every component: `@tangible/ui/styles/unlayered` as shipped. */
    case Full = 'full';

    /** A `$components` subset. Core tokens, resets, utilities and icons always emit. */
    case Subset = 'subset';
}
