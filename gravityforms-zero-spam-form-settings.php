<?php

if ( ! class_exists( 'GFForms' ) || ! is_callable( array( 'GFForms', 'include_addon_framework' ) ) ) {
	return;
}

GFForms::include_addon_framework();

/**
 * @since 1.2
 */
class GF_Zero_Spam_AddOn extends GFAddOn {

	protected $_slug        = 'gf-zero-spam';
	protected $_path        = GF_ZERO_SPAM_BASENAME;
	protected $_full_path   = __FILE__;
	protected $_title       = 'Gravity Forms Zero Spam';
	protected $_short_title = 'Zero Spam';

	public function init() {
		parent::init();

		add_filter( 'gform_form_settings_fields', array( $this, 'add_settings_field' ), 10, 2 );
		add_filter( 'gform_tooltips', array( $this, 'add_tooltip' ) );

		// Adding at 20 priority so anyone filtering the default priority (10) will define the default, but the
		// per-form setting may still _override_ the default.
		add_filter( 'gf_zero_spam_check_key_field', array( $this, 'filter_gf_zero_spam_check_key_field' ), 20, 2 );

		add_filter( 'cron_schedules', array( $this, 'add_monthly_schedule' ) );
		add_action( 'gf_zero_spam_send_report', array( $this, 'send_report' ) );
		add_action( 'gform_after_submission', array( $this, 'check_entry_limit' ), 10, 2 );

	}

	/**
	 * Use per-form settings to determine whether to check for spam.
	 *
	 * @param bool  $check_key_field
	 * @param array $form
	 *
	 * @return array|mixed
	 */
	public function filter_gf_zero_spam_check_key_field( $check_key_field = true, $form = array() ) {

		// The setting has been set, but it's not enabled.
		if ( isset( $form['enableGFZeroSpam'] ) && empty( $form['enableGFZeroSpam'] ) ) {
			return false;
		}

		return $check_key_field;
	}

	/**
	 * Include custom tooltip text for the Zero Spam setting in the Form Settings page
	 *
	 * @param array $tooltips Key/Value pair of tooltip/tooltip text
	 *
	 * @return array
	 */
	public function add_tooltip( $tooltips ) {

		$tooltips['enableGFZeroSpam'] = esc_html__( 'Enable to fight spam using a simple, effective method that is more effective than the built-in anti-spam honeypot.', 'gf-zero-spam' );

		return $tooltips;
	}

	/**
	 * Adds the Zero Spam field to the "Form Options" settings group in GF 2.5+
	 *
	 * @see https://docs.gravityforms.com/gform_form_settings_fields/
	 *
	 * @param array $fields Form Settings fields.
	 * @param array $form The current form
	 *
	 * @return array
	 */
	function add_settings_field( $fields, $form = array() ) {

		$fields['form_options']['fields'][] = array(
			'name'          => 'enableGFZeroSpam',
			'type'          => 'toggle',
			'label'         => esc_html__( 'Prevent spam using Gravity Forms Zero Spam', 'gf-zero-spam' ),
			'tooltip'       => gform_tooltip( 'enableGFZeroSpam', '', true ),
			'default_value' => apply_filters( 'gf_zero_spam_check_key_field', true, $form ),
		);

		return $fields;
	}

	/**
	 * Logging is not currently supported.
	 *
	 * @param array $plugins An array of plugins that support logging.
	 *
	 * @return array
	 */
	public function set_logging_supported( $plugins ) {

		return $plugins;
	}


