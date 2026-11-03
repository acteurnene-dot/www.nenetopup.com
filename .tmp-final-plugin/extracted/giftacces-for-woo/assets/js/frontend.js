(function () {
	'use strict';

	var toastTimer = null;
	var pendingTimers = [];

	function getToast() {
		var existing = document.getElementById('giftaccess-woo-copy-toast');
		if (existing) {
			return existing;
		}

		var toast = document.createElement('div');
		toast.id = 'giftaccess-woo-copy-toast';
		toast.className = 'giftaccess-woo-copy-toast';
		toast.setAttribute('role', 'status');
		toast.setAttribute('aria-live', 'polite');
		document.body.appendChild(toast);

		return toast;
	}

	function showToast(message, isError) {
		var toast = getToast();
		toast.textContent = message;
		toast.classList.toggle('is-error', !!isError);
		toast.classList.add('is-visible');

		if (toastTimer) {
			clearTimeout(toastTimer);
		}

		toastTimer = setTimeout(function () {
			toast.classList.remove('is-visible');
		}, 1600);
	}

	function fallbackCopy(text) {
		var textarea = document.createElement('textarea');
		textarea.value = text;
		textarea.setAttribute('readonly', 'readonly');
		textarea.style.position = 'absolute';
		textarea.style.left = '-9999px';
		document.body.appendChild(textarea);
		textarea.select();

		var ok = false;
		try {
			ok = document.execCommand('copy');
		} catch (e) {
			ok = false;
		}

		document.body.removeChild(textarea);
		return ok;
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('.giftaccess-woo-copy-btn');
		if (!button) {
			return;
		}

		event.preventDefault();

		var text = button.getAttribute('data-copy') || '';
		if (!text) {
			return;
		}

		var oldLabel = button.textContent;

		function setFeedback(success) {
			showToast(success ? (giftAccessWooFront.copied + ' ✅') : giftAccessWooFront.copyFailed, !success);
			button.textContent = success ? giftAccessWooFront.copied : oldLabel;

			setTimeout(function () {
				button.textContent = oldLabel;
			}, 1600);
		}

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard
				.writeText(text)
				.then(function () {
					setFeedback(true);
				})
				.catch(function () {
					setFeedback(fallbackCopy(text));
				});
			return;
		}

		setFeedback(fallbackCopy(text));
	});

	function formatCountdown(seconds) {
		var template = (window.giftAccessWooFront && giftAccessWooFront.refreshingIn) ? giftAccessWooFront.refreshingIn : 'Rafraîchissement automatique dans %s s.';
		return template.replace('%s', seconds);
	}

	function parseNumber(value) {
		if (typeof value !== 'string') {
			value = String(value || '');
		}

		value = value.replace(',', '.').replace(/[^\d.-]/g, '');
		var number = parseFloat(value);

		return isNaN(number) ? 0 : number;
	}

	function formatTotalMoney(amount, currency, decimals) {
		if (Math.abs(amount - Math.round(amount)) < 0.00001) {
			decimals = 0;
		}

		var fixed = amount.toFixed(decimals);
		var parts = fixed.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
		var formatted = parts.join('.');

		return currency ? formatted + ' ' + currency : formatted;
	}

	function initAmountCalculators() {
		var blocks = document.querySelectorAll('[data-giftaccess-woo-calculation]');
		if (!blocks.length) {
			return;
		}

		blocks.forEach(function (block) {
			var wrapper = block.closest('.giftaccess-woo-amount-field-wrapper');
			var input = wrapper ? wrapper.querySelector('input[name="giftaccess_woo_amount"]') : null;
			var text = block.querySelector('[data-giftaccess-woo-calculation-text]');
			var unitRate = parseNumber(block.getAttribute('data-unit-rate'));
			var currency = block.getAttribute('data-store-currency') || '';
			var decimals = parseInt(block.getAttribute('data-decimals') || '2', 10);

			if (!input || !text || unitRate <= 0) {
				return;
			}

			if (isNaN(decimals) || decimals < 0) {
				decimals = 2;
			}

			function update() {
				var amount = parseNumber(input.value);
				var total = amount > 0 ? amount * unitRate : 0;

				text.textContent = formatTotalMoney(total, currency, decimals);
			}

			input.addEventListener('input', update);
			input.addEventListener('change', update);
			update();
		});
	}

	function initPendingBlocks() {
		var blocks = document.querySelectorAll('[data-giftaccess-woo-pending]');
		if (!blocks.length) {
			return;
		}

		blocks.forEach(function (block) {
			var orderId = block.getAttribute('data-order-id') || '0';
			var refreshSeconds = parseInt(block.getAttribute('data-auto-refresh-seconds') || '0', 10);
			var maxRefreshAttempts = parseInt(block.getAttribute('data-max-refresh-attempts') || '0', 10);
			var countdownNode = block.querySelector('[data-giftaccess-woo-countdown]');
			var dotsNode = block.querySelector('[data-giftaccess-woo-dots]');
			var storageKey = 'izyGiftAccessPendingReloads:' + orderId;
			var usedAttempts = 0;

			try {
				usedAttempts = parseInt(window.sessionStorage.getItem(storageKey) || '0', 10);
				if (isNaN(usedAttempts)) {
					usedAttempts = 0;
				}
			} catch (e) {
				usedAttempts = 0;
			}

			if (dotsNode) {
				var dotCount = 0;
				pendingTimers.push(window.setInterval(function () {
					dotCount = (dotCount + 1) % 4;
					dotsNode.textContent = Array(dotCount + 1).join('.');
				}, 450));
			}

			if (!refreshSeconds || !countdownNode) {
				return;
			}

			if (maxRefreshAttempts > 0 && usedAttempts >= maxRefreshAttempts) {
				countdownNode.textContent = (window.giftAccessWooFront && giftAccessWooFront.refreshLimitReached) ? giftAccessWooFront.refreshLimitReached : 'Rafraîchissement automatique arrêté. Rechargez la page si nécessaire.';
				return;
			}

			var remaining = refreshSeconds;
			countdownNode.textContent = formatCountdown(remaining);

			pendingTimers.push(window.setInterval(function () {
				remaining -= 1;

				if (remaining <= 0) {
					try {
						window.sessionStorage.setItem(storageKey, String(usedAttempts + 1));
					} catch (e) {
						// Ignore storage issues.
					}

					try {
						var url = new URL(window.location.href);
						url.searchParams.set('giftaccess_woo_poll', String(Date.now()));
						window.location.replace(url.toString());
					} catch (e) {
						window.location.reload();
					}
					return;
				}

				countdownNode.textContent = formatCountdown(remaining);
			}, 1000));
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			initAmountCalculators();
			initPendingBlocks();
		});
	} else {
		initAmountCalculators();
		initPendingBlocks();
	}
})();
