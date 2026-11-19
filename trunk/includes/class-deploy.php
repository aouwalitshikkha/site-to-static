<?php
/**
 * Deploy a finished export to a remote host.
 *
 * GitHub is the first target: a token, an owner/repository, a branch and an
 * optional path. The push uses the Git Data API, so only changed files are
 * uploaded and one commit lands per deployment.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_Deploy
 */
class AOUWAL_Deploy {

	/**
	 * Deployment settings option.
	 *
	 * Kept separate from aouwal_settings so saving the export settings cannot
	 * drop or rewrite deploy credentials.
	 *
	 * @var string
	 */
	const OPTION = 'aouwal_deploy';

	/**
	 * GitHub REST API base.
	 *
	 * @var string
	 */
	const API = 'https://api.github.com';

	/**
	 * Files per tree request. GitHub caps a tree at 100,000 entries and 7 MB,
	 * so a large site is committed in several tree chunks.
	 *
	 * @var int
	 */
	const TREE_CHUNK = 100;

	/**
	 * Default deployment settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'github_enabled'   => 0,
			'github_token'     => '',
			'github_owner'     => '',
			'github_repo'      => '',
			'github_branch'    => 'main',
			'github_path'      => '',
			'github_message'   => 'Static export {date}',
			'github_prune'     => 0,
			'github_on_export' => 0,
		);
	}

	/**
	 * All deployment settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * A single deployment setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value when the setting is not stored.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Register a settings error when the admin UI is loaded.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return void
	 */
	protected static function notice( $code, $message ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, $code, $message );
		}
	}

	/**
	 * Sanitize deployment settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$d      = self::defaults();
		$stored = self::all();
		$out    = array();

		$booleans = array( 'github_enabled', 'github_prune', 'github_on_export' );
		foreach ( $booleans as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
		}

		$out['github_owner'] = isset( $input['github_owner'] ) ? self::sanitize_slug( (string) $input['github_owner'] ) : '';
		$out['github_repo']  = isset( $input['github_repo'] ) ? self::sanitize_slug( (string) $input['github_repo'] ) : '';
		$out['github_repo']  = preg_replace( '/\.git$/i', '', $out['github_repo'] );

		$branch = isset( $input['github_branch'] ) ? sanitize_text_field( (string) $input['github_branch'] ) : $d['github_branch'];
		$branch = preg_replace( '#[^A-Za-z0-9._/-]#', '', str_replace( '\\', '/', $branch ) );
		$branch = preg_replace( '#/{2,}#', '/', (string) $branch );
		$branch = trim( $branch, '/' );
		if ( '' === $branch ) {
			$branch = $d['github_branch'];
		}
		$out['github_branch'] = $branch;

		$out['github_path'] = isset( $input['github_path'] ) ? self::sanitize_path( (string) $input['github_path'] ) : '';

		$message = isset( $input['github_message'] ) ? sanitize_text_field( (string) $input['github_message'] ) : $d['github_message'];
		if ( '' === $message ) {
			$message = $d['github_message'];
		}
		$out['github_message'] = $message;

		// A blank token field keeps the stored token, so saving an unrelated
		// deployment setting does not wipe it. Removal is explicit.
		$out['github_token'] = isset( $stored['github_token'] ) ? (string) $stored['github_token'] : '';

		if ( ! empty( $input['github_clear'] ) ) {
			$out['github_token'] = '';
		} elseif ( isset( $input['github_token'] ) && '' !== trim( (string) $input['github_token'] ) ) {
			$token = trim( (string) $input['github_token'] );
			if ( ! preg_match( '/^[A-Za-z0-9_.-]+$/', $token ) ) {
				self::notice(
					'aouwal_github_token',
					__( 'The GitHub token was not saved: it contains characters a token never contains, such as spaces or newlines.', 'aouwal-static-site-export' )
				);
			} else {
				$encrypted = AOUWAL_Network::encrypt( $token );
				if ( is_wp_error( $encrypted ) ) {
					self::notice(
						'aouwal_github_token',
						__( 'The GitHub token could not be stored securely because this server has neither the sodium extension nor OpenSSL support.', 'aouwal-static-site-export' )
					);
				} else {
					$out['github_token'] = (string) $encrypted;
				}
			}
		}

		return wp_parse_args( $out, $d );
	}

	/**
	 * Restrict an owner or repository name to GitHub-safe characters.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	protected static function sanitize_slug( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9._-]/', '', trim( $value ) );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Sanitize an optional repository subfolder.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	protected static function sanitize_path( $value ) {
		$value = str_replace( '\\', '/', $value );
		$value = preg_replace( '#/{2,}#', '/', trim( $value, '/' ) );
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		if ( preg_match( '#(^|/)\.\.(/|$)#', $value ) || preg_match( '#[^a-zA-Z0-9_./-]#', $value ) ) {
			self::notice(
				'aouwal_github_path',
				__( 'The repository path was ignored. Use a relative folder with letters, numbers, dashes, underscores and slashes only.', 'aouwal-static-site-export' )
			);
			return '';
		}
		return $value;
	}

	/**
	 * Whether a token is stored.
	 *
	 * @return bool
	 */
	public static function has_token() {
		return '' !== self::token();
	}

	/**
	 * Decrypted token, or an empty string.
	 *
	 * The plain token is only ever handed to the HTTP API request. It is never
	 * rendered, logged or stored in the job record.
	 *
	 * @return string
	 */
	public static function token() {
		$stored = (string) self::get( 'github_token', '' );
		if ( '' === $stored ) {
			return '';
		}
		return (string) AOUWAL_Network::decrypt( $stored );
	}

	/**
	 * Whether GitHub has every required value.
	 *
	 * @return bool
	 */
	public static function github_configured() {
		return '' !== (string) self::get( 'github_owner', '' )
			&& '' !== (string) self::get( 'github_repo', '' )
			&& self::has_token();
	}

	/**
	 * Targets that are enabled and ready to receive a deployment.
	 *
	 * Nothing is returned for a target that is switched off, so an opt-in
	 * setting is the only way a remote request can happen.
	 *
	 * @return string[]
	 */
	public static function enabled_targets() {
		$targets = array();
		if ( self::get( 'github_enabled' ) && self::github_configured() ) {
			$targets[] = 'github';
		}
		return $targets;
	}

	/**
	 * Whether a finished export should be deployed automatically.
	 *
	 * @return bool
	 */
	public static function auto_enabled() {
		return (bool) self::get( 'github_on_export' ) && in_array( 'github', self::enabled_targets(), true );
	}

	/**
	 * Deploy a finished export to every enabled target.
	 *
	 * @param string $job_id Job ID.
	 * @return array[] One result row per target.
	 */
	public static function deploy_job( $job_id ) {
		$targets = self::enabled_targets();
		if ( empty( $targets ) ) {
			return array();
		}

		$lock = 'aouwal_deploy_' . preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $job_id ) );
		if ( get_transient( $lock ) ) {
			return array( self::fail_result( 'deploy', __( 'A deployment for this export is already running.', 'aouwal-static-site-export' ) ) );
		}
		set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );

		$results = array();
		foreach ( $targets as $target ) {
			if ( 'github' === $target ) {
				$results[] = self::deploy_github( $job_id );
			}
		}

		delete_transient( $lock );
		return $results;
	}

	/**
	 * Deployment targets reported by the last run of a job.
	 *
	 * @param array $job Job data.
	 * @return array[]
	 */
	public static function last_results( array $job ) {
		return ( ! empty( $job['deploy'] ) && is_array( $job['deploy'] ) ) ? $job['deploy'] : array();
	}

	/**
	 * Verify the token can read the repository and the target branch.
	 *
	 * @return array|WP_Error
	 */
	public static function test_github() {
		if ( ! self::github_configured() ) {
			return new WP_Error(
				'aouwal_github_config',
				__( 'Add the repository owner, the repository name and a token first.', 'aouwal-static-site-export' )
			);
		}

		$repo = self::request( 'GET', self::repo_path() );
		if ( is_wp_error( $repo ) ) {
			return $repo;
		}

		$branch = (string) self::get( 'github_branch', 'main' );
		$ref    = self::request( 'GET', self::repo_path( '/git/ref/heads/' . self::encode_ref( $branch ) ) );
		$exists = ! is_wp_error( $ref );
		if ( ! $exists && 404 !== self::error_status( $ref ) ) {
			return $ref;
		}

		$head     = ( $exists && ! empty( $ref['object']['sha'] ) ) ? (string) $ref['object']['sha'] : '';
		$full     = isset( $repo['full_name'] ) ? (string) $repo['full_name'] : self::repo_slug();
		$default  = isset( $repo['default_branch'] ) ? (string) $repo['default_branch'] : '';
		$can_push = isset( $repo['permissions']['push'] ) ? (bool) $repo['permissions']['push'] : true;

		if ( $exists ) {
			/* translators: 1: repository name, 2: branch name. */
			$message = sprintf( __( 'Connected to %1$s. Branch %2$s exists.', 'aouwal-static-site-export' ), $full, $branch );
		} else {
			/* translators: 1: repository name, 2: branch name. */
			$message = sprintf( __( 'Connected to %1$s. Branch %2$s does not exist yet and will be created on the first deployment.', 'aouwal-static-site-export' ), $full, $branch );
		}

		return array(
			'repo'           => $full,
			'private'        => ! empty( $repo['private'] ),
			'default_branch' => $default,
			'branch'         => $branch,
			'branch_exists'  => $exists,
			'head'           => $head,
			'can_push'       => $can_push,
			'message'        => $message,
		);
	}

	/**
	 * Push the export folder to GitHub.
	 *
	 * @param string $job_id Job ID.
	 * @return array Result row.
	 */
	public static function deploy_github( $job_id ) {
		$result = self::result( 'github' );

		if ( ! self::github_configured() ) {
			return self::fail_result( 'github', __( 'GitHub is not configured. Add an owner, a repository and a token.', 'aouwal-static-site-export' ) );
		}

		$exporter = new AOUWAL_Exporter();
		$job      = $exporter->get_job( $job_id );
		if ( ! $job || empty( $job['output_dir'] ) ) {
			return self::fail_result( 'github', __( 'Export job not found.', 'aouwal-static-site-export' ) );
		}
		if ( AOUWAL_Exporter::PHASE_DONE !== $job['phase'] ) {
			return self::fail_result( 'github', __( 'Only a finished export can be deployed.', 'aouwal-static-site-export' ) );
		}

		$root = untrailingslashit( wp_normalize_path( (string) $job['output_dir'] ) );
		$fs   = AOUWAL_Writer::filesystem_instance();
		if ( ! $fs || ! $fs->is_dir( $root ) ) {
			return self::fail_result( 'github', __( 'The export files are missing. Run the export again.', 'aouwal-static-site-export' ) );
		}

		$branch = (string) self::get( 'github_branch', 'main' );
		$repo   = self::repo_slug();

		$local = array();
		self::scan_files( $fs, $root, '', $local );
		if ( empty( $local ) ) {
			return self::fail_result( 'github', __( 'The export folder is empty.', 'aouwal-static-site-export' ) );
		}

		// Resolve the branch head. A missing branch is created from the default
		// branch, so the first push to gh-pages does not start from nothing.
		$head          = '';
		$base_tree     = '';
		$branch_exists = false;
		$ref           = self::request( 'GET', self::repo_path( '/git/ref/heads/' . self::encode_ref( $branch ) ) );
		if ( ! is_wp_error( $ref ) && ! empty( $ref['object']['sha'] ) ) {
			$head          = (string) $ref['object']['sha'];
			$branch_exists = true;
		} elseif ( is_wp_error( $ref ) && 404 !== self::error_status( $ref ) ) {
			return self::fail_result( 'github', self::error_message( $ref ) );
		} else {
			$repo_info = self::request( 'GET', self::repo_path() );
			if ( is_wp_error( $repo_info ) ) {
				return self::fail_result( 'github', self::error_message( $repo_info ) );
			}
			$default = isset( $repo_info['default_branch'] ) ? (string) $repo_info['default_branch'] : '';
			if ( '' !== $default && $default !== $branch ) {
				$base_ref = self::request( 'GET', self::repo_path( '/git/ref/heads/' . self::encode_ref( $default ) ) );
				if ( ! is_wp_error( $base_ref ) && ! empty( $base_ref['object']['sha'] ) ) {
					$head = (string) $base_ref['object']['sha'];
				}
			}
		}

		$remote  = array();
		$compare = false;
		if ( '' !== $head ) {
			$commit = self::request( 'GET', self::repo_path( '/git/commits/' . $head ) );
			if ( is_wp_error( $commit ) ) {
				return self::fail_result( 'github', self::error_message( $commit ) );
			}
			$base_tree = isset( $commit['tree']['sha'] ) ? (string) $commit['tree']['sha'] : '';
			if ( '' === $base_tree ) {
				return self::fail_result( 'github', __( 'GitHub did not return a tree for the branch head.', 'aouwal-static-site-export' ) );
			}

			$tree = self::request( 'GET', self::repo_path( '/git/trees/' . rawurlencode( $base_tree ) . '?recursive=1' ) );
			if ( ! is_wp_error( $tree ) && empty( $tree['truncated'] ) && ! empty( $tree['tree'] ) && is_array( $tree['tree'] ) ) {
				foreach ( $tree['tree'] as $entry ) {
					if ( isset( $entry['type'], $entry['path'], $entry['sha'] ) && 'blob' === $entry['type'] ) {
						$remote[ (string) $entry['path'] ] = (string) $entry['sha'];
					}
				}
				$compare = true;
			}
		}

		$entries = array();
		$files   = 0;
		$skipped = 0;

		foreach ( $local as $relative => $sha ) {
			$path = self::remote_path( $relative );
			if ( $compare && isset( $remote[ $path ] ) && $remote[ $path ] === $sha ) {
				++$skipped;
				continue;
			}

			$content = $fs->get_contents( trailingslashit( $root ) . $relative );
			if ( ! is_string( $content ) ) {
				return self::fail_result(
					'github',
					sprintf(
					/* translators: %s: file path inside the export. */
						__( 'Could not read %s from the export folder.', 'aouwal-static-site-export' ),
						$relative
					)
				);
			}

			$blob = self::request(
				'POST',
				self::repo_path( '/git/blobs' ),
				array(
					'content'  => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- GitHub's blob API requires base64 for binary files.
					'encoding' => 'base64',
				)
			);
			if ( is_wp_error( $blob ) ) {
				return self::fail_result(
					'github',
					sprintf(
					/* translators: 1: file path, 2: GitHub error text. */
						__( 'Uploading %1$s failed: %2$s', 'aouwal-static-site-export' ),
						$relative,
						self::error_message( $blob )
					)
				);
			}
			if ( empty( $blob['sha'] ) ) {
				return self::fail_result(
					'github',
					sprintf(
					/* translators: %s: file path. */
						__( 'GitHub did not return a blob for %s.', 'aouwal-static-site-export' ),
						$relative
					)
				);
			}

			$entries[] = array(
				'path' => $path,
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => (string) $blob['sha'],
			);
			++$files;
		}

		$deleted = 0;
		if ( $compare && $branch_exists && self::get( 'github_prune' ) ) {
			$prefix   = trim( (string) self::get( 'github_path', '' ), '/' );
			$deployed = array();
			foreach ( $local as $relative => $unused ) {
				$deployed[ self::remote_path( $relative ) ] = true;
			}
			foreach ( $remote as $path => $unused ) {
				if ( '' !== $prefix && 0 !== strpos( $path, $prefix . '/' ) ) {
					continue;
				}
				if ( isset( $deployed[ $path ] ) ) {
					continue;
				}
				$entries[] = array(
					'path' => $path,
					'mode' => '100644',
					'type' => 'blob',
					'sha'  => null,
				);
				++$deleted;
			}
		}

		if ( empty( $entries ) ) {
			$result['ok']      = true;
			$result['skipped'] = $skipped;
			/* translators: 1: repository name, 2: branch name. */
			$result['message'] = sprintf( __( 'Already up to date on %1$s@%2$s. No commit was created.', 'aouwal-static-site-export' ), $repo, $branch );
			return $result;
		}

		$tree_sha = $base_tree;
		foreach ( array_chunk( $entries, self::TREE_CHUNK ) as $chunk ) {
			$body = array( 'tree' => $chunk );
			if ( '' !== $tree_sha ) {
				$body['base_tree'] = $tree_sha;
			}
			$tree = self::request( 'POST', self::repo_path( '/git/trees' ), $body );
			if ( is_wp_error( $tree ) ) {
				return self::fail_result(
					'github',
					sprintf(
					/* translators: %s: GitHub error text. */
						__( 'Building the commit tree failed: %s', 'aouwal-static-site-export' ),
						self::error_message( $tree )
					)
				);
			}
			$tree_sha = isset( $tree['sha'] ) ? (string) $tree['sha'] : '';
			if ( '' === $tree_sha ) {
				return self::fail_result( 'github', __( 'GitHub did not return a tree SHA.', 'aouwal-static-site-export' ) );
			}
		}

		$commit_body = array(
			'message' => self::commit_message(),
			'tree'    => $tree_sha,
		);
		if ( '' !== $head ) {
			$commit_body['parents'] = array( $head );
		}

		$commit = self::request( 'POST', self::repo_path( '/git/commits' ), $commit_body );
		if ( is_wp_error( $commit ) ) {
			return self::fail_result(
				'github',
				sprintf(
				/* translators: %s: GitHub error text. */
					__( 'Creating the commit failed: %s', 'aouwal-static-site-export' ),
					self::error_message( $commit )
				)
			);
		}
		if ( empty( $commit['sha'] ) ) {
			return self::fail_result( 'github', __( 'GitHub did not return a commit SHA.', 'aouwal-static-site-export' ) );
		}

		$sha = (string) $commit['sha'];
		if ( $branch_exists ) {
			$updated = self::request(
				'PATCH',
				self::repo_path( '/git/refs/heads/' . self::encode_ref( $branch ) ),
				array(
					'sha'   => $sha,
					'force' => false,
				)
			);
		} else {
			$updated = self::request(
				'POST',
				self::repo_path( '/git/refs' ),
				array(
					'ref' => 'refs/heads/' . $branch,
					'sha' => $sha,
				)
			);
		}
		if ( is_wp_error( $updated ) ) {
			return self::fail_result(
				'github',
				sprintf(
				/* translators: %s: GitHub error text. */
					__( 'Updating the branch failed: %s', 'aouwal-static-site-export' ),
					self::error_message( $updated )
				)
			);
		}

		$result['ok']      = true;
		$result['commit']  = $sha;
		$result['url']     = isset( $commit['html_url'] ) ? (string) $commit['html_url'] : '';
		$result['files']   = $files;
		$result['deleted'] = $deleted;
		$result['skipped'] = $skipped;
		$result['message'] = sprintf(
			/* translators: 1: number of uploaded files, 2: repository name, 3: branch, 4: short commit SHA, 5: number of unchanged files, 6: number of removed files. */
			__( 'Pushed %1$d file(s) to %2$s@%3$s in commit %4$s. %5$d unchanged, %6$d removed.', 'aouwal-static-site-export' ),
			$files,
			$repo,
			$branch,
			substr( $sha, 0, 7 ),
			$skipped,
			$deleted
		);

		return $result;
	}

	/**
	 * GitHub repository path for an API suffix.
	 *
	 * @param string $suffix Suffix beginning with a slash.
	 * @return string
	 */
	protected static function repo_path( $suffix = '' ) {
		return '/repos/' . rawurlencode( (string) self::get( 'github_owner', '' ) ) . '/' . rawurlencode( (string) self::get( 'github_repo', '' ) ) . $suffix;
	}

	/**
	 * Owner/repository for messages.
	 *
	 * @return string
	 */
	protected static function repo_slug() {
		return (string) self::get( 'github_owner', '' ) . '/' . (string) self::get( 'github_repo', '' );
	}

	/**
	 * Encode a branch name for a ref path, keeping its slashes.
	 *
	 * @param string $branch Branch name.
	 * @return string
	 */
	protected static function encode_ref( $branch ) {
		$parts = array_map( 'rawurlencode', explode( '/', (string) $branch ) );
		return implode( '/', $parts );
	}

	/**
	 * Perform a GitHub API request through the WordPress HTTP API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   API path beginning with a slash.
	 * @param array|null $body   JSON body or null.
	 * @return array|WP_Error Decoded response or error.
	 */
	protected static function request( $method, $path, $body = null ) {
		$token = self::token();
		if ( '' === $token ) {
			return new WP_Error( 'aouwal_github_token', __( 'No GitHub token is saved.', 'aouwal-static-site-export' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => (int) apply_filters( 'aouwal_deploy_timeout', 60 ),
			'headers' => array(
				'Authorization'        => 'Bearer ' . $token,
				'Accept'               => 'application/vnd.github+json',
				'X-GitHub-Api-Version' => '2022-11-28',
				'User-Agent'           => 'Aouwal-Static-Site-Export/' . AOUWAL_VERSION,
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'aouwal_github_network', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = ( is_array( $data ) && ! empty( $data['message'] ) )
				? (string) $data['message']
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'GitHub returned HTTP %d.', 'aouwal-static-site-export' ), $code );

			if ( is_array( $data ) && ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$details = array();
				foreach ( $data['errors'] as $error ) {
					if ( is_array( $error ) && ! empty( $error['message'] ) ) {
						$details[] = (string) $error['message'];
					} elseif ( is_string( $error ) ) {
						$details[] = $error;
					}
				}
				if ( ! empty( $details ) ) {
					$message .= ' (' . implode( '; ', $details ) . ')';
				}
			}

			return new WP_Error( 'aouwal_github_http', $message, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * HTTP status stored on a request error.
	 *
	 * @param WP_Error $error Error.
	 * @return int
	 */
	protected static function error_status( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return 0;
		}
		$data = $error->get_error_data();
		return ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 0;
	}

	/**
	 * Safe error text. The token is never part of an API error message.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	protected static function error_message( $error ) {
		return is_wp_error( $error ) ? (string) $error->get_error_message() : '';
	}

	/**
	 * Walk the export folder and record a git blob SHA per file.
	 *
	 * Reading each file twice (hash, then upload only if changed) keeps memory
	 * flat on a large media library.
	 *
	 * @param WP_Filesystem_Base $fs     Filesystem.
	 * @param string             $dir    Absolute directory.
	 * @param string             $prefix Relative prefix.
	 * @param array              $out    Relative path => blob SHA, by reference.
	 * @return void
	 */
	protected static function scan_files( $fs, $dir, $prefix, array &$out ) {
		$entries = $fs->dirlist( $dir );
		if ( ! is_array( $entries ) ) {
			return;
		}

		foreach ( $entries as $name => $info ) {
			if ( '.' === $name || '..' === $name ) {
				continue;
			}
			$path     = trailingslashit( $dir ) . $name;
			$relative = '' === $prefix ? (string) $name : $prefix . '/' . $name;

			if ( ! empty( $info['type'] ) && 'd' === $info['type'] ) {
				self::scan_files( $fs, $path, $relative, $out );
				continue;
			}

			$content = $fs->get_contents( $path );
			if ( ! is_string( $content ) ) {
				continue;
			}
			$out[ $relative ] = sha1( 'blob ' . strlen( $content ) . "\0" . $content );
		}
	}

	/**
	 * Repository path for an export file.
	 *
	 * @param string $relative Relative file path.
	 * @return string
	 */
	protected static function remote_path( $relative ) {
		$prefix = trim( (string) self::get( 'github_path', '' ), '/' );
		return ( '' === $prefix ) ? $relative : $prefix . '/' . $relative;
	}

	/**
	 * Commit message for the current deployment.
	 *
	 * @return string
	 */
	protected static function commit_message() {
		$message = (string) self::get( 'github_message', 'Static export {date}' );
		$message = str_replace( '{date}', current_time( 'Y-m-d H:i' ), $message );
		$message = trim( $message );
		if ( '' === $message ) {
			$message = 'Static export ' . current_time( 'Y-m-d H:i' );
		}
		return $message;
	}

	/**
	 * Empty result row.
	 *
	 * @param string $target Target key.
	 * @return array
	 */
	protected static function result( $target ) {
		return array(
			'target'  => $target,
			'ok'      => false,
			'message' => '',
			'commit'  => '',
			'url'     => '',
			'files'   => 0,
			'deleted' => 0,
			'skipped' => 0,
			'time'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Failed result row with a message.
	 *
	 * @param string $target  Target key.
	 * @param string $message Failure text.
	 * @return array
	 */
	protected static function fail_result( $target, $message ) {
		$result            = self::result( $target );
		$result['message'] = (string) $message;
		return $result;
	}
}
