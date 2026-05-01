<?php
/**
 * WCPF_Filter_Widget class for displaying product filters in a widget.
 */
class WCPF_Filter_Widget extends WP_Widget {
    /**
     * Constructor to set up the widget.
     */
    public function __construct() {
        parent::__construct(
            'wcpf_filter_widget',
            __('WooCommerce Product Filter', 'wc-product-filter'),
            [
                'description' => __('Displays a product filter with configurable layout.', 'wc-product-filter'),
            ]
        );
    }

    /**
     * Widget frontend output.
     *
     * @param array $args Widget arguments.
     * @param array $instance Widget instance settings.
     */
    public function widget($args, $instance) {
        $plugin_root_url = plugins_url('', 'woocommerce-product-filter/woocommerce-product-filter.php');
        // Only display on shop, category, or brand pages
        if (!is_shop() && !is_product_category() && !is_tax('product_brand')) {
            return;
        }

        $general_settings = get_option('wcpf_general_settings', [
            'widget_usage' => 'disabled',
            'widget_filter' => '',
            'hide_empty_terms' => 0,
            'selected_filters_position' => 'above_filters',
            'apply_filter_behavior' => 'apply_button',
            'default_filter_group_state' => 'closed'
        ]);

        // Check if widget usage is enabled and a valid filter is selected
        if ($general_settings['widget_usage'] !== 'enabled' || empty($general_settings['widget_filter'])) {
            return;
        }

        $title = apply_filters('widget_title', !empty($instance['title']) ? $instance['title'] : '');
        $filter_id = $general_settings['widget_filter'];
        $layout = !empty($instance['layout']) ? $instance['layout'] : 'horizontal';
        $hide_empty_terms = !empty($general_settings['hide_empty_terms']);
        // Get all filters
        $filters = get_option('wcpf_filters', []);

        // Validate filter_id
        if (!isset($filters[$filter_id]) || !$filters[$filter_id]['active']) {
            return;
        }

        // Get filter settings
        $filter_settings = $filters[$filter_id];
        $layout_styles = isset($filter_settings['layout_style']) ? $filter_settings['layout_style'] : [];
        $term_styles = isset($filter_settings['term_style']) ? $filter_settings['term_style'] : [];

        // Initialize WCPF_Product_Filter if not already done
        if (!class_exists('WCPF_Product_Filter')) {
            require_once plugin_dir_path(__FILE__) . '../class-product-filter.php';
            $filter_instance = new WCPF_Product_Filter();
            $filter_instance->init();
        } else {
            $filter_instance = new WCPF_Product_Filter();
        }

        // Start output
        echo $args['before_widget'];

        if (!empty($title)) {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }

        // Render filter based on layout
        if ($layout === 'horizontal') {
            // Horizontal: Use original render_filter_ui with forced filter_id
            ob_start();
            $this->render_horizontal_filter($filter_instance, $filter_id);
            echo ob_get_clean();
        } else {
            // Vertical: Render custom vertical layout
            $this->render_vertical_filter($filter_id, $filter_settings, $general_settings['selected_filters_position'], $general_settings['apply_filter_behavior'], $layout_styles, $term_styles);
        }

        echo $args['after_widget'];
    }

