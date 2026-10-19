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
	 * Run a full static export.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Number of URLs processed per loop iteration.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--keep-zip]
	 * : Keep the generated zip (default true). Path is printed on completion.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		$batch = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 10;

		$exporter = new AOUWAL_Exporter();
		$start    = $exporter->start();

		if ( is_wp_error( $start ) ) {
			WP_CLI::error( $start->get_error_message() );
		}

		$job_id = $start['job_id'];
		WP_CLI::log( sprintf( 'Discovered %d URLs (job %s).', $start['total'], $job_id ) );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Exporting', max( 1, $start['total'] ) );
		$done     = 0;

		while ( true ) {
			$result = $exporter->process_batch( $job_id, $batch );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			$counts = $result['counts'];
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
			WP_CLI::error( $final->get_error_message() );
		}

		$progress->finish();
		$done_job = $exporter->get_job( $job_id );
		$done_zip = ( $done_job && ! empty( $done_job['zip_path'] ) ) ? $done_job['zip_path'] : $final['zip'];
		WP_CLI::success( sprintf( 'Export complete: %s', $done_zip ) );
	}
}

WP_CLI::add_command( 'aouwal-static-export', 'AOUWAL_CLI' );
