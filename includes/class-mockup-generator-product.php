<?php
if (!defined('ABSPATH')) {
    exit;
}

class Mockup_Generator_Product {
    private $main;

    public function __construct($main) {
        $this->main = $main;

        // Filter the gallery images early
        add_filter('woocommerce_product_get_gallery_image_ids', [$this, 'filter_gallery_images'], 5, 2);
        
        // Then modify the remaining images
        add_filter('woocommerce_single_product_image_thumbnail_html', [$this, 'modify_gallery_image'], 99, 2);
        add_filter('woocommerce_product_get_image', [$this, 'modify_product_image'], 99, 2);
        add_filter('post_thumbnail_html', [$this, 'modify_thumbnail_html'], 99, 1);
        
        // Gallery specific modifications
        add_filter('woocommerce_gallery_image_html_attachment_image_params', [$this, 'modify_gallery_image_params'], 99, 4);
        add_filter('woocommerce_gallery_full_size', [$this, 'modify_gallery_full_size'], 99, 1);
    }

    public function filter_gallery_images($ids, $product) {
        if (is_admin()) {
            return $ids;
        }
    
        $product_id = $product->get_id();
        $enabled = get_post_meta($product_id, '_auto_frame_enabled', true);
        if ($enabled !== '1') {
            return $ids;
        }
    
        $framed_images = get_post_meta($product_id, '_framed_images', true);
        if (empty($framed_images)) {
            return $ids;
        }
    
        $primary_variant = get_post_meta($product_id, '_primary_variant', true);
        if (!$primary_variant || !isset($framed_images[$primary_variant])) {
            return $ids;
        }
    
        $original_image_id = get_post_thumbnail_id($product_id);
        if (!$original_image_id) {
            return $ids;
        }
        
        $framed_id = $framed_images[$primary_variant];
        
        // If this is the only product image, return empty array
        if (empty($ids)) {
            return array();
        }
        
        // For additional gallery images, replace original with framed version
        return array_map(function($id) use ($original_image_id, $framed_id) {
            return $id === $original_image_id ? $framed_id : $id;
        }, $ids);
    }

    public function modify_product_image($html, $product) {
        if (is_admin()) {
            return $html;
        }
    
        $product_id = is_object($product) ? $product->get_id() : $product;
        $enabled = get_post_meta($product_id, '_auto_frame_enabled', true);
        if ($enabled !== '1') {
            return $html;
        }
    
        $primary_variant = get_post_meta($product_id, '_primary_variant', true);
        if (!$primary_variant) {
            return $html;
        }
    
        $framed_images = get_post_meta($product_id, '_framed_images', true);
        if (!isset($framed_images[$primary_variant])) {
            return $html;
        }

        $framed_image_id = $framed_images[$primary_variant];
        $framed_metadata = wp_get_attachment_metadata($framed_image_id);
        
        if ($framed_metadata) {
            return wp_get_attachment_image($framed_image_id, 'full', false, array(
                'class' => 'wp-post-image',
                'width' => $framed_metadata['width'],
                'height' => $framed_metadata['height'],
                'data-large_image' => wp_get_attachment_image_url($framed_image_id, 'full'),
                'data-large_image-width' => $framed_metadata['width'],
                'data-large_image-height' => $framed_metadata['height']
            ));
        }
    
        return $html;
    }

    public function modify_thumbnail_html($html) {
        if (is_admin() || !function_exists('wc_get_product')) {
            return $html;
        }
    
        global $post;
        if (!$post || get_post_type($post) !== 'product') {
            return $html;
        }
    
        $product_id = $post->ID;
        return $this->modify_product_image($html, $product_id);
    }

