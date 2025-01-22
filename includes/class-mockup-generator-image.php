<?php
if (!defined('ABSPATH')) {
    exit;
}

class Mockup_Generator_Image {
    public function get_image_orientation($image_id) {
        $image_meta = wp_get_attachment_metadata($image_id);
        if (!$image_meta) return 'unknown';
    
        $width = $image_meta['width'];
        $height = $image_meta['height'];
    
        // Calculate aspect ratio
        $ratio = $width / $height;
        
        // More precise ratio thresholds
        if ($ratio > 1.05) {
            return 'landscape';
        } elseif ($ratio < 0.95) {
            return 'portrait';
        } else {
            return 'square';
        }
    }

    public function generate_mockup($product_id, $image_id, $template_path, $variant_id, $settings) {
        error_log('Starting mockup generation with ImageMagick/GD');
        error_log('Settings: ' . print_r($settings, true));
    
        $image_path = get_attached_file($image_id);
        if (!$image_path || !file_exists($image_path)) {
            error_log('Product image not found: ' . $image_path);
            return false;
        }
    
        try {
            if (extension_loaded('imagick')) {
                error_log('Using ImageMagick');
                $mockup = $this->create_mockup_imagick(
                    $image_path,
                    $template_path,
                    $settings
                );
            } else {
                error_log('Using GD');
                $mockup = $this->create_mockup_gd(
                    $image_path,
                    $template_path,
                    $settings
                );
            }
    
            if (!$mockup) {
                error_log('Failed to create mockup');
                return false;
            }
    
            $upload_dir = wp_upload_dir();
            $filename = sprintf('mockup-%s-%s', $variant_id, basename($image_path));
            $filepath = $upload_dir['path'] . '/' . wp_unique_filename($upload_dir['path'], $filename);
    
            error_log('Saving mockup to: ' . $filepath);
            $result = $mockup->save($filepath);
            
            if (is_wp_error($result)) {
                error_log('Error saving mockup: ' . $result->get_error_message());
                return false;
            }
    
            $attachment = array(
                'guid' => $upload_dir['url'] . '/' . basename($filepath),
                'post_mime_type' => 'image/jpeg',
                'post_title' => sprintf('%s - %s', 
                    get_the_title($variant_id),
                    get_the_title($image_id)
                ),
                'post_content' => '',
                'post_status' => 'inherit'
            );
    
            $attach_id = wp_insert_attachment($attachment, $filepath, $product_id);
            
            if (is_wp_error($attach_id)) {
                error_log('Error creating attachment: ' . $attach_id->get_error_message());
                return false;
            }
    
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
            wp_update_attachment_metadata($attach_id, $attach_data);
            
            error_log('Successfully created mockup attachment: ' . $attach_id);
            return $attach_id;
    
        } catch (Exception $e) {
            error_log('Error generating mockup: ' . $e->getMessage());
            return false;
        }
    }

