<?php
if (!defined('ABSPATH')) {
    exit;
}

class Mockup_Generator_Template {
    const POST_TYPE = 'mockup_variant';
    const TEMPLATE_CAT = 'mockup_template';

    public function __construct() {
        add_action('init', [$this, 'register_post_type_and_taxonomy']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_box_data']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_head', [$this, 'add_admin_styles']);
        add_action('restrict_manage_posts', [$this, 'add_template_category_filter']);
        add_action('pre_get_posts', [$this, 'handle_custom_sorting']);
        
        // Admin columns
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'set_variant_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_variant_columns'], 10, 2);
        add_filter('manage_edit-' . self::POST_TYPE . '_sortable_columns', [$this, 'set_sortable_columns']);
        add_filter('post_row_actions', [$this, 'modify_row_actions'], 10, 2);
    }

    public function enqueue_admin_scripts($hook) {
        global $post_type;
        
        if ($post_type !== self::POST_TYPE) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script(
            'mockup-generator-admin',
            plugins_url('/assets/js/admin.js', dirname(__FILE__)),
            ['jquery'],
            '1.0.0',
            true
        );
    }

    public function register_post_type_and_taxonomy() {
        // Register Template Category
        register_taxonomy(
            self::TEMPLATE_CAT,
            self::POST_TYPE,
            [
                'labels' => [
                    'name' => 'Template Categories',
                    'singular_name' => 'Template Category',
                    'menu_name' => 'Categories',
                    'all_items' => 'All Categories',
                    'edit_item' => 'Edit Category',
                    'view_item' => 'View Category',
                    'update_item' => 'Update Category',
                    'add_new_item' => 'Add New Category',
                    'new_item_name' => 'New Category Name',
                    'parent_item' => 'Parent Category',
                    'parent_item_colon' => 'Parent Category:',
                    'search_items' => 'Search Categories',
                ],
                'hierarchical' => true,
                'show_ui' => true,
                'show_admin_column' => true,
                'query_var' => true,
                'rewrite' => ['slug' => 'template'],
            ]
        );

        // Register Variant Post Type
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => 'Mockups',
                'singular_name' => 'Mockup',
                'add_new' => 'Add new mockup',
                'add_new_item' => 'Add new mockup',
                'edit_item' => 'Edit mockup',
                'new_item' => 'New mockup',
                'view_item' => 'View mockup',
                'search_items' => 'Search mockups',
                'not_found' => 'No mockups found',
                'not_found_in_trash' => 'No mockups found in trash'
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-layout',
            'supports' => ['title'],
            'show_in_admin_bar' => false,
            'menu_position' => 58,
            'taxonomies' => [self::TEMPLATE_CAT, 'mockup_orientation'],
            
        ]);

        // Add some default categories if they don't exist
        if (!term_exists('Wood Frame', self::TEMPLATE_CAT)) {
            wp_insert_term('Wood Frame', self::TEMPLATE_CAT);
        }
        if (!term_exists('Room Scene', self::TEMPLATE_CAT)) {
            wp_insert_term('Room Scene', self::TEMPLATE_CAT);
        }


        register_taxonomy(
            'mockup_orientation',
            self::POST_TYPE,
            [
                'labels' => [
                    'name' => 'Orientations',
                    'singular_name' => 'Orientation',
                    'menu_name' => 'Orientations',
                    'all_items' => 'All Orientations',
                    'edit_item' => 'Edit Orientation',
                    'view_item' => 'View Orientation',
                    'update_item' => 'Update Orientation',
                    'add_new_item' => 'Add New Orientation',
                    'new_item_name' => 'New Orientation Name',
                    'search_items' => 'Search Orientations',
                ],
                'hierarchical' => false,
                'show_ui' => true,
                'show_admin_column' => true,
                'query_var' => true,
                'rewrite' => ['slug' => 'orientation'],
            ]
        );

        // Add settings page to Mockups menu
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            'Mockup Generator Settings',
            'Settings',
            'manage_options',
            'mockup-generator-settings',
            [$this, 'render_settings_page']
        );

        

        // Add default orientations
        $orientations = ['Portrait', 'Landscape', 'Square'];
        foreach ($orientations as $orientation) {
            if (!term_exists($orientation, 'mockup_orientation')) {
                wp_insert_term($orientation, 'mockup_orientation');
            }
        }
    }

    public function render_settings_page() {
        // Include the settings class to handle the page
        $settings = new Mockup_Generator_Settings($this);
        $settings->render_settings_page();
    }

    public function add_meta_boxes() {
        add_meta_box(
            'mockup_variant_settings',
            'Variant Settings',
            [$this, 'render_variant_settings'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function render_variant_settings($post) {
        wp_nonce_field('mockup_variant_meta', 'mockup_variant_nonce');
        
        if ($post->post_type === 'product') {
            // Get all variant posts
            $variants = get_posts([
                'post_type' => self::POST_TYPE,
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC'
            ]);

            // Get orientation of post featured image
            $orientation = '';
            $image_id = get_post_thumbnail_id($post->ID);
            if ($image_id) {
                $image_meta = wp_get_attachment_metadata($image_id);
                if (!empty($image_meta)) {
                    $width = $image_meta['width'];
                    $height = $image_meta['height'];
                    $ratio = $width / $height;
                    if ($ratio > 1.05) {
                        $orientation = 'landscape';
                    } elseif ($ratio < 0.95) {
                        $orientation = 'portrait';
                    } else {
                        $orientation = 'square';
                    }
                }
            }

            // Get currently selected variants and primary variant
            $selected_variants = get_post_meta($post->ID, '_selected_variants', true) ?: array();
            $primary_variant = get_post_meta($post->ID, '_primary_variant', true);
            $framed_images = get_post_meta($post->ID, '_framed_images', true) ?: array();

            // Get any stored errors
            $frame_errors = get_post_meta($post->ID, '_frame_generation_errors', true);

            include plugin_dir_path(__FILE__) . 'views/meta-box.php';
        } else {
            // Original variant settings form
            $template_image_id = get_post_meta($post->ID, '_template_image', true);
            $dimensions = get_post_meta($post->ID, '_template_dimensions', true) ?: ['width' => '', 'height' => ''];
            $product_max_size = get_post_meta($post->ID, '_product_max_size', true) ?: '';
            $alignment = get_post_meta($post->ID, '_product_alignment', true) ?: 'center';
            $offset = get_post_meta($post->ID, '_product_offset', true) ?: ['x' => 0, 'y' => 0];
            $blend_mode = get_post_meta($post->ID, '_blend_mode', true) ?: 'normal';
            ?>
<table class="form-table">
    <tr>
        <th scope="row">Template Image</th>
        <td>
            <div class="template-image-upload">
                <div class="preview-image">
                    <?php if ($template_image_id): ?>
                        <?php echo wp_get_attachment_image($template_image_id, 'medium'); ?>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="template_image_id" id="template_image_id" 
                       value="<?php echo esc_attr($template_image_id); ?>">
                <button type="button" class="upload-image-button button">
                    <?php echo $template_image_id ? 'Change Image' : 'Upload Image'; ?>
                </button>
                <?php if ($template_image_id): ?>
                    <button type="button" class="remove-image-button button">Remove Image</button>
                <?php endif; ?>
                <p class="description">Upload the base template image (e.g., empty frame or room scene)</p>
            </div>
        </td>
    </tr>
    <tr>
        <th scope="row">Template Dimensions</th>
        <td>
            <input type="number" name="template_width" value="<?php echo esc_attr($dimensions['width']); ?>" placeholder="Width" min="1">
            x
            <input type="number" name="template_height" value="<?php echo esc_attr($dimensions['height']); ?>" placeholder="Height" min="1">
            px
            <p class="description">The dimensions of the template image</p>
        </td>
    </tr>
    <tr>
        <th scope="row">Product Max Size</th>
        <td>
            <input type="number" name="product_max_size" value="<?php echo esc_attr($product_max_size); ?>" min="1">
            px
            <p class="description">Maximum size of the product image to be overlaid</p>
        </td>
    </tr>
    <tr>
        <th scope="row">Product Alignment</th>
        <td>
            <select name="product_alignment">
                <option value="top-left" <?php selected($alignment, 'top-left'); ?>>Top Left</option>
                <option value="top-center" <?php selected($alignment, 'top-center'); ?>>Top Center</option>
                <option value="top-right" <?php selected($alignment, 'top-right'); ?>>Top Right</option>
                <option value="center-left" <?php selected($alignment, 'center-left'); ?>>Center Left</option>
                <option value="center" <?php selected($alignment, 'center'); ?>>Center</option>
                <option value="center-right" <?php selected($alignment, 'center-right'); ?>>Center Right</option>
                <option value="bottom-left" <?php selected($alignment, 'bottom-left'); ?>>Bottom Left</option>
                <option value="bottom-center" <?php selected($alignment, 'bottom-center'); ?>>Bottom Center</option>
                <option value="bottom-right" <?php selected($alignment, 'bottom-right'); ?>>Bottom Right</option>
            </select>
            <p class="description">Where to position the product image on the template</p>
        </td>
    </tr>
    <tr>
        <th scope="row">Offset</th>
        <td>
            X: <input type="number" name="offset_x" value="<?php echo esc_attr($offset['x']); ?>">
            Y: <input type="number" name="offset_y" value="<?php echo esc_attr($offset['y']); ?>">
            px
            <p class="description">Fine-tune the product image position (optional)</p>
        </td>
    </tr>
    <tr>
        <th scope="row">Blend Mode</th>
        <td>
            <select name="blend_mode">
                <option value="normal" <?php selected($blend_mode, 'normal'); ?>>Normal</option>
                <option value="multiply" <?php selected($blend_mode, 'multiply'); ?>>Multiply</option>
            </select>
            <p class="description">How the product image should blend with the template</p>
        </td>
    </tr>
    <tr>
        <th scope="row">Orientation</th>
        <td>
            <?php
            $orientations = get_terms([
                'taxonomy' => 'mockup_orientation',
                'hide_empty' => false,
            ]);
            
            if (!empty($orientations) && !is_wp_error($orientations)) {
                $current_orientation = wp_get_object_terms($post->ID, 'mockup_orientation', ['fields' => 'ids']);
                foreach ($orientations as $orientation) {
                    printf(
                        '<label style="margin-right: 15px;"><input type="radio" name="mockup_orientation" value="%s" %s> %s</label>',
                        esc_attr($orientation->term_id),
                        checked(!empty($current_orientation) && in_array($orientation->term_id, $current_orientation), true, false),
                        esc_html($orientation->name)
                    );
                }
            }
            ?>
            <p class="description">Select the orientation of this template (optional)</p>
        </td>
    </tr>
</table>

<div id="template-preview" class="template-preview-container">
    <!-- Preview will be loaded here via JavaScript -->
</div>
            <?php
        }
    }

    public function save_meta_box_data($post_id) {
        if (!isset($_POST['mockup_variant_nonce']) || 
            !wp_verify_nonce($_POST['mockup_variant_nonce'], 'mockup_variant_meta')) {
            return;
        }

        if (isset($_POST['template_image_id'])) {
            update_post_meta($post_id, '_template_image', absint($_POST['template_image_id']));
        }

        update_post_meta($post_id, '_template_dimensions', [
            'width' => absint($_POST['template_width']),
            'height' => absint($_POST['template_height'])
        ]);

        if (isset($_POST['product_max_size'])) {
            update_post_meta($post_id, '_product_max_size', absint($_POST['product_max_size']));
        }

        if (isset($_POST['product_alignment'])) {
            update_post_meta($post_id, '_product_alignment', sanitize_text_field($_POST['product_alignment']));
        }

        update_post_meta($post_id, '_product_offset', [
            'x' => intval($_POST['offset_x']),
            'y' => intval($_POST['offset_y'])
        ]);

        if (isset($_POST['blend_mode'])) {
            update_post_meta($post_id, '_blend_mode', sanitize_text_field($_POST['blend_mode']));
        }
        if (isset($_POST['mockup_orientation'])) {
            $orientation_id = absint($_POST['mockup_orientation']);
            wp_set_object_terms($post_id, [$orientation_id], 'mockup_orientation');
        }
    }
    public function set_variant_columns($columns) {
        return [
            'cb' => '<input type="checkbox" />',
            'image' => '',  // Thumbnail column
            'title' => __('Variant Name'),
            'category' => __('Template Category'),
            'dimensions' => __('Dimensions'),
            'product_max' => __('Product Max Size'),
            'alignment' => __('Alignment'),
            'blend_mode' => __('Blend Mode'),
            'orientation' => __('Orientation'), 
            'date' => __('Date')
        ];
    }

    public function render_variant_columns($column, $post_id) {
        switch ($column) {
            case 'image':
                $template_image_id = get_post_meta($post_id, '_template_image', true);
                if ($template_image_id) {
                    echo wp_get_attachment_image($template_image_id, [50, 50], false, [
                        'style' => 'width: auto; height: 50px; object-fit: contain;'
                    ]);
                }
                break;

            case 'category':
                $terms = get_the_terms($post_id, self::TEMPLATE_CAT);
                if ($terms && !is_wp_error($terms)) {
                    $term_names = array_map(function($term) {
                        return sprintf(
                            '<a href="%s">%s</a>',
                            esc_url(add_query_arg(['post_type' => self::POST_TYPE, self::TEMPLATE_CAT => $term->slug], 'edit.php')),
                            esc_html($term->name)
                        );
                    }, $terms);
                    echo implode(', ', $term_names);
                }
                break;

            case 'dimensions':
                $dimensions = get_post_meta($post_id, '_template_dimensions', true);
                if ($dimensions && !empty($dimensions['width']) && !empty($dimensions['height'])) {
                    printf('%d × %d px', $dimensions['width'], $dimensions['height']);
                } else {
                    echo '—';
                }
                break;

            case 'product_max':
                $max_size = get_post_meta($post_id, '_product_max_size', true);
                echo $max_size ? esc_html($max_size . ' px') : '—';
                break;

            case 'alignment':
                $alignment = get_post_meta($post_id, '_product_alignment', true);
                if ($alignment) {
                    echo esc_html(str_replace('-', ' ', ucwords($alignment)));
                } else {
                    echo '—';
                }
                break;

            case 'blend_mode':
                $blend_mode = get_post_meta($post_id, '_blend_mode', true);
                echo $blend_mode ? esc_html(ucfirst($blend_mode)) : '—';
                break;
        }
    }

    public function set_sortable_columns($columns) {
        $columns['dimensions'] = 'dimensions';
        $columns['product_max'] = 'product_max';
        $columns['alignment'] = 'alignment';
        $columns['blend_mode'] = 'blend_mode';
        $columns['orientation'] = 'orientation';

        return $columns;
    }

    public function handle_custom_sorting($query) {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== self::POST_TYPE) {
            return;
        }

        $orderby = $query->get('orderby');

        switch ($orderby) {
            case 'dimensions':
                $query->set('meta_key', '_template_dimensions');
                $query->set('orderby', 'meta_value_num');
                break;
            case 'product_max':
                $query->set('meta_key', '_product_max_size');
                $query->set('orderby', 'meta_value_num');
                break;
            case 'alignment':
                $query->set('meta_key', '_product_alignment');
                $query->set('orderby', 'meta_value');
                break;
            case 'blend_mode':
                $query->set('meta_key', '_blend_mode');
                $query->set('orderby', 'meta_value');
                break;
        }
    }

    public function add_template_category_filter() {
        global $typenow;
        
        if ($typenow === self::POST_TYPE) {
            $current = isset($_GET[self::TEMPLATE_CAT]) ? $_GET[self::TEMPLATE_CAT] : '';
            $current_orientation = isset($_GET['mockup_orientation']) ? $_GET['mockup_orientation'] : '';

            wp_dropdown_categories([
                'show_option_all' => __('All Categories'),
                'taxonomy' => self::TEMPLATE_CAT,
                'name' => self::TEMPLATE_CAT,
                'orderby' => 'name',
                'selected' => $current,
                'hierarchical' => true,
                'depth' => 3,
                'show_count' => true,
                'hide_empty' => true,
            ]);

            wp_dropdown_categories([
                'show_option_all' => __('All Orientations'),
                'taxonomy' => 'mockup_orientation',
                'name' => 'mockup_orientation',
                'orderby' => 'name',
                'selected' => $current_orientation,
                'hierarchical' => false,
                'show_count' => true,
                'hide_empty' => true,
            ]);
        }
    }

    public function modify_row_actions($actions, $post) {
        if ($post->post_type === self::POST_TYPE) {
            // Remove Quick Edit
            unset($actions['inline hide-if-no-js']);
            
            // Add preview link if template image exists
            $template_image_id = get_post_meta($post->ID, '_template_image', true);
            if ($template_image_id) {
                $preview_url = wp_get_attachment_image_url($template_image_id, 'full');
                $actions['preview'] = sprintf(
                    '<a href="%s" target="_blank">%s</a>',
                    esc_url($preview_url),
                    __('View Template')
                );
            }
        }
        return $actions;
    }

    public function add_admin_styles() {
        global $current_screen;
        
        if ($current_screen->post_type === self::POST_TYPE) {
            ?>
            <style>
                .column-image {
                    width: 60px;
                }
                .column-image img {
                    border: 1px solid #ddd;
                    background: #f7f7f7;
                    padding: 2px;
                }
                .column-dimensions,
                .column-product_max,
                .column-alignment,
                .column-blend_mode {
                    width: 120px;
                }
                .column-category {
                    width: 15%;
                }
                .template-image-upload .preview-image {
                    margin: 10px 0;
                    padding: 10px;
                    background: #f9f9f9;
                    border: 1px solid #ddd;
                    display: inline-block;
                }
                .template-image-upload .button {
                    margin-right: 10px;
                }
                .template-image-upload img {
                    max-width: 200px;
                    height: auto;
                    display: block;
                }
                .column-orientation {
                    width: 100px;
                }
            </style>
            <?php
        }
    }
}