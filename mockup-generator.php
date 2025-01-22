<?php
/**
 * Plugin Name: Product mockup generator
 * Description: Automatically generates Woocommerce mockups from product images
 * Version: 1.1
 * Author: Matt Kevan
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load required files
require_once plugin_dir_path(__FILE__) . 'includes/class-mockup-generator.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mockup-generator-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mockup-generator-template.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mockup-generator-batch-processor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mockup-generator-image.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-mockup-generator-product.php';

// Initialize plugin
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        new Mockup_Generator();
    }
});