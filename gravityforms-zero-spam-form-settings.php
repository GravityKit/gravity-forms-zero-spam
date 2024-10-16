<?php

if ( ! class_exists( 'GFForms' ) || ! is_callable( array( 'GFForms', 'include_addon_framework' ) ) ) {
	return;
}

GFForms::include_addon_framework();

/**
 * @since 1.2
 */
class GF_Zero_Spam_AddOn extends GFAddOn {

	protected $_slug = 'gf-zero-spam';
	protected $_path = GF_ZERO_SPAM_BASENAME;
	protected $_full_path = __FILE__;
	protected $_title = 'Gravity Forms Zero Spam';
	protected $_short_title = 'Zero Spam';

	const REPORT_LAST_SENT_DATE_OPTION = 'gf_zero_spam_report_last_date';

	const REPORT_CRON_HOOK_NAME = 'gf_zero_spam_send_report';

	public function init() {
		parent::init();

		add_filter( 'gform_form_settings_fields', array( $this, 'add_settings_field' ), 10, 2 );
		add_filter( 'gform_tooltips', array( $this, 'add_tooltip' ) );

		// Adding at 20 priority so anyone filtering the default priority (10) will define the default, but the
		// per-form setting may still _override_ the default.
		add_filter( 'gf_zero_spam_check_key_field', array( $this, 'filter_gf_zero_spam_check_key_field' ), 20, 2 );

		add_filter( 'gf_zero_spam_add_key_field', array( $this, 'filter_gf_zero_spam_add_key_field' ), 20 );

		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
		add_action( self::REPORT_CRON_HOOK_NAME, array( $this, 'send_report' ) );
		add_action( 'gform_after_submission', array( $this, 'after_submission' ) );
		add_action( 'gform_update_status', array( $this, 'update_status' ), 10, 2 );
	}

	/**
	 * @inheritdoc
	 * @since 1.4
	 */
	public function get_menu_icon() {
		return '<svg style="height: 28px; width: 37px; max-width: 37px" width="1358" height="1056" viewBox="0 0 1358 1056" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1058.52 262.19L767.31 478.75C729.344 507.251 683.153 522.66 635.68 522.66C588.207 522.66 542.016 507.251 504.05 478.75L212.84 262.19C204 255.619 198.133 245.806 196.529 234.909C194.924 224.012 197.714 212.925 204.285 204.085C210.856 195.245 220.669 189.378 231.566 187.774C242.463 186.169 253.55 188.959 262.39 195.53L553.6 412.13C577.276 429.899 606.078 439.505 635.68 439.505C665.282 439.505 694.084 429.899 717.76 412.13L1008.97 195.53C1013.34 192.276 1018.32 189.916 1023.6 188.585C1028.89 187.253 1034.39 186.975 1039.78 187.768C1045.17 188.56 1050.36 190.407 1055.04 193.204C1059.72 196 1063.8 199.691 1067.05 204.065C1070.31 208.439 1072.67 213.412 1074 218.698C1075.33 223.985 1075.61 229.481 1074.82 234.875C1074.02 240.269 1072.18 245.454 1069.38 250.133C1066.59 254.813 1062.89 258.896 1058.52 262.15V262.19ZM1276.96 974.8C1276.55 975.21 1276.07 975.48 1275.65 975.87C1226.01 1025.06 1159.49 1053.49 1089.62 1055.37C1019.76 1057.25 951.81 1032.45 899.59 986H189.38C84.95 986 0 901 0 796.62V189.45C0 84.99 85 0 189.38 0H1081.96C1186.43 0 1271.41 85 1271.41 189.45V580.16C1298.08 605.314 1319.45 635.55 1334.26 669.087C1349.06 702.625 1357.01 738.786 1357.63 775.442C1358.25 812.097 1351.53 848.507 1337.87 882.526C1324.21 916.546 1303.87 947.488 1278.07 973.53C1277.64 973.91 1277.37 974.39 1276.96 974.8ZM1274.6 780.04C1274.6 673.94 1188.29 587.63 1082.2 587.63C1045.54 587.597 1009.64 598.108 978.78 617.91L1244.32 883.45C1264.12 852.595 1274.63 816.7 1274.6 780.04ZM189.38 902.97H835.82C810.095 851.575 801.133 793.407 810.198 736.653C819.262 679.898 845.896 627.415 886.35 586.59C886.74 586.16 887.02 585.68 887.43 585.27C887.84 584.86 888.32 584.58 888.74 584.19C927.343 545.965 976.404 520.03 1029.73 509.658C1083.06 499.286 1138.26 504.941 1188.38 525.91V189.45C1188.35 161.235 1177.13 134.186 1157.18 114.235C1137.22 94.284 1110.17 83.0618 1081.96 83.03H189.38C161.182 83.0829 134.156 94.3155 114.227 114.265C94.2984 134.214 83.0938 161.252 83.07 189.45V796.62C83.1017 824.809 94.3111 851.835 114.24 871.772C134.169 891.708 161.191 902.928 189.38 902.97ZM1082.2 972.44C1118.86 972.467 1154.75 961.956 1185.61 942.16L920.07 676.62C900.272 707.478 889.762 743.377 889.79 780.04C889.79 886.13 976.07 972.44 1082.2 972.44Z" fill="black"/></svg>';
	}

