<?php
/**
 * Abstract AI Provider base class.
 *
 * @package SmartImageAssistant
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class SIA_AI_Provider {

    /** @var Smart_Image_Assistant */
    protected $plugin;

    public function __construct(Smart_Image_Assistant $plugin) {
        $this->plugin = $plugin;
    }

    abstract public function get_key(): string;
    abstract public function get_name(): string;
    abstract public function get_description(): string;
    abstract public function is_configured(array $provider_settings): bool;
    abstract public function get_default_models(): array;
    abstract public function render_settings_fields(array $provider_settings): void;
    abstract public function sanitize_settings(array $input): array;
    abstract public function generate_image(string $prompt, int $width, int $height, array $provider_settings);
    abstract public function get_endpoint_url(array $provider_settings): string;
}