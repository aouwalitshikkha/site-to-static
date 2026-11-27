<?php
/**
 * Scheduled and publish-triggered exports.
 *
 * Reuses the chunked exporter from WP-Cron. Automatic runs never overlap: the
 * export lock is the only gate, and a busy or paused export pushes the next
 * attempt instead of starting a second crawl.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_Scheduler
 */
class AOUWAL_Scheduler {

	/**
	 * Recurring export hook.
	 *
	 * @var string
	 */
	const RECURRING_HOOK = 'aouwal_scheduled_export';

	/**
	 * Debounced export hook used after a publish, update or delete.
	 *
	 * @var string
	 */
	const DEBOUNCE_HOOK = 'aouwal_debounce_export';

	/**
	 * Valid schedule modes.
	 *
	 * @var string[]
	 */
	const MODES = array( 'off', 'publish', 'hourly', 'twicedaily', 'daily' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'sync' ), 20 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_change' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( self::RECURRING_HOOK, array( __CLASS__, 'run_recurring' ) );
		add_action( self::DEBOUNCE_HOOK, array( __CLASS__, 'run_debounced' ) );
	}

	/**
	 * Saved schedule mode.
	 *
	 * @return string off, publish, hourly, twicedaily or daily.
	 */
	public static function mode() {
		$mode = (string) AOUWAL_Settings::get( 'schedule_mode', 'off' );
		return in_array( $mode, self::MODES, true ) ? $mode : 'off';
	}

	/**
	 * Create, update or remove the recurring event to match the setting.
	 *
	 * Runs on every request so a site that was deactivated and activated again
	 * gets its event back without saving settings.
	 *
	 * @return void
	 */
	public static function sync() {
		$mode      = self::mode();
		$recur     = in_array( $mode, array( 'hourly', 'twicedaily', 'daily' ), true ) ? $mode : '';
		$current   = wp_get_schedule( self::RECURRING_HOOK );
		$scheduled = (bool) wp_next_scheduled( self::RECURRING_HOOK );

		if ( '' === $recur ) {
			if ( $scheduled ) {
				wp_clear_scheduled_hook( self::RECURRING_HOOK );
			}
			if ( 'publish' !== $mode ) {
				wp_clear_scheduled_hook( self::DEBOUNCE_HOOK );
			}
			return;
		}

		if ( $recur === $current && $scheduled ) {
			return;
		}

		wp_clear_scheduled_hook( self::RECURRING_HOOK );
		wp_schedule_event( time() + 300, $recur, self::RECURRING_HOOK );
	}

	/**
	 * Queue one debounced export after a post is published or updated.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 * @return void
	 */
	public static function on_post_change( $new_status, $old_status, $post ) {
		if ( 'publish' !== self::mode() ) {
			return;
		}
		// Static mode deletes the stale file and rebuilds on its own.
		if ( AOUWAL_Static_Server::enabled() ) {
			return;
		}
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post->ID ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, (array) AOUWAL_Settings::get( 'post_types', array( 'post', 'page' ) ), true ) ) {
			return;
		}

		self::queue_debounced();
	}

	/**
	 * Queue one debounced export after a published post is deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_delete( $post_id ) {
		if ( 'publish' !== self::mode() || AOUWAL_Static_Server::enabled() ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! in_array( $post->post_type, (array) AOUWAL_Settings::get( 'post_types', array( 'post', 'page' ) ), true ) ) {
			return;
		}

		self::queue_debounced();
	}

	/**
	 * Schedule the debounced run once per burst of changes.
	 *
	 * A second change while a run is pending reuses the pending event, so a
	 * batch of edits produces one export instead of one per post.
	 *
	 * @return void
	 */
	protected static function queue_debounced() {
		if ( wp_next_scheduled( self::DEBOUNCE_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 120, self::DEBOUNCE_HOOK );
	}

	/**
	 * Cron callback for the recurring schedule.
	 *
	 * @return void
	 */
	public static function run_recurring() {
		if ( ! in_array( self::mode(), array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
			return;
		}
		self::run_export( self::RECURRING_HOOK );
	}

	/**
	 * Cron callback for the debounced publish run.
	 *
	 * @return void
	 */
	public static function run_debounced() {
		if ( 'publish' !== self::mode() ) {
			return;
		}
		self::run_export( self::DEBOUNCE_HOOK );
	}

	/**
	 * Run one export in batches, then finalize.
	 *
	 * Returns a short result code so callers and tests can tell why nothing was
	 * exported. A locked or paused export is retried later instead of being
	 * replaced, which keeps a manual run intact.
	 *
	 * @param string $continue_hook Hook used for follow-up runs.
	 * @return string done, locked, paused, error or timeout.
	 */
	public static function run_export( $continue_hook ) {
		$exporter = new AOUWAL_Exporter();
		$lock     = $exporter->lock_info();
		if ( $lock ) {
			self::log( sprintf( 'skipped scheduled run: export %s is locked by job %s', gmdate( 'Y-m-d H:i:s' ), (string) $lock['job_id'] ) );
			wp_schedule_single_event( time() + 300, $continue_hook );
			return 'locked';
		}

		$status = $exporter->status();
		if ( ! empty( $status['paused'] ) ) {
			self::log( sprintf( 'skipped scheduled run at %s: export %s is paused', gmdate( 'Y-m-d H:i:s' ), (string) $status['job_id'] ) );
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, $continue_hook );
			return 'paused';
		}

		$start = $exporter->start();
		if ( is_wp_error( $start ) ) {
			self::log( 'scheduled run could not start: ' . $start->get_error_message() );
			wp_schedule_single_event( time() + 600, $continue_hook );
			return 'error';
		}

		$job      = (string) $start['job_id'];
		$batch    = max( 1, (int) AOUWAL_Settings::get( 'batch_size', 5 ) );
		$deadline = time() + 20;

		while ( time() < $deadline ) {
			$result = $exporter->process_batch( $job, $batch );
			if ( is_wp_error( $result ) ) {
				self::log( 'scheduled batch failed: ' . $result->get_error_message() );
				wp_schedule_single_event( time() + 600, $continue_hook );
				return 'error';
			}
			if ( ! empty( $result['paused'] ) || ! empty( $result['cancelled'] ) ) {
				return 'paused';
			}
			if ( ! empty( $result['done'] ) ) {
				$final = $exporter->finalize( $job );
				if ( is_wp_error( $final ) ) {
					self::log( 'scheduled finalize failed: ' . $final->get_error_message() );
					return 'error';
				}
				return 'done';
			}
		}

		wp_schedule_single_event( time() + 5, $continue_hook );
		return 'timeout';
	}

	/**
	 * Remove every cron event this plugin owns.
	 *
	 * @return void
	 */
	public static function clear_events() {
		wp_clear_scheduled_hook( self::RECURRING_HOOK );
		wp_clear_scheduled_hook( self::DEBOUNCE_HOOK );
		wp_clear_scheduled_hook( AOUWAL_Static_Server::CRON_HOOK );
	}

	/**
	 * Debug log line, only when WP_DEBUG is on.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	protected static function log( $message ) {
		do_action( 'aouwal_export_skipped', $message );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Aouwal Static Site Export: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Opt-in debug log for cron runs, only when WP_DEBUG is on.
		}
	}
}
