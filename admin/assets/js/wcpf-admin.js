jQuery(document).ready(function($) {
    if (typeof $.fn.sortable === 'undefined') {
        console.error('WCPF Error: jQuery UI Sortable is not loaded. Please check if jQuery UI is included.');
        return;
    }

    console.log('WCPF Debug: wcpf-admin.js loaded successfully');

    $('.wcpf-category-select, .wcpf-brand-select').select2({
        placeholder: wcpf_admin_params.i18n.select2_placeholder,
        allowClear: true,
        width: '100%',
        minimumInputLength: 0
    });

    $('.wcpf-category-select').on('select2:select', function(e) {
        if (e.params.data.id === 'all') {
            $(this).find('option:not([value="all"])').prop('selected', true);
            $(this).trigger('change');
        }
    }).on('select2:unselect', function(e) {
        if (e.params.data.id === 'all') {
            $(this).find('option').prop('selected', false);
            $(this).trigger('change');
        }
    });

    $('.wcpf-brand-select').on('select2:select', function(e) {
        if (e.params.data.id === 'all') {
            $(this).find('option:not([value="all"])').prop('selected', true);
            $(this).trigger('change');
        }
    }).on('select2:unselect', function(e) {
        if (e.params.data.id === 'all') {
            $(this).find('option').prop('selected', false);
            $(this).trigger('change');
        }
    });

    var availableAttributes = {};
    $('.wcpf-available-attributes li, .wcpf-active-attributes li').each(function() {
        var key = $(this).data('key');
        var label = $(this).find('.wcpf-label-editable').text().trim() || $(this).text().trim();
        availableAttributes[key] = label;
    });
    console.log('WCPF Debug: Available attributes:', availableAttributes);

    $('.wcpf-active-attributes li').each(function() {
        var $li = $(this);
        var key = $li.data('key');
        var label = $li.find('.wcpf-label-editable').text().trim() || availableAttributes[key] || key;
        $li.append(`<input type="hidden" class="wcpf-label-input-hidden" name="wcpf_filter_settings[attribute_labels][${key}]" value="${label}">`);
        console.log('WCPF Debug: Initialized hidden input for key:', key, 'value:', label);
    });

    $('.wcpf-sortable').sortable({
        connectWith: '.wcpf-sortable',
        update: function(event, ui) {
            var $list = $(this);
            var items = $list.find('li').map(function() {
                return $(this).data('key');
            }).get();
            console.log('WCPF Debug: Updated attributes:', items);

            $list.find('input[name="wcpf_filter_settings[active_attributes][]"]').remove();
            $list.find('input[name^="wcpf_filter_settings[attribute_labels]"]').remove();

            if ($list.parent().hasClass('wcpf-active-attributes')) {
                items.forEach(function(item) {
                    var $li = $list.find(`li[data-key="${item}"]`);
                    var $editableSpan = $li.find('.wcpf-label-editable');
                    var label;
                    if ($editableSpan.length === 0) {
                        label = $li.text().trim() || availableAttributes[item] || item;
                        $li.empty();
                        $li.append(`<span class="wcpf-label-editable" data-key="${item}">${label}</span>`);
                        console.log('WCPF Debug: Created editable span for key:', item, 'label:', label);
                    } else {
                        label = $editableSpan.text().trim() || availableAttributes[item] || item;
                    }
                    $li.append(`<input type="hidden" name="wcpf_filter_settings[active_attributes][]" value="${item}">`);
                    $li.append(`<input type="hidden" class="wcpf-label-input-hidden" name="wcpf_filter_settings[attribute_labels][${item}]" value="${label}">`);
                    console.log('WCPF Debug: Added inputs for key:', item, 'label:', label);
                });
            }
        }
    }).disableSelection();

    $('.wcpf-sortable-terms').sortable({
        connectWith: '.wcpf-sortable-terms[data-taxonomy]',
        update: function(event, ui) {
            var $list = $(this);
            var taxonomy = $list.closest('.wcpf-attribute-terms').data('taxonomy');
            var isActiveList = $list.parent().hasClass('wcpf-active-terms');

            var items = $list.find('li').map(function() {
                return $(this).data('key');
            }).get();
            console.log('WCPF Debug: Updated terms for taxonomy:', taxonomy, 'items:', items, 'isActiveList:', isActiveList);

            $list.find(`input[name="wcpf_filter_settings[active_attribute_terms][${taxonomy}][]"]`).remove();

            if (isActiveList) {
                items.forEach(function(item) {
                    if (item === 'select-all') return;
                    var $li = $list.find(`li[data-key="${item}"]`);
                    if (!$li.find('input').length) {
                        $li.append(`<input type="hidden" name="wcpf_filter_settings[active_attribute_terms][${taxonomy}][]" value="${item}">`);
                    }
                });
            }

            var $availableList = $list.closest('.wcpf-terms-container').find('.wcpf-available-terms .wcpf-sortable-terms');
            var $activeList = $list.closest('.wcpf-terms-container').find('.wcpf-active-terms .wcpf-sortable-terms');
            $availableList.find('li').each(function() {
                var key = $(this).data('key');
                if ($activeList.find(`li[data-key="${key}"]:not([data-key="select-all"])`).length) {
                    $(this).remove();
                }
            });
        },
        receive: function(event, ui) {
            var $list = $(this);
            var taxonomy = $list.closest('.wcpf-attribute-terms').data('taxonomy');
            var isActiveList = $list.parent().hasClass('wcpf-active-terms');
            console.log('WCPF Debug: Received item in list for taxonomy:', taxonomy, 'isActiveList:', isActiveList);

            var item = ui.item.data('key');
            var termId = ui.item.data('term-id');
            var $availableList = $list.closest('.wcpf-terms-container').find('.wcpf-available-terms .wcpf-sortable-terms');
            var $activeList = $list.closest('.wcpf-terms-container').find('.wcpf-active-terms .wcpf-sortable-terms');

            // Đảm bảo item là thẻ <li>
            if (!ui.item.is('li')) {
                console.log('WCPF Debug: Fixing incorrect tag for item:', item, 'taxonomy:', taxonomy);
                var $newLi = $('<li></li>').attr({
                    'data-key': item,
                    'data-term-id': termId,
                    'class': 'wcpf-sortable-item'
                });
                $newLi.append(ui.item.contents());
                ui.item.replaceWith($newLi);
                ui.item = $newLi;
            }

            if (isActiveList && item === 'select-all') {
                console.log('WCPF Debug: Select All dragged to active terms for taxonomy:', taxonomy);

                $availableList.find('li:not([data-key="select-all"])').each(function() {
                    var $term = $(this);
                    var termKey = $term.data('key');
                    var termId = $term.data('term-id');
                    var termName = $term.find('.wcpf-term-name').text().trim() || $term.text().trim();
                    var imageUrl = $term.find('.wcpf-term-icon').is('img') ? $term.find('.wcpf-term-icon').attr('src') : '';
                    if (!$activeList.find(`li[data-key="${termKey}"]`).length) {
                        var imageHtml = imageUrl ? `<img src="${imageUrl}" class="wcpf-term-icon" alt="${termName}">` : `<img src="${wcpf_admin_params.default_term_image}" class="wcpf-term-icon" alt="${termName}">`;
                        var $newItem = $(`
                            <li data-key="${termKey}" data-term-id="${termId}" class="wcpf-sortable-item">
                                <span class="wcpf-term-image-container">${imageHtml}</span>
                                ${imageUrl && imageUrl !== wcpf_admin_params.default_term_image ? `<button type="button" class="wcpf-remove-term-image" data-term-id="${termId}">Xóa ảnh</button>` : ''}
                                <span class="wcpf-term-name">${termName}</span>
                                <input type="hidden" name="wcpf_filter_settings[active_attribute_terms][${taxonomy}][]" value="${termKey}">
                            </li>
                        `);
                        $activeList.append($newItem);
                    }
                    $term.remove();
                });

                ui.item.remove();

                if (!$availableList.find('li[data-key="select-all"]').length) {
                    $availableList.prepend(`<li data-key="select-all" class="wcpf-sortable-item wcpf-select-all">${wcpf_admin_params.i18n.select_all || 'Chọn tất cả'}</li>`);
                }
            } else {
                // Xóa các nút và input hiện có để tránh trùng lặp
                ui.item.find('.wcpf-remove-term-image, input').remove();

                var termName = ui.item.find('.wcpf-term-name').text().trim() || ui.item.text().trim();
                var imageUrl = ui.item.find('.wcpf-term-icon').is('img') ? ui.item.find('.wcpf-term-icon').attr('src') : '';
                var imageHtml = imageUrl ? `<img src="${imageUrl}" class="wcpf-term-icon" alt="${termName}">` : `<img src="${wcpf_admin_params.default_term_image}" class="wcpf-term-icon" alt="${termName}">`;

                // Xóa nội dung hiện tại của item để tái cấu trúc
                ui.item.empty();

                // Tái cấu trúc item
                ui.item.append(`<span class="wcpf-term-image-container">${imageHtml}</span>`);
                if (isActiveList && imageUrl && imageUrl !== wcpf_admin_params.default_term_image) {
                    ui.item.append(`<button type="button" class="wcpf-remove-term-image" data-term-id="${termId}">Xóa ảnh</button>`);
                }
                ui.item.append(`<span class="wcpf-term-name">${termName}</span>`);
                if (isActiveList) {
                    ui.item.append(`<input type="hidden" name="wcpf_filter_settings[active_attribute_terms][${taxonomy}][]" value="${item}">`);
                }

                console.log('WCPF Debug: Item after processing:', ui.item.prop('outerHTML'));
            }
        }
    }).disableSelection();

    $('.wcpf-sortable-price').sortable({
        update: function(event, ui) {
            var $list = $(this);
            $list.find('li').each(function(index) {
                $(this).find('input').each(function() {
                    var name = $(this).attr('name');
                    var newName = name.replace(/\[price_ranges\]\[\d+\]/, `[price_ranges][${index}]`);
                    $(this).attr('name', newName);
                });
            });
        }
    }).disableSelection();

    $(document).on('click', '.wcpf-label-editable', function() {
        var $span = $(this);
        var key = $span.data('key');
        var currentLabel = $span.text().trim();
        console.log('WCPF Debug: Editing label for key:', key, 'current:', currentLabel);
        var $input = $(`<input type="text" class="wcpf-label-input" value="${currentLabel}">`);
        $span.replaceWith($input);
        $input.focus();
    });

    $(document).on('blur', '.wcpf-label-input', function() {
        var $input = $(this);
        var key = $input.closest('li').data('key');
        var newLabel = $input.val().trim();
        var defaultLabel = key === 'price' ? 'Giá' : (availableAttributes[key] || key);
        console.log('WCPF Debug: Blurring input for key:', key, 'newLabel:', newLabel, 'defaultLabel:', defaultLabel);
        if (!newLabel) {
            newLabel = defaultLabel;
        }
        var $span = $(`<span class="wcpf-label-editable" data-key="${key}">${newLabel}</span>`);
        $input.replaceWith($span);
        var $li = $input.closest('li');
        $li.find(`.wcpf-label-input-hidden[name="wcpf_filter_settings[attribute_labels][${key}]"]`).remove();
        $li.append(`<input type="hidden" class="wcpf-label-input-hidden" name="wcpf_filter_settings[attribute_labels][${key}]" value="${newLabel}">`);
        console.log('WCPF Debug: Added new hidden input for key:', key, 'value:', newLabel);
    });

    $(document).on('keypress', '.wcpf-label-input', function(e) {
        if (e.which === 13) {
            console.log('WCPF Debug: Enter pressed for label input');
            $(this).blur();
        }
    });

    $(document).on('click', '.wcpf-active-terms .wcpf-term-image-container', function(e) {
        e.preventDefault();
        var $container = $(this);
        var termId = $container.closest('li').data('term-id');
        var $li = $container.closest('li');

        var frame = wp.media({
            title: wcpf_admin_params.i18n.upload_term_image || 'Chọn ảnh cho term',
            button: {
                text: 'Chọn ảnh'
            },
            multiple: false,
            library: {
                type: 'image'
            }
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            var attachmentId = attachment.id;
            var imageUrl = attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;

            $.ajax({
                url: wcpf_admin_params.ajax_url,
                method: 'POST',
                data: {
                    action: 'wcpf_upload_term_image',
                    nonce: wcpf_admin_params.nonce,
                    term_id: termId,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        console.log('WCPF Debug: Uploaded image for term:', termId, 'URL:', response.data.image_url);
                        $container.html(`<img src="${response.data.image_url}" class="wcpf-term-icon" alt="Term Image">`);
                        if (!$li.find('.wcpf-remove-term-image').length) {
                            $container.after(`<button type="button" class="wcpf-remove-term-image" data-term-id="${termId}">Xóa ảnh</button>`);
                        }
                    } else {
                        console.error('WCPF AJAX Error:', response.data.message);
                        alert(response.data.message || wcpf_admin_params.i18n.error_upload);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error('WCPF AJAX Error:', textStatus, errorThrown);
                    alert(wcpf_admin_params.i18n.error_upload + ' ' + textStatus);
                }
            });
        });

        frame.open();
    });

    $(document).on('click', '.wcpf-remove-term-image', function(e) {
        e.preventDefault();
        var $button = $(this);
        var termId = $button.data('term-id');
        var $li = $button.closest('li');
        var $imageContainer = $li.find('.wcpf-term-image-container');

        $.ajax({
            url: wcpf_admin_params.ajax_url,
            method: 'POST',
            data: {
                action: 'wcpf_remove_term_image',
                nonce: wcpf_admin_params.nonce,
                term_id: termId
            },
            success: function(response) {
                if (response.success) {
                    console.log('WCPF Debug: Removed image for term:', termId);
                    $imageContainer.html(`<img src="${wcpf_admin_params.default_term_image}" class="wcpf-term-icon" alt="Default Term Image">`);
                    $button.remove();
                } else {
                    console.error('WCPF AJAX Error:', response.data.message);
                    alert(response.data.message || wcpf_admin_params.i18n.error_remove_image);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('WCPF AJAX Error:', textStatus, errorThrown);
                alert(wcpf_admin_params.i18n.error_remove_image + ' ' + textStatus);
            }
        });
    });

    $('.wcpf-button-add').on('click', function() {
        var $list = $('.wcpf-sortable-price');
        var index = $list.find('li').length;
        $list.append(`
            <li class="wcpf-price-range-item">
                <input type="number" name="wcpf_filter_settings[price_ranges][${index}][min]" placeholder="Giá Tối thiểu" class="wcpf-input wcpf-input-number">
                <input type="number" name="wcpf_filter_settings[price_ranges][${index}][max]" placeholder="Giá Tối đa" class="wcpf-input wcpf-input-number">
                <input type="text" name="wcpf_filter_settings[price_ranges][${index}][label]" placeholder="Nhãn (VD: 0 - 10 triệu)" class="wcpf-input wcpf-input-text">
                <button type="button" class="wcpf-button wcpf-button-remove">Xóa</button>
            </li>
        `);
    });

    $(document).on('click', '.wcpf-button-remove', function() {
        $(this).closest('li').remove();
        $('.wcpf-sortable-price').find('li').each(function(index) {
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                var newName = name.replace(/\[price_ranges\]\[\d+\]/, `[price_ranges][${index}]`);
                $(this).attr('name', newName);
            });
        });
    });

    $(document).on('change', '.wcpf-toggle-filter', function() {
        var $checkbox = $(this);
        var filterId = $checkbox.data('filter-id');
        var active = $checkbox.is(':checked') ? 1 : 0;

        $.ajax({
            url: wcpf_admin_params.ajax_url,
            method: 'POST',
            data: {
                action: 'wcpf_toggle_filter',
                nonce: wcpf_admin_params.nonce,
                filter_id: filterId,
                active: active
            },
            success: function(response) {
                if (response.success) {
                    console.log('WCPF Debug: Toggled filter', filterId, 'to active:', active);
                    $checkbox.prop('checked', active === 1);
                } else {
                    console.error('WCPF AJAX Error:', response.data.message);
                    alert(response.data.message || wcpf_admin_params.i18n.error_toggle);
                    $checkbox.prop('checked', active !== 1);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('WCPF AJAX Error:', textStatus, errorThrown);
                alert(wcpf_admin_params.i18n.error_toggle + ' ' + textStatus);
                $checkbox.prop('checked', active !== 1);
            }
        });
    });

    $(document).on('click', '.wcpf-delete-filter', function() {
        if (!confirm(wcpf_admin_params.i18n.confirm_delete)) {
            return;
        }
        var filterId = $(this).data('filter-id');
        $.ajax({
            url: wcpf_admin_params.ajax_url,
            method: 'POST',
            data: {
                action: 'wcpf_delete_filter',
                nonce: wcpf_admin_params.nonce,
                filter_id: filterId
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert(response.data.message || wcpf_admin_params.i18n.error_delete);
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('WCPF AJAX Error:', textStatus, errorThrown);
                alert(wcpf_admin_params.i18n.error_delete + ' ' + textStatus);
            }
        });
    });

    $('#wcpf-settings-form').on('submit', function() {
        var $form = $(this);
        $form.find('input[name^="wcpf_filter_settings[attribute_labels]"]').remove();
        $('.wcpf-active-attributes li').each(function() {
            var $li = $(this);
            var key = $li.data('key');
            var label = $li.find('.wcpf-label-editable').text().trim() || availableAttributes[key] || key;
            $li.append(`<input type="hidden" class="wcpf-label-input-hidden" name="wcpf_filter_settings[attribute_labels][${key}]" value="${label}">`);
            console.log('WCPF Debug: Synced hidden input for key:', key, 'value:', label);
        });
        var labelInputs = $form.find('input[name^="wcpf_filter_settings[attribute_labels]"]');
        console.log('WCPF Debug: Hidden inputs for attribute_labels:', labelInputs.serializeArray());
        var formData = $form.serializeArray();
        console.log('WCPF Debug: Form data being submitted:', formData);
        var attributeLabels = {};
        formData.forEach(function(item) {
            if (item.name.match(/wcpf_filter_settings\[attribute_labels\]\[(.+)\]/)) {
                var key = item.name.match(/wcpf_filter_settings\[attribute_labels\]\[(.+)\]/)[1];
                attributeLabels[key] = item.value;
            }
        });
        console.log('WCPF Debug: Attribute labels in form data:', attributeLabels);
    });
	// Xử lý nút "Chọn ảnh"
    $('.wcpf-upload-image-button').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $wrapper = $button.closest('.wcpf-image-upload-wrapper');
        var $preview = $wrapper.find('.wcpf-image-preview');
        var $input = $wrapper.find('#wcpf-loading-image-id');

        var frame = wp.media({
            title: wcpf_admin_params.i18n.select_image,
            button: { text: wcpf_admin_params.i18n.select_image },
            multiple: false,
            library: { type: 'image' }
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $input.val(attachment.id);
            $preview.html('<img src="' + attachment.url + '" alt="' + wcpf_admin_params.i18n.select_image + '" class="loading-image-preview">');
            if (!$wrapper.find('.wcpf-remove-image-button').length) {
                $button.after('<button type="button" class="button wcpf-remove-image-button">' + wcpf_admin_params.i18n.remove_image + '</button>');
            }
        });

        frame.open();
    });

    // Xử lý nút "Xóa ảnh"
    $(document).on('click', '.wcpf-remove-image-button', function(e) {
        e.preventDefault();
        var $button = $(this);
        var $wrapper = $button.closest('.wcpf-image-upload-wrapper');
        var $preview = $wrapper.find('.wcpf-image-preview');
        var $input = $wrapper.find('#wcpf-loading-image-id');

        $input.val('');
        $preview.empty();
        $button.remove();
    });
	$('.wcpf-clear-cache-button').on('click', function() {
		if (!confirm('Bạn có chắc chắn muốn xóa toàn bộ cache của bộ lọc?')) {
			return;
		}

		var $button = $(this);
		$button.prop('disabled', true).text('Đang xóa cache...');

		$.ajax({
			url: wcpf_admin_params.ajax_url,
			type: 'POST',
			data: {
				action: 'wcpf_clear_cache',
				nonce: wcpf_admin_params.nonce
			},
			success: function(response) {
				if (response.success) {
					alert(wcpf_admin_params.i18n.clear_cache_success);
				} else {
					alert(wcpf_admin_params.i18n.clear_cache_error);
				}
			},
			error: function() {
				alert(wcpf_admin_params.i18n.clear_cache_error);
			},
			complete: function() {
				$button.prop('disabled', false).text('Xóa toàn bộ cache bộ lọc');
			}
		});
	});
	
	$('.wcpf-clear-cache').on('click', function(e) {
        e.preventDefault();
        var $button = $(this);
        var originalText = $button.text();
        $button.text(wcpf_admin_params.i18n.clearing_cache).prop('disabled', true);

        $.ajax({
            url: wcpf_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'wcpf_clear_cache',
                nonce: wcpf_admin_params.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success notice
                    var $notice = $('<div class="notice notice-success is-dismissible"><p>' + response.data.message + '</p></div>');
                    $('.wcpf-container').prepend($notice);
                    // Update cache count
                    $('.wcpf-cache-count').text('0');
                    // Reset button
                    $button.text(originalText).prop('disabled', false);
                    // Auto-dismiss notice after 5 seconds
                    setTimeout(function() {
                        $notice.fadeOut(400, function() {
                            $(this).remove();
                        });
                    }, 5000);
                } else {
                    // Show error notice
                    var $notice = $('<div class="notice notice-error is-dismissible"><p>' + response.data.message + '</p></div>');
                    $('.wcpf-container').prepend($notice);
                    $button.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                // Show generic error notice
                var $notice = $('<div class="notice notice-error is-dismissible"><p>' + wcpf_admin_params.i18n.error_clear_cache + '</p></div>');
                $('.wcpf-container').prepend($notice);
                $button.text(originalText).prop('disabled', false);
            }
        });
    });
});