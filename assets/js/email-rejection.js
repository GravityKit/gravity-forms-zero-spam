/**
 * Email Rejection Rules – vanilla JS admin UI.
 *
 * Single IIFE, no dependencies. Renders a rule-management table for the
 * global settings page (mountGlobal) and a per-field settings panel in the
 * GF form editor (mountField). Uses innerHTML + template literals for
 * rendering and event delegation for interaction.
 *
 * All user-supplied values are passed through escHtml() before insertion
 * into markup, preventing XSS. This code only runs on authenticated
 * wp-admin pages.
 *
 * @since 1.5.0
 */
( () => {
	'use strict';

	/* ---- Utilities ---- */

	/**
	 * Generate a short unique ID.
	 *
	 * @return {string}
	 */
	function uid() {
		return Date.now().toString( 36 ) + Math.random().toString( 36 ).substring( 2, 7 );
	}

	/**
	 * Escape HTML entities in a string to prevent XSS.
	 *
	 * @param {string} str Raw string.
	 *
	 * @return {string} Escaped string safe for innerHTML insertion.
	 */
	function escHtml( str ) {
		const div = document.createElement( 'div' );

		div.appendChild( document.createTextNode( str ) );

		// innerHTML escapes <, >, & but not quotes. Escape them for safe use in attributes.
		return div.innerHTML.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	/**
	 * Auto-detect rule type from a raw value string.
	 *
	 * @param {string} value The value to classify.
	 *
	 * @return {string} 'email', 'wildcard', or 'domain'.
	 */
	function detectType( value ) {
		if ( value.startsWith( '@' ) && ! value.includes( ' ' ) ) {
			return 'domain';
		}

		if ( value.includes( '@' ) ) {
			return 'email';
		}

		if ( value.includes( '*' ) ) {
			return 'wildcard';
		}

		return 'domain';
	}

	/**
	 * Return a placeholder string for the given rule type.
	 *
	 * @param {string} type Rule type.
	 *
	 * @return {string}
	 */
	function placeholder( type ) {
		const map = {
			domain: 'spamdomain.com',
			email: 'bad@site.com',
			wildcard: '*.disposable.io',
			regex: '^temp\\+.*@example\\.org$',
		};

		return map[ type ] || '';
	}

	/**
	 * Validate a value for the given rule type.
	 *
	 * @param {string} type  Rule type (domain, email, wildcard, regex).
	 * @param {string} value Trimmed value to validate.
	 * @param {Object} t     Translations object.
	 *
	 * @return {string|null} Error message, or null if valid.
	 */
	function validateValue( type, value, t ) {
		if ( type === 'email' && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value ) ) {
			return t.invalidEmail || 'Please enter a valid email address.';
		}

		if ( type === 'domain' && ! /^[^\s@]+\.[^\s@]+$/.test( value ) ) {
			return t.invalidDomain || 'Please enter a valid domain.';
		}

		if ( type === 'regex' ) {
			try {
				new RegExp( value );
			} catch ( _ex ) {
				return t.invalidRegex || 'Invalid regular expression.';
			}
		}

		return null;
	}

	/* ---- Type <select> builder ---- */

	/**
	 * Build a <select> for rule type.
	 *
	 * @param {string} selected  Currently selected type.
	 * @param {Object} t         Translations object.
	 * @param {string} ariaLabel Accessible label for the select element.
	 *
	 * @return {string} HTML string.
	 */
	function typeSelect( selected, t, ariaLabel ) {
		const types = [
			{ value: 'domain', label: t.domain || 'Domain' },
			{ value: 'email', label: t.email || 'Email' },
			{ value: 'wildcard', label: t.wildcard || 'Wildcard' },
			{ value: 'regex', label: t.regex || 'Regex' },
		];

		const opts = types.map( ( o ) => '<option value="' + o.value + '"' + ( o.value === selected ? ' selected' : '' ) + '>' + escHtml( o.label ) + '</option>' ).join( '' );

		const labelAttr = ariaLabel ? ' aria-label="' + escHtml( ariaLabel ) + '"' : '';

		return '<select class="gf-zero-spam-type-select"' + labelAttr + '>' + opts + '</select>';
	}

	/* ---- Action <select> builder ---- */

	/**
	 * Build a <select> for rule action.
	 *
	 * @param {string}  selected       Currently selected action.
	 * @param {Object}  t              Translations object.
	 * @param {boolean} blockSupported Whether the "block" action is available.
	 * @param {string}  ariaLabel      Accessible label for the select element.
	 *
	 * @return {string} HTML string.
	 */
	function actionSelect( selected, t, blockSupported, ariaLabel ) {
		const actions = [
			{ value: 'flag', label: t.flag || 'Flag as Spam' },
			{ value: 'log', label: t.log || 'Log Only' },
		];

		if ( blockSupported ) {
			actions.unshift( { value: 'block', label: t.block || 'Block' } );
		}

		const opts = actions.map( ( o ) => '<option value="' + o.value + '"' +
				( o.value === selected ? ' selected' : '' ) +
				'>' + escHtml( o.label ) + '</option>' ).join( '' );

		const labelAttr = ariaLabel ? ' aria-label="' + escHtml( ariaLabel ) + '"' : '';

		return '<select class="gf-zero-spam-action-select"' + labelAttr + '>' + opts + '</select>';
	}

	/* ================================================================
	 * RuleTable
	 * ================================================================ */

	/**
	 * Manages a list of rules and renders them into a container.
	 *
	 * @param {Object} cfg
	 * @param {Element}  cfg.container      DOM element to render into.
	 * @param {Array}    cfg.rules          Initial rules array.
	 * @param {Object}   cfg.translations   i18n strings.
	 * @param {boolean}  cfg.blockSupported Whether "block" action is available.
	 * @param {Function} cfg.onChange        Called after every mutation with the current rules array.
	 */
	function RuleTable( cfg ) {
		const container = cfg.container;
		const t = cfg.translations || {};
		const blockSupported = cfg.blockSupported;
		const onChange = cfg.onChange || (() => {});

		let rules = ( cfg.rules || [] ).slice();
		let editingId = null;

		/* ---- CRUD ---- */

		function addRule( rule ) {
			rules.push( rule );
			render();
			onChange( rules );
			focusNewValueInput();
		}

		function removeRule( id ) {
			// Find next rule to focus after removal.
			const idx = rules.findIndex( ( r ) => r.id === id );

			rules = rules.filter( ( r ) => r.id !== id );
			render();
			onChange( rules );

			// Focus the Edit button of the next row, or the value input if no rows left.
			if ( rules.length > 0 ) {
				const focusIdx = Math.min( idx, rules.length - 1 );
				const rows = container.querySelectorAll( 'tr[data-rule-id]' );

				if ( rows[ focusIdx ] ) {
					const editBtn = rows[ focusIdx ].querySelector( '[data-action="edit"]' );

					if ( editBtn ) {
						editBtn.focus();
					}
				}
			} else {
				focusNewValueInput();
			}
		}

		function updateRule( id, changes ) {
			rules = rules.map( ( r ) => r.id === id ? Object.assign( {}, r, changes ) : r );

			editingId = null;
			render();
			onChange( rules );

			// Focus the Edit button of the saved row.
			const savedRow = container.querySelector( 'tr[data-rule-id="' + id + '"]' );

			if ( savedRow ) {
				const editBtn = savedRow.querySelector( '[data-action="edit"]' );

				if ( editBtn ) {
					editBtn.focus();
				}
			}
		}

		/**
		 * Focus the add-row value input.
		 */
		function focusNewValueInput() {
			const input = container.querySelector( '[data-role="new-value"]' );

			if ( input ) {
				input.focus();
			}
		}

		function toggleRule( id ) {
			rules = rules.map( ( r ) => r.id === id ? Object.assign( {}, r, { enabled: ! r.enabled } ) : r );

			// Preserve scroll position across the re-render.
			const wrapper = container.querySelector( '.gf-zero-spam-rule-table-wrapper' );
			const scrollLeft = wrapper ? wrapper.scrollLeft : 0;

			render();
			onChange( rules );

			const restoredWrapper = container.querySelector( '.gf-zero-spam-rule-table-wrapper' );

			if ( restoredWrapper ) {
				restoredWrapper.scrollLeft = scrollLeft;
			}

			// Re-focus the toggle button on the same row.
			const row = container.querySelector( 'tr[data-rule-id="' + id + '"]' );

			if ( row ) {
				const toggleBtn = row.querySelector( '[data-action="toggle"]' );

				if ( toggleBtn ) {
					toggleBtn.focus();
				}
			}
		}

		function importBatch( newRules ) {
			rules = rules.concat( newRules );
			render();
			onChange( rules );
		}

		function getRules() {
			return rules;
		}

		/* ---- Rendering ---- */

		// All values inserted into markup are escaped via escHtml().
		function render() {
			let html = '';

			if ( ! blockSupported ) {
				if ( rules.some( ( r ) => r.action === 'block' ) ) {
					// Warn that existing block rules are inactive.
					html += '<div class="gf-zero-spam-block-notice" role="alert">' +
						escHtml( t.blockNotice || 'Some rules use the Block action, which requires Gravity Forms 2.9.15+. These rules are inactive until you update.' ) +
						'</div>';
				} else {
					// Inform that blocking is available with a newer GF version.
					html += '<div class="gf-zero-spam-block-info">' +
						escHtml( t.blockAvailable || 'Upgrading to Gravity Forms 2.9.15 or higher enables the ability to configure rules that block matching form submissions.' ) +
						'</div>';
				}
			}

			if ( rules.length > 0 ) {
				// tabindex="-1" prevents the scrollable wrapper from being a tab stop.
				html += '<div class="gf-zero-spam-rule-table-wrapper" tabindex="-1">' +
					'<table class="wp-list-table widefat striped gf-zero-spam-rule-table" aria-label="' + escHtml( t.emailRejectionRules || 'Email rejection rules' ) + '">' +
					'<thead><tr>' +
					'<th class="column-type">' + escHtml( t.type || 'Type' ) + '</th>' +
					'<th class="column-value">' + escHtml( t.value || 'Value' ) + '</th>' +
					'<th class="column-action">' + escHtml( t.action || 'Action' ) + '</th>' +
					'<th class="column-actions"><span class="screen-reader-text">' + escHtml( t.actions || 'Actions' ) + '</span></th>' +
					'</tr></thead><tbody>';

				for ( let i = 0; i < rules.length; i++ ) {
					html += renderRow( rules[ i ] );
				}

				html += '</tbody></table></div>';
			}

			// Add-row outside the scrollable wrapper so it stays visible.
			html += '<div class="gf-zero-spam-add-row">' +
				typeSelect( 'domain', t, t.type || 'Type' ) +
				'<div class="gf-zero-spam-add-row-value">' +
				'<input type="text" class="gf-zero-spam-input" data-role="new-value" placeholder="' + escHtml( placeholder( 'domain' ) ) + '" aria-label="' + escHtml( t.value || 'Value' ) + '">' +
				'</div>' +
				actionSelect( blockSupported ? 'block' : 'flag', t, blockSupported, t.action || 'Action' ) +
				'<button type="button" class="button" data-action="add" disabled>' +
				escHtml( t.addRule || 'Add Rule' ) +
				'</button>' +
				'<span class="gf-zero-spam-error gf-zero-spam-hidden" data-role="add-error" role="alert"></span>' +
				'</div>';

			container.innerHTML = html; // Safe: all dynamic values escaped via escHtml().
		}

		/**
		 * Render a single table row (display or edit mode).
		 *
		 * @param {Object} rule The rule object.
		 *
		 * @return {string}
		 */
		function renderRow( rule ) {
			const isEditing = editingId === rule.id;
			const isDisabled = rule.enabled === false;
			const showDisabledStyle = isDisabled && ! isEditing;
			const disabledClass = showDisabledStyle ? ' disabled' : '';
			const disabledAttr = showDisabledStyle ? ' aria-disabled="true"' : '';
			const toggleLabel = isDisabled ? ( t.enable || 'Enable' ) : ( t.disable || 'Disable' );

			let html = '<tr class="gf-zero-spam-rule-row' + disabledClass + '"' + disabledAttr + ' data-rule-id="' + escHtml( rule.id ) + '">';

			if ( isEditing ) {
				html += '<td class="column-type">' + typeSelect( rule.type, t, ( t.type || 'Type' ) + ': ' + rule.value ) + '</td>' +
					'<td class="column-value">' +
					'<input type="text" class="gf-zero-spam-input" data-role="edit-value" value="' + escHtml( rule.value ) + '" placeholder="' + escHtml( placeholder( rule.type ) ) + '" aria-label="' + escHtml( ( t.value || 'Value' ) + ': ' + rule.value ) + '">' +
					'<span class="gf-zero-spam-error gf-zero-spam-hidden" data-role="edit-error" role="alert"></span>' +
					'</td>' +
					'<td class="column-action">' + actionSelect( rule.action, t, blockSupported, ( t.action || 'Action' ) + ': ' + rule.value ) + '</td>' +
					'<td class="column-actions">' +
					'<button type="button" class="button button-primary" data-action="save">' + escHtml( t.save || 'Save' ) + '</button> ' +
					'<button type="button" class="button" data-action="cancel">' + escHtml( t.cancel || 'Cancel' ) + '</button>' +
					'</td>';
			} else {
				const blockInactive = rule.action === 'block' && ! blockSupported;
				const badgeClass = 'gf-zero-spam-action-badge action-' + escHtml( rule.action ) + ( blockInactive ? ' action-inactive' : '' );
				const badgeTitle = blockInactive ? ' title="' + escHtml( t.blockRequiresGF || 'Requires Gravity Forms 2.9.15+' ) + '"' : '';

				html += '<td class="column-type"><span class="gf-zero-spam-type-label">' + escHtml( rule.type ) + '</span></td>' +
					'<td class="column-value"><code class="gf-zero-spam-value">' + escHtml( rule.value ) + '</code></td>' +
					'<td class="column-action"><span class="' + badgeClass + '"' + badgeTitle + '>' + escHtml( rule.action ) + '</span></td>' +
					'<td class="column-actions"><span class="gf-zero-spam-row-actions">' +
					'<button type="button" class="gf-zero-spam-link-btn" data-action="edit" aria-label="' + escHtml( ( t.edit || 'Edit' ) + ': ' + rule.value ) + '">' + escHtml( t.edit || 'Edit' ) + '</button>' +
					'<span class="gf-zero-spam-sep" aria-hidden="true">|</span>' +
					'<button type="button" class="gf-zero-spam-link-btn" data-action="toggle" aria-label="' + escHtml( toggleLabel + ': ' + rule.value ) + '">' + escHtml( toggleLabel ) + '</button>' +
					'<span class="gf-zero-spam-sep" aria-hidden="true">|</span>' +
					'<button type="button" class="gf-zero-spam-link-btn gf-zero-spam-link-btn--delete" data-action="remove" aria-label="' + escHtml( ( t.removeRule || 'Remove' ) + ': ' + rule.value ) + '">' + escHtml( t.removeRule || 'Remove' ) + '</button>' +
					'</span></td>';
			}

			html += '</tr>';

			return html;
		}

		/* ---- Event delegation ---- */

		container.addEventListener( 'click', ( e ) => {
			const btn = e.target.closest( '[data-action]' );

			if ( ! btn ) {
				return;
			}

			const action = btn.getAttribute( 'data-action' );
			const row = btn.closest( 'tr[data-rule-id]' );
			const ruleId = row ? row.getAttribute( 'data-rule-id' ) : null;

			if ( action === 'add' ) {
				btn.blur();
				handleAdd();
			} else if ( action === 'edit' ) {
				editingId = ruleId;
				render();

				// Focus the edit value input.
				const editInput = container.querySelector( '[data-role="edit-value"]' );

				if ( editInput ) {
					editInput.focus();
				}
			} else if ( action === 'save' ) {
				handleSave( ruleId, row );
			} else if ( action === 'cancel' ) {
				const cancelledId = editingId;

				editingId = null;
				render();

				// Restore focus to the Edit button on the same row.
				const cancelledRow = container.querySelector( 'tr[data-rule-id="' + cancelledId + '"]' );

				if ( cancelledRow ) {
					const editBtn = cancelledRow.querySelector( '[data-action="edit"]' );

					if ( editBtn ) {
						editBtn.focus();
					}
				}
			} else if ( action === 'remove' ) {
				handleRemove( ruleId );
			} else if ( action === 'toggle' ) {
				toggleRule( ruleId );
			}
		} );

		container.addEventListener( 'change', ( e ) => {
			// Update placeholder when the add-row type select changes.
			const addTypeSelect = e.target.closest( '.gf-zero-spam-add-row .gf-zero-spam-type-select' );

			if ( addTypeSelect ) {
				const valueInput = container.querySelector( '[data-role="new-value"]' );

				if ( valueInput ) {
					valueInput.placeholder = placeholder( addTypeSelect.value );
				}
			}
		} );

		// Enable/disable Add button as user types.
		container.addEventListener( 'input', ( e ) => {
			if ( e.target.matches( '[data-role="new-value"]' ) ) {
				const addBtn = container.querySelector( '[data-action="add"]' );

				if ( addBtn ) {
					addBtn.disabled = e.target.value.trim().length === 0;
				}
			}

			// Enable/disable Save button when editing a rule.
			if ( e.target.matches( '[data-role="edit-value"]' ) ) {
				const row = e.target.closest( 'tr[data-rule-id]' );
				const saveBtn = row && row.querySelector( '[data-action="save"]' );

				if ( saveBtn ) {
					saveBtn.disabled = e.target.value.trim().length === 0;
				}
			}
		} );

		// Enter key in add-row value input triggers add.
		container.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' && e.target.matches( '[data-role="new-value"]' ) ) {
				e.preventDefault();
				handleAdd();
			}

			// Enter in edit-row value input triggers save.
			if ( e.key === 'Enter' && e.target.matches( '[data-role="edit-value"]' ) ) {
				e.preventDefault();

				const row = e.target.closest( 'tr[data-rule-id]' );

				if ( row ) {
					handleSave( row.getAttribute( 'data-rule-id' ), row );
				}
			}

			// Escape in edit mode cancels editing.
			if ( e.key === 'Escape' && editingId ) {
				const previousId = editingId;

				editingId = null;
				render();

				// Focus the Edit button of the row that was being edited.
				const row = container.querySelector( 'tr[data-rule-id="' + previousId + '"]' );

				if ( row ) {
					const editBtn = row.querySelector( '[data-action="edit"]' );

					if ( editBtn ) {
						editBtn.focus();
					}
				}
			}
		} );

		/* ---- Handlers ---- */

		function handleAdd() {
			const foot = container.querySelector( '.gf-zero-spam-add-row' );

			if ( ! foot ) {
				return;
			}

			const typeEl = foot.querySelector( '.gf-zero-spam-type-select' );
			const valueEl = foot.querySelector( '[data-role="new-value"]' );
			const actionEl = foot.querySelector( '.gf-zero-spam-action-select' );
			const errEl = foot.querySelector( '[data-role="add-error"]' );

			const type = typeEl.value;
			const value = valueEl.value.trim().replace( /^[,;.]+|[,;.]+$/g, '' );
			const action = actionEl.value;

			if ( ! value ) {
				return;
			}

			const error = validateValue( type, value, t );

			if ( error ) {
				errEl.textContent = error;
				errEl.classList.remove( 'gf-zero-spam-hidden' );
				return;
			}

			errEl.classList.add( 'gf-zero-spam-hidden' );

			const lowerValue = value.toLowerCase();
			const isDuplicate = rules.some( ( r ) => r.type === type && r.value.toLowerCase() === lowerValue );

			if ( isDuplicate ) {
				errEl.textContent = t.duplicateRule || 'A rule with this type and value already exists.';
				errEl.classList.remove( 'gf-zero-spam-hidden' );
				return;
			}

			const rule = {
				id: uid(),
				type: type,
				value: value,
				action: action,
				enabled: true,
			};

			addRule( rule );
		}

		function handleSave( ruleId, row ) {
			const typeEl = row.querySelector( '.gf-zero-spam-type-select' );
			const valueEl = row.querySelector( '[data-role="edit-value"]' );
			const actionEl = row.querySelector( '.gf-zero-spam-action-select' );
			const errEl = row.querySelector( '[data-role="edit-error"]' );

			const type = typeEl.value;
			const value = valueEl.value.trim().replace( /^[,;.]+|[,;.]+$/g, '' );
			const action = actionEl.value;

			const error = validateValue( type, value, t );

			if ( error ) {
				errEl.textContent = error;
				errEl.classList.remove( 'gf-zero-spam-hidden' );
				return;
			}

			// Duplicate check: exclude the rule being edited.
			const lowerValue = value.toLowerCase();
			const isDuplicate = rules.some( ( r ) => r.id !== ruleId && r.type === type && r.value.toLowerCase() === lowerValue );

			if ( isDuplicate ) {
				errEl.textContent = t.duplicateRule || 'A rule with this type and value already exists.';
				errEl.classList.remove( 'gf-zero-spam-hidden' );
				return;
			}

			updateRule( ruleId, { type: type, value: value, action: action } );
		}

		function handleRemove( ruleId ) {
			const rule = rules.find( ( r ) => r.id === ruleId );
			const label = rule ? rule.value : '';
			const msg = ( t.confirmRemove || 'Remove this rule?' ) + ( label ? ' (' + label + ')' : '' );

			if ( confirm( msg ) ) {
				removeRule( ruleId );
			}
		}

		// Initial render.
		render();

		// Public API.
		return {
			addRule: addRule,
			removeRule: removeRule,
			updateRule: updateRule,
			toggleRule: toggleRule,
			importBatch: importBatch,
			getRules: getRules,
			render: render,
		};
	}

	/* ================================================================
	 * Import Panel HTML + logic (used by mountGlobal)
	 * ================================================================ */

	/**
	 * Render and wire the import panel for the global settings page.
	 *
	 * @param {Element}   container      Parent element to append import panel into.
	 * @param {Object}    table          RuleTable instance.
	 * @param {Object}    t              Translations.
	 * @param {boolean}   blockSupported Whether block action is available.
	 */
	function renderImportPanel( container, table, t, blockSupported ) {
		let feedbackTimer = null;
		const wrap = document.createElement( 'div' );

		wrap.className = 'gf-zero-spam-import';

		// All text values are escaped. Only structural HTML is in the template.
		wrap.innerHTML =
			'<button type="button" class="button" data-import-toggle aria-expanded="false">' +
			'+ ' + escHtml( t.importRules || 'Import Rules' ) +
			'</button>' +
			'<div class="gf-zero-spam-import-panel gf-zero-spam-hidden">' +
			'<p class="description">' + escHtml( t.importDescription || 'Paste values, one per line. Auto-detected as Domain or Email type.' ) + '</p>' +
			'<textarea rows="5" class="gf-zero-spam-input" data-role="import-text" aria-label="' + escHtml( t.importRules || 'Import Rules' ) + '" placeholder="spamdomain.com&#10;bad@site.com&#10;anotherdomain.org"></textarea>' +
			'<div class="gf-zero-spam-import-footer">' +
			'<label class="gf-zero-spam-import-action">' +
			escHtml( t.action || 'Action' ) + ': ' +
			actionSelect( blockSupported ? 'block' : 'flag', t, blockSupported, t.action || 'Action' ) +
			'</label>' +
			'<button type="button" class="button" data-action="import" disabled>' +
			escHtml( t.import || 'Import' ) +
			'</button>' +
			'</div>' +
			'<span class="gf-zero-spam-import-feedback gf-zero-spam-hidden" data-role="import-feedback" role="status" aria-live="polite"></span>' +
			'</div></div>';

		container.appendChild( wrap );

		const panel = wrap.querySelector( '.gf-zero-spam-import-panel' );
		const toggleBtn = wrap.querySelector( '[data-import-toggle]' );
		const textarea = wrap.querySelector( '[data-role="import-text"]' );
		const importBtn = wrap.querySelector( '[data-action="import"]' );
		const feedbackEl = wrap.querySelector( '[data-role="import-feedback"]' );

		toggleBtn.addEventListener( 'click', () => {
			const isOpen = ! panel.classList.contains( 'gf-zero-spam-hidden' );

			panel.classList.toggle( 'gf-zero-spam-hidden', isOpen );
			toggleBtn.textContent = ( isOpen ? '+ ' : '\u2212 ' ) + ( t.importRules || 'Import Rules' );
			toggleBtn.setAttribute( 'aria-expanded', String( ! isOpen ) );
			toggleBtn.blur();
		} );

		textarea.addEventListener( 'input', () => {
			importBtn.disabled = textarea.value.trim().length === 0;
		} );

		importBtn.addEventListener( 'click', () => {
			const lines = textarea.value
				.split( /[\n,]+/ )
				.map( ( l ) => l.trim() )
				.filter( ( l ) => l.length > 0 );

			if ( lines.length === 0 ) {
				return;
			}

			const action = wrap.querySelector( '.gf-zero-spam-action-select' ).value;
			const newRules = [];
			let skipped = 0;
			let duplicates = 0;

			// Build a set of existing type+value pairs for dedup.
			const existing = new Set( table.getRules().map( ( r ) => r.type + ':' + r.value.toLowerCase() ) );

			for ( let i = 0; i < lines.length; i++ ) {
				const line = lines[ i ].replace( /^[,;.]+|[,;.]+$/g, '' );
				const type = detectType( line );
				let value = line.toLowerCase();

				// Strip leading @ from domain patterns (e.g., @example.com → example.com).
				if ( type === 'domain' && value.startsWith( '@' ) ) {
					value = value.slice( 1 );
				}

				if ( validateValue( type, value, t ) ) {
					skipped++;
					continue;
				}

				const key = type + ':' + value;

				if ( existing.has( key ) ) {
					duplicates++;
					continue;
				}

				existing.add( key );

				const rule = {
					id: uid(),
					type: type,
					value: value,
					action: action,
					enabled: true,
				};

				newRules.push( rule );
			}

			if ( newRules.length > 0 ) {
				table.importBatch( newRules );
			}

			textarea.value = '';
			importBtn.disabled = true;

			let msg = '';

			if ( newRules.length === 0 ) {
				msg = ( t.importNone || 'No valid rules found to import.' );
			} else if ( newRules.length === 1 ) {
				msg = ( t.importOne || '1 rule imported.' );
			} else {
				msg = ( t.importMany || '[count] rules imported.' ).replace( '[count]', newRules.length );
			}

			if ( skipped === 1 ) {
				msg += ' ' + ( t.importSkippedOne || 'Skipped 1 invalid value.' );
			} else if ( skipped > 1 ) {
				msg += ' ' + ( t.importSkippedMany || 'Skipped [count] invalid values.' ).replace( '[count]', skipped );
			}

			if ( duplicates === 1 ) {
				msg += ' ' + ( t.importDuplicateOne || 'Skipped 1 duplicate.' );
			} else if ( duplicates > 1 ) {
				msg += ' ' + ( t.importDuplicateMany || 'Skipped %d duplicates.' ).replace( '%d', duplicates );
			}

			feedbackEl.textContent = msg;
			feedbackEl.classList.remove( 'gf-zero-spam-hidden' );
			feedbackEl.classList.toggle( 'is-error', newRules.length === 0 );

			clearTimeout( feedbackTimer );

			feedbackTimer = setTimeout( () => {
				feedbackEl.textContent = '';
				feedbackEl.classList.add( 'gf-zero-spam-hidden' );
				feedbackEl.classList.remove( 'is-error' );
			}, 5000 );
		} );
	}

	/* ================================================================
	 * mountGlobal – Zero Spam global settings page
	 * ================================================================ */

	/**
	 * Mount the rule builder on the global settings page.
	 *
	 * @param {Object} config PHP-localized config object.
	 */
	function mountGlobal( config ) {
		const target = document.querySelector( config.targetSelector );

		if ( ! target ) {
			return;
		}

		const t = config.translations || {};
		const blockSupported = !! config.blockSupported;

		// Wrapper div.
		const wrapper = document.createElement( 'div' );

		wrapper.className = 'gf-zero-spam-rule-builder';
		target.appendChild( wrapper );

		// Table container.
		const tableContainer = document.createElement( 'div' );

		wrapper.appendChild( tableContainer );

		// Hidden input for form submission.
		const hiddenInput = document.createElement( 'input' );

		hiddenInput.type = 'hidden';
		hiddenInput.name = config.inputElementName || '_gform_setting_gf_zero_spam_email_rules';
		hiddenInput.value = JSON.stringify( config.rules || [] );
		wrapper.appendChild( hiddenInput );

		// Create table.
		const table = RuleTable( {
			container: tableContainer,
			rules: config.rules || [],
			translations: t,
			blockSupported: blockSupported,
			onChange: ( rules ) => {
				hiddenInput.value = JSON.stringify( rules );
			},
		} );

		// Import panel.
		renderImportPanel( wrapper, table, t, blockSupported );
	}

	/* ================================================================
	 * mountField – GF form editor field settings
	 * ================================================================ */

	/**
	 * Mount the per-field email rejection settings panel.
	 *
	 * @param {Object} config
	 * @param {string}   config.targetSelector  CSS selector of the mount container.
	 * @param {Object}   config.fieldSettings   { enabled, mode, rules, message }.
	 * @param {Object}   config.translations    i18n strings.
	 * @param {boolean}  config.blockSupported  Whether block action is available.
	 * @param {Function} config.onUpdate         Callback receiving the full settings object.
	 */
	function mountField( config ) {
		const target = document.querySelector( config.targetSelector );

		if ( ! target ) {
			return;
		}

		const t = config.translations || {};
		const blockSupported = config.blockSupported;
		const settingsUrl = config.settingsUrl || '';
		const settings = config.fieldSettings || { enabled: false, mode: 'inherit_add', rules: [], message: '' };
		const onUpdate = config.onUpdate || (() => {});

		// Track current state.
		let currentEnabled = settings.enabled || false;
		let currentMode = settings.mode || 'inherit_add';
		let currentMessage = settings.message || '';

		// Build outer HTML.
		target.textContent = '';

		const wrapper = document.createElement( 'div' );

		wrapper.className = 'gf-zero-spam-field-rule-builder';
		target.appendChild( wrapper );

		let table = null;

		renderFieldUI();

		/**
		 * Notify the caller with updated field settings.
		 *
		 * @param {Array|null} rulesOverride If provided, use these rules instead of querying the table.
		 */
		function notify( rulesOverride ) {
			const currentRules = rulesOverride !== undefined ? rulesOverride : ( table ? table.getRules() : settings.rules || [] );

			onUpdate( {
				enabled: currentEnabled,
				mode: currentMode,
				rules: currentRules,
				message: currentMessage,
			} );
		}

		// All text values are escaped via escHtml(). Only structural HTML is in the template.
		function renderFieldUI() {
			// Build raw HTML for the tooltip, then escape the whole thing for the aria-label attribute.
			// The browser decodes entities when reading the attribute, so jQuery UI Tooltip gets valid HTML.
			const tooltipHtml = settingsUrl
				? escHtml( t.fieldSettingsDescriptionBefore || 'Add rules to block, flag, or log submissions based on the email entered in this field. Rules can extend or replace the ' ) +
					'<a href="' + settingsUrl + '" target="_blank">' +
					escHtml( t.fieldSettingsDescriptionLink || 'global rejection rules' ) + '</a>' +
					escHtml( t.fieldSettingsDescriptionAfter || '.' )
				: escHtml( t.fieldSettingsDescription || 'Add rules to block, flag, or log submissions based on the email entered in this field. Rules can extend or replace the global rejection rules.' );

			let html = '<input type="checkbox" id="gf-zs-field-enabled" data-role="field-enabled"' + ( currentEnabled ? ' checked' : '' ) + '>' +
				'<label class="gf-zero-spam-field-toggle" for="gf-zs-field-enabled">' +
				escHtml( t.enableForField || 'Enable rejection rules' ) +
				'</label>' +
				'<button onclick="return false;" onkeypress="return false;" class="gf_tooltip tooltip" aria-label="' + escHtml( tooltipHtml ) + '">' +
				'<i class="gform-icon gform-icon--question-mark" aria-hidden="true"></i>' +
				'</button>';

			if ( currentEnabled ) {
				html += '<div class="gf-zero-spam-field-options">';

				html +=
					'<fieldset class="gf-zero-spam-mode-selector">' +
					'<legend>' + escHtml( t.ruleMode || 'Rule Mode' ) + '</legend>' +
					'<div class="gf-zero-spam-mode-option">' +
					'<input type="radio" id="gf-zs-mode-inherit" name="gf_zs_field_mode" value="inherit_add"' + ( currentMode === 'inherit_add' ? ' checked' : '' ) + '>' +
					'<label for="gf-zs-mode-inherit">' + escHtml( t.inheritAdd || 'Inherit global rules + add field-specific rules' ) + '</label>' +
					'</div>' +
					'<div class="gf-zero-spam-mode-option">' +
					'<input type="radio" id="gf-zs-mode-replace" name="gf_zs_field_mode" value="replace"' + ( currentMode === 'replace' ? ' checked' : '' ) + '>' +
					'<label for="gf-zs-mode-replace">' + escHtml( t.replace || 'Use only field-specific rules (ignore global)' ) + '</label>' +
					'</div>' +
					'</fieldset>' +
					'<div class="gf-zero-spam-field-rules" role="group" aria-labelledby="gf-zs-field-rules-heading">' +
					'<span class="gf-zero-spam-field-heading" id="gf-zs-field-rules-heading">' + escHtml( t.fieldRules || 'Field-Specific Rules' ) + '</span>' +
					'<div data-role="field-table"></div>' +
					'</div>' +
					'<div class="gf-zero-spam-field-message">' +
					'<span class="gf-zero-spam-field-heading">' + escHtml( t.validationMessage || 'Validation Message (optional)' ) + '</span>' +
					'<input type="text" class="gf-zero-spam-input" data-role="field-message" value="' + escHtml( currentMessage ) + '" placeholder="' + escHtml( t.leaveBlank || 'Leave blank to use the global default message.' ) + '">' +
					'</div></div>';
			}

			wrapper.innerHTML = html; // Safe: all dynamic values escaped via escHtml().

			// Initialize GF's jQuery UI tooltips on the newly rendered markup.
			if ( typeof window.gform_initialize_tooltips === 'function' ) {
				window.gform_initialize_tooltips();
			}

			// Mount rule table if enabled.
			if ( currentEnabled ) {
				const tableContainer = wrapper.querySelector( '[data-role="field-table"]' );

				table = RuleTable( {
					container: tableContainer,
					rules: settings.rules || [],
					translations: t,
					blockSupported: blockSupported,
					onChange: ( rules ) => {
						settings.rules = rules;
						notify( rules );
					},
				} );
			} else {
				table = null;
			}
		}

		// Event delegation on wrapper.
		wrapper.addEventListener( 'change', ( e ) => {
			if ( e.target.matches( '[data-role="field-enabled"]' ) ) {
				currentEnabled = e.target.checked;
				renderFieldUI();
				notify();
				return;
			}

			if ( e.target.matches( '[name="gf_zs_field_mode"]' ) ) {
				currentMode = e.target.value;
				notify();
			}
		} );

		wrapper.addEventListener( 'input', ( e ) => {
			if ( e.target.matches( '[data-role="field-message"]' ) ) {
				currentMessage = e.target.value;
				notify();
			}
		} );
	}

	/* ================================================================
	 * initFieldEditor – GF form editor integration
	 *
	 * Registers the email rejection setting for email fields and binds
	 * the mountField UI to GF's field-settings event. Replaces the
	 * inline <script> that was previously in PHP editor_js().
	 * ================================================================ */

	function initFieldEditor() {
		if ( typeof window.fieldSettings === 'undefined' || typeof window.gform === 'undefined' ) {
			return;
		}

		const fieldConfig = window.gfZeroSpamEmailRules_field;

		if ( ! fieldConfig ) {
			return;
		}

		// Show setting only for email fields.
		if ( typeof window.fieldSettings.email === 'string' ) {
			window.fieldSettings.email += ', .email_rejection_setting';
		} else {
			window.fieldSettings.email = '.email_rejection_setting';
		}

		// Use GF's vanilla JS hook fired right after the jQuery gform_load_field_settings event.
		gform.addAction( 'gform_post_load_field_settings', ( args ) => {
			const field = args[ 0 ];

			if ( field.type !== 'email' ) {
				return;
			}

			const settings = field.emailRejection || { enabled: false, mode: 'inherit_add', rules: [], message: '' };

			mountField( {
				targetSelector: fieldConfig.targetSelector,
				context: 'field',
				fieldSettings: settings,
				translations: fieldConfig.translations || {},
				blockSupported: fieldConfig.blockSupported,
				settingsUrl: fieldConfig.settingsUrl || '',
				onUpdate: ( updatedSettings ) => {
					// Write back to the field object so GF saves it.
					SetFieldProperty( 'emailRejection', updatedSettings );
				},
			} );
		} );
	}

	/* ================================================================
	 * Auto-init on DOMContentLoaded
	 * ================================================================ */

	function autoInit() {
		// Global settings page: mount from localized config objects.
		const keys = Object.keys( window );

		for ( let i = 0; i < keys.length; i++ ) {
			if ( ! /^gfZeroSpamEmailRules_/.test( keys[ i ] ) ) {
				continue;
			}

			const config = window[ keys[ i ] ];

			if ( ! config.targetSelector || ! document.querySelector( config.targetSelector ) ) {
				continue;
			}

			if ( config.context === 'global' ) {
				mountGlobal( config );
			}
		}

		// GF form editor: bind field settings via gform.addAction().
		initFieldEditor();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', autoInit );
	} else {
		autoInit();
	}

	/* ---- Public API ---- */

	window.GfZeroSpamEmailRules = {
		mountGlobal: mountGlobal,
		mountField: mountField,
	};
} )();
