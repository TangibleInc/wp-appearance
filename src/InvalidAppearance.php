<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance;

/**
 * A theme that failed validation, with every problem found — not just
 * the first — keyed by the dotted path of the offending value
 * (`colors.primary.ladder.base`, `layout.spacing_base`), so a settings
 * panel can put each message beside its control.
 */
final class InvalidAppearance extends \InvalidArgumentException {
    /**
     * @param array<string, string> $errors path => message
     */
    public function __construct(
        public readonly array $errors,
    ) {
        parent::__construct(\sprintf(
            'Invalid appearance: %s',
            implode('; ', array_map(
                static fn (string $path, string $message): string => $path.' '.$message,
                array_keys($errors),
                $errors,
            )),
        ));
    }
}
