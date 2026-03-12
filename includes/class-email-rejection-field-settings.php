<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Registers per-field email rejection settings in the GF form editor
 * and enqueues the FieldRuleBuilder UI.
 *
 * @since 1.5.0
 */
class GF_Zero_Spam_Email_Rejection_Field_Settings {

	/**
	 * Initializes hooks.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'gform_field_advanced_settings', [ $this, 'render_field_settings' ], 50, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );
	}

	/**
	 * Renders the email rejection settings HTML in the field editor.
	 *
	 * @since 1.5.0
	 *
	 * @param int $position The settings group position.
	 * @param int $form_id  The form ID.
	 *
	 * @return void
	 */
	public function render_field_settings( $position, $form_id ) {
		// Position 50 = after Admin Label.
		if ( 50 !== $position ) {
			return;
		}

		?>
		<li class="email_rejection_setting field_setting" style="display: none;">
			<div id="gf-zero-spam-field-rule-builder"></div>
		</li>
		<?php
	}

	/**
	 * Enqueues assets on the form editor page.
	 *
	 * @since 1.5.0
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets() {
		if ( ! $this->is_form_editor() ) {
			return;
		}

		$plugin_dir = plugin_dir_url( __DIR__ );
		$version    = GF_Zero_Spam_Email_Rejection_Settings::get_asset_version();

		wp_enqueue_script(
			'gf-zero-spam',
			$plugin_dir . 'dist/js/gf-zero-spam-admin.js',
			[],
			$version,
			true
		);

		wp_enqueue_style(
			'gf-zero-spam',
			$plugin_dir . 'dist/css/gf-zero-spam.css',
			[],
			$version
		);

		wp_localize_script(
            'gf-zero-spam',
            'gfZeroSpamEmailRules_field',
            [
				'targetSelector' => '#gf-zero-spam-field-rule-builder',
				'blockSupported' => GF_Zero_Spam_Email_Rejection::is_block_supported(),
				'settingsUrl'    => admin_url( 'admin.php?page=gf_settings&subview=gf-zero-spam#gform-settings-section-email-rejection-rules' ),
				'translations'   => GF_Zero_Spam_Email_Rejection_Settings::get_translations(),
			]
        );
	}

	/**
	 * Checks if we're on the form editor page.
	 *
	 * @since 1.5.0
	 *
	 * @return bool
	 */
	private function is_form_editor() {
		return is_admin()
			&& rgget( 'page' ) === 'gf_edit_forms'
			&& rgget( 'view' ) !== 'settings';
	}
}