	/**
	 * Register addon global settings
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		$email_body = '
			<h2>Spam report</h2>
			You have received {{total_spam_count}} spam emails across the following forms:
			{{spam_report_list}}
			To turn off this message, visit {{settings_url}}.
		';
		return array(
			array(
				'title'  => esc_html__( 'Gravity Forms Zero Spam', 'gf-zero-spam' ),
				'fields' => array(
					array(
						'label'         => esc_html__( 'Email Frequency', 'gf-zero-spam' ),
						'description'   => esc_html__( 'How frequently should spam report emails be sent.', 'gf-zero-spam' ),
						'type'          => 'select',
						'name'          => 'gf_zero_spam_email_frequency',
						'choices'       => array(
							array(
								'label' => __( 'Choose One', 'gf-zero-spam' ),
								'value' => '',
							),
							array(
								'label' => __( 'Daily', 'gf-zero-spam' ),
								'value' => 'daily',
							),
							array(
								'label' => __( 'Weekly', 'gf-zero-spam' ),
								'value' => 'weekly',
							),
							array(
								'label' => __( 'Monthly', 'gf-zero-spam' ),
								'value' => 'monthly',
							),
							array(
								'label' => __( 'Entry Limit', 'gf-zero-spam' ),
								'value' => 'entry_limit',
							),
						),
						'required'      => true,
						'save_callback' => function( $field, $value ) {
							return $this->add_cron_job( $value );
						},
					),
					array(
						'label'               => esc_html__( 'Entry Limit', 'gf-zero-spam' ),
						'description'         => esc_html__( 'A spam report email will be sent when the number of spam messages reaches this number.', 'gf-zero-spam' ),
						'type'                => 'text',
						'input_type'          => 'number',
						'min'                 => 1,
						'value'               => 1,
						'name'                => 'gf_zero_spam_entry_limit',
						'dependency'          => array(
							'live'   => true,
							'fields' => array(
								array(
									'field'  => 'gf_zero_spam_email_frequency',
									'values' => array( 'entry_limit' ),
								),
							),
						),
						'validation_callback' => function( $field, $value ) {
							if ( (int) $value < 1 ) {
								$field->set_error( esc_html__( 'Entry limit has to be 1 or more.', 'gf-zero-spam' ) );
							}
						},
					),

					array(
						'label'               => esc_html__( 'Spam Report Email', 'gf-zero-spam' ),
						'description'         => esc_html__( 'Send spam report to this email address.', 'gf-zero-spam' ),
						'type'                => 'text',
						'input_type'          => 'email',
						'value'               => get_bloginfo( 'admin_email' ),
						'name'                => 'gf_zero_spam_report_email',
						'required'            => true,
						'validation_callback' => function( $field, $value ) {
							if ( ! is_email( $value ) ) {
								$field->set_error( esc_html__( 'Email is invalid.', 'gf-zero-spam' ) );
							}
						},

					),

					array(
						'name'     => 'gf_zero_spam_subject',
						'label'    => esc_html__( 'Subject', 'gf-zero-spam' ),
						'type'     => 'text',
						'value'    => 'Your Gravity Forms spam report for {{site_name}}',
						'required' => true,
					),
					array(
						'name'       => 'gf_zero_spam_message',
						'label'      => esc_html__( 'Message', 'gf-zero-spam' ),
						'type'       => 'textarea',
						'value'      => trim( $email_body ),
						'use_editor' => true,
						'required'   => true,
					),

				),
			),

		);

	}

	/**
	 * Check if entry limit has been reached.
	 *
	 * @param array $entry
	 * @param array $form
	 * @return void
	 */
	public function check_entry_limit( $entry, $form ) {
		if ( $entry['status'] !== 'spam' ) {
			return;
		}

		if ( ! isset( $form['enableGFZeroSpam'] ) || (int) $form['enableGFZeroSpam'] === 0 ) {
			return;
		}

		$freq  = $this->get_plugin_setting( 'gf_zero_spam_email_frequency' );
		$limit = (int) $this->get_plugin_setting( 'gf_zero_spam_entry_limit' );
		if ( $freq !== 'entry_limit' || $limit <= 0 ) {
			return;
		}

		$results = $this->get_latest_spam_entries();
		if ( count( $results ) >= $limit ) {
			$this->send_report();
		}

	}

