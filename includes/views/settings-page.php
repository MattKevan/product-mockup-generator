<div class="wrap">
    <h1>Mockup Generator Settings</h1>
    
    <form action="options.php" method="post">
        <?php settings_fields('mockup_generator_settings'); ?>
        <table class="form-table">
            <tr>
                <th scope="row">Global Settings</th>
                <td>
                    <label>
                        <input type="checkbox" 
                            name="mockup_generator_defaults[enabled_globally]" 
                            value="1" 
                            <?php checked($defaults['enabled_globally'], '1'); ?>>
                        Enable mockup generation for all products by default
                    </label>
                    <p class="description">Individual products can still override this setting.</p>
                </td>
            </tr>
        </table>

        <h2 class="title">Template Categories</h2>
        <p class="description">Select which template categories are enabled by default and choose the primary category for product images.</p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="check-column">
                        <input type="checkbox" id="categories-select-all">
                    </th>
                    <th scope="col">Category</th>
                    <th scope="col">Primary Image</th>
                    <th scope="col">Available Variants</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $category): 
                    // Get variants in this category
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
                    ?>
                    <tr>
                        <th scope="row" class="check-column">
                            <input type="checkbox" 
                                name="mockup_generator_defaults[enabled_categories][]" 
                                value="<?php echo esc_attr($category->term_id); ?>"
                                <?php checked(in_array($category->term_id, $defaults['enabled_categories'])); ?>>
                        </th>
                        <td>
                            <strong><?php echo esc_html($category->name); ?></strong>
                            <div class="row-actions">
                                <?php echo esc_html($category->description); ?>
                            </div>
                        </td>
                        <td>
                            <input type="radio" 
                                name="mockup_generator_defaults[default_category]" 
                                value="<?php echo esc_attr($category->term_id); ?>"
                                <?php checked($defaults['default_category'], $category->term_id); ?>>
                        </td>
                        <td>
                            <?php 
                            if (!empty($variants)) {
                                echo esc_html(implode(', ', wp_list_pluck($variants, 'post_title')));
                            } else {
                                echo '<em>No variants available</em>';
                            }
                            ?>
                        </td>
                    </tr>                
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <h2 class="title">Batch Processing</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Processing Settings</th>
                <td>
                    <label>
                        Products per batch:
                        <input type="number" 
                               name="mockup_generator_defaults[batch_size]" 
                               value="<?php echo esc_attr($defaults['batch_size']); ?>"
                               min="1" 
                               max="50">
                    </label>
                    <br>
                    <label>
                        Delay between batches (seconds):
                        <input type="number" 
                               name="mockup_generator_defaults[processing_delay]" 
                               value="<?php echo esc_attr($defaults['processing_delay']); ?>"
                               min="1" 
                               max="10">
                    </label>
                    <br><br>
                    <input type="submit" 
                           name="regenerate_mockups" 
                           class="button button-primary" 
                           value="Regenerate All Product Mockups"
                           onclick="return confirm('Are you sure you want to regenerate mockups for all products? This may take some time.');">
                </td>
            </tr>
        </table>
        
        <?php submit_button('Save Settings'); ?>
    </form>

    <?php $this->render_processing_status(); ?>
</div>

<style>
.variant-type-section {
    margin-bottom: 20px;
    padding: 10px;
    border: 1px solid #ddd;
    background: #f9f9f9;
}
.variant-category-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
</style>

<script type="text/javascript">
jQuery(document).ready(function($) {
    $('#categories-select-all').on('change', function() {
        $('input[name="mockup_generator_defaults[enabled_categories][]"]').prop('checked', $(this).prop('checked'));
    });
});
</script>