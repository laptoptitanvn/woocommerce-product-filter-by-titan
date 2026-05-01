<?php
/**
 * WCPF Init - Handles early initialization tasks for WooCommerce Product Filter plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Đăng ký hook trên widgets_init để đảm bảo widget được xử lý sớm
add_action('widgets_init', 'wcpf_setup_filters_hook');
function wcpf_setup_filters_hook() {
    // Lấy general settings
    $general_settings = get_option('wcpf_general_settings', [
        'widget_usage' => 'disabled',
        'widget_filter' => '',
        'selected_filters_position' => 'above_filters',
        'apply_filter_behavior' => 'require_button',
        'default_filter_group_state' => 'closed'
    ]);

    // Kiểm tra nếu widget usage được bật và có filter hợp lệ
    if ($general_settings['widget_usage'] !== 'enabled' || empty($general_settings['widget_filter'])) {
        return;
    }

    $filter_id = $general_settings['widget_filter'];
    $filters = get_option('wcpf_filters', []);

    // Kiểm tra filter_id hợp lệ
    if (!isset($filters[$filter_id]) || !$filters[$filter_id]['active']) {
        return;
    }

    // Khởi tạo WCPF_Product_Filter
    if (!class_exists('WCPF_Product_Filter')) {
        require_once plugin_dir_path(__FILE__) . 'class-product-filter.php';
    }
    $filter_instance = new WCPF_Product_Filter();
    if (method_exists($filter_instance, 'init')) {
        $filter_instance->init();
    }

    // Đăng ký hook woocommerce_before_shop_loop
    if ($general_settings['selected_filters_position'] === 'above_products') {
        add_action('woocommerce_before_shop_loop', function() use ($filter_instance, $filter_id, $filters, $general_settings) {
            // Kiểm tra điều kiện chỉ trên các trang shop, category, hoặc brand
            if (!is_shop() && !is_product_category() && !is_tax('product_brand')) {
                return;
            }

            // Khởi tạo WCPF_Filter_Widget
            if (!class_exists('WCPF_Filter_Widget')) {
                require_once plugin_dir_path(__FILE__) . 'class-wcpf-filter-widget.php';
            }
            $widget_instance = new WCPF_Filter_Widget();

            // Gọi phương thức render_selected_filters_above_shop_loop
            $widget_instance->render_selected_filters_above_shop_loop($filter_instance, $filter_id, $filters[$filter_id]);
        }, 5);
    }
}