<?php
/**
 * WCPF_Product_Filter class handles the frontend product filtering functionality.
 */
class WCPF_Product_Filter {
    /**
     * Initialize the plugin.
     */
    public function init() {
        $settings = get_option('wcpf_settings', ['auto_display' => 1]);
        $general_settings = get_option('wcpf_general_settings', [
            'widget_usage' => 'disabled',
            'apply_filter_behavior' => 'apply_button'
        ]);
        
        // Chỉ thêm action hiển thị bộ lọc phía trên sản phẩm nếu widget_usage không được bật
        if ($settings['auto_display'] && $general_settings['widget_usage'] !== 'enabled') {
            add_action('woocommerce_before_shop_loop', [$this, 'render_filter_ui'], 5);
        }
        
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts'], 20);
        add_action('pre_get_posts', [$this, 'filter_products_query']);
        add_action('init', [$this, 'add_rewrite_rules']);
        add_filter('query_vars', [$this, 'add_query_vars']);
        add_action('parse_request', [$this, 'parse_category_request'], 1);
        add_shortcode('wcpf_filter', [$this, 'shortcode_filter']);
        // Hook để flush khi danh mục hoặc cấu hình thay đổi
        add_action('create_product_cat', [$this, 'flush_rewrite_rules_on_activation']);
        add_action('edit_product_cat', [$this, 'flush_rewrite_rules_on_activation']);
        add_action('delete_product_cat', [$this, 'flush_rewrite_rules_on_activation']);
		add_action('created_term', [$this, 'flush_rewrite_rules_on_activation']);
        add_action('edited_term', [$this, 'flush_rewrite_rules_on_activation']);
        add_action('delete_term', [$this, 'flush_rewrite_rules_on_activation']);
		
        add_action('update_option_woocommerce_shop_page_id', [$this, 'flush_rewrite_rules_on_activation']);
        add_action('update_option_woocommerce_permalinks', [$this, 'flush_rewrite_rules_on_activation']);
		       // Hook để flush khi danh mục hoặc cấu hình thay đổi
        
        add_action('update_option_woocommerce_shop_page_id', [$this, 'flush_rewrite_rules_on_activation']);
        add_action('update_option_woocommerce_permalinks', [$this, 'flush_rewrite_rules_on_activation']);
		
        register_activation_hook(__FILE__, [$this, 'flush_rewrite_rules_on_activation']);
        register_deactivation_hook(__FILE__, [$this, 'flush_rewrite_rules_on_activation']);
		// Initialize SEO Optimizer
		if (class_exists('WCPF_SEO_Optimizer')) {
			$seo_optimizer = new WCPF_SEO_Optimizer();
			$seo_optimizer->init();
		}
    }

    /**
     * Flush rewrite rules on activation or configuration changes.
     */
    public function flush_rewrite_rules_on_activation() {
        $this->add_rewrite_rules();
        flush_rewrite_rules();
    }

