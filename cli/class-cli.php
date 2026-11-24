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
	 * Whether stdout carries JSON.
	 *
	 * @var bool
	 */
	protected $json = false;

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
	 * [--format=<format>]
	 * : Output format. "table" is the default human output. "json" prints one
	 * JSON object on stdout and sends log lines to stderr. "porcelain" is an
	 * alias for "json".
	 * ---
	 * default: table
	 * ---
	 *
	 * [--output-dir=<path>]
	 * : Copy the finished site tree to this directory. The directory must be
	 * missing or empty unless --force is used.
	 *
	 * [--zip=<path>]
	 * : Copy the finished ZIP to this path. The file must not exist unless
	 * --force is used. The path must end in .zip.
	 *
	 * [--force]
	 * : Replace a non-empty --output-dir or an existing --zip file.
	 *
	 * ## EXIT STATUS
	 *
	 * 0: export completed without failed items.
	 * 1: export completed with failed items, or was cancelled.
	 * 2: could not start (lock, invalid flag or path, missing zip extension).
	 *
	 * ## EXAMPLES
	 *
	 *     wp aouwal-static-export run --timeout=90 --delay=250
	 *     wp aouwal-static-export run --changed-only
	 *     wp aouwal-static-export run --format=json --zip=/var/www/site.zip
	 *     wp aouwal-static-export run --output-dir=/var/www/static --force
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? strtolower( (string) $assoc_args['format'] ) : 'table';
		if ( ! in_array( $format, array( 'table', 'json', 'porcelain' ), true ) ) {
			$this->fail( 'Invalid --format. Use "table", "json" or "porcelain".', 2 );
		}
		$this->json = in_array( $format, array( 'json', 'porcelain' ), true );
		$force      = ! empty( $assoc_args['force'] );

		$output_override = '';
		$zip_override    = '';

		if ( isset( $assoc_args['output-dir'] ) ) {
			$output_override = $this->validate_target( (string) $assoc_args['output-dir'], false, $force );
			if ( is_wp_error( $output_override ) ) {
				$this->fail( $output_override->get_error_message(), 2 );
			}
		}
		if ( isset( $assoc_args['zip'] ) ) {
			$zip_override = $this->validate_target( (string) $assoc_args['zip'], true, $force );
			if ( is_wp_error( $zip_override ) ) {
				$this->fail( $zip_override->get_error_message(), 2 );
			}
		}
		if ( '' !== $output_override && '' !== $zip_override && $output_override === $zip_override ) {
			$this->fail( '--output-dir and --zip must be different paths.', 2 );
		}

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
				$this->fail( 'The --origin value is not a usable URL. Use http:// or https:// with a host, without credentials, a query string or a fragment.', 2 );
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
			$this->fail( $start->get_error_message(), 2 );
		}

		$job_id = $start['job_id'];
		$job    = $exporter->get_job( $job_id );
		$mode   = ( $job && isset( $job['mode'] ) ) ? $job['mode'] : 'full';
		$reused = ( $job && ! empty( $job['reused'] ) ) ? ' from the previous export' : '';

		$this->line( sprintf( 'Discovered %d URLs%s (job %s, %s export).', $start['total'], $reused, $job_id, $mode ) );

		$outcome = $this->run_batches( $exporter, $job_id, $batch, (int) $start['total'] );

		if ( isset( $outcome['error'] ) ) {
			$this->fail( $outcome['error']->get_error_message(), 2 );
		}

		if ( ! empty( $outcome['cancelled'] ) ) {
			$job = $exporter->get_job( $job_id );
			if ( $job && ! empty( $job['notice'] ) ) {
				foreach ( (array) $job['notice'] as $notice ) {
					$this->line( $notice );
				}
			}
			$this->warning( 'Export cancelled. Partial files were removed.' );
			$this->emit_error( 'Export cancelled.', 1, $job_id );
			WP_CLI::halt( 1 );
		}

		if ( ! empty( $outcome['paused'] ) ) {
			$this->warning( sprintf( 'Export paused. Continue it with: wp aouwal-static-export resume --batch=%d', $batch ) );
			if ( $this->json ) {
				$job = $exporter->get_job( $job_id );
				fwrite( STDOUT, (string) wp_json_encode( $this->summary( $job, array( 'counts' => $outcome['counts'] ), $job['output_dir'], $job['zip_path'] ) ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- JSON CLI output to stdout.
			}
			WP_CLI::halt( 0 );
		}

		$final = isset( $outcome['final'] ) ? $outcome['final'] : array();
		$job   = isset( $outcome['job'] ) ? $outcome['job'] : $exporter->get_job( $job_id );

		$paths = $this->publish( $job, $output_override, $zip_override, $force );
		if ( is_wp_error( $paths ) ) {
			$this->fail( $paths->get_error_message(), 2 );
		}

		$summary = $this->summary( $job, $final, $paths['output_dir'], $paths['zip'] );

		if ( $this->json ) {
			fwrite( STDOUT, (string) wp_json_encode( $summary ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- JSON CLI output to stdout.
		} else {
			WP_CLI::success( sprintf( 'Export complete: %s', $paths['zip'] ) );
		}

		foreach ( (array) ( isset( $final['deploy'] ) ? $final['deploy'] : array() ) as $row ) {
			if ( ! empty( $row['ok'] ) ) {
				$this->line( $row['message'] );
			} else {
				$this->warning( $row['message'] );
			}
		}

		if ( ! empty( $final['counts']['error'] ) ) {
			foreach ( (array) $final['log'] as $notice ) {
				$this->warning( $notice );
			}
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Retry only the failed items of the newest finished export.
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
		$this->finish_human( $exporter, $state['job_id'], $this->run_batches( $exporter, $state['job_id'], $batch, (int) $result['total'] ) );
	}

	/**
	 * Process the queue until it is empty, then package the export.
	 *
	 * @param AOUWAL_Exporter $exporter Exporter instance.
	 * @param string          $job_id   Job ID.
	 * @param int             $batch    URLs per loop iteration.
	 * @param int             $total    Total number of queued items.
	 * @return array Outcome for the caller.
	 */
	protected function run_batches( $exporter, $job_id, $batch, $total ) {
		$progress = $this->json ? null : \WP_CLI\Utils\make_progress_bar( 'Exporting', max( 1, $total ) );
		$done     = 0;

		while ( true ) {
			$result = $exporter->process_batch( $job_id, $batch );
			if ( is_wp_error( $result ) ) {
				if ( $progress ) {
					$progress->finish();
				}
				return array( 'error' => $result );
			}

			if ( ! empty( $result['cancelled'] ) ) {
				if ( $progress ) {
					$progress->finish();
				}
				return array(
					'cancelled' => true,
					'job'       => $exporter->get_job( $job_id ),
				);
			}

			if ( ! empty( $result['paused'] ) ) {
				if ( $progress ) {
					$progress->finish();
				}
				return array(
					'paused' => true,
					'counts' => $result['counts'],
					'job'    => $exporter->get_job( $job_id ),
				);
			}

			$counts  = $result['counts'];
			$current = $counts['done'] + $counts['error'];
			if ( $progress ) {
				$progress->tick( max( 0, $current - $done ) );
			}
			$done = $current;

			if ( ! empty( $result['log'] ) ) {
				foreach ( $result['log'] as $line ) {
					$this->line( $line );
				}
			}

			if ( ! empty( $result['done'] ) ) {
				break;
			}
		}

		$final = $exporter->finalize( $job_id );
		if ( is_wp_error( $final ) ) {
			if ( $progress ) {
				$progress->finish();
			}
			return array( 'error' => $final );
		}

		// A cancel can land between the last batch and this call.
		if ( ! empty( $final['cancelled'] ) ) {
			if ( $progress ) {
				$progress->finish();
			}
			return array(
				'cancelled' => true,
				'job'       => $exporter->get_job( $job_id ),
			);
		}

		if ( $progress ) {
			$progress->finish();
		}

		return array(
			'final' => $final,
			'job'   => $exporter->get_job( $job_id ),
		);
	}

	/**
	 * Finish a human-only retry or resume run.
	 *
	 * @param AOUWAL_Exporter $exporter Exporter.
	 * @param string          $job_id   Job ID.
	 * @param array           $outcome  run_batches() result.
	 * @return void
	 */
	protected function finish_human( $exporter, $job_id, array $outcome ) {
		if ( isset( $outcome['error'] ) ) {
			WP_CLI::error( $outcome['error']->get_error_message() );
		}
		if ( ! empty( $outcome['cancelled'] ) ) {
			$job = $exporter->get_job( $job_id );
			if ( $job && ! empty( $job['notice'] ) ) {
				foreach ( (array) $job['notice'] as $line ) {
					WP_CLI::log( $line );
				}
			}
			WP_CLI::warning( 'Export cancelled. Partial files were removed.' );
			WP_CLI::halt( 1 );
		}
		if ( ! empty( $outcome['paused'] ) ) {
			WP_CLI::warning( sprintf( 'Export paused. Continue it with: wp aouwal-static-export resume --job=%s', $job_id ) );
			WP_CLI::halt( 0 );
		}

		$final = isset( $outcome['final'] ) ? $outcome['final'] : array();
		$job   = isset( $outcome['job'] ) ? $outcome['job'] : $exporter->get_job( $job_id );
		$zip   = ( $job && ! empty( $job['zip_path'] ) ) ? $job['zip_path'] : '';
		WP_CLI::success( sprintf( 'Export complete: %s', $zip ) );

		foreach ( (array) ( isset( $final['deploy'] ) ? $final['deploy'] : array() ) as $row ) {
			if ( ! empty( $row['ok'] ) ) {
				WP_CLI::log( $row['message'] );
			} else {
				WP_CLI::warning( $row['message'] );
			}
		}

		if ( ! empty( $final['counts']['error'] ) ) {
			foreach ( (array) $final['log'] as $notice ) {
				WP_CLI::warning( $notice );
			}
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Print a log line on stdout in human mode and stderr in JSON mode.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	protected function line( $message ) {
		if ( $this->json ) {
			fwrite( STDERR, (string) $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI log line; stdout is reserved for JSON.
			return;
		}
		WP_CLI::log( (string) $message );
	}

	/**
	 * Print a warning on stderr.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	protected function warning( $message ) {
		if ( $this->json ) {
			fwrite( STDERR, 'Warning: ' . (string) $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI warning; stdout is reserved for JSON.
			return;
		}
		WP_CLI::warning( (string) $message );
	}

	/**
	 * Emit a JSON error object when JSON mode is active.
	 *
	 * @param string $message Message.
	 * @param int    $code    Exit code.
	 * @param string $job_id  Optional job ID.
	 * @return void
	 */
	protected function emit_error( $message, $code, $job_id = '' ) {
		if ( ! $this->json ) {
			return;
		}
		$data = array(
			'error'  => (string) $message,
			'code'   => (int) $code,
			'job_id' => (string) $job_id,
		);
		fwrite( STDOUT, (string) wp_json_encode( $data ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- JSON CLI output to stdout.
	}

	/**
	 * Stop with a human error or a JSON error object.
	 *
	 * @param string $message Message.
	 * @param int    $code    Exit code.
	 * @return void
	 */
	protected function fail( $message, $code = 2 ) {
		if ( $this->json ) {
			$this->emit_error( $message, $code );
			fwrite( STDERR, 'Error: ' . (string) $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI error; stdout is reserved for JSON.
			WP_CLI::halt( (int) $code );
		}
		fwrite( STDERR, 'Error: ' . (string) $message . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI error.
		WP_CLI::halt( (int) $code );
	}

	/**
	 * Build the machine-readable summary.
	 *
	 * @param array  $job        Job data.
	 * @param array  $finalized  finalize() result.
	 * @param string $output_dir Final output directory.
	 * @param string $zip        Final ZIP path.
	 * @return array
	 */
	protected function summary( $job, array $finalized, $output_dir, $zip ) {
		$job      = is_array( $job ) ? $job : array();
		$counts   = isset( $finalized['counts'] ) ? $finalized['counts'] : array();
		$started  = isset( $job['started'] ) ? (string) $job['started'] : '';
		$end      = isset( $job['finished'] ) ? (string) $job['finished'] : '';
		$duration = 0;
		if ( '' !== $started && '' !== $end ) {
			$duration = max( 0, strtotime( $end ) - strtotime( $started ) );
		}
		if ( file_exists( $zip ) ) {
			clearstatcache( true, $zip );
		}

		return array(
			'job_id'     => isset( $job['id'] ) ? (string) $job['id'] : '',
			'mode'       => isset( $job['mode'] ) ? (string) $job['mode'] : 'full',
			'reused'     => ! empty( $job['reused'] ),
			'counts'     => $counts,
			'output_dir' => (string) $output_dir,
			'zip'        => (string) $zip,
			'bytes'      => file_exists( $zip ) ? (int) filesize( $zip ) : 0,
			'duration'   => (int) $duration,
			'started'    => $started,
			'finished'   => $end,
			'deploy'     => isset( $finalized['deploy'] ) ? (array) $finalized['deploy'] : array(),
		);
	}

	/**
	 * Copy the finished export to the requested paths.
	 *
	 * @param array  $job             Job data.
	 * @param string $output_override Destination folder, or empty.
	 * @param string $zip_override    Destination ZIP, or empty.
	 * @param bool   $force           Replace existing paths.
	 * @return array|WP_Error
	 */
	protected function publish( $job, $output_override, $zip_override, $force ) {
		$source_dir = isset( $job['output_dir'] ) ? untrailingslashit( wp_normalize_path( (string) $job['output_dir'] ) ) : '';
		$source_zip = isset( $job['zip_path'] ) ? (string) $job['zip_path'] : '';
		$out_dir    = '' !== $output_override ? $output_override : $source_dir;
		$out_zip    = '' !== $zip_override ? $zip_override : $source_zip;

		if ( '' === $source_dir || ! is_dir( $source_dir ) ) {
			return new WP_Error( 'aouwal_publish_source', __( 'The export folder is missing, so it cannot be copied to --output-dir.', 'aouwal-static-site-export' ) );
		}

		$fs = AOUWAL_Writer::filesystem_instance();
		if ( ! $fs ) {
			return new WP_Error( 'aouwal_publish_fs', __( 'Could not initialize the WordPress filesystem.', 'aouwal-static-site-export' ) );
		}

		if ( '' !== $output_override ) {
			$target = untrailingslashit( wp_normalize_path( $output_override ) );
			if ( $target === $source_dir || 0 === strpos( $target . '/', $source_dir . '/' ) || 0 === strpos( $source_dir . '/', $target . '/' ) ) {
				return new WP_Error( 'aouwal_publish_overlap', __( '--output-dir must not be inside the export folder or contain it.', 'aouwal-static-site-export' ) );
			}
			if ( $fs->exists( $target ) ) {
				if ( $force ) {
					if ( ! $fs->delete( $target, true ) ) {
						return new WP_Error( 'aouwal_publish_delete', __( 'Could not clear --output-dir.', 'aouwal-static-site-export' ) );
					}
				} else {
					$entries = $fs->dirlist( $target );
					if ( ! is_array( $entries ) || ! empty( $entries ) ) {
						return new WP_Error( 'aouwal_publish_exists', __( '--output-dir is not empty. Use --force to replace it.', 'aouwal-static-site-export' ) );
					}
				}
			}
			if ( ! wp_mkdir_p( $target ) || ! $this->copy_tree_cli( $fs, $source_dir, $target ) ) {
				return new WP_Error( 'aouwal_publish_copy', __( 'Could not copy the export to --output-dir.', 'aouwal-static-site-export' ) );
			}
			$out_dir = $target;
		}

		if ( '' !== $zip_override ) {
			$target = wp_normalize_path( $zip_override );
			if ( $fs->exists( $target ) ) {
				if ( ! $force ) {
					return new WP_Error( 'aouwal_zip_exists', __( '--zip already exists. Use --force to replace it.', 'aouwal-static-site-export' ) );
				}
				if ( ! $fs->delete( $target ) ) {
					return new WP_Error( 'aouwal_zip_delete', __( 'Could not replace the existing --zip file.', 'aouwal-static-site-export' ) );
				}
			}
			if ( ! $fs->copy( $source_zip, $target, true ) ) {
				return new WP_Error( 'aouwal_zip_copy', __( 'Could not copy the ZIP to --zip.', 'aouwal-static-site-export' ) );
			}
			$out_zip = $target;
		}

		return array(
			'output_dir' => $out_dir,
			'zip'        => $out_zip,
		);
	}

	/**
	 * Recursively copy a directory through WP_Filesystem.
	 *
	 * @param WP_Filesystem_Base $fs Filesystem.
	 * @param string             $from Source.
	 * @param string             $to   Destination.
	 * @return bool
	 */
	protected function copy_tree_cli( $fs, $from, $to ) {
		$entries = $fs->dirlist( $from );
		if ( ! is_array( $entries ) ) {
			return false;
		}

		$ok = true;
		foreach ( $entries as $name => $info ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$src = trailingslashit( $from ) . $name;
			$dst = trailingslashit( $to ) . $name;
			if ( ! empty( $info['type'] ) && 'd' === $info['type'] ) {
				if ( ! is_dir( $dst ) && ! wp_mkdir_p( $dst ) ) {
					$ok = false;
					continue;
				}
				$ok = $this->copy_tree_cli( $fs, $src, $dst ) && $ok;
				continue;
			}
			$content = $fs->get_contents( $src );
			if ( ! is_string( $content ) || ! $fs->put_contents( $dst, $content, FS_CHMOD_FILE ) ) {
				$ok = false;
			}
		}
		return $ok;
	}

	/**
	 * Validate and normalize an output target.
	 *
	 * @param string $raw    Raw flag value.
	 * @param bool   $is_zip Whether this is the ZIP target.
	 * @param bool   $force  Whether replacement is allowed.
	 * @return string|WP_Error
	 */
	protected function validate_target( $raw, $is_zip, $force ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return new WP_Error( 'aouwal_path_empty', __( 'The path cannot be empty.', 'aouwal-static-site-export' ) );
		}

		if ( 0 === strpos( $raw, '~' ) ) {
			$home = getenv( 'HOME' );
			if ( ! $home && getenv( 'USERPROFILE' ) ) {
				$home = getenv( 'USERPROFILE' );
			}
			if ( $home ) {
				$raw = rtrim( $home, '/\\' ) . substr( $raw, 1 );
			}
		}

		$path = wp_normalize_path( $raw );
		if ( ! preg_match( '#^(/|[A-Za-z]:/)#', $path ) && ! path_is_absolute( $path ) ) {
			$cwd  = getcwd();
			$path = wp_normalize_path( ( $cwd ? $cwd : ABSPATH ) . '/' . $path );
		}
		if ( preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return new WP_Error( 'aouwal_path_traversal', __( 'The path cannot contain "..".', 'aouwal-static-site-export' ) );
		}

		$path      = $is_zip ? $path : untrailingslashit( $path );
		$forbidden = array(
			untrailingslashit( wp_normalize_path( ABSPATH ) ),
			untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ),
		);
		if ( in_array( $path, $forbidden, true ) ) {
			return new WP_Error( 'aouwal_path_root', __( 'The path cannot be the WordPress root or wp-content.', 'aouwal-static-site-export' ) );
		}

		if ( $is_zip ) {
			if ( '.zip' !== strtolower( substr( $path, -4 ) ) ) {
				return new WP_Error( 'aouwal_zip_extension', __( 'The --zip path must end in .zip.', 'aouwal-static-site-export' ) );
			}
			if ( is_dir( $path ) ) {
				return new WP_Error( 'aouwal_zip_dir', __( 'The --zip path is a directory.', 'aouwal-static-site-export' ) );
			}
			if ( file_exists( $path ) && ! $force ) {
				return new WP_Error( 'aouwal_zip_exists', __( '--zip already exists. Use --force to replace it.', 'aouwal-static-site-export' ) );
			}
			$parent = dirname( $path );
			if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
				return new WP_Error( 'aouwal_zip_parent', __( 'The parent directory for --zip does not exist and could not be created.', 'aouwal-static-site-export' ) );
			}
			return $path;
		}

		if ( file_exists( $path ) ) {
			if ( ! is_dir( $path ) ) {
				return new WP_Error( 'aouwal_output_file', __( '--output-dir points at a file.', 'aouwal-static-site-export' ) );
			}
			$entries = scandir( $path );
			$has     = is_array( $entries ) && count( array_diff( $entries, array( '.', '..' ) ) ) > 0;
			if ( $has && ! $force ) {
				return new WP_Error( 'aouwal_output_exists', __( '--output-dir is not empty. Use --force to replace it.', 'aouwal-static-site-export' ) );
			}
		}
		return $path;
	}

	/**
	 * Report a cancelled export and exit 1.
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
	 * Deploy the newest finished export to every enabled target.
	 *
	 * ## OPTIONS
	 *
	 * [--job=<id>]
	 * : Deploy this job instead of the newest one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aouwal-static-export deploy
	 *     wp aouwal-static-export deploy --job=0123456789abcdef0123
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function deploy( $args, $assoc_args ) {
		$exporter = new AOUWAL_Exporter();
		$state    = $exporter->status();
		$job_id   = isset( $assoc_args['job'] ) ? sanitize_text_field( (string) $assoc_args['job'] ) : $state['job_id'];

		if ( '' === $job_id ) {
			WP_CLI::error( 'No export to deploy.' );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{20}$/', $job_id ) ) {
			WP_CLI::error( 'The job id is not valid.' );
		}

		$job = $exporter->get_job( $job_id );
		if ( ! $job || AOUWAL_Exporter::PHASE_DONE !== $job['phase'] ) {
			WP_CLI::error( 'Only a finished export can be deployed.' );
		}

		$results = AOUWAL_Deploy::deploy_job( $job_id );
		if ( empty( $results ) ) {
			WP_CLI::error( 'No deployment target is enabled. Enable a target and save its settings in the Deploy tab first.' );
		}

		$exporter->record_deploy( $job_id, $results );

		$failed = false;
		foreach ( $results as $row ) {
			if ( ! empty( $row['ok'] ) ) {
				WP_CLI::log( $row['message'] );
			} else {
				$failed = true;
				WP_CLI::warning( $row['message'] );
			}
		}

		if ( $failed ) {
			WP_CLI::halt( 1 );
		}

		WP_CLI::success( 'Deployment complete.' );
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

		$this->finish_human( $exporter, $state['job_id'], $this->run_batches( $exporter, $state['job_id'], $batch, (int) $result['total'] ) );
	}
}

WP_CLI::add_command( 'aouwal-static-export', 'AOUWAL_CLI' );