	/**
	 * Use global and per-form settings to determine whether to check for spam.
	 *
	 * @param bool $check_key_field
	 * @param array $form
	 *
	 * @return array|mixed
	 */
	public function filter_gf_zero_spam_check_key_field( $check_key_field = true, $form = array() ) {

		// The setting has been set, but it's not enabled.
		if ( isset( $form['enableGFZeroSpam'] ) && empty( $form['enableGFZeroSpam'] ) ) {
			return false;
		}

		$enabled = $this->get_plugin_setting( 'gf_zero_spam_blocking' );

		if ( is_null( $enabled ) ) {
			return $check_key_field;
		}

		return ! empty( $enabled );
	}

	/**
	 * Use global setting to modify whether to add the spam key field to the form.
	 *
	 * @param bool $add_key_field Whether to add the spam key field to the form.
	 *
	 * @return bool Whether to add the spam key field to the form.
	 */
	public function filter_gf_zero_spam_add_key_field( $add_key_field = true ) {

		$enabled = $this->get_plugin_setting( 'gf_zero_spam_blocking' );

		// Not yet set.
		if ( is_null( $enabled ) ) {
			return $add_key_field;
		}

		return ! empty( $enabled );
	}

	/**
	 * Include custom tooltip text for the Zero Spam setting in the Form Settings page.
	 *
	 * @param array $tooltips Key/Value pair of tooltip/tooltip text.
	 *
	 * @return array
	 */
	public function add_tooltip( $tooltips ) {

		$tooltips['enableGFZeroSpam'] = esc_html__( 'Enable to fight spam using a simple, effective method that is more effective than the built-in anti-spam honeypot.', 'gravity-forms-zero-spam' );

		return $tooltips;
	}

	/**
	 * Adds the Zero Spam field to the "Form Options" settings group in GF 2.5+.
	 *
	 * @see https://docs.gravityforms.com/gform_form_settings_fields/
	 *
	 * @param array $fields Form Settings fields.
	 * @param array $form   The current form.
	 *
	 * @return array
	 */
	function add_settings_field( $fields, $form = array() ) {

		$fields['form_options']['fields'][] = array(
			'name'          => 'enableGFZeroSpam',
			'type'          => 'toggle',
			'label'         => esc_html__( 'Prevent spam using Gravity Forms Zero Spam', 'gravity-forms-zero-spam' ),
			'tooltip'       => gform_tooltip( 'enableGFZeroSpam', '', true ),
			'default_value' => apply_filters( 'gf_zero_spam_check_key_field', true, $form ),
		);

		return $fields;
	}

