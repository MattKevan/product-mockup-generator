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
        
        // Product save and update hooks
        add_action('woocommerce_process_product_meta', [$this, 'process_product'], 10, 1);
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
        $image_id = get_post_thumbnail_id($post->ID);
        $enabled = get_post_meta($post->ID, '_auto_frame_enabled', true);
        
        // Get global settings
        $defaults = get_option('mockup_generator_defaults', []);
        $is_globally_enabled = !empty($defaults['enabled_globally']);
        
        if ($image_id) {
            $orientation = $this->image_processor->get_image_orientation($image_id);
            
            // Simple query for variants
            $variants = get_posts([
                'post_type' => Mockup_Generator_Template::POST_TYPE,
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC'
            ]);
    
            // Get saved options
            $selected_variants = get_post_meta($post->ID, '_selected_variants', true) ?: array();
            $primary_variant = get_post_meta($post->ID, '_primary_variant', true);
            $framed_images = get_post_meta($post->ID, '_framed_images', true) ?: array();
            $frame_errors = get_post_meta($post->ID, '_frame_generation_errors', true);
        }
        
        wp_nonce_field('auto_frame_generator', 'auto_frame_nonce');
        include plugin_dir_path(__FILE__) . 'views/meta-box.php';
    }

    public function handle_product_save($product) {
        $product_id = $product->get_id();
        
        if (!isset($_POST['auto_frame_nonce']) || 
            !wp_verify_nonce($_POST['auto_frame_nonce'], 'auto_frame_generator')) {
            return;
        }

        $enabled = isset($_POST['auto_frame_enabled']) ? '1' : '0';
        update_post_meta($product_id, '_auto_frame_enabled', $enabled);

        if ($enabled === '1') {
            $selected_variants = isset($_POST['selected_variants']) ? array_map('absint', $_POST['selected_variants']) : array();
            $primary_variant = isset($_POST['primary_variant']) ? absint($_POST['primary_variant']) : '';

            update_post_meta($product_id, '_selected_variants', $selected_variants);
            update_post_meta($product_id, '_primary_variant', $primary_variant);

            $this->generate_mockups_for_product($product_id);
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
    public function process_product($product_id) {
        $this->log_debug("Processing product: " . $product_id);
        
        if (!isset($_POST['auto_frame_nonce']) || 
            !wp_verify_nonce($_POST['auto_frame_nonce'], 'auto_frame_generator')) {
            $this->log_debug("Nonce verification failed");
            return;
        }
    
        // Clear previous errors
        delete_post_meta($product_id, '_frame_generation_errors');
    
        // Always use explicit checkbox value
        $enabled = isset($_POST['auto_frame_enabled']) ? '1' : '0';
        $this->log_debug("Auto frame enabled value: " . $enabled);
        
        // Always store the explicit setting
        update_post_meta($product_id, '_auto_frame_enabled', $enabled);
    
        // If disabled, clean up old frames
        if ($enabled !== '1') {
            $this->log_debug("Mockup generation disabled, cleaning up old frames");
            $previous_frames = get_post_meta($product_id, '_framed_images', true) ?: array();
            $this->cleanup_frames($product_id, $previous_frames);
            delete_post_meta($product_id, '_framed_images');
            delete_post_meta($product_id, '_selected_variants');
            delete_post_meta($product_id, '_primary_variant');
            delete_post_meta($product_id, '_original_image_hash');
            return;
        }
    
        $selected_variants = isset($_POST['selected_variants']) ? array_map('absint', $_POST['selected_variants']) : [];
        $primary_variant = isset($_POST['primary_variant']) ? absint($_POST['primary_variant']) : '';
        
        // If no variants selected, use defaults from settings
        if (empty($selected_variants)) {
            $defaults = get_option('mockup_generator_defaults', []);
            if (!empty($defaults['default_category'])) {
                $variants = get_posts([
                    'post_type' => Mockup_Generator_Template::POST_TYPE,
                    'numberposts' => -1,
                    'tax_query' => [
                        [
                            'taxonomy' => Mockup_Generator_Template::TEMPLATE_CAT,
                            'field' => 'term_id',
                            'terms' => $defaults['default_category']
                        ]
                    ]
                ]);
                $selected_variants = array_map(function($variant) {
                    return $variant->ID;
                }, $variants);
                $primary_variant = !empty($selected_variants) ? reset($selected_variants) : '';
            }
        }
        
        $this->log_debug("Selected variants: " . print_r($selected_variants, true));
        $this->log_debug("Primary variant: " . $primary_variant);
        
        update_post_meta($product_id, '_selected_variants', $selected_variants);
        update_post_meta($product_id, '_primary_variant', $primary_variant);
    
        if (empty($selected_variants)) {
            $this->log_debug("No variants selected or available");
            update_post_meta($product_id, '_frame_generation_errors', ['No mockup variants selected or available']);
            return;
        }
    
        // Generate mockups
        $this->generate_mockups_for_product($product_id);
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
        $errors = array();
        
        $this->log_debug('Starting mockup generation for product: ' . $product_id);
        
        $selected_variants = get_post_meta($product_id, '_selected_variants', true) ?: array();
        $image_id = get_post_thumbnail_id($product_id);
        
        if (!$image_id) {
            $this->log_debug('No product image found for product: ' . $product_id);
            return false;
        }
    
        $image_path = get_attached_file($image_id);
        if (!$image_path || !file_exists($image_path)) {
            $this->log_debug('Product image file not found: ' . $image_path);
            return false;
        }
    
        if (empty($selected_variants)) {
            $this->log_debug('No variants selected for product: ' . $product_id);
            return false;
        }
    
        $this->log_debug('Selected variants: ' . print_r($selected_variants, true));
    
        // Get image orientation
        $orientation = $this->image_processor->get_image_orientation($image_id);
        $this->log_debug('Image orientation: ' . $orientation);
        
        // Filter variants by orientation
        $valid_variants = array_filter($selected_variants, function($variant_id) use ($orientation) {
            $terms = wp_get_object_terms($variant_id, 'mockup_orientation', ['fields' => 'slugs']);
            $this->log_debug('Variant ' . $variant_id . ' orientations: ' . print_r($terms, true));
            return empty($terms) || in_array($orientation, $terms);
        });
        
        if (empty($valid_variants)) {
            $error = 'No valid templates found for image orientation: ' . $orientation;
            $this->log_debug($error);
            update_post_meta($product_id, '_frame_generation_errors', [$error]);
            return false;
        }
    
        $image_hash = md5_file($image_path);
        
        // Clean up existing mockups
        $previous_mockups = get_post_meta($product_id, '_framed_images', true);
        if (!empty($previous_mockups)) {
            $this->log_debug('Cleaning up previous mockups: ' . print_r($previous_mockups, true));
            $this->cleanup_frames($product_id, $previous_mockups);
        }
    
        $existing_mockups = array();
        $success = false;
        
        foreach ($valid_variants as $variant_id) {
            $this->log_debug('Processing variant: ' . $variant_id);
            
            $template_image_id = get_post_meta($variant_id, '_template_image', true);
            if (!$template_image_id) {
                $error = sprintf('No template image set for variant: %s (ID: %d)', get_the_title($variant_id), $variant_id);
                $this->log_debug($error);
                $errors[] = $error;
                continue;
            }
    
            $template_path = get_attached_file($template_image_id);
            if (!$template_path || !file_exists($template_path)) {
                $error = sprintf('Template image file not found for variant: %s (ID: %d)', get_the_title($variant_id), $variant_id);
                $this->log_debug($error);
                $errors[] = $error;
                continue;
            }
    
            $settings = [
                'dimensions' => get_post_meta($variant_id, '_template_dimensions', true),
                'product_max_size' => get_post_meta($variant_id, '_product_max_size', true),
                'alignment' => get_post_meta($variant_id, '_product_alignment', true),
                'offset' => get_post_meta($variant_id, '_product_offset', true),
                'blend_mode' => get_post_meta($variant_id, '_blend_mode', true)
            ];
    
            $this->log_debug('Template settings: ' . print_r($settings, true));
    
            $mockup_image_id = $this->image_processor->generate_mockup(
                $product_id,
                $image_id,
                $template_path,
                $variant_id,
                $settings
            );
            
            if ($mockup_image_id) {
                $this->log_debug('Successfully generated mockup: ' . $mockup_image_id);
                $existing_mockups[$variant_id] = $mockup_image_id;
                $success = true;
            } else {
                $error = sprintf('Failed to generate mockup for variant: %s', get_the_title($variant_id));
                $this->log_debug($error);
                $errors[] = $error;
            }
        }
    
        if (!empty($existing_mockups)) {
            $this->log_debug('Saving generated mockups: ' . print_r($existing_mockups, true));
            update_post_meta($product_id, '_framed_images', $existing_mockups);
            update_post_meta($product_id, '_original_image_hash', $image_hash);
        }
    
        if (!empty($errors)) {
            $this->log_debug('Saving generation errors: ' . print_r($errors, true));
            update_post_meta($product_id, '_frame_generation_errors', $errors);
        }
    
        return $success;
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