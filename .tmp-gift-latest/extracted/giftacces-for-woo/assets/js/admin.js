(function ($) {
	'use strict';

	function refreshProductFields() {
		var enabled = $('#_giftaccess_woo_enabled').is(':checked');
		var amountType = $('#_giftaccess_woo_amount_type').val();
		var productType = $('#product-type').val();
		var isVariable = productType === 'variable';

		$('p.form-field.giftaccess-woo-dependent').toggle(enabled);
		$('p.form-field.giftaccess-woo-amount-fixed').toggle(enabled && amountType === 'fixed');
		$('p.form-field.giftaccess-woo-amount-dynamic').toggle(enabled && amountType === 'dynamic');
		$('p.form-field.giftaccess-woo-variable-note').toggle(enabled && isVariable);
		$('p.form-field.giftaccess-woo-variable-hide').toggle(enabled && !isVariable);
	}

	function bindApiTestButton() {
		var $button = $('#giftaccess-woo-test-api');
		var $result = $('#giftaccess-woo-test-api-result');

		if (!$button.length) {
			return;
		}

		$button.on('click', function () {
			$button.prop('disabled', true);
			$result.removeClass('giftaccess-woo-success giftaccess-woo-error').text(izyGiftAccessWoo.i18n.testing);

			$.post(izyGiftAccessWoo.ajaxUrl, {
				action: 'giftaccess_woo_test_api',
				nonce: izyGiftAccessWoo.nonce
			})
				.done(function (response) {
					if (response && response.success) {
						$result.addClass('giftaccess-woo-success').text(response.data.message || izyGiftAccessWoo.i18n.success);
						return;
					}
					var msg = (response && response.data && response.data.message) || izyGiftAccessWoo.i18n.error;
					$result.addClass('giftaccess-woo-error').html(msg.replace(/\|/g, '<br>'));
				})
				.fail(function (xhr) {
					var message = izyGiftAccessWoo.i18n.error;
					if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						message = xhr.responseJSON.data.message;
					}
					$result.addClass('giftaccess-woo-error').html(message.replace(/\|/g, '<br>'));
				})
				.always(function () {
					$button.prop('disabled', false);
				});
		});
	}

	function bindBulkSelection() {
		$('[data-giftaccess-bulk-table]').each(function () {
			var $table = $(this);
			var $selectAll = $table.find('[data-giftaccess-select-all]');
			var $rowCheckboxes = $table.find('[data-giftaccess-row-checkbox]');

			if (!$selectAll.length || !$rowCheckboxes.length) {
				return;
			}

			function syncSelectAllState() {
				var checkedCount = $rowCheckboxes.filter(':checked').length;
				var totalCount = $rowCheckboxes.length;
				var isAllChecked = totalCount > 0 && checkedCount === totalCount;
				var isIndeterminate = checkedCount > 0 && checkedCount < totalCount;

				$selectAll.prop('checked', isAllChecked);
				$selectAll.prop('indeterminate', isIndeterminate);
			}

			$selectAll.on('change', function () {
				var shouldCheck = $(this).is(':checked');
				$rowCheckboxes.prop('checked', shouldCheck);
				$selectAll.prop('indeterminate', false);
			});

			$rowCheckboxes.on('change', syncSelectAllState);
			syncSelectAllState();
		});
	}

	function bindConfirmActions() {
		$('form[data-giftaccess-confirm]').on('submit', function () {
			var message = $(this).attr('data-giftaccess-confirm') || '';
			if (!message) {
				return true;
			}

			return window.confirm(message);
		});

		$('a[data-giftaccess-confirm]').on('click', function (event) {
			var message = $(this).attr('data-giftaccess-confirm') || '';
			if (!message) {
				return;
			}

			if (!window.confirm(message)) {
				event.preventDefault();
			}
		});
	}

	$(function () {
		if ($('#_giftaccess_woo_enabled').length) {
			refreshProductFields();
			$('#_giftaccess_woo_enabled, #_giftaccess_woo_amount_type, #product-type').on('change', refreshProductFields);
		}

		bindApiTestButton();
		bindBulkSelection();
		bindConfirmActions();
	});
})(jQuery);
