<?php
/**
 * Admin UI, menus and AJAX endpoints.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class STS_Admin
 */
class STS_Admin {

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

		add_action( 'wp_ajax_sts_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_sts_batch', array( $this, 'ajax_batch' ) );
		add_action( 'wp_ajax_sts_finalize', array( $this, 'ajax_finalize' ) );
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public function menu() {
		$this->hook = add_menu_page(
			__( 'Static Export', 'site-to-static' ),
			__( 'Static Export', 'site-to-static' ),
			'manage_options',
			'site-to-static',
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
			'sts_settings_group',
			STS_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'STS_Settings', 'sanitize' ),
				'default'           => STS_Settings::defaults(),
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

		wp_enqueue_style( 'sts-admin', STS_PLUGIN_URL . 'admin/assets/admin.css', array(), STS_VERSION );
		wp_enqueue_script( 'sts-admin', STS_PLUGIN_URL . 'admin/assets/export.js', array( 'jquery' ), STS_VERSION, true );
		wp_localize_script(
			'sts-admin',
			'STS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'sts_ajax' ),
				'batch'   => (int) STS_Settings::get( 'batch_size', 5 ),
				'i18n'    => array(
					'starting'   => __( 'Discovering URLs…', 'site-to-static' ),
					'crawling'   => __( 'Processing', 'site-to-static' ),
					'finalizing' => __( 'Packaging export…', 'site-to-static' ),
					'done'       => __( 'Export complete.', 'site-to-static' ),
					'error'      => __( 'An error occurred.', 'site-to-static' ),
					'confirm'    => __( 'Start a new static export?', 'site-to-static' ),
					'download'   => __( 'Download export ZIP', 'site-to-static' ),
					/* translators: %s: number of discovered URLs. */
					'discovered' => __( 'Discovered %s URLs.', 'site-to-static' ),
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'site-to-static' ) );
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'settings' => __( 'Settings', 'site-to-static' ),
			'export'   => __( 'Export', 'site-to-static' ),
			'deploy'   => __( 'Deploy', 'site-to-static' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}

		echo '<div class="wrap sts-wrap">';
		echo '<div class="sts-brand">';
		echo '<span class="sts-brand-mark" aria-hidden="true">&lt;/&gt;</span>';
		echo '<span class="sts-brand-text">';
		echo '<span class="sts-brand-title">' . esc_html__( 'Site to Static', 'site-to-static' ) . '</span>';
		printf(
			'<span class="sts-brand-sub">%1$s <a href="https://abdulaouwal.com" target="_blank" rel="noopener noreferrer">%2$s</a></span>',
			esc_html__( 'by', 'site-to-static' ),
			esc_html__( 'Abdul Aouwal', 'site-to-static' )
		);
		echo '</span>';
		echo '<a class="sts-brand-site" href="https://abdulaouwal.com" target="_blank" rel="noopener noreferrer">abdulaouwal.com</a>';
		echo '</div>';

		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'site-to-static' ) . '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper sts-tabs">';
		foreach ( $tabs as $key => $label ) {
			$url   = add_query_arg(
				array(
					'page' => 'site-to-static',
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
				require STS_PLUGIN_DIR . 'admin/views/export.php';
				break;
			case 'deploy':
				require STS_PLUGIN_DIR . 'admin/views/deploy.php';
				break;
			default:
				require STS_PLUGIN_DIR . 'admin/views/settings.php';
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
		check_ajax_referer( 'sts_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'site-to-static' ) ), 403 );
		}
		$exporter = new STS_Exporter();
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
		check_ajax_referer( 'sts_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'site-to-static' ) ), 403 );
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$batch  = (int) STS_Settings::get( 'batch_size', 5 );

		$exporter = new STS_Exporter();
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
		check_ajax_referer( 'sts_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'site-to-static' ) ), 403 );
		}
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		$exporter = new STS_Exporter();
		$result   = $exporter->finalize( $job_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}
}

new STS_Admin();
