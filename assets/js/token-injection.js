/**
 * Gravity Forms Zero Spam — token injection.
 *
 * Registers an async pre-submission filter (GF 2.9+) or a submit-event
 * listener (older GF) for each form configured in gfZeroSpamConfig.
 *
 * @since 1.7.3
 */
(() => {
	if (typeof gfZeroSpamConfig === 'undefined') {
		return;
	}

	const forms = gfZeroSpamConfig.forms;
	const debug = !!gfZeroSpamConfig.debug;

	if (!forms || !forms.length) {
		return;
	}

	function log(msg) {
		if (debug) {
			console.warn('[GF Zero Spam] ' + msg);
		}
	}

	/**
	 * Fetches a fresh token via admin-ajax, falling back to the embedded token.
	 */
	function fetchToken(cfg) {
		const body = new FormData();
		body.append('action', 'gf_zero_spam_token');
		body.append('form_id', cfg.formId);

		const options = { method: 'POST', body: body };

		if (typeof AbortSignal !== 'undefined' && AbortSignal.timeout) {
			options.signal = AbortSignal.timeout(cfg.timeout);
		}

		let response;

		try {
			response = fetch(cfg.ajaxUrl, options);
		} catch (err) {
			log('Token fetch failed for form ' + cfg.formId + ': ' + err.message + '. Using fallback token.');
			return Promise.resolve(cfg.fallbackToken);
		}

		if (!response || typeof response.then !== 'function') {
			log('Token fetch failed for form ' + cfg.formId + ': fetch returned non-thenable. Using fallback token.');
			return Promise.resolve(cfg.fallbackToken);
		}

		return Promise.resolve(response)
			.then((res) => {
				if (!res.ok) {
					throw new Error('AJAX ' + res.status);
				}
				return res.json();
			})
			.then((json) => json.token)
			.catch((err) => {
				log('Token fetch failed for form ' + cfg.formId + ': ' + err.message + '. Using fallback token.');
				return cfg.fallbackToken;
			});
	}

	/**
	 * Injects the token hidden input into the form element.
	 */
	function injectToken(formEl, token) {
		const old = formEl.querySelector('input[name="gf_zero_spam_token"]');

		if (old) {
			old.remove();
		}

		const input = document.createElement('input');
		input.type = 'hidden';
		input.name = 'gf_zero_spam_token';
		input.value = token;
		input.setAttribute('autocomplete', 'new-password');
		formEl.appendChild(input);
	}

	// GF 2.9+ path: register a global async pre-submission filter.
	if (typeof gform !== 'undefined' && gform.utils && gform.utils.addAsyncFilter) {
		const formIds = {};

		forms.forEach((cfg) => {
			formIds[cfg.formId] = cfg;
		});

		gform.utils.addAsyncFilter('gform/submission/pre_submission', (data) => {
			const id = parseInt(data.form.dataset.formid, 10);
			const cfg = formIds[id];

			if (!cfg) {
				return Promise.resolve(data);
			}

			// The server only checks the token on final submission and Save and Continue,
			// so page navigation skips the blocking fetch. Skip-list on purpose: an
			// unknown or missing submissionType must still fetch, never the reverse.
			if ('next' === data.submissionType || 'previous' === data.submissionType) {
				return Promise.resolve(data);
			}

			return fetchToken(cfg).then((token) => {
				injectToken(data.form, token);
				return data;
			});
		});

		return;
	}

	// Legacy path (GF < 2.9): attach submit handlers per form.
	forms.forEach((cfg) => {
		const formEl = document.getElementById('gform_' + cfg.formId);

		if (!formEl || formEl.dataset.gfzsBound) {
			return;
		}

		formEl.dataset.gfzsBound = '1';
		formEl.addEventListener('submit', function (e) {
			// Same skip rule as the GF 2.9+ path: page navigation (target page > 0)
			// never consumes the token, but Save and Continue (gform_save) does.
			const targetInput = this.querySelector('input[name="gform_target_page_number_' + cfg.formId + '"]');
			const saveInput = this.querySelector('input[name="gform_save"]');
			const isNavigation = targetInput && parseInt(targetInput.value, 10) > 0;
			const isSave = saveInput && '1' === String(saveInput.value);

			if (isNavigation && !isSave) {
				return;
			}

			e.preventDefault();

			fetchToken(cfg).then((token) => {
				injectToken(this, token);
				this.submit();
			});
		});
	});
})();
