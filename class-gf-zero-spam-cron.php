<?php
/**
 * Gravity Forms Zero Spam Cron Handler
 *
 * Handles all cron-related functionality for the spam report feature.
 *
 * @package GravityFormsZeroSpam
 * @since 1.4.7
 * @version 1.5.0
 */

// Exit if accessed directly.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class GF_Zero_Spam_Cron
 *
 * Manages all cron scheduling and execution for spam reports.
 *
 * @since 1.4.7
 */
class GF_Zero_Spam_Cron {

	/**
	 * The cron hook name for spam reports.
	 *
	 * @since 1.4.7
	 * @var string
	 */
	const CRON_HOOK = 'gf_zero_spam_send_report';

	/**
	 * Option key for storing the last sent date.
	 *
	 * @since 1.4.7
	 * @var string
	 */
	const LAST_SENT_OPTION = 'gf_zero_spam_report_last_date';

	/**
	 * Singleton instance.
	 *
	 * @since 1.4.7
	 * @var GF_Zero_Spam_Cron|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.4.7
	 *
	 * @return GF_Zero_Spam_Cron
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the cron handler.
	 *
	 * Registers cron action immediately (not on init hook) to ensure availability
	 * during wp-cron.php execution, especially with alternate cron systems like WP Engine.
	 *
	 * @since 1.4.7
	 * @since 1.5.0 Moved cron action registration to constructor for immediate availability.
	 */
	private function __construct() {
		// Register the cron handler immediately on every request, like GFForms::cron() does.
		// This ensures the handler is available when wp-cron.php runs, especially with
		// alternate cron systems (WP Engine, etc.) that may execute before init hook.
		add_action( self::CRON_HOOK, array( $this, 'execute_cron' ) );

		// Register custom cron schedules.
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );
	}

	/**
	 * Execute the cron job.
	 *
	 * @since 1.4.7
	 *
	 * @return void
	 */
	public function execute_cron() {
		$this->log_debug( 'Starting cron execution for spam report.' );

		// Ensure the addon class is loaded.
		if ( ! class_exists( 'GF_Zero_Spam_AddOn' ) ) {
			$addon_file = plugin_dir_path( __FILE__ ) . 'gravityforms-zero-spam-form-settings.php';
			if ( ! is_readable( $addon_file ) ) {
				$this->log_error( 'Could not load addon file for cron execution.' );
				return;
			}
			require_once $addon_file;
		}

		// Get the addon instance and send report.
		$addon = GF_Zero_Spam_AddOn::get_instance();
		if ( ! $addon || ! method_exists( $addon, 'send_report' ) ) {
			$this->log_error( 'Could not execute send_report - addon or method not available.' );
			return;
		}

		$this->log_debug( 'Executing send_report via cron.' );

		$addon->send_report();
	}

	/**
	 * Add custom cron schedules.
	 *
	 * @since 1.4.7
	 *
	 * @param array $schedules Existing cron schedules.
	 *
	 * @return array Modified schedules.
	 */
	public function add_cron_schedules( $schedules ) {
		// Add monthly schedule if it doesn't exist.
		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => MONTH_IN_SECONDS,
				'display'  => __( 'Once Monthly', 'gravity-forms-zero-spam' ),
			);
		}

		return $schedules;
	}

	/**
	 * Schedule a cron job.
	 *
	 * @since 1.4.7
	 * @since 1.5.0 Schedule 5 minutes in future to prevent race conditions with alternate cron systems.
	 *
	 * @param string $frequency The frequency of the cron job.
	 *
	 * @return bool True if scheduled successfully, false otherwise.
	 */
	public function schedule( $frequency ) {
		// Clear any existing schedule.
		$this->unschedule();

		// Don't schedule if frequency is empty or entry_limit.
		if ( empty( $frequency ) || 'entry_limit' === $frequency ) {
			return false;
		}

		// Only schedule if not already scheduled.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Schedule 5 minutes in the future to prevent race conditions with alternate cron systems.
			// This ensures the event is properly registered before execution, especially with
			// WP Engine's alternate cron and similar systems.
			$start_time = time() + ( 5 * MINUTE_IN_SECONDS );
			$result     = wp_schedule_event( $start_time, $frequency, self::CRON_HOOK );

			if ( false !== $result ) {
				$this->log_debug( sprintf(
					'Cron scheduled successfully for frequency: %s, first run at: %s',
					$frequency,
					gmdate( 'Y-m-d H:i:s', $start_time )
				) );
				return true;
			} else {
				$this->log_error( 'Failed to schedule cron for frequency: ' . $frequency );
				return false;
			}
		}

		return true;
	}

	/**
	 * Unschedule the cron job.
	 *
	 * @since 1.4.7
	 *
	 * @return void
	 */
	public function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Get the next scheduled time for the cron job.
	 *
	 * @since 1.4.7
	 *
	 * @return int|false Timestamp of next scheduled run, or false if not scheduled.
	 */
	public function get_next_scheduled() {
		return wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Get a human-readable string of the next scheduled time.
	 *
	 * @since 1.4.7
	 *
	 * @return string
	 */
	public function get_next_scheduled_display() {
		$timestamp = $this->get_next_scheduled();

		if ( ! $timestamp ) {
			return __( 'Not scheduled', 'gravity-forms-zero-spam' );
		}

		$local_time = get_date_from_gmt( date( 'Y-m-d H:i:s', $timestamp ), 'Y-m-d H:i:s' );
		$time_diff  = human_time_diff( $timestamp );

		return sprintf(
			/* translators: 1: Local time, 2: Human-readable time difference */
			__( '%1$s (%2$s from now)', 'gravity-forms-zero-spam' ),
			$local_time,
			$time_diff
		);
	}

	/**
	 * Update the last sent timestamp.
	 *
	 * @since 1.4.7
	 *
	 * @param int|null $timestamp Timestamp to set, or null for current time.
	 *
	 * @return void
	 */
	public function update_last_sent( $timestamp = null ) {
		if ( null === $timestamp ) {
			$timestamp = current_time( 'timestamp', true );
		}
		
		$old_timestamp = $this->get_last_sent();
		update_option( self::LAST_SENT_OPTION, $timestamp );
		
		$this->log_debug( sprintf(
			'Updated last sent timestamp from %s to %s',
			$old_timestamp ? gmdate( 'Y-m-d H:i:s', $old_timestamp ) : 'never',
			gmdate( 'Y-m-d H:i:s', $timestamp )
		) );
	}

	/**
	 * Get the last sent timestamp.
	 *
	 * @since 1.4.7
	 *
	 * @return int|false
	 */
	public function get_last_sent() {
		return get_option( self::LAST_SENT_OPTION, false );
	}

	/**
	 * Get the last sent date formatted.
	 *
	 * @since 1.4.7
	 *
	 * @param string $format Date format.
	 *
	 * @return string
	 */
	public function get_last_sent_date( $format = 'Y-m-d H:i:s' ) {
		$timestamp = $this->get_last_sent();

		if ( ! $timestamp ) {
			// First time running - look back 30 days for initial report
			$timestamp = current_time( 'timestamp', true ) - ( 30 * DAY_IN_SECONDS );
		}

		// Convert UTC timestamp to local time for database comparison
		// Gravity Forms stores entries in local time, not UTC
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ), $format );
	}

	/**
	 * Clean up all cron-related data.
	 *
	 * @since 1.4.7
	 *
	 * @return void
	 */
	public static function cleanup() {
		// Unschedule cron.
		wp_clear_scheduled_hook( self::CRON_HOOK );

		// Delete options.
		delete_option( self::LAST_SENT_OPTION );
	}

	/**
	 * Log debug message.
	 *
	 * @since 1.4.7
	 *
	 * @param string $message Message to log.
	 *
	 * @return void
	 */
	private function log_debug( $message ) {
		if ( ! class_exists( 'GFLogging' ) ) {
			return;
		}

		GFLogging::include_logger();
		GFLogging::log_message( 'gf-zero-spam', $message, KLogger::DEBUG );
	}

	/**
	 * Log error message.
	 *
	 * @since 1.4.7
	 *
	 * @param string $message Message to log.
	 *
	 * @return void
	 */
	private function log_error( $message ) {
		if ( ! class_exists( 'GFLogging' ) ) {
			return;
		}

		GFLogging::include_logger();
		GFLogging::log_message( 'gf-zero-spam', $message, KLogger::ERROR );
	}
}

// Initialize the cron handler.
GF_Zero_Spam_Cron::get_instance();