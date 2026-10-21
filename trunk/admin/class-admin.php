<?php
/**
 * Admin UI, menus and AJAX endpoints.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_Admin
 */
class AOUWAL_Admin {

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	protected $hook = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_action( 'wp_ajax_aouwal_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_aouwal_batch', array( $this, 'ajax_batch' ) );
		add_action( 'wp_ajax_aouwal_finalize', array( $this, 'ajax_finalize' ) );
		add_action( 'admin_post_aouwal_download', array( $this, 'download_zip' ) );
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public function menu() {
		$this->hook = add_menu_page(
			__( 'Static Export', 'aouwal-static-site-export' ),
			__( 'Static Export', 'aouwal-static-site-export' ),
			'manage_options',
			'aouwal-static-site-export',
			array( $this, 'render' ),
			'dashicons-download',
			81
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'aouwal_settings_group',
			AOUWAL_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'AOUWAL_Settings', 'sanitize' ),
				'default'           => AOUWAL_Settings::defaults(),
			)
		);
	}

	/**
	 * Enqueue admin assets on the plugin page.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook ) {
			return;
		}

		wp_enqueue_style( 'aouwal-admin', AOUWAL_PLUGIN_URL . 'admin/assets/admin.css', array(), AOUWAL_VERSION );
		wp_enqueue_script( 'aouwal-admin', AOUWAL_PLUGIN_URL . 'admin/assets/export.js', array( 'jquery' ), AOUWAL_VERSION, true );
		wp_localize_script(
			'aouwal-admin',
			'AOUWAL',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'aouwal_ajax' ),
				'i18n'    => array(
					'starting'   => __( 'Discovering URLs…', 'aouwal-static-site-export' ),
					'crawling'   => __( 'Processing', 'aouwal-static-site-export' ),
					'finalizing' => __( 'Packaging export…', 'aouwal-static-site-export' ),
					'done'       => __( 'Export complete.', 'aouwal-static-site-export' ),
					'done_with_errors' => __( 'Export finished with errors.', 'aouwal-static-site-export' ),
					'error'      => __( 'An error occurred.', 'aouwal-static-site-export' ),
					'confirm'    => __( 'Start a new static export?', 'aouwal-static-site-export' ),
					'download'   => __( 'Download export ZIP', 'aouwal-static-site-export' ),
					/* translators: %s: number of discovered URLs. */
					'discovered' => __( 'Discovered %s URLs.', 'aouwal-static-site-export' ),
				),
			)
		);
	}

	/**
	 * Render the admin page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'aouwal-static-site-export' ) );
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'settings' => __( 'Settings', 'aouwal-static-site-export' ),
			'export'   => __( 'Export', 'aouwal-static-site-export' ),
			'deploy'   => __( 'Deploy', 'aouwal-static-site-export' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}

		echo '<div class="wrap aouwal-wrap">';
		echo '<div class="aouwal-brand">';
		echo '<span class="aouwal-brand-mark" aria-hidden="true">&lt;/&gt;</span>';
		echo '<span class="aouwal-brand-text">';
		echo '<span class="aouwal-brand-title">' . esc_html__( 'Aouwal Static Site Export', 'aouwal-static-site-export' ) . '</span>';
		printf(
			'<span class="aouwal-brand-sub">%1$s <a href="https://abdulaouwal.com" target="_blank" rel="noopener noreferrer">%2$s</a></span>',
			esc_html__( 'by', 'aouwal-static-site-export' ),
			esc_html__( 'Abdul Aouwal', 'aouwal-static-site-export' )
		);
		echo '</span>';
		echo '<a class="aouwal-brand-site" href="https://abdulaouwal.com" target="_blank" rel="noopener noreferrer">abdulaouwal.com</a>';
		echo '</div>';

		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'aouwal-static-site-export' ) . '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper aouwal-tabs">';
		foreach ( $tabs as $key => $label ) {
			$url   = add_query_arg(
				array(
					'page' => 'aouwal-static-site-export',
					'tab'  => $key,
				),
				admin_url( 'admin.php' )
			);
			$class = 'nav-tab' . ( $tab === $key ? ' nav-tab-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		switch ( $tab ) {
			case 'export':
				require AOUWAL_PLUGIN_DIR . 'admin/views/export.php';
				break;
			case 'deploy':
				require AOUWAL_PLUGIN_DIR . 'admin/views/deploy.php';
				break;
			default:
				require AOUWAL_PLUGIN_DIR . 'admin/views/settings.php';
				break;
		}

		echo '</div>';
	}

	/**
	 * AJAX: start export.
	 *
	 * @return void
	 */
	public function ajax_start() {
		check_ajax_referer( 'aouwal_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aouwal-static-site-export' ) ), 403 );
		}
		$exporter = new AOUWAL_Exporter();
		$result   = $exporter->start();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: process a batch.
	 *
	 * @return void
	 */
	public function ajax_batch() {
		check_ajax_referer( 'aouwal_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aouwal-static-site-export' ) ), 403 );
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$batch  = (int) AOUWAL_Settings::get( 'batch_size', 5 );

		$exporter = new AOUWAL_Exporter();
		$result   = $exporter->process_batch( $job_id, max( 1, $batch ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: finalize export.
	 *
	 * @return void
	 */
	public function ajax_finalize() {
		check_ajax_referer( 'aouwal_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'aouwal-static-site-export' ) ), 403 );
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		$exporter = new AOUWAL_Exporter();
		$result   = $exporter->finalize( $job_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Serve a job ZIP through an authenticated, expiring URL.
	 *
	 * URL format: admin-post.php?action=aouwal_download&job_id=<id>&_wpnonce=<nonce>.
	 * The nonce expires on the default WordPress tick and the file is
	 * removed when old jobs are pruned, so leaked URLs stop working.
	 *
	 * @return void
	 */
	public function download_zip() {
		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'aouwal_download_' . $job_id );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'aouwal-static-site-export' ), 403 );
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{20}$/', $job_id ) ) {
			wp_die( esc_html__( 'Export job not found.', 'aouwal-static-site-export' ), 404 );
		}

		$exporter = new AOUWAL_Exporter();
		$job      = $exporter->get_job( $job_id );
		if ( ! $job || empty( $job['zip_path'] ) ) {
			wp_die( esc_html__( 'Export job not found.', 'aouwal-static-site-export' ), 404 );
		}

		$path     = wp_normalize_path( (string) $job['zip_path'] );
		$allowed  = false;
		foreach ( AOUWAL_Exporter::storage_dirs() as $dir ) {
			$dir = wp_normalize_path( $dir );
			if ( $path === $dir || 0 === strpos( $path, rtrim( $dir, '/' ) . '/' ) ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed || basename( $path ) !== $job_id . '.zip' ) {
			wp_die( esc_html__( 'Export job not found.', 'aouwal-static-site-export' ), 404 );
		}
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Export file not found. Run a new export.', 'aouwal-static-site-export' ), 404 );
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="static-export-' . $job_id . '.zip"' );
		header( 'Content-Length: ' . filesize( $path ) );

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local export ZIP streaming; not a remote fetch.
		if ( ! $handle ) {
			wp_die( esc_html__( 'Could not read the export file.', 'aouwal-static-site-export' ), 500 );
		}
		while ( ! feof( $handle ) ) {
			echo fread( $handle, 262144 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread,WordPress.Security.EscapeOutput.OutputNotEscaped -- Chunked local ZIP streaming avoids loading the whole archive via WP_Filesystem; binary output.
			if ( function_exists( 'flush' ) ) {
				flush();
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local stream close.
		exit;
	}
}

new AOUWAL_Admin();
