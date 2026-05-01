<?php
/**
 * WCPF_Admin class handles the admin functionality for WooCommerce Product Filter plugin.
 */
class WCPF_Admin {
    // Default term image constant
    const DEFAULT_TERM_IMAGE = WCPF_URL . 'admin/assets/images/default-term-icon.png';

    /**
     * Constructor to initialize hooks.
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_wcpf_toggle_filter', [$this, 'ajax_toggle_filter']);
        add_action('wp_ajax_wcpf_upload_term_image', [$this, 'ajax_upload_term_image']);
        add_action('wp_ajax_wcpf_remove_term_image', [$this, 'ajax_remove_term_image']);
        add_action('wp_ajax_wcpf_delete_filter', [$this, 'ajax_delete_filter']);
		add_action('wp_ajax_wcpf_clear_cache', [$this, 'ajax_clear_cache']);
		
		// Hook cho thêm, sửa, xóa danh mục sản phẩm
		add_action('create_product_cat', [$this, 'clear_cache'], 10, 3);
		add_action('edit_product_cat', [$this, 'clear_cache'], 10, 3);
		add_action('delete_product_cat', [$this, 'clear_cache'], 10, 3);
		
		// Hook tổng quát cho chỉnh sửa term
		add_action('edited_term', [$this, 'clear_cache'], 10, 3);
		add_action('created_term', [$this, 'clear_cache'], 10, 3);
		add_action('delete_term', [$this, 'clear_cache'], 10, 3);

		// Hook cho cập nhật/xóa sản phẩm
		add_action('save_post_product', [$this, 'clear_cache_on_product_save'], 10, 3);
		add_action('delete_post', [$this, 'clear_cache_on_product_delete'], 10, 3);
		
		add_action('woocommerce_new_product', [$this, 'clear_cache'], 10, 2);
    }

    /**
     * Add admin menu pages.
     */
    public function add_admin_menu() {
        add_menu_page(
            __('WooCommerce Product Filter', 'wc-product-filter'),
            __('Product Filter', 'wc-product-filter'),
            'manage_options',
            'wcpf-settings',
            [$this, 'render_filters_list_page'],
            'dashicons-filter',
            56
        );

        add_submenu_page(
            'wcpf-settings',
            __('All Filters', 'wc-product-filter'),
            __('All Filters', 'wc-product-filter'),
            'manage_options',
            'wcpf-settings',
            [$this, 'render_filters_list_page']
        );

        add_submenu_page(
            'wcpf-settings',
            __('Add New Filter', 'wc-product-filter'),
            __('Add New', 'wc-product-filter'),
            'manage_options',
            'wcpf-add-filter',
            [$this, 'render_add_filter_page']
        );

        add_submenu_page(
            'wcpf-settings',
            __('General Settings', 'wc-product-filter'),
            __('General Settings', 'wc-product-filter'),
            'manage_options',
            'wcpf-general-settings',
            [$this, 'render_general_settings_page']
        );
    }