    public function modify_gallery_image($html, $attachment_id) {
        if (is_admin()) {
            return $html;
        }
    
        global $product;
        if (!$product) {
            return $html;
        }
    
        $product_id = $product->get_id();
        $enabled = get_post_meta($product_id, '_auto_frame_enabled', true);
        if ($enabled !== '1') {
            return $html;
        }
    
        $primary_variant = get_post_meta($product_id, '_primary_variant', true);
        if (!$primary_variant) {
            return $html;
        }
    
        $framed_images = get_post_meta($product_id, '_framed_images', true);
        if (!isset($framed_images[$primary_variant])) {
            return $html;
        }

        $framed_image_id = $framed_images[$primary_variant];
        $framed_metadata = wp_get_attachment_metadata($framed_image_id);
        $full_src = wp_get_attachment_image_src($framed_image_id, 'full');
        
        if ($framed_metadata && $full_src) {
            $dom = new DOMDocument();
            @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            
            $imgs = $dom->getElementsByTagName('img');
            if ($imgs->length > 0) {
                $img = $imgs->item(0);
                
                // Set all dimension-related attributes
                $img->setAttribute('src', $full_src[0]);
                $img->setAttribute('width', (string)$framed_metadata['width']);
                $img->setAttribute('height', (string)$framed_metadata['height']);
                $img->setAttribute('data-large_image', $full_src[0]);
                $img->setAttribute('data-large_image-width', (string)$framed_metadata['width']);
                $img->setAttribute('data-large_image-height', (string)$framed_metadata['height']);
                
                // Remove responsive image attributes
                $img->removeAttribute('srcset');
                $img->removeAttribute('sizes');
                
                // Update the wrapper elements
                $figures = $dom->getElementsByTagName('figure');
                if ($figures->length > 0) {
                    $figure = $figures->item(0);
                    $figure->setAttribute('data-thumb', $full_src[0]);
                    $figure->setAttribute('data-width', (string)$framed_metadata['width']);
                    $figure->setAttribute('data-height', (string)$framed_metadata['height']);
                }

                // Update div wrapper if it exists
                $divs = $dom->getElementsByTagName('div');
                foreach ($divs as $div) {
                    if ($div->hasAttribute('data-thumb')) {
                        $div->setAttribute('data-thumb', $full_src[0]);
                        $div->setAttribute('data-width', (string)$framed_metadata['width']);
                        $div->setAttribute('data-height', (string)$framed_metadata['height']);
                    }
                }

                // Add woocommerce-product-gallery__image class if not present
                $parent = $img->parentNode;
                if ($parent && $parent->nodeName === 'div') {
                    $classes = $parent->getAttribute('class');
                    if (strpos($classes, 'woocommerce-product-gallery__image') === false) {
                        $parent->setAttribute('class', $classes . ' woocommerce-product-gallery__image');
                    }
                }

                return $dom->saveHTML();
            }
        }
    
        return $html;
    }

    public function modify_gallery_image_params($params, $attachment_id, $image_size, $main_image) {
        global $product;
        if (!$product || is_admin()) {
            return $params;
        }
    
        $product_id = $product->get_id();
        $enabled = get_post_meta($product_id, '_auto_frame_enabled', true);
        if ($enabled !== '1') {
            return $params;
        }
    
        $primary_variant = get_post_meta($product_id, '_primary_variant', true);
        if (!$primary_variant) {
            return $params;
        }
    
        $framed_images = get_post_meta($product_id, '_framed_images', true);
        if (!isset($framed_images[$primary_variant])) {
            return $params;
        }

        $framed_image_id = $framed_images[$primary_variant];
        $framed_metadata = wp_get_attachment_metadata($framed_image_id);
        $full_src = wp_get_attachment_image_src($framed_image_id, 'full');

        if ($framed_metadata && $full_src) {
            $params = array_merge($params, array(
                'src' => $full_src[0],
                'width' => (string)$framed_metadata['width'],
                'height' => (string)$framed_metadata['height'],
                'data-large_image' => $full_src[0],
                'data-large_image-width' => (string)$framed_metadata['width'],
                'data-large_image-height' => (string)$framed_metadata['height'],
                'data-src' => $full_src[0],
                'class' => (isset($params['class']) ? $params['class'] : '') . ' wp-post-image',
                'data-caption' => '',
                'data-thumb' => $full_src[0],
                'alt' => isset($params['alt']) ? $params['alt'] : '',
            ));

            // Remove responsive image attributes
            unset($params['srcset']);
            unset($params['sizes']);
        }
    
        return $params;
    }

    public function modify_gallery_full_size($size) {
        return 'full';
    }

    public function add_mockup_switching_data() {
        if (is_product()) {
            global $product;
            $enabled = get_post_meta($product->get_id(), '_auto_frame_enabled', true);
            
            if ($enabled === '1') {
                $mockup_images = get_post_meta($product->get_id(), '_framed_images', true);
                $original_image_id = get_post_thumbnail_id($product->get_id());
                
                if ($mockup_images && $original_image_id) {
                    $mockup_data = [
                        'original' => wp_get_attachment_image_url($original_image_id, 'full'),
                        'variants' => []
                    ];
                    
                    foreach ($mockup_images as $variant_id => $image_id) {
                        $variant = get_post($variant_id);
                        if ($variant) {
                            $mockup_data['variants'][$variant_id] = [
                                'title' => $variant->post_title,
                                'image' => wp_get_attachment_image_url($image_id, 'full')
                            ];
                        }
                    }
                    
                    wp_localize_script('wc-single-product', 'mockupData', $mockup_data);
                }
            }
        }
    }
}