    /**
     * Render selected filters above shop loop.
     *
     * @param WCPF_Product_Filter $filter_instance The filter instance.
     * @param string $filter_id The filter ID.
     * @param array $filter_settings The filter settings.
     */
    public function render_selected_filters_above_shop_loop($filter_instance, $filter_id, $filter_settings) {
        $general_settings = get_option('wcpf_general_settings', [
            'custom_texts' => [
                'apply_button' => 'Áp dụng',
                'reset_button' => 'Xóa hết',
                'mobile_menu_button' => 'Bộ lọc',
                'mobile_menu_title' => 'BỘ LỌC',
            ]
        ]);
        $hide_empty_terms = !empty($general_settings['hide_empty_terms']);
        $use_value_id_in_url = !empty($general_settings['use_value_id_in_url']);
        $default_filter_group_state = $general_settings['default_filter_group_state'] ?? 'closed';
        $queried_object = get_queried_object();
        $is_brand_page = is_tax('product_brand');
        $taxonomy = $is_brand_page ? 'product_brand' : 'product_cat';
        $category_slug = $queried_object && isset($queried_object->slug) ? $queried_object->slug : '';
        $shop_id = wc_get_page_id('shop');
        $shop_page_slug = get_page_uri($shop_id);

        // Prepare filter settings
        $settings = [
            'active_attributes' => isset($filter_settings['active_attributes']) ? (array)$filter_settings['active_attributes'] : [],
            'active_attribute_terms' => isset($filter_settings['active_attribute_terms']) ? (array)$filter_settings['active_attribute_terms'] : [],
            'price_ranges' => isset($filter_settings['price_ranges']) ? (array)$filter_settings['price_ranges'] : [],
            'attribute_labels' => isset($filter_settings['attribute_labels']) ? (array)$filter_settings['attribute_labels'] : [],
            'custom_css_classes' => isset($filter_settings['custom_css_classes']) ? (array)$filter_settings['custom_css_classes'] : [],
            'custom_texts' => $general_settings['custom_texts'],
            'max_terms' => isset($filter_settings['max_terms']) ? (array)$filter_settings['max_terms'] : [],
            'show_term_product_count' => isset($filter_settings['show_term_product_count']) ? (array)$filter_settings['show_term_product_count'] : [],
            'display_settings' => isset($filter_settings['display_settings']) ? (array)$filter_settings['display_settings'] : []
        ];

        $attributes = wc_get_attribute_taxonomies();
        $active_attributes = $settings['active_attributes'];
        $filtered_attributes = array_filter($attributes, function($attr) use ($active_attributes) {
            return in_array('pa_' . $attr->attribute_name, $active_attributes);
        });

        if ($is_brand_page) {
            $active_attributes = array_diff($active_attributes, ['product_brand']);
        }

        $selected_filters = (new WCPF_Product_Filter())->get_selected_filters();
        $min_price = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? floatval($_GET['min_price']) : null;
        $max_price = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? floatval($_GET['max_price']) : null;

        $price_ranges = $settings['price_ranges'];
        $filter_terms = [];
        foreach ($selected_filters as $tax => $terms) {
            foreach ($terms as $term) {
                $filter_terms[] = $tax . ':' . ($use_value_id_in_url ? $term->term_id : $term->slug);
            }
        }

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

        ?>
        <div class="wcpf-selected-filters">
            <?php if (!empty($selected_filters) || $min_price !== null || $max_price !== null) : ?>
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
                        <span class="wcpf-filter-tag">
                            <?php echo esc_html($term->name); ?>
                            <a href="#" class="wcpf-remove-filter" data-taxonomy="<?php echo esc_attr($taxonomy); ?>" data-term="<?php echo esc_attr($data_term); ?>"></a>
                        </span>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if ($min_price !== null || $max_price !== null) : ?>
                    <?php
                    $price_key = $min_price . '-' . ($max_price ?? 'max');
                    $count = isset($term_product_counts['price'][$price_key]) ? (int) $term_product_counts['price'][$price_key] : 0;
                    if ($hide_empty_terms && $count === 0) {
                        error_log('WCPF Debug: render_selected_filters_above_shop_loop skipping empty price range: min=' . $min_price . ', max=' . ($max_price ?? 'max') . ', count=' . $count);
                    } else {
                    ?>
                        <span class="wcpf-filter-tag">
                            <?php
                            $price_label = '';
                            foreach ($price_ranges as $range) {
                                if ($min_price == $range['min'] && ($max_price == $range['max'] || ($range['max'] === null && $max_price === null))) {
                                    $price_label = $range['label'];
                                    break;
                                }
                            }
                            echo esc_html($price_label ?: ($min_price . ($max_price ? '-' . $max_price : '')));
                            ?>
                            <a href="#" class="wcpf-remove-filter" data-taxonomy="price" data-term="price-<?php echo esc_attr($min_price); ?>-<?php echo esc_attr($max_price ?? 'max'); ?>"></a>
                        </span>
                    <?php } ?>
                <?php endif; ?>
                <a href="#" class="wcpf-reset-filters"><?php echo esc_html($general_settings['custom_texts']['reset_button']); ?></a>
            <?php endif; ?>
            <div class="wcpf-loading-overlay" style="display: none;">
                <div class="wcpf-spinner"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render horizontal filter using WCPF_Product_Filter::render_filter_ui.
     *
     * @param WCPF_Product_Filter $filter_instance The filter instance.
     * @param string $filter_id The filter ID.
     */
    private function render_horizontal_filter($filter_instance, $filter_id) {
        ob_start();
        // Temporarily override filters to render only the selected filter
        $original_filters = get_option('wcpf_filters', []);
        $temp_filters = [$filter_id => $original_filters[$filter_id]];
        update_option('wcpf_filters', $temp_filters);

        // Render filter UI
        $filter_instance->render_filter_ui($filter_id);

        // Restore original filters
        update_option('wcpf_filters', $original_filters);
        echo ob_get_clean();
    }

    /**
     * Get parent ID of a term.
     *
     * @param int $term_id Term ID.
     * @param string $taxonomy Taxonomy name.
     * @return int Parent ID or 0 if none.
     */
    private function wp_get_term_parent_id($term_id, $taxonomy) {
        $term = get_term($term_id, $taxonomy);
        if (is_wp_error($term) || !$term) {
            return 0;
        }
        return $term->parent;
    }

    /**
     * Build category tree for parent_child mode.
     *
     * @param array $categories_by_id Categories indexed by ID.
     * @param int $parent_id Parent ID to start building.
     * @param int $depth Current depth.
     * @return array Ordered categories with depth.
     */
    private function build_category_tree($categories_by_id, $parent_id, $depth = 0) {
        $ordered = [];
        foreach ($categories_by_id as $category) {
            if ($category->parent == $parent_id) {
                $category->depth = $depth;
                $ordered[] = $category;
                $children = $this->build_category_tree($categories_by_id, $category->term_id, $depth + 1);
                $ordered = array_merge($ordered, $children);
            }
        }
        return $ordered;
    }

    /**
     * Render vertical filter based on mobile filter menu.
     *
     * @param string $filter_id The filter ID.
     * @param array $filter_settings The filter settings.
     * @param string $selected_filters_position The position of selected filters.
     * @param string $apply_filter_behavior The filter apply behavior.
     * @param array $layout_styles Layout styles for each attribute.
     * @param array $term_styles Term styles for each attribute.
     */
    private function render_vertical_filter($filter_id, $filter_settings, $selected_filters_position, $apply_filter_behavior, $layout_styles, $term_styles) {
        $general_settings = get_option('wcpf_general_settings', [
            'custom_texts' => [
                'apply_button' => 'Áp dụng',
                'reset_button' => 'Xóa hết',
                'mobile_menu_button' => 'Bộ lọc',
                'mobile_menu_title' => 'BỘ LỌC',
                'show_more_text' => '+ Xem thêm',
                'show_less_text' => '- Thu gọn'
            ],
            'hide_empty_terms' => 0,
            'apply_filter_behavior' => 'apply_button',
            'default_filter_group_state' => 'closed',
            'max_terms_per_attribute' => 0,
            'use_value_id_in_url' => 0
        ]);
        $hide_empty_terms = !empty($general_settings['hide_empty_terms']);
        $default_filter_group_state = $general_settings['default_filter_group_state'] ?? 'closed';
        $use_value_id_in_url = !empty($general_settings['use_value_id_in_url']);
        $queried_object = get_queried_object();
        $is_brand_page = is_tax('product_brand');
        $is_category_page = is_product_category();
        $taxonomy = $is_brand_page ? 'product_brand' : 'product_cat';
        $category_slug = $queried_object && isset($queried_object->slug) ? $queried_object->slug : '';
        $category_id = $queried_object && isset($queried_object->term_id) && $is_category_page ? $queried_object->term_id : 0;
        $shop_id = wc_get_page_id('shop');
        $shop_page_slug = get_page_uri($shop_id);

        // Prepare filter settings
        $settings = [
            'active_attributes' => isset($filter_settings['active_attributes']) ? (array)$filter_settings['active_attributes'] : [],
            'active_attribute_terms' => isset($filter_settings['active_attribute_terms']) ? (array)$filter_settings['active_attribute_terms'] : [],
            'price_ranges' => isset($filter_settings['price_ranges']) ? (array)$filter_settings['price_ranges'] : [],
            'attribute_labels' => isset($filter_settings['attribute_labels']) ? (array)$filter_settings['attribute_labels'] : [],
            'custom_css_classes' => isset($filter_settings['custom_css_classes']) ? (array)$filter_settings['custom_css_classes'] : [],
            'custom_texts' => $general_settings['custom_texts'],
            'max_terms' => isset($filter_settings['max_terms']) ? (array)$filter_settings['max_terms'] : [],
            'show_term_product_count' => isset($filter_settings['show_term_product_count']) ? (array)$filter_settings['show_term_product_count'] : [],
            'display_settings' => isset($filter_settings['display_settings']) ? (array)$filter_settings['display_settings'] : [],
            'category_display_mode' => isset($filter_settings['category_display_mode']) ? (array)$filter_settings['category_display_mode'] : [],
            'category_mode' => isset($filter_settings['category_mode']) ? (array)$filter_settings['category_mode'] : []
        ];

        $attributes = wc_get_attribute_taxonomies();
        $active_attributes = $settings['active_attributes'];
        $filtered_attributes = array_filter($attributes, function($attr) use ($active_attributes) {
            return in_array('pa_' . $attr->attribute_name, $active_attributes);
        });

        if ($is_brand_page) {
            $active_attributes = array_diff($active_attributes, ['product_brand']);
        }

        $selected_filters = (new WCPF_Product_Filter())->get_selected_filters();
        $min_price = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? floatval($_GET['min_price']) : null;
        $max_price = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? floatval($_GET['max_price']) : null;

        $price_ranges = $settings['price_ranges'];
        $filter_terms = [];
        foreach ($selected_filters as $tax => $terms) {
            foreach ($terms as $term) {
                $filter_terms[] = $tax . ':' . ($use_value_id_in_url ? $term->term_id : $term->slug);
            }
        }

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

        // Debug term_product_counts để kiểm tra dữ liệu
        error_log('WCPF Debug: render_vertical_filter term_product_counts=' . print_r($term_product_counts, true));

        ?>
        <div class="wcpf-filter-wrapper wcpf-filter-vertical">
            <div class="wcpf-mobile-filter-menu">
                <?php if ($selected_filters_position === 'above_filters') : ?>
                    <div class="wcpf-selected-filters">
                        <?php if (!empty($selected_filters) || $min_price !== null || $max_price !== null) : ?>
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
                                        <a href="#" class="wcpf-remove-filter" data-taxonomy="<?php echo esc_attr($taxonomy); ?>" data-term="<?php echo esc_attr($data_term); ?>"></a>
                                    </span>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            <?php if ($min_price !== null || $max_price !== null) : ?>
                                <?php
                                $price_key = $min_price . '-' . ($max_price ?? 'max');
                                $count = isset($term_product_counts['price'][$price_key]) ? (int) $term_product_counts['price'][$price_key] : 0;
                                if ($hide_empty_terms && $count === 0) {
                                    error_log('WCPF Debug: render_vertical_filter skipping empty price range: min=' . $min_price . ', max=' . ($max_price ?? 'max') . ', count=' . $count);
                                } else {
                                ?>
                                    <span class="wcpf-filter-tag">
                                        <?php
                                        $price_label = '';
                                        foreach ($price_ranges as $range) {
                                            if ($min_price == $range['min'] && ($max_price == $range['max'] || ($range['max'] === null && $max_price === null))) {
                                                $price_label = $range['label'];
                                                break;
                                            }
                                        }
                                        echo esc_html($price_label ?: ($min_price . ($max_price ? '-' . $max_price : '')));
                                        ?>
                                        <a href="#" class="wcpf-remove-filter" data-taxonomy="price" data-term="price-<?php echo esc_attr($min_price); ?>-<?php echo esc_attr($max_price ?? 'max'); ?>"></a>
                                    </span>
                                <?php } ?>
                            <?php endif; ?>
                            <a href="#" class="wcpf-reset-filters"><?php echo esc_html($settings['custom_texts']['reset_button']); ?></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($active_attributes as $filter_key) : ?>
                    <?php
                    // Kiểm tra display_settings
                    $display_setting = isset($settings['display_settings'][$filter_key]) ? $settings['display_settings'][$filter_key] : 'both';
                    $is_mobile = wp_is_mobile();
                    if (($display_setting === 'desktop' && $is_mobile) || ($display_setting === 'mobile' && !$is_mobile)) {
                        continue;
                    }

                    $custom_class = !empty($settings['custom_css_classes'][$filter_key]) ? esc_attr($settings['custom_css_classes'][$filter_key]) : '';
                    $layout_style = isset($layout_styles[$filter_key]) ? $layout_styles[$filter_key] : 'flow';
                    $term_style = isset($term_styles[$filter_key]) ? $term_styles[$filter_key] : 'label';
                    $show_term_count = isset($settings['show_term_product_count'][$filter_key]) && $settings['show_term_product_count'][$filter_key];
                    $max_terms = isset($settings['max_terms'][$filter_key]) && is_numeric($settings['max_terms'][$filter_key]) && $settings['max_terms'][$filter_key] > 0
                        ? (int)$settings['max_terms'][$filter_key]
                        : (int)($general_settings['max_terms_per_attribute'] ?? 0);
                    ?>
				<?php if ($filter_key === 'product_cat') : ?>
					<?php
					$active_terms = $settings['active_attribute_terms']['product_cat'] ?? [];
					if (empty($active_terms)) {
						error_log('WCPF Debug: render_vertical_filter product_cat - No active terms, skipping');
						continue;
					}

					// Xác định chế độ hiển thị danh mục
					$category_display_mode = isset($settings['category_display_mode']['product_cat']) ? $settings['category_display_mode']['product_cat'] : 'parent_child';
					$category_mode = isset($settings['category_mode']['product_cat']) ? $settings['category_mode']['product_cat'] : 'category_filter';

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
							if ($uncategorized_slug === 'uncategorized' && $uncategorized_count === 0) {
								$term_args['exclude'] = [$uncategorized_id];
								error_log('WCPF Debug: render_vertical_filter product_cat - excluding uncategorized_id=' . $uncategorized_id . ' (empty and default slug)');
							}
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
							} else {
								$parent_id = $this->wp_get_term_parent_id($category_id, 'product_cat') ?: 0;
								$term_args['parent'] = $parent_id;
								unset($term_args['slug']);
								unset($term_args['include']);
							}
						} else {
							$term_args['parent'] = 0;
							$term_args['slug'] = $active_terms;
						}
					} elseif ($category_display_mode === 'contextual') {
						if ($is_category_page && $category_id) {
							$parent_id = $this->wp_get_term_parent_id($category_id, 'product_cat') ?: 0;
							$term_args['parent'] = $parent_id;
							unset($term_args['slug']);
							unset($term_args['include']);
						} else {
							$term_args['parent'] = 0;
							$term_args['slug'] = $active_terms;
						}
					}

					// Lấy danh mục
					$categories = get_terms($term_args);
					if (is_wp_error($categories)) {
						error_log('WCPF Debug: render_vertical_filter product_cat - get_terms error: ' . $categories->get_error_message());
						continue;
					}
					if (empty($categories)) {
						error_log('WCPF Debug: render_vertical_filter product_cat - No categories found');
						continue;
					}

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
							error_log('WCPF Debug: render_vertical_filter product_cat - Calculated recursive product count for ' . $category->slug . ': ' . $product_count);
						}
					}

					$ordered_categories = [];
					if ($category_display_mode === 'parent_child') {
						$categories_by_id = [];
						foreach ($categories as $cat) {
							$categories_by_id[$cat->term_id] = $cat;
						}
						$ordered_categories = $this->build_category_tree($categories_by_id, 0);
					} else {
						$ordered_categories = $categories;
					}

					if (empty($ordered_categories)) {
						error_log('WCPF Debug: render_vertical_filter product_cat - No ordered categories');
						continue;
					}

					// Xác định danh mục hiện tại
					$current_term_id = 0;
					if ($is_category_page && $category_id) {
						$current_term_id = $category_id;
					}

					// Lấy tất cả danh mục cha của danh mục hiện tại
					$ancestor_ids = $current_term_id ? get_ancestors($current_term_id, 'product_cat') : [];

					// Lấy danh mục có con
					$terms_with_children = [];
					foreach ($ordered_categories as $category) {
						$child_terms = get_terms([
							'taxonomy' => 'product_cat',
							'parent' => $category->term_id,
							'hide_empty' => $hide_empty_terms,
							'fields' => 'ids',
						]);
						if (!is_wp_error($child_terms) && !empty($child_terms)) {
							$terms_with_children[] = $category->term_id;
						}
					}

					$category_terms_count = count($ordered_categories);
					$show_toggle = $max_terms > 0 && $category_terms_count > $max_terms;
					?>
					<details <?php echo $default_filter_group_state === 'open' ? 'open' : ''; ?>>
						<summary class="wcpf-filter-section-title"><?php echo esc_html($settings['attribute_labels']['product_cat'] ?: __('DANH MỤC', 'wc-product-filter')); ?></summary>
						<div class="wcpf-filter-section <?php echo $custom_class; ?>" data-taxonomy="product_cat">
							<?php if ($category_mode === 'category_filter') : ?>
								<ul class="wcpf-mobile-filter-grid list category <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
									<?php
									foreach ($ordered_categories as $index => $category) :
										$count = isset($term_product_counts['product_cat'][$category->slug]) ? (int) $term_product_counts['product_cat'][$category->slug] : 0;
										if ($hide_empty_terms && $count === 0) {
											continue;
										}
										$is_selected = in_array($use_value_id_in_url ? $category->term_id : $category->slug, wp_list_pluck($selected_filters['product_cat'] ?? [], $use_value_id_in_url ? 'term_id' : 'slug'));
										$is_hidden = $show_toggle && $index >= $max_terms ? 'hidden' : '';
										$depth = $category_display_mode === 'parent_child' ? ($category->depth ?? 0) : 0;
										?>
										<li class="wcpf-filter-label <?php echo esc_attr($term_style); ?> <?php echo $is_selected ? 'selected' : ''; ?> <?php echo $is_hidden; ?>" 
											data-taxonomy="product_cat" 
											data-term="<?php echo esc_attr($use_value_id_in_url ? $category->term_id : $category->slug); ?>" 
											style="padding-left: <?php echo $depth * 20; ?>px;">
											<input type="checkbox" <?php checked($is_selected); ?>>
											<span class="checkmark"></span>
											<span class="term-title"><?php echo esc_html($category->name); ?></span>
											<?php if ($show_term_count && $count > 0) : ?>
												<span class="wcpf-term-count"><?php echo esc_html($count); ?></span>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php else : // category_text_link ?>
								<ul class="wcpf-mobile-filter-grid list category text_link <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
									<?php
									$open_lists = [];
									foreach ($ordered_categories as $index => $category) :
										$count = isset($term_product_counts['product_cat'][$category->slug]) ? (int) $term_product_counts['product_cat'][$category->slug] : 0;
										if ($hide_empty_terms && $count === 0) {
											continue;
										}
										$is_hidden = $show_toggle && $index >= $max_terms ? 'hidden' : '';
										$depth = $category_display_mode === 'parent_child' ? ($category->depth ?? 0) : 0;
										$has_children = in_array($category->term_id, $terms_with_children);
										$is_open = $has_children && ($current_term_id == $category->term_id || in_array($category->term_id, $ancestor_ids));
										$term_link = get_term_link($category, 'product_cat');
										if (is_wp_error($term_link)) {
											$term_link = '#';
											error_log('WCPF Debug: render_vertical_filter product_cat - Failed to get term link for ' . $category->slug);
										}

										// Đóng các danh mục con đã mở ở cấp cao hơn nếu cần
										while (!empty($open_lists) && end($open_lists)['depth'] >= $depth) {
											$last_open = array_pop($open_lists);
											echo '</ul>';
										}

										// Mở danh mục nếu có con
										if ($has_children && $category_display_mode === 'parent_child') :
											$open_lists[] = ['term_id' => $category->term_id, 'depth' => $depth];
											?>
											<li class="wcpf-category-parent <?php echo $is_hidden; ?>">
												<div class="wcpf-category-parent-title text_link">
													<a class="wcpf-filter-label wcpf-text-link <?php echo esc_attr($term_style); ?> <?php echo $current_term_id == $category->term_id ? 'selected' : ''; ?>" 
													   href="<?php echo esc_url($term_link); ?>" 
													   data-taxonomy="product_cat" 
													   data-term="<?php echo esc_attr($use_value_id_in_url ? $category->term_id : $category->slug); ?>">
														<span class="term-title"><?php echo esc_html($category->name); ?></span>
														<?php if ($show_term_count && $count > 0) : ?>
															<span class="wcpf-term-count">(<?php echo esc_html($count); ?>)</span>
														<?php endif; ?>
													</a>
													<span class="wcpf-toggle-icon <?php echo $is_open ? 'wcpf-toggle-open' : 'wcpf-toggle-closed'; ?>" data-toggle-target="children-<?php echo esc_attr($category->term_id); ?>"></span>
												</div>
												<ul class="wcpf-category-children" id="children-<?php echo esc_attr($category->term_id); ?>" style="display: <?php echo $is_open ? 'block' : 'none'; ?>;">
										<?php else : ?>
											<li class="<?php echo $is_hidden; ?>">
												<a class="wcpf-filter-label wcpf-text-link <?php echo esc_attr($term_style); ?> <?php echo $current_term_id == $category->term_id ? 'selected' : ''; ?>" 
												   href="<?php echo esc_url($term_link); ?>" 
												   data-taxonomy="product_cat" 
												   data-term="<?php echo esc_attr($use_value_id_in_url ? $category->term_id : $category->slug); ?>" 
												   style="padding-left: <?php echo ($depth + 1); ?>px; <?php echo $current_term_id == $category->term_id ? 'font-weight: bold;' : ''; ?>">
													<span class="term-title"><?php echo esc_html($category->name); ?></span>
													<?php if ($show_term_count && $count > 0) : ?>
														<span class="wcpf-term-count">(<?php echo esc_html($count); ?>)</span>
													<?php endif; ?>
												</a>
											</li>
										<?php endif; ?>
									<?php endforeach; ?>
									<?php
									// Đóng tất cả danh mục con còn mở
									while (!empty($open_lists)) {
										array_pop($open_lists);
										echo '</ul>';
									}
									?>
								</ul>
							<?php endif; ?>
						</div>
					</details>
                    <?php elseif ($filter_key === 'price') : ?>
                        <?php
                        $price_terms_count = count($price_ranges);
                        $show_toggle = $max_terms > 0 && $price_terms_count > $max_terms;
                        ?>
                        <details <?php echo $default_filter_group_state === 'open' ? 'open' : ''; ?>>
                            <summary class="wcpf-filter-section-title"><?php echo esc_html($settings['attribute_labels']['price'] ?: __('KHOẢNG GIÁ', 'wc-product-filter')); ?></summary>
                            <div class="wcpf-filter-section <?php echo $custom_class; ?>" data-taxonomy="price">
                                <div class="wcpf-mobile-filter-grid <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
                                    <?php foreach ($price_ranges as $index => $range) : ?>
                                        <?php
                                        $price_key = $range['min'] . '-' . ($range['max'] ?? 'max');
                                        $count = isset($term_product_counts['price'][$price_key]) ? (int) $term_product_counts['price'][$price_key] : 0;
                                        if ($hide_empty_terms && $count === 0) {
                                            error_log('WCPF Debug: render_vertical_filter skipping empty price range: min=' . $range['min'] . ', max=' . ($range['max'] ?? 'max') . ', count=' . $count);
                                            continue;
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
                                            <?php if ($show_term_count && isset($term_product_counts['price'][$price_key])) : ?>
                                                <span class="wcpf-term-count"><?php echo esc_html($count); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </details>
                    <?php elseif ($filter_key === 'product_brand' && taxonomy_exists('product_brand') && !$is_brand_page) : ?>
                        <?php
                        $active_terms = $settings['active_attribute_terms']['product_brand'] ?? [];
                        if (empty($active_terms)) {
                            error_log('WCPF Debug: render_vertical_filter no active terms for product_brand');
                            continue;
                        }
                        if ($use_value_id_in_url) {
                            $term_ids = [];
                            foreach ($active_terms as $value) {
                                $term = get_term_by('slug', $value, 'product_brand');
                                if ($term) {
                                    $term_ids[] = $term->term_id;
                                } else {
                                    error_log('WCPF Debug: render_vertical_filter term not found for slug=' . $value . ', taxonomy=product_brand');
                                }
                            }
                            $brands = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false, 'include' => $term_ids]);
                        } else {
                            $brands = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false, 'slug' => $active_terms]);
                        }
                        if (is_wp_error($brands) || empty($brands)) {
                            error_log('WCPF Debug: render_vertical_filter no brands found, active_terms=' . print_r($active_terms, true));
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
                            error_log('WCPF Debug: render_vertical_filter no ordered brands, active_terms=' . print_r($active_terms, true));
                            continue;
                        }
                        $brand_terms_count = count($ordered_brands);
                        $show_toggle = $max_terms > 0 && $brand_terms_count > $max_terms;
                        ?>
                        <details <?php echo $default_filter_group_state === 'open' ? 'open' : ''; ?>>
                            <summary class="wcpf-filter-section-title"><?php echo esc_html($settings['attribute_labels']['product_brand'] ?: __('THƯƠNG HIỆU', 'wc-product-filter')); ?></summary>
                            <div class="wcpf-filter-section filter-brand <?php echo $custom_class; ?>" data-taxonomy="product_brand">
                                <div class="wcpf-mobile-filter-grid <?php echo esc_attr($layout_style); ?> filter-brand" data-max-terms="<?php echo esc_attr($max_terms); ?>">
                                    <?php foreach ($ordered_brands as $index => $brand) : ?>
                                        <?php
                                        $count = isset($term_product_counts['product_brand'][$brand->slug]) ? (int) $term_product_counts['product_brand'][$brand->slug] : 0;
                                        if ($hide_empty_terms && $count === 0) {
                                            error_log('WCPF Debug: render_vertical_filter skipping empty brand term: slug=' . $brand->slug . ', count=' . $count);
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
                                                <img class="brand-term-img" src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($brand->name); ?>" width="20">
                                            <?php endif; ?>
                                            <span class="term-title"><?php echo esc_html($brand->name); ?></span>
                                            <?php if ($show_term_count && isset($term_product_counts['product_brand'][$brand->slug])) : ?>
                                                <span class="wcpf-term-count"><?php echo esc_html($count); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </details>
                    <?php elseif (taxonomy_exists($filter_key)) : ?>
                        <?php
                        $attribute = array_filter($filtered_attributes, function($attr) use ($filter_key) {
                            return 'pa_' . $attr->attribute_name === $filter_key;
                        });
                        $attribute = reset($attribute);
                        if (!$attribute) continue;
                        $taxonomy = 'pa_' . $attribute->attribute_name;
                        $active_terms = $settings['active_attribute_terms'][$taxonomy] ?? [];
                        if (empty($active_terms)) {
                            error_log('WCPF Debug: render_vertical_filter no active terms for taxonomy=' . $taxonomy);
                            continue;
                        }
                        if ($use_value_id_in_url) {
                            $term_ids = [];
                            foreach ($active_terms as $value) {
                                $term = get_term_by('slug', $value, $taxonomy);
                                if ($term) {
                                    $term_ids[] = $term->term_id;
                                } else {
                                    error_log('WCPF Debug: render_vertical_filter term not found for slug=' . $value . ', taxonomy=' . $taxonomy);
                                }
                            }
                            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'include' => $term_ids]);
                        } else {
                            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'slug' => $active_terms]);
                        }
                        if (is_wp_error($terms) || empty($terms)) {
                            error_log('WCPF Debug: render_vertical_filter no terms found for taxonomy=' . $taxonomy . ', active_terms=' . print_r($active_terms, true));
                            continue;
                        }
                        $ordered_terms = [];
                        foreach ($active_terms as $index => $value) {
                            foreach ($terms as $term) {
                                if ($use_value_id_in_url ? $term->term_id == ($term_ids[$index] ?? $value) : $term->slug === $value) {
                                    $ordered_terms[] = $term;
                                    break;
                                }
                            }
                        }
                        if (empty($ordered_terms)) {
                            error_log('WCPF Debug: render_vertical_filter no ordered terms for taxonomy=' . $taxonomy . ', active_terms=' . print_r($active_terms, true));
                            continue;
                        }
                        $terms_count = count($ordered_terms);
                        $show_toggle = $max_terms > 0 && $terms_count > $max_terms;
                        ?>
                        <details <?php echo $default_filter_group_state === 'open' ? 'open' : ''; ?>>
                            <summary class="wcpf-filter-section-title"><?php echo esc_html($settings['attribute_labels'][$taxonomy] ?: $attribute->attribute_label); ?></summary>
                            <div class="wcpf-filter-section <?php echo $custom_class; ?>" data-taxonomy="<?php echo esc_attr($taxonomy); ?>">
                                <div class="wcpf-mobile-filter-grid <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
                                    <?php foreach ($ordered_terms as $index => $term) : ?>
                                        <?php
                                        $count = isset($term_product_counts[$taxonomy][$term->slug]) ? (int) $term_product_counts[$taxonomy][$term->slug] : 0;
                                        if ($hide_empty_terms && $count === 0) {
                                            error_log('WCPF Debug: render_vertical_filter skipping empty term: taxonomy=' . $taxonomy . ', slug=' . $term->slug . ', count=' . $count);
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
                                                <img class="term-img" src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($term->name); ?>" width="20">
                                            <?php endif; ?>
                                            <span class="term-title"><?php echo esc_html($term->name); ?></span>
                                            <?php if ($show_term_count && isset($term_product_counts[$taxonomy][$term->slug])) : ?>
                                                <span class="wcpf-term-count"><?php echo esc_html($count); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </details>
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
                            error_log('WCPF Debug: render_vertical_filter no active terms for stock_status');
                            continue;
                        }
                        $terms_count = count($ordered_terms);
                        $show_toggle = $max_terms > 0 && $terms_count > $max_terms;
                        $selected_statuses = wp_list_pluck($selected_filters['stock_status'] ?? [], 'slug');
                        $show_term_product_count = !empty($settings['show_term_product_count']['stock_status']);
                        $custom_class = !empty($settings['custom_css_classes']['stock_status']) ? esc_attr($settings['custom_css_classes']['stock_status']) : '';
                        $layout_style = isset($layout_styles['stock_status']) ? $layout_styles['stock_status'] : 'flow';
                        $term_style = isset($term_styles['stock_status']) ? $term_styles['stock_status'] : 'label';
                        ?>
                        <details <?php echo $default_filter_group_state === 'open' ? 'open' : ''; ?>>
                            <summary class="wcpf-filter-section-title"><?php echo esc_html($settings['attribute_labels']['stock_status'] ?: __('TRẠNG THÁI', 'wc-product-filter')); ?></summary>
                            <div class="wcpf-filter-section <?php echo $custom_class; ?>" data-taxonomy="stock_status">
                                <div class="wcpf-mobile-filter-grid <?php echo esc_attr($layout_style); ?>" data-max-terms="<?php echo esc_attr($max_terms); ?>">
                                    <?php foreach ($ordered_terms as $index => $term) : ?>
                                        <?php
                                        $count = isset($term_product_counts['stock_status'][$term->slug]) ? (int) $term_product_counts['stock_status'][$term->slug] : 0;
                                        if ($hide_empty_terms && $count === 0) {
                                            error_log('WCPF Debug: render_vertical_filter skipping empty stock status: slug=' . $term->slug . ', count=' . $count);
                                            continue;
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
                                                <span class="wcpf-term-count"><?php echo esc_html($count); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </details>
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
                                       data-taxonomy="search"
                                       data-term="<?php echo esc_attr($search_term); ?>">
                                <input type="hidden" name="post_type" value="product">
                                <input type="hidden" name="wc_query" value="product_query">
                                <?php
                                foreach ($_GET as $key => $value) {
                                    if ($key !== 's' && $key !== 'post_type' && $key !== 'wc_query') {
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
                        $max_terms = isset($settings['max_terms']['sort_by']) && is_numeric($settings['max_terms']['sort_by']) 
                            ? (int)$settings['max_terms']['sort_by'] 
                            : (int)($general_settings['max_terms_per_attribute'] ?? 0);
                        ?>
                        <div class="wcpf-filter-group sortby <?php echo $custom_class; ?>">
                            <select class="wcpf-sort-select" data-taxonomy="sort_by" onchange="this.form.submit()">
                                <?php foreach ($available_terms as $slug => $name) : ?>
                                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($selected_sort, $slug); ?> data-term="<?php echo esc_attr($slug); ?>">
                                        <?php echo esc_html($name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="wcpf-action-buttons">
                    <?php if ($general_settings['apply_filter_behavior'] === 'apply_button') : ?>
                        <button class="wcpf-reset-filters" role="button" tabindex="0">
                            <?php echo esc_html($settings['custom_texts']['reset_button']); ?>
                        </button>
                        <button class="wcpf-apply-filters" data-mode="apply" data-locked="true" tabindex="0">
                            <?php echo esc_html($settings['custom_texts']['apply_button']); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Widget backend form.
     *
     * @param array $instance Widget instance settings.
     */
    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        $layout = !empty($instance['layout']) ? $instance['layout'] : 'horizontal';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php _e('Title:', 'wc-product-filter'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('layout')); ?>"><?php _e('Layout:', 'wc-product-filter'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('layout')); ?>" name="<?php echo esc_attr($this->get_field_name('layout')); ?>">
                <option value="horizontal" <?php selected($layout, 'horizontal'); ?>><?php _e('Horizontal', 'wc-product-filter'); ?></option>
                <option value="vertical" <?php selected($layout, 'vertical'); ?>><?php _e('Vertical', 'wc-product-filter'); ?></option>
            </select>
        </p>
        <p>
            <small><?php _e('The filter displayed is set in WooCommerce Product Filter General Settings.', 'wc-product-filter'); ?></small>
        </p>
        <?php
    }

    /**
     * Update widget instance settings.
     *
     * @param array $new_instance New settings.
     * @param array $old_instance Old settings.
     * @return array Updated settings.
     */
    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = !empty($new_instance['title']) ? sanitize_text_field($new_instance['title']) : '';
        $instance['layout'] = !empty($new_instance['layout']) && in_array($new_instance['layout'], ['horizontal', 'vertical']) ? $new_instance['layout'] : 'horizontal';
        return $instance;
    }
}
?>