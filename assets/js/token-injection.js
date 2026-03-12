/**
 * Gravity Forms Zero Spam — token injection.
 *
 * Registers an async pre-submission filter (GF 2.9+) or a submit-event
 * listener (older GF) for each form configured in gfZeroSpamConfig.
 *
 * @since TBD
 */
(() => {
	if (typeof gfZeroSpamConfig === 'undefined') {
		return;
	}

	const forms = gfZeroSpamConfig.forms;

	if (!forms || !forms.length) {
		return;
	}

	/**
	 * Fetches a fresh token, falling back through REST → admin-ajax → embedded fallback.
	 */
	function fetchToken(cfg) {
		return fetch(cfg.restUrl + '?form_id=' + cfg.formId, {
			signal: AbortSignal.timeout(cfg.timeout)
		})
			.then((res) => {
				if (!res.ok) {
					throw new Error('REST failed');
				}
				return res.json();
			})
			.then((json) => json.token)
			.catch(() => fetch(cfg.ajaxUrl + '?action=gf_zero_spam_token&form_id=' + cfg.formId, {
					signal: AbortSignal.timeout(cfg.timeout)
				})
					.then((res) => {
						if (!res.ok) {
							throw new Error('AJAX failed');
						}
						return res.json();
					})
					.then((json) => json.token)
					.catch(() => cfg.fallbackToken));
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
			e.preventDefault();

			fetchToken(cfg).then((token) => {
				injectToken(this, token);
				this.submit();
			});
		});
	});
})();
