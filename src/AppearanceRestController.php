<?php

declare(strict_types=1);

namespace Tangible\WP\Appearance;

/**
 * `tangible/v1/appearance` — the site theme, read and replaced whole.
 *
 * GET returns the stored sparse theme (Appearance::load()). PUT takes
 * the complete sparse theme as JSON and replaces what is stored: the
 * settings panel owns the draft, so a key it no longer sends is a reset.
 * PUT only, on purpose — PATCH would promise a merge this route does not
 * do. Both require `manage_options`; this is site-wide configuration.
 *
 * A PUT that fails validation answers 400 with every problem found,
 * keyed by dotted path, so the panel can annotate each control rather
 * than report the first failure.
 */
final class AppearanceRestController {
    public const NAMESPACE = 'tangible/v1';
    public const ROUTE = '/appearance';

    public function registerRoutes(): void {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get'],
                'permission_callback' => [$this, 'permission'],
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'update'],
                'permission_callback' => [$this, 'permission'],
                // Top-level shape only, for OPTIONS discovery; every value
                // is validated by Appearance::normalize(), which is the
                // one place that knows the schema.
                'args' => $this->args(),
            ],
            'schema' => [$this, 'schema'],
        ]);
    }

    public function permission(): bool|\WP_Error {
        if (current_user_can('manage_options')) {
            return true;
        }

        return new \WP_Error(
            'tangible_appearance_forbidden',
            'You are not allowed to manage the site appearance.',
            ['status' => rest_authorization_required_code()],
        );
    }

    public function get(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response(Appearance::load());
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
        // JSON or nothing. WordPress only parses form-encoded bodies for
        // routes that declare args, and a body that arrived as something
        // else must never be mistaken for "reset everything".
        $body = $request->get_json_params();
        if (!\is_array($body)) {
            return new \WP_Error(
                'tangible_appearance_body',
                'Send the theme as a JSON object.',
                ['status' => 400],
            );
        }

        try {
            return new \WP_REST_Response(Appearance::save($body));
        } catch (InvalidAppearance $e) {
            return new \WP_Error(
                'tangible_appearance_invalid',
                'The appearance could not be saved.',
                ['status' => 400, 'errors' => $e->errors],
            );
        }
    }

    /** @return array<string, mixed> */
    public function schema(): array {
        return [
            '$schema' => 'http://json-schema.org/draft-04/schema#',
            'title' => 'tangible_appearance',
            'type' => 'object',
            'properties' => $this->args(),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function args(): array {
        return [
            'version' => ['type' => 'integer', 'readonly' => true],
            'color_scheme' => ['type' => 'string', 'enum' => Appearance::COLOR_SCHEMES],
            'colors' => ['type' => 'object'],
            'colors_dark' => ['type' => 'object'],
            'layout' => ['type' => 'object'],
        ];
    }
}
