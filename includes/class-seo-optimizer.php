<?php
/**
 * WCPF_SEO_Optimizer class handles SEO-related functionalities for the WooCommerce Product Filter plugin.
 *
 * This class manages SEO features such as Canonical URLs and can be extended for other SEO optimizations.
 *
 * @package WCPF
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class WCPF_SEO_Optimizer {
    /**
     * Track if canonical has been added to prevent duplicates.
     *
     * @var bool
     */
    private static $canonical_added = false;

    /**
     * Initialize the SEO optimizer.
     */
    public function init() {
        // Remove default canonicals from SEO plugins and WordPress core
        add_filter('wpseo_canonical', [$this, 'disable_seo_plugin_canonical'], 99999, 1); // Yoast SEO
        add_filter('rank_math/frontend/canonical', [$this, 'disable_seo_plugin_canonical'], 99999, 1); // Rank Math
        add_filter('seopress_titles_canonical', [$this, 'disable_seo_plugin_canonical'], 99999, 1); // SEOPress
        add_filter('aioseo_canonical_url', [$this, 'disable_seo_plugin_canonical'], 99999, 1); // All in One SEO
        add_filter('get_canonical_url', [$this, 'disable_core_canonical'], 99999, 2); // WordPress core

        // Remove default robots meta from SEO plugins
        add_filter('wpseo_robots', [$this, 'disable_seo_plugin_robots'], 99999, 1); // Yoast SEO
        add_filter('rank_math/frontend/robots', [$this, 'disable_seo_plugin_robots'], 99999, 1); // Rank Math
        add_filter('seopress_titles_robots', [$this, 'disable_seo_plugin_robots'], 99999, 1); // SEOPress
        add_filter('aioseo_robots', [$this, 'disable_seo_plugin_robots'], 99999, 1); // All in One SEO

        // Add custom canonical and meta robots
        add_action('wp_head', [$this, 'add_canonical_to_head'], 99999);
        add_action('wp_head', [$this, 'add_meta_robots_to_head'], 99999);

        // Start output buffering to remove any remaining hardcoded canonicals and robots
        add_action('wp_head', [$this, 'start_canonical_buffer'], 0);
        add_action('wp_head', [$this, 'end_canonical_buffer'], 100000);

        // Add custom SEO title
        add_filter('pre_get_document_title', [$this, 'override_seo_title'], 99999, 1);
    }

    /**
     * Disable canonical URLs from SEO plugins if not in disable mode.
     *
     * @param string|bool $canonical The canonical URL.
     * @return string|bool
     */
    public function disable_seo_plugin_canonical($canonical) {
        $general_settings = get_option('wcpf_general_settings', ['canonical_url' => 'default_wp']);
        $canonical_setting = $general_settings['canonical_url'] ?? 'default_wp';

        //error_log('WCPF Debug: disable_seo_plugin_canonical - setting=' . $canonical_setting . ', canonical=' . (string)$canonical);

        // If in disable mode, keep the original canonical
        if ($canonical_setting === 'default_wp') {
            return $canonical;
        }

        // Only disable on relevant WooCommerce pages
        if (is_shop() || is_product_category() || is_tax('product_brand')) {
            return false;
        }

        return $canonical;
    }

    /**
     * Disable canonical URL from WordPress core if not in disable mode.
     *
     * @param string|null $canonical_url The canonical URL.
     * @param WP_Post     $post         Post object.
     * @return string|null
     */
    public function disable_core_canonical($canonical_url, $post) {
        $general_settings = get_option('wcpf_general_settings', ['canonical_url' => 'default_wp']);
        $canonical_setting = $general_settings['canonical_url'] ?? 'default_wp';

        //error_log('WCPF Debug: disable_core_canonical - setting=' . $canonical_setting . ', canonical=' . (string)$canonical_url);

        // If in disable mode, keep the original canonical
        if ($canonical_setting === 'default_wp') {
            return $canonical_url;
        }

        // Only disable on relevant WooCommerce pages
        if (is_shop() || is_product_category() || is_tax('product_brand')) {
            return null;
        }

        return $canonical_url;
    }

    /**
     * Disable meta robots from SEO plugins if not in default mode and filters are present.
     *
     * @param mixed $robots The robots meta content (string, array, or other).
     * @return mixed Original robots value or empty array if disabled.
     */
    public function disable_seo_plugin_robots($robots) {
        $general_settings = get_option('wcpf_general_settings', ['meta_robots' => 'default_wp']);
        $meta_robots_setting = $general_settings['meta_robots'] ?? 'default_wp';
        $filters = get_query_var('filters', '');

        // Log robots value safely
        $robots_log = is_scalar($robots) ? (string)$robots : (is_array($robots) ? json_encode($robots) : gettype($robots));

        //error_log('WCPF Debug: disable_seo_plugin_robots - setting=' . $meta_robots_setting . ', robots=' . $robots_log . ', filters=' . $filters);

        // If in default mode or no filters, keep the original robots meta
        if ($meta_robots_setting === 'default_wp' || empty($filters)) {
            return $robots;
        }

        // Only disable on relevant WooCommerce pages with filters
        if (is_shop() || is_product_category() || is_tax('product_brand')) {
            return []; // Return empty array to safely disable Rank Math robots meta
        }

        return $robots;
    }

    /**
     * Start output buffering to capture wp_head output.
     */
    public function start_canonical_buffer() {
        $general_settings = get_option('wcpf_general_settings', ['canonical_url' => 'default_wp', 'meta_robots' => 'default_wp']);
        $canonical_setting = $general_settings['canonical_url'] ?? 'default_wp';
        $meta_robots_setting = $general_settings['meta_robots'] ?? 'default_wp';
        $filters = get_query_var('filters', '');

        // Start buffering if canonical is not in default mode, or meta robots is not in default mode and filters are present, and on relevant pages
        if (($canonical_setting !== 'default_wp' || ($meta_robots_setting !== 'default_wp' && !empty($filters))) && (is_shop() || is_product_category() || is_tax('product_brand'))) {
            ob_start();
            //error_log('WCPF Debug: start_canonical_buffer - Buffering started, filters=' . $filters);
        }
    }

    /**
     * End output buffering and remove hardcoded canonical and robots tags, preserving plugin's tags.
     */
    public function end_canonical_buffer() {
        $general_settings = get_option('wcpf_general_settings', ['canonical_url' => 'default_wp', 'meta_robots' => 'default_wp']);
        $canonical_setting = $general_settings['canonical_url'] ?? 'default_wp';
        $meta_robots_setting = $general_settings['meta_robots'] ?? 'default_wp';
        $filters = get_query_var('filters', '');

        // Only process buffer if canonical is not in default mode, or meta robots is not in default mode and filters are present, and on relevant pages
        if (($canonical_setting !== 'default_wp' || ($meta_robots_setting !== 'default_wp' && !empty($filters))) && (is_shop() || is_product_category() || is_tax('product_brand'))) {
            $buffer = ob_get_clean();
            $canonical_url = $this->generate_canonical_url();

            // Remove all canonical and robots tags
            $buffer = preg_replace('/<link\s+rel=["\']canonical["\'].*?\/>/i', '', $buffer);
            $buffer = preg_replace('/<meta\s+name=["\']robots["\'].*?\/>/i', '', $buffer);

            // Re-add plugin's canonical if it exists
            if ($canonical_url && $canonical_setting !== 'default_wp') {
                $buffer .= '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
            }

            // Re-add plugin's meta robots if applicable and filters are present
            if ($meta_robots_setting !== 'default_wp' && !empty($filters)) {
                $robots_content = $meta_robots_setting === 'index_follow' ? 'index, follow' : 'noindex, nofollow';
                $buffer .= '<meta name="robots" content="' . esc_attr($robots_content) . '" />' . "\n";
            }

            echo $buffer;
            //error_log('WCPF Debug: end_canonical_buffer - Buffer processed, canonical=' . (string)$canonical_url . ', robots=' . $meta_robots_setting . ', filters=' . $filters);
        }
    }

    /**
     * Generate canonical URL based on settings and filter state.
     *
     * @return string|null Canonical URL or null if disabled or invalid.
     */
    public function generate_canonical_url() {
        $general_settings = get_option('wcpf_general_settings', ['canonical_url' => 'default_wp']);
        $canonical_setting = $general_settings['canonical_url'] ?? 'default_wp';

        //error_log('WCPF Debug: generate_canonical_url - setting=' . $canonical_setting);

        // If in disable mode, return null (no canonical tag added)
        if ($canonical_setting === 'default_wp') {
            return null;
        }

        $queried_object = get_queried_object();
        $is_shop = is_shop();
        $is_category = is_product_category();
        $is_brand = is_tax('product_brand');

        //error_log('WCPF Debug: generate_canonical_url - is_shop=' . ($is_shop ? 'true' : 'false') . ', is_category=' . ($is_category ? 'true' : 'false') . ', is_brand=' . ($is_brand ? 'true' : 'false'));

        $shop_id = wc_get_page_id('shop');
        $shop_page_slug = get_page_uri($shop_id);
        $category_base = get_option('woocommerce_permalinks')['category_base'] ?: 'danh-muc-san-pham';
        $brand_base = get_option('woocommerce_brand_permalink') ?: 'brand';
        $brand_base = ltrim($brand_base, '/');
        $use_value_id_in_url = !empty($general_settings['use_value_id_in_url']);
        $category_slug = $queried_object && isset($queried_object->slug) ? ($use_value_id_in_url && isset($queried_object->term_id) ? $queried_object->term_id : $queried_object->slug) : '';

        $filters = get_query_var('filters', '');
        $has_filters = !empty($filters);

        $base_url = home_url();

        if ($is_shop) {
            $base_url = get_permalink($shop_id);
        } elseif ($is_category) {
            $term = $use_value_id_in_url ? get_term_by('id', (int) $category_slug, 'product_cat') : get_term_by('slug', $category_slug, 'product_cat');
            if ($term) {
                $base_url = get_term_link($term, 'product_cat');
            }
        } elseif ($is_brand) {
            $term = $use_value_id_in_url ? get_term_by('id', (int) $category_slug, 'product_brand') : get_term_by('slug', $category_slug, 'product_brand');
            if ($term) {
                $base_url = get_term_link($term, 'product_brand');
            }
        }

        if (!$base_url || is_wp_error($base_url)) {
            //error_log('WCPF Debug: generate_canonical_url failed to determine base_url');
            return null;
        }

        if ($canonical_setting === 'with_filter' && $has_filters) {
            $canonical_url = trailingslashit($base_url) . 'filters/' . $filters;
            $paged = get_query_var('paged') ? get_query_var('paged') : 1;
            if ($paged > 1) {
                $canonical_url .= '/page/' . $paged;
            }
        } else {
            $canonical_url = $base_url;
        }

        $canonical_url = esc_url_raw($canonical_url);

        //error_log('WCPF Debug: generate_canonical_url result=' . $canonical_url . ', setting=' . $canonical_setting . ', has_filters=' . ($has_filters ? 'true' : 'false'));

        return $canonical_url;
    }

    /**
     * Add canonical URL to head after removing default canonicals.
     */
    public function add_canonical_to_head() {
        if (!is_shop() && !is_product_category() && !is_tax('product_brand')) {
            //error_log('WCPF Debug: add_canonical_to_head - Not a relevant WooCommerce page');
            return;
        }

        $general_settings = get_option('wcpf_general_settings', ['canonical_url' => 'default_wp']);
        $canonical_setting = $general_settings['canonical_url'] ?? 'default_wp';

        //error_log('WCPF Debug: add_canonical_to_head - setting=' . $canonical_setting);

        // If in disable mode, do nothing (keep original canonical)
        if ($canonical_setting === 'default_wp') {
            //error_log('WCPF Debug: add_canonical_to_head - Canonical setting is disable, no action taken');
            return;
        }

        // Prevent adding canonical multiple times
        if (self::$canonical_added) {
            //error_log('WCPF Debug: add_canonical_to_head - Canonical already added, skipping');
            return;
        }

        $canonical_url = $this->generate_canonical_url();
        if ($canonical_url) {
            //error_log('WCPF Debug: add_canonical_to_head - Adding custom canonical: ' . $canonical_url);
            echo '<link rel="canonical" href="' . esc_url($canonical_url) . '" />' . "\n";
            self::$canonical_added = true;
        } else {
            //error_log('WCPF Debug: add_canonical_to_head - No canonical URL generated');
        }
    }

    /**
     * Add meta robots tag to head based on settings, only when filters are present.
     */
    public function add_meta_robots_to_head() {
        if (!is_shop() && !is_product_category() && !is_tax('product_brand')) {
            //error_log('WCPF Debug: add_meta_robots_to_head - Not a relevant WooCommerce page');
            return;
        }

        $general_settings = get_option('wcpf_general_settings', ['meta_robots' => 'default_wp']);
        $meta_robots_setting = $general_settings['meta_robots'] ?? 'default_wp';
        $filters = get_query_var('filters', '');

        //error_log('WCPF Debug: add_meta_robots_to_head - setting=' . $meta_robots_setting . ', filters=' . $filters);

        // If in default mode or no filters, do nothing (keep original robots meta)
        if ($meta_robots_setting === 'default_wp' || empty($filters)) {
            //error_log('WCPF Debug: add_meta_robots_to_head - Meta robots setting is default or no filters, no action taken');
            return;
        }

        // Output meta robots tag based on setting
        $robots_content = '';
        if ($meta_robots_setting === 'index_follow') {
            $robots_content = 'index, follow';
        } elseif ($meta_robots_setting === 'noindex_nofollow') {
            $robots_content = 'noindex, nofollow';
        }

        if ($robots_content) {
            //error_log('WCPF Debug: add_meta_robots_to_head - Adding meta robots: ' . $robots_content);
            echo '<meta name="robots" content="' . esc_attr($robots_content) . '" />' . "\n";
        } else {
            //error_log('WCPF Debug: add_meta_robots_to_head - No meta robots content generated');
        }
    }

    /**
     * Get filter attributes and their values from the filters query var.
     *
     * @return array Array of attribute-value pairs.
     */
    private function get_filter_attributes() {
        $filters = get_query_var('filters', '');
        if (empty($filters)) {
            return [];
        }

        $general_settings = get_option('wcpf_general_settings', ['use_value_id_in_url' => false]);
        $use_value_id_in_url = !empty($general_settings['use_value_id_in_url']);

        $excluded_attributes = ['stock_status', 'search', 'orderby', 'price-range'];

        $attributes = [];
        $filter_parts = explode('/', $filters); // Split by attribute groups (e.g., product_brand-217/cpu-160-161)

        foreach ($filter_parts as $part) {
            $pair = explode('-', $part, 2); // Split attribute and values
            if (count($pair) < 2) {
                continue; // Skip invalid parts
            }

            $attr_slug = $pair[0];
            $value_ids_or_slugs = explode('-', $pair[1]); // Handle multiple values (e.g., 160-161)

            // Bỏ qua thuộc tính không mong muốn
            if (in_array($attr_slug, $excluded_attributes)) {
                //error_log('WCPF Debug: get_filter_attributes - Skipping attribute: ' . $attr_slug);
                continue;
            }

            // Determine taxonomy and attribute name
            $taxonomy = $attr_slug === 'product_brand' ? 'product_brand' : 'pa_' . $attr_slug;
            $attr_name = $attr_slug === 'product_brand' ? 'Brand' : '';
            if (empty($attr_name)) {
                // Lấy nhãn thuộc tính từ WooCommerce
                $attr_name = wc_attribute_label('pa_' . $attr_slug);
                // Nếu nhãn rỗng, chuyển slug thành dạng title case
                if (empty($attr_name)) {
                    $attr_name = ucwords(str_replace('-', ' ', $attr_slug));
                }
            }

            // Get term names for values
            $value_names = [];
            foreach ($value_ids_or_slugs as $value) {
                $term = null;
                if ($use_value_id_in_url) {
                    $term = get_term_by('id', (int) $value, $taxonomy);
                } else {
                    $term = get_term_by('slug', $value, $taxonomy);
                }
                $value_name = $term ? $term->name : ucwords(str_replace('-', ' ', $value));
                if ($value_name) {
                    $value_names[] = $value_name;
                }
            }

            if (!empty($value_names)) {
                $attributes[] = [
                    'attribute' => $attr_name,
                    'values' => $value_names, // Store as array to handle multiple values
                ];
            }
        }

        //error_log('WCPF Debug: get_filter_attributes - use_value_id_in_url=' . ($use_value_id_in_url ? 'true' : 'false') . ', filters=' . $filters . ', attributes=' . print_r($attributes, true));
        return $attributes;
    }

    /**
     * Generate SEO title based on settings and filter state.
     *
     * @return string|null SEO title or null if default.
     */
    private function generate_seo_title() {
        $general_settings = get_option('wcpf_general_settings', ['seo_title_format' => 'default']);
        $title_format = $general_settings['seo_title_format'] ?? 'default';

        //error_log('WCPF Debug: generate_seo_title - title_format=' . $title_format);

        // If default or no filters, return null to keep original title
        $filters = get_query_var('filters', '');
        if ($title_format === 'default' || empty($filters)) {
            //error_log('WCPF Debug: generate_seo_title - No filters or default format, keeping default title');
            return null;
        }

        if (!is_shop() && !is_product_category() && !is_tax('product_brand')) {
            //error_log('WCPF Debug: generate_seo_title - Not a relevant WooCommerce page');
            return null;
        }

        // Get base title
        $base_title = '';
        $queried_object = get_queried_object();

        // Check if we're in a product category (product_cat) based on URL or queried object
        $is_category = is_product_category();
        $category_from_url = '';
        if (!$is_category && !empty($_SERVER['REQUEST_URI'])) {
            $url_parts = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
            $category_base = get_option('woocommerce_permalinks')['category_base'] ?: 'danh-muc-san-pham';
            $category_base = trim($category_base, '/');
            if ($url_parts[0] === $category_base && !empty($url_parts[1])) {
                $term = get_term_by('slug', $url_parts[1], 'product_cat');
                if ($term) {
                    $category_from_url = $term->name;
                    $is_category = true;
                }
            }
        }

        if ($is_category) {
            // Use category name if available
            $base_title = $category_from_url ?: ($queried_object && isset($queried_object->name) ? $queried_object->name : '');
        } elseif (is_tax('product_brand')) {
            // Use brand name for product_brand taxonomy
            $base_title = $queried_object && isset($queried_object->name) ? $queried_object->name : '';
        } else {
            // Fallback to shop page title
            $base_title = get_the_title(wc_get_page_id('shop'));
        }

        if (empty($base_title)) {
            //error_log('WCPF Debug: generate_seo_title - No base title found');
            return null;
        }

        //error_log('WCPF Debug: generate_seo_title - base_title=' . $base_title . ', is_category=' . ($is_category ? 'true' : 'false'));

        $attributes = $this->get_filter_attributes();
        $has_attributes = !empty($attributes);

        if (!$has_attributes) {
            //error_log('WCPF Debug: generate_seo_title - No attributes, using base title');
            return $base_title;
        }

        $title = '';
        switch ($title_format) {
            case 'title_with_attributes':
                // {title} with [attribute] [values] and [attribute] [values]
                $title = $base_title;
                if ($has_attributes) {
                    $attr_strings = [];
                    foreach ($attributes as $attr) {
                        $values = implode(', ', $attr['values']); // Join multiple values with comma
                        $attr_strings[] = $attr['attribute'] . ' ' . $values;
                    }
                    $title .= ' with ' . implode(' and ', $attr_strings);
                }
                break;

            case 'title_attributes_colon':
                // {title} [attribute]:[values];[attribute]:[values]
                $title = $base_title;
                if ($has_attributes) {
                    $attr_strings = [];
                    foreach ($attributes as $attr) {
                        $values = implode(', ', $attr['values']);
                        $attr_strings[] = $attr['attribute'] . ':' . $values;
                    }
                    $title .= ' ' . implode(';', $attr_strings);
                }
                break;

            case 'attribute_title_with_attributes':
                // [attribute 1 values] {title} with [attribute] [values] and [attribute] [values]
                if ($has_attributes) {
                    $title = implode(', ', $attributes[0]['values']) . ' ' . $base_title;
                    if (count($attributes) > 1) {
                        $attr_strings = [];
                        for ($i = 1; $i < count($attributes); $i++) {
                            $values = implode(', ', $attributes[$i]['values']);
                            $attr_strings[] = $attributes[$i]['attribute'] . ' ' . $values;
                        }
                        $title .= ' with ' . implode(' and ', $attr_strings);
                    }
                } else {
                    $title = $base_title;
                }
                break;

            case 'title_values_slash':
                // {title} - [values] / [values]
                $title = $base_title;
                if ($has_attributes) {
                    $all_values = [];
                    foreach ($attributes as $attr) {
                        $all_values = array_merge($all_values, $attr['values']);
                    }
                    $title .= ' - ' . implode(' / ', $all_values);
                }
                break;

            case 'attributes_colon_title':
                // [attribute]:[values];[attribute]:[values] - {title}
                if ($has_attributes) {
                    $attr_strings = [];
                    foreach ($attributes as $attr) {
                        $values = implode(', ', $attr['values']);
                        $attr_strings[] = $attr['attribute'] . ':' . $values;
                    }
                    $title = implode(';', $attr_strings) . ' - ' . $base_title;
                } else {
                    $title = $base_title;
                }
                break;

            default:
                $title = $base_title;
                break;
        }

        $title = wp_strip_all_tags($title);
        //error_log('WCPF Debug: generate_seo_title - result=' . $title);
        return $title;
    }

    /**
     * Override the document title with custom SEO title.
     *
     * @param string $title The original title.
     * @return string The modified title.
     */
    public function override_seo_title($title) {
        $custom_title = $this->generate_seo_title();
        if ($custom_title) {
            return $custom_title;
        }
        return $title;
    }

    /**
     * Placeholder for future SEO features.
     */
    public function add_additional_seo_features() {
        // Add future SEO functionalities here
    }
}
?>