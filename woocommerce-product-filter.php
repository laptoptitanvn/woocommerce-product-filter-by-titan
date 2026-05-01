<?php
/*
Plugin Name: WooCommerce Product Filter by Titan
Description: Product Filter for WooCommerce: To use the Brand attribute, WooCommerce 9.6 or higher is required. After activating the plugin, go to Permalinks, set the custom structure to /%postname%/, and click Save to ensure the plugin functions properly.
Version: 1.0.1
Author: Laptop Titan
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: wc-product-filter
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WCPF_PATH', plugin_dir_path(__FILE__));
define('WCPF_URL', plugin_dir_url(__FILE__));

// Include main class
require_once WCPF_PATH . 'includes/class-product-filter.php';

// Include admin class
if (is_admin()) {
    require_once WCPF_PATH . 'admin/class-wcpf-admin.php';
}

// Include widget class
require_once WCPF_PATH . 'includes/class-wcpf-filter-widget.php';
require_once WCPF_PATH . 'includes/class-seo-optimizer.php';
require_once WCPF_PATH . 'includes/wcpf-init.php';

// Initialize plugin
function wcpf_init() {
    $plugin = new WCPF_Product_Filter();
    $plugin->init();

    // Register widget
    add_action('widgets_init', function() {
        register_widget('WCPF_Filter_Widget');
    });
}
add_action('plugins_loaded', 'wcpf_init');

// Register shortcode
function wcpf_register_shortcode() {
    add_shortcode('wcpf_filter', function() {
        ob_start();
        $plugin = new WCPF_Product_Filter();
        $plugin->render_filter_ui();
        return ob_get_clean();
    });
}
add_action('init', 'wcpf_register_shortcode');
?>