	/**
	 * Register addon global settings.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {

		$spam_report_description = sprintf( '<h3 style="margin-top: 0">%s</h3><p>%s</p><p><strong>%s</strong></p><p>%s</p><hr style="margin: 1em 0;">',
			esc_html__( 'Know when entries are flagged as spam.', 'gravity-forms-zero-spam' ),
			esc_html__( 'It can be hard to know when entries are being marked as spam. When enabled, this feature will send an automated spam report, giving you a summary of recent spam entries. If no spam entries have been submitted, no report will be sent.', 'gravity-forms-zero-spam' ),
			esc_html__( 'This feature works with any spam filter, including the Gravity Forms spam honeypot, Gravity Forms Zero Spam, reCAPTCHA, or others.', 'gravity-forms-zero-spam' ),
			strtr( esc_html__( 'Note: Depending on site traffic, time-based reports may not always be sent at the scheduled frequency. {{link}}See how to set up a "cron" to make this more reliable{{/link}}.', 'gravity-forms-zero-spam' ), array(
				'{{heading}}' => 'Spam Report',
				'{{link}}'    => '<a href="https://deliciousbrains.com/wp-offload-ses/doc/cron-setup/" rel="nofollow noopener noreferrer" target="_blank"><span class="screen-reader-text">' . esc_html__( 'Link opens in a new tab', 'gravity-forms-zero-spam' ) . '</span>',
				'{{/link}}'   => '</a>',
			) )
		);

		// translators: Do not translate the placeholders inside the curly brackets, like this {{placeholders}}.
		$email_body = '<h2>' . esc_html_x( 'Gravity Forms Spam Report', 'The heading inside the email body.', 'gravity-forms-zero-spam' ) . '</h2>';
		// translators: Do not translate the placeholders inside the curly brackets, like this {{placeholders}}.
		$email_body .= wpautop( esc_html__( 'You have received {{total_spam_count}} spam entries from the following form(s):', 'gravity-forms-zero-spam' ) );
		$email_body .= '{{spam_report_list}}';
		// translators: Do not translate the placeholders inside the curly brackets, like this {{placeholders}}.
		$email_body .= wpautop( '<em>' . esc_html__( 'To modify or disable this email, visit {{settings_link}}the Gravity Forms Zero Spam settings page{{/settings_link}}.', 'gravity-forms-zero-spam' ) . '</em>' );

		$available_variables_message = wpautop( esc_html__( 'The following variables may be used:', 'gravity-forms-zero-spam' ) );
		$available_variables_message .= '<ul class="ul-disc" style="margin-bottom: 1em;">';
		$available_variables_message .= '<li style="list-style: disc;"><code>{{site_name}}</code> - ' . esc_html__( 'The name of this website', 'gravity-forms-zero-spam' ) . '</li>';
		$available_variables_message .= '<li style="list-style: disc;"><code>{{admin_email}}</code> - ' . esc_html__( 'The email of the site administrator', 'gravity-forms-zero-spam' ) . '</li>';
		$available_variables_message .= '<li style="list-style: disc;"><code>{{total_spam_count}}</code> - ' . esc_html__( 'The total number of spam emails received since the last report.', 'gravity-forms-zero-spam' ) . '</li>';
		$available_variables_message .= '<li style="list-style: disc;"><code>{{spam_report_list}}</code> - ' . esc_html__( 'A list of forms and the number of spam entries since the last report.', 'gravity-forms-zero-spam' ) . '</li>';
		$available_variables_message .= '<li style="list-style: disc;"><code>{{settings_link}}</code> and <code>{{/settings_link}}</code> - ' . esc_html__( 'A link to the plugin settings page. Text inside the variables will be the link text. Make sure to include both the opening and closing variables.', 'gravity-forms-zero-spam' ) . '</li>';
		$available_variables_message .= '</ul>';

		return array(
			array(
				'title'       => esc_html__( 'Spam Blocking', 'gravity-forms-zero-spam' ),
				'description' => esc_html__( 'Enable to fight spam using a simple, effective method that is more effective than the built-in anti-spam honeypot.', 'gravity-forms-zero-spam' ) . ' ' . esc_html__( 'It is possible to enable or disable spam blocking on a per-form basis inside each form\'s settings.', 'gravity-forms-zero-spam' ),
				'fields'      => array(
					array(
						'label'         => esc_html__( 'Enable Zero Spam by Default', 'gravity-forms-zero-spam' ),
						'type'          => 'radio',
						'name'          => 'gf_zero_spam_blocking',
						'default_value' => '1',
						'choices'       => array(
							array(
								'label' => __( 'Enabled: Add Zero Spam to Gravity Forms forms', 'gravity-forms-zero-spam' ),
								'value' => '1',
							),
							array(
								'label' => __( 'Disabled: Use Gravity Forms\' built-in spam prevention', 'gravity-forms-zero-spam' ),
								'value' => '0',
							),
						),
						'required'      => false,
					),
				),
			),
			array(
				'title'       => esc_html__( 'Spam Report Email', 'gravity-forms-zero-spam' ),
				'description' => $spam_report_description,
				'fields'      => array(
					array(
						'label'         => esc_html__( 'Spam Report Frequency', 'gravity-forms-zero-spam' ),
						// translators: Do not translate the placeholders inside the curly brackets, like this {{placeholders}}.
						'description'   => wpautop( esc_html__( 'How frequently should spam report emails be sent?', 'gravity-forms-zero-spam' ) ),
						'type'          => 'radio',
						'name'          => 'gf_zero_spam_email_frequency',
						'value'         => '',
						'choices'       => array(
							array(
								'label' => __( 'Disabled', 'gravity-forms-zero-spam' ),
								'value' => '',
							),
							array(
								'label' => __( 'Threshold-Based', 'gravity-forms-zero-spam' ),
								'value' => 'entry_limit',
							),
							array(
								'label' => __( 'Twice Daily', 'gravity-forms-zero-spam' ),
								'value' => 'twicedaily',
							),
							array(
								'label' => __( 'Daily', 'gravity-forms-zero-spam' ),
								'value' => 'daily',
							),
							array(
								'label' => __( 'Weekly', 'gravity-forms-zero-spam' ),
								'value' => 'weekly',
							),
							array(
								'label' => __( 'Monthly', 'gravity-forms-zero-spam' ),
								'value' => 'monthly',
							),
						),
						'required'      => false,
						'save_callback' => function ( $field, $value ) {
							return $this->update_cron_job( $value );
						},
					),

					array(
						'label'               => esc_html__( 'Spam Entry Threshold', 'gravity-forms-zero-spam' ),
						'description'         => esc_html__( 'A spam report email will be sent when the specified number of spam entries is reached.', 'gravity-forms-zero-spam' ),
						'type'                => 'text',
						'input_type'          => 'number',
						'min'                 => 1,
						'value'               => 10,
						'step'                => 1,
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
						'validation_callback' => function ( $field, $value ) {
							if ( (int) $value < 1 ) {
								$field->set_error( esc_html__( 'Entry limit has to be 1 or more.', 'gravity-forms-zero-spam' ) );
							}
						},
					),

					array(
						'label'               => esc_html__( 'Email Address', 'gravity-forms-zero-spam' ),
						'description'         => esc_html__( 'Send spam report to this email address.', 'gravity-forms-zero-spam' ),
						'type'                => 'text',
						'input_type'          => 'email',
						'value'               => '{{admin_email}}',
						'name'                => 'gf_zero_spam_report_email',
						'required'            => true,
						'validation_callback' => function ( $field, $value ) {
							if ( is_email( $value ) || '{{admin_email}}' === $value ) {
								return;
							}
							$field->set_error( esc_html__( 'The email entered is invalid.', 'gravity-forms-zero-spam' ) );
						},
						'dependency'          => array(
							'live'   => true,
							'fields' => array(
								array(
									'field' => 'gf_zero_spam_email_frequency',
								),
							),
						),
					),

					array(
						'name'       => 'gf_zero_spam_subject',
						'label'      => esc_html__( 'Email Subject', 'gravity-forms-zero-spam' ),
						'type'       => 'text',
						'value'      => esc_html__( 'Your Gravity Forms spam report for {{site_name}}', 'gravity-forms-zero-spam' ),
						'required'   => true,
						'dependency' => array(
							'live'   => true,
							'fields' => array(
								array(
									'field' => 'gf_zero_spam_email_frequency',
								),
							),
						),
					),
					array(
						'name'        => 'gf_zero_spam_message',
						'label'       => esc_html__( 'Email Message', 'gravity-forms-zero-spam' ),
						'description' => $available_variables_message,
						'type'        => 'textarea',
						'value'       => trim( $email_body ),
						'use_editor'  => true,
						'required'    => true,
						'dependency'  => array(
							'live'   => true,
							'fields' => array(
								array(
									'field' => 'gf_zero_spam_email_frequency',
								),
							),
						),
					),
					array(
						'name'          => 'gf_zero_spam_test_email',
						'type'          => 'hidden',
						'value'         => '',
						'save_callback' => function ( $field, $value ) {
							if ( empty( $value ) ) {
								return;
							}

							$this->send_report( array(), true );
						},
					),
					array(
						'name'    => 'gf_zero_spam_test_email_button',
						'type'    => 'button',
						'label'   => esc_html__( 'Send Test Email & Save Settings', 'gravity-forms-zero-spam' ),
						'value'   => esc_html__( 'Send Email & Save Settings', 'gravity-forms-zero-spam' ),
						'class'   => 'button',
						'onclick' => 'jQuery( "#gf_zero_spam_test_email" ).val( "1" ); jQuery( "#gform-settings-save" ).click();',
					),
				),
			),
		);

	}

	/**
	 * Check if entry limit has been reached after status update.
	 *
	 * @since 1.4
	 *
	 * @param int    $entry_id       The entry ID.
	 * @param string $property_value The new status.
	 *
	 * @return void
	 */
	public function update_status( $entry_id, $property_value ) {

		if ( $property_value !== 'spam' ) {
			return;
		}

		$this->check_entry_limit();
	}

