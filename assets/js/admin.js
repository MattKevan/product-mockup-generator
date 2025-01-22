(function($) {
    'use strict';

    // Mockup Generator Admin
    class MockupGeneratorAdmin {
        constructor() {
            this.variantSelection = $('.variant-selection');
            this.enableCheckbox = $('#auto_frame_enabled');
            this.previewContainer = $('.mockup-preview');
            this.previewError = $('.mockup-preview-error');
            this.previewInProgress = false;

            this.initEventListeners();
            this.initInitialState();
        }

        initEventListeners() {
            // Enable/disable mockup generation
            this.enableCheckbox.on('change', () => {
                this.variantSelection.toggle(this.enableCheckbox.is(':checked'));
            });

            // Variant selection changes
            $('input[name="selected_variants[]"]').on('change', (e) => {
                const checkbox = $(e.target);
                const variantId = checkbox.val();
                const radioBtn = $(`input[name="primary_variant"][value="${variantId}"]`);
                
                if (!checkbox.is(':checked')) {
                    radioBtn.prop('checked', false);
                } else if ($('input[name="primary_variant"]:checked').length === 0) {
                    radioBtn.prop('checked', true);
                }
            });

            // Primary variant selection
            $('input[name="primary_variant"]').on('change', (e) => {
                const radio = $(e.target);
                const variantId = radio.val();
                const checkbox = $(`input[name="selected_variants[]"][value="${variantId}"]`);
                
                if (radio.is(':checked')) {
                    checkbox.prop('checked', true);
                }
            });
        }

        initInitialState() {
            this.variantSelection.toggle(this.enableCheckbox.is(':checked'));
        }
    }

    // Template Variant Admin
    class TemplateVariantAdmin {
        constructor() {
            this.initMediaUploader();
            this.initRemoveImage();
            this.initDimensionsAutoFill();
        }

        initMediaUploader() {
            $('.upload-image-button').on('click', (e) => {
                e.preventDefault();
                
                const button = $(e.currentTarget);
                const container = button.closest('.template-image-upload');
                const imagePreview = container.find('.preview-image');
                const imageIdInput = container.find('#template_image_id');
                const dimensionsFields = {
                    width: $('input[name="template_width"]'),
                    height: $('input[name="template_height"]')
                };
                
                const frame = wp.media({
                    title: 'Select Template Image',
                    multiple: false,
                    library: {
                        type: 'image'
                    }
                });

                frame.on('select', () => {
                    const attachment = frame.state().get('selection').first().toJSON();
                    
                    // Update image preview and ID
                    imageIdInput.val(attachment.id);
                    imagePreview.html(`<img src="${attachment.url}" style="max-width:200px;">`);
                    button.text('Change Image');
                    
                    // Auto-fill dimensions if empty
                    if (!dimensionsFields.width.val() && !dimensionsFields.height.val()) {
                        dimensionsFields.width.val(attachment.width);
                        dimensionsFields.height.val(attachment.height);
                    }
                    
                    // Add remove button
                    if (!container.find('.remove-image-button').length) {
                        container.append('<button type="button" class="remove-image-button button">Remove Image</button>');
                    }
                });

                frame.open();
            });
        }

        initRemoveImage() {
            $(document).on('click', '.remove-image-button', (e) => {
                e.preventDefault();
                const container = $(e.currentTarget).closest('.template-image-upload');
                container.find('.preview-image').empty();
                container.find('#template_image_id').val('');
                container.find('.upload-image-button').text('Upload Image');
                $(e.currentTarget).remove();
            });
        }

        initDimensionsAutoFill() {
            // Auto calculate dimensions based on orientation
            $('select[name="mockup_orientation"]').on('change', (e) => {
                const orientation = $(e.target).val();
                const width = $('input[name="template_width"]');
                const height = $('input[name="template_height"]');
                const maxSize = 1500; // Default max size

                if (!width.val() || !height.val()) {
                    switch(orientation) {
                        case 'portrait':
                            width.val(Math.round(maxSize * 0.7));
                            height.val(maxSize);
                            break;
                        case 'landscape':
                            width.val(maxSize);
                            height.val(Math.round(maxSize * 0.7));
                            break;
                        case 'square':
                            width.val(maxSize);
                            height.val(maxSize);
                            break;
                    }
                }
            });
        }
    }

    // Initialize on document ready
    $(document).ready(function() {
        // Initialize based on current page
        if ($('#mockup_generator_box').length) {
            new MockupGeneratorAdmin();
        }
        
        if ($('#mockup_variant_settings').length) {
            new TemplateVariantAdmin();
        }
    });

})(jQuery);