    /**
     * Enqueue admin scripts and styles.
     *
     * @param string $hook The current admin page.
     */
    public function enqueue_admin_scripts($hook) {
        $allowed_hooks = [
            'toplevel_page_wcpf-settings',
            'product-filter_page_wcpf-general-settings',
            'product-filter_page_wcpf-add-filter'
        ];

        if (!in_array($hook, $allowed_hooks)) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('wcpf-admin-css', WCPF_URL . 'admin/assets/css/wcpf-admin.css', [], '1.6.0');
        wp_enqueue_script('wcpf-admin-js', WCPF_URL . 'admin/assets/js/wcpf-admin.js', ['jquery', 'jquery-ui-sortable', 'select2'], '1.6.0', true);
        wp_enqueue_script('select2', WCPF_URL . 'admin/assets/js/select2.min.js', ['jquery'], '4.0.13', true);

        wp_localize_script('wcpf-admin-js', 'wcpf_admin_params', [
            'nonce' => wp_create_nonce('wcpf_admin_nonce'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'default_term_image' => self::DEFAULT_TERM_IMAGE,
            'max_terms_per_attribute' => get_option('wcpf_general_settings', ['max_terms_per_attribute' => 0])['max_terms_per_attribute'],
            'i18n' => [
                'select2_placeholder' => __('Search and select...', 'wc-product-filter'),
                'confirm_delete' => __('Are you sure you want to delete this filter?', 'wc-product-filter'),
                'error_delete' => __('Error deleting filter.', 'wc-product-filter'),
                'error_toggle' => __('Error toggling filter status.', 'wc-product-filter'),
                'error_upload' => __('Error uploading image.', 'wc-product-filter'),
                'error_remove_image' => __('Error removing image.', 'wc-product-filter'),
                'upload_term_image' => __('Chọn ảnh cho term', 'wc-product-filter'),
                'select_image' => __('Chọn ảnh', 'wc-product-filter'),
                'remove_image' => __('Xóa ảnh', 'wc-product-filter'),
				'clear_cache' => __('Xóa cache', 'wc-product-filter'),
				'clearing_cache' => __('Đang xóa cache...', 'wc-product-filter'),
				'cache_cleared' => __('Xóa cache thành công.', 'wc-product-filter'),
				'error_clear_cache' => __('Lỗi khi xóa cache.', 'wc-product-filter'),
            ]
        ]);
    }

    /**
     * Register settings for the plugin.
     */
    public function register_settings() {
        register_setting('wcpf_general_settings_group', 'wcpf_general_settings', [
            'sanitize_callback' => [$this, 'sanitize_general_settings']
        ]);

        register_setting('wcpf_filter_settings_group', 'wcpf_filter_settings', [
            'sanitize_callback' => [$this, 'sanitize_filter_settings']
        ]);
    }

    /**
     * Sanitize general settings.
     *
     * @param array $input The input settings.
     * @return array Sanitized settings.
     */
    public function sanitize_general_settings($input) {
        $sanitized = [];
        $filters = get_option('wcpf_filters', []);

        $sanitized['shop_filter'] = isset($input['shop_filter']) && array_key_exists($input['shop_filter'], $filters) ? sanitize_text_field($input['shop_filter']) : '';
        $sanitized['custom_texts'] = isset($input['custom_texts']) ? array_map('sanitize_text_field', $input['custom_texts']) : [
            'apply_button' => 'Áp dụng',
            'reset_button' => 'Xóa tất cả',
            'mobile_menu_button' => 'LỌC',
            'mobile_menu_title' => 'LỌC',
            'show_more_text' => '+ Xem thêm',
            'show_less_text' => '- Thu gọn'
        ];
        $sanitized['custom_css'] = isset($input['custom_css']) ? wp_kses_post($input['custom_css']) : '';
        $sanitized['hide_empty_terms'] = isset($input['hide_empty_terms']) ? 1 : 0;
        $sanitized['widget_usage'] = isset($input['widget_usage']) && in_array($input['widget_usage'], ['disabled', 'enabled']) ? $input['widget_usage'] : 'disabled';
        $sanitized['widget_filter'] = isset($input['widget_filter']) && array_key_exists($input['widget_filter'], $filters) ? sanitize_text_field($input['widget_filter']) : '';
        $sanitized['selected_filters_position'] = isset($input['selected_filters_position']) && in_array($input['selected_filters_position'], ['above_filters', 'above_products']) ? $input['selected_filters_position'] : 'above_filters';
        $sanitized['apply_filter_behavior'] = isset($input['apply_filter_behavior']) && in_array($input['apply_filter_behavior'], ['immediate', 'apply_button']) ? $input['apply_filter_behavior'] : 'apply_button';
        $sanitized['default_filter_group_state'] = isset($input['default_filter_group_state']) && in_array($input['default_filter_group_state'], ['open', 'closed']) ? $input['default_filter_group_state'] : 'closed';
        $sanitized['max_terms_per_attribute'] = isset($input['max_terms_per_attribute']) && is_numeric($input['max_terms_per_attribute']) ? intval($input['max_terms_per_attribute']) : 0;
        $sanitized['loading_image_id'] = isset($input['loading_image_id']) && is_numeric($input['loading_image_id']) ? intval($input['loading_image_id']) : 0;
		$sanitized['use_value_id_in_url'] = isset($input['use_value_id_in_url']) ? 1 : 0;
		$sanitized['canonical_url'] = isset($input['canonical_url']) && in_array($input['canonical_url'], ['with_filter', 'without_filter', 'default_wp']) ? sanitize_text_field($input['canonical_url']) : 'default_wp';
		$sanitized['meta_robots'] = isset($input['meta_robots']) && in_array($input['meta_robots'], ['index_follow', 'noindex_nofollow', 'default_wp']) ? sanitize_text_field($input['meta_robots']) : 'default_wp';
		$sanitized['seo_title_format'] = isset($input['seo_title_format']) && in_array($input['seo_title_format'], ['default', 'title_with_attributes', 'title_attributes_colon', 'attribute_title_with_attributes', 'title_values_slash', 'attributes_colon_title']) ? sanitize_text_field($input['seo_title_format']) : 'default';

        return $sanitized;
    }

    /**
     * Sanitize filter settings.
     *
     * @param array $input The input filter settings.
     * @return array Sanitized filter settings.
     */
    public function sanitize_filter_settings($input) {
        $sanitized = [];
        $sanitized['name'] = isset($input['name']) ? sanitize_text_field($input['name']) : '';
        $sanitized['active'] = isset($input['active']) ? 1 : 0;
        $sanitized['categories'] = isset($input['categories']) ? array_map('intval', (array)$input['categories']) : [];
        $sanitized['brands'] = isset($input['brands']) ? array_map('sanitize_text_field', (array)$input['brands']) : [];
        $sanitized['apply_to_subcategories'] = isset($input['apply_to_subcategories']) ? 1 : 0;
        $sanitized['active_attributes'] = isset($input['active_attributes']) ? array_map('sanitize_text_field', $input['active_attributes']) : [];
        $sanitized['price_ranges'] = isset($input['price_ranges']) ? array_map(function($range) {
            $min = is_numeric($range['min']) ? floatval($range['min']) : 0;
            $max = !empty($range['max']) && is_numeric($range['max']) ? floatval($range['max']) : null;
            $label = !empty($range['label']) ? sanitize_text_field($range['label']) : "$min - " . ($max ? $max : 'Trên ' . $min);
            return [
                'min' => $min,
                'max' => $max,
                'label' => $label
            ];
        }, $input['price_ranges']) : [];
        $sanitized['attribute_labels'] = isset($input['attribute_labels']) ? array_map('sanitize_text_field', (array)$input['attribute_labels']) : [];

        // Sanitize custom CSS classes
        $sanitized['custom_css_classes'] = [];
        if (isset($input['custom_css_classes']) && is_array($input['custom_css_classes'])) {
            foreach ($input['custom_css_classes'] as $key => $class) {
                $sanitized['custom_css_classes'][sanitize_text_field($key)] = preg_replace('/[^a-zA-Z0-9_-]/', '', $class);
            }
        }

        // Sanitize layout and term style settings for each attribute
        $sanitized['layout_style'] = [];
        if (isset($input['layout_style']) && is_array($input['layout_style'])) {
            foreach ($input['layout_style'] as $key => $style) {
                if (in_array($style, ['flow', 'list', 'two-colums', 'three-colums', 'four_colums'])) {
                    $sanitized['layout_style'][sanitize_text_field($key)] = $style;
                } else {
                    $sanitized['layout_style'][sanitize_text_field($key)] = 'flow';
                }
            }
        }

        $sanitized['term_style'] = [];
        if (isset($input['term_style']) && is_array($input['term_style'])) {
            foreach ($input['term_style'] as $key => $style) {
                if (in_array($style, ['label', 'checkbox-square', 'checkbox-circle'])) {
                    $sanitized['term_style'][sanitize_text_field($key)] = $style;
                } else {
                    $sanitized['term_style'][sanitize_text_field($key)] = 'label';
                }
            }
        }

        $sanitized['active_attribute_terms'] = [];
        if (isset($input['active_attribute_terms']) && is_array($input['active_attribute_terms'])) {
            foreach ($input['active_attribute_terms'] as $taxonomy => $terms) {
                if ($taxonomy !== 'price' && $taxonomy !== 'search') {
                    $terms = array_filter((array)$terms, function($slug) {
                        return $slug !== 'select-all';
                    });
                    $sanitized['active_attribute_terms'][$taxonomy] = array_map('sanitize_text_field', $terms);
                    if ($taxonomy !== 'sort_by' && $taxonomy !== 'stock_status' && taxonomy_exists($taxonomy)) {
                        $sanitized['active_attribute_terms'][$taxonomy] = array_filter($sanitized['active_attribute_terms'][$taxonomy], function($slug) use ($taxonomy) {
                            return get_term_by('slug', $slug, $taxonomy) !== false;
                        });
                    }
                }
            }
        }

        // Sanitize display settings
        $sanitized['display_settings'] = [];
        if (isset($input['display_settings']) && is_array($input['display_settings'])) {
            foreach ($input['display_settings'] as $key => $value) {
                if (in_array($value, ['both', 'desktop', 'mobile'])) {
                    $sanitized['display_settings'][sanitize_text_field($key)] = $value;
                } else {
                    $sanitized['display_settings'][sanitize_text_field($key)] = 'both';
                }
            }
        }

        // Sanitize max terms
        $sanitized['max_terms'] = [];
        if (isset($input['max_terms']) && is_array($input['max_terms'])) {
            foreach ($input['max_terms'] as $key => $value) {
                $sanitized['max_terms'][sanitize_text_field($key)] = is_numeric($value) ? intval($value) : '';
            }
        }

        // Sanitize single select
        $sanitized['single_select'] = [];
        if (isset($input['single_select']) && is_array($input['single_select'])) {
            foreach ($input['single_select'] as $key => $value) {
                $sanitized['single_select'][sanitize_text_field($key)] = $value ? 1 : 0;
            }
        }

        // Sanitize show_term_product_count for each attribute
        $sanitized['show_term_product_count'] = [];
        if (isset($input['show_term_product_count']) && is_array($input['show_term_product_count'])) {
            foreach ($input['show_term_product_count'] as $key => $value) {
                $sanitized['show_term_product_count'][sanitize_text_field($key)] = $value ? 1 : 0;
            }
        }
		
		// Sanitize category display mode for product_cat
		$sanitized['category_display_mode'] = [];
		if (isset($input['category_display_mode']) && is_array($input['category_display_mode'])) {
			foreach ($input['category_display_mode'] as $key => $value) {
				if (in_array($value, ['leaf_only', 'parent_child', 'contextual'])) {
					$sanitized['category_display_mode'][sanitize_text_field($key)] = $value;
				} else {
					$sanitized['category_display_mode'][sanitize_text_field($key)] = 'leaf_only';
				}
			}
		}
		
		// Sanitize category display mode for product_cat
		$sanitized['category_mode'] = [];
		if (isset($input['category_mode']) && is_array($input['category_mode'])) {
			foreach ($input['category_mode'] as $key => $value) {
				if (in_array($value, ['category_filter', 'category_text_link'])) {
					$sanitized['category_mode'][sanitize_text_field($key)] = $value;
				} else {
					$sanitized['category_mode'][sanitize_text_field($key)] = 'category_filter';
				}
			}
		}

        $existing_settings = get_option('wcpf_filter_settings', []);
        if (empty($sanitized['attribute_labels']) && !empty($existing_settings['attribute_labels'])) {
            $sanitized['attribute_labels'] = $existing_settings['attribute_labels'];
        }

        return $sanitized;
    }
	

    /**
     * AJAX handler to toggle filter status.
     */
    public function ajax_toggle_filter() {
        check_ajax_referer('wcpf_admin_nonce', 'nonce');

        if (!isset($_POST['filter_id']) || !isset($_POST['active'])) {
            wp_send_json_error(['message' => __('Dữ liệu không hợp lệ.', 'wc-product-filter')]);
        }

        $filter_id = sanitize_text_field($_POST['filter_id']);
        $active = intval($_POST['active']);
        $filters = get_option('wcpf_filters', []);

        if (!isset($filters[$filter_id])) {
            wp_send_json_error(['message' => __('Bộ lọc không tồn tại.', 'wc-product-filter')]);
        }

        $filters[$filter_id]['active'] = $active;
        update_option('wcpf_filters', $filters);

        wp_send_json_success(['active' => $active]);
    }

    /**
     * AJAX handler to delete a filter.
     */
    public function ajax_delete_filter() {
        check_ajax_referer('wcpf_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Không có quyền truy cập.', 'wc-product-filter')]);
        }

        if (!isset($_POST['filter_id'])) {
            wp_send_json_error(['message' => __('Dữ liệu không hợp lệ.', 'wc-product-filter')]);
        }

        $filter_id = sanitize_text_field($_POST['filter_id']);
        $filters = get_option('wcpf_filters', []);

        if (!isset($filters[$filter_id])) {
            wp_send_json_error(['message' => __('Bộ lọc không tồn tại.', 'wc-product-filter')]);
        }

        unset($filters[$filter_id]);
        update_option('wcpf_filters', $filters);

        $general_settings = get_option('wcpf_general_settings', []);
        if (isset($general_settings['shop_filter']) && $general_settings['shop_filter'] === $filter_id) {
            $general_settings['shop_filter'] = '';
            update_option('wcpf_general_settings', $general_settings);
        }
        if (isset($general_settings['widget_filter']) && $general_settings['widget_filter'] === $filter_id) {
            $general_settings['widget_filter'] = '';
            update_option('wcpf_general_settings', $general_settings);
        }

        wp_send_json_success(['message' => __('Bộ lọc đã được xóa thành công.', 'wc-product-filter')]);
    }

    /**
     * AJAX handler to upload term image.
     */
    public function ajax_upload_term_image() {
        check_ajax_referer('wcpf_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Không có quyền truy cập.', 'wc-product-filter')]);
        }

        if (!isset($_POST['term_id']) || !isset($_POST['attachment_id'])) {
            wp_send_json_error(['message' => __('Dữ liệu không hợp lệ.', 'wc-product-filter')]);
        }

        $term_id = intval($_POST['term_id']);
        $attachment_id = intval($_POST['attachment_id']);
        $term = get_term($term_id);

        if (!$term || is_wp_error($term)) {
            wp_send_json_error(['message' => __('Thuộc tính không tồn tại.', 'wc-product-filter')]);
        }

        if (!wp_attachment_is_image($attachment_id)) {
            wp_send_json_error(['message' => __('Hình ảnh không hợp lệ.', 'wc-product-filter')]);
        }

        update_term_meta($term_id, 'thumbnail_id', $attachment_id);
        $image_url = wp_get_attachment_thumb_url($attachment_id);

        wp_send_json_success(['image_url' => $image_url]);
    }

    /**
     * AJAX handler to remove term image.
     */
    public function ajax_remove_term_image() {
        check_ajax_referer('wcpf_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Không có quyền truy cập.', 'wc-product-filter')]);
        }

        if (!isset($_POST['term_id'])) {
            wp_send_json_error(['message' => __('Dữ liệu không hợp lệ.', 'wc-product-filter')]);
        }

        $term_id = intval($_POST['term_id']);
        $term = get_term($term_id);

        if (!$term || is_wp_error($term)) {
            wp_send_json_error(['message' => __('Thuộc tính không tồn tại.', 'wc-product-filter')]);
        }

        delete_term_meta($term_id, 'thumbnail_id');
        wp_send_json_success(['message' => __('Đã xóa hình ảnh.', 'wc-product-filter')]);
    }
	
	/**
 * AJAX handler to clear WCPF cache.
 */
	public function ajax_clear_cache() {
		check_ajax_referer('wcpf_admin_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Không có quyền truy cập.', 'wc-product-filter')]);
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'options';
		$cache_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE option_name LIKE '%wcpf_term_count_%'");
		
		if ($cache_count > 0) {
			$wpdb->query("DELETE FROM $table_name WHERE option_name LIKE '%wcpf_term_count_%'");
		}

		wp_send_json_success([
			'message' => __('Xóa cache thành công.', 'wc-product-filter'),
			'cache_count' => $cache_count
		]);
	}
	
	public function clear_cache($term_or_product_id, $tt_id_or_product = null, $taxonomy = '') {
		// Kiểm tra taxonomy hoặc post type
		if (!empty($taxonomy) && $taxonomy !== 'product_cat' && !isset($_POST['post_type']) || (isset($_POST['post_type']) && $_POST['post_type'] !== 'product')) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'options';
		$cache_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE option_name LIKE '%wcpf_term_count_%'");

		if ($cache_count > 0) {
			$wpdb->query("DELETE FROM $table_name WHERE option_name LIKE '%wcpf_term_count_%'");
		}
	}
	
	public function clear_cache_on_product_save($post_id, $post, $update) {
		if ($post->post_type === 'product') {
			$this->clear_cache($post_id);
		}
	}

	public function clear_cache_on_product_delete($post_id, $post) {
		if (get_post_type($post_id) === 'product') {
			$this->clear_cache($post_id);
		}
	}

    /**
     * Render the filters list page.
     */
    public function render_filters_list_page() {
        $filters = get_option('wcpf_filters', []);

        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['filter_id'])) {
            $this->render_edit_filter_page(sanitize_text_field($_GET['filter_id']));
            return;
        }
        ?>
        <div class="wrap wcpf-container">
            <h1 class="wp-heading-inline"><?php _e('Tất cả bộ lọc', 'wc-product-filter'); ?></h1>
            <a href="<?php echo admin_url('admin.php?page=wcpf-add-filter'); ?>" class="page-title-action"><?php _e('Thêm bộ lọc mới', 'wc-product-filter'); ?></a>
            <table class="wcpf-table">
                <thead>
                    <tr class="wcpf-table-row">
                        <th class="wcpf-table-header"><?php _e('Tên', 'wc-product-filter'); ?></th>
                        <th class="wcpf-table-header"><?php _e('Kích hoạt', 'wc-product-filter'); ?></th>
                        <th class="wcpf-table-header"><?php _e('Danh mục', 'wc-product-filter'); ?></th>
                        <th class="wcpf-table-header"><?php _e('Thương hiệu', 'wc-product-filter'); ?></th>
                        <th class="wcpf-table-header"><?php _e('Hành động', 'wc-product-filter'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filters as $id => $filter): ?>
                        <tr class="wcpf-table-row">
                            <td class="wcpf-table-cell"><?php echo esc_html($filter['name']); ?></td>
                            <td class="wcpf-table-cell">
                                <label class="wcpf-toggle-switch">
                                    <input type="checkbox" class="wcpf-toggle-filter" data-filter-id="<?php echo esc_attr($id); ?>" <?php checked($filter['active'], 1); ?>>
                                    <span class="wcpf-toggle-slider"></span>
                                </label>
                            </td>
                            <td class="wcpf-table-cell">
                                <?php
                                $cat_names = [];
                                foreach ($filter['categories'] as $cat_id) {
                                    $term = get_term($cat_id, 'product_cat');
                                    if ($term) $cat_names[] = $term->name;
                                }
                                echo esc_html(implode(', ', $cat_names));
                                ?>
                            </td>
                            <td class="wcpf-table-cell">
                                <?php
                                $brand_names = [];
                                foreach ($filter['brands'] as $slug) {
                                    $term = get_term_by('slug', $slug, 'product_brand');
                                    if ($term) $brand_names[] = $term->name;
                                }
                                echo esc_html(implode(', ', $brand_names));
                                ?>
                            </td>
                            <td class="wcpf-table-cell">
                                <a href="<?php echo admin_url('admin.php?page=wcpf-settings&action=edit&filter_id=' . $id); ?>" class="wcpf-button wcpf-button-edit"><?php _e('Sửa', 'wc-product-filter'); ?></a>
                                <button class="wcpf-button wcpf-button-remove wcpf-delete-filter" data-filter-id="<?php echo esc_attr($id); ?>"><?php _e('Xóa', 'wc-product-filter'); ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the general settings page.
     */
    public function render_general_settings_page() {
        $settings = get_option('wcpf_general_settings', [
            'shop_filter' => '',
            'custom_texts' => [
                'apply_button' => 'Áp dụng',
                'reset_button' => 'Xóa tất cả',
                'mobile_menu_button' => 'LỌC',
                'mobile_menu_title' => 'LỌC',
                'show_more_text' => '+ Xem thêm',
                'show_less_text' => '- Thu gọn'
            ],
            'custom_css' => '',
            'hide_empty_terms' => 0,
            'widget_usage' => 'disabled',
            'widget_filter' => '',
            'selected_filters_position' => 'above_filters',
            'apply_filter_behavior' => 'apply_button',
            'default_filter_group_state' => 'closed',
            'max_terms_per_attribute' => 0,
            'loading_image_id' => 0
        ]);
        $filters = get_option('wcpf_filters', []);
        ?>
        <div class="wrap wcpf-container">
            <h1 class="wp-heading-inline"><?php _e('Cài đặt chung', 'wc-product-filter'); ?></h1>
            <form method="post" action="options.php" class="wcpf-form">
                <?php settings_fields('wcpf_general_settings_group'); ?>
                <?php wp_nonce_field('wcpf_admin_nonce', 'wcpf_admin_nonce'); ?>
                <div class="wcpf-section">
                    <h2 class="wcpf-section-title"><?php _e('Cài đặt bộ lọc', 'wc-product-filter'); ?></h2>
                    <table class="wcpf-table">
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Ảnh Loading', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <div class="wcpf-image-upload-wrapper">
                                    <?php
                                    $loading_image_id = isset($settings['loading_image_id']) ? intval($settings['loading_image_id']) : 0;
                                    $loading_image_url = $loading_image_id ? wp_get_attachment_url($loading_image_id) : '';
                                    ?>
                                    <input type="hidden" name="wcpf_general_settings[loading_image_id]" id="wcpf-loading-image-id" value="<?php echo esc_attr($loading_image_id); ?>">
                                    <div class="wcpf-image-preview">
                                        <?php if ($loading_image_url) : ?>
                                            <img src="<?php echo esc_url($loading_image_url); ?>" alt="<?php _e('Ảnh Loading', 'wc-product-filter'); ?>" class="loading-image-preview">
                                        <?php endif; ?>
                                    </div>
                                    <button type="button" class="button wcpf-upload-image-button" style="margin-right:5px"><?php _e('Chọn ảnh', 'wc-product-filter'); ?></button>
                                    <?php if ($loading_image_id) : ?>
                                    <button type="button" class="button wcpf-remove-image-button"><?php _e('Xóa ảnh', 'wc-product-filter'); ?></button>
                                    <?php endif; ?>
                                </div>
                                <p class="wcpf-description"><?php _e('Tải lên ảnh loading để thay thế hiệu ứng loading spin mặc định. Nếu không có ảnh, hiệu ứng loading spin mặc định sẽ được sử dụng.', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Chọn bộ lọc cho trang cửa hàng', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <select name="wcpf_general_settings[shop_filter]" class="wcpf-input wcpf-input-select">
                                    <option value=""><?php _e('Không', 'wc-product-filter'); ?></option>
                                    <?php foreach ($filters as $filter_id => $filter) : ?>
                                        <?php if ($filter['active']) : ?>
                                            <option value="<?php echo esc_attr($filter_id); ?>" <?php selected($settings['shop_filter'], $filter_id); ?>>
                                                <?php echo esc_html($filter['name'] ?: 'Bộ lọc ' . $filter_id); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <p class="wcpf-description"><?php _e('Chọn bộ lọc để hiển thị trên ', 'wc-product-filter'); ?> <a href="admin.php?page=wc-settings&tab=products" target="_blank"><strong><?php _e('trang cửa hàng', 'wc-product-filter'); ?></strong></a></p>
                            </td>
                        </tr>
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Hành vi áp dụng bộ lọc', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <label class="wcpf-radio">
                                    <input type="radio" name="wcpf_general_settings[apply_filter_behavior]" value="immediate" <?php checked($settings['apply_filter_behavior'], 'immediate'); ?> class="wcpf-radio-input">
                                    <span><?php _e('Lọc ngay khi chọn thuộc tính', 'wc-product-filter'); ?></span>
                                </label>
                                <label class="wcpf-radio">
                                    <input type="radio" name="wcpf_general_settings[apply_filter_behavior]" value="apply_button" <?php checked($settings['apply_filter_behavior'], 'apply_button'); ?> class="wcpf-radio-input">
                                    <span><?php _e('Xác nhận qua nút', 'wc-product-filter'); ?></span>
                                </label>
                            </td>
                        </tr>
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Ẩn các thuộc tính không có sản phẩm', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <label class="wcpf-checkbox">
                                    <input type="checkbox" name="wcpf_general_settings[hide_empty_terms]" value="1" <?php checked($settings['hide_empty_terms'], 1); ?> class="wcpf-checkbox-input">
                                    <span><?php _e('Ẩn các thuộc tính không có sản phẩm trong giao diện bộ lọc ở frontend', 'wc-product-filter'); ?></span>
                                </label>
                                <p class="wcpf-description"><?php _e('Các thuộc tính không có sản phẩm sau khi lọc sẽ bị loại bỏ', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Số lượng hiển thị của mỗi nhóm thuộc tính', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <input type="number" name="wcpf_general_settings[max_terms_per_attribute]" value="<?php echo esc_attr($settings['max_terms_per_attribute'] ?? 0); ?>" min="0" class="wcpf-input wcpf-input-number" placeholder="0 (hiển thị toàn bộ)" style="width: 180px;">
                                <p class="wcpf-description"><?php _e('Nhập số lượng tối đa hiển thị cho mỗi nhóm thuộc tính (0 để hiển thị toàn bộ). Ví dụ: Nhập 6 để hiển thị 6 thuộc tính, nút "Xem thêm" sẽ hiển thị phần còn lại.', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
						<tr class="wcpf-table-row">
							<th class="wcpf-table-header"><?php _e('Xóa cache bộ lọc', 'wc-product-filter'); ?></th>
							<td class="wcpf-table-cell">
								<?php
								global $wpdb;
								$cache_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}options WHERE option_name LIKE '%wcpf_term_count_%'");
								?>
								<p><?php printf(__('Số lượng cache hiện tại: <span class="wcpf-cache-count">%d</span>', 'wc-product-filter'), $cache_count); ?></p>
								<button type="button" class="wcpf-button wcpf-clear-cache"><?php _e('Xóa cache', 'wc-product-filter'); ?></button>
								<p class="wcpf-description"><?php _e('Xóa tất cả cache liên quan đến số lượng sản phẩm trong bộ lọc (transient wcpf_term_count_). Nên xóa cache sau khi thay đổi sản phẩm hoặc danh mục.', 'wc-product-filter'); ?></p>
							</td>
						</tr>
                    </table>
                </div>
                <div class="wcpf-section">
                    <h2 class="wcpf-section-title"><?php _e('Cài đặt widget', 'wc-product-filter'); ?></h2>
                    <table class="wcpf-table">
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Sử dụng widget', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <label class="wcpf-radio">
                                    <input type="radio" name="wcpf_general_settings[widget_usage]" value="disabled" <?php checked($settings['widget_usage'], 'disabled'); ?> class="wcpf-radio-input">
                                    <span><?php _e('Không sử dụng widget', 'wc-product-filter'); ?></span>
                                </label>
                                <label class="wcpf-radio">
                                    <input type="radio" name="wcpf_general_settings[widget_usage]" value="enabled" <?php checked($settings['widget_usage'], 'enabled'); ?> class="wcpf-radio-input">
                                    <span><?php _e('Sử dụng widget', 'wc-product-filter'); ?></span>
                                </label>
                                <p class="wcpf-description"><?php _e('Nếu chọn "Sử dụng widget", bộ lọc sẽ chỉ hiển thị qua widget và không hiển thị tự động trên trang cửa hàng.', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Chọn bộ lọc cho widget', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <select name="wcpf_general_settings[widget_filter]" class="wcpf-input wcpf-input-select">
                                    <option value=""><?php _e('Không', 'wc-product-filter'); ?></option>
                                    <?php foreach ($filters as $filter_id => $filter) : ?>
                                        <?php if ($filter['active']) : ?>
                                            <option value="<?php echo esc_attr($filter_id); ?>" <?php selected($settings['widget_filter'], $filter_id); ?>>
                                                <?php echo esc_html($filter['name'] ?: 'Bộ lọc ' . $filter_id); ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <p class="wcpf-description"><?php _e('Chọn bộ lọc để sử dụng trong widget.', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
						<tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Thuộc tính đang kích hoạt', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <label class="wcpf-radio">
                                    <input type="radio" name="wcpf_general_settings[selected_filters_position]" value="above_filters" <?php checked($settings['selected_filters_position'], 'above_filters'); ?> class="wcpf-radio-input">
                                    <span><?php _e('Phía trên bộ lọc', 'wc-product-filter'); ?></span>
                                </label>
                                <label class="wcpf-radio">
                                    <input type="radio" name="wcpf_general_settings[selected_filters_position]" value="above_products" <?php checked($settings['selected_filters_position'], 'above_products'); ?> class="wcpf-radio-input">
                                    <span><?php _e('Phía trên danh mục sản phẩm', 'wc-product-filter'); ?></span>
                                </label>
                                <p class="wcpf-description"><?php _e('Áp dụng cho Widget có Layout Vertical', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Trạng thái mặc định của nhóm lọc', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <label class="wcpf-radio">
                                    <input type="radio" name="wcpf_general_settings[default_filter_group_state]" value="open" <?php checked($settings['default_filter_group_state'], 'open'); ?> class="wcpf-radio-input">
                                    <span><?php _e('Mở ra tất cả', 'wc-product-filter'); ?></span>
                                </label>
                                <label class="wcpf-radio">
                                    <input type="radio" name="wcpf_general_settings[default_filter_group_state]" value="closed" <?php checked($settings['default_filter_group_state'], 'closed'); ?> class="wcpf-radio-input">
                                    <span><?php _e('Đóng lại tất cả', 'wc-product-filter'); ?></span>
                                </label>
                                <p class="wcpf-description"><?php _e('Xác định trạng thái mặc định của các nhóm lọc khi tải trang trong layout dọc. Người dùng có thể thay đổi thủ công bằng cách nhấn vào tiêu đề nhóm.', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="wcpf-section">
                    <h2 class="wcpf-section-title"><?php _e('Văn bản tùy chỉnh', 'wc-product-filter'); ?></h2>
                    <table class="wcpf-table">
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Nút Áp dụng', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <input type="text" name="wcpf_general_settings[custom_texts][apply_button]" value="<?php echo esc_attr($settings['custom_texts']['apply_button']); ?>" class="wcpf-input wcpf-input-text">
                                <p class="wcpf-description"><?php _e('Văn bản hiển thị trên nút áp dụng bộ lọc.', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Nút Xóa tất cả', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <input type="text" name="wcpf_general_settings[custom_texts][reset_button]" value="<?php echo esc_attr($settings['custom_texts']['reset_button']); ?>" class="wcpf-input wcpf-input-text">
                                <p class="wcpf-description"><?php _e('Văn bản hiển thị trên nút xóa tất cả bộ lọc.', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Nút Menu trên Mobile', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <input type="text" name="wcpf_general_settings[custom_texts][mobile_menu_button]" value="<?php echo esc_attr($settings['custom_texts']['mobile_menu_button']); ?>" class="wcpf-input wcpf-input-text">
                                <p class="wcpf-description"><?php _e('Văn bản hiển thị trên nút mở menu bộ lọc trên mobile.', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Tiêu đề Menu trên Mobile', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <input type="text" name="wcpf_general_settings[custom_texts][mobile_menu_title]" value="<?php echo esc_attr($settings['custom_texts']['mobile_menu_title']); ?>" class="wcpf-input wcpf-input-text">
                                <p class="wcpf-description"><?php _e('Tiêu đề hiển thị trên menu bộ lọc trên mobile.', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Văn bản Xem thêm', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <input type="text" name="wcpf_general_settings[custom_texts][show_more_text]" value="<?php echo esc_attr($settings['custom_texts']['show_more_text']); ?>" class="wcpf-input wcpf-input-text">
                                <p class="wcpf-description"><?php _e('Văn bản hiển thị trên nút Xem thêm khi có nhiều thuộc tính.', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('Văn bản Thu gọn', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <input type="text" name="wcpf_general_settings[custom_texts][show_less_text]" value="<?php echo esc_attr($settings['custom_texts']['show_less_text']); ?>" class="wcpf-input wcpf-input-text">
                                <p class="wcpf-description"><?php _e('Văn bản hiển thị trên nút Thu gọn khi có nhiều thuộc tính.', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
                <div class="wcpf-section">
                    <h2 class="wcpf-section-title"><?php _e('CSS tùy chỉnh', 'wc-product-filter'); ?></h2>
                    <table class="wcpf-table">
                        <tr class="wcpf-table-row">
                            <th class="wcpf-table-header"><?php _e('CSS tùy chỉnh', 'wc-product-filter'); ?></th>
                            <td class="wcpf-table-cell">
                                <textarea name="wcpf_general_settings[custom_css]" class="wcpf-input wcpf-input-textarea custom_css"><?php echo esc_textarea($settings['custom_css']); ?></textarea>
                                <p class="wcpf-description"><?php _e('Nhập CSS tùy chỉnh để tùy chỉnh giao diện bộ lọc.', 'wc-product-filter'); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
				<div class="wcpf-section">
					<h2 class="wcpf-section-title"><?php _e('Cài đặt SEO', 'wc-product-filter'); ?></h2>
					<table class="wcpf-table">
						<tr class="wcpf-table-row">
							<th class="wcpf-table-header"><?php _e('Sử dụng ID trong URL', 'wc-product-filter'); ?></th>
							<td class="wcpf-table-cell">
								<label class="wcpf-checkbox">
									<input type="checkbox" name="wcpf_general_settings[use_value_id_in_url]" value="1" <?php checked($settings['use_value_id_in_url'] ?? 0, 1); ?> class="wcpf-checkbox-input">
									<span><?php _e('Sử dụng term ID thay vì slug trong URL bộ lọc', 'wc-product-filter'); ?></span>
								</label>
								<p class="wcpf-description"><?php _e('Nếu bật, URL bộ lọc sẽ sử dụng term ID (ví dụ: /filters/cpu-123/) thay vì slug (ví dụ: /filters/cpu-intel-core-i7/). Mặc định sử dụng slug.', 'wc-product-filter'); ?></p>
							</td>
						</tr>
						<tr class="wcpf-table-row">
							<th class="wcpf-table-header"><?php _e('Meta Robots with Filter', 'wc-product-filter'); ?></th>
							<td class="wcpf-table-cell">
								<?php 
									$settings_meta = get_option('wcpf_general_settings', ['meta_robots' => 'default_wp']);
									$value_meta = isset($settings_meta['meta_robots']) ? $settings_meta['meta_robots'] : 'default_wp';
								?>
								<select name="wcpf_general_settings[meta_robots]" class="wcpf-input wcpf-input-select">
									<option value="default_wp" <?php selected($value_meta, 'default_wp'); ?>><?php _e('Default Wordpress', 'wc-product-filter'); ?></option>
									<option value="index_follow" <?php selected($value_meta, 'index_follow'); ?>><?php _e('index, follow', 'wc-product-filter'); ?></option>
									<option value="noindex_nofollow" <?php selected($value_meta, 'noindex_nofollow'); ?>><?php _e('noindex, nofollow', 'wc-product-filter'); ?></option>
								</select>
							</td>
						</tr>
						<tr class="wcpf-table-row">
							<th class="wcpf-table-header"><?php _e('Canonical URL with Filter', 'wc-product-filter'); ?></th>
							<td class="wcpf-table-cell">
								<?php 
									$settings_canonical = get_option('wcpf_general_settings', ['canonical_url' => 'default_wp']);
									$value_canonical = isset($settings_canonical['canonical_url']) ? $settings_canonical['canonical_url'] : 'default_wp';
								?>
								<select name="wcpf_general_settings[canonical_url]" class="wcpf-input wcpf-input-select">
									<option value="default_wp" <?php selected($value_canonical, 'default_wp'); ?>><?php _e('Default Wordpress', 'wc-product-filter'); ?></option>
									<option value="with_filter" <?php selected($value_canonical, 'with_filter'); ?>><?php _e('Bao gồm bộ lọc (With Filter)', 'wc-product-filter'); ?></option>
									<option value="without_filter" <?php selected($value_canonical, 'without_filter'); ?>><?php _e('Không bao gồm bộ lọc (Without Filter)', 'wc-product-filter'); ?></option>
								</select>
								<p class="wcpf-description"><?php _e('Chọn cách hiển thị thẻ canonical: "With Filter" bao gồm toàn bộ URL bộ lọc, "Without Filter" chỉ hiển thị danh mục cha.', 'wc-product-filter'); ?></p>
							</td>
						</tr>
						<tr class="wcpf-table-row">
							<th class="wcpf-table-header"><?php _e('SEO Title', 'wc-product-filter'); ?></th>
							<td class="wcpf-table-cell">
								<select name="wcpf_general_settings[seo_title_format]" class="wcpf-input wcpf-input-select">
									<option value="default" <?php selected($settings['seo_title_format'] ?? 'default', 'default'); ?>><?php _e('Default Wordpress', 'wc-product-filter'); ?></option>
									<option value="title_with_attributes" <?php selected($settings['seo_title_format'] ?? 'default', 'title_with_attributes'); ?>><?php _e('{title} with [attribute] [values] and [attribute] [values]', 'wc-product-filter'); ?></option>
									<option value="title_attributes_colon" <?php selected($settings['seo_title_format'] ?? 'default', 'title_attributes_colon'); ?>><?php _e('{title} [attribute]:[values];[attribute]:[values]', 'wc-product-filter'); ?></option>
									<option value="attribute_title_with_attributes" <?php selected($settings['seo_title_format'] ?? 'default', 'attribute_title_with_attributes'); ?>><?php _e('[attribute 1 values] {title} with [attribute] [values] and [attribute] [values]', 'wc-product-filter'); ?></option>
									<option value="title_values_slash" <?php selected($settings['seo_title_format'] ?? 'default', 'title_values_slash'); ?>><?php _e('{title} - [values] / [values]', 'wc-product-filter'); ?></option>
									<option value="attributes_colon_title" <?php selected($settings['seo_title_format'] ?? 'default', 'attributes_colon_title'); ?>><?php _e('[attribute]:[values];[attribute]:[values] - {title}', 'wc-product-filter'); ?></option>
								</select>
								<p class="wcpf-description"><?php _e('Chọn định dạng tiêu đề SEO khi áp dụng bộ lọc. {title} là tiêu đề trang, [attribute] và [values] là thuộc tính và giá trị được chọn. Chọn "Default" để giữ tiêu đề mặc định.', 'wc-product-filter'); ?></p>
							</td>
						</tr>
					</table>
				</div>
                <div>
                    <button type="submit" class="wcpf-button wcpf-button-submit"><?php _e('Lưu thay đổi', 'wc-product-filter'); ?></button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render the add filter page.
     */
    public function render_add_filter_page() {
        $settings = get_option('wcpf_filter_settings', []);
        $this->render_filter_form($settings);
    }

    /**
     * Render the edit filter page.
     *
     * @param string $filter_id The filter ID.
     */
    public function render_edit_filter_page($filter_id) {
        $filters = get_option('wcpf_filters', []);
        if (!isset($filters[$filter_id])) {
            wp_die(__('Bộ lọc không tồn tại.', 'wc-product-filter'));
        }
        $settings = $filters[$filter_id];
        $this->render_filter_form($settings, $filter_id);
    }


/**
 * Render the filter form.
 *
 * @param array $settings Filter settings.
 * @param string $filter_id Filter ID.
 */
/**
 * Render the filter form.
 *
 * @param array $settings Filter settings.
 * @param string $filter_id Filter ID.
 */
public function render_filter_form($settings, $filter_id = '') {
    $defaults = [
        'name' => '',
        'active' => 1,
        'categories' => [],
        'brands' => [],
        'apply_to_subcategories' => 0,
        'active_attributes' => [],
        'active_attribute_terms' => [],
        'price_ranges' => [
            ['min' => 0, 'max' => 10000000, 'label' => '0 - 10 triệu'],
            ['min' => 10000000, 'max' => 15000000, 'label' => '10 - 15 triệu'],
            ['min' => 15000000, 'max' => 20000000, 'label' => '15 - 20 triệu'],
            ['min' => 20000000, 'max' => 25000000, 'label' => '20 - 25 triệu'],
            ['min' => 25000000, 'max' => 30000000, 'label' => '25 - 30 triệu'],
            ['min' => 30000000, 'max' => 40000000, 'label' => '30 - 40 triệu'],
            ['min' => 40000000, 'max' => null, 'label' => 'Trên 40 triệu'],
        ],
        'attribute_labels' => [],
        'custom_css_classes' => [],
        'layout_style' => [],
        'term_style' => [],
        'display_settings' => [],
        'max_terms' => [],
        'single_select' => [],
        'show_term_product_count' => []
    ];
    $settings = wp_parse_args($settings, $defaults);

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wcpf_filter_settings']) && isset($_POST['wcpf_admin_nonce'])) {
        if (!current_user_can('manage_options')) {
            wp_die(__('Không có quyền truy cập.', 'wc-product-filter'));
        }

        if (!wp_verify_nonce($_POST['wcpf_admin_nonce'], 'wcpf_admin_nonce')) {
            wp_die(__('Xác minh bảo mật thất bại.', 'wc-product-filter'));
        }

        $input = $_POST['wcpf_filter_settings'];
        $sanitized = $this->sanitize_filter_settings($input);

        $filters = get_option('wcpf_filters', []);
        $new_filter_id = $filter_id ?: 'filter_' . uniqid();

        $filters[$new_filter_id] = $sanitized;
        update_option('wcpf_filters', $filters);

        // Redirect to edit page or filters list
        $redirect_url = $filter_id
            ? admin_url('admin.php?page=wcpf-settings&action=edit&filter_id=' . $new_filter_id . '&updated=1')
            : admin_url('admin.php?page=wcpf-settings&updated=1');
        wp_safe_redirect($redirect_url);
        exit;
    }

    $attributes = wc_get_attribute_taxonomies();
    $available_attributes = [];
    foreach ($attributes as $attr) {
        $available_attributes['pa_' . $attr->attribute_name] = $attr->attribute_label;
    }
    if (taxonomy_exists('product_brand')) {
        $available_attributes['product_brand'] = __('THƯƠNG HIỆU', 'wc-product-filter');
    }
	
    $available_attributes['price'] = __('GIÁ', 'wc-product-filter');
    $available_attributes['search'] = __('TÌM KIẾM', 'wc-product-filter');
    $available_attributes['sort_by'] = __('SẮP XẾP THEO', 'wc-product-filter');
    $available_attributes['stock_status'] = __('TRẠNG THÁI TỒN KHO', 'wc-product-filter');
	$available_attributes['product_cat'] = __('DANH MỤC SẢN PHẨM', 'wc-product-filter');
    $available_brands = [];
    if (taxonomy_exists('product_brand')) {
        $brands = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false]);
        foreach ($brands as $brand) {
            $available_brands[$brand->slug] = $brand->name;
        }
    }

    $categories = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'hierarchical' => true,
    ]);

    $available_terms = [];
    foreach ($available_attributes as $taxonomy => $label) {
		if ($taxonomy !== 'price' && $taxonomy !== 'search' && $taxonomy !== 'sort_by' && $taxonomy !== 'stock_status' && taxonomy_exists($taxonomy)) {
			$terms_args = [
				'taxonomy' => $taxonomy,
				'hide_empty' => false,
				'hierarchical' => true,
				'orderby' => 'name',
				'order' => 'ASC',
			];
			wp_cache_delete('wcpf_terms_' . $taxonomy, 'wcpf');
			$terms = get_terms($terms_args);
			if (is_wp_error($terms)) {
				error_log('WCPF: Error fetching terms for taxonomy ' . $taxonomy . ': ' . $terms->get_error_message());
				$terms = [];
			}
			if ($taxonomy === 'product_cat') {
				error_log('WCPF: Terms fetched for product_cat: ' . print_r(array_map(function($term) { return $term->name . ' (ID: ' . $term->term_id . ', Parent: ' . $term->parent . ')'; }, $terms), true));
				error_log('WCPF: Category display mode for product_cat (ADMIN, ignored): ' . ($settings['category_display_mode']['product_cat'] ?? 'not_set'));
				// Chỉ giữ danh mục cha cấp cao nhất (parent = 0)
				$terms = array_filter($terms, function($term) {
					return $term->parent === 0;
				});
				error_log('WCPF: Parent terms for product_cat: ' . print_r(array_map(function($term) { return $term->name . ' (ID: ' . $term->term_id . ', Parent: ' . $term->parent . ')'; }, $terms), true));
			}
			foreach ($terms as $term) {
				$thumbnail_id = get_term_meta($term->term_id, 'thumbnail_id', true);
				$image_url = $thumbnail_id ? wp_get_attachment_thumb_url($thumbnail_id) : '';
				$depth = ($taxonomy === 'product_cat') ? count(get_ancestors($term->term_id, 'product_cat', 'taxonomy')) : 0;
				$available_terms[$taxonomy][$term->slug] = [
					'name' => $term->name,
					'term_id' => $term->term_id,
					'image_url' => $image_url,
					'depth' => $depth
				];
			}
		}
        if ($taxonomy === 'sort_by') {
            $sort_options = [
                'menu_order' => __('Mặc định', 'wc-product-filter'),
                'popularity' => __('Phổ biến', 'wc-product-filter'),
                'rating' => __('Xếp hạng', 'wc-product-filter'),
                'date' => __('Mới nhất', 'wc-product-filter'),
                'price' => __('Giá: Thấp đến cao', 'wc-product-filter'),
                'price-desc' => __('Giá: Cao đến thấp', 'wc-product-filter'),
            ];
            foreach ($sort_options as $slug => $name) {
                $available_terms['sort_by'][$slug] = [
                    'name' => $name,
                    'term_id' => $slug,
                    'image_url' => ''
                ];
            }
        }
        if ($taxonomy === 'stock_status') {
            $stock_options = [
                'stock-in' => __('Còn hàng', 'wc-product-filter'),
                'stock-out' => __('Hết hàng', 'wc-product-filter'),
                'on-sale' => __('Giảm giá', 'wc-product-filter'),
            ];
            foreach ($stock_options as $slug => $name) {
                $available_terms['stock_status'][$slug] = [
                    'name' => $name,
                    'term_id' => $slug,
                    'image_url' => ''
                ];
            }
        }
    }

    // Display success message
    if (isset($_GET['updated'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . __('Cài đặt bộ lọc đã được lưu thành công.', 'wc-product-filter') . '</p></div>';
    }
    ?>
    <div class="wrap wcpf-container">
        <h1 class="wp-heading-inline"><?php echo $filter_id ? __('Sửa bộ lọc', 'wc-product-filter') : __('Thêm bộ lọc mới', 'wc-product-filter'); ?></h1>
        <form method="post" action="" class="wcpf-form" id="wcpf-settings-form">
            <?php settings_fields('wcpf_filter_settings_group'); ?>
            <?php wp_nonce_field('wcpf_admin_nonce', 'wcpf_admin_nonce'); ?>
            <?php if ($filter_id) : ?>
                <input type="hidden" name="wcpf_filter_id" value="<?php echo esc_attr($filter_id); ?>">
            <?php endif; ?>

            <div class="wcpf-section">
                <h2 class="wcpf-section-title"><?php _e('Cài đặt bộ lọc', 'wc-product-filter'); ?></h2>
                <div>
                    <label><?php _e('Tên bộ lọc', 'wc-product-filter'); ?></label>
                    <input type="text" name="wcpf_filter_settings[name]" value="<?php echo esc_attr($settings['name']); ?>" class="wcpf-input wcpf-input-text" required>
                </div>
                <label class="wcpf-checkbox">
                    <input type="checkbox" name="wcpf_filter_settings[active]" value="1" <?php checked($settings['active'], 1); ?> class="wcpf-checkbox-input">
                    <p><?php _e('Kích hoạt bộ lọc này', 'wc-product-filter'); ?></p>
                </label>
            </div>

            <div class="wcpf-section">
                <h2 class="wcpf-section-title"><?php _e('Gán cho danh mục và thương hiệu', 'wc-product-filter'); ?></h2>
                <div>
                    <label><?php _e('Danh mục', 'wc-product-filter'); ?></label>
                    <select name="wcpf_filter_settings[categories][]" multiple class="wcpf-category-select">
                        <option value="all"><?php _e('Chọn tất cả danh mục', 'wc-product-filter'); ?></option>
                        <?php
                        foreach ($categories as $cat) {
                            $prefix = str_repeat('— ', $cat->depth ?? 0);
                            echo '<option value="' . esc_attr($cat->term_id) . '" ' . (in_array($cat->term_id, $settings['categories']) ? 'selected' : '') . '>' . esc_html($prefix . $cat->name) . '</option>';
                        }
                        ?>
                    </select>
                    <p class="wcpf-description"><?php _e('Chọn danh mục để áp dụng bộ lọc.', 'wc-product-filter'); ?></p>
                </div>
                <label class="wcpf-checkbox">
                    <input type="checkbox" name="wcpf_filter_settings[apply_to_subcategories]" value="1" <?php checked($settings['apply_to_subcategories'], 1); ?> class="wcpf-checkbox-input">
                    <p><?php _e('Áp dụng cho danh mục con. Nếu được chọn, bộ lọc này sẽ áp dụng cho tất cả danh mục con của danh mục đã chọn.', 'wc-product-filter'); ?></p>
                </label>
                <?php if (taxonomy_exists('product_brand')): ?>
                    <div>
                        <label><?php _e('Thương hiệu', 'wc-product-filter'); ?></label>
                        <select name="wcpf_filter_settings[brands][]" multiple class="wcpf-brand-select">
                            <option value="all"><?php _e('Chọn tất cả thương hiệu', 'wc-product-filter'); ?></option>
                            <?php
                            foreach ($available_brands as $slug => $name) {
                                echo '<option value="' . esc_attr($slug) . '" ' . (in_array($slug, $settings['brands']) ? 'selected' : '') . '>' . esc_html($name) . '</option>';
                            }
                            ?>
                        </select>
                        <p class="wcpf-description"><?php _e('Hệ thống sẽ sử dụng bộ lọc phù hợp đầu tiên cho các trang thương hiệu.', 'wc-product-filter'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="wcpf-section">
                <h2 class="wcpf-section-title"><?php _e('Kích hoạt thuộc tính', 'wc-product-filter'); ?></h2>
                <div class="wcpf-drag-drop-container">
                    <div class="wcpf-available-attributes">
                        <h3 class="wcpf-sub-title"><?php _e('Thuộc tính có sẵn', 'wc-product-filter'); ?></h3>
                        <ul class="wcpf-sortable">
                            <?php foreach ($available_attributes as $key => $label): ?>
                                <?php if (!in_array($key, $settings['active_attributes'])): ?>
                                    <li data-key="<?php echo esc_attr($key); ?>" class="wcpf-sortable-item"><?php echo esc_html($label); ?></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="wcpf-active-attributes">
                        <h3 class="wcpf-sub-title"><?php _e('Thuộc tính đang hoạt động', 'wc-product-filter'); ?></h3>
                        <ul class="wcpf-sortable">
                            <?php foreach ($settings['active_attributes'] as $key): ?>
                                <?php if (isset($available_attributes[$key])): ?>
                                    <li data-key="<?php echo esc_attr($key); ?>" class="wcpf-sortable-item">
                                        <span class="wcpf-label-editable" data-key="<?php echo esc_attr($key); ?>">
                                            <?php echo esc_html($settings['attribute_labels'][$key] ?: $available_attributes[$key]); ?>
                                        </span>
                                        <input type="hidden" name="wcpf_filter_settings[active_attributes][]" value="<?php echo esc_attr($key); ?>">
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>

<?php foreach ($settings['active_attributes'] as $key): ?>
    <?php if (isset($available_attributes[$key])): ?>
        <div class="wcpf-section">
            <details class="wcpf-attribute-toggle">
                <summary class="wcpf-section-title"><?php echo esc_html($settings['attribute_labels'][$key] ?: $available_attributes[$key]); ?></summary>
                <div class="wcpf-attribute-terms" data-taxonomy="<?php echo esc_attr($key); ?>">
                    <?php if ($key === 'price'): ?>
                        <div class="wcpf-price-ranges">
                            <ul class="wcpf-sortable-price">
                                <?php foreach ($settings['price_ranges'] as $index => $range): ?>
                                    <li class="wcpf-price-range-item">
                                        <input type="number" name="wcpf_filter_settings[price_ranges][<?php echo $index; ?>][min]" value="<?php echo esc_attr($range['min']); ?>" placeholder="Giá tối thiểu" class="wcpf-input wcpf-input-number">
                                        <input type="number" name="wcpf_filter_settings[price_ranges][<?php echo $index; ?>][max]" value="<?php echo $range['max'] !== null ? esc_attr($range['max']) : ''; ?>" placeholder="Giá tối đa" class="wcpf-input wcpf-input-number">
                                        <input type="text" name="wcpf_filter_settings[price_ranges][<?php echo $index; ?>][label]" value="<?php echo esc_attr($range['label']); ?>" placeholder="Nhãn (ví dụ: 0 - 10 triệu)" class="wcpf-input wcpf-input-text">
                                        <button type="button" class="wcpf-button wcpf-button-remove"><?php _e('Xóa', 'wc-product-filter'); ?></button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="wcpf-button wcpf-button-add"><?php _e('Thêm khoảng giá', 'wc-product-filter'); ?></button>
                        </div>
                    <?php elseif ($key !== 'search'): ?>
                        <div class="wcpf-terms-container">
                            <div class="wcpf-available-terms">
                                <h3 class="wcpf-sub-title"><?php _e('Thuộc tính có sẵn', 'wc-product-filter'); ?></h3>
								<ul class="wcpf-sortable-terms wcpf-terms-list" data-taxonomy="<?php echo esc_attr($key); ?>">
									<?php
									if (isset($available_terms[$key]) && !empty($available_terms[$key])) {
										echo '<li data-key="select-all" class="wcpf-sortable-item wcpf-select-all">' . __('Chọn tất cả', 'wc-product-filter') . '</li>';
									}
									if (isset($available_terms[$key])) {
										if ($key === 'product_cat') {
											error_log('WCPF: Available terms for product_cat: ' . print_r(array_map(function($slug) use ($available_terms, $key) {
												return $available_terms[$key][$slug]['name'] . ' (Slug: ' . $slug . ', Depth: ' . $available_terms[$key][$slug]['depth'] . ')';
											}, array_keys($available_terms[$key])), true));
										}
										foreach ($available_terms[$key] as $slug => $data) {
											if (!isset($settings['active_attribute_terms'][$key]) || !in_array($slug, $settings['active_attribute_terms'][$key])) {
												$image_html = $data['image_url'] ? '<img src="' . esc_url($data['image_url']) . '" class="wcpf-term-icon" alt="' . esc_attr($data['name']) . '">' : '<img src="' . esc_url(self::DEFAULT_TERM_IMAGE) . '" class="wcpf-term-icon" alt="' . esc_attr($data['name']) . '">';
												$prefix = ($key === 'product_cat') ? str_repeat('— ', $data['depth']) : '';
												echo '<li data-key="' . esc_attr($slug) . '" data-term-id="' . esc_attr($data['term_id']) . '" class="wcpf-sortable-item">';
												echo '<span class="wcpf-term-image-container">' . $image_html . '</span>';
												echo '<span class="wcpf-term-name">' . esc_html($prefix . $data['name']) . '</span>';
												echo '</li>';
											}
										}
									}
									?>
								</ul>
                            </div>
                            <div class="wcpf-active-terms">
                                <h3 class="wcpf-sub-title"><?php _e('Thuộc tính đang hoạt động', 'wc-product-filter'); ?></h3>
                                <ul class="wcpf-sortable-terms wcpf-terms-list" data-taxonomy="<?php echo esc_attr($key); ?>">
									<?php
									if (isset($settings['active_attribute_terms'][$key])) {
										// Debug: In danh sách active terms
										if ($key === 'product_cat') {
											error_log('WCPF: Active terms for product_cat: ' . print_r($settings['active_attribute_terms'][$key], true));
										}
										foreach ($settings['active_attribute_terms'][$key] as $slug) {
											if (isset($available_terms[$key][$slug])) {
												$data = $available_terms[$key][$slug];
												$image_html = $data['image_url'] ? '<img src="' . esc_url($data['image_url']) . '" class="wcpf-term-icon" alt="' . esc_attr($data['name']) . '">' : '<img src="' . esc_url(self::DEFAULT_TERM_IMAGE) . '" class="wcpf-term-icon" alt="' . esc_attr($data['name']) . '">';
												$prefix = ($key === 'product_cat') ? str_repeat('— ', $data['depth']) : '';
												echo '<li data-key="' . esc_attr($slug) . '" data-term-id="' . esc_attr($data['term_id']) . '" class="wcpf-sortable-item">';
												echo '<span class="wcpf-term-image-container">' . $image_html . '</span>';
												if ($data['image_url']) {
													echo '<button type="button" class="wcpf-remove-term-image" data-term-id="' . esc_attr($data['term_id']) . '">' . __('Xóa ảnh', 'wc-product-filter') . '</button>';
												}
												echo '<span class="wcpf-term-name">' . esc_html($prefix . $data['name']) . '</span>';
												echo '<input type="hidden" name="wcpf_filter_settings[active_attribute_terms][' . esc_attr($key) . '][]" value="' . esc_attr($slug) . '">';
												echo '</li>';
											}
										}
									}
									?>
								</ul>
                            </div>
                        </div>
                    <?php endif; ?>
					<?php if ($key === 'product_cat'): ?>
					<div class="wcpf-custom-layout">
                        <h3 class="wcpf-sub-title"><?php _e('Chức năng danh mục', 'wc-product-filter'); ?></h3>
						<div class="wcpf-display-options">
							<div class="wcpf-layout-options">
								<label><?php _e('Chức năng danh mục', 'wc-product-filter'); ?></label>
								<div class="wcpf-display-thumbnails">
									<label>
										<input type="radio" name="wcpf_filter_settings[category_mode][<?php echo esc_attr($key); ?>]" value="category_filter" <?php checked(isset($settings['category_mode'][$key]) ? $settings['category_mode'][$key] : 'category_filter', 'category_filter'); ?>>
										<?php _e('Như là bộ lọc', 'wc-product-filter'); ?>
									</label>
									<label>
										<input type="radio" name="wcpf_filter_settings[category_mode][<?php echo esc_attr($key); ?>]" value="category_text_link" <?php checked(isset($settings['category_mode'][$key]) ? $settings['category_mode'][$key] : 'category_text_link', 'category_text_link'); ?>>
										<?php _e('Dạng text link', 'wc-product-filter'); ?>
									</label>
								</div>
								<p class="wcpf-description"><?php _e('Nếu chọn Như là bộ lọc, danh mục có chức năng lọc như các thuộc tính khác. Nếu chọn Dạng text link danh mục chỉ có chức năng điều hướng sang URL của danh mục đó.', 'wc-product-filter'); ?></p>
							</div>
						</div>
					</div>
					<?php endif; ?>
                    <div class="wcpf-custom-layout">
                        <h3 class="wcpf-sub-title"><?php _e('Cài đặt hiển thị', 'wc-product-filter'); ?></h3>
                        <div class="wcpf-display-options">
							<div class="wcpf-layout-options">
								<label><?php _e('Hiển thị:', 'wc-product-filter'); ?></label>
								<div class="wcpf-display-thumbnails">
									<label>
										<input type="radio" name="wcpf_filter_settings[display_settings][<?php echo esc_attr($key); ?>]" value="both" <?php checked(isset($settings['display_settings'][$key]) ? $settings['display_settings'][$key] : 'both', 'both'); ?>>
										<?php _e('Desktop và Mobile', 'wc-product-filter'); ?>
									</label>
									<label>
										<input type="radio" name="wcpf_filter_settings[display_settings][<?php echo esc_attr($key); ?>]" value="desktop" <?php checked(isset($settings['display_settings'][$key]) ? $settings['display_settings'][$key] : 'both', 'desktop'); ?>>
										<?php _e('Chỉ Desktop', 'wc-product-filter'); ?>
									</label>
									<label>
										<input type="radio" name="wcpf_filter_settings[display_settings][<?php echo esc_attr($key); ?>]" value="mobile" <?php checked(isset($settings['display_settings'][$key]) ? $settings['display_settings'][$key] : 'both', 'mobile'); ?>>
										<?php _e('Chỉ Mobile', 'wc-product-filter'); ?>
									</label>
								</div>
							</div>
							<?php if ($key === 'product_cat'): ?>
							<div class="wcpf-layout-options">
								<label><?php _e('Chế độ hiển thị danh mục:', 'wc-product-filter'); ?></label>
								<div class="wcpf-display-thumbnails">
									<label>
										<input type="radio" name="wcpf_filter_settings[category_display_mode][<?php echo esc_attr($key); ?>]" value="leaf_only" <?php checked(isset($settings['category_display_mode'][$key]) ? $settings['category_display_mode'][$key] : 'leaf_only', 'leaf_only'); ?>>
										<?php _e('Chỉ danh mục con', 'wc-product-filter'); ?>
									</label>
									<label>
										<input type="radio" name="wcpf_filter_settings[category_display_mode][<?php echo esc_attr($key); ?>]" value="parent_child" <?php checked(isset($settings['category_display_mode'][$key]) ? $settings['category_display_mode'][$key] : 'leaf_only', 'parent_child'); ?>>
										<?php _e('Cả danh mục cha và con', 'wc-product-filter'); ?>
									</label>
									<label>
										<input type="radio" name="wcpf_filter_settings[category_display_mode][<?php echo esc_attr($key); ?>]" value="contextual" <?php checked(isset($settings['category_display_mode'][$key]) ? $settings['category_display_mode'][$key] : 'leaf_only', 'contextual'); ?>>
										<?php _e('Các danh mục cùng cấp', 'wc-product-filter'); ?>
									</label>
								</div>
								<p class="wcpf-description"><?php _e('Chỉ danh mục con: Hiển thị các danh mục con của danh mục hiện tại | Cả danh mục cha và con: Hiển thị toàn bộ cây danh mục | Các danh mục cùng cấp: Hiển thị danh mục cùng cấp với danh mục hiện tại.', 'wc-product-filter'); ?></p>
							</div>
							
							<?php endif; ?>
                        </div>
                    </div>
                    <?php if ($key !== 'search' && $key !== 'sort_by'): ?>
                        <div class="wcpf-layout-settings wcpf-custom-layout">
                            <h3 class="wcpf-sub-title"><?php _e('Cài đặt giao diện', 'wc-product-filter'); ?></h3>
							<?php if ($key !== 'product_cat'): ?>
                            <div class="wcpf-layout-options">
                                <label><?php _e('Giao diện nhóm lọc:', 'wc-product-filter'); ?></label>
                                <div class="wcpf-layout-thumbnails">
                                    <label>
                                        <input type="radio" name="wcpf_filter_settings[layout_style][<?php echo esc_attr($key); ?>]" value="flow" <?php checked(isset($settings['layout_style'][$key]) ? $settings['layout_style'][$key] : 'flow', 'flow'); ?>>
                                        Flow
                                    </label>
                                    <label>
                                        <input type="radio" name="wcpf_filter_settings[layout_style][<?php echo esc_attr($key); ?>]" value="list" <?php checked(isset($settings['layout_style'][$key]) ? $settings['layout_style'][$key] : 'flow', 'list'); ?>>
                                        List
                                    </label>
									<label>
										<input type="radio" name="wcpf_filter_settings[layout_style][<?php echo esc_attr($key); ?>]" value="two-colums" <?php checked(isset($settings['layout_style'][$key]) ? $settings['layout_style'][$key] : 'flow', 'two-colums'); ?>>
										2 Columns
									</label>
									<label>
										<input type="radio" name="wcpf_filter_settings[layout_style][<?php echo esc_attr($key); ?>]" value="three-colums" <?php checked(isset($settings['layout_style'][$key]) ? $settings['layout_style'][$key] : 'flow', 'three-colums'); ?>>
										3 Columns
									</label>
									<label>
										<input type="radio" name="wcpf_filter_settings[layout_style][<?php echo esc_attr($key); ?>]" value="four_colums" <?php checked(isset($settings['layout_style'][$key]) ? $settings['layout_style'][$key] : 'flow', 'four_colums'); ?>>
										4 Columns
									</label>
                                </div>
                            </div>
							<?php endif; ?>
                            <div class="wcpf-term-style-options">
                                <label><?php _e('Giao diện thuộc tính:', 'wc-product-filter'); ?></label>
                                <div class="wcpf-term-thumbnails">
									<?php 
										if ($key == 'product_cat') {
									?>
									 <label>
                                        <input type="radio" name="wcpf_filter_settings[term_style][<?php echo esc_attr($key); ?>]" value="checkbox-square" <?php checked(isset($settings['term_style'][$key]) ? $settings['term_style'][$key] : 'checkbox-square', 'checkbox-square'); ?>>
                                        Checkbox Square
                                    </label>
                                    <label>
                                        <input type="radio" name="wcpf_filter_settings[term_style][<?php echo esc_attr($key); ?>]" value="checkbox-circle" <?php checked(isset($settings['term_style'][$key]) ? $settings['term_style'][$key] : 'checkbox-square', 'checkbox-circle'); ?>>
                                        Checkbox Circle
                                    </label>
									<?php } else { ?>
                                    <label>
                                        <input type="radio" name="wcpf_filter_settings[term_style][<?php echo esc_attr($key); ?>]" value="label" <?php checked(isset($settings['term_style'][$key]) ? $settings['term_style'][$key] : 'label', 'label'); ?>>
                                        Label
                                    </label>
									
                                    <label>
                                        <input type="radio" name="wcpf_filter_settings[term_style][<?php echo esc_attr($key); ?>]" value="checkbox-square" <?php checked(isset($settings['term_style'][$key]) ? $settings['term_style'][$key] : 'label', 'checkbox-square'); ?>>
                                        Checkbox Square
                                    </label>
                                    <label>
                                        <input type="radio" name="wcpf_filter_settings[term_style][<?php echo esc_attr($key); ?>]" value="checkbox-circle" <?php checked(isset($settings['term_style'][$key]) ? $settings['term_style'][$key] : 'label', 'checkbox-circle'); ?>>
                                        Checkbox Circle
                                    </label>
									<?php } ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($key !== 'sort_by' && $key !== 'search'): ?>
                        <div class="wcpf-general-settings wcpf-custom-layout">
                            <h3 class="wcpf-sub-title"><?php _e('Cài đặt chung', 'wc-product-filter'); ?></h3>
                            <div class="wcpf-max-terms-options">
                                <label>
									<?php if ($key === 'product_cat') { ?>
										<?php _e('Số danh mục hiển thị (Show more/Show less)', 'wc-product-filter'); ?>
									<?php } else { ?>
										<?php _e('Số thuộc tính hiển thị (Show more/Show less)', 'wc-product-filter'); ?>
									<?php } ?>
								</label>
                                <input type="number" name="wcpf_filter_settings[max_terms][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr(isset($settings['max_terms'][$key]) ? $settings['max_terms'][$key] : ''); ?>" min="0" class="wcpf-input wcpf-input-number" placeholder="<?php _e('Sử dụng cài đặt chung', 'wc-product-filter'); ?>" style="width:190px;">
                            </div>
                            <div class="wcpf-show-term-product-count-options">
                                <label class="wcpf-checkbox">
                                    <input type="checkbox" name="wcpf_filter_settings[show_term_product_count][<?php echo esc_attr($key); ?>]" value="1" <?php checked(isset($settings['show_term_product_count'][$key]) ? $settings['show_term_product_count'][$key] : 0, 1); ?> class="wcpf-checkbox-input">
                                    <span>
										<?php if ($key === 'product_cat') { ?>
											<?php _e('Hiển thị số lượng sản phẩm cho các danh mục', 'wc-product-filter'); ?>
										<?php } else { ?>
											<?php _e('Hiển thị số lượng sản phẩm các thuộc tính', 'wc-product-filter'); ?>
										<?php } ?>
									</span>
                                </label>
                            </div>
							<?php if ($key !== 'price'): ?>
                            <div class="wcpf-single-select-options">
                                <label class="wcpf-checkbox">
                                    <input type="checkbox" name="wcpf_filter_settings[single_select][<?php echo esc_attr($key); ?>]" value="1" <?php checked(isset($settings['single_select'][$key]) ? $settings['single_select'][$key] : 0, 1); ?> class="wcpf-checkbox-input">
									<span>
										<?php if ($key === 'product_cat') { ?>
											<?php _e('Chọn một danh mục mỗi thời điểm (Single Select).', 'wc-product-filter'); ?>
										<?php } else { ?>
											<?php _e('Chọn một thuộc tính mỗi thời điểm (Single Select).', 'wc-product-filter'); ?>
										<?php } ?>
									</span>
                                </label>
                            </div>
							<?php endif; ?>
							
                        </div>
                    <?php endif; ?>
                    <div class="wcpf-custom-css-class wcpf-custom-layout">
                        <h3 class="wcpf-sub-title"><?php _e('Class tùy chỉnh', 'wc-product-filter'); ?></h3>
                        <label><?php _e('Tên class CSS tùy chỉnh cho ' . esc_html($settings['attribute_labels'][$key] ?: $available_attributes[$key]), 'wc-product-filter'); ?></label>
                        <input type="text" name="wcpf_filter_settings[custom_css_classes][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($settings['custom_css_classes'][$key] ?? ''); ?>" class="wcpf-input wcpf-input-text">
                        <p class="wcpf-description"><?php _e('Nhập tên class CSS tùy chỉnh (ví dụ: ten-tuy-chinh). Class này sẽ được thêm vào thẻ wcpf-filter-group trong frontend.', 'wc-product-filter'); ?></p>
                    </div>
                </div>
            </details>
        </div>
    <?php endif; ?>
<?php endforeach; ?>
            <div>
                <button type="submit" class="wcpf-button wcpf-button-submit"><?php _e('Lưu thay đổi', 'wc-product-filter'); ?></button>
            </div>
        </form>
    </div>
    <?php
}
// Add this function at the end of the WCPF_Admin class
private function add_child_terms(&$sorted_terms, $terms, $parent_id, $depth) {
    $children = array_filter($terms, function($term) use ($parent_id) { return $term->parent == $parent_id; });
    usort($children, function($a, $b) { return strcmp($a->name, $b->name); });
    foreach ($children as $child) {
        $sorted_terms[] = $child;
        $this->add_child_terms($sorted_terms, $terms, $child->term_id, $depth + 1);
    }
}
}
new WCPF_Admin();