	/**
	 * Check if entry limit has been reached after submission.
	 *
	 * @since 1.4
	 *
	 * @param array $entry The entry object.
	 * @param array $form  The form object.
	 *
	 * @return void
	 */
	public function after_submission( $entry ) {

		if ( $entry['status'] !== 'spam' ) {
			return;
		}

		$this->check_entry_limit();
	}

	/**
	 * Check if entry limit has been reached.
	 *
	 * @return bool
	 */
	public function check_entry_limit( $send_report = true ) {
		$frequency = $this->get_plugin_setting( 'gf_zero_spam_email_frequency' );

		if ( $frequency !== 'entry_limit' ) {
			return false;
		}

		$results = $this->get_latest_spam_entries();
		$limit   = $this->get_plugin_setting( 'gf_zero_spam_entry_limit' );

		if ( $limit && count( $results ) < (int) $limit ) {
			return false;
		}

		if ( $send_report ) {
			$this->send_report( $results );
		}

		return true;
	}

	/**
	 * Add monthly intervals to existing cron schedules.
	 *
	 * @param array $schedules
	 *
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {

		if ( isset( $schedules['monthly'] ) ) {
			return $schedules;
		}

		$schedules['monthly'] = array(
			'interval' => MONTH_IN_SECONDS,
			'display'  => __( 'Once Monthly', 'gravity-forms-zero-spam' ),
		);

		return $schedules;
	}

	/**
	 * Send spam report.
	 *
	 * @return boolean
	 */
	public function send_report( $results = array(), $is_test = false ) {

		// When called from cron, $results will be empty.
		if ( empty( $results ) ) {
			$results = $this->get_latest_spam_entries();
		}

		if ( empty( $results ) && ! $is_test ) {
			return false;
		}

		if ( $is_test ) {
			$settings = $this->get_posted_settings();
		} else {
			$settings = $this->get_plugin_settings();
		}

		$email = rgar( $settings, 'gf_zero_spam_report_email' );

		if ( empty( $email ) ) {
			return false;
		}

		$email = $this->replace_tags( $email );

		if ( ! is_email( $email ) ) {
			return false;
		}

		$subject = rgar( $settings, 'gf_zero_spam_subject' );
		$message = rgar( $settings, 'gf_zero_spam_message' );

		if ( $subject === '' || $message === '' ) {
			return false;
		}

		$subject = $this->replace_tags( $subject );
		$message = $this->replace_tags( $message );
		$message = wpautop( $message );

		$headers = array( 'Content-type' => 'Content-type: text/html; charset=' . esc_attr( get_option( 'blog_charset' ) ) );
		$success = wp_mail( $email, $subject, $message, $headers );

		// Don't log or update last sent date when sending test email.
		if ( $is_test ) {
			return $success;
		}

		if ( $success ) {
			$this->log_debug( __METHOD__ . '(): Spam report email sent successfully.' );
			update_option( self::REPORT_LAST_SENT_DATE_OPTION, current_time( 'timestamp', 1 ) );
		} else {
			$this->log_error( __METHOD__ . '(): Spam report email failed to send.' );
		}

		return false;
	}