    /**
     * Shortcode to render filter UI.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function shortcode_filter($atts) {
        if (!is_shop() && !is_product_category() && !is_tax('product_brand')) {
            return '<p>' . __('This shortcode can only be used on WooCommerce shop, category, or brand pages.', 'wc-product-filter') . '</p>';
        }
        ob_start();
        $this->render_filter_ui();
        return ob_get_clean();
    }

    /**
     * Enqueue frontend scripts and styles.
     */
    public function enqueue_scripts() {
        $is_brand_page = is_tax('product_brand');
        $queried_object = get_queried_object();
        $taxonomy = $is_brand_page ? 'product_brand' : 'product_cat';
        $category_slug = $queried_object && isset($queried_object->slug) ? $queried_object->slug : '';
        $category_base = get_option('woocommerce_permalinks')['category_base'] ?: 'danh-muc-san-pham';
        $brand_base = get_option('woocommerce_brand_permalink') ?: 'brand';
        $brand_base = ltrim($brand_base, '/');
        $use_category_base = $category_base !== 'danh-muc-san-pham';
        $shop_id = wc_get_page_id('shop');
        $shop_page_slug = get_page_uri($shop_id);

        if (is_shop() || is_product_category() || $is_brand_page) {
            if (!defined('WCPF_URL')) {
                define('WCPF_URL', plugin_dir_url(__FILE__));
            }

            wp_enqueue_style('wcpf-filter', WCPF_URL . 'assets/css/filter.css', [], '1.9.30');
            wp_enqueue_script('wcpf-filter', WCPF_URL . 'assets/js/filter.min.js', ['jquery'], '1.9.30', true);
            $general_settings = get_option('wcpf_general_settings', [
                'apply_filter_behavior' => 'apply_button',
                'max_terms_per_attribute' => 0,
                'hide_empty_terms' => 1,
                'loading_image_id' => 0,
                'custom_texts' => [
                    'show_more_text' => '+ Xem thêm',
                    'show_less_text' => '- Thu gọn'
                ],
                'widget_usage' => 'disabled',
                'widget_filter' => '',
                'shop_filter' => ''
            ]);
            $show_more_text = $general_settings['custom_texts']['show_more_text'] ?? 'Xem thêm';
            $show_less_text = $general_settings['custom_texts']['show_less_text'] ?? 'Thu gọn';
            $loading_image_url = '';
            if (!empty($general_settings['loading_image_id'])) {
                $loading_image_url = wp_get_attachment_url($general_settings['loading_image_id']);
            }

            // Tìm bộ lọc phù hợp
            $filters = get_option('wcpf_filters', []);
            $matched_filter = null;
            $default_max_terms = (int) ($general_settings['max_terms_per_attribute'] ?? 0);
            $is_widget_context = did_action('dynamic_sidebar') || doing_action('dynamic_sidebar');

            if ($general_settings['widget_usage'] === 'enabled' && !empty($general_settings['widget_filter'])) {
                // Ưu tiên bộ lọc widget, không phụ thuộc is_widget_context
                $filter_id = $general_settings['widget_filter'];
                if (isset($filters[$filter_id]) && $filters[$filter_id]['active']) {
                    $matched_filter = $filters[$filter_id];
                    //error_log('WCPF Debug: Widget context - Matched filter ID: ' . $filter_id . ', category_mode: ' . ($matched_filter['category_mode']['product_cat'] ?? 'not set'));
                } else {
                    //error_log('WCPF Debug: Widget context - No active filter found for widget_filter ID: ' . $filter_id);
                }
            } elseif (is_shop()) {
                $filter_id = $general_settings['shop_filter'] ?? '';
                if ($filter_id && isset($filters[$filter_id]) && $filters[$filter_id]['active']) {
                    $matched_filter = $filters[$filter_id];
                    //error_log('WCPF Debug: Shop context - Matched filter ID: ' . $filter_id . ', category_mode: ' . ($matched_filter['category_mode']['product_cat'] ?? 'not set'));
                }
            } elseif ($is_brand_page && $category_slug) {
                foreach ($filters as $filter_id => $filter) {
                    if (!isset($filter['active']) || !$filter['active']) continue;
                    $filter_brands = isset($filter['brands']) ? (array)$filter['brands'] : [];
                    if (in_array($category_slug, $filter_brands)) {
                        $matched_filter = $filter;
                        break;
                    }
                }
            } elseif (is_product_category() && $category_slug) {
                $category_term = get_term_by('slug', $category_slug, 'product_cat');
                $category_id = $category_term ? $category_term->term_id : null;
                foreach ($filters as $filter_id => $filter) {
                    if (!isset($filter['active']) || !$filter['active']) continue;
                    $filter_categories = isset($filter['categories']) ? array_map('intval', (array)$filter['categories']) : [];
                    $apply_to_subcategories = isset($filter['apply_to_subcategories']) && $filter['apply_to_subcategories'];
                    if ($category_id && (empty($filter_categories) || in_array($category_id, $filter_categories))) {
                        $matched_filter = $filter;
                        break;
                    }
                    if ($apply_to_subcategories && $category_id) {
                        $ancestors = get_ancestors($category_id, 'product_cat');
                        foreach ($ancestors as $ancestor_id) {
                            if (in_array($ancestor_id, $filter_categories)) {
                                $matched_filter = $filter;
                                break 2;
                            }
                        }
                    }
                }
            }

            // Xác định category_mode
            $category_mode = 'category_filter'; // Giá trị mặc định
            if ($matched_filter && isset($matched_filter['category_mode']) && is_array($matched_filter['category_mode']) && isset($matched_filter['category_mode']['product_cat'])) {
                $category_mode = $matched_filter['category_mode']['product_cat'];
            } else {
                // Fallback nếu không có matched_filter hoặc category_mode không được thiết lập
                $settings = get_option('wcpf_settings', []);
                if (isset($settings['category_mode']['product_cat'])) {
                    $category_mode = $settings['category_mode']['product_cat'];
                }
                //error_log('WCPF Debug: Fallback to default category_mode: ' . $category_mode);
            }

            wp_localize_script('wcpf-filter', 'wcpf_params', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'home_url' => home_url(),
                'category' => $category_slug ?: $shop_page_slug,
                'taxonomy' => $taxonomy,
                'category_base' => $category_base,
                'brand_base' => $brand_base,
                'use_category_base' => $use_category_base,
                'shop_slug' => $shop_page_slug,
                'nonce' => wp_create_nonce('wcpf_nonce'),
                'apply_filter_behavior' => in_array($general_settings['apply_filter_behavior'], ['immediate', 'apply_button']) ? $general_settings['apply_filter_behavior'] : 'apply_button',
                'max_terms_per_attribute' => $default_max_terms,
                'hide_empty_terms' => (bool) ($general_settings['hide_empty_terms'] ?? 1),
                'loading_image_url' => $loading_image_url,
                'single_select' => (isset($matched_filter['single_select']) && is_array($matched_filter['single_select']) && !empty($matched_filter['single_select'])) ? $matched_filter['single_select'] : [],
                'category_mode' => $category_mode,
                'i18n' => [
                    'loading' => __('Đang tải...', 'wc-product-filter'),
                    'no_results' => __('Không tìm thấy sản phẩm.', 'wc-product-filter'),
                    'show_more' => $show_more_text,
                    'show_less' => $show_less_text
                ],
                'apply_button_template' => $general_settings['custom_texts']['apply_button'] ?? 'Áp dụng',
            ]);

            //error_log('WCPF Debug: wcpf_params - category_mode: ' . $category_mode);

            // Áp dụng CSS tùy chỉnh toàn cục
            if (!empty($general_settings['custom_css'])) {
                wp_add_inline_style('wcpf-filter', $general_settings['custom_css']);
            }
        }
    }

    /**
     * Add rewrite rules for filter URLs.
     */
    public function add_rewrite_rules() {
        $category_base = get_option('woocommerce_permalinks')['category_base'] ?: 'danh-muc-san-pham';
        $brand_base = get_option('woocommerce_brand_permalink') ?: 'brand';
        $brand_base = ltrim($brand_base, '/');
        $shop_id = wc_get_page_id('shop');
        $shop_page_slug = get_page_uri($shop_id);
		$general_settings = get_option('wcpf_general_settings', ['use_value_id_in_url' => 0]);
		$use_value_id_in_url = !empty($general_settings['use_value_id_in_url']);

        //error_log('WCPF Debug: Adding rewrite rules. category_base=' . $category_base . ', brand_base=' . $brand_base . ', shop_page_slug=' . $shop_page_slug);

        // Rule cho trang cửa hàng
        add_rewrite_rule(
            $shop_page_slug . '/filters/(.+)/page/([0-9]+)/?$',
            'index.php?post_type=product&filters=$matches[1]&paged=$matches[2]',
            'top'
        );
        add_rewrite_rule(
            $shop_page_slug . '/filters/(.+)/?$',
            'index.php?post_type=product&filters=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            $shop_page_slug . '/?$',
            'index.php?post_type=product',
            'top'
        );
        add_rewrite_rule(
            $shop_page_slug . '/filters/(.+)/(.+)/?$',
            'index.php?post_type=product&filters=$matches[1]/$matches[2]',
            'top'
        );
        add_rewrite_rule(
            $shop_page_slug . '/filters/(.+)/(.+)/page/([0-9]+)/?$',
            'index.php?post_type=product&filters=$matches[1]/$matches[2]&paged=$matches[3]',
            'top'
        );
		
		// Rule cho trang cửa hàng với product_cat
		
		add_rewrite_rule(
			$shop_page_slug . '/filters/product_cat-([a-z0-9\-]+(?:-[a-z0-9\-]+)*)/?$',
			'index.php?post_type=product&filters=product_cat-$matches[1]',
			'top'
		);
		add_rewrite_rule(
			$shop_page_slug . '/filters/product_cat-([a-z0-9\-]+(?:-[a-z0-9\-]+)*)/page/([0-9]+)/?$',
			'index.php?post_type=product&filters=product_cat-$matches[1]&paged=$matches[2]',
			'top'
		);
		
		// Rule cho trang categories với product_cat
		
		add_rewrite_rule(
			$category_base . '/filters/product_cat-([a-z0-9\-]+(?:-[a-z0-9\-]+)*)/?$',
			'index.php?post_type=product&filters=product_cat-$matches[1]',
			'top'
		);
		add_rewrite_rule(
			$category_base . '/filters/product_cat-([a-z0-9\-]+(?:-[a-z0-9\-]+)*)/page/([0-9]+)/?$',
			'index.php?post_type=product&filters=product_cat-$matches[1]&paged=$matches[2]',
			'top'
		);
		
		// Rule cho trang Brand với product_cat
		
		add_rewrite_rule(
			$brand_base . '/filters/product_cat-([a-z0-9\-]+(?:-[a-z0-9\-]+)*)/?$',
			'index.php?post_type=product&filters=product_cat-$matches[1]',
			'top'
		);
		add_rewrite_rule(
			$brand_base . '/filters/product_cat-([a-z0-9\-]+(?:-[a-z0-9\-]+)*)/page/([0-9]+)/?$',
			'index.php?post_type=product&filters=product_cat-$matches[1]&paged=$matches[2]',
			'top'
		);

        // Rule cho trang thương hiệu (product_brand)
		
        add_rewrite_rule(
            $brand_base . '/([^/]+)/filters/(.+)/page/([0-9]+)/?$',
            'index.php?product_brand=$matches[1]&filters=$matches[2]&paged=$matches[3]',
            'top'
        );
        add_rewrite_rule(
            $brand_base . '/([^/]+)/filters/(.+)/?$',
            'index.php?product_brand=$matches[1]&filters=$matches[2]',
            'top'
        );
        add_rewrite_rule(
            $brand_base . '/([^/]+)/?$',
            'index.php?product_brand=$matches[1]',
            'top'
        );

        // Rule cho trang danh mục sản phẩm (product_cat) với category_base
        add_rewrite_rule(
            $category_base . '/([^/]+)/filters/(.+)/page/([0-9]+)/?$',
            'index.php?product_cat=$matches[1]&filters=$matches[2]&paged=$matches[3]',
            'top'
        );
        add_rewrite_rule(
            $category_base . '/([^/]+)/filters/(.+)/?$',
            'index.php?product_cat=$matches[1]&filters=$matches[2]',
            'top'
        );
        add_rewrite_rule(
            $category_base . '/([^/]+)/?$',
            'index.php?product_cat=$matches[1]',
            'top'
        );

        // Rule cho danh mục con
        add_rewrite_rule(
            $category_base . '/([^/]+)/([^/]+)/filters/(.+)/page/([0-9]+)/?$',
            'index.php?product_cat=$matches[1]/$matches[2]&filters=$matches[3]&paged=$matches[4]',
            'top'
        );
        add_rewrite_rule(
            $category_base . '/([^/]+)/([^/]+)/filters/(.+)/?$',
            'index.php?product_cat=$matches[1]/$matches[2]&filters=$matches[3]',
            'top'
        );
        add_rewrite_rule(
            $category_base . '/([^/]+)/([^/]+)/?$',
            'index.php?product_cat=$matches[1]/$matches[2]',
            'top'
        );

        // Rule cho danh mục sản phẩm khi Strip Category Base bật (URL không có category_base)
        add_rewrite_rule(
            '([^/]+)/filters/(.+)/page/([0-9]+)/?$',
            'index.php?product_cat=$matches[1]&filters=$matches[2]&paged=$matches[3]',
            'top'
        );
        add_rewrite_rule(
            '([^/]+)/filters/(.+)/?$',
            'index.php?product_cat=$matches[1]&filters=$matches[2]',
            'top'
        );
		
		// Thêm quy tắc cho stock_status trên trang cửa hàng
		add_rewrite_rule(
			$shop_page_slug . '/filters/stock_status-([a-z\-]+(?:-[a-z\-]+)*)/?$',
			'index.php?post_type=product&filters=stock_status-$matches[1]',
			'top'
		);
		add_rewrite_rule(
			$shop_page_slug . '/filters/stock_status-([a-z\-]+(?:-[a-z\-]+)*)/page/([0-9]+)/?$',
			'index.php?post_type=product&filters=stock_status-$matches[1]&paged=$matches[2]',
			'top'
		);

		// Thêm quy tắc cho stock_status trên trang danh mục sản phẩm
		add_rewrite_rule(
			$category_base . '/([^/]+)/filters/stock_status-([a-z\-]+(?:-[a-z\-]+)*)/?$',
			'index.php?product_cat=$matches[1]&filters=stock_status-$matches[2]',
			'top'
		);
		add_rewrite_rule(
			$category_base . '/([^/]+)/filters/stock_status-([a-z\-]+(?:-[a-z\-]+)*)/page/([0-9]+)/?$',
			'index.php?product_cat=$matches[1]&filters=stock_status-$matches[2]&paged=$matches[3]',
			'top'
		);

		// Thêm quy tắc cho stock_status trên trang thương hiệu
		add_rewrite_rule(
			$brand_base . '/([^/]+)/filters/stock_status-([a-z\-]+(?:-[a-z\-]+)*)/?$',
			'index.php?product_brand=$matches[1]&filters=stock_status-$matches[2]',
			'top'
		);
		add_rewrite_rule(
			$brand_base . '/([^/]+)/filters/stock_status-([a-z\-]+(?:-[a-z\-]+)*)/page/([0-9]+)/?$',
			'index.php?product_brand=$matches[1]&filters=stock_status-$matches[2]&paged=$matches[3]',
			'top'
		);

        // Rule cho danh mục sản phẩm không có filters (tối ưu để tránh xung đột)
        // Chỉ áp dụng cho các slug tồn tại trong product_cat
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'slugs']);
        foreach ($categories as $slug) {
            add_rewrite_rule(
                $slug . '/?$',
                'index.php?product_cat=' . $slug,
                'top'
            );
        }
        //error_log('WCPF Debug: Added specific rewrite rules for product_cat slugs: ' . implode(', ', $categories));

        
		if ($use_value_id_in_url) {
			add_rewrite_rule(
				$shop_page_slug . '/filters/(.+)/(.+)/?$',
				'index.php?post_type=product&filters=$matches[1]/$matches[2]',
				'top'
			);
			add_rewrite_rule(
				$shop_page_slug . '/filters/(.+)/(.+)/page/([0-9]+)/?$',
				'index.php?post_type=product&filters=$matches[1]/$matches[2]&paged=$matches[3]',
				'top'
			);
		}
		
		// Rule cho filters với pa_* khi use_value_id_in_url bật
		$product_taxonomies = get_object_taxonomies('product');
		$product_taxonomies = array_diff($product_taxonomies, ['product_type', 'product_visibility', 'product_cat', 'product_tag', 'product_shipping_class', 'product_brand']);
		foreach ($product_taxonomies as $taxonomy) {
			$taxonomy_obj = get_taxonomy($taxonomy);
			if (!empty($taxonomy_obj->public)) {
				$taxonomy_base = !empty($taxonomy_obj->rewrite['slug']) ? $taxonomy_obj->rewrite['slug'] : $taxonomy_obj->name;
				$taxonomy_base = ltrim($taxonomy_base, '/');
				if ($use_value_id_in_url) {
					
					add_rewrite_rule(
						$taxonomy_base . '/([0-9]+)/filters/' . $taxonomy_obj->name . '-([0-9]+(?:-[0-9]+)*)/page/([0-9]+)/?$',
						'index.php?' . $taxonomy_obj->name . '=$matches[1]&filters=' . $taxonomy_obj->name . '-$matches[2]&paged=$matches[3]',
						'top'
					);
					add_rewrite_rule(
						$taxonomy_base . '/([0-9]+)/filters/' . $taxonomy_obj->name . '-([0-9]+(?:-[0-9]+)*)/?$',
						'index.php?' . $taxonomy_obj->name . '=$matches[1]&filters=' . $taxonomy_obj->name . '-$matches[2]',
						'top'
					);
					add_rewrite_rule(
						$taxonomy_base . '/([0-9]+)/?$',
						'index.php?' . $taxonomy_obj->name . '=$matches[1]',
						'top'
					);
				} else {
					add_rewrite_rule(
						$taxonomy_base . '/([^/]+)/filters/' . $taxonomy_obj->name . '-([^/]+)/page/([0-9]+)/?$',
						'index.php?' . $taxonomy_obj->name . '=$matches[1]&filters=' . $taxonomy_obj->name . '-$matches[2]&paged=$matches[3]',
						'top'
					);
					add_rewrite_rule(
						$taxonomy_base . '/([^/]+)/filters/' . $taxonomy_obj->name . '-([^/]+)/?$',
						'index.php?' . $taxonomy_obj->name . '=$matches[1]&filters=' . $taxonomy_obj->name . '-$matches[2]',
						'top'
					);
					add_rewrite_rule(
						$taxonomy_base . '/([^/]+)/?$',
						'index.php?' . $taxonomy_obj->name . '=$matches[1]',
						'top'
					);
				}
			}
		}
    }

    /**
     * Parse category request to handle custom URLs.
     *
     * @param WP $wp The WordPress environment.
     */
	public function parse_category_request($wp) {
		$category_base = get_option('woocommerce_permalinks')['category_base'] ?: 'danh-muc-san-pham';
		$shop_id = wc_get_page_id('shop');
		$shop_page_slug = get_page_uri($shop_id);
		$brand_base = get_option('woocommerce_brand_permalink') ?: 'brand';
		$brand_base = ltrim($brand_base, '/');
		$general_settings = get_option('wcpf_general_settings', ['use_value_id_in_url' => 0]);
		$use_value_id_in_url = !empty($general_settings['use_value_id_in_url']);

		$request_path = trim($wp->request, '/');

		// Bỏ qua nếu là trang cửa hàng hoặc thương hiệu
		if (strpos($request_path, $shop_page_slug) === 0 || strpos($request_path, $brand_base) === 0) {
			return;
		}

		// Xử lý URL có filters
		if (preg_match('#^([^/]+)/filters/(.+)/?$#', $request_path, $matches) ||
			preg_match('#^([^/]+)/filters/(.+)/page/([0-9]+)/?$#', $request_path, $matches)) {
			$category_slug = $matches[1];
			// Kiểm tra category bằng slug hoặc ID tùy theo cài đặt
			$term = $use_value_id_in_url ? get_term_by('id', (int) $category_slug, 'product_cat') : get_term_by('slug', $category_slug, 'product_cat');
			if ($term) {
				$wp->query_vars['product_cat'] = $use_value_id_in_url ? $term->term_id : $category_slug;
				$wp->query_vars['filters'] = $matches[2];
				if (isset($matches[3])) {
					$wp->query_vars['paged'] = $matches[3];
				}
				unset($wp->query_vars['pagename']);
				unset($wp->query_vars['name']);
				unset($wp->query_vars['post_type']);
				//error_log('WCPF Debug: parse_category_request matched product_cat with filters, ' . ($use_value_id_in_url ? 'id=' : 'slug=') . $category_slug . ', filters=' . $matches[2] . ', paged=' . (isset($matches[3]) ? $matches[3] : ''));
			} else {
				//error_log('WCPF Debug: parse_category_request không tìm thấy term product_cat, ' . ($use_value_id_in_url ? 'id=' : 'slug=') . $category_slug);
			}
			return;
		}

		// Xử lý danh mục sản phẩm không có filters
		if (!empty($wp->query_vars['pagename']) && empty($wp->query_vars['product_cat']) && empty($wp->query_vars['name'])) {
			$slug = $wp->query_vars['pagename'];
			// Kiểm tra term bằng slug hoặc ID tùy theo cài đặt
			$term = $use_value_id_in_url ? get_term_by('id', (int) $slug, 'product_cat') : get_term_by('slug', $slug, 'product_cat');
			if ($term) {
				// Kiểm tra xem slug có phải là page không
				$page = get_page_by_path($slug);
				if (!$page) {
					$wp->query_vars['product_cat'] = $use_value_id_in_url ? $term->term_id : $slug;
					unset($wp->query_vars['pagename']);
					unset($wp->query_vars['name']);
					unset($wp->query_vars['post_type']);
					//error_log('WCPF Debug: parse_category_request reassigned pagename to product_cat, ' . ($use_value_id_in_url ? 'id=' : 'slug=') . $slug);
				} else {
					//error_log('WCPF Debug: parse_category_request skipped, ' . ($use_value_id_in_url ? 'id=' : 'slug=') . $slug . ' is a page');
				}
			}
		} elseif (!empty($wp->query_vars['name']) && empty($wp->query_vars['product_cat']) && empty($wp->query_vars['pagename'])) {
			$slug = $wp->query_vars['name'];
			// Kiểm tra term bằng slug hoặc ID tùy theo cài đặt
			$term = $use_value_id_in_url ? get_term_by('id', (int) $slug, 'product_cat') : get_term_by('slug', $slug, 'product_cat');
			if ($term) {
				// Kiểm tra xem slug có phải là post không
				$post = get_posts(['name' => $slug, 'post_type' => 'post', 'posts_per_page' => 1]);
				if (empty($post)) {
					$wp->query_vars['product_cat'] = $use_value_id_in_url ? $term->term_id : $slug;
					unset($wp->query_vars['name']);
					unset($wp->query_vars['pagename']);
					unset($wp->query_vars['post_type']);
					//error_log('WCPF Debug: parse_category_request reassigned name to product_cat, ' . ($use_value_id_in_url ? 'id=' : 'slug=') . $slug);
				} else {
					//error_log('WCPF Debug: parse_category_request skipped, ' . ($use_value_id_in_url ? 'id=' : 'slug=') . $slug . ' is a post');
				}
			}
		}
	}

    /**
     * Add custom query vars.
     *
     * @param array $vars Existing query vars.
     * @return array Updated query vars.
     */
    public function add_query_vars($vars) {
        $vars[] = 'filters';
        return $vars;
    }

    /**
     * Render the filter UI.
     */
	/**
	 * Render the filter UI.
	 */
	public function render_filter_ui() {
		if (!is_shop() && !is_product_category() && !is_tax('product_brand')) return;

		// Lấy danh sách bộ lọc và cài đặt chung
		$filters = get_option('wcpf_filters', []);
		$general_settings = get_option('wcpf_general_settings', [
			'shop_filter' => '',
			'widget_usage' => 'disabled',
			'widget_filter' => '',
			'custom_texts' => [],
			'show_term_product_count' => 0,
			'hide_empty_terms' => 0,
			'apply_filter_behavior' => 'apply_button',
			'max_terms_per_attribute' => 0,
			'use_value_id_in_url' => 0
		]);
		$filter_settings = get_option('wcpf_filter_settings', [
            'category_display_mode' => 'parent_child' // Default mode
        ]);
		$hide_empty_terms = !empty($general_settings['hide_empty_terms']);
		$use_value_id_in_url = !empty($general_settings['use_value_id_in_url']);
		$default_max_terms = (int) ($general_settings['max_terms_per_attribute'] ?? 0);
		$queried_object = get_queried_object();
		$is_brand_page = is_tax('product_brand');
		$is_category_page = is_product_category();
		$taxonomy = $is_brand_page ? 'product_brand' : 'product_cat';
		$category_slug = $queried_object && isset($queried_object->slug) ? ($use_value_id_in_url && isset($queried_object->term_id) ? $queried_object->term_id : $queried_object->slug) : '';
		$category_term = $is_category_page ? ($use_value_id_in_url ? get_term_by('id', (int) $category_slug, 'product_cat') : get_term_by('slug', $category_slug, 'product_cat')) : null;
		//$category_slug, 'product_cat') : get_term_by('slug', $category_slug, 'product_cat')) : null;
        $category_id = $category_term ? $category_term->term_id : null;
		$shop_id = wc_get_page_id('shop');
		$shop_page_slug = get_page_uri($shop_id);

		// Tìm bộ lọc phù hợp
		$matched_filter = null;
		$category_term = $is_category_page ? ($use_value_id_in_url ? get_term_by('id', (int) $category_slug, 'product_cat') : get_term_by('slug', $category_slug, 'product_cat')) : null;
		$category_id = $category_term ? $category_term->term_id : null;

		// Kiểm tra nếu đang trong ngữ cảnh widget
		$is_widget_context = did_action('dynamic_sidebar') || doing_action('dynamic_sidebar');

		if ($is_widget_context && $general_settings['widget_usage'] === 'enabled' && !empty($general_settings['widget_filter'])) {
			$filter_id = $general_settings['widget_filter'];
			if (isset($filters[$filter_id]) && $filters[$filter_id]['active']) {
				$matched_filter = $filters[$filter_id];
				//error_log('WCPF Debug: render_filter_ui - Matched widget filter, filter_id=' . $filter_id . ', filter_name=' . ($matched_filter['name'] ?? ''));
			}
		} else {
			foreach ($filters as $filter_id => $filter) {
				if (!isset($filter['active']) || !$filter['active']) {
					continue;
				}

				$filter_categories = isset($filter['categories']) ? array_map('intval', (array)$filter['categories']) : [];
				$filter_brands = isset($filter['brands']) ? (array)$filter['brands'] : [];
				$apply_to_subcategories = isset($filter['apply_to_subcategories']) && $filter['apply_to_subcategories'];

				// Kiểm tra trang cửa hàng
				if (is_shop() && $general_settings['shop_filter'] === $filter_id) {
					$matched_filter = $filter;
					break;
				}

				// Kiểm tra danh mục sản phẩm
				if ($is_category_page && $category_id) {
					// Kiểm tra danh mục trực tiếp
					if (in_array($category_id, $filter_categories)) {
						$matched_filter = $filter;
						break;
					}
					// Kiểm tra danh mục cha nếu apply_to_subcategories bật
					if ($apply_to_subcategories) {
						$ancestors = get_ancestors($category_id, 'product_cat');
						foreach ($ancestors as $ancestor_id) {
							if (in_array($ancestor_id, $filter_categories)) {
								$matched_filter = $filter;
								break 2; // Thoát cả vòng lặp ngoài
							}
						}
					}
				}

				// Kiểm tra trang thương hiệu
				if ($is_brand_page) {
					// Chuẩn hóa filter_brands khi use_value_id_in_url bật
					$brands_to_check = $filter_brands;
					if ($use_value_id_in_url) {
						$converted_brands = [];
						foreach ($filter_brands as $brand_value) {
							$brand_term = get_term_by('slug', $brand_value, 'product_brand');
							if ($brand_term) {
								$converted_brands[] = $brand_term->term_id;
							} else {
								$converted_brands[] = $brand_value; // Giữ nguyên nếu không tìm thấy term
							}
						}
						$brands_to_check = $converted_brands;
					}
					$brand_term = $use_value_id_in_url ? get_term_by('id', (int) $category_slug, 'product_brand') : get_term_by('slug', $category_slug, 'product_brand');
					$brand_value = $brand_term ? ($use_value_id_in_url ? $brand_term->term_id : $brand_term->slug) : $category_slug;
					if (in_array($brand_value, $brands_to_check)) {
						$matched_filter = $filter;
						break;
					}
				}
			}
		}

		// Nếu không tìm thấy bộ lọc phù hợp, thoát
		if (!$matched_filter) {
			//error_log('WCPF Debug: render_filter_ui - No matching filter found for taxonomy=' . $taxonomy . ', category_slug=' . $category_slug . ', is_shop=' . is_shop() . ', is_widget_context=' . ($is_widget_context ? 'true' : 'false'));
			return;
		}

		// Cấu hình bộ lọc
		$settings = [
			'active_attributes' => isset($matched_filter['active_attributes']) ? (array)$matched_filter['active_attributes'] : [],
			'active_attribute_terms' => isset($matched_filter['active_attribute_terms']) ? (array)$matched_filter['active_attribute_terms'] : [],
			'price_ranges' => isset($matched_filter['price_ranges']) ? (array)$matched_filter['price_ranges'] : [
				['min' => 0, 'max' => 10000000, 'label' => '0 - 10 triệu'],
				['min' => 10000000, 'max' => 15000000, 'label' => '10 - 15 triệu'],
				['min' => 15000000, 'max' => 20000000, 'label' => '15 - 20 triệu'],
				['min' => 20000000, 'max' => 25000000, 'label' => '20 - 25 triệu'],
				['min' => 25000000, 'max' => 30000000, 'label' => '25 - 30 triệu'],
				['min' => 30000000, 'max' => 40000000, 'label' => '30 - 40 triệu'],
				['min' => 40000000, 'max' => null, 'label' => 'Trên 40 triệu'],
			],
			'attribute_labels' => isset($matched_filter['attribute_labels']) ? (array)$matched_filter['attribute_labels'] : [],
			'custom_css_classes' => isset($matched_filter['custom_css_classes']) ? (array)$matched_filter['custom_css_classes'] : [],
			'custom_texts' => $general_settings['custom_texts'] ?: [
				'apply_x_button' => 'Xem %d kết quả',
				'reset_button' => 'Xoá hết',
				'mobile_menu_button' => 'Bộ lọc',
				'mobile_menu_title' => 'BỘ LỌC',
				'show_more_text' => '+ Xem thêm',
				'show_less_text' => '- Thu gọn'
			],
			'show_term_product_count' => isset($matched_filter['show_term_product_count']) ? (array)$matched_filter['show_term_product_count'] : [],
			'max_terms' => isset($matched_filter['max_terms']) ? (array)$matched_filter['max_terms'] : [],
			'layout_styles' => isset($matched_filter['layout_style']) ? (array)$matched_filter['layout_style'] : [],
			'term_styles' => isset($matched_filter['term_style']) ? (array)$matched_filter['term_style'] : [],
			'display_settings' => isset($matched_filter['display_settings']) ? (array)$matched_filter['display_settings'] : []
		];

		// Debug cài đặt layout_styles và term_styles
		//error_log('WCPF Debug: render_filter_ui matched_filter layout_styles=' . print_r($settings['layout_styles'], true));
		//error_log('WCPF Debug: render_filter_ui matched_filter term_styles=' . print_r($settings['term_styles'], true));

		$attributes = wc_get_attribute_taxonomies();
		$active_attributes = $settings['active_attributes'];
		$filtered_attributes = array_filter($attributes, function($attr) use ($active_attributes) {
			return in_array('pa_' . $attr->attribute_name, $active_attributes);
		});

		if ($is_brand_page) {
			$active_attributes = array_diff($active_attributes, ['product_brand']);
		}

		$selected_filters = $this->get_selected_filters();
		$min_price = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? floatval($_GET['min_price']) : null;
		$max_price = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? floatval($_GET['max_price']) : null;

		$price_ranges = $settings['price_ranges'];
		$filter_terms = [];
		foreach ($selected_filters as $tax => $terms) {
			foreach ($terms as $term) {
				$filter_terms[] = $tax . ':' . ($use_value_id_in_url ? $term->term_id : $term->slug);
			}
		}
		//error_log('WCPF Debug: render_filter_ui filter_terms=' . print_r($filter_terms, true));
		// Chuẩn bị số lượng sản phẩm cho từng term nếu show_term_product_count hoặc hide_empty_terms bật
		$term_product_counts = (new WCPF_Product_Filter())->calculate_term_product_counts(
			$active_attributes,
			$filter_terms,
			$settings,
			$category_slug,
			$taxonomy,
			$hide_empty_terms,
			$min_price,
			$max_price
		);
		
		// Tính số lượng sản phẩm cho product_cat riêng biệt
        $category_counts = $this->calculate_category_counts($active_attributes, $settings, $hide_empty_terms);
        if (!empty($category_counts)) {
            $term_product_counts['product_cat'] = $category_counts['product_cat'] ?? [];
        }

		//error_log('WCPF Debug: render_filter_ui filter_id=' . ($filter_id ?? 'unknown') . ', filter_name=' . ($matched_filter['name'] ?? '') . ', selected_filters=' . print_r($selected_filters, true) . ', total_products=' . $total_products . ', page_type=' . ($is_brand_page ? 'brand' : ($is_category_page ? 'category' : 'shop')) . ', active_attributes=' . print_r($active_attributes, true) . ', term_product_counts=' . print_r($term_product_counts, true));
		?>
		<div class="wcpf-filter-wrapper horizontal woocommerce">
			<div class="wcpf-mobile-filter-header">
				<button class="wcpf-filter-button <?php echo (!empty($selected_filters) || $min_price !== null || $max_price !== null) ? 'wcpf-filter-active' : ''; ?>">
					<i class="un-filter-v2"></i><?php echo esc_html($settings['custom_texts']['mobile_menu_button']); ?>
				</button>
				<?php if (taxonomy_exists('product_brand') && in_array('product_brand', $settings['active_attributes']) && !$is_brand_page) : ?>
					<?php
					$brands = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false, 'slug' => $settings['active_attribute_terms']['product_brand'] ?? []]);
					$ordered_brands = [];
					foreach ($settings['active_attribute_terms']['product_brand'] ?? [] as $slug) {
						foreach ($brands as $brand) {
							if ($brand->slug === $slug) {
								$ordered_brands[] = $brand;
								break;
							}
						}
					}
					?>
					<div class="wcpf-brand-tags-container">
						<?php foreach ($ordered_brands as $brand) : ?>
							<?php
							$brand_image = get_term_meta($brand->term_id, 'thumbnail_id', true);
							$image_url = $brand_image ? wp_get_attachment_url($brand_image) : '';
							if (!$image_url) continue;

							$args = [
								'post_type' => 'product',
								'post_status' => 'publish',
								'posts_per_page' => 1,
								'fields' => 'ids',
								'tax_query' => [
									'relation' => 'AND',
									[
										'taxonomy' => 'product_brand',
										'field' => $use_value_id_in_url ? 'term_id' : 'slug',
										'terms' => $use_value_id_in_url ? $brand->term_id : $brand->slug,
									],
								],
							];

							if (!is_shop() && !empty($category_slug)) {
								$args['tax_query'][] = [
									'taxonomy' => 'product_cat',
									'field' => $use_value_id_in_url ? 'term_id' : 'slug',
									'terms' => $category_slug,
								];
							}

							$query = new WP_Query($args);
							$has_products = $query->have_posts();

							//error_log('WCPF Debug: render_brand_tags brand=' . ($use_value_id_in_url ? $brand->term_id : $brand->slug) . ', has_products=' . ($has_products ? 'true' : 'false'));
							?>
							<a href="#" class="wcpf-brand-tag <?php echo $has_products ? '' : 'wcpf-brand-disabled'; ?>" 
							   data-taxonomy="product_brand" 
							   data-term="<?php echo esc_attr($use_value_id_in_url ? $brand->term_id : $brand->slug); ?>">
								<img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($brand->name); ?>">
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<?php
			$general_settings = get_option('wcpf_general_settings', ['selected_filters_position' => 'default', 'widget_usage' => 'disabled', 'use_value_id_in_url' => 0]);
			$is_horizontal_layout = isset($matched_filter['layout_style']) && $matched_filter['layout_style'] === 'horizontal';

			if (!empty($selected_filters) || $min_price !== null || $max_price !== null) {
				$should_render = true;
				if ($general_settings['widget_usage'] === 'enabled' && $is_horizontal_layout && $general_settings['selected_filters_position'] === 'above_products') {
					$should_render = false;
				}

				if ($should_render) {
					if ($general_settings['selected_filters_position'] === 'default' || $general_settings['selected_filters_position'] === 'above_filters' || $general_settings['widget_usage'] !== 'enabled') {
						?>
						<div class="wcpf-selected-filters">
							<?php foreach ($selected_filters as $taxonomy => $terms) : ?>
								<?php foreach ($terms as $term) : ?>
									<?php
									// Kiểm tra số lượng sản phẩm của term khi hide_empty_terms bật
									$should_display = true;
									if ($hide_empty_terms && isset($term_product_counts[$taxonomy][$term->slug])) {
										$should_display = $term_product_counts[$taxonomy][$term->slug] > 0;
									}
									if (!$should_display) {
										continue;
									}
									$data_term = $taxonomy === 'search' || $taxonomy === 'sort_by' || $taxonomy === 'stock_status' ? $term->slug : ($use_value_id_in_url ? $term->term_id : $term->slug);
									?>
									<span class="wcpf-filter-tag" 
										  data-taxonomy="<?php echo esc_attr($taxonomy); ?>" 
										  data-term="<?php echo esc_attr($use_value_id_in_url ? $term->term_id : $term->slug); ?>">
										<?php echo esc_html($term->name); ?>
										<a href="#" class="wcpf-remove-filter" 
										   data-taxonomy="<?php echo esc_attr($taxonomy); ?>" 
										   data-term="<?php echo esc_attr($data_term); ?>" ></a>
									</span>
								<?php endforeach; ?>
							<?php endforeach; ?>
							<?php if ($min_price !== null || $max_price !== null) : ?>
								<?php
								$price_label = '';
								foreach ($price_ranges as $range) {
									if ($min_price == $range['min'] && ($max_price == $range['max'] || ($range['max'] === null && $max_price === null))) {
										$price_label = $range['label'];
										break;
									}
								}
								$price_has_products = true;
								if ($hide_empty_terms && isset($term_product_counts['price'][$min_price . '-' . ($max_price ?? 'max')])) {
									$price_has_products = $term_product_counts['price'][$min_price . '-' . ($max_price ?? 'max')] > 0;
								}
								if ($price_has_products) : ?>
									<span class="wcpf-filter-tag" 
										  data-taxonomy="price" 
										  data-term="price-<?php echo esc_attr($min_price); ?>-<?php echo esc_attr($max_price ?? 'max'); ?>">
										<?php echo esc_html($price_label ?: ($min_price . ($max_price ? '-' . $max_price : ''))); ?>
										<a href="#" class="wcpf-remove-filter" 
										   data-taxonomy="price" 
										   data-term="price-<?php echo esc_attr($min_price); ?>-<?php echo esc_attr($max_price ?? 'max'); ?>"></a>
									</span>
								<?php endif; ?>
							<?php endif; ?>
							<?php if (!empty($selected_filters) || ($min_price !== null && $max_price !== null && $price_has_products)) : ?>
								<a href="#" class="wcpf-reset-filters"><?php echo esc_html($settings['custom_texts']['reset_button']); ?></a>
							<?php endif; ?>
						</div>
						<?php
					}
				}
			}
			?>

			<div class="wcpf-filter-options">
				<?php foreach ($active_attributes as $filter_key) : ?>
					<?php
					// Kiểm tra display_settings
					$display_setting = isset($settings['display_settings'][$filter_key]) ? $settings['display_settings'][$filter_key] : 'both';
					$is_mobile = wp_is_mobile();
					if (($display_setting === 'desktop' && $is_mobile) || ($display_setting === 'mobile' && !$is_mobile)) {
						continue;
					}

					$custom_class = !empty($settings['custom_css_classes'][$filter_key]) ? esc_attr($settings['custom_css_classes'][$filter_key]) : '';
					$show_term_product_count = !empty($settings['show_term_product_count'][$filter_key]);
					$max_terms = isset($settings['max_terms'][$filter_key]) && is_numeric($settings['max_terms'][$filter_key]) ? (int)$settings['max_terms'][$filter_key] : $default_max_terms;

					// Sửa cách lấy layout_style và term_style
					$layout_style = isset($settings['layout_styles'][$filter_key]) && !empty($settings['layout_styles'][$filter_key]) ? esc_attr($settings['layout_styles'][$filter_key]) : 'flow';
					$term_style = isset($settings['term_styles'][$filter_key]) && !empty($settings['term_styles'][$filter_key]) ? esc_attr($settings['term_styles'][$filter_key]) : 'label';
					//error_log("WCPF Debug: render_filter_ui filter_key=$filter_key, layout_style=$layout_style, term_style=$term_style, custom_class=$custom_class");
					?>
				<?php if ($filter_key === 'product_cat') : ?>
					<?php
					// Debug matched_filter và settings
					//error_log('WCPF Debug: render_filter_ui product_cat - matched_filter=' . print_r($matched_filter, true));
					//error_log('WCPF Debug: render_filter_ui product_cat - settings=' . print_r($settings, true));
					$active_terms = $settings['active_attribute_terms']['product_cat'] ?? [];
					//error_log('WCPF Debug: render_filter_ui product_cat - active_terms=' . print_r($active_terms, true));

					if (empty($active_terms)) {
						//error_log('WCPF Debug: render_filter_ui product_cat - No active terms, skipping');
						continue;
					}

					// Xác định chế độ hiển thị danh mục từ matched_filter
					$category_display_mode = 'parent_child'; // Giá trị mặc định
					if (isset($matched_filter['category_display_mode']['product_cat'])) {
						$category_display_mode = $matched_filter['category_display_mode']['product_cat'];
					}
					//error_log('WCPF Debug: render_filter_ui product_cat - raw matched_filter[category_display_mode]=' . print_r($matched_filter['category_display_mode'] ?? 'null', true));
					//error_log('WCPF Debug: render_filter_ui product_cat - category_display_mode=' . $category_display_mode);

					// Xác định chế độ category_mode từ matched_filter
					$category_mode = 'category_filter'; // Giá trị mặc định
					if (isset($matched_filter['category_mode']) && is_array($matched_filter['category_mode']) && isset($matched_filter['category_mode']['product_cat'])) {
						$category_mode = $matched_filter['category_mode']['product_cat'];
					} elseif (isset($settings['category_mode']['product_cat'])) {
						$category_mode = $settings['category_mode']['product_cat'];
					}
					//error_log('WCPF Debug: render_filter_ui product_cat - category_mode=' . $category_mode);
					//error_log('WCPF Debug: render_filter_ui product_cat - raw matched_filter[category_mode]=' . print_r($matched_filter['category_mode'] ?? 'null', true));
					//error_log('WCPF Debug: render_filter_ui product_cat - raw settings[category_mode]=' . print_r($settings['category_mode'] ?? 'null', true));
					//error_log('WCPF Debug: render_filter_ui product_cat - rendering for desktop container=wcpf-filter-grid');

					$term_args = [
						'taxonomy' => 'product_cat',
						'hide_empty' => $hide_empty_terms,
						'hierarchical' => true,
					];

					// Xử lý danh mục Uncategorized
					$uncategorized_id = get_option('default_product_cat', 0);
					if ($uncategorized_id) {
						$uncategorized_term = get_term_by('id', $uncategorized_id, 'product_cat');
						if ($uncategorized_term) {
							$uncategorized_slug = $uncategorized_term->slug;
							$uncategorized_count = isset($term_product_counts['product_cat'][$uncategorized_slug]) ? (int) $term_product_counts['product_cat'][$uncategorized_slug] : 0;
							//error_log('WCPF Debug: render_filter_ui product_cat - uncategorized_id=' . $uncategorized_id . ', slug=' . $uncategorized_slug . ', count=' . $uncategorized_count);
							if ($uncategorized_slug === 'uncategorized' && $uncategorized_count === 0) {
								$term_args['exclude'] = [$uncategorized_id];
								//error_log('WCPF Debug: render_filter_ui product_cat - excluding uncategorized_id=' . $uncategorized_id . ' (empty and default slug)');
							} else {
								//error_log('WCPF Debug: render_filter_ui product_cat - keeping uncategorized_id=' . $uncategorized_id . ' (has products or renamed)');
							}
						} else {
							//error_log('WCPF Debug: render_filter_ui product_cat - uncategorized_id=' . $uncategorized_id . ' not found');
						}
					}

					// Xử lý term_args dựa trên category_display_mode
					if ($category_display_mode === 'parent_child') {
						$term_ids = [];
						foreach ($active_terms as $slug) {
							$term = get_term_by('slug', $slug, 'product_cat');
							if ($term) {
								$term_ids[] = $term->term_id;
								$child_ids = get_term_children($term->term_id, 'product_cat');
								if (!is_wp_error($child_ids)) {
									$term_ids = array_merge($term_ids, $child_ids);
								}
							}
						}
						$term_ids = array_unique($term_ids);
						if (!empty($term_ids)) {
							$term_args['include'] = $term_ids;
						} else {
							$term_args['slug'] = $active_terms;
						}
						//error_log('WCPF Debug: render_filter_ui product_cat - parent_child mode, term_ids=' . print_r($term_ids, true));
					} elseif ($category_display_mode === 'leaf_only') {
						if ($is_category_page && $category_id) {
							$child_check_args = [
								'taxonomy' => 'product_cat',
								'parent' => $category_id,
								'hide_empty' => $hide_empty_terms,
								'exclude' => $term_args['exclude'] ?? [],
							];
							$child_terms = get_terms($child_check_args);
							if (!is_wp_error($child_terms) && !empty($child_terms)) {
								$term_args['parent'] = $category_id;
								unset($term_args['slug']);
								unset($term_args['include']);
								//error_log('WCPF Debug: render_filter_ui product_cat - leaf_only mode, category_id=' . $category_id . ' has children, fetching all child categories');
							} else {
								$parent_id = $this->wp_get_term_parent_id($category_id, 'product_cat') ?: 0;
								$term_args['parent'] = $parent_id;
								unset($term_args['slug']);
								unset($term_args['include']);
								//error_log('WCPF Debug: render_filter_ui product_cat - leaf_only mode, category_id=' . $category_id . ' has no children, fetching all sibling categories, parent_id=' . $parent_id);
							}
						} else {
							$term_args['parent'] = 0;
							$term_args['slug'] = $active_terms;
							//error_log('WCPF Debug: render_filter_ui product_cat - leaf_only mode, no category page, setting parent=0');
						}
					} elseif ($category_display_mode === 'contextual') {
						if ($is_category_page && $category_id) {
							$parent_id = $this->wp_get_term_parent_id($category_id, 'product_cat') ?: 0;
							$term_args['parent'] = $parent_id;
							unset($term_args['slug']);
							unset($term_args['include']);
							//error_log('WCPF Debug: render_filter_ui product_cat - contextual mode, fetching all sibling categories, parent_id=' . $parent_id);
						} else {
							$term_args['parent'] = 0;
							$term_args['slug'] = $active_terms;
							//error_log('WCPF Debug: render_filter_ui product_cat - contextual mode, no category page, setting parent=0');
						}
					}

					//error_log('WCPF Debug: render_filter_ui product_cat - term_args=' . print_r($term_args, true));

					// Lấy danh mục
					$categories = get_terms($term_args);
					if (is_wp_error($categories)) {
						//error_log('WCPF Debug: render_filter_ui product_cat - get_terms error: ' . $categories->get_error_message());
						continue;
					}
					if (empty($categories)) {
						//error_log('WCPF Debug: render_filter_ui product_cat - No categories found');
						continue;
					}

					// Log danh mục trả về
					$category_slugs = wp_list_pluck($categories, 'slug');
					//error_log('WCPF Debug: render_filter_ui product_cat - categories returned=' . print_r($category_slugs, true));

					// Debug term_product_counts trước khi xử lý
					//error_log('WCPF Debug: render_filter_ui product_cat - term_product_counts before processing=' . print_r($term_product_counts['product_cat'] ?? [], true));

					// Hàm tính số lượng sản phẩm đệ quy
					if (!function_exists('wcpf_get_recursive_product_count')) {
						function wcpf_get_recursive_product_count($term_id, $taxonomy = 'product_cat') {
							$count = (int) get_term($term_id, $taxonomy)->count;
							$child_terms = get_terms([
								'taxonomy' => $taxonomy,
								'parent' => $term_id,
								'hide_empty' => false,
								'fields' => 'ids',
							]);
							if (!is_wp_error($child_terms)) {
								foreach ($child_terms as $child_id) {
									$count += wcpf_get_recursive_product_count($child_id, $taxonomy);
								}
							}
							return $count;
						}
					}

					// Bổ sung số lượng sản phẩm cho danh mục (chỉ nếu chưa có)
					foreach ($categories as $category) {
						if (!isset($term_product_counts['product_cat'][$category->slug])) {
							$product_count = wcpf_get_recursive_product_count($category->term_id, 'product_cat');
							$term_product_counts['product_cat'][$category->slug] = $product_count;
							//error_log('WCPF Debug: render_filter_ui product_cat - Calculated recursive product count for ' . $category->slug . ': ' . $product_count);
						}
						// Log danh mục con để kiểm tra
						$child_terms = get_terms([
							'taxonomy' => 'product_cat',
							'parent' => $category->term_id,
							'hide_empty' => false,
							'fields' => 'all',
						]);
						if (!is_wp_error($child_terms) && !empty($child_terms)) {
							$child_slugs = wp_list_pluck($child_terms, 'slug');
							$child_counts = [];
							foreach ($child_terms as $child) {
								$child_counts[$child->slug] = wcpf_get_recursive_product_count($child->term_id, 'product_cat');
							}
							//error_log('WCPF Debug: render_filter_ui product_cat - Child terms of ' . $category->slug . ': ' . print_r($child_slugs, true));
							//error_log('WCPF Debug: render_filter_ui product_cat - Child term counts of ' . $category->slug . ': ' . print_r($child_counts, true));
						}
					}

					// Debug term_product_counts sau khi xử lý
					//error_log('WCPF Debug: render_filter_ui product_cat - term_product_counts after processing=' . print_r($term_product_counts['product_cat'] ?? [], true));

					$ordered_categories = [];
					if ($category_display_mode === 'parent_child') {
						$categories_by_id = [];
						foreach ($categories as $cat) {
							$categories_by_id[$cat->term_id] = $cat;
						}
						$ordered_categories = $this->build_category_tree($categories_by_id, 0);
						//error_log('WCPF Debug: render_filter_ui product_cat - parent_child mode, ordered_categories=' . print_r(wp_list_pluck($ordered_categories, 'slug'), true));
					} else {
						$ordered_categories = $categories;
						//error_log('WCPF Debug: render_filter_ui product_cat - leaf_only/contextual mode, ordered_categories=' . print_r(wp_list_pluck($ordered_categories, 'slug'), true));
					}

					if (empty($ordered_categories)) {
						//error_log('WCPF Debug: render_filter_ui product_cat - No ordered categories');
						continue;
					}

					$category_terms_count = count($ordered_categories);
					$show_toggle = $max_terms > 0 && $category_terms_count > $max_terms;
					?>
					<div class="wcpf-filter-group category<?php echo esc_attr($custom_class); ?>">
						<button class="wcpf-filter-toggle" data-taxonomy="product_cat">
							<?php echo esc_html($settings['attribute_labels']['product_cat'] ?: __('DANH MỤC', 'wc-product-filter')); ?>
							<?php if (!empty($selected_filters['product_cat'])) : ?>
								<span class="wcpf-selected-count"><?php echo count($selected_filters['product_cat']); ?></span>
							<?php endif; ?>
						</button>
						<div class="wcpf-filter-menu" data-taxonomy="product_cat" style="display: none;">
							<div class="wcpf-filter-grid list" data-max-terms="<?php echo esc_attr($max_terms); ?>">
								<?php foreach ($ordered_categories as $index => $category) : ?>
									<?php
									$count = isset($term_product_counts['product_cat'][$category->slug]) ? (int) $term_product_counts['product_cat'][$category->slug] : 0;
									//error_log('WCPF Debug: render_filter_ui product_cat - Category ' . $category->slug . ' count: ' . $count);
									if ($hide_empty_terms && $count === 0) {
										continue;
									}
									$is_hidden = $show_toggle && $index >= $max_terms ? 'hidden' : '';
									$depth = $category_display_mode === 'parent_child' ? ($category->depth ?? 0) : 0;
									//error_log('WCPF Debug: render_filter_ui product_cat - Rendering category ' . $category->slug . ' with mode=' . $category_mode);
									?>
									<?php if ($category_mode === 'category_filter') : ?>
										<?php
										$is_selected = in_array($use_value_id_in_url ? $category->term_id : $category->slug, wp_list_pluck($selected_filters['product_cat'] ?? [], $use_value_id_in_url ? 'term_id' : 'slug'));
										//error_log('WCPF Debug: render_filter_ui product_cat - Category ' . $category->slug . ', is_selected=' . ($is_selected ? 'true' : 'false'));
										?>
										<span class="wcpf-filter-label <?php echo esc_attr($term_style); ?> <?php echo $is_selected ? 'selected' : ''; ?> <?php echo $is_hidden; ?>" 
											  data-taxonomy="product_cat" 
											  data-term="<?php echo esc_attr($use_value_id_in_url ? $category->term_id : $category->slug); ?>" 
											  style="padding-left: <?php echo $depth * 20; ?>px;">
											<?php if ($term_style !== 'label') : ?>
												<input type="checkbox" <?php checked($is_selected); ?>>
												<span class="checkmark"></span>
											<?php endif; ?>
											<span class="term-title"><?php echo esc_html($category->name); ?></span>
											<?php if ($show_term_product_count && $count > 0) : ?>
												<span class="wcpf-term-count"><?php echo esc_html($count); ?></span>
											<?php endif; ?>
										</span>
									<?php else : // category_text_link ?>
										<?php
										$term_link = get_term_link($category, 'product_cat');
										if (is_wp_error($term_link)) {
											$term_link = '#';
											//error_log('WCPF Debug: render_filter_ui product_cat - Failed to get term link for ' . $category->slug);
										}
										?>
										<a class="wcpf-filter-label wcpf-text-link <?php echo esc_attr($term_style); ?> <?php echo $is_hidden; ?>" 
										   href="<?php echo esc_url($term_link); ?>" 
										   data-taxonomy="product_cat" 
										   data-term="<?php echo esc_attr($use_value_id_in_url ? $category->term_id : $category->slug); ?>" 
										   style="padding-left: <?php echo $depth * 20; ?>px;">
											<span class="term-title"><?php echo esc_html($category->name); ?></span>
											<?php if ($show_term_product_count && $count > 0) : ?>
												<span class="wcpf-term-count"><?php echo esc_html($count); ?></span>
											<?php endif; ?>
										</a>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				<?php endif; ?>
					<?php if ($filter_key === 'price') : ?>
						<?php
						$price_terms_count = count($price_ranges);
						$show_toggle = $max_terms > 0 && $price_terms_count > $max_terms;
						?>
						<div class="wcpf-filter-group <?php echo $custom_class; ?>">
							<button class="wcpf-filter-toggle" data-taxonomy="price">
								<?php echo esc_html($settings['attribute_labels']['price'] ?: __('KHOẢNG GIÁ', 'wc-product-filter')); ?>
								<?php if ($min_price !== null && $max_price !== null) : ?>
									<span class="wcpf-selected-count">1</span>
								<?php endif; ?>
							</button>
							<div class="wcpf-filter-menu" data-taxonomy="price" style="display: none;">
								<div class="wcpf-filter-grid <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
									<?php foreach ($price_ranges as $index => $range) : ?>
										<?php
										if ($hide_empty_terms && isset($term_product_counts['price'][$range['min'] . '-' . ($range['max'] ?? 'max')])) {
											$count = $term_product_counts['price'][$range['min'] . '-' . ($range['max'] ?? 'max')] ?? 0;
											if ($count === 0) continue;
										}
										$is_hidden = $show_toggle && $index >= $max_terms ? 'hidden' : '';
										?>
										<span class="wcpf-filter-label wcpf-price-range <?php echo esc_attr($term_style); ?> <?php echo ($min_price == $range['min'] && $max_price == $range['max']) ? 'selected' : ''; ?> <?php echo $is_hidden; ?>" 
											  data-min-price="<?php echo esc_attr($range['min']); ?>" 
											  data-max-price="<?php echo esc_attr($range['max'] ?? ''); ?>">
											<?php if ($term_style !== 'label') : ?>
												<input type="checkbox" <?php checked($min_price == $range['min'] && $max_price == $range['max']); ?>>
												<span class="checkmark"></span>
											<?php endif; ?>
											<?php echo esc_html($range['label']); ?>
											<?php if ($show_term_product_count && isset($term_product_counts['price'][$range['min'] . '-' . ($range['max'] ?? 'max')])) : ?>
												<span class="wcpf-term-count"><?php echo esc_html($term_product_counts['price'][$range['min'] . '-' . ($range['max'] ?? 'max')]); ?></span>
											<?php endif; ?>
										</span>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					<?php elseif ($filter_key === 'product_brand' && taxonomy_exists('product_brand') && !$is_brand_page) : ?>
					<?php
					$active_terms = $settings['active_attribute_terms']['product_brand'] ?? [];
					if (empty($active_terms)) {
						//error_log('WCPF Debug: render_filter_ui no active terms for product_brand');
						continue;
					}
					if ($use_value_id_in_url) {
						$term_ids = [];
						foreach ($active_terms as $value) {
							$term = get_term_by('slug', $value, 'product_brand');
							if ($term) {
								$term_ids[] = $term->term_id;
							} else {
								//error_log('WCPF Debug: render_filter_ui term not found for slug=' . $value . ', taxonomy=product_brand');
							}
						}
						$brands = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false, 'include' => $term_ids]);
					} else {
						$brands = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false, 'slug' => $active_terms]);
					}
					if (is_wp_error($brands) || empty($brands)) {
						//error_log('WCPF Debug: render_filter_ui no brands found, active_terms=' . print_r($active_terms, true));
						continue;
					}
					$ordered_brands = [];
					foreach ($active_terms as $index => $value) {
						foreach ($brands as $brand) {
							if ($use_value_id_in_url ? $brand->term_id == ($term_ids[$index] ?? $value) : $brand->slug === $value) {
								$ordered_brands[] = $brand;
								break;
							}
						}
					}
					if (empty($ordered_brands)) {
						//error_log('WCPF Debug: render_filter_ui no ordered brands, active_terms=' . print_r($active_terms, true));
						continue;
					}
					$brand_terms_count = count($ordered_brands);
					$show_toggle = $max_terms > 0 && $brand_terms_count > $max_terms;
					?>
					<div class="wcpf-filter-group <?php echo $custom_class; ?>">
						<button class="wcpf-filter-toggle" data-taxonomy="product_brand">
							<?php echo esc_html($settings['attribute_labels']['product_brand'] ?: __('THƯƠNG HIỆU', 'wc-product-filter')); ?>
							<?php if (!empty($selected_filters['product_brand'])) : ?>
								<span class="wcpf-selected-count"><?php echo count($selected_filters['product_brand']); ?></span>
							<?php endif; ?>
						</button>
						<div class="wcpf-filter-menu filter-brand" data-taxonomy="product_brand" style="display: none;">
							<div class="wcpf-filter-grid <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
								<?php foreach ($ordered_brands as $index => $brand) : ?>
									<?php
									// Kiểm tra số lượng sản phẩm nghiêm ngặt
									$count = isset($term_product_counts['product_brand'][$brand->slug]) ? (int) $term_product_counts['product_brand'][$brand->slug] : 0;
									if ($hide_empty_terms && $count === 0) {
										//error_log('WCPF Debug: render_filter_ui skipping empty brand term: slug=' . $brand->slug . ', count=' . $count);
										continue;
									}
									$brand_image = get_term_meta($brand->term_id, 'thumbnail_id', true);
									$image_url = $brand_image ? wp_get_attachment_url($brand_image) : '';
									$is_selected = in_array($use_value_id_in_url ? $brand->term_id : $brand->slug, wp_list_pluck($selected_filters['product_brand'] ?? [], $use_value_id_in_url ? 'term_id' : 'slug'));
									$is_hidden = $show_toggle && $index >= $max_terms ? 'hidden' : '';
									?>
									<span class="wcpf-filter-label <?php echo esc_attr($term_style); ?> <?php echo $is_selected ? 'selected' : ''; ?> <?php echo $is_hidden; ?>" 
										  data-taxonomy="product_brand" 
										  data-term="<?php echo esc_attr($use_value_id_in_url ? $brand->term_id : $brand->slug); ?>">
										<?php if ($term_style !== 'label') : ?>
											<input type="checkbox" <?php checked($is_selected); ?>>
											<span class="checkmark"></span>
										<?php endif; ?>
										<?php if ($image_url) : ?>
											<img class="brand-term-img" src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($brand->name); ?>" width="30" style="vertical-align: middle;">
										<?php endif; ?>
										<span class="term-title"><?php echo esc_html($brand->name); ?></span>
										<?php if ($show_term_product_count && isset($term_product_counts['product_brand'][$brand->slug])) : ?>
											<span class="wcpf-term-count"><?php echo esc_html($count); ?></span>
										<?php endif; ?>
									</span>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
					<?php elseif (taxonomy_exists($filter_key)) : ?>
						<?php
						$attribute = array_filter($filtered_attributes, function($attr) use ($filter_key) {
							return 'pa_' . $attr->attribute_name === $filter_key;
						});
						$attribute = reset($attribute);
						if (!$attribute) continue;
						$taxonomy = 'pa_' . $attribute->attribute_name;

						$active_terms = $settings['active_attribute_terms'][$taxonomy] ?? [];
						if ($use_value_id_in_url) {
							// Chuyển slug thành term_id nếu active_terms chứa slug
							$term_ids = [];
							foreach ($active_terms as $value) {
								$term = get_term_by('slug', $value, $taxonomy);
								if ($term) {
									$term_ids[] = $term->term_id;
								}
							}
							$terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'include' => $term_ids]);
						} else {
							$terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'slug' => $active_terms]);
						}
						$ordered_terms = [];
						foreach ($active_terms as $value) {
							foreach ($terms as $term) {
								if ($use_value_id_in_url ? $term->term_id == ($term_ids[array_search($value, $active_terms)] ?? $value) : $term->slug === $value) {
									$ordered_terms[] = $term;
									break;
								}
							}
						}
						if (empty($ordered_terms)) continue;
						$terms_count = count($ordered_terms);
						$show_toggle = $max_terms > 0 && $terms_count > $max_terms;
						?>
						<div class="wcpf-filter-group <?php echo $custom_class; ?>">
							<button class="wcpf-filter-toggle" data-taxonomy="<?php echo esc_attr($taxonomy); ?>">
								<?php echo esc_html($settings['attribute_labels'][$taxonomy] ?: $attribute->attribute_label); ?>
								<?php if (!empty($selected_filters[$taxonomy])) : ?>
									<span class="wcpf-selected-count"><?php echo count($selected_filters[$taxonomy]); ?></span>
								<?php endif; ?>
							</button>
							<div class="wcpf-filter-menu" data-taxonomy="<?php echo esc_attr($taxonomy); ?>" style="display: none;">
								<div class="wcpf-filter-grid <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
									<?php foreach ($ordered_terms as $index => $term) : ?>
										<?php
										$count = isset($term_product_counts[$taxonomy][$term->slug]) ? (int) $term_product_counts[$taxonomy][$term->slug] : 0;
										if ($hide_empty_terms && $count === 0) {
											//error_log('WCPF Debug: render_filter_ui skipping empty term: taxonomy=' . $taxonomy . ', slug=' . $term->slug . ', count=' . $count);
											continue;
										}
										$term_image = get_term_meta($term->term_id, 'thumbnail_id', true);
										$image_url = $term_image ? wp_get_attachment_url($term_image) : '';
										$is_selected = in_array($use_value_id_in_url ? $term->term_id : $term->slug, wp_list_pluck($selected_filters[$taxonomy] ?? [], $use_value_id_in_url ? 'term_id' : 'slug'));
										$is_hidden = $show_toggle && $index >= $max_terms ? 'hidden' : '';
										?>
										<span class="wcpf-filter-label <?php echo esc_attr($term_style); ?> <?php echo $is_selected ? 'selected' : ''; ?> <?php echo $is_hidden; ?>" 
											  data-taxonomy="<?php echo esc_attr($taxonomy); ?>" 
											  data-term="<?php echo esc_attr($use_value_id_in_url ? $term->term_id : $term->slug); ?>">
											<?php if ($term_style !== 'label') : ?>
												<input type="checkbox" <?php checked($is_selected); ?>>
												<span class="checkmark"></span>
											<?php endif; ?>
											<?php if ($image_url) : ?>
												<img class="term-img" src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($term->name); ?>" width="30" style="vertical-align: middle;">
											<?php endif; ?>
											<span class="term-title"><?php echo esc_html($term->name); ?></span>
											<?php if ($show_term_product_count && isset($term_product_counts[$taxonomy][$term->slug])) : ?>
												<span class="wcpf-term-count"><?php echo esc_html($term_product_counts[$taxonomy][$term->slug]); ?></span>
											<?php endif; ?>
										</span>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					<?php elseif ($filter_key === 'search') : ?>
						<?php
						$show_toggle = false;
						$search_term = isset($_GET['s']) && !empty($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
						$custom_class = !empty($settings['custom_css_classes']['search']) ? esc_attr($settings['custom_css_classes']['search']) : '';
						$request_uri = wp_unslash($_SERVER['REQUEST_URI']);
						$home_url = home_url('/');
						$parsed_home = parse_url($home_url);
						$subdir = isset($parsed_home['path']) ? trim($parsed_home['path'], '/') : '';
						if ($subdir && strpos($request_uri, '/' . $subdir . '/') === 0) {
							$request_uri = substr($request_uri, strlen('/' . $subdir));
							if (empty($request_uri)) {
								$request_uri = '/';
							}
						}
						$current_url = esc_url(home_url(add_query_arg([], $request_uri)));
						?>
						<div class="wcpf-filter-group search <?php echo $custom_class; ?>">
							<form class="wcpf-search-form" method="get" action="<?php echo $current_url; ?>">
								<input type="text" class="wcpf-search-input" 
									   name="s" 
									   placeholder="<?php echo esc_attr(__('Nhập từ khóa tìm kiếm...', 'wc-product-filter')); ?>" 
									   value="<?php echo esc_attr($search_term); ?>" 
									   data-taxonomy="search"
									   data-term="<?php echo esc_attr($search_term); ?>">
								<input type="hidden" name="post_type" value="product">
								<input type="hidden" name="wc_query" value="product_query">
								<?php if (isset($_GET['sort_by'])) : ?>
									<input type="hidden" name="sort_by" value="<?php echo esc_attr(sanitize_text_field($_GET['sort_by'])); ?>">
								<?php endif; ?>
								<?php
								foreach ($_GET as $key => $value) {
									if ($key !== 's' && $key !== 'post_type' && $key !== 'wc_query' && $key !== 'sort_by') {
										if (is_array($value)) {
											foreach ($value as $sub_value) {
												echo '<input type="hidden" name="' . esc_attr($key) . '[]" value="' . esc_attr($sub_value) . '">';
											}
										} else {
											echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
										}
									}
								}
								?>
							</form>
						</div>
					<?php elseif ($filter_key === 'sort_by') : ?>
						<?php
						$sort_options = [
							'menu_order' => __('Mặc định', 'wc-product-filter'),
							'popularity' => __('Phổ biến', 'wc-product-filter'),
							'rating' => __('Xếp hạng', 'wc-product-filter'),
							'date' => __('Mới nhất', 'wc-product-filter'),
							'price' => __('Giá: Thấp đến cao', 'wc-product-filter'),
							'price-desc' => __('Giá: Cao đến thấp', 'wc-product-filter'),
						];
						$active_terms = isset($settings['active_attribute_terms']['sort_by']) ? (array)$settings['active_attribute_terms']['sort_by'] : [];
						$available_terms = array_filter($sort_options, function($key) use ($active_terms) {
							return in_array($key, $active_terms);
						}, ARRAY_FILTER_USE_KEY);
						if (empty($available_terms)) {
							continue;
						}
						$selected_sort = isset($_GET['sort_by']) ? sanitize_text_field($_GET['sort_by']) : 'menu_order';
						$custom_class = !empty($settings['custom_css_classes']['sort_by']) ? esc_attr($settings['custom_css_classes']['sort_by']) : '';
						?>
						<div class="wcpf-filter-group sortby <?php echo $custom_class; ?>">
							<form class="wcpf-sort-form" method="get" action="<?php echo $current_url; ?>">
								<select class="wcpf-sort-select" name="sort_by" data-taxonomy="sort_by">
									<?php foreach ($available_terms as $slug => $name) : ?>
										<option value="<?php echo esc_attr($slug); ?>" <?php selected($selected_sort, $slug); ?> data-term="<?php echo esc_attr($slug); ?>">
											<?php echo esc_html($name); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<input type="hidden" name="post_type" value="product">
								<input type="hidden" name="wc_query" value="product_query">
								<?php if (isset($_GET['s'])) : ?>
									<input type="hidden" name="s" value="<?php echo esc_attr(sanitize_text_field($_GET['s'])); ?>">
								<?php endif; ?>
								<?php
								foreach ($_GET as $key => $value) {
									if ($key !== 'sort_by' && $key !== 's' && $key !== 'post_type' && $key !== 'wc_query') {
										if (is_array($value)) {
											foreach ($value as $sub_value) {
												echo '<input type="hidden" name="' . esc_attr($key) . '[]" value="' . esc_attr($sub_value) . '">';
											}
										} else {
											echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
										}
									}
								}
								?>
							</form>
						</div>
					<?php elseif ($filter_key === 'stock_status') : ?>
						<?php
						$stock_options = [
							'stock-in' => __('Còn hàng', 'wc-product-filter'),
							'stock-out' => __('Hết hàng', 'wc-product-filter'),
							'on-sale' => __('Giảm giá', 'wc-product-filter'),
						];
						$active_terms = isset($settings['active_attribute_terms']['stock_status']) ? (array)$settings['active_attribute_terms']['stock_status'] : [];
						$ordered_terms = [];
						foreach ($active_terms as $slug) {
							if (isset($stock_options[$slug])) {
								$ordered_terms[] = (object) [
									'slug' => $slug,
									'name' => $stock_options[$slug],
									'term_id' => $slug
								];
							}
						}
						if (empty($ordered_terms)) {
							//error_log('WCPF Debug: render_filter_ui stock_status no active terms, skipping');
							continue;
						}
						$terms_count = count($ordered_terms);
						$show_toggle = $max_terms > 0 && $terms_count > $max_terms;
						$selected_statuses = wp_list_pluck($selected_filters['stock_status'] ?? [], 'slug');
						$selected_count = count($selected_statuses);
						$show_term_product_count = !empty($settings['show_term_product_count']['stock_status']);
						$custom_class = !empty($settings['custom_css_classes']['stock_status']) ? esc_attr($settings['custom_css_classes']['stock_status']) : '';
						$layout_style = isset($settings['layout_styles']['stock_status']) && !empty($settings['layout_styles']['stock_status']) ? esc_attr($settings['layout_styles']['stock_status']) : 'flow';
						$term_style = isset($settings['term_styles']['stock_status']) && !empty($settings['term_styles']['stock_status']) ? esc_attr($settings['term_styles']['stock_status']) : 'label';
						?>
						<div class="wcpf-filter-group <?php echo $custom_class; ?>">
							<button class="wcpf-filter-toggle" data-taxonomy="stock_status">
								<?php echo esc_html($settings['attribute_labels']['stock_status'] ?: __('TRẠNG THÁI', 'wc-product-filter')); ?>
								<?php if ($selected_count > 0) : ?>
									<span class="wcpf-selected-count"><?php echo esc_html($selected_count); ?></span>
								<?php endif; ?>
							</button>
							<div class="wcpf-filter-menu" data-taxonomy="stock_status" style="display: none;">
								<div class="wcpf-filter-grid <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
									<?php foreach ($ordered_terms as $index => $term) : ?>
										<?php
										if ($hide_empty_terms && isset($term_product_counts['stock_status'][$term->slug])) {
											$count = $term_product_counts['stock_status'][$term->slug] ?? 0;
											if ($count === 0) continue;
										}
										$is_selected = in_array($term->slug, $selected_statuses);
										$is_hidden = $show_toggle && $index >= $max_terms ? 'hidden' : '';
										?>
										<span class="wcpf-filter-label <?php echo esc_attr($term_style); ?> <?php echo $is_selected ? 'selected' : ''; ?> <?php echo $is_hidden; ?>" 
											  data-taxonomy="stock_status" 
											  data-term="<?php echo esc_attr($term->slug); ?>">
											<?php if ($term_style !== 'label') : ?>
												<input type="checkbox" <?php checked($is_selected); ?>>
												<span class="checkmark"></span>
											<?php endif; ?>
											<span class="term-title"><?php echo esc_html($term->name); ?></span>
											<?php if ($show_term_product_count && isset($term_product_counts['stock_status'][$term->slug])) : ?>
												<span class="wcpf-term-count"><?php echo esc_html($term_product_counts['stock_status'][$term->slug]); ?></span>
											<?php endif; ?>
										</span>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php if ($general_settings['apply_filter_behavior'] === 'apply_button') : ?>
					<button class="wcpf-apply-filters" data-mode="apply" data-locked="true" tabindex="0">
						<?php echo esc_html($settings['custom_texts']['apply_button']); ?>
					</button>
				<?php endif; ?>
			</div>
			
			<!--
			////////////////////////////////////
			MOBILE RENDER
			///////////////////////////////////
			-->
			
			<div class="wcpf-mobile-filter-menu" style="display: none;">
				<div class="wcpf-filter-title">
					<span class="wcpf-filter-title-text"><?php echo esc_html($settings['custom_texts']['mobile_menu_title']); ?></span>
					<button class="wcpf-close-menu"><?php _e('× Đóng', 'wc-product-filter'); ?></button>
				</div>
				<?php foreach ($active_attributes as $filter_key) : ?>
					<?php
					$display_setting = isset($settings['display_settings'][$filter_key]) ? $settings['display_settings'][$filter_key] : 'both';
					$is_mobile = wp_is_mobile();
					if (($display_setting === 'desktop' && $is_mobile) || ($display_setting === 'mobile' && !$is_mobile)) {
						continue;
					}

					$custom_class = !empty($settings['custom_css_classes'][$filter_key]) ? esc_attr($settings['custom_css_classes'][$filter_key]) : '';
					$show_term_product_count = !empty($settings['show_term_product_count'][$filter_key]);
					$max_terms = isset($settings['max_terms'][$filter_key]) && is_numeric($settings['max_terms'][$filter_key]) ? (int)$settings['max_terms'][$filter_key] : $default_max_terms;

					$layout_style = isset($settings['layout_styles'][$filter_key]) && !empty($settings['layout_styles'][$filter_key]) ? esc_attr($settings['layout_styles'][$filter_key]) : 'flow';
					$term_style = isset($settings['term_styles'][$filter_key]) && !empty($settings['term_styles'][$filter_key]) ? esc_attr($settings['term_styles'][$filter_key]) : 'label';
					//error_log("WCPF Debug: render_filter_ui mobile filter_key=$filter_key, layout_style=$layout_style, term_style=$term_style, custom_class=$custom_class");
					?>
					
						<?php if ($filter_key === 'product_cat') : ?>
						<?php
						//error_log('WCPF Debug: render_filter_ui product_cat - matched_filter=' . print_r($matched_filter, true));
						$active_terms = $settings['active_attribute_terms']['product_cat'] ?? [];
						//error_log('WCPF Debug: render_filter_ui product_cat - active_terms=' . print_r($active_terms, true));

						if (empty($active_terms)) {
							//error_log('WCPF Debug: render_filter_ui product_cat - No active terms, skipping');
							continue;
						}

						// Xác định chế độ hiển thị danh mục từ matched_filter
						$category_display_mode = 'parent_child'; // Giá trị mặc định
						if (isset($matched_filter['category_display_mode']['product_cat'])) {
							$category_display_mode = $matched_filter['category_display_mode']['product_cat'];
						}
						
							// Xác định chế độ category_mode từ matched_filter
						$category_mode = 'category_filter'; // Giá trị mặc định
						if (isset($matched_filter['category_mode']) && is_array($matched_filter['category_mode']) && isset($matched_filter['category_mode']['product_cat'])) {
							$category_mode = $matched_filter['category_mode']['product_cat'];
						} elseif (isset($settings['category_mode']['product_cat'])) {
							$category_mode = $settings['category_mode']['product_cat'];
						}
						//error_log('WCPF Debug: render_filter_ui product_cat - raw matched_filter[category_display_mode]=' . print_r($matched_filter['category_display_mode'] ?? 'null', true));
						//error_log('WCPF Debug: render_filter_ui product_cat - category_display_mode=' . $category_display_mode);

						$term_args = [
							'taxonomy' => 'product_cat',
							'hide_empty' => $hide_empty_terms,
							'hierarchical' => true,
						];

						// Xử lý danh mục Uncategorized
						$uncategorized_id = get_option('default_product_cat', 0);
						if ($uncategorized_id) {
							$uncategorized_term = get_term_by('id', $uncategorized_id, 'product_cat');
							if ($uncategorized_term) {
								$uncategorized_slug = $uncategorized_term->slug;
								$uncategorized_count = isset($term_product_counts['product_cat'][$uncategorized_slug]) ? (int) $term_product_counts['product_cat'][$uncategorized_slug] : 0;
								//error_log('WCPF Debug: render_filter_ui product_cat - uncategorized_id=' . $uncategorized_id . ', slug=' . $uncategorized_slug . ', count=' . $uncategorized_count);
								if ($uncategorized_slug === 'uncategorized' && $uncategorized_count === 0) {
									$term_args['exclude'] = [$uncategorized_id];
									//error_log('WCPF Debug: render_filter_ui product_cat - excluding uncategorized_id=' . $uncategorized_id . ' (empty and default slug)');
								} else {
									//error_log('WCPF Debug: render_filter_ui product_cat - keeping uncategorized_id=' . $uncategorized_id . ' (has products or renamed)');
								}
							} else {
								//error_log('WCPF Debug: render_filter_ui product_cat - uncategorized_id=' . $uncategorized_id . ' not found');
							}
						}

						// Xử lý term_args dựa trên category_display_mode
						if ($category_display_mode === 'parent_child') {
							$term_ids = [];
							foreach ($active_terms as $slug) {
								$term = get_term_by('slug', $slug, 'product_cat');
								if ($term) {
									$term_ids[] = $term->term_id;
									$child_ids = get_term_children($term->term_id, 'product_cat');
									if (!is_wp_error($child_ids)) {
										$term_ids = array_merge($term_ids, $child_ids);
									}
								}
							}
							$term_ids = array_unique($term_ids);
							if (!empty($term_ids)) {
								$term_args['include'] = $term_ids;
							} else {
								$term_args['slug'] = $active_terms;
							}
							//error_log('WCPF Debug: render_filter_ui product_cat - parent_child mode, term_ids=' . print_r($term_ids, true));
						} elseif ($category_display_mode === 'leaf_only') {
							if ($is_category_page && $category_id) {
								$child_check_args = [
									'taxonomy' => 'product_cat',
									'parent' => $category_id,
									'hide_empty' => $hide_empty_terms,
									'exclude' => $term_args['exclude'] ?? [],
								];
								$child_terms = get_terms($child_check_args);
								if (!is_wp_error($child_terms) && !empty($child_terms)) {
									$term_args['parent'] = $category_id;
									unset($term_args['slug']);
									unset($term_args['include']);
									//error_log('WCPF Debug: render_filter_ui product_cat - leaf_only mode, category_id=' . $category_id . ' has children, fetching all child categories');
								} else {
									$parent_id = $this->wp_get_term_parent_id($category_id, 'product_cat') ?: 0;
									$term_args['parent'] = $parent_id;
									unset($term_args['slug']);
									unset($term_args['include']);
									//error_log('WCPF Debug: render_filter_ui product_cat - leaf_only mode, category_id=' . $category_id . ' has no children, fetching all sibling categories, parent_id=' . $parent_id);
								}
							} else {
								$term_args['parent'] = 0;
								$term_args['slug'] = $active_terms;
								//error_log('WCPF Debug: render_filter_ui product_cat - leaf_only mode, no category page, setting parent=0');
							}
						} elseif ($category_display_mode === 'contextual') {
							if ($is_category_page && $category_id) {
								$parent_id = $this->wp_get_term_parent_id($category_id, 'product_cat') ?: 0;
								$term_args['parent'] = $parent_id;
								unset($term_args['slug']);
								unset($term_args['include']);
								//error_log('WCPF Debug: render_filter_ui product_cat - contextual mode, fetching all sibling categories, parent_id=' . $parent_id);
							} else {
								$term_args['parent'] = 0;
								$term_args['slug'] = $active_terms;
								//error_log('WCPF Debug: render_filter_ui product_cat - contextual mode, no category page, setting parent=0');
							}
						}

						//error_log('WCPF Debug: render_filter_ui product_cat - term_args=' . print_r($term_args, true));

						// Lấy danh mục
						$categories = get_terms($term_args);
						if (is_wp_error($categories)) {
							//error_log('WCPF Debug: render_filter_ui product_cat - get_terms error: ' . $categories->get_error_message());
							continue;
						}
						if (empty($categories)) {
							//error_log('WCPF Debug: render_filter_ui product_cat - No categories found');
							continue;
						}

						// Log danh mục trả về
						$category_slugs = wp_list_pluck($categories, 'slug');
						//error_log('WCPF Debug: render_filter_ui product_cat - categories returned=' . print_r($category_slugs, true));

						// Hàm tính số lượng sản phẩm đệ quy
						if (!function_exists('wcpf_get_recursive_product_count')) {
							function wcpf_get_recursive_product_count($term_id, $taxonomy = 'product_cat') {
								$count = (int) get_term($term_id, $taxonomy)->count;
								$child_terms = get_terms([
									'taxonomy' => $taxonomy,
									'parent' => $term_id,
									'hide_empty' => false,
									'fields' => 'ids',
								]);
								if (!is_wp_error($child_terms)) {
									foreach ($child_terms as $child_id) {
										$count += wcpf_get_recursive_product_count($child_id, $taxonomy);
									}
								}
								return $count;
							}
						}

						// Bổ sung số lượng sản phẩm cho danh mục
						foreach ($categories as $category) {
							if (!isset($term_product_counts['product_cat'][$category->slug])) {
								$product_count = wcpf_get_recursive_product_count($category->term_id, 'product_cat');
								$term_product_counts['product_cat'][$category->slug] = $product_count;
								//error_log('WCPF Debug: render_filter_ui product_cat - Calculated recursive product count for ' . $category->slug . ': ' . $product_count);
							}
							// Log danh mục con để kiểm tra
							$child_terms = get_terms([
								'taxonomy' => 'product_cat',
								'parent' => $category->term_id,
								'hide_empty' => false,
								'fields' => 'all',
							]);
							if (!is_wp_error($child_terms) && !empty($child_terms)) {
								$child_slugs = wp_list_pluck($child_terms, 'slug');
								$child_counts = [];
								foreach ($child_terms as $child) {
									$child_counts[$child->slug] = wcpf_get_recursive_product_count($child->term_id, 'product_cat');
								}
								//error_log('WCPF Debug: render_filter_ui product_cat - Child terms of ' . $category->slug . ': ' . print_r($child_slugs, true));
								//error_log('WCPF Debug: render_filter_ui product_cat - Child term counts of ' . $category->slug . ': ' . print_r($child_counts, true));
							}
						}

						// Log số lượng sản phẩm
						//error_log('WCPF Debug: render_filter_ui product_cat - term_product_counts[product_cat]=' . print_r($term_product_counts['product_cat'] ?? [], true));

						$ordered_categories = [];
						if ($category_display_mode === 'parent_child') {
							$categories_by_id = [];
							foreach ($categories as $cat) {
								$categories_by_id[$cat->term_id] = $cat;
							}
							$ordered_categories = $this->build_category_tree($categories_by_id, 0);
							//error_log('WCPF Debug: render_filter_ui product_cat - parent_child mode, ordered_categories=' . print_r(wp_list_pluck($ordered_categories, 'slug'), true));
						} else {
							$ordered_categories = $categories;
							//error_log('WCPF Debug: render_filter_ui product_cat - leaf_only/contextual mode, ordered_categories=' . print_r(wp_list_pluck($ordered_categories, 'slug'), true));
						}

						if (empty($ordered_categories)) {
							//error_log('WCPF Debug: render_filter_ui product_cat - No ordered categories');
							continue;
						}

						$category_terms_count = count($ordered_categories);
						$show_toggle = $max_terms > 0 && $category_terms_count > $max_terms;
						?>
							<div class="wcpf-filter-section <?php echo $custom_class; ?>" data-taxonomy="product_cat">
							<div class="wcpf-filter-section-title"><?php echo esc_html($settings['attribute_labels']['product_cat'] ?: __('DANH MỤC', 'wc-product-filter')); ?></div>
								<div class="wcpf-mobile-filter-grid list category <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
									<?php foreach ($ordered_categories as $index => $category) : ?>
										<?php
										$count = isset($term_product_counts['product_cat'][$category->slug]) ? (int) $term_product_counts['product_cat'][$category->slug] : 0;
										//error_log('WCPF Debug: render_filter_ui product_cat - Category ' . $category->slug . ' count: ' . $count);
										if ($hide_empty_terms && $count === 0) {
											continue;
										}
										$is_selected = in_array($use_value_id_in_url ? $category->term_id : $category->slug, wp_list_pluck($selected_filters['product_cat'] ?? [], $use_value_id_in_url ? 'term_id' : 'slug'));
										//error_log('WCPF Debug: render_filter_ui product_cat - Category ' . $category->slug . ', is_selected=' . ($is_selected ? 'true' : 'false'));
										$is_hidden = $show_toggle && $index >= $max_terms ? 'hidden' : '';
										$depth = $category_display_mode === 'parent_child' ? ($category->depth ?? 0) : 0;
										?>
										<?php if ($category_mode === 'category_filter') : ?>
										<span class="wcpf-filter-label <?php echo esc_attr($term_style); ?> <?php echo $is_selected ? 'selected' : ''; ?> <?php echo $is_hidden; ?>" 
											  data-taxonomy="product_cat" 
											  data-term="<?php echo esc_attr($use_value_id_in_url ? $category->term_id : $category->slug); ?>" 
											  style="padding-left: <?php echo $depth * 20; ?>px;">
											<?php if ($term_style !== 'label') : ?>
												<input type="checkbox" <?php checked($is_selected); ?>>
												<span class="checkmark"></span>
											<?php endif; ?>
											<span class="term-title"><?php echo esc_html($category->name); ?></span>
											<?php if ($show_term_product_count && $count > 0) : ?>
												<span class="wcpf-term-count"><?php echo esc_html($count); ?></span>
											<?php endif; ?>
										</span>
										<?php else : // category_text_link ?>
										<?php
											$term_link = get_term_link($category, 'product_cat');
											if (is_wp_error($term_link)) {
												$term_link = '#';
												//error_log('WCPF Debug: render_filter_ui product_cat - Failed to get term link for ' . $category->slug);
											}
											?>
											<a class="wcpf-filter-label wcpf-text-link <?php echo esc_attr($term_style); ?> <?php echo $is_hidden; ?>" 
											   href="<?php echo esc_url($term_link); ?>" 
											   data-taxonomy="product_cat" 
											   data-term="<?php echo esc_attr($use_value_id_in_url ? $category->term_id : $category->slug); ?>" 
											   style="padding-left: <?php echo $depth * 20; ?>px;">
												<span class="term-title"><?php echo esc_html($category->name); ?></span>
												<?php if ($show_term_product_count && $count > 0) : ?>
													<span class="wcpf-term-count"><?php echo esc_html($count); ?></span>
												<?php endif; ?>
											</a>
										<?php endif; ?>
									<?php endforeach; ?>
								</div>
							</div>
					<?php endif; ?>

					<?php if ($filter_key === 'price') : ?>
						<?php
						$price_terms_count = count($price_ranges);
						$show_toggle = $max_terms > 0 && $price_terms_count > $max_terms;
						?>
						<div class="wcpf-filter-section <?php echo $custom_class; ?>" data-taxonomy="price">
							<div class="wcpf-filter-section-title"><?php echo esc_html($settings['attribute_labels']['price'] ?: __('KHOẢNG GIÁ', 'wc-product-filter')); ?></div>
							<div class="wcpf-mobile-filter-grid <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
								<?php foreach ($price_ranges as $index => $range) : ?>
									<?php
									if ($hide_empty_terms && isset($term_product_counts['price'][$range['min'] . '-' . ($range['max'] ?? 'max')])) {
										$count = $term_product_counts['price'][$range['min'] . '-' . ($range['max'] ?? 'max')] ?? 0;
										if ($count === 0) continue;
									}
									$is_hidden = $show_toggle && $index >= $max_terms ? 'hidden' : '';
									?>
									<span class="wcpf-filter-label wcpf-price-range <?php echo esc_attr($term_style); ?> <?php echo ($min_price == $range['min'] && $max_price == $range['max']) ? 'selected' : ''; ?> <?php echo $is_hidden; ?>" 
										  data-min-price="<?php echo esc_attr($range['min']); ?>" 
										  data-max-price="<?php echo esc_attr($range['max'] ?? ''); ?>">
										<?php if ($term_style !== 'label') : ?>
											<input type="checkbox" <?php checked($min_price == $range['min'] && $max_price == $range['max']); ?>>
											<span class="checkmark"></span>
										<?php endif; ?>
										<?php echo esc_html($range['label']); ?>
										<?php if ($show_term_product_count && isset($term_product_counts['price'][$range['min'] . '-' . ($range['max'] ?? 'max')])) : ?>
											<span class="wcpf-term-count"><?php echo esc_html($term_product_counts['price'][$range['min'] . '-' . ($range['max'] ?? 'max')]); ?></span>
										<?php endif; ?>
									</span>
								<?php endforeach; ?>
							</div>
						</div>
					<?php elseif ($filter_key === 'product_brand' && taxonomy_exists('product_brand') && !$is_brand_page) : ?>
						<?php
						$active_terms = $settings['active_attribute_terms']['product_brand'] ?? [];
						if (empty($active_terms)) {
							//error_log('WCPF Debug: render_filter_ui no active terms for product_brand');
							continue;
						}
						if ($use_value_id_in_url) {
							$term_ids = [];
							foreach ($active_terms as $value) {
								$term = get_term_by('slug', $value, 'product_brand');
								if ($term) {
									$term_ids[] = $term->term_id;
								} else {
									//error_log('WCPF Debug: render_filter_ui term not found for slug=' . $value . ', taxonomy=product_brand');
								}
							}
							$brands = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false, 'include' => $term_ids]);
						} else {
							$brands = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false, 'slug' => $active_terms]);
						}
						if (is_wp_error($brands) || empty($brands)) {
							//error_log('WCPF Debug: render_filter_ui no brands found, active_terms=' . print_r($active_terms, true));
							continue;
						}
						$ordered_brands = [];
						foreach ($active_terms as $index => $value) {
							foreach ($brands as $brand) {
								if ($use_value_id_in_url ? $brand->term_id == ($term_ids[$index] ?? $value) : $brand->slug === $value) {
									$ordered_brands[] = $brand;
									break;
								}
							}
						}
						if (empty($ordered_brands)) {
							//error_log('WCPF Debug: render_filter_ui no ordered brands, active_terms=' . print_r($active_terms, true));
							continue;
						}
						$brand_terms_count = count($ordered_brands);
						$show_toggle = $max_terms > 0 && $brand_terms_count > $max_terms;
						?>
						<div class="wcpf-filter-section filter-brand <?php echo $custom_class; ?>" data-taxonomy="product_brand">
							<div class="wcpf-filter-section-title"><?php echo esc_html($settings['attribute_labels']['product_brand'] ?: __('THƯƠNG HIỆU', 'wc-product-filter')); ?></div>
							<div class="wcpf-mobile-filter-grid filter-brand <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
								<?php foreach ($ordered_brands as $index => $brand) : ?>
									<?php
									$count = isset($term_product_counts['product_brand'][$brand->slug]) ? (int) $term_product_counts['product_brand'][$brand->slug] : 0;
									if ($hide_empty_terms && $count === 0) {
										//error_log('WCPF Debug: render_filter_ui skipping empty brand term: slug=' . $brand->slug . ', count=' . $count);
										continue;
									}
									$brand_image = get_term_meta($brand->term_id, 'thumbnail_id', true);
									$image_url = $brand_image ? wp_get_attachment_url($brand_image) : '';
									$is_selected = in_array($use_value_id_in_url ? $brand->term_id : $brand->slug, wp_list_pluck($selected_filters['product_brand'] ?? [], $use_value_id_in_url ? 'term_id' : 'slug'));
									$is_hidden = $show_toggle && $index >= $max_terms ? 'hidden' : '';
									?>
									<span class="wcpf-filter-label <?php echo esc_attr($term_style); ?> <?php echo $is_selected ? 'selected' : ''; ?> <?php echo $is_hidden; ?>" 
										  data-taxonomy="product_brand" 
										  data-term="<?php echo esc_attr($use_value_id_in_url ? $brand->term_id : $brand->slug); ?>">
										<?php if ($term_style !== 'label') : ?>
											<input type="checkbox" <?php checked($is_selected); ?>>
											<span class="checkmark"></span>
										<?php endif; ?>
										<?php if ($image_url) : ?>
											<img class="brand-term-img" src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($brand->name); ?>" width="30">
										<?php endif; ?>
										<span class="term-title"><?php echo esc_html($brand->name); ?></span>
										<?php if ($show_term_product_count && isset($term_product_counts['product_brand'][$brand->slug])) : ?>
											<span class="wcpf-term-count"><?php echo esc_html($term_product_counts['product_brand'][$brand->slug]); ?></span>
										<?php endif; ?>
									</span>
								<?php endforeach; ?>
							</div>
						</div>
					<?php elseif (taxonomy_exists($filter_key)) : ?>
						<?php
						$attribute = array_filter($filtered_attributes, function($attr) use ($filter_key) {
							return 'pa_' . $attr->attribute_name === $filter_key;
						});
						$attribute = reset($attribute);
						if (!$attribute) continue;
						$taxonomy = 'pa_' . $attribute->attribute_name;
						
						$active_terms = $settings['active_attribute_terms'][$taxonomy] ?? [];
						if ($use_value_id_in_url) {
							// Chuyển slug thành term_id nếu active_terms chứa slug
							$term_ids = [];
							foreach ($active_terms as $value) {
								$term = get_term_by('slug', $value, $taxonomy);
								if ($term) {
									$term_ids[] = $term->term_id;
								}
							}
							$terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'include' => $term_ids]);
						} else {
							$terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'slug' => $active_terms]);
						}
						$ordered_terms = [];
						foreach ($active_terms as $value) {
							foreach ($terms as $term) {
								if ($use_value_id_in_url ? $term->term_id == ($term_ids[array_search($value, $active_terms)] ?? $value) : $term->slug === $value) {
									$ordered_terms[] = $term;
									break;
								}
							}
						}
						if (empty($ordered_terms)) continue;
						$terms_count = count($ordered_terms);
						$show_toggle = $max_terms > 0 && $terms_count > $max_terms;
						?>
						<div class="wcpf-filter-section <?php echo $custom_class; ?>" data-taxonomy="<?php echo esc_attr($taxonomy); ?>">
							<div class="wcpf-filter-section-title"><?php echo esc_html($settings['attribute_labels'][$taxonomy] ?: $attribute->attribute_label); ?></div>
							<div class="wcpf-mobile-filter-grid <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
								<?php foreach ($ordered_terms as $index => $term) : ?>
									<?php
									$count = isset($term_product_counts[$taxonomy][$term->slug]) ? (int) $term_product_counts[$taxonomy][$term->slug] : 0;
									if ($hide_empty_terms && $count === 0) {
										//error_log('WCPF Debug: render_filter_ui skipping empty term: taxonomy=' . $taxonomy . ', slug=' . $term->slug . ', count=' . $count);
										continue;
									}
									$term_image = get_term_meta($term->term_id, 'thumbnail_id', true);
									$image_url = $term_image ? wp_get_attachment_url($term_image) : '';
									$is_selected = in_array($use_value_id_in_url ? $term->term_id : $term->slug, wp_list_pluck($selected_filters[$taxonomy] ?? [], $use_value_id_in_url ? 'term_id' : 'slug'));
									$is_hidden = $show_toggle && $index >= $max_terms ? 'hidden' : '';
									?>
									<span class="wcpf-filter-label <?php echo esc_attr($term_style); ?> <?php echo $is_selected ? 'selected' : ''; ?> <?php echo $is_hidden; ?>" 
										  data-taxonomy="<?php echo esc_attr($taxonomy); ?>" 
										  data-term="<?php echo esc_attr($use_value_id_in_url ? $term->term_id : $term->slug); ?>">
										<?php if ($term_style !== 'label') : ?>
											<input type="checkbox" <?php checked($is_selected); ?>>
											<span class="checkmark"></span>
										<?php endif; ?>
										<?php if ($image_url) : ?>
											<img class="term-img" src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($term->name); ?>" width="30">
										<?php endif; ?>
										<span class="term-title"><?php echo esc_html($term->name); ?></span>
										<?php if ($show_term_product_count && isset($term_product_counts[$taxonomy][$term->slug])) : ?>
											<span class="wcpf-term-count"><?php echo esc_html($term_product_counts[$taxonomy][$term->slug]); ?></span>
										<?php endif; ?>
									</span>
								<?php endforeach; ?>
							</div>
						</div>
					<?php elseif ($filter_key === 'search') : ?>
						<?php
						$show_toggle = false;
						$search_term = isset($_GET['s']) && !empty($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
						$custom_class = !empty($settings['custom_css_classes']['search']) ? esc_attr($settings['custom_css_classes']['search']) : '';
						$home_url = home_url('/');
						$parsed_home = parse_url($home_url);
						$subdir = isset($parsed_home['path']) ? trim($parsed_home['path'], '/') : '';
						$request_uri = wp_unslash($_SERVER['REQUEST_URI']);
						if ($subdir && strpos($request_uri, '/' . $subdir . '/') === 0) {
							$request_uri = substr($request_uri, strlen('/' . $subdir));
							if (empty($request_uri)) {
								$request_uri = '/';
							}
						}
						$current_url = esc_url(home_url(add_query_arg([], $request_uri)));
						?>
						<div class="wcpf-filter-group search <?php echo $custom_class; ?>">
							<form class="wcpf-search-form" method="get" action="<?php echo $current_url; ?>">
								<input type="text" class="wcpf-search-input" 
									   name="s" 
									   placeholder="<?php echo esc_attr(__('Nhập từ khóa tìm kiếm...', 'wc-product-filter')); ?>" 
									   value="<?php echo esc_attr($search_term); ?>" 
									   data-taxonomy="search">
								<input type="hidden" name="post_type" value="product">
								<input type="hidden" name="wc_query" value="product_query">
								<?php if (isset($_GET['sort_by'])) : ?>
									<input type="hidden" name="sort_by" value="<?php echo esc_attr(sanitize_text_field($_GET['sort_by'])); ?>">
								<?php endif; ?>
								<?php
								foreach ($_GET as $key => $value) {
									if ($key !== 's' && $key !== 'post_type' && $key !== 'wc_query' && $key !== 'sort_by') {
										if (is_array($value)) {
											foreach ($value as $sub_value) {
												echo '<input type="hidden" name="' . esc_attr($key) . '[]" value="' . esc_attr($sub_value) . '">';
											}
										} else {
											echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
										}
									}
								}
								?>
							</form>
						</div>
					<?php elseif ($filter_key === 'sort_by') : ?>
						<?php
						$sort_options = [
							'menu_order' => __('Mặc định', 'wc-product-filter'),
							'popularity' => __('Phổ biến', 'wc-product-filter'),
							'rating' => __('Xếp hạng', 'wc-product-filter'),
							'date' => __('Mới nhất', 'wc-product-filter'),
							'price' => __('Giá: Thấp đến cao', 'wc-product-filter'),
							'price-desc' => __('Giá: Cao đến thấp', 'wc-product-filter'),
						];
						$active_terms = isset($settings['active_attribute_terms']['sort_by']) ? (array)$settings['active_attribute_terms']['sort_by'] : [];
						$available_terms = array_filter($sort_options, function($key) use ($active_terms) {
							return in_array($key, $active_terms);
						}, ARRAY_FILTER_USE_KEY);
						if (empty($available_terms)) {
							continue;
						}
						$selected_sort = isset($_GET['sort_by']) ? sanitize_text_field($_GET['sort_by']) : 'menu_order';
						$custom_class = !empty($settings['custom_css_classes']['sort_by']) ? esc_attr($settings['custom_css_classes']['sort_by']) : '';
						$max_terms = isset($settings['max_terms']['sort_by']) && is_numeric($settings['max_terms']['sort_by']) ? (int)$settings['max_terms']['sort_by'] : $default_max_terms;
						?>
						<div class="wcpf-filter-group sortby <?php echo $custom_class; ?>">
							<form class="wcpf-sort-form" method="get" action="<?php echo $current_url; ?>">
								<select class="wcpf-sort-select" name="sort_by" data-taxonomy="sort_by" onchange="this.form.submit()">
									<?php foreach ($available_terms as $slug => $name) : ?>
										<option value="<?php echo esc_attr($slug); ?>" <?php selected($selected_sort, $slug); ?>>
											<?php echo esc_html($name); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<input type="hidden" name="post_type" value="product">
								<input type="hidden" name="wc_query" value="product_query">
								<?php if (isset($_GET['s'])) : ?>
									<input type="hidden" name="s" value="<?php echo esc_attr(sanitize_text_field($_GET['s'])); ?>">
								<?php endif; ?>
								<?php
								foreach ($_GET as $key => $value) {
									if ($key !== 'sort_by' && $key !== 's' && $key !== 'post_type' && $key !== 'wc_query') {
										if (is_array($value)) {
											foreach ($value as $sub_value) {
												echo '<input type="hidden" name="' . esc_attr($key) . '[]" value="' . esc_attr($sub_value) . '">';
											}
										} else {
											echo '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
										}
									}
								}
								?>
							</form>
						</div>
					<?php elseif ($filter_key === 'stock_status') : ?>
						<?php
						$stock_options = [
							'stock-in' => __('Còn hàng', 'wc-product-filter'),
							'stock-out' => __('Hết hàng', 'wc-product-filter'),
							'on-sale' => __('Giảm giá', 'wc-product-filter'),
						];
						$active_terms = isset($settings['active_attribute_terms']['stock_status']) ? (array)$settings['active_attribute_terms']['stock_status'] : [];
						$ordered_terms = [];
						foreach ($active_terms as $slug) {
							if (isset($stock_options[$slug])) {
								$ordered_terms[] = (object) [
									'slug' => $slug,
									'name' => $stock_options[$slug],
									'term_id' => $slug
								];
							}
						}
						if (empty($ordered_terms)) {
							//error_log('WCPF Debug: render_filter_ui stock_status no active terms, skipping');
							continue;
						}
						$terms_count = count($ordered_terms);
						$show_toggle = $max_terms > 0 && $terms_count > $max_terms;
						$selected_statuses = wp_list_pluck($selected_filters['stock_status'] ?? [], 'slug');
						$selected_count = count($selected_statuses);
						$show_term_product_count = !empty($settings['show_term_product_count']['stock_status']);
						$custom_class = !empty($settings['custom_css_classes']['stock_status']) ? esc_attr($settings['custom_css_classes']['stock_status']) : '';
						$layout_style = isset($settings['layout_styles']['stock_status']) && !empty($settings['layout_styles']['stock_status']) ? esc_attr($settings['layout_styles']['stock_status']) : 'flow';
						$term_style = isset($settings['term_styles']['stock_status']) && !empty($settings['term_styles']['stock_status']) ? esc_attr($settings['term_styles']['stock_status']) : 'label';
						?>
						<div class="wcpf-filter-section <?php echo $custom_class; ?>" data-taxonomy="stock_status">
							<div class="wcpf-filter-section-title"><?php echo esc_html($settings['attribute_labels']['stock_status'] ?: __('TRẠNG THÁI', 'wc-product-filter')); ?></div>
							<div class="wcpf-mobile-filter-grid <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
								<?php foreach ($ordered_terms as $index => $term) : ?>
									<?php
									if ($hide_empty_terms && isset($term_product_counts['stock_status'][$term->slug])) {
										$count = $term_product_counts['stock_status'][$term->slug] ?? 0;
										if ($count === 0) continue;
									}
									$term_image = get_term_meta($term->term_id, 'thumbnail_id', true);
									$image_url = $term_image ? wp_get_attachment_url($term_image) : '';
									$is_selected = in_array($term->slug, $selected_statuses);
									$is_hidden = $show_toggle && $index >= $max_terms ? 'hidden' : '';
									?>
									<span class="wcpf-filter-label <?php echo esc_attr($term_style); ?> <?php echo $is_selected ? 'selected' : ''; ?> <?php echo $is_hidden; ?>" 
										  data-taxonomy="stock_status" 
										  data-term="<?php echo esc_attr($term->slug); ?>">
										<?php if ($term_style !== 'label') : ?>
											<input type="checkbox" <?php checked($is_selected); ?>>
											<span class="checkmark"></span>
										<?php endif; ?>
										<?php if ($image_url) : ?>
											<img class="term-img" src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($term->name); ?>" width="30">
										<?php endif; ?>
										<span class="term-title"><?php echo esc_html($term->name); ?></span>
										<?php if ($show_term_product_count && isset($term_product_counts['stock_status'][$term->slug])) : ?>
											<span class="wcpf-term-count"><?php echo esc_html($term_product_counts['stock_status'][$term->slug]); ?></span>
										<?php endif; ?>
									</span>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
				<div class="wcpf-action-buttons">
					<?php if ($general_settings['apply_filter_behavior'] === 'apply_button') : ?>
						<button class="wcpf-reset-filters" role="button" tabindex="0"><?php echo esc_html($settings['custom_texts']['reset_button']); ?></button>
						<button class="wcpf-apply-filters" data-mode="apply" data-locked="true" tabindex="0">
							<?php echo esc_html($settings['custom_texts']['apply_button']); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<div class="wcpf-loading-overlay" style="display: none;">
				<div class="wcpf-spinner"></div>
			</div>
		</div>
		<?php
	}

    /**
     * Get selected filters from query parameters.
     *
     * @return array Selected filters.
     */
	public function get_selected_filters() {
		$filters = [];
		$query_filters = get_query_var('filters', '');
		$general_settings = get_option('wcpf_general_settings', ['use_value_id_in_url' => 0]);
		$use_value_id_in_url = !empty($general_settings['use_value_id_in_url']);
		$seen_terms = [];
		//error_log('WCPF Debug: get_selected_filters query_filters_raw=' . print_r($query_filters, true) . ', use_value_id_in_url=' . ($use_value_id_in_url ? 'true' : 'false'));

		if ($query_filters) {
			$filter_parts = array_filter(explode('/', trim($query_filters, '/')));
			foreach ($filter_parts as $part) {
				if (empty($part)) continue;
				//error_log('WCPF Debug: get_selected_filters processing part=' . $part);

				// Xử lý product_cat
				if (strpos($part, 'product_cat-') === 0) {
					$term_string = str_replace('product_cat-', '', $part);
					$taxonomy = 'product_cat';
					if (!taxonomy_exists($taxonomy)) {
						//error_log('WCPF Debug: get_selected_filters taxonomy not exists: ' . $taxonomy);
						continue;
					}

					$term_values = [];
					if ($use_value_id_in_url) {
						$term_values = array_filter(explode('-', $term_string), 'is_numeric');
						//error_log('WCPF Debug: get_selected_filters product_cat term_values (ID mode)=' . print_r($term_values, true));
					} else {
						// Lấy danh sách slug hợp lệ và tối ưu hóa
						$terms = get_terms([
							'taxonomy' => $taxonomy,
							'hide_empty' => false,
							'fields' => 'slugs',
							'cache_domain' => 'wcpf_terms_' . $taxonomy
						]);
						if (is_wp_error($terms)) {
							//error_log('WCPF Debug: get_selected_filters get_terms error: ' . $terms->get_error_message());
							continue;
						}
						// Sắp xếp slug theo độ dài giảm dần và loại bỏ slug không chuẩn
						$terms = array_filter($terms, function($slug) {
							return preg_match('/^[a-z0-9\-]+$/', $slug);
						});
						usort($terms, function($a, $b) {
							return strlen($b) - strlen($a);
						});
						//error_log('WCPF Debug: get_selected_filters product_cat available slugs=' . print_r($terms, true));

						$temp = $term_string;
						$processed_length = 0;
						$max_iterations = 100; // Ngăn vòng lặp vô hạn
						$iteration = 0;

						while ($temp && $iteration < $max_iterations) {
							$matched = false;
							foreach ($terms as $slug) {
								if (strpos($temp, $slug) === 0 && (strlen($temp) === strlen($slug) || $temp[strlen($slug)] === '-')) {
									$term_values[] = $slug;
									$temp = ltrim(substr($temp, strlen($slug)), '-');
									$processed_length += strlen($slug) + (strlen($temp) < strlen($term_string) ? 1 : 0);
									$matched = true;
									//error_log('WCPF Debug: get_selected_filters matched product_cat slug: ' . $slug . ', remaining: ' . $temp);
									break;
								}
							}
							if (!$matched) {
								// Tìm slug hợp lệ tiếp theo gần nhất
								$min_pos = strlen($temp);
								$next_slug = '';
								foreach ($terms as $slug) {
									$pos = strpos($temp, $slug);
									if ($pos !== false && $pos < $min_pos) {
										$min_pos = $pos;
										$next_slug = $slug;
									}
								}
								if ($min_pos < strlen($temp)) {
									$temp = substr($temp, $min_pos);
									$processed_length += $min_pos;
									//error_log('WCPF Debug: get_selected_filters advancing to next potential slug: ' . $temp);
								} else {
									// Nếu không tìm thấy slug nào, bỏ qua ký tự đầu tiên
									$temp = ltrim(substr($temp, 1), '-');
									$processed_length++;
									//error_log('WCPF Debug: get_selected_filters skipping unmatched product_cat segment: ' . $temp);
								}
							}
							$iteration++;
							// Thoát nếu không tiến triển
							if ($processed_length >= strlen($term_string)) {
								break;
							}
						}
						if ($iteration >= $max_iterations) {
							//error_log('WCPF Debug: get_selected_filters reached max iterations for product_cat, possible infinite loop');
						}
						//error_log('WCPF Debug: get_selected_filters product_cat term_values (slug mode)=' . print_r($term_values, true));
					}

					foreach ($term_values as $term_value) {
						if (empty($term_value)) continue;
						$term_value_clean = $use_value_id_in_url ? (int) $term_value : sanitize_text_field($term_value);
						$term = $use_value_id_in_url ? get_term_by('id', $term_value_clean, $taxonomy) : get_term_by('slug', $term_value_clean, $taxonomy);
						if ($term && (!isset($seen_terms[$taxonomy]) || !in_array($use_value_id_in_url ? $term->term_id : $term->slug, $seen_terms[$taxonomy]))) {
							$filters[$taxonomy][] = $term;
							$seen_terms[$taxonomy][] = $use_value_id_in_url ? $term->term_id : $term->slug;
							//error_log('WCPF Debug: get_selected_filters added product_cat term: slug=' . $term->slug . ', id=' . $term->term_id);
						} else {
							//error_log('WCPF Debug: get_selected_filters product_cat term not found or duplicate: value=' . $term_value_clean);
						}
					}
				}
				// Xử lý stock_status
				elseif (strpos($part, 'stock_status-') === 0) {
					$term_slug = str_replace('stock_status-', '', $part);
					$taxonomy = 'stock_status';
					$stock_options = [
						'stock-in' => __('Còn hàng', 'wc-product-filter'),
						'stock-out' => __('Hết hàng', 'wc-product-filter'),
						'on-sale' => __('Giảm giá', 'wc-product-filter'),
					];
					
					$stock_terms = [];
					$temp = $term_slug;
					foreach (array_keys($stock_options) as $option) {
						if (strpos($temp, $option) === 0) {
							$stock_terms[] = $option;
							$temp = ltrim(str_replace($option, '', $temp), '-');
						}
					}
					
					foreach ($stock_terms as $term_slug) {
						if (array_key_exists($term_slug, $stock_options)) {
							$filters['stock_status'][] = (object) [
								'slug' => $term_slug,
								'name' => $stock_options[$term_slug],
								'taxonomy' => 'stock_status'
							];
							//error_log('WCPF Debug: get_selected_filters added stock_status term: slug=' . $term_slug);
						}
					}
				}
				// Xử lý product_brand
				elseif (strpos($part, 'product_brand-') === 0) {
					$term_string = str_replace('product_brand-', '', $part);
					$taxonomy = 'product_brand';
					if (!taxonomy_exists($taxonomy)) {
						//error_log('WCPF Debug: get_selected_filters taxonomy not exists: ' . $taxonomy);
						continue;
					}

					$term_values = [];
					if ($use_value_id_in_url) {
						$term_values = array_filter(explode('-', $term_string), 'is_numeric');
					} else {
						$terms = get_terms([
							'taxonomy' => $taxonomy,
							'hide_empty' => false,
							'fields' => 'slugs',
							'cache_domain' => 'wcpf_terms_' . $taxonomy
						]);
						if (is_wp_error($terms)) {
							//error_log('WCPF Debug: get_selected_filters get_terms error: ' . $terms->get_error_message());
							continue;
						}
						$terms = array_filter($terms, function($slug) {
							return preg_match('/^[a-z0-9\-]+$/', $slug);
						});
						usort($terms, function($a, $b) {
							return strlen($b) - strlen($a);
						});
						$temp = $term_string;
						$processed_length = 0;
						$max_iterations = 100;
						$iteration = 0;

						while ($temp && $iteration < $max_iterations) {
							$matched = false;
							foreach ($terms as $slug) {
								if (strpos($temp, $slug) === 0 && (strlen($temp) === strlen($slug) || $temp[strlen($slug)] === '-')) {
									$term_values[] = $slug;
									$temp = ltrim(substr($temp, strlen($slug)), '-');
									$processed_length += strlen($slug) + (strlen($temp) < strlen($term_string) ? 1 : 0);
									$matched = true;
									//error_log('WCPF Debug: get_selected_filters matched product_brand slug: ' . $slug . ', remaining: ' . $temp);
									break;
								}
							}
							if (!$matched) {
								$min_pos = strlen($temp);
								$next_slug = '';
								foreach ($terms as $slug) {
									$pos = strpos($temp, $slug);
									if ($pos !== false && $pos < $min_pos) {
										$min_pos = $pos;
										$next_slug = $slug;
									}
								}
								if ($min_pos < strlen($temp)) {
									$temp = substr($temp, $min_pos);
									$processed_length += $min_pos;
									//error_log('WCPF Debug: get_selected_filters advancing to next potential product_brand slug: ' . $temp);
								} else {
									$temp = ltrim(substr($temp, 1), '-');
									$processed_length++;
									//error_log('WCPF Debug: get_selected_filters skipping unmatched product_brand segment: ' . $temp);
								}
							}
							$iteration++;
							if ($processed_length >= strlen($term_string)) {
								break;
							}
						}
						if ($iteration >= $max_iterations) {
							//error_log('WCPF Debug: get_selected_filters reached max iterations for product_brand, possible infinite loop');
						}
					}

					foreach ($term_values as $term_value) {
						if (empty($term_value)) continue;
						$term = $use_value_id_in_url ? get_term_by('id', (int) $term_value, $taxonomy) : get_term_by('slug', sanitize_text_field($term_value), $taxonomy);
						if ($term && (!isset($seen_terms[$taxonomy]) || !in_array($use_value_id_in_url ? $term->term_id : $term->slug, $seen_terms[$taxonomy]))) {
							$filters[$taxonomy][] = $term;
							$seen_terms[$taxonomy][] = $use_value_id_in_url ? $term->term_id : $term->slug;
							//error_log('WCPF Debug: get_selected_filters added product_brand term: slug=' . $term->slug . ', id=' . $term->term_id);
						}
					}
				}
				// Xử lý các taxonomy pa_*
				else {
					$parts = explode('-', $part, 2);
					if (count($parts) !== 2) {
						//error_log('WCPF Debug: Invalid filter part format in get_selected_filters: ' . $part);
						continue;
					}
					list($taxonomy_key, $term_string) = $parts;
					if (in_array($taxonomy_key, ['product_cat', 'product_brand'])) {
						//error_log('WCPF Debug: Skipping invalid taxonomy_key in pa_*: ' . $taxonomy_key);
						continue;
					}
					$taxonomy = 'pa_' . $taxonomy_key;
					if (!taxonomy_exists($taxonomy)) {
						//error_log('WCPF Debug: Taxonomy not found in get_selected_filters: ' . $taxonomy);
						continue;
					}

					$term_values = [];
					if ($use_value_id_in_url) {
						$term_values = explode('-', $term_string);
					} else {
						$terms = get_terms([
							'taxonomy' => $taxonomy,
							'hide_empty' => false,
							'fields' => 'slugs',
							'cache_domain' => 'wcpf_terms_' . $taxonomy
						]);
						if (is_wp_error($terms)) {
							//error_log('WCPF Debug: get_selected_filters get_terms error: ' . $terms->get_error_message());
							continue;
						}
						$terms = array_filter($terms, function($slug) {
							return preg_match('/^[a-z0-9\-]+$/', $slug);
						});
						usort($terms, function($a, $b) {
							return strlen($b) - strlen($a);
						});
						$temp = $term_string;
						$processed_length = 0;
						$max_iterations = 100;
						$iteration = 0;

						while ($temp && $iteration < $max_iterations) {
							$matched = false;
							foreach ($terms as $slug) {
								if (strpos($temp, $slug) === 0 && (strlen($temp) === strlen($slug) || $temp[strlen($slug)] === '-')) {
									$term_values[] = $slug;
									$temp = ltrim(substr($temp, strlen($slug)), '-');
									$processed_length += strlen($slug) + (strlen($temp) < strlen($term_string) ? 1 : 0);
									$matched = true;
									//error_log('WCPF Debug: get_selected_filters matched pa_* slug: ' . $slug . ', remaining: ' . $temp);
									break;
								}
							}
							if (!$matched) {
								$min_pos = strlen($temp);
								$next_slug = '';
								foreach ($terms as $slug) {
									$pos = strpos($temp, $slug);
									if ($pos !== false && $pos < $min_pos) {
										$min_pos = $pos;
										$next_slug = $slug;
									}
								}
								if ($min_pos < strlen($temp)) {
									$temp = substr($temp, $min_pos);
									$processed_length += $min_pos;
									//error_log('WCPF Debug: get_selected_filters advancing to next potential pa_* slug: ' . $temp);
								} else {
									$temp = ltrim(substr($temp, 1), '-');
									$processed_length++;
									//error_log('WCPF Debug: get_selected_filters skipping unmatched pa_* segment: ' . $temp);
								}
							}
							$iteration++;
							if ($processed_length >= strlen($term_string)) {
								break;
							}
						}
						if ($iteration >= $max_iterations) {
							//error_log('WCPF Debug: get_selected_filters reached max iterations for pa_*, possible infinite loop');
						}
					}

					foreach ($term_values as $term_value) {
						if (empty($term_value)) continue;
						$term = $use_value_id_in_url ? get_term_by('id', (int) $term_value, $taxonomy) : get_term_by('slug', sanitize_text_field($term_value), $taxonomy);
						if ($term && (!isset($seen_terms[$taxonomy]) || !in_array($use_value_id_in_url ? $term->term_id : $term->slug, $seen_terms[$taxonomy]))) {
							$filters[$taxonomy][] = $term;
							$seen_terms[$taxonomy][] = $use_value_id_in_url ? $term->term_id : $term->slug;
							//error_log('WCPF Debug: get_selected_filters added pa_* term: taxonomy=' . $taxonomy . ', slug=' . $term->slug);
						}
					}
				}
			}
		}

		// Xử lý bộ lọc tìm kiếm
		if (isset($_GET['s']) && !empty($_GET['s'])) {
			$search_term = sanitize_text_field($_GET['s']);
			$filters['search'] = [(object) [
				'slug' => $search_term,
				'name' => $search_term,
				'taxonomy' => 'search'
			]];
			//error_log('WCPF Debug: get_selected_filters search_term=' . $search_term);
		}

		// Xử lý bộ lọc sắp xếp
		if (isset($_GET['sort_by']) && !empty($_GET['sort_by'])) {
			$sort_term = sanitize_text_field($_GET['sort_by']);
			$sort_options = [
				'menu_order' => __('Mặc định', 'wc-product-filter'),
				'popularity' => __('Phổ biến', 'wc-product-filter'),
				'rating' => __('Xếp hạng', 'wc-product-filter'),
				'date' => __('Mới nhất', 'wc-product-filter'),
				'price' => __('Giá: Thấp đến cao', 'wc-product-filter'),
				'price-desc' => __('Giá: Cao đến thấp', 'wc-product-filter'),
			];
			if (array_key_exists($sort_term, $sort_options)) {
				$filters['sort_by'] = [(object) [
					'slug' => $sort_term,
					'name' => $sort_options[$sort_term],
					'taxonomy' => 'sort_by'
				]];
				//error_log('WCPF Debug: get_selected_filters sort_by=' . $sort_term);
			}
		}

		//error_log('WCPF Debug: get_selected_filters result=' . print_r($filters, true));
		return $filters;
	}

    /**
     * Get total products based on filter parameters.
     *
     * @param array $filters Filter terms.
     * @param float|null $min_price Minimum price.
     * @param float|null $max_price Maximum price.
     * @param string $category_slug Category slug.
     * @param string $taxonomy Taxonomy.
     * @return int Total products.
     */

	public function get_total_products($filters, $min_price = null, $max_price = null, $category_slug = null, $taxonomy = 'product_cat') {

		$args = [
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'tax_query' => [],
			'meta_query' => [],
			'no_found_rows' => true,
		];

		// Thêm tax_query cho taxonomy hiện tại (product_cat hoặc product_brand) nếu $category_slug hợp lệ
		$use_value_id_in_url = !empty(get_option('wcpf_general_settings', ['use_value_id_in_url' => 0])['use_value_id_in_url']);
		if ($category_slug && in_array($taxonomy, ['product_cat', 'product_brand']) && taxonomy_exists($taxonomy)) {
			$shop_id = wc_get_page_id('shop');
			$shop_page_slug = get_page_uri($shop_id);
			if ($category_slug !== $shop_page_slug) {
				$args['tax_query'][] = [
					'taxonomy' => $taxonomy,
					'field' => $use_value_id_in_url ? 'term_id' : 'slug',
					'terms' => $category_slug,
					'operator' => 'IN',
				];
				//error_log('WCPF Debug: get_total_products added tax_query for taxonomy=' . $taxonomy . ', value=' . $category_slug . ', field=' . ($use_value_id_in_url ? 'term_id' : 'slug'));
			}
		}
		
		//CHAT GPT THÊM//
		
		//END CHATGPT THÊM//

		// Nếu đang ở trang product_brand, thêm tax_query cho product_brand (tránh trùng lặp)
		if (is_tax('product_brand') && $category_slug && $taxonomy !== 'product_brand' && taxonomy_exists('product_brand')) {
			$args['tax_query'][] = [
				'taxonomy' => 'product_brand',
				'field' => 'slug',
				'terms' => $category_slug,
				'operator' => 'IN',
			];
			//error_log('WCPF Debug: get_total_products added tax_query for product_brand, slug=' . $category_slug);
		}

		$taxonomy_groups = [];
		$stock_status_queries = [];
		$processed_stock_status = []; // Theo dõi trạng thái đã xử lý để tránh trùng lặp

			$use_value_id_in_url = !empty(get_option('wcpf_general_settings', ['use_value_id_in_url' => 0])['use_value_id_in_url']);
			foreach ($filters as $filter) {
			if (strpos($filter, ':') === false) {
				//error_log('WCPF Debug: Invalid filter format: ' . $filter);
				continue;
			}
			list($tax, $term) = explode(':', $filter, 2);
			if ($tax === 'stock_status') {
				$stock_options = ['stock-in', 'stock-out', 'on-sale'];
				if (in_array($term, $stock_options) && !in_array($term, $processed_stock_status)) {
					$processed_stock_status[] = $term;
					if ($term === 'stock-in') {
						$stock_status_queries[] = [
							'key' => '_stock_status',
							'value' => 'instock',
							'compare' => '='
						];
					} elseif ($term === 'stock-out') {
						$stock_status_queries[] = [
							'key' => '_stock_status',
							'value' => 'outofstock',
							'compare' => '='
						];
					} elseif ($term === 'on-sale') {
						$stock_status_queries[] = [
							'relation' => 'OR',
							[
								'key' => '_sale_price',
								'value' => '',
								'compare' => '!='
							],
							[
								'key' => '_sale_price',
								'value' => 0,
								'compare' => '>'
							]
						];
					}
					//error_log('WCPF Debug: get_total_products stock_status=' . $term);
				} else {
					//error_log('WCPF Debug: Invalid or duplicate stock_status term: ' . $term);
				}
			} elseif (taxonomy_exists($tax)) {
					$term_obj = get_term_by($use_value_id_in_url ? 'id' : 'slug', sanitize_text_field($term), $tax);
					if ($term_obj) {
						$taxonomy_groups[$tax][] = $use_value_id_in_url ? $term_obj->term_id : $term_obj->slug;
					} else {
						//error_log('WCPF Debug: get_total_products term not found: taxonomy=' . $tax . ', term=' . $term . ', field=' . ($use_value_id_in_url ? 'term_id' : 'slug'));
						continue; // Bỏ qua nếu term không tồn tại
					}
			} else {
					//error_log('WCPF Debug: get_total_products taxonomy not found: ' . $tax);
				}
		}

		// Thêm stock_status queries với quan hệ OR
		if (!empty($stock_status_queries)) {
			$args['meta_query'][] = [
				'relation' => 'OR',
				$stock_status_queries
			];
		}

		foreach ($taxonomy_groups as $tax => $terms) {
    $args['tax_query'][] = [
        'taxonomy' => $tax,
        'field' => $use_value_id_in_url ? 'term_id' : 'slug',
        'terms' => $terms,
        'operator' => 'IN',
    ];
}

		if ($min_price !== null || $max_price !== null) {
			$args['meta_query'][] = [
				'key' => '_price',
				'value' => [
					$min_price !== null && is_numeric($min_price) ? floatval($min_price) : 0,
					$max_price !== null && is_numeric($max_price) ? floatval($max_price) : PHP_INT_MAX,
				],
				'type' => 'NUMERIC',
				'compare' => 'BETWEEN',
			];
		}

		// Xử lý tìm kiếm
		$search_terms = array_filter($filters, function($filter) {
			return strpos($filter, 'search:') === 0;
		});
		if (!empty($search_terms)) {
			$search_term = str_replace('search:', '', reset($search_terms));
			$args['s'] = sanitize_text_field($search_term);
			//error_log('WCPF Debug: get_total_products search_term=' . $search_term);
		}

		if (!empty($args['tax_query'])) {
			$args['tax_query']['relation'] = 'AND';
		}

		// Thêm tax_query để loại bỏ sản phẩm ẩn
		$args['tax_query'][] = [
			'taxonomy' => 'product_visibility',
			'field' => 'term_taxonomy_id',
			'terms' => [7], // Giả định term ID 7 là 'hidden'
			'operator' => 'NOT IN',
		];

		//error_log('WCPF Debug: get_total_products args=' . print_r($args, true));
		$start_time = microtime(true);
		$query = new WP_Query($args);
		$count = $query->post_count;

		return $count;
	}

    /**
     * Filter products query based on URL parameters.
     *
     * @param WP_Query $query The WP_Query instance.
     */
    public function filter_products_query($query) {
        if (!is_admin() && (is_woocommerce() || $query->is_shop() || $query->is_product_taxonomy() || $query->is_search()) && isset($query->query_vars['wc_query']) && $query->query_vars['wc_query'] === 'product_query') {
            $sort_by = isset($_GET['sort_by']) ? sanitize_text_field($_GET['sort_by']) : get_option('woocommerce_default_catalog_orderby', 'menu_order');
            $cache_key = 'wcpf_sort_products_' . md5(json_encode($query->query_vars) . $sort_by);
            $cached_query = get_transient($cache_key);
            if ($cached_query !== false) {
                $query->posts = $cached_query['posts'];
                $query->post_count = count($cached_query['posts']);
                //error_log('WCPF Debug: filter_products_query using cached query: ' . $cache_key);
                return;
            }

            if ($query->is_search()) {
                $query->set('post_type', 'product');
            }

            switch ($sort_by) {
                case 'popularity':
                    $query->set('meta_key', 'total_sales');
                    $query->set('orderby', 'meta_value_num');
                    $query->set('order', 'DESC');
                    break;
                case 'rating':
                    $query->set('meta_key', '_wc_average_rating');
                    $query->set('orderby', 'meta_value_num');
                    $query->set('order', 'DESC');
                    break;
                case 'date':
                    $query->set('orderby', 'date');
                    $query->set('order', 'DESC');
                    break;
                case 'price':
                    $query->set('meta_key', '_price');
                    $query->set('orderby', 'meta_value_num');
                    $query->set('order', 'ASC');
                    break;
                case 'price-desc':
                    $query->set('meta_key', '_price');
                    $query->set('orderby', 'meta_value_num');
                    $query->set('order', 'DESC');
                    break;
                case 'menu_order':
                default:
                    $query->set('orderby', 'menu_order title');
                    $query->set('order', 'ASC');
                    break;
            }

            $tax_query = [];
            $meta_query = $query->get('meta_query') ?: [];
            $filters = $this->get_selected_filters();
            $queried_object = get_queried_object();
            $is_brand_page = is_tax('product_brand');
            $is_category_page = is_product_category();
            $general_settings = get_option('wcpf_general_settings', ['use_value_id_in_url' => 0]);
            $use_value_id_in_url = !empty($general_settings['use_value_id_in_url']);
            $category_slug = $queried_object && isset($queried_object->slug) ? ($use_value_id_in_url && isset($queried_object->term_id) ? $queried_object->term_id : $queried_object->slug) : '';
            $taxonomy = $is_brand_page ? 'product_brand' : 'product_cat';

            //error_log('WCPF Debug: filter_products_query filters=' . print_r($filters, true));

            // Chỉ thêm tax_query cho taxonomy hiện tại nếu không có bộ lọc product_cat
            if ($category_slug && $category_slug !== 'shop' && empty($filters['product_cat'])) {
                $tax_query[] = [
                    'taxonomy' => $taxonomy,
                    'field' => $use_value_id_in_url ? 'term_id' : 'slug',
                    'terms' => $category_slug,
                    'operator' => 'IN',
                ];
            }

            // Xử lý product_cat
            if (!empty($filters['product_cat'])) {
                $term_ids = [];
                foreach ($filters['product_cat'] as $term) {
                    if (is_a($term, 'WP_Term') && isset($term->term_id)) {
                        $term_ids[] = (int) $term->term_id;
                    }
                }
                if (!empty($term_ids)) {
                    $tax_query[] = [
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => $term_ids,
                        'operator' => 'IN',
                    ];
                }
            }

            // Xử lý product_brand
            if (!empty($filters['product_brand'])) {
                $term_ids = [];
                foreach ($filters['product_brand'] as $term) {
                    if (is_a($term, 'WP_Term') && isset($term->term_id)) {
                        $term_ids[] = (int) $term->term_id;
                    }
                }
                if (!empty($term_ids)) {
                    $tax_query[] = [
                        'taxonomy' => 'product_brand',
                        'field' => 'term_id',
                        'terms' => $term_ids,
                        'operator' => 'IN',
                    ];
                }
            }

            // Xử lý pa_* attributes
            foreach ($filters as $taxonomy => $terms) {
                if (strpos($taxonomy, 'pa_') === 0) {
                    $term_ids = [];
                    foreach ($terms as $term) {
                        if (is_a($term, 'WP_Term') && isset($term->term_id)) {
                            $term_ids[] = (int) $term->term_id;
                        }
                    }
                    if (!empty($term_ids)) {
                        $tax_query[] = [
                            'taxonomy' => $taxonomy,
                            'field' => 'term_id',
                            'terms' => $term_ids,
                            'operator' => 'IN',
                        ];
                    }
                }
            }

            // Xử lý stock_status
            if (!empty($filters['stock_status'])) {
                $stock_status_queries = [];
                foreach ($filters['stock_status'] as $stock_term) {
                    if ($stock_term->slug === 'stock-in') {
                        $stock_status_queries[] = [
                            'key' => '_stock_status',
                            'value' => 'instock',
                            'compare' => '=',
                        ];
                    } elseif ($stock_term->slug === 'stock-out') {
                        $stock_status_queries[] = [
                            'key' => '_stock_status',
                            'value' => 'outofstock',
                            'compare' => '=',
                        ];
                    } elseif ($stock_term->slug === 'on-sale') {
                        $stock_status_queries[] = [
                            'key' => '_sale_price',
                            'value' => 0,
                            'compare' => '>',
                            'type' => 'NUMERIC',
                        ];
                    }
                }
                if (!empty($stock_status_queries)) {
                    $meta_query[] = array_merge(['relation' => 'AND'], $stock_status_queries);
                }
            }

            // Xử lý giá
            if (isset($_GET['min_price']) || isset($_GET['max_price'])) {
                $meta_query[] = [
                    'key' => '_price',
                    'value' => [
                        isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? floatval($_GET['min_price']) : 0,
                        isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? floatval($_GET['max_price']) : PHP_INT_MAX,
                    ],
                    'type' => 'NUMERIC',
                    'compare' => 'BETWEEN',
                ];
            }

            // Xử lý tìm kiếm
            if (isset($_GET['s']) && !empty($_GET['s'])) {
                $query->set('s', sanitize_text_field($_GET['s']));
            }

            if (!empty($tax_query)) {
                $tax_query['relation'] = 'AND';
                $query->set('tax_query', $tax_query);
            }
            if (!empty($meta_query)) {
                $meta_query['relation'] = 'AND';
                $query->set('meta_query', $meta_query);
            }

            //error_log('WCPF Debug: filter_products_query final tax_query=' . print_r($tax_query, true));
            //error_log('WCPF Debug: filter_products_query final meta_query=' . print_r($meta_query, true));
        }
    }


    private function get_selected_stock_status($filters) {
        $stock_status_terms = array_filter($filters, function($filter) {
            return strpos($filter, 'stock_status:') === 0;
        });
        return array_map(function($term) {
            return str_replace('stock_status:', '', $term);
        }, $stock_status_terms);
    }
	/**
	 * AJAX handler to check if a term has products.
	 */

	/**
	 * Set HTTP headers to prevent caching.
	 */
	private function set_no_cache_headers() {
		header('Cache-Control: no-cache, no-store, must-revalidate');
		header('Pragma: no-cache');
		header('Expires: 0');
	}
