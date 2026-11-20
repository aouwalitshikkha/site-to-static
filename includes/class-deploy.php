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
			'github_enabled'        => 0,
			'github_token'          => '',
			'github_owner'          => '',
			'github_repo'           => '',
			'github_branch'         => 'main',
			'github_path'           => '',
			'github_message'        => 'Static export {date}',
			'github_prune'          => 0,
			'github_on_export'      => 0,

			'cloudflare_enabled'    => 0,
			'cloudflare_token'      => '',
			'cloudflare_account_id' => '',
			'cloudflare_project'    => '',
			'cloudflare_branch'     => '',
			'cloudflare_message'    => 'Static export {date}',
			'cloudflare_on_export'  => 0,
			'cloudflare_zone_id'    => '',
			'cloudflare_purge'      => 0,

			'netlify_enabled'       => 0,
			'netlify_token'         => '',
			'netlify_site_id'       => '',
			'netlify_on_export'     => 0,
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

		$booleans = array( 'github_enabled', 'github_prune', 'github_on_export', 'cloudflare_enabled', 'cloudflare_on_export', 'cloudflare_purge', 'netlify_enabled', 'netlify_on_export' );
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
		$out['github_token'] = self::sanitize_secret( $input, $stored, 'github_token', 'github_clear', 'GitHub' );

		$account = isset( $input['cloudflare_account_id'] ) ? strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $input['cloudflare_account_id'] ) ) : '';
		if ( '' !== trim( (string) ( isset( $input['cloudflare_account_id'] ) ? $input['cloudflare_account_id'] : '' ) ) && 32 !== strlen( (string) $account ) ) {
			self::notice(
				'aouwal_cloudflare_account',
				__( 'The Cloudflare account ID was ignored. It is the 32-character hexadecimal value from the dashboard URL or the overview page.', 'aouwal-static-site-export' )
			);
			$account = '';
		}
		$out['cloudflare_account_id'] = $account;

		$zone = isset( $input['cloudflare_zone_id'] ) ? strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $input['cloudflare_zone_id'] ) ) : '';
		if ( '' !== trim( (string) ( isset( $input['cloudflare_zone_id'] ) ? $input['cloudflare_zone_id'] : '' ) ) && 32 !== strlen( (string) $zone ) ) {
			self::notice(
				'aouwal_cloudflare_zone',
				__( 'The Cloudflare zone ID was ignored. It is the 32-character hexadecimal value on the zone overview page.', 'aouwal-static-site-export' )
			);
			$zone = '';
		}
		$out['cloudflare_zone_id'] = $zone;

		$out['cloudflare_project'] = isset( $input['cloudflare_project'] ) ? strtolower( preg_replace( '/[^a-z0-9-]/i', '', (string) $input['cloudflare_project'] ) ) : '';

		$cf_branch                = isset( $input['cloudflare_branch'] ) ? sanitize_text_field( (string) $input['cloudflare_branch'] ) : '';
		$cf_branch                = preg_replace( '#[^A-Za-z0-9._/-]#', '', str_replace( '\\', '/', $cf_branch ) );
		$cf_branch                = preg_replace( '#/{2,}#', '/', (string) $cf_branch );
		$out['cloudflare_branch'] = trim( (string) $cf_branch, '/' );

		$cf_message = isset( $input['cloudflare_message'] ) ? sanitize_text_field( (string) $input['cloudflare_message'] ) : $d['cloudflare_message'];
		if ( '' === $cf_message ) {
			$cf_message = $d['cloudflare_message'];
		}
		$out['cloudflare_message'] = $cf_message;

		$out['cloudflare_token'] = self::sanitize_secret( $input, $stored, 'cloudflare_token', 'cloudflare_clear', 'Cloudflare' );

		$site_id                = isset( $input['netlify_site_id'] ) ? sanitize_text_field( (string) $input['netlify_site_id'] ) : '';
		$site_id                = preg_replace( '/[^A-Za-z0-9.-]/', '', trim( $site_id ) );
		$out['netlify_site_id'] = is_string( $site_id ) ? $site_id : '';

		$out['netlify_token'] = self::sanitize_secret( $input, $stored, 'netlify_token', 'netlify_clear', 'Netlify' );

		return wp_parse_args( $out, $d );
	}

	/**
	 * Sanitize an encrypted token field.
	 *
	 * A blank field keeps the stored value and an explicit clear checkbox
	 * removes it, matching the Basic Auth password behavior.
	 *
	 * @param array  $input     Raw input.
	 * @param array  $stored    Stored settings.
	 * @param string $key       Token key.
	 * @param string $clear_key Clear checkbox key.
	 * @param string $label     Target name for messages.
	 * @return string
	 */
	protected static function sanitize_secret( array $input, array $stored, $key, $clear_key, $label ) {
		$value = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';

		if ( ! empty( $input[ $clear_key ] ) ) {
			return '';
		}

		if ( ! isset( $input[ $key ] ) || '' === trim( (string) $input[ $key ] ) ) {
			return $value;
		}

		$token = trim( (string) $input[ $key ] );
		if ( ! preg_match( '/^[A-Za-z0-9_.-]+$/', $token ) ) {
			self::notice(
				'aouwal_' . $key,
				sprintf(
					/* translators: %s: deployment target, such as GitHub or Cloudflare. */
					__( 'The %s token was not saved: it contains characters a token never contains, such as spaces or newlines.', 'aouwal-static-site-export' ),
					$label
				)
			);
			return $value;
		}

		$encrypted = AOUWAL_Network::encrypt( $token );
		if ( is_wp_error( $encrypted ) ) {
			self::notice(
				'aouwal_' . $key,
				sprintf(
					/* translators: %s: deployment target, such as GitHub or Cloudflare. */
					__( 'The %s token could not be stored securely because this server has neither the sodium extension nor OpenSSL support.', 'aouwal-static-site-export' ),
					$label
				)
			);
			return $value;
		}

		return (string) $encrypted;
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
	 * Whether a Cloudflare token is stored.
	 *
	 * @return bool
	 */
	public static function has_cloudflare_token() {
		return '' !== self::cloudflare_token();
	}

	/**
	 * Decrypted Cloudflare API token.
	 *
	 * @return string
	 */
	public static function cloudflare_token() {
		$stored = (string) self::get( 'cloudflare_token', '' );
		if ( '' === $stored ) {
			return '';
		}
		return (string) AOUWAL_Network::decrypt( $stored );
	}

	/**
	 * Whether Cloudflare Pages has every required value.
	 *
	 * @return bool
	 */
	public static function cloudflare_configured() {
		return '' !== (string) self::get( 'cloudflare_account_id', '' )
			&& '' !== (string) self::get( 'cloudflare_project', '' )
			&& self::has_cloudflare_token();
	}

	/**
	 * Whether a Netlify token is stored.
	 *
	 * @return bool
	 */
	public static function has_netlify_token() {
		return '' !== self::netlify_token();
	}

	/**
	 * Decrypted Netlify personal access token.
	 *
	 * @return string
	 */
	public static function netlify_token() {
		$stored = (string) self::get( 'netlify_token', '' );
		if ( '' === $stored ) {
			return '';
		}
		return (string) AOUWAL_Network::decrypt( $stored );
	}

	/**
	 * Whether Netlify has every required value.
	 *
	 * @return bool
	 */
	public static function netlify_configured() {
		return '' !== (string) self::get( 'netlify_site_id', '' ) && self::has_netlify_token();
	}

	/**
	 * Registered deployment targets.
	 *
	 * One place decides the order, label, configuration check, test method and
	 * deploy method for every target. Adding a target means one entry here plus
	 * its settings and methods.
	 *
	 * @return array<string,array{label:string,configured:string,test:string,deploy:string}>
	 */
	public static function targets() {
		return array(
			'github'     => array(
				'label'      => __( 'GitHub', 'aouwal-static-site-export' ),
				'configured' => 'github_configured',
				'test'       => 'test_github',
				'deploy'     => 'deploy_github',
			),
			'cloudflare' => array(
				'label'      => __( 'Cloudflare Pages', 'aouwal-static-site-export' ),
				'configured' => 'cloudflare_configured',
				'test'       => 'test_cloudflare',
				'deploy'     => 'deploy_cloudflare',
			),
			'netlify'    => array(
				'label'      => __( 'Netlify', 'aouwal-static-site-export' ),
				'configured' => 'netlify_configured',
				'test'       => 'test_netlify',
				'deploy'     => 'deploy_netlify',
			),
		);
	}

	/**
	 * Human label for a target key.
	 *
	 * @param string $target Target key.
	 * @return string
	 */
	public static function target_label( $target ) {
		$targets = self::targets();
		return isset( $targets[ $target ] ) ? (string) $targets[ $target ]['label'] : (string) $target;
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
		foreach ( self::targets() as $key => $target ) {
			if ( self::get( $key . '_enabled' ) && call_user_func( array( __CLASS__, $target['configured'] ) ) ) {
				$targets[] = $key;
			}
		}
		return $targets;
	}

	/**
	 * Enabled targets that should run after every successful export.
	 *
	 * @return string[]
	 */
	public static function auto_targets() {
		$targets = array();
		foreach ( self::enabled_targets() as $target ) {
			if ( self::get( $target . '_on_export' ) ) {
				$targets[] = $target;
			}
		}
		return $targets;
	}

	/**
	 * Whether any target deploys automatically.
	 *
	 * @return bool
	 */
	public static function auto_enabled() {
		return ! empty( self::auto_targets() );
	}

	/**
	 * Deploy a finished export to the requested targets.
	 *
	 * @param string     $job_id  Job ID.
	 * @param array|null $targets Target keys, or null for every enabled target.
	 * @return array[] One result row per target.
	 */
	public static function deploy_job( $job_id, $targets = null ) {
		$enabled = self::enabled_targets();
		if ( null === $targets ) {
			$targets = $enabled;
		}
		$targets = array_values( array_intersect( (array) $targets, $enabled ) );
		if ( empty( $targets ) ) {
			return array();
		}

		$lock = 'aouwal_deploy_' . preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $job_id ) );
		if ( get_transient( $lock ) ) {
			return array( self::fail_result( 'deploy', __( 'A deployment for this export is already running.', 'aouwal-static-site-export' ) ) );
		}
		set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );

		$results = array();
		$map     = self::targets();
		foreach ( $targets as $target ) {
			if ( isset( $map[ $target ] ) ) {
				$results[] = call_user_func( array( __CLASS__, $map[ $target ]['deploy'] ), $job_id );
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
	 * Test every enabled target.
	 *
	 * @return array[] Result rows with target, ok and message.
	 */
	public static function test_targets() {
		$results = array();
		$map     = self::targets();
		foreach ( self::enabled_targets() as $target ) {
			if ( ! isset( $map[ $target ] ) ) {
				continue;
			}
			$test = call_user_func( array( __CLASS__, $map[ $target ]['test'] ) );

			if ( is_wp_error( $test ) ) {
				$results[] = array(
					'target'  => $target,
					'ok'      => false,
					'message' => $test->get_error_message(),
				);
			} else {
				$results[] = array(
					'target'  => $target,
					'ok'      => true,
					'message' => isset( $test['message'] ) ? (string) $test['message'] : '',
				);
			}
		}
		return $results;
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
	 * Verify the Cloudflare token can read the Pages project.
	 *
	 * @return array|WP_Error
	 */
	public static function test_cloudflare() {
		if ( ! self::cloudflare_configured() ) {
			return new WP_Error(
				'aouwal_cloudflare_config',
				__( 'Add the Cloudflare account ID, the Pages project name and an API token first.', 'aouwal-static-site-export' )
			);
		}

		$response = self::cloudflare_request( 'GET', self::cloudflare_project_path() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$project = isset( $response['result'] ) && is_array( $response['result'] ) ? $response['result'] : array();
		$name    = isset( $project['name'] ) ? (string) $project['name'] : (string) self::get( 'cloudflare_project', '' );
		$branch  = isset( $project['production_branch'] ) ? (string) $project['production_branch'] : '';
		$sub     = isset( $project['subdomain'] ) ? (string) $project['subdomain'] : '';

		/* translators: 1: Pages project name, 2: production branch. */
		$message = sprintf( __( 'Connected to Cloudflare Pages project %1$s (production branch %2$s).', 'aouwal-static-site-export' ), $name, $branch );
		$host    = (string) $sub;
		if ( '' !== $host && ! preg_match( '/\.pages\.dev$/i', $host ) ) {
			$host .= '.pages.dev';
		}
		if ( '' !== $host ) {
			/* translators: %s: pages.dev hostname. */
			$message .= ' ' . sprintf( __( 'Preview URL: https://%s.', 'aouwal-static-site-export' ), $host );
		}
		if ( ! empty( $project['source'] ) ) {
			$message .= ' ' . __( 'This project is connected to Git, so direct uploads are not supported. Use the GitHub target instead.', 'aouwal-static-site-export' );
		}

		return array(
			'project'           => $name,
			'production_branch' => $branch,
			'subdomain'         => $sub,
			'message'           => $message,
		);
	}

	/**
	 * Verify the Netlify token can read the site.
	 *
	 * @return array|WP_Error
	 */
	public static function test_netlify() {
		if ( ! self::netlify_configured() ) {
			return new WP_Error(
				'aouwal_netlify_config',
				__( 'Add the Netlify site ID and a personal access token first.', 'aouwal-static-site-export' )
			);
		}

		$response = self::netlify_request( 'GET', '/sites/' . rawurlencode( (string) self::get( 'netlify_site_id', '' ) ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$name = isset( $response['name'] ) ? (string) $response['name'] : (string) self::get( 'netlify_site_id', '' );
		$url  = isset( $response['ssl_url'] ) ? (string) $response['ssl_url'] : ( isset( $response['url'] ) ? (string) $response['url'] : '' );

		/* translators: 1: Netlify site name, 2: site URL. */
		$message = sprintf( __( 'Connected to Netlify site %1$s (%2$s).', 'aouwal-static-site-export' ), $name, $url );

		return array(
			'site'    => $name,
			'url'     => $url,
			'message' => $message,
		);
	}

	/**
	 * Upload the export folder to Netlify.
	 *
	 * Uses Netlify's file digest deploy: a SHA1 manifest, the missing hashes
	 * from the deploy response, one upload per missing hash, then a state poll.
	 *
	 * @param string $job_id Job ID.
	 * @return array Result row.
	 */
	public static function deploy_netlify( $job_id ) {
		if ( ! self::netlify_configured() ) {
			return self::fail_result( 'netlify', __( 'Netlify is not configured. Add a site ID and a personal access token.', 'aouwal-static-site-export' ) );
		}

		$exporter = new AOUWAL_Exporter();
		$job      = $exporter->get_job( $job_id );
		if ( ! $job || empty( $job['output_dir'] ) ) {
			return self::fail_result( 'netlify', __( 'Export job not found.', 'aouwal-static-site-export' ) );
		}
		if ( AOUWAL_Exporter::PHASE_DONE !== $job['phase'] ) {
			return self::fail_result( 'netlify', __( 'Only a finished export can be deployed.', 'aouwal-static-site-export' ) );
		}

		$root = untrailingslashit( wp_normalize_path( (string) $job['output_dir'] ) );
		$fs   = AOUWAL_Writer::filesystem_instance();
		if ( ! $fs || ! $fs->is_dir( $root ) ) {
			return self::fail_result( 'netlify', __( 'The export files are missing. Run the export again.', 'aouwal-static-site-export' ) );
		}

		$files = array();
		self::scan_netlify_files( $fs, $root, '', $files );
		if ( empty( $files ) ) {
			return self::fail_result( 'netlify', __( 'The export folder is empty.', 'aouwal-static-site-export' ) );
		}

		$manifest = array();
		$by_hash  = array();
		foreach ( $files as $relative => $file ) {
			$manifest[ '/' . $relative ] = $file['sha1'];
			if ( ! isset( $by_hash[ $file['sha1'] ] ) ) {
				$by_hash[ $file['sha1'] ] = $relative;
			}
		}

		$site_id = rawurlencode( (string) self::get( 'netlify_site_id', '' ) );
		$deploy  = self::netlify_request( 'POST', '/sites/' . $site_id . '/deploys', array( 'files' => $manifest ) );
		if ( is_wp_error( $deploy ) ) {
			return self::fail_result( 'netlify', self::netlify_error( $deploy ) );
		}

		$deploy_id = isset( $deploy['id'] ) ? (string) $deploy['id'] : '';
		if ( '' === $deploy_id ) {
			return self::fail_result( 'netlify', __( 'Netlify did not return a deploy ID.', 'aouwal-static-site-export' ) );
		}

		$required = isset( $deploy['required'] ) && is_array( $deploy['required'] ) ? array_values( array_filter( array_map( 'strval', $deploy['required'] ) ) ) : array();
		$uploaded = 0;

		foreach ( $required as $hash ) {
			if ( ! isset( $by_hash[ $hash ] ) ) {
				continue;
			}
			$relative = $by_hash[ $hash ];
			$content  = $fs->get_contents( trailingslashit( $root ) . $relative );
			if ( ! is_string( $content ) ) {
				return self::fail_result(
					'netlify',
					sprintf(
						/* translators: %s: file path inside the export. */
						__( 'Could not read %s from the export folder.', 'aouwal-static-site-export' ),
						$relative
					)
				);
			}

			$path  = implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
			$write = self::netlify_request( 'PUT', '/deploys/' . rawurlencode( $deploy_id ) . '/files/' . $path, $content, true, 'application/octet-stream', 180 );
			if ( is_wp_error( $write ) ) {
				return self::fail_result(
					'netlify',
					sprintf(
						/* translators: 1: file path, 2: Netlify error text. */
						__( 'Uploading %1$s failed: %2$s', 'aouwal-static-site-export' ),
						$relative,
						self::netlify_error( $write )
					)
				);
			}

			// Every path sharing this hash is now covered; Netlify stores the
			// content once and the manifest points each path at it.
			foreach ( $files as $other => $other_file ) {
				if ( $other_file['sha1'] === $hash ) {
					++$uploaded;
				}
			}
		}

		$state    = isset( $deploy['state'] ) ? (string) $deploy['state'] : '';
		$url      = isset( $deploy['deploy_ssl_url'] ) ? (string) $deploy['deploy_ssl_url'] : '';
		$site_url = isset( $deploy['ssl_url'] ) ? (string) $deploy['ssl_url'] : ( isset( $deploy['url'] ) ? (string) $deploy['url'] : '' );
		if ( '' === $url ) {
			$url = $site_url;
		}

		$settled = in_array( $state, array( 'ready', 'error', 'rejected' ), true );
		for ( $i = 0; $i < 4 && ! $settled; $i++ ) {
			sleep( 1 );
			$status = self::netlify_request( 'GET', '/deploys/' . rawurlencode( $deploy_id ) );
			if ( is_wp_error( $status ) ) {
				break;
			}
			$state   = isset( $status['state'] ) ? (string) $status['state'] : $state;
			$settled = in_array( $state, array( 'ready', 'error', 'rejected' ), true );
			if ( ! empty( $status['deploy_ssl_url'] ) ) {
				$url = (string) $status['deploy_ssl_url'];
			}
			if ( ! empty( $status['ssl_url'] ) ) {
				$site_url = (string) $status['ssl_url'];
			}
			if ( ! empty( $status['error_message'] ) && in_array( $state, array( 'error', 'rejected' ), true ) ) {
				return self::fail_result( 'netlify', (string) $status['error_message'] );
			}
		}

		$result            = self::result( 'netlify' );
		$result['commit']  = $deploy_id;
		$result['url']     = ( '' !== $url ) ? $url : $site_url;
		$result['files']   = $uploaded;
		$result['skipped'] = count( $files ) - $uploaded;

		if ( in_array( $state, array( 'error', 'rejected' ), true ) ) {
			$result['message'] = sprintf(
				/* translators: 1: Netlify site ID, 2: deploy URL. */
				__( 'Netlify accepted the deploy for %1$s but processing failed. Check the Netlify deploy log: %2$s', 'aouwal-static-site-export' ),
				(string) self::get( 'netlify_site_id', '' ),
				$result['url']
			);
			return $result;
		}

		$result['ok']      = true;
		$result['message'] = sprintf(
			/* translators: 1: number of uploaded files, 2: Netlify site ID, 3: number of unchanged files, 4: deploy URL. */
			__( 'Deployed %1$d file(s) to Netlify site %2$s. %3$d unchanged. %4$s', 'aouwal-static-site-export' ),
			$uploaded,
			(string) self::get( 'netlify_site_id', '' ),
			count( $files ) - $uploaded,
			$result['url']
		);

		return $result;
	}

	/**
	 * Upload the export folder to a Cloudflare Pages project.
	 *
	 * Uses the Pages asset protocol: manifest hashes, missing-hash check,
	 * base64 asset upload, then a multipart deployment with the manifest,
	 * branch and optional _headers/_redirects files.
	 *
	 * @param string $job_id Job ID.
	 * @return array Result row.
	 */
	public static function deploy_cloudflare( $job_id ) {
		if ( ! self::cloudflare_configured() ) {
			return self::fail_result( 'cloudflare', __( 'Cloudflare Pages is not configured. Add an account ID, a project name and a token.', 'aouwal-static-site-export' ) );
		}

		$exporter = new AOUWAL_Exporter();
		$job      = $exporter->get_job( $job_id );
		if ( ! $job || empty( $job['output_dir'] ) ) {
			return self::fail_result( 'cloudflare', __( 'Export job not found.', 'aouwal-static-site-export' ) );
		}
		if ( AOUWAL_Exporter::PHASE_DONE !== $job['phase'] ) {
			return self::fail_result( 'cloudflare', __( 'Only a finished export can be deployed.', 'aouwal-static-site-export' ) );
		}

		$root = untrailingslashit( wp_normalize_path( (string) $job['output_dir'] ) );
		$fs   = AOUWAL_Writer::filesystem_instance();
		if ( ! $fs || ! $fs->is_dir( $root ) ) {
			return self::fail_result( 'cloudflare', __( 'The export files are missing. Run the export again.', 'aouwal-static-site-export' ) );
		}

		$files = array();
		self::scan_cloudflare_files( $fs, $root, '', $files );
		if ( empty( $files ) ) {
			return self::fail_result( 'cloudflare', __( 'The export folder is empty.', 'aouwal-static-site-export' ) );
		}
		if ( count( $files ) > 20000 ) {
			return self::fail_result( 'cloudflare', __( 'Cloudflare Pages accepts up to 20,000 files in a deployment. Exclude some content or use Git integration instead.', 'aouwal-static-site-export' ) );
		}

		$project_name = (string) self::get( 'cloudflare_project', '' );
		$project_path = self::cloudflare_project_path();

		// The project call both validates the target and supplies the
		// production branch for the success message.
		$project_response = self::cloudflare_request( 'GET', $project_path );
		if ( is_wp_error( $project_response ) ) {
			return self::fail_result( 'cloudflare', self::cloudflare_error( $project_response ) );
		}
		$project = isset( $project_response['result'] ) && is_array( $project_response['result'] ) ? $project_response['result'] : array();
		$prod    = isset( $project['production_branch'] ) ? (string) $project['production_branch'] : '';
		$sub     = isset( $project['subdomain'] ) ? (string) $project['subdomain'] : '';
		$branch  = (string) self::get( 'cloudflare_branch', '' );

		if ( ! empty( $project['source'] ) ) {
			return self::fail_result( 'cloudflare', __( 'This Pages project is connected to Git, so Cloudflare builds it from the repository and rejects direct uploads. Use the GitHub target, or create a Direct Upload project.', 'aouwal-static-site-export' ) );
		}

		$manifest = array();
		$hashes   = array();

		foreach ( $files as $relative => $file ) {
			if ( $file['size'] > 25 * 1024 * 1024 ) {
				return self::fail_result(
					'cloudflare',
					sprintf(
						/* translators: %s: file path inside the export. */
						__( 'Cloudflare Pages accepts files up to 25 MB, and %s is larger. Lower the Maximum asset size setting or exclude the file.', 'aouwal-static-site-export' ),
						$relative
					)
				);
			}
			$manifest[ '/' . $relative ] = $file['hash'];
			$hashes[ $file['hash'] ]     = true;
		}

		$token = self::cloudflare_request( 'GET', $project_path . '/upload-token' );
		if ( is_wp_error( $token ) ) {
			return self::fail_result( 'cloudflare', self::cloudflare_error( $token ) );
		}
		$jwt = isset( $token['result']['jwt'] ) ? (string) $token['result']['jwt'] : '';
		if ( '' === $jwt ) {
			return self::fail_result( 'cloudflare', __( 'Cloudflare did not return an upload token for this project.', 'aouwal-static-site-export' ) );
		}

		$missing_response = self::cloudflare_request(
			'POST',
			'/pages/assets/check-missing',
			array( 'hashes' => array_keys( $hashes ) ),
			$jwt
		);
		if ( is_wp_error( $missing_response ) ) {
			return self::fail_result( 'cloudflare', self::cloudflare_error( $missing_response ) );
		}
		$missing = isset( $missing_response['result'] ) && is_array( $missing_response['result'] )
			? array_values( array_filter( array_map( 'strval', $missing_response['result'] ) ) )
			: array();

		$by_hash = array();
		foreach ( $files as $relative => $file ) {
			if ( ! isset( $by_hash[ $file['hash'] ] ) ) {
				$by_hash[ $file['hash'] ] = array(
					'path' => $relative,
					'type' => $file['type'],
					'size' => $file['size'],
				);
			}
		}

		$uploaded = 0;
		$buckets  = array();
		$current  = array();
		$size     = 0;

		foreach ( $missing as $hash ) {
			if ( ! isset( $by_hash[ $hash ] ) ) {
				continue;
			}
			$item = $by_hash[ $hash ];
			if ( ! empty( $current ) && ( $size + $item['size'] > 20 * 1024 * 1024 || count( $current ) >= 500 ) ) {
				$buckets[] = $current;
				$current   = array();
				$size      = 0;
			}
			$current[] = $hash;
			$size     += $item['size'];
		}
		if ( ! empty( $current ) ) {
			$buckets[] = $current;
		}

		foreach ( $buckets as $bucket ) {
			$payload = array();
			foreach ( $bucket as $hash ) {
				$content = $fs->get_contents( trailingslashit( $root ) . $by_hash[ $hash ]['path'] );
				if ( ! is_string( $content ) ) {
					return self::fail_result(
						'cloudflare',
						sprintf(
							/* translators: %s: file path inside the export. */
							__( 'Could not read %s from the export folder.', 'aouwal-static-site-export' ),
							$by_hash[ $hash ]['path']
						)
					);
				}
				$payload[] = array(
					'key'      => $hash,
					'value'    => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Cloudflare's asset API requires base64 file contents.
					'metadata' => array( 'contentType' => $by_hash[ $hash ]['type'] ),
					'base64'   => true,
				);
				++$uploaded;
			}

			$upload = self::cloudflare_request( 'POST', '/pages/assets/upload', $payload, $jwt, 180 );
			if ( is_wp_error( $upload ) ) {
				return self::fail_result(
					'cloudflare',
					sprintf(
						/* translators: %s: Cloudflare error text. */
						__( 'Uploading the assets failed: %s', 'aouwal-static-site-export' ),
						self::cloudflare_error( $upload )
					)
				);
			}
		}

		// Best effort: remembers the hashes so the next deployment can skip
		// unchanged files. A failure here only costs upload time next run.
		self::cloudflare_request(
			'POST',
			'/pages/assets/upsert-hashes',
			array( 'hashes' => array_keys( $hashes ) ),
			$jwt
		);

		$boundary = 'aouwal' . strtolower( wp_generate_password( 24, false, false ) );
		$fields   = array( 'manifest' => (string) wp_json_encode( $manifest ) );
		if ( '' !== $branch ) {
			$fields['branch'] = $branch;
		}
		$fields['commit_message'] = substr( self::commit_message( 'cloudflare_message' ), 0, 384 );

		$config_files = array();
		foreach ( array( '_headers', '_redirects', '_routes.json' ) as $config_name ) {
			if ( $fs->is_file( trailingslashit( $root ) . $config_name ) ) {
				$config_files[ $config_name ] = (string) $fs->get_contents( trailingslashit( $root ) . $config_name );
			}
		}

		$body       = self::multipart_body( $fields, $config_files, $boundary );
		$deployment = self::cloudflare_request(
			'POST',
			$project_path . '/deployments',
			$body,
			null,
			180,
			'multipart/form-data; boundary=' . $boundary
		);
		if ( is_wp_error( $deployment ) ) {
			return self::fail_result(
				'cloudflare',
				sprintf(
					/* translators: %s: Cloudflare error text. */
					__( 'Creating the Pages deployment failed: %s', 'aouwal-static-site-export' ),
					self::cloudflare_error( $deployment )
				)
			);
		}

		$deployed = isset( $deployment['result'] ) && is_array( $deployment['result'] ) ? $deployment['result'] : array();
		$url      = isset( $deployed['url'] ) ? (string) $deployed['url'] : '';
		$id       = isset( $deployed['id'] ) ? (string) $deployed['id'] : '';
		$stage    = isset( $deployed['latest_stage']['status'] ) ? (string) $deployed['latest_stage']['status'] : '';
		$result   = self::result( 'cloudflare' );

		$result['commit']  = $id;
		$result['url']     = $url;
		$result['files']   = $uploaded;
		$result['skipped'] = count( $files ) - $uploaded;

		if ( 'failure' === $stage ) {
			$result['message'] = sprintf(
				/* translators: 1: Pages project name, 2: deployment URL. */
				__( 'Cloudflare accepted the upload for %1$s but the deployment failed. Check the Pages dashboard: %2$s', 'aouwal-static-site-export' ),
				$project_name,
				$url
			);
			return $result;
		}

		$result['ok']      = true;
		$result['message'] = sprintf(
			/* translators: 1: number of uploaded files, 2: Pages project name, 3: number of unchanged files, 4: deployment URL. */
			__( 'Deployed %1$d file(s) to Cloudflare Pages project %2$s. %3$d unchanged. %4$s', 'aouwal-static-site-export' ),
			$uploaded,
			$project_name,
			count( $files ) - $uploaded,
			$url
		);

		if ( '' === $prod && '' !== $sub ) {
			$result['message'] .= ' https://' . preg_replace( '/\.pages\.dev$/i', '', (string) $sub ) . '.pages.dev';
		}

		$zone = (string) self::get( 'cloudflare_zone_id', '' );
		if ( self::get( 'cloudflare_purge' ) && '' !== $zone ) {
			$purge = self::cloudflare_request(
				'POST',
				'/zones/' . rawurlencode( $zone ) . '/purge_cache',
				array( 'purge_everything' => true )
			);
			if ( is_wp_error( $purge ) ) {
				$result['message'] .= ' ' . sprintf(
					/* translators: %s: Cloudflare error text. */
					__( 'Cache purge failed: %s', 'aouwal-static-site-export' ),
					self::cloudflare_error( $purge )
				);
			} else {
				$result['message'] .= ' ' . __( 'Cloudflare cache purged.', 'aouwal-static-site-export' );
			}
		}

		return $result;
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
	 * Cloudflare API path for the configured account and project.
	 *
	 * @param string $suffix Suffix beginning with a slash.
	 * @return string
	 */
	protected static function cloudflare_project_path( $suffix = '' ) {
		return '/accounts/' . rawurlencode( (string) self::get( 'cloudflare_account_id', '' ) )
			. '/pages/projects/' . rawurlencode( (string) self::get( 'cloudflare_project', '' ) )
			. $suffix;
	}

	/**
	 * Call the Cloudflare API through the WordPress HTTP API.
	 *
	 * @param string            $method       HTTP method.
	 * @param string            $path         API path beginning with a slash.
	 * @param array|string|null $body   JSON data, raw multipart body, or null.
	 * @param string|null       $bearer       Explicit bearer token (upload JWT), or null for the saved token.
	 * @param int               $timeout      Timeout in seconds.
	 * @param string            $content_type Content-Type header.
	 * @return array|WP_Error Decoded response or error.
	 */
	protected static function cloudflare_request( $method, $path, $body = null, $bearer = null, $timeout = 60, $content_type = '' ) {
		$token = ( null === $bearer ) ? self::cloudflare_token() : (string) $bearer;
		if ( '' === $token ) {
			return new WP_Error( 'aouwal_cloudflare_token', __( 'No Cloudflare token is saved.', 'aouwal-static-site-export' ) );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'User-Agent'    => 'Aouwal-Static-Site-Export/' . AOUWAL_VERSION,
		);
		if ( '' !== $content_type ) {
			$headers['Content-Type'] = $content_type;
		} elseif ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => (int) $timeout,
		);
		if ( null !== $body ) {
			$args['body'] = is_string( $body ) ? $body : (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( 'https://api.cloudflare.com/client/v4' . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'aouwal_cloudflare_network', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		$success = is_array( $data ) && array_key_exists( 'success', $data ) ? (bool) $data['success'] : ( $code >= 200 && $code < 300 );
		if ( ! $success || $code < 200 || $code >= 300 ) {
			$messages = array();
			if ( is_array( $data ) && ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
				foreach ( $data['errors'] as $error ) {
					if ( is_array( $error ) && ! empty( $error['message'] ) ) {
						$messages[] = (string) $error['message'];
					}
				}
			}
			if ( empty( $messages ) && is_array( $data ) && ! empty( $data['message'] ) ) {
				$messages[] = (string) $data['message'];
			}
			if ( empty( $messages ) ) {
				/* translators: %d: HTTP status code. */
				$messages[] = sprintf( __( 'Cloudflare returned HTTP %d.', 'aouwal-static-site-export' ), $code );
			}
			return new WP_Error( 'aouwal_cloudflare_http', implode( '; ', $messages ), array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Safe Cloudflare error text.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	protected static function cloudflare_error( $error ) {
		return is_wp_error( $error ) ? (string) $error->get_error_message() : '';
	}

	/**
	 * Call the Netlify API through the WordPress HTTP API.
	 *
	 * @param string            $method       HTTP method.
	 * @param string            $path         API path beginning with a slash.
	 * @param array|string|null $body   JSON data, raw upload body, or null.
	 * @param bool              $raw          Whether body is a raw string.
	 * @param string            $content_type Content-Type header.
	 * @param int               $timeout      Timeout in seconds.
	 * @return array|WP_Error Decoded response or error.
	 */
	protected static function netlify_request( $method, $path, $body = null, $raw = false, $content_type = 'application/json', $timeout = 60 ) {
		$token = self::netlify_token();
		if ( '' === $token ) {
			return new WP_Error( 'aouwal_netlify_token', __( 'No Netlify token is saved.', 'aouwal-static-site-export' ) );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'User-Agent'    => 'Aouwal-Static-Site-Export/' . AOUWAL_VERSION,
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = $content_type;
		}

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => (int) $timeout,
		);
		if ( null !== $body ) {
			$args['body'] = ( $raw || is_string( $body ) ) ? (string) $body : (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( 'https://api.netlify.com/api/v1' . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'aouwal_netlify_network', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$text = (string) wp_remote_retrieve_body( $response );
		$data = '' !== $text ? json_decode( $text, true ) : array();

		if ( $code < 200 || $code >= 300 ) {
			$message = '';
			if ( is_array( $data ) ) {
				if ( ! empty( $data['message'] ) ) {
					$message = (string) $data['message'];
				} elseif ( ! empty( $data['error'] ) ) {
					$message = (string) $data['error'];
				}
			}
			if ( '' === $message ) {
				/* translators: %d: HTTP status code. */
				$message = sprintf( __( 'Netlify returned HTTP %d.', 'aouwal-static-site-export' ), $code );
			}
			return new WP_Error( 'aouwal_netlify_http', $message, array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Safe Netlify error text.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	protected static function netlify_error( $error ) {
		return is_wp_error( $error ) ? (string) $error->get_error_message() : '';
	}

	/**
	 * Walk the export folder and record a SHA1 per file for a Netlify deploy.
	 *
	 * @param WP_Filesystem_Base $fs     Filesystem.
	 * @param string             $dir    Absolute directory.
	 * @param string             $prefix Relative prefix.
	 * @param array              $out    Relative path => metadata, by reference.
	 * @return void
	 */
	protected static function scan_netlify_files( $fs, $dir, $prefix, array &$out ) {
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
				self::scan_netlify_files( $fs, $path, $relative, $out );
				continue;
			}

			$content = $fs->get_contents( $path );
			if ( ! is_string( $content ) ) {
				continue;
			}
			$out[ $relative ] = array(
				'sha1' => sha1( $content ),
				'size' => strlen( $content ),
			);
		}
	}

	/**
	 * Walk the export folder and record the Pages hash, size and type per file.
	 *
	 * Pages treats _headers, _redirects and _routes.json as configuration and
	 * expects them as separate parts of the deployment request, not as assets.
	 *
	 * @param WP_Filesystem_Base $fs     Filesystem.
	 * @param string             $dir    Absolute directory.
	 * @param string             $prefix Relative prefix.
	 * @param array              $out    Relative path => metadata, by reference.
	 * @return void
	 */
	protected static function scan_cloudflare_files( $fs, $dir, $prefix, array &$out ) {
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

			if ( '' === $prefix && in_array( $name, array( '_headers', '_redirects', '_routes.json', '_worker.js', 'functions' ), true ) ) {
				continue;
			}

			if ( ! empty( $info['type'] ) && 'd' === $info['type'] ) {
				self::scan_cloudflare_files( $fs, $path, $relative, $out );
				continue;
			}

			$content = $fs->get_contents( $path );
			if ( ! is_string( $content ) ) {
				continue;
			}

			$extension        = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
			$out[ $relative ] = array(
				'hash' => substr( hash( 'sha256', base64_encode( $content ) . $extension ), 0, 32 ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Cloudflare's asset hash is defined over base64 contents plus the extension.
				'size' => strlen( $content ),
				'type' => self::content_type( $name ),
			);
		}
	}

	/**
	 * Content type for a file path.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	protected static function content_type( $path ) {
		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		$map = array(
			'html'  => 'text/html',
			'htm'   => 'text/html',
			'css'   => 'text/css',
			'js'    => 'application/javascript',
			'mjs'   => 'application/javascript',
			'json'  => 'application/json',
			'xml'   => 'application/xml',
			'txt'   => 'text/plain',
			'map'   => 'application/json',
			'svg'   => 'image/svg+xml',
			'png'   => 'image/png',
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'gif'   => 'image/gif',
			'webp'  => 'image/webp',
			'avif'  => 'image/avif',
			'ico'   => 'image/x-icon',
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf'   => 'font/ttf',
			'otf'   => 'font/otf',
			'eot'   => 'application/vnd.ms-fontobject',
			'pdf'   => 'application/pdf',
			'mp4'   => 'video/mp4',
			'webm'  => 'video/webm',
			'mp3'   => 'audio/mpeg',
			'wasm'  => 'application/wasm',
		);
		return isset( $map[ $ext ] ) ? $map[ $ext ] : 'application/octet-stream';
	}

	/**
	 * Build a multipart/form-data body for the Pages deployment request.
	 *
	 * @param array  $fields    Plain form fields.
	 * @param array  $files     File name => contents.
	 * @param string $boundary  Boundary.
	 * @return string
	 */
	protected static function multipart_body( array $fields, array $files, $boundary ) {
		$eol  = "\r\n";
		$body = '';

		foreach ( $fields as $name => $value ) {
			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$body .= (string) $value . $eol;
		}
		foreach ( $files as $name => $content ) {
			$body .= '--' . $boundary . $eol;
			$body .= 'Content-Disposition: form-data; name="' . $name . '"; filename="' . $name . '"' . $eol;
			$body .= 'Content-Type: text/plain' . $eol . $eol;
			$body .= (string) $content . $eol;
		}
		$body .= '--' . $boundary . '--' . $eol;

		return $body;
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
	 * @param string $key Setting key holding the template.
	 * @return string
	 */
	protected static function commit_message( $key = 'github_message' ) {
		$message = (string) self::get( $key, 'Static export {date}' );
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
