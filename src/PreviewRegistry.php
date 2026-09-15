<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance;

/**
 * Where the settings page's live preview gets its content.
 *
 * The frame is the package's; what is IN it is each plugin's, because a
 * site owner most needs to see one palette serving every surface at
 * once. Quiz shows a question, the LMS shows course cards and a stats
 * strip, and anything that ships later adds its own — through a filter,
 * so a third-party Tangible plugin joins without a package release:
 *
 *   add_filter(PreviewRegistry::FILTER, static function (array $previews): array {
 *       $previews[] = [
 *           'id'      => 'quiz',                    // [a-z0-9_-]
 *           'label'   => __('Quiz', 'tangible-quiz'), // the switcher's tab
 *           'render'  => fn (): string => '<div data-quiz-player></div>',
 *           'enqueue' => fn (): void => $player->enqueueAssets(), // optional
 *       ];
 *       return $previews;
 *   });
 *
 * `render` returns server-rendered HTML the panel injects into a
 * `.tui-interface` frame carrying the DRAFT tokens as an inline style.
 * The frame is not an iframe: the plugin's own front-end stylesheets
 * apply, which is the point. Anything that mounts JavaScript into its
 * markup should do so on demand rather than on DOMContentLoaded — the
 * panel injects content after load and dispatches
 * `tangible-appearance:preview-mounted` on the document with the frame
 * in `detail.root` each time it does.
 *
 * `enqueue` is called on the settings page so the content's own scripts
 * and styles are present; a `render` that enqueues for itself (the LMS
 * elements do) needs no `enqueue`.
 */
final class PreviewRegistry {
    public const FILTER = 'tangible_appearance_previews';
    public const MOUNTED_EVENT = 'tangible-appearance:preview-mounted';

    /**
     * Every registered preview, enqueued and rendered, in registration
     * order. Invalid entries are dropped with a notice rather than
     * breaking the page; a duplicate id keeps the first.
     *
     * @return list<array{id: string, label: string, html: string}>
     */
    public static function collect(): array {
        $registered = apply_filters(self::FILTER, []);
        if (!\is_array($registered)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($registered as $entry) {
            $preview = self::validate($entry);
            if (null === $preview) {
                continue;
            }
            if (isset($seen[$preview['id']])) {
                continue;
            }
            $seen[$preview['id']] = true;

            // A registration that is well-formed but throws at call time is
            // still one plugin's bug: it loses its own preview and the page
            // carries on with the others.
            try {
                if (isset($entry['enqueue'])) {
                    ($entry['enqueue'])();
                }
                $html = ($entry['render'])();
            } catch (\Throwable $e) {
                _doing_it_wrong(
                    self::FILTER,
                    \sprintf('The "%s" appearance preview threw while rendering: %s', $preview['id'], $e->getMessage()),
                    '',
                );
                continue;
            }

            if (!\is_string($html)) {
                _doing_it_wrong(
                    self::FILTER,
                    \sprintf('The "%s" appearance preview\'s render must return a string.', $preview['id']),
                    '',
                );
                continue;
            }

            $out[] = [
                'id' => $preview['id'],
                'label' => $preview['label'],
                'html' => $html,
            ];
        }

        return $out;
    }

    /**
     * @return array{id: string, label: string}|null
     */
    private static function validate(mixed $entry): ?array {
        $id = \is_array($entry) ? ($entry['id'] ?? null) : null;
        $label = \is_array($entry) ? ($entry['label'] ?? null) : null;
        $render = \is_array($entry) ? ($entry['render'] ?? null) : null;
        $enqueue = \is_array($entry) ? ($entry['enqueue'] ?? null) : null;

        $valid = \is_string($id) && 1 === preg_match('/^[a-z0-9_-]+$/', $id)
            && \is_string($label) && '' !== trim($label)
            && \is_callable($render)
            && (null === $enqueue || \is_callable($enqueue));

        if (!$valid) {
            _doing_it_wrong(
                self::FILTER,
                'An appearance preview needs an id ([a-z0-9_-]), a label, a callable render and, optionally, a callable enqueue.',
                '',
            );

            return null;
        }

        return ['id' => $id, 'label' => trim($label)];
    }
}
