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
                        <th>Select</th>
                        <th>Variant</th>
                        <th>Primary</th>
                        <th>Orientation</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($variants as $variant): 
                        $terms = wp_get_object_terms($variant->ID, 'mockup_orientation', ['fields' => 'slugs']);
                        $is_selected = in_array($variant->ID, $selected_variants);
                    ?>
                        <tr>
                            <td>
                                <input type="checkbox" 
                                       name="selected_variants[]" 
                                       value="<?php echo esc_attr($variant->ID); ?>"
                                       <?php checked($is_selected); ?>>
                            </td>
                            <td><?php echo esc_html($variant->post_title); ?></td>
                            <td>
                                <input type="radio" 
                                       name="primary_variant" 
                                       value="<?php echo esc_attr($variant->ID); ?>"
                                       <?php checked($primary_variant == $variant->ID); ?>>
                            </td>
                            <td>
                                <?php echo !empty($terms) ? esc_html(ucfirst(implode(', ', $terms))) : 'Any'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
</style>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Show/hide variant selection based on enabled checkbox
    $('#auto_frame_enabled').on('change', function() {
        $('.variant-selection').toggle($(this).is(':checked'));
    }).trigger('change');

    // Handle variant selection and primary variant
    $('input[name="selected_variants[]"]').on('change', function() {
        var $this = $(this);
        var $row = $this.closest('tr');
        var $primaryRadio = $row.find('input[name="primary_variant"]');
        
        if (!$this.is(':checked')) {
            $primaryRadio.prop('checked', false);
        } else if ($('input[name="primary_variant"]:checked').length === 0) {
            $primaryRadio.prop('checked', true);
        }
    });

    // Ensure primary variant is also selected
    $('input[name="primary_variant"]').on('change', function() {
        if ($(this).is(':checked')) {
            $(this).closest('tr').find('input[name="selected_variants[]"]').prop('checked', true);
        }
    });
});
</script>