/**
 * Calculate product counts for terms.
 *
 * @param array $active_attributes Active attributes.
 * @param array $filter_terms Selected filter terms.
 * @param array $settings Filter settings.
 * @param string $category_slug Current category slug or ID.
 * @param string $taxonomy Current taxonomy.
 * @param bool $hide_empty_terms Hide empty terms.
 * @param float|null $min_price Minimum price.
 * @param float|null $max_price Maximum price.
 * @return array Term product counts.
 */
	public function calculate_term_product_counts($active_attributes, $filter_terms, $settings, $category_slug, $taxonomy, $hide_empty_terms, $min_price = null, $max_price = null) {
		$term_product_counts = [];
		$queried_object = get_queried_object();
		$is_tax_page = is_tax('product_cat') || is_tax('product_brand');
		$general_settings = get_option('wcpf_general_settings', ['use_value_id_in_url' => 0]);
		$use_value_id_in_url = !empty($general_settings['use_value_id_in_url']);

		// Debug inputs
		//error_log('WCPF Debug: calculate_term_product_counts inputs: active_attributes=' . print_r($active_attributes, true));
		//error_log('WCPF Debug: filter_terms=' . print_r($filter_terms, true));
		//error_log('WCPF Debug: category_slug=' . $category_slug . ', taxonomy=' . $taxonomy . ', min_price=' . $min_price . ', max_price=' . $max_price . ', hide_empty_terms=' . ($hide_empty_terms ? 'true' : 'false'));

		// Cache key
		$cache_key = 'wcpf_term_count_' . md5(wp_json_encode([
			'attributes' => $active_attributes,
			'filters' => $filter_terms,
			'category' => $category_slug,
			'taxonomy' => $taxonomy,
			'min_price' => $min_price,
			'max_price' => $max_price,
			'queried_object' => $is_tax_page ? ($queried_object->term_id ?? 0) : 0,
			'version' => '2.0' // Added to invalidate old cache
		]));

		// Try to get from cache
		$cached_counts = get_transient($cache_key);
		if (false !== $cached_counts) {
			//error_log('WCPF Debug: calculate_term_product_counts using cached counts for key=' . $cache_key);
			return $cached_counts;
		}

		// Get product visibility term IDs
		$visibility_term_ids = [];
		$visibility_terms = get_terms([
			'taxonomy' => 'product_visibility',
			'hide_empty' => false,
			'slug' => ['exclude-from-catalog', 'exclude-from-search'],
			'fields' => 'ids',
		]);
		if (!is_wp_error($visibility_terms)) {
			$visibility_term_ids = $visibility_terms;
		}
		//error_log('WCPF Debug: visibility_term_ids=' . print_r($visibility_term_ids, true));

		// Base query args
		$query_args = [
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'tax_query' => [],
			'meta_query' => [],
			'no_found_rows' => true,
		];

		// Add current taxonomy query if on taxonomy page
		if ($is_tax_page && $queried_object instanceof WP_Term && $category_slug) {
			$query_args['tax_query'][] = [
				'taxonomy' => $queried_object->taxonomy,
				'field' => $use_value_id_in_url ? 'term_id' : 'slug',
				'terms' => $use_value_id_in_url ? $queried_object->term_id : $category_slug,
				'operator' => 'IN'
			];
		}

		// Add product visibility query
		if (!empty($visibility_term_ids)) {
			$query_args['tax_query'][] = [
				'taxonomy' => 'product_visibility',
				'field' => 'term_taxonomy_id',
				'terms' => $visibility_term_ids,
				'operator' => 'NOT IN',
			];
		}

		// Apply filter terms
		$this->apply_filter_terms_to_query($query_args, $filter_terms, $use_value_id_in_url);

		// Add price filter if provided
		if ($min_price !== null || $max_price !== null) {
			$query_args['meta_query'][] = [
				'key' => '_price',
				'value' => [$min_price ?? 0, $max_price ?? PHP_INT_MAX],
				'type' => 'NUMERIC',
				'compare' => 'BETWEEN'
			];
		}

		// Ensure tax_query relation
		if (!empty($query_args['tax_query']) && count($query_args['tax_query']) > 1) {
			$query_args['tax_query']['relation'] = 'AND';
		}

		// Process each active attribute
		foreach ($active_attributes as $attribute) {
			if ($attribute === 'product_cat') {
				continue; // Bỏ qua product_cat, sẽ được xử lý bởi calculate_category_counts
			}

			if (empty($settings['show_term_product_count'][$attribute]) && !$hide_empty_terms) {
				//error_log('WCPF Debug: Skipping attribute=' . $attribute . ' due to settings');
				continue;
			}

			if ($attribute === 'price') {
				if (empty($settings['price_ranges'])) {
					//error_log('WCPF Debug: No price_ranges defined for attribute=price');
					continue;
				}
				foreach ($settings['price_ranges'] as $range) {
					$price_key = $range['min'] . '-' . ($range['max'] ?? 'max');
					$temp_query_args = $query_args;
					$temp_query_args['meta_query'] = array_merge($temp_query_args['meta_query'], [[
						'key' => '_price',
						'value' => [$range['min'], $range['max'] ?? PHP_INT_MAX],
						'type' => 'NUMERIC',
						'compare' => 'BETWEEN'
					]]);

					//error_log('WCPF Debug: price temp_query_args=' . print_r($temp_query_args, true));

					$query = new WP_Query($temp_query_args);
					$count = $query->post_count;
					$term_product_counts['price'][$price_key] = $count; // Always include count, even if 0
					//error_log('WCPF Debug: calculate_term_product_counts taxonomy=price, term=' . $price_key . ', count=' . $count . ', query=' . $query->request);
				}
			} elseif ($attribute === 'stock_status') {
				$stock_options = ['stock-in', 'stock-out', 'on-sale'];
				$active_terms = $settings['active_attribute_terms']['stock_status'] ?? $stock_options;
				//error_log('WCPF Debug: stock_status active_terms=' . print_r($active_terms, true));

				foreach ($active_terms as $status) {
					$temp_query_args = $query_args;
					$temp_query_args['meta_query'] = array_merge($temp_query_args['meta_query'], [[
						'relation' => 'AND'
					]]);
					if ($status === 'stock-in') {
						$temp_query_args['meta_query'][] = [
							'key' => '_stock_status',
							'value' => 'instock',
							'compare' => '='
						];
					} elseif ($status === 'stock-out') {
						$temp_query_args['meta_query'][] = [
							'key' => '_stock_status',
							'value' => 'outofstock',
							'compare' => '='
						];
					} elseif ($status === 'on-sale') {
						$temp_query_args['meta_query'][] = [
							'key' => '_sale_price',
							'value' => 0,
							'compare' => '>',
							'type' => 'NUMERIC'
						];
					}

					//error_log('WCPF Debug: stock_status temp_query_args=' . print_r($temp_query_args, true));

					$query = new WP_Query($temp_query_args);
					$count = $query->post_count;
					$term_product_counts['stock_status'][$status] = $count; // Always include count, even if 0
					//error_log('WCPF Debug: calculate_term_product_counts taxonomy=stock_status, term=' . $status . ', count=' . $count . ', query=' . $query->request);
				}
			} elseif (taxonomy_exists($attribute)) {
				$taxonomy_key = $attribute;
				$active_terms = $settings['active_attribute_terms'][$taxonomy_key] ?? [];
				//error_log('WCPF Debug: taxonomy_key=' . $taxonomy_key . ', active_terms=' . print_r($active_terms, true));

				// Convert slugs to term IDs if use_value_id_in_url is true
				$term_ids = [];
				if ($use_value_id_in_url) {
					foreach ($active_terms as $slug) {
						$term = get_term_by('slug', $slug, $taxonomy_key);
						if ($term && !is_wp_error($term)) {
							$term_ids[] = $term->term_id;
							//error_log('WCPF Debug: taxonomy=' . $taxonomy_key . ', slug=' . $slug . ', term_id=' . $term->term_id);
						} else {
							//error_log('WCPF Debug: No term found for taxonomy=' . $taxonomy_key . ', slug=' . $slug);
						}
					}
				} else {
					$term_ids = $active_terms;
				}

				if (empty($term_ids)) {
					//error_log('WCPF Debug: No valid term IDs for taxonomy=' . $taxonomy_key);
					continue;
				}

				$terms = get_terms([
					'taxonomy' => $taxonomy_key,
					'hide_empty' => false,
					'include' => $use_value_id_in_url ? $term_ids : [],
					'slug' => $use_value_id_in_url ? [] : $active_terms,
				]);

				if (is_wp_error($terms)) {
					//error_log('WCPF Debug: get_terms error for taxonomy=' . $taxonomy_key . ': ' . $terms->get_error_message());
					continue;
				}
				if (empty($terms)) {
					//error_log('WCPF Debug: No terms found for taxonomy=' . $taxonomy_key);
					continue;
				}

				foreach ($terms as $term) {
					$temp_query_args = $query_args;
					// Remove existing tax_query conditions for this taxonomy
					$temp_query_args['tax_query'] = array_filter($temp_query_args['tax_query'], function ($tax) use ($taxonomy_key) {
						return !isset($tax['taxonomy']) || $tax['taxonomy'] !== $taxonomy_key;
					});
					// Add condition for the current term
					$temp_query_args['tax_query'][] = [
						'taxonomy' => $taxonomy_key,
						'field' => $use_value_id_in_url ? 'term_id' : 'slug',
						'terms' => $use_value_id_in_url ? $term->term_id : $term->slug,
						'operator' => 'IN'
					];

					// Ensure tax_query relation
					if (count($temp_query_args['tax_query']) > 1) {
						$temp_query_args['tax_query']['relation'] = 'AND';
					}

					//error_log('WCPF Debug: taxonomy=' . $taxonomy_key . ', term=' . ($use_value_id_in_url ? $term->term_id : $term->slug) . ', temp_query_args=' . print_r($temp_query_args, true));

					$query = new WP_Query($temp_query_args);
					$count = $query->post_count;
					$term_product_counts[$taxonomy_key][$term->slug] = $count; // Always include count, even if 0
					//error_log('WCPF Debug: calculate_term_product_counts taxonomy=' . $taxonomy_key . ', term=' . ($use_value_id_in_url ? $term->term_id : $term->slug) . ', count=' . $count . ', query=' . $query->request);
				}
			}
		}

		// Cache the results
		if (!empty($term_product_counts)) {
			set_transient($cache_key, $term_product_counts, HOUR_IN_SECONDS);
			//error_log('WCPF Debug: calculate_term_product_counts cached counts for key=' . $cache_key);
		} else {
			//error_log('WCPF Debug: calculate_term_product_counts empty counts, not cached for key=' . $cache_key);
		}
		//error_log('WCPF Debug: calculate_term_product_counts final counts=' . print_r($term_product_counts, true));

		return $term_product_counts;
}

	public function calculate_category_counts($active_attributes, $settings, $hide_empty_terms) {
        $counts = [];
        if (!in_array('product_cat', $active_attributes)) {
            return $counts;
        }

        $active_terms = $settings['active_attribute_terms']['product_cat'] ?? [];
        if (empty($active_terms)) {
            return $counts;
        }

        $use_value_id_in_url = !empty(get_option('wcpf_general_settings', [])['use_value_id_in_url']);
        $term_args = [
            'taxonomy' => 'product_cat',
            'hide_empty' => $hide_empty_terms,
        ];

        if ($use_value_id_in_url) {
            $term_ids = [];
            foreach ($active_terms as $value) {
                $term = get_term_by('slug', $value, 'product_cat');
                if ($term) {
                    $term_ids[] = $term->term_id;
                }
            }
            $term_args['include'] = $term_ids;
        } else {
            $term_args['slug'] = $active_terms;
        }

        $terms = get_terms($term_args);
        if (is_wp_error($terms) || empty($terms)) {
            return $counts;
        }

        foreach ($terms as $term) {
            $args = [
                'post_type' => 'product',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'tax_query' => [
                    [
                        'taxonomy' => 'product_cat',
                        'field' => $use_value_id_in_url ? 'term_id' : 'slug',
                        'terms' => $use_value_id_in_url ? $term->term_id : $term->slug,
                    ],
                ],
            ];

            $query = new WP_Query($args);
            $counts['product_cat'][$term->slug] = $query->post_count;
        }

        return $counts;
    }