	/**
	 * Add monthly interval to schedules.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public function add_monthly_schedule( $schedules ) {

		$schedules['monthly'] = array(
			'interval' => 2635200,
			'display'  => __( 'Once a month', 'gf-zero-spam' ),
		);

		return $schedules;
	}

	/**
	 * Send spam report.
	 *
	 * @return boolean
	 */
	public function send_report() {
		$email = $this->get_plugin_setting( 'gf_zero_spam_report_email' );

		if ( ! is_email( $email ) ) {
			return;
		}

		$subject = $this->get_plugin_setting( 'gf_zero_spam_subject' );
		$message = $this->get_plugin_setting( 'gf_zero_spam_message' );

		if ( $subject == '' || $message == '' ) {
			return;
		}

		$results = $this->get_latest_spam_entries();
		if ( empty( $results ) ) {
			return $output;
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$success = wp_mail( $email, $this->replace_tags( $subject ), $this->replace_tags( $message ), $headers );
		if ( $success ) {
			update_option( 'gv_zero_spam_report_last_date', current_time( 'Y-m-d H:i:s' ) );
		}

		return false;
	}

	/**
	 * Replace tags in email template.
	 *
	 * @param string $value
	 * @return string
	 */
	private function replace_tags( $value ) {
		$replace = array(
			'{{site_name}}'        => get_bloginfo( 'name' ),
			'{{total_spam_count}}' => $this->get_spam_count(),
			'{{spam_report_list}}' => $this->get_report_list(),
			'{{settings_url}}'     => admin_url( 'admin.php?page=gf_settings&subview=gf-zero-spam' ),
		);

		foreach ( $replace as $tag => $val ) {
			$value = str_replace( $tag, $val, $value );
		}

		return $value;
	}

	/**
	 * Get latest spam entries.
	 *
	 * @return array
	 */
	private function get_latest_spam_entries() {
		global $wpdb;
		$last_date = ( get_option( 'gv_zero_spam_report_last_date' ) ? get_option( 'gv_zero_spam_report_last_date' ) : date( 'Y-m-d', 0 ) );

		return $wpdb->get_results( $wpdb->prepare( "SELECT id,form_id FROM {$wpdb->prefix}gf_entry WHERE status=%s AND date_created > %s ORDER BY form_id", 'spam', $last_date ), ARRAY_A );
	}

	/**
	 * Get report list.
	 *
	 * @return void
	 */
	private function get_report_list() {
		$results = $this->get_latest_spam_entries();

		$output = '';
		if ( empty( $results ) ) {
			return $output;
		}

		$counted_results = array();
		foreach ( $results as $result ) {
			if ( isset( $counted_results[ $result['form_id'] ] ) ) {
				$counted_results[ $result['form_id'] ]++;
			} else {
				$counted_results[ $result['form_id'] ] = 1;
			}
		}

		$last_date = ( get_option( 'gv_zero_spam_report_last_date' ) ? get_option( 'gv_zero_spam_report_last_date' ) : '' );

		$output .= '<ul>';
		foreach ( $counted_results as $form_id => $count ) {
			$form = GFAPI::get_form( $form_id );

			if ( ! isset( $form['enableGFZeroSpam'] ) || (int) $form['enableGFZeroSpam'] === 0 ) {
				continue;
			}

			$args = array(
				'id'     => $form_id,
				'filter' => 'spam',
			);

			if ( $last_date ) {
				$args['s']        = $last_date;
				$args['field_id'] = 'date_created';
				$args['orderby']  = '0';
				$args['order']    = 'ASC';
				$args['operator'] = '>';
			}

			$link = add_query_arg(
				$args,
				admin_url( 'admin.php?page=gf_entries&view=entries' )
			);

			$output .= '<li><a href="' . $link . '">' . esc_html( $form['title'] ) . ' ' . (int) $count . '</a></li>';
		}

		$output .= '</ul>';
		return $output;
	}

	/**
	 * Get spam count.
	 *
	 * @return boolean
	 */
	private function get_spam_count() {
		$results = $this->get_latest_spam_entries();

		return count( $results );
	}

	/**
	 * Add cron job for spam reporting.
	 *
	 * @param string $frequency
	 * @return string
	 */
	public function add_cron_job( $frequency ) {
		if ( empty( $frequency ) ) {
			return $frequency;
		}

		wp_clear_scheduled_hook( 'gf_zero_spam_send_report' );

		if ( $frequency === 'entry_limit' ) {
			return $frequency;
		}

		wp_schedule_event( time(), $frequency, 'gf_zero_spam_send_report' );

		return $frequency;
	}


}

new GF_Zero_Spam_AddOn();
