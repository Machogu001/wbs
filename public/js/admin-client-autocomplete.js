(function (window, document) {
	'use strict';

	if (window.WbsClientAutocomplete) {
		return;
	}

	var stylesInjected = false;

	function injectStyles() {
		if (stylesInjected) {
			return;
		}
		stylesInjected = true;

		var style = document.createElement('style');
		style.textContent = '' +
			'.wbs-client-autocomplete-panel{' +
			'position:absolute;left:0;right:0;top:calc(100% + 0.25rem);z-index:1080;display:none;' +
			'background:#fff;border:1px solid rgba(15,23,42,0.12);border-radius:0.85rem;box-shadow:0 1rem 2rem rgba(15,23,42,0.14);' +
			'max-height:18rem;overflow:auto;padding:0.35rem;}' +
			'.wbs-client-autocomplete-option{' +
			'display:block;width:100%;border:0;background:transparent;text-align:left;padding:0.65rem 0.75rem;border-radius:0.65rem;}' +
			'.wbs-client-autocomplete-option:hover,.wbs-client-autocomplete-option.is-active{' +
			'background:#eff6ff;}' +
			'.wbs-client-autocomplete-title{display:block;font-weight:600;color:#0f172a;line-height:1.35;}' +
			'.wbs-client-autocomplete-meta{display:block;margin-top:0.2rem;font-size:0.82rem;color:#475569;line-height:1.35;}' +
			'.wbs-client-autocomplete-empty{' +
			'padding:0.75rem;color:#64748b;font-size:0.9rem;}' +
			'.wbs-client-autocomplete-host{position:relative;}';
		document.head.appendChild(style);
	}

	function normalizeText(value) {
		return String(value || '').trim();
	}

	function getMeterMeta(item) {
		var meters = Array.isArray(item.meters) ? item.meters : [];
		if (!meters.length) {
			return 'Search by account number, meter number, or client name.';
		}

		if (item.matched_meter_number) {
			for (var i = 0; i < meters.length; i += 1) {
				if (String(meters[i].number) === String(item.matched_meter_number)) {
					return 'Matched meter: ' + meters[i].number + (meters[i].label ? ' - ' + meters[i].label : '');
				}
			}
			return 'Matched meter: ' + item.matched_meter_number;
		}

		var summary = meters.map(function (meter) {
			return meter.number + (meter.label ? ' (' + meter.label + ')' : '');
		}).join(', ');

		return 'Meters: ' + summary;
	}

	function createOptionButton(item, index, selectItem) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'wbs-client-autocomplete-option';
		button.setAttribute('data-index', String(index));

		var title = document.createElement('span');
		title.className = 'wbs-client-autocomplete-title';
		title.textContent = normalizeText(item.full_name) + ' - ' + normalizeText(item.account_number);
		button.appendChild(title);

		var meta = document.createElement('span');
		meta.className = 'wbs-client-autocomplete-meta';
		meta.textContent = getMeterMeta(item);
		button.appendChild(meta);

		button.addEventListener('mousedown', function (event) {
			event.preventDefault();
			selectItem(item);
		});

		return button;
	}

	function initInput(input, options) {
		if (!input || input.dataset.clientAutocompleteReady === '1') {
			return;
		}

		input.dataset.clientAutocompleteReady = '1';
		input.removeAttribute('list');

		var host = input.parentElement;
		if (host && !host.classList.contains('wbs-client-autocomplete-host')) {
			host.classList.add('wbs-client-autocomplete-host');
		}

		var panel = document.createElement('div');
		panel.className = 'wbs-client-autocomplete-panel';
		panel.setAttribute('role', 'listbox');
		panel.setAttribute('aria-label', 'Client suggestions');
		if (host) {
			host.appendChild(panel);
		}

		var activeIndex = -1;
		var currentItems = [];
		var debounceTimer = null;
		var requestId = 0;
		var minChars = Number(options.minChars || 2);

		function closePanel() {
			panel.style.display = 'none';
			panel.innerHTML = '';
			activeIndex = -1;
		}

		function setActive(index) {
			var buttons = panel.querySelectorAll('.wbs-client-autocomplete-option');
			buttons.forEach(function (button, buttonIndex) {
				button.classList.toggle('is-active', buttonIndex === index);
			});
			activeIndex = index;
		}

		function dispatchResults(items) {
			input.dispatchEvent(new CustomEvent('wbs:client-results', {
				bubbles: true,
				detail: { items: items }
			}));
		}

		function selectItem(item) {
			input.value = item.selection_value || item.account_number || '';
			input.dispatchEvent(new CustomEvent('wbs:client-selected', {
				bubbles: true,
				detail: { item: item }
			}));
			closePanel();
		}

		function renderItems(items) {
			panel.innerHTML = '';
			currentItems = items.slice();
			dispatchResults(currentItems);

			if (!currentItems.length) {
				var empty = document.createElement('div');
				empty.className = 'wbs-client-autocomplete-empty';
				empty.textContent = 'No clients matched this search.';
				panel.appendChild(empty);
				panel.style.display = 'block';
				activeIndex = -1;
				return;
			}

			currentItems.forEach(function (item, index) {
				panel.appendChild(createOptionButton(item, index, selectItem));
			});
			panel.style.display = 'block';
			setActive(-1);
		}

		function fetchSuggestions(query) {
			requestId += 1;
			var currentRequestId = requestId;
			fetch((options.endpoint || '/api/admin/search_clients') + '?q=' + encodeURIComponent(query), {
				headers: {
					'Accept': 'application/json',
					'X-Requested-With': 'XMLHttpRequest'
				}
			})
				.then(function (response) { return response.json(); })
				.then(function (payload) {
					if (currentRequestId !== requestId) {
						return;
					}
					if (!payload || payload.status !== 'success' || !Array.isArray(payload.data)) {
						renderItems([]);
						return;
					}
					renderItems(payload.data);
				})
				.catch(function () {
					if (currentRequestId === requestId) {
						closePanel();
					}
				});
		}

		input.addEventListener('input', function () {
			input.dispatchEvent(new CustomEvent('wbs:client-input', {
				bubbles: true,
				detail: { value: input.value }
			}));

			window.clearTimeout(debounceTimer);
			var query = normalizeText(input.value);
			if (query.length < minChars) {
				closePanel();
				dispatchResults([]);
				return;
			}

			debounceTimer = window.setTimeout(function () {
				fetchSuggestions(query);
			}, Number(options.debounceMs || 250));
		});

		input.addEventListener('keydown', function (event) {
			if (panel.style.display !== 'block' || !currentItems.length) {
				return;
			}

			if (event.key === 'ArrowDown') {
				event.preventDefault();
				setActive(Math.min(activeIndex + 1, currentItems.length - 1));
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				setActive(Math.max(activeIndex - 1, 0));
			} else if (event.key === 'Enter' && activeIndex >= 0) {
				event.preventDefault();
				selectItem(currentItems[activeIndex]);
			} else if (event.key === 'Escape') {
				closePanel();
			}
		});

		input.addEventListener('blur', function () {
			window.setTimeout(closePanel, 150);
		});

		document.addEventListener('click', function (event) {
			if (host && !host.contains(event.target)) {
				closePanel();
			}
		});
	}

	window.WbsClientAutocomplete = {
		init: function (selector, options) {
			injectStyles();
			document.querySelectorAll(selector).forEach(function (input) {
				initInput(input, options || {});
			});
		}
	};
})(window, document);