/**
 * Helper function to apply filter terms to WP_Query args.
 *
 * @param array &$query_args WP_Query arguments.
 * @param array $filter_terms Filter terms to apply.
 * @param bool $use_value_id_in_url Whether to use term IDs in URLs.
 */
	private function apply_filter_terms_to_query(&$query_args, $filter_terms, $use_value_id_in_url) {
		$taxonomy_groups = [];
		$stock_status_queries = [];

		//error_log('WCPF Debug: apply_filter_terms_to_query filter_terms=' . print_r($filter_terms, true));

		foreach ($filter_terms as $filter) {
			if (strpos($filter, ':') === false) {
				continue;
			}
			list($tax, $term) = explode(':', $filter, 2);
			if ($tax === 'stock_status') {
				if ($term === 'stock-in') {
					$stock_status_queries[] = [
						'key' => '_stock_status',
						'value' => 'instock',
						'compare' => '='
					];
				} elseif ($term === 'stock-out') {
					$stock_status_queries[] = [
						'key' => '_stock_status',
						'value' => 'outofstock',
						'compare' => '='
					];
				} elseif ($term === 'on-sale') {
					$stock_status_queries[] = [
						'key' => '_sale_price',
						'value' => 0,
						'compare' => '>',
						'type' => 'NUMERIC'
					];
				}
			} elseif (taxonomy_exists($tax)) {
				$term_value = $use_value_id_in_url ? (int) $term : sanitize_text_field($term);
				if ($term_value) {
					$taxonomy_groups[$tax][] = $term_value;
				}
			}
		}

		// Add stock_status queries
		if (!empty($stock_status_queries)) {
			$query_args['meta_query'][] = [
				'relation' => 'OR',
				$stock_status_queries
			];
		}

		// Add taxonomy queries
		foreach ($taxonomy_groups as $tax => $terms) {
			$query_args['tax_query'][] = [
				'taxonomy' => $tax,
				'field' => $use_value_id_in_url ? 'term_id' : 'slug',
				'terms' => $terms,
				'operator' => 'IN',
			];
		}

		//error_log('WCPF Debug: apply_filter_terms_to_query taxonomy_groups=' . print_r($taxonomy_groups, true));

		// Add search term if present
		$search_terms = array_filter($filter_terms, function($filter) {
			return strpos($filter, 'search:') === 0;
		});
		if (!empty($search_terms)) {
			$query_args['s'] = str_replace('search:', '', reset($search_terms));
		}

		// Ensure tax_query relation
		if (!empty($query_args['tax_query']) && count($query_args['tax_query']) > 1) {
			$query_args['tax_query']['relation'] = 'AND';
		}

		// Ensure meta_query relation
		if (!empty($query_args['meta_query']) && count($query_args['meta_query']) > 1) {
			$query_args['meta_query']['relation'] = 'AND';
		}

		//error_log('WCPF Debug: apply_filter_terms_to_query final query_args=' . print_r($query_args, true));
	}
	public function build_category_tree($categories, $parent_id = 0, $depth = 0) {
		$tree = [];
		//error_log('WCPF Debug: build_category_tree parent_id=' . $parent_id . ', depth=' . $depth . ', categories=' . print_r(array_keys($categories), true));
		foreach ($categories as $category) {
			if ($category->parent == $parent_id) {
				$category->depth = $depth;
				$tree[] = $category;
				$children = $this->build_category_tree($categories, $category->term_id, $depth + 1);
				$tree = array_merge($tree, $children);
			}
		}
		//error_log('WCPF Debug: build_category_tree result for parent_id=' . $parent_id . ': ' . print_r(wp_list_pluck($tree, 'slug'), true));
		return $tree;
	}

    /**
     * Get parent term ID.
     *
     * @param int $term_id Term ID.
     * @param string $taxonomy Taxonomy name.
     * @return int Parent term ID or 0.
     */
    private function wp_get_term_parent_id($term_id, $taxonomy) {
        $term = get_term($term_id, $taxonomy);
        return $term && !is_wp_error($term) ? $term->parent : 0;
    }
}
?>