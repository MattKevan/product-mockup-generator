<?php
if (!defined('ABSPATH')) {
    exit;
}

class Mockup_Generator_Batch_Processor {
    private $generator;

    public function __construct($generator) {
        $this->generator = $generator;
        add_action('process_mockup_generation_batch', [$this, 'process_batch']);
    }

    public function process_batch() {
        $progress = get_option('mockup_generator_progress');
        $settings = get_option('mockup_generator_defaults');
        $batch_size = isset($settings['batch_size']) ? intval($settings['batch_size']) : 10;

        if (empty($progress['remaining_products'])) {
            update_option('mockup_generator_processing', false);
            return;
        }

        // Get the next batch
        $batch = array_splice($progress['remaining_products'], 0, $batch_size);

        foreach ($batch as $product_id) {
            $result = $this->process_product($product_id);
            $progress['processed']++;
            
            if ($result) {
                $progress['successful']++;
            } else {
                $progress['failed']++;
            }
        }

        // Update progress
        update_option('mockup_generator_progress', $progress);

        // Schedule next batch if there are remaining products
        if (!empty($progress['remaining_products'])) {
            $delay = isset($settings['processing_delay']) ? intval($settings['processing_delay']) : 2;
            wp_schedule_single_event(time() + $delay, 'process_mockup_generation_batch');
        } else {
            update_option('mockup_generator_processing', false);
        }
    }

    private function process_product($product_id) {
        $enabled = get_post_meta($product_id, '_auto_frame_enabled', true);
        if ($enabled !== '1') {
            return false;
        }

        // Clean up existing mockups
        $existing_mockups = get_post_meta($product_id, '_framed_images', true) ?: array();
        if (!empty($existing_mockups)) {
            foreach ($existing_mockups as $mockup_id) {
                wp_delete_attachment($mockup_id, true);
            }
        }

        // Reset hash to force regeneration
        delete_post_meta($product_id, '_original_image_hash');

        $selected_variants = get_post_meta($product_id, '_selected_variants', true) ?: array();
        $image_id = get_post_thumbnail_id($product_id);

        if (!$image_id || empty($selected_variants)) {
            return false;
        }

        // Get product image orientation
        $orientation = $this->generator->get_image_processor()->get_image_orientation($image_id);

        // Filter variants by orientation
        $valid_variants = array_filter($selected_variants, function($variant_id) use ($orientation) {
            $terms = wp_get_object_terms($variant_id, 'mockup_orientation', ['fields' => 'slugs']);
            return empty($terms) || in_array($orientation, $terms);
        });

        if (empty($valid_variants)) {
            // No valid variants for this orientation
            return false;
        }

        return $this->generator->generate_mockups_for_product($product_id);
    }

    /**
     * Schedule a batch process
     */
    public function schedule_batch_process($product_ids) {
        update_option('mockup_generator_processing', true);
        update_option('mockup_generator_progress', [
            'total' => count($product_ids),
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'remaining_products' => $product_ids
        ]);

        wp_schedule_single_event(time(), 'process_mockup_generation_batch');
    }

    /**
     * Check if batch processing is currently running
     */
    public function is_processing() {
        return get_option('mockup_generator_processing', false);
    }

    /**
     * Get current progress
     */
    public function get_progress() {
        return get_option('mockup_generator_progress', [
            'total' => 0,
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'remaining_products' => []
        ]);
    }
}