    private function create_mockup_imagick($image_path, $template_path, $settings) {
        try {
            $artwork = new Imagick($image_path);
            $template = new Imagick($template_path);
            
            // Resize artwork maintaining aspect ratio
            $artwork_size = $artwork->getImageGeometry();
            $scale = min(
                $settings['product_max_size'] / $artwork_size['width'],
                $settings['product_max_size'] / $artwork_size['height']
            );
            $new_width = round($artwork_size['width'] * $scale);
            $new_height = round($artwork_size['height'] * $scale);
            $artwork->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, 1);

            // Resize template to specified dimensions
            $template->resizeImage(
                $settings['dimensions']['width'], 
                $settings['dimensions']['height'], 
                Imagick::FILTER_LANCZOS, 
                1
            );

            // Calculate position based on alignment
            $x = $settings['offset']['x'];
            $y = $settings['offset']['y'];
            
            switch ($settings['alignment']) {
                case 'top-left':
                    break;
                case 'top-center':
                    $x += ($settings['dimensions']['width'] - $new_width) / 2;
                    break;
                case 'top-right':
                    $x += $settings['dimensions']['width'] - $new_width;
                    break;
                case 'center-left':
                    $y += ($settings['dimensions']['height'] - $new_height) / 2;
                    break;
                case 'center':
                    $x += ($settings['dimensions']['width'] - $new_width) / 2;
                    $y += ($settings['dimensions']['height'] - $new_height) / 2;
                    break;
                case 'center-right':
                    $x += $settings['dimensions']['width'] - $new_width;
                    $y += ($settings['dimensions']['height'] - $new_height) / 2;
                    break;
                case 'bottom-left':
                    $y += $settings['dimensions']['height'] - $new_height;
                    break;
                case 'bottom-center':
                    $x += ($settings['dimensions']['width'] - $new_width) / 2;
                    $y += $settings['dimensions']['height'] - $new_height;
                    break;
                case 'bottom-right':
                    $x += $settings['dimensions']['width'] - $new_width;
                    $y += $settings['dimensions']['height'] - $new_height;
                    break;
            }

            // Set blend mode
            if ($settings['blend_mode'] === 'multiply') {
                $artwork->setImageCompose(Imagick::COMPOSITE_MULTIPLY);
            }

            $artwork->setImageColorspace($template->getImageColorspace());
            $template->compositeImage($artwork, Imagick::COMPOSITE_OVER, $x, $y);

            $temp_file = tempnam(sys_get_temp_dir(), 'mockup_');
            $template->writeImage($temp_file);
            $editor = wp_get_image_editor($temp_file);
            unlink($temp_file);

            $artwork->destroy();
            $template->destroy();

            return $editor;

        } catch (Exception $e) {
            return null;
        }
    }

    private function create_mockup_gd($image_path, $template_path, $settings) {
        try {
            $artwork = imagecreatefromstring(file_get_contents($image_path));
            $template = imagecreatefromstring(file_get_contents($template_path));
            
            // Resize artwork maintaining aspect ratio
            $artwork_width = imagesx($artwork);
            $artwork_height = imagesy($artwork);
            $scale = min(
                $settings['product_max_size'] / $artwork_width,
                $settings['product_max_size'] / $artwork_height
            );
            $new_width = round($artwork_width * $scale);
            $new_height = round($artwork_height * $scale);
            
            $scaled_artwork = imagecreatetruecolor($new_width, $new_height);
            imagecopyresampled(
                $scaled_artwork, $artwork,
                0, 0, 0, 0,
                $new_width, $new_height,
                $artwork_width, $artwork_height
            );

            // Create and resize template
            $final = imagecreatetruecolor($settings['dimensions']['width'], $settings['dimensions']['height']);
            imagecopyresampled(
                $final, $template,
                0, 0, 0, 0,
                $settings['dimensions']['width'], $settings['dimensions']['height'],
                imagesx($template), imagesy($template)
            );

            // Calculate position based on alignment
            $x = $settings['offset']['x'];
            $y = $settings['offset']['y'];
            
            switch ($settings['alignment']) {
                case 'top-left':
                    break;
                case 'top-center':
                    $x += ($settings['dimensions']['width'] - $new_width) / 2;
                    break;
                case 'top-right':
                    $x += $settings['dimensions']['width'] - $new_width;
                    break;
                case 'center-left':
                    $y += ($settings['dimensions']['height'] - $new_height) / 2;
                    break;
                case 'center':
                    $x += ($settings['dimensions']['width'] - $new_width) / 2;
                    $y += ($settings['dimensions']['height'] - $new_height) / 2;
                    break;
                case 'center-right':
                    $x += $settings['dimensions']['width'] - $new_width;
                    $y += ($settings['dimensions']['height'] - $new_height) / 2;
                    break;
                case 'bottom-left':
                    $y += $settings['dimensions']['height'] - $new_height;
                    break;
                case 'bottom-center':
                    $x += ($settings['dimensions']['width'] - $new_width) / 2;
                    $y += $settings['dimensions']['height'] - $new_height;
                    break;
                case 'bottom-right':
                    $x += $settings['dimensions']['width'] - $new_width;
                    $y += $settings['dimensions']['height'] - $new_height;
                    break;
            }

            // Apply blend mode
            if ($settings['blend_mode'] === 'multiply') {
                imagecopymerge($final, $scaled_artwork, $x, $y, 0, 0, $new_width, $new_height, 100);
            } else {
                imagecopy($final, $scaled_artwork, $x, $y, 0, 0, $new_width, $new_height);
            }

            $temp_file = tempnam(sys_get_temp_dir(), 'mockup_');
            imagejpeg($final, $temp_file, 100);
            
            imagedestroy($artwork);
            imagedestroy($template);
            imagedestroy($scaled_artwork);
            imagedestroy($final);

            $editor = wp_get_image_editor($temp_file);
            unlink($temp_file);

            return $editor;

        } catch (Exception $e) {
            return null;
        }
    }
}