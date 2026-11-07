<?php
/**
 * WP-CLI commands.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Export the site to static HTML.
 */
class AOUWAL_CLI {

	/**
	 * Run a static export.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Number of URLs processed per loop iteration.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--changed-only]
	 * : Reuse the last export and re-export only pages and assets that
	 * changed, overriding the saved export mode for this run.
	 *
	 * [--full]
	 * : Re-crawl every page and asset, overriding the saved export mode for
	 * this run.
	 *
	 * [--timeout=<seconds>]
	 * : Request timeout for this run, overriding the saved setting (1-120).
	 *
	 * [--delay=<milliseconds>]
	 * : Pause between crawl requests for this run, overriding the saved
	 * setting (0-3000).
	 *
	 * [--origin=<url>]
	 * : Send crawl requests to this origin for this run, overriding the saved
	 * setting. Links still use the site URL.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aouwal-static-export run --timeout=90 --delay=250
	 *     wp aouwal-static-export run --origin=http://wordpress:8080
	 *     wp aouwal-static-export run --changed-only
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 10;

		$overrides = array();
		if ( isset( $assoc_args['timeout'] ) ) {
			$overrides['request_timeout'] = min( 120, max( 1, (int) $assoc_args['timeout'] ) );
		}
		if ( isset( $assoc_args['delay'] ) ) {
			$overrides['request_delay'] = min( 3000, max( 0, (int) $assoc_args['delay'] ) );
		}
		if ( isset( $assoc_args['origin'] ) ) {
			$origin = AOUWAL_Network::sanitize_origin( (string) $assoc_args['origin'] );
			if ( '' === $origin ) {
				WP_CLI::error( 'The --origin value is not a usable URL. Use http:// or https:// with a host, without credentials, a query string or a fragment.' );
			}
			$overrides['crawl_origin'] = $origin;
		}
		if ( ! empty( $overrides ) ) {
			AOUWAL_Network::set_overrides( $overrides );
		}

		// The mode flags never write the setting, so a one-off changed-only
		// cron run cannot change what the admin screen does next time.
		$runtime = array();
		if ( isset( $assoc_args['changed-only'] ) ) {
			$runtime['export_mode'] = 'changed';
		}
		if ( isset( $assoc_args['full'] ) ) {
			$runtime['export_mode'] = 'full';
		}
		if ( ! empty( $runtime ) ) {
			AOUWAL_Settings::set_runtime( $runtime );
		}

		$exporter = new AOUWAL_Exporter();
		$start    = $exporter->start();

		if ( is_wp_error( $start ) ) {
			WP_CLI::error( $start->get_error_message() );
		}

		$job_id = $start['job_id'];
		$job    = $exporter->get_job( $job_id );
		$mode   = ( $job && isset( $job['mode'] ) ) ? $job['mode'] : 'full';
		$reused = ( $job && ! empty( $job['reused'] ) ) ? ' from the previous export' : '';

		WP_CLI::log( sprintf( 'Discovered %d URLs%s (job %s, %s export).', $start['total'], $reused, $job_id, $mode ) );

		$this->run_batches( $exporter, $job_id, $batch, (int) $start['total'] );
	}

	/**
	 * Retry only the failed items of the newest finished export.
	 *
	 * Already-written files and the output directory are reused, and the ZIP
	 * is rebuilt in place when the retry run finishes. Transient failures
	 * (timeouts, 429, 5xx) normally succeed on the next attempt.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Number of URLs processed per loop iteration.
	 * ---
	 * default: 10
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp aouwal-static-export retry
	 *     wp aouwal-static-export retry --batch=5
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function retry( $args, $assoc_args ) {
		$batch    = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 10;
		$exporter = new AOUWAL_Exporter();
		$state    = $exporter->status();

		if ( '' === $state['job_id'] ) {
			WP_CLI::error( 'No export to retry.' );
		}

		$result = $exporter->retry_failed( $state['job_id'] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( sprintf( 'Requeued %d failed item(s) from job %s.', (int) $result['retried'], $state['job_id'] ) );
		$this->run_batches( $exporter, $state['job_id'], $batch, (int) $result['total'] );
	}

	/**
	 * Process the queue until it is empty, then package the export.
	 *
	 * Stops early and explains why when the export is paused or cancelled from
	 * the admin screen while this command runs.
	 *
	 * @param AOUWAL_Exporter $exporter Exporter instance.
	 * @param string          $job_id   Job ID.
	 * @param int             $batch    URLs per loop iteration.
	 * @param int             $total    Total number of queued items.
	 * @return void
	 */
	protected function run_batches( $exporter, $job_id, $batch, $total ) {
		$progress = \WP_CLI\Utils\make_progress_bar( 'Exporting', max( 1, $total ) );
		$done     = 0;

		while ( true ) {
			$result = $exporter->process_batch( $job_id, $batch );
			if ( is_wp_error( $result ) ) {
				$progress->finish();
				WP_CLI::error( $result->get_error_message() );
			}

			if ( ! empty( $result['cancelled'] ) ) {
				$progress->finish();
				$this->report_cancelled( $exporter, $job_id );
			}

			if ( ! empty( $result['paused'] ) ) {
				$progress->finish();
				WP_CLI::warning( sprintf( 'Export paused. Continue it with: wp aouwal-static-export resume --job=%s', $job_id ) );
				WP_CLI::halt( 0 );
			}

			$counts  = $result['counts'];
			$current = $counts['done'] + $counts['error'];
			$progress->tick( max( 0, $current - $done ) );
			$done = $current;

			if ( ! empty( $result['log'] ) ) {
				foreach ( $result['log'] as $line ) {
					WP_CLI::log( $line );
				}
			}

			if ( ! empty( $result['done'] ) ) {
				break;
			}
		}

		$final = $exporter->finalize( $job_id );
		if ( is_wp_error( $final ) ) {
			$progress->finish();
			WP_CLI::error( $final->get_error_message() );
		}

		// A cancel can land between the last batch and this call.
		if ( ! empty( $final['cancelled'] ) ) {
			$progress->finish();
			$this->report_cancelled( $exporter, $job_id );
		}

		$progress->finish();
		$done_job = $exporter->get_job( $job_id );
		$done_zip = ( $done_job && ! empty( $done_job['zip_path'] ) ) ? $done_job['zip_path'] : $final['zip'];
		WP_CLI::success( sprintf( 'Export complete: %s', $done_zip ) );

		if ( ! empty( $final['counts']['error'] ) ) {
			foreach ( (array) $final['log'] as $notice ) {
				WP_CLI::warning( $notice );
			}
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Print the stored cancel reason and exit with a failure code.
	 *
	 * @param AOUWAL_Exporter $exporter Exporter instance.
	 * @param string          $job_id   Job ID.
	 * @return void
	 */
	protected function report_cancelled( $exporter, $job_id ) {
		$job = $exporter->get_job( $job_id );
		if ( $job && ! empty( $job['notice'] ) ) {
			foreach ( (array) $job['notice'] as $line ) {
				WP_CLI::log( $line );
			}
		}
		WP_CLI::warning( 'Export cancelled. Partial files were removed.' );
		WP_CLI::halt( 1 );
	}

	/**
	 * Show the state of the newest export.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aouwal-static-export status
	 *
	 * @return void
	 */
	public function status() {
		$exporter = new AOUWAL_Exporter();
		$state    = $exporter->status();

		if ( '' === $state['job_id'] ) {
			WP_CLI::log( 'No export has run yet.' );
			return;
		}

		$counts = $state['counts'];
		$job    = $exporter->get_job( $state['job_id'] );
		WP_CLI::log( sprintf( 'Job:       %s', $state['job_id'] ) );
		WP_CLI::log( sprintf( 'Mode:      %s', ( $job && isset( $job['mode'] ) ) ? $job['mode'] : 'full' ) );
		WP_CLI::log( sprintf( 'Phase:     %s', $state['phase'] ) );
		WP_CLI::log( sprintf( 'Processed: %d of %d', $counts['done'] + $counts['error'], $counts['total'] ) );
		WP_CLI::log( sprintf( 'Pending:   %d', $counts['pending'] ) );
		WP_CLI::log( sprintf( 'Errors:    %d', $counts['error'] ) );

		if ( '' !== $state['current_url'] ) {
			WP_CLI::log( sprintf( 'Last URL:  %s', $state['current_url'] ) );
		}

		foreach ( (array) $state['notice'] as $line ) {
			WP_CLI::log( 'Notice:    ' . $line );
		}

		if ( $counts['error'] > 0 ) {
			$health = $exporter->health( $state['job_id'] );
			foreach ( $health['reasons'] as $reason => $number ) {
				WP_CLI::log( sprintf( 'Reason:    %d× %s', (int) $number, $reason ) );
			}
			foreach ( $health['failed'] as $failure ) {
				$origin = '' !== $failure['origin'] ? ' found on ' . $failure['origin'] : '';
				WP_CLI::log( sprintf( 'Failed:    %s (%s)%s', $failure['url'], $failure['reason'], $origin ) );
			}
		}
	}

	/**
	 * Cancel the newest export and delete its partial files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aouwal-static-export cancel
	 *
	 * @return void
	 */
	public function cancel() {
		$exporter = new AOUWAL_Exporter();
		$state    = $exporter->status();

		if ( '' === $state['job_id'] ) {
			WP_CLI::error( 'No export to cancel.' );
		}

		$result = $exporter->cancel( $state['job_id'] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		foreach ( (array) $result['log'] as $line ) {
			WP_CLI::log( $line );
		}
		WP_CLI::success( 'Export cancelled.' );
	}

	/**
	 * Resume the newest paused export.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Number of URLs processed per loop iteration.
	 * ---
	 * default: 10
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp aouwal-static-export resume --batch=5
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function resume( $args, $assoc_args ) {
		$batch    = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 10;
		$exporter = new AOUWAL_Exporter();
		$state    = $exporter->status();

		if ( '' === $state['job_id'] ) {
			WP_CLI::error( 'No export to resume.' );
		}

		$result = $exporter->resume( $state['job_id'] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( sprintf( 'Resuming job %s at %d of %d.', $state['job_id'], $result['counts']['done'] + $result['counts']['error'], $result['total'] ) );

		$this->run_batches( $exporter, $state['job_id'], $batch, (int) $result['total'] );
	}
}

WP_CLI::add_command( 'aouwal-static-export', 'AOUWAL_CLI' );
