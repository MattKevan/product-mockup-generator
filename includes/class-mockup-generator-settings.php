<?php
if (!defined('ABSPATH')) {
    exit;
}

class Mockup_Generator_Settings {
    private $generator;

    public function __construct($generator) {
        $this->generator = $generator;
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_bulk_actions']);
    }
    public function register_settings() {
        register_setting('mockup_generator_settings', 'mockup_generator_defaults');
    }
    

    // Add sanitization callback
public function sanitize_defaults($input) {
    $defaults = get_option('mockup_generator_defaults', []);
    
    $output = [
        'enabled_globally' => isset($input['enabled_globally']) ? '1' : '0',
        'enabled_by_default' => isset($input['enabled_by_default']) ? '1' : '0',
        'enabled_categories' => isset($input['enabled_categories']) ? array_map('absint', $input['enabled_categories']) : [],
        'default_category' => isset($input['default_category']) ? absint($input['default_category']) : '',
        'batch_size' => isset($input['batch_size']) ? absint($input['batch_size']) : 10,
        'processing_delay' => isset($input['processing_delay']) ? absint($input['processing_delay']) : 2
    ];

    return $output;
}
    
public function render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $defaults = get_option('mockup_generator_defaults', [
        'enabled_globally' => '0',
        'enabled_by_default' => '0',
        'enabled_categories' => [],
        'default_category' => '',
        'batch_size' => '10',
        'processing_delay' => '2'
    ]);

    // Get all available categories
    $categories = get_terms([
        'taxonomy' => Mockup_Generator_Template::TEMPLATE_CAT,
        'hide_empty' => false,
        'orderby' => 'name',
        'order' => 'ASC'
    ]);

    include plugin_dir_path(__FILE__) . 'views/settings-page.php';
}
    
    // Add this method to handle the global enable/disable setting
    public function maybe_enable_global_mockups() {
        $defaults = get_option('mockup_generator_defaults', []);
        if (!empty($defaults['enabled_globally'])) {
            add_filter('get_post_metadata', function($value, $post_id, $meta_key, $single) {
                if ($meta_key === '_auto_frame_enabled' && get_post_type($post_id) === 'product') {
                    return '1';
                }
                return $value;
            }, 10, 4);
        }
    }

    private function bulk_enable_mockups($default_variant) {
        $products = wc_get_products([
            'limit' => -1,
            'status' => 'publish',
            'return' => 'ids',
        ]);
    
        foreach ($products as $product_id) {
            update_post_meta($product_id, '_auto_frame_enabled', '1');
            if ($default_variant) {
                update_post_meta($product_id, '_selected_variants', [$default_variant]);
                update_post_meta($product_id, '_primary_variant', $default_variant);
            }
        }
    
        add_settings_error(
            'mockup_generator_bulk_action',
            'bulk_enable_complete',
            sprintf('Mockup generation enabled for %d products.', count($products)),
            'updated'
        );
    }

    private function bulk_disable_mockups() {
        $products = wc_get_products([
            'limit' => -1,
            'status' => 'publish',
            'return' => 'ids',
        ]);
    
        foreach ($products as $product_id) {
            $mockups = get_post_meta($product_id, '_framed_images', true) ?: array();
            if (!empty($mockups)) {
                foreach ($mockups as $mockup_id) {
                    wp_delete_attachment($mockup_id, true);
                }
            }
            
            delete_post_meta($product_id, '_auto_frame_enabled');
            delete_post_meta($product_id, '_framed_images');
            delete_post_meta($product_id, '_selected_variants');
            delete_post_meta($product_id, '_primary_variant');
            delete_post_meta($product_id, '_original_image_hash');
        }
    
        add_settings_error(
            'mockup_generator_bulk_action',
            'bulk_disable_complete',
            sprintf('Mockup generation disabled for %d products.', count($products)),
            'updated'
        );
    }

    private function render_processing_status() {
        $processing = get_option('mockup_generator_processing', false);
        if ($processing) {
            $progress = get_option('mockup_generator_progress', [
                'total' => 0,
                'processed' => 0,
                'successful' => 0,
                'failed' => 0
            ]);
            ?>
            <div class="notice notice-info">
                <h3>Processing Status</h3>
                <p>Mockup generation in progress...</p>
                <p>Progress: <?php echo $progress['processed']; ?> / <?php echo $progress['total']; ?> products</p>
                <p>Successful: <?php echo $progress['successful']; ?></p>
                <p>Failed: <?php echo $progress['failed']; ?></p>
            </div>
            <?php
        }
    }

    public function handle_bulk_actions() {
        if (isset($_POST['bulk_enable_mockups']) && check_admin_referer('mockup_generator_bulk_action')) {
            $defaults = get_option('mockup_generator_defaults', []);
            $this->bulk_enable_mockups($defaults['default_variant']);
        }
        
        if (isset($_POST['bulk_disable_mockups']) && check_admin_referer('mockup_generator_bulk_action')) {
            $this->bulk_disable_mockups();
        }

        if (isset($_POST['regenerate_mockups']) && check_admin_referer('mockup_generator_regenerate')) {
            $this->initiate_bulk_regeneration();
        }
    }

    private function initiate_bulk_regeneration() {
        $products = wc_get_products([
            'limit' => -1,
            'status' => 'publish',
            'return' => 'ids',
            'meta_key' => '_auto_frame_enabled',
            'meta_value' => '1'
        ]);

        if (empty($products)) {
            add_settings_error(
                'mockup_generator_regenerate',
                'no_products',
                'No products found with mockup generation enabled.',
                'error'
            );
            return;
        }

        update_option('mockup_generator_processing', true);
        update_option('mockup_generator_progress', [
            'total' => count($products),
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'remaining_products' => $products
        ]);

        wp_schedule_single_event(time(), 'process_mockup_generation_batch');

        add_settings_error(
            'mockup_generator_regenerate',
            'regeneration_started',
            'Mockup regeneration has been initiated.',
            'updated'
        );
    }
}