<div class="mockup-generator-container">
    <?php if (!$image_id): ?>
        <div class="notice notice-warning">
            <p>Please set a featured image first.</p>
        </div>
    <?php else: ?>
        <p>
            <label>
                <input type="checkbox" 
                       id="auto_frame_enabled"
                       name="auto_frame_enabled" 
                       value="1" 
                       <?php checked($enabled === '1' || ($enabled === '' && $is_globally_enabled)); ?>>
                Enable mockup generation
            </label>
            <?php if ($is_globally_enabled && $enabled === ''): ?>
                <span class="description">(Globally enabled by default)</span>
            <?php endif; ?>
        </p>

        <div class="frame-info">
            <p>Image Orientation: <?php echo esc_html(ucfirst($orientation)); ?></p>
            <?php 
            $metadata = wp_get_attachment_metadata($image_id);
            if ($metadata): ?>
                <p>Image Dimensions: <?php echo esc_html($metadata['width'] . 'x' . $metadata['height'] . 'px'); ?></p>
            <?php endif; ?>
        </div>

        <?php if ($frame_errors): ?>
            <div class="notice notice-error">
                <p>Mockup Generation Errors:</p>
                <ul>
                    <?php foreach ($frame_errors as $error): ?>
                        <li><?php echo esc_html($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="variant-selection" style="margin-top: 10px;">
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Enable</th>
                        <th>Template Category</th>
                        <th>Primary Image</th>
                        <th>Available Variants</th>
                        <?php if (!empty($framed_images)): ?>
                            <th>Current Mockup</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($categories as $category):
                        // Skip if no matching variants for this category
                        if (empty($category_variants[$category->term_id])) continue;
                        
                        // Get matching variants for display
                        $matching_variants = $category_variants[$category->term_id];
                        $variant_names = wp_list_pluck($matching_variants, 'post_title');
                        
                        // Get the first matching variant ID (will be used automatically)
                        $auto_variant_id = $matching_variants[0]->ID;
                    ?>
                        <tr>
                        <td>
                            <input type="checkbox" 
                                name="enabled_categories[]" 
                                value="<?php echo esc_attr($category->term_id); ?>"
                                <?php checked(in_array($category->term_id, $enabled_categories)); ?>>
                        </td>
                            <td>
                                <?php echo esc_html($category->name); ?>
                                <?php if ($is_globally_enabled && in_array($category->term_id, $default_categories)): ?>
                                    <span class="description">(Enabled by default)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="radio" 
                                    name="primary_category" 
                                    value="<?php echo esc_attr($category->term_id); ?>"
                                    <?php checked($category->term_id == $primary_category); ?>>
                            </td>

                            <td>
                                <?php 
                                echo esc_html(implode(', ', $variant_names));
                                if (count($matching_variants) == 1) {
                                    echo ' <span class="description">(Auto-selected)</span>';
                                }
                                ?>
                            </td>
                            <?php if (!empty($framed_images)): ?>
                                <td>
                                    <?php 
                                    if (isset($framed_images[$auto_variant_id])) {
                                        echo wp_get_attachment_image($framed_images[$auto_variant_id], [50, 50]);
                                    }
                                    ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (empty($category_variants)): ?>
            <div class="notice notice-warning">
                <p>No template variants available for this image orientation (<?php echo esc_html($orientation); ?>).</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.mockup-generator-container {
    padding: 10px;
}
.frame-info {
    margin: 10px 0;
    padding: 10px;
    background: #fff;
    border: 1px solid #eee;
}
.variant-selection table {
    margin-top: 10px;
}
.variant-selection th,
.variant-selection td {
    padding: 8px;
}
.variant-selection img {
    max-width: 50px;
    height: auto;
}
.description {
    color: #666;
    font-style: italic;
    font-size: 0.9em;
    margin-left: 5px;
}
</style>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Show/hide variant selection based on enabled checkbox
    $('#auto_frame_enabled').on('change', function() {
        $('.variant-selection').toggle($(this).is(':checked'));
    }).trigger('change');

    // Handle category selection and primary category
    $('input[name="enabled_categories[]"]').on('change', function() {
        var $this = $(this);
        var $row = $this.closest('tr');
        var $primaryRadio = $row.find('input[name="primary_category"]');
        
        if (!$this.is(':checked')) {
            $primaryRadio.prop('checked', false);
        } else {
            // If this is the only checked category, make it primary
            var $checkedCategories = $('input[name="enabled_categories[]"]:checked');
            var $selectedPrimary = $('input[name="primary_category"]:checked');
            
            if ($checkedCategories.length === 1 || !$selectedPrimary.length) {
                $primaryRadio.prop('checked', true);
            }
        }
    });

    // Ensure primary category is also enabled
    $('input[name="primary_category"]').on('change', function() {
        if ($(this).is(':checked')) {
            $(this).closest('tr').find('input[name="enabled_categories[]"]').prop('checked', true);
        }
    });

    // Set default selections if nothing is selected
    function setDefaultSelections() {
        var $checkedCategories = $('input[name="enabled_categories[]"]:checked');
        var $selectedPrimary = $('input[name="primary_category"]:checked');
        
        if ($checkedCategories.length === 0) {
            // Check categories marked as default
            $('input[data-default="true"]').each(function() {
                var $row = $(this).closest('tr');
                $row.find('input[name="enabled_categories[]"]').prop('checked', true);
                $(this).prop('checked', true);
            });
        }
    }

    setDefaultSelections();
});
</script>