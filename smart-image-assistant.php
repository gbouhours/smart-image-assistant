<?php
/**
 * Plugin Name: Smart Image Assistant
 * Description: Generate featured images from post content using multiple AI providers (Cloudflare Workers AI, OpenRouter, OpenAI). Modular, extensible, and translation-ready.
 * Version: 2.0.0
 * Author: Gregory Bouhours
 * Author URI: https://frontendwizard.com
 * License: GPLv2 or later
 * Text Domain: smart-image-assistant
 * Domain Path: /languages
 *
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// -----------------------------------------------------------------------------
// Autoload (simple)
// -----------------------------------------------------------------------------

$sia_base_dir = plugin_dir_path(__FILE__);

require_once $sia_base_dir . 'includes/class-sia-ai-provider.php';
require_once $sia_base_dir . 'providers/class-sia-provider-cloudflare.php';
require_once $sia_base_dir . 'providers/class-sia-provider-openrouter.php';
require_once $sia_base_dir . 'providers/class-sia-provider-openai.php';
require_once $sia_base_dir . 'includes/class-smart-image-assistant.php';

// -----------------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------------

add_action('plugins_loaded', function () {
    new Smart_Image_Assistant();
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_url = admin_url('options-general.php?page=smart-image-assistant');
    $links[] = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'smart-image-assistant') . '</a>';
    return $links;
});