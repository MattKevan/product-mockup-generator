<?php
if (!defined('ABSPATH')) {
    exit;
}

class Mockup_Generator {
    private $settings;
    private $batch_processor;
    private $template_manager;
    private $image_processor;
    private $product_manager;

    public function __construct() {
        // Initialize components
        $this->settings = new Mockup_Generator_Settings($this);
        $this->settings->maybe_enable_global_mockups();
    
        $this->batch_processor = new Mockup_Generator_Batch_Processor($this);
        $this->template_manager = new Mockup_Generator_Template();
        $this->image_processor = new Mockup_Generator_Image();
        $this->product_manager = new Mockup_Generator_Product($this);
    
        // Add metabox to product
        add_action('admin_menu', [$this, 'add_menu']);
        
        // Product save hooks - Update these lines
        // Remove this line:
        // add_action('woocommerce_process_product_meta', [$this, 'process_product'], 10, 1);
        // Keep this line:
        add_action('woocommerce_admin_process_product_object', [$this, 'handle_product_save']);
        
        // Image change hooks
        add_action('deleted_post_meta', [$this, 'handle_image_change'], 10, 4);
        add_action('updated_post_meta', [$this, 'handle_image_change'], 10, 4);
        add_action('added_post_meta', [$this, 'handle_image_change'], 10, 4);
        add_action('delete_attachment', [$this, 'handle_image_deletion'], 10, 1);
        
        // Add admin scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function enqueue_admin_scripts($hook) {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === 'product') {
            wp_enqueue_media();
            wp_enqueue_script(
                'mockup-generator-admin',
                plugins_url('/assets/js/admin.js', dirname(__FILE__)),
                ['jquery'],
                '1.0.0',
                true
            );
        }
    }

    public function add_menu() {
        add_meta_box(
            'mockup_generator_box',
            'Mockup Generator',
            [$this, 'render_meta_box'],
            'product',
            'normal',
            'high'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('auto_frame_generator', 'auto_frame_nonce');
        
        $image_id = get_post_thumbnail_id($post->ID);
        $enabled = get_post_meta($post->ID, '_auto_frame_enabled', true);
        
        // Get global settings
        $defaults = get_option('mockup_generator_defaults', []);
        $is_globally_enabled = !empty($defaults['enabled_globally']);
        $default_categories = isset($defaults['enabled_categories']) ? $defaults['enabled_categories'] : [];
        $default_primary = isset($defaults['default_category']) ? $defaults['default_category'] : '';
        
        if ($image_id) {
            $orientation = $this->image_processor->get_image_orientation($image_id);
            
            // Get categories
            $categories = get_terms([
                'taxonomy' => Mockup_Generator_Template::TEMPLATE_CAT,
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC'
            ]);
    
            // Get saved options or use defaults
            $enabled_categories = get_post_meta($post->ID, '_enabled_categories', true);
            $primary_category = get_post_meta($post->ID, '_primary_category', true);
            $framed_images = get_post_meta($post->ID, '_framed_images', true) ?: array();
            $frame_errors = get_post_meta($post->ID, '_frame_generation_errors', true);
    
            // Use defaults if no settings saved or if globally enabled
            if (empty($enabled_categories) || ($is_globally_enabled && $enabled === '')) {
                $enabled_categories = $default_categories;
                $primary_category = $default_primary;
            }
    
            // Debug log for variant fetching
            $this->log_debug("Fetching variants for orientation: " . $orientation);
    
            // Get available variants for each category
            $category_variants = [];
            foreach ($categories as $category) {
                $this->log_debug("Processing category: " . $category->term_id . " - " . $category->name);
                
                // Get all variants for this category
                $variants = get_posts([
                    'post_type' => Mockup_Generator_Template::POST_TYPE,
                    'numberposts' => -1,
                    'tax_query' => [
                        [
                            'taxonomy' => Mockup_Generator_Template::TEMPLATE_CAT,
                            'field' => 'term_id',
                            'terms' => $category->term_id
                        ]
                    ]
                ]);
    
                $this->log_debug("Found " . count($variants) . " total variants for category " . $category->term_id);
    
                // Filter variants by orientation
                $matching_variants = [];
                foreach ($variants as $variant) {
                    $terms = wp_get_object_terms($variant->ID, 'mockup_orientation', ['fields' => 'slugs']);
                    $this->log_debug("Variant " . $variant->ID . " orientations: " . print_r($terms, true));
                    
                    // Include variant if it has no orientation set or matches current orientation
                    if (empty($terms) || in_array(strtolower($orientation), $terms)) {
                        $matching_variants[] = $variant;
                        $this->log_debug("Added variant " . $variant->ID . " to matching variants");
                    }
                }
    
                if (!empty($matching_variants)) {
                    $category_variants[$category->term_id] = $matching_variants;
                    $this->log_debug("Category " . $category->term_id . " has " . count($matching_variants) . " matching variants");
                } else {
                    $this->log_debug("No matching variants found for category " . $category->term_id);
                }
            }
    
            // Pass all variables to template
            include plugin_dir_path(__FILE__) . 'views/meta-box.php';
        }
    }

    public function handle_product_save($product) {
        $product_id = $product->get_id();
        
        if (!isset($_POST['auto_frame_nonce']) || 
            !wp_verify_nonce($_POST['auto_frame_nonce'], 'auto_frame_generator')) {
            return;
        }
    
        $this->log_debug("Starting product save for product ID: " . $product_id);
    
        // Clear previous errors
        delete_post_meta($product_id, '_frame_generation_errors');
    
        $enabled = isset($_POST['auto_frame_enabled']) ? '1' : '0';
        $this->log_debug("Auto frame enabled value: " . $enabled);
        
        update_post_meta($product_id, '_auto_frame_enabled', $enabled);
    
        if ($enabled === '1') {
            // Get categories from POST or use defaults
            $enabled_categories = isset($_POST['enabled_categories']) ? array_map('absint', $_POST['enabled_categories']) : [];
            $primary_category = isset($_POST['primary_category']) ? absint($_POST['primary_category']) : '';
    
            $this->log_debug("Selected categories: " . print_r($enabled_categories, true));
            $this->log_debug("Primary category: " . $primary_category);
    
            // Use defaults if no categories selected
            if (empty($enabled_categories)) {
                $defaults = get_option('mockup_generator_defaults', []);
                $enabled_categories = isset($defaults['enabled_categories']) ? $defaults['enabled_categories'] : [];
                $primary_category = isset($defaults['default_category']) ? $defaults['default_category'] : '';
                
                $this->log_debug("Using default categories: " . print_r($enabled_categories, true));
                $this->log_debug("Using default primary category: " . $primary_category);
            }
    
            update_post_meta($product_id, '_enabled_categories', $enabled_categories);
            update_post_meta($product_id, '_primary_category', $primary_category);
    
            // Generate mockups
            $this->generate_mockups_for_categories($product_id, $enabled_categories, $primary_category);
        } else {
            $this->log_debug("Mockup generation disabled - cleaning up");
            // Clean up if disabled
            $this->cleanup_frames($product_id, get_post_meta($product_id, '_framed_images', true));
            delete_post_meta($product_id, '_framed_images');
            delete_post_meta($product_id, '_enabled_categories');
            delete_post_meta($product_id, '_primary_category');
            delete_post_meta($product_id, '_original_image_hash');
        }
    }

    public function handle_image_change($meta_id, $post_id, $meta_key, $meta_value) {
        if ($meta_key === '_thumbnail_id' && get_post_type($post_id) === 'product') {
            $enabled = get_post_meta($post_id, '_auto_frame_enabled', true);
            if ($enabled === '1') {
                // Only proceed if we have a valid image ID and file
                if (!empty($meta_value)) {
                    $file_path = get_attached_file($meta_value);
                    if ($file_path && file_exists($file_path)) {
                        $current_hash = md5_file($file_path);
                        $stored_hash = get_post_meta($post_id, '_original_image_hash', true);
                        
                        if ($current_hash !== $stored_hash) {
                            $this->generate_mockups_for_product($post_id);
                        }
                    }
                } else {
                    // Image was removed, clean up mockups
                    $this->cleanup_frames($post_id, get_post_meta($post_id, '_framed_images', true));
                    delete_post_meta($post_id, '_framed_images');
                    delete_post_meta($post_id, '_original_image_hash');
                }
            }
        }
    }

    public function handle_image_deletion($attachment_id) {
        global $wpdb;
        $products = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
            $attachment_id
        ));

        foreach ($products as $product_id) {
            if (get_post_type($product_id) === 'product') {
                $enabled = get_post_meta($product_id, '_auto_frame_enabled', true);
                if ($enabled === '1') {
                    $this->cleanup_frames($product_id, get_post_meta($product_id, '_framed_images', true));
                    delete_post_meta($product_id, '_framed_images');
                    delete_post_meta($product_id, '_original_image_hash');
                }
            }
        }
    }

    private function generate_mockups_for_categories($product_id, $categories, $primary_category) {
        try {
            $this->log_debug("Starting mockup generation for product: " . $product_id);
            $this->log_debug("Categories: " . print_r($categories, true));
            $this->log_debug("Primary category: " . $primary_category);
    
            $image_id = get_post_thumbnail_id($product_id);
            if (!$image_id) {
                throw new Exception("No featured image found for product");
            }
    
            $orientation = $this->image_processor->get_image_orientation($image_id);
            $this->log_debug("Image orientation: " . $orientation);
    
            $generated_mockups = [];
            $gallery_images = [];
            $category_variant_map = array();
            $errors = array();
    
            foreach ($categories as $category_id) {
                try {
                    $this->log_debug("Processing category: " . $category_id);
                    
                    $variant_id = $this->get_best_variant_for_category($category_id, $orientation);
                    if (!$variant_id) {
                        throw new Exception("No matching variant found for category");
                    }
                    
                    $this->log_debug("Selected variant: " . $variant_id);
                    
                    $mockup_id = $this->generate_single_mockup($product_id, $image_id, $variant_id);
                    if (!$mockup_id) {
                        throw new Exception("Failed to generate mockup");
                    }
                    
                    $this->log_debug("Generated mockup ID: " . $mockup_id);
                    
                    $generated_mockups[$variant_id] = $mockup_id;
                    $category_variant_map[$category_id] = $variant_id;
                    
                    if ($category_id !== $primary_category) {
                        $gallery_images[] = $mockup_id;
                    }
                } catch (Exception $e) {
                    $errors[] = "Category {$category_id}: " . $e->getMessage();
                }
            }
    
            if (!empty($errors)) {
                update_post_meta($product_id, '_frame_generation_errors', $errors);
            }
    
            if (!empty($generated_mockups)) {
                update_post_meta($product_id, '_framed_images', $generated_mockups);
                update_post_meta($product_id, '_category_variant_map', $category_variant_map);
                
                if (!empty($gallery_images)) {
                    update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_images));
                }
                return true;
            }
    
            return false;
    
        } catch (Exception $e) {
            $this->log_debug("Error in generate_mockups_for_categories: " . $e->getMessage());
            update_post_meta($product_id, '_frame_generation_errors', [$e->getMessage()]);
            return false;
        }
    }
    
    private function get_best_variant_for_category($category_id, $orientation) {
        $this->log_debug("Getting best variant for category {$category_id} with orientation {$orientation}");
        
        $variants = get_posts([
            'post_type' => Mockup_Generator_Template::POST_TYPE,
            'numberposts' => -1,
            'tax_query' => [
                [
                    'taxonomy' => Mockup_Generator_Template::TEMPLATE_CAT,
                    'field' => 'term_id',
                    'terms' => $category_id
                ]
            ]
        ]);
    
        $this->log_debug("Found " . count($variants) . " total variants");
    
        foreach ($variants as $variant) {
            $terms = wp_get_object_terms($variant->ID, 'mockup_orientation', ['fields' => 'slugs']);
            $this->log_debug("Checking variant {$variant->ID} with orientations: " . print_r($terms, true));
            
            // Include variant if it has no orientation set or matches current orientation
            if (empty($terms) || in_array(strtolower($orientation), $terms)) {
                $this->log_debug("Found matching variant: " . $variant->ID);
                return $variant->ID;
            }
        }
    
        $this->log_debug("No matching variant found for category {$category_id}");
        return false;
    }

    private function log_debug($message) {
        $log_file = plugin_dir_path(dirname(__FILE__)) . 'mockup-debug.log';
        $timestamp = date('[Y-m-d H:i:s]');
        
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }
        
        file_put_contents($log_file, $timestamp . ' ' . $message . "\n", FILE_APPEND);
    }
    
    public function generate_mockups_for_product($product_id) {
        $image_id = get_post_thumbnail_id($product_id);
        if (!$image_id) {
            return false;
        }
    
        $orientation = $this->image_processor->get_image_orientation($image_id);
        $enabled_categories = get_post_meta($product_id, '_enabled_categories', true) ?: array();
        $primary_category = get_post_meta($product_id, '_primary_category', true);
        
        $existing_mockups = array();
        $gallery_images = array();
        $success = false;
        $category_variant_map = array(); // Add this to track which variant belongs to which category
    
        foreach ($enabled_categories as $category_id) {
            // Get best matching variant for this category based on orientation
            $variant_id = $this->get_best_variant_for_category($category_id, $orientation);
            
            if (!$variant_id) {
                continue;
            }
    
            $mockup_id = $this->generate_single_mockup($product_id, $image_id, $variant_id);
            
            if ($mockup_id) {
                $existing_mockups[$variant_id] = $mockup_id;
                $category_variant_map[$category_id] = $variant_id; // Store the mapping
                
                // Add to gallery if not primary
                if ($category_id !== $primary_category) {
                    $gallery_images[] = $mockup_id;
                }
                
                $success = true;
            }
        }
    
        if (!empty($existing_mockups)) {
            update_post_meta($product_id, '_framed_images', $existing_mockups);
            update_post_meta($product_id, '_category_variant_map', $category_variant_map); // Save the mapping
            
            // Update product gallery
            if (!empty($gallery_images)) {
                update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_images));
            }
        }
    
        return $success;
    }
    private function generate_single_mockup($product_id, $image_id, $variant_id) {
        $template_image_id = get_post_meta($variant_id, '_template_image', true);
        if (!$template_image_id) {
            return false;
        }
    
        $template_path = get_attached_file($template_image_id);
        if (!$template_path || !file_exists($template_path)) {
            return false;
        }
    
        $settings = [
            'dimensions' => get_post_meta($variant_id, '_template_dimensions', true),
            'product_max_size' => get_post_meta($variant_id, '_product_max_size', true),
            'alignment' => get_post_meta($variant_id, '_product_alignment', true),
            'offset' => get_post_meta($variant_id, '_product_offset', true),
            'blend_mode' => get_post_meta($variant_id, '_blend_mode', true)
        ];
    
        return $this->image_processor->generate_mockup(
            $product_id,
            $image_id,
            $template_path,
            $variant_id,
            $settings
        );
    }
    private function cleanup_frames($product_id, $frames) {
        if (!empty($frames)) {
            // Check if it's the old format (nested arrays)
            if (is_array(reset($frames))) {
                foreach ($frames as $frame_type => $orientations) {
                    foreach ($orientations as $orientation => $attachment_id) {
                        if (is_numeric($attachment_id)) {
                            wp_delete_attachment($attachment_id, true);
                        }
                    }
                }
            } else {
                // New format (flat array of attachment IDs)
                foreach ($frames as $attachment_id) {
                    if (is_numeric($attachment_id)) {
                        wp_delete_attachment($attachment_id, true);
                    }
                }
            }
        }
    }

    // Getter methods for other components to access
    public function get_image_processor() {
        return $this->image_processor;
    }

    public function get_template_manager() {
        return $this->template_manager;
    }

    public function get_product_manager() {
        return $this->product_manager;
    }
}