	/**
	 * Replace tags in email template.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	private function replace_tags( $value = '' ) {
		$replace = array(
			'{{site_name}}'        => get_bloginfo( 'name' ),
			'{{admin_email}}'      => get_bloginfo( 'admin_email' ),
			'{{total_spam_count}}' => (string) $this->get_spam_count(),
			'{{spam_report_list}}' => $this->get_report_list(),
			'{{settings_link}}'    => '<a href="' . esc_url( admin_url( 'admin.php?page=gf_settings&subview=gf-zero-spam' ) ) . '">',
			'{{/settings_link}}'   => '</a>',
		);

		return strtr( $value, $replace );
	}

	/**
	 * Get latest spam entries.
	 *
	 * @return array
	 */
	private function get_latest_spam_entries() {
		global $wpdb;

		$sql = $wpdb->prepare( "SELECT `id`, `form_id` FROM {$wpdb->prefix}gf_entry WHERE `status`=%s AND `date_created` >= %s ORDER BY `form_id`", 'spam', $this->get_last_report_date( 'Y-m-d H:i:s' ) );

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Returns the date the last report was set.
	 *
	 * @param string $date_format Date format to return.
	 *
	 * @return string Date in format passed by $date_format.
	 */
	private function get_last_report_date( $date_format = 'Y-m-d' ) {

		$last_report_timestamp = get_option( self::REPORT_LAST_SENT_DATE_OPTION, current_time( 'timestamp', 1 ) );

		return gmdate( $date_format, $last_report_timestamp );
	}

	/**
	 * Get report list.
	 *
	 * @return string HTML list of spam entries.
	 */
	private function get_report_list() {

		$results = $this->get_latest_spam_entries();

		if ( empty( $results ) ) {
			return '';
		}

		$counted_results = array();
		foreach ( $results as $result ) {
			if ( isset( $counted_results[ $result['form_id'] ] ) ) {
				$counted_results[ $result['form_id'] ] ++;
			} else {
				$counted_results[ $result['form_id'] ] = 1;
			}
		}

		$last_date = $this->get_last_report_date( 'Y-m-d' );

		$results_output = array();
		foreach ( $counted_results as $form_id => $count ) {

			$form_info = GFFormsModel::get_form( $form_id );

			// Don't include forms that are in the trash.
			if ( ! $form_info ) {
				continue;
			}

			$args = array(
				'id'     => $form_id,
				'filter' => 'spam',
			);

			// If last report date is set, the link will go to spam entries submitted after that date.
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

			$count = (int) $count;

			$results_output[] = strtr( '{{form_link}}: {{count}} {{new_entries}}', array(
				'{{form_link}}'   => '<a href="' . esc_url( $link ) . '">' . esc_html( $form_info->title ) . '</a>',
				'{{count}}'       => $count,
				'{{new_entries}}' => _n( 'spam entry', 'spam entries', $count, 'gravity-forms-zero-spam' ),
			) );
		}

		$output = '<ul><li>' . implode( '</li><li>', $results_output ) . '</li></ul>';

		return $output;
	}

	/**
	 * Get spam count.
	 *
	 * @return int $count The number of spam entries since the last report was sent.
	 */
	private function get_spam_count() {
		$results = $this->get_latest_spam_entries();

		return count( $results );
	}

	/**
	 * Add cron job for spam reporting.
	 *
	 * @param string $frequency The frequency of the cron job.
	 *
	 * @return string
	 */
	public function update_cron_job( $frequency ) {

		// Always remove the existing cron job.
		wp_clear_scheduled_hook( self::REPORT_CRON_HOOK_NAME );

		if ( empty( $frequency ) ) {
			return $frequency;
		}

		if ( $frequency === 'entry_limit' ) {
			return $frequency;
		}

		wp_schedule_event( time(), $frequency, self::REPORT_CRON_HOOK_NAME );

		return $frequency;
	}

}

new GF_Zero_Spam_AddOn;
