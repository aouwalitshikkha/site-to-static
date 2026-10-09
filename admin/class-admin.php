<?php
/**
 * Admin UI, menus and AJAX endpoints.
 *
 * @package WP_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WSE_Admin
 */
class WSE_Admin {

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

		add_action( 'wp_ajax_wse_start', array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_wse_batch', array( $this, 'ajax_batch' ) );
		add_action( 'wp_ajax_wse_finalize', array( $this, 'ajax_finalize' ) );
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public function menu() {
		$this->hook = add_menu_page(
			__( 'Static Export', 'wp-static-export' ),
			__( 'Static Export', 'wp-static-export' ),
			'manage_options',
			'wp-static-export',
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
			'wse_settings_group',
			WSE_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WSE_Settings', 'sanitize' ),
				'default'           => WSE_Settings::defaults(),
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

		wp_enqueue_style( 'wse-admin', WSE_PLUGIN_URL . 'admin/assets/admin.css', array(), WSE_VERSION );
		wp_enqueue_script( 'wse-admin', WSE_PLUGIN_URL . 'admin/assets/export.js', array( 'jquery' ), WSE_VERSION, true );
		wp_localize_script(
			'wse-admin',
			'WSE',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wse_ajax' ),
				'batch'   => (int) WSE_Settings::get( 'batch_size', 5 ),
				'i18n'    => array(
					'starting'   => __( 'Discovering URLs…', 'wp-static-export' ),
					'crawling'   => __( 'Processing', 'wp-static-export' ),
					'finalizing' => __( 'Packaging export…', 'wp-static-export' ),
					'done'       => __( 'Export complete.', 'wp-static-export' ),
					'error'      => __( 'An error occurred.', 'wp-static-export' ),
					'confirm'    => __( 'Start a new static export?', 'wp-static-export' ),
					'download'   => __( 'Download export ZIP', 'wp-static-export' ),
					/* translators: %s: number of discovered URLs. */
					'discovered' => __( 'Discovered %s URLs.', 'wp-static-export' ),
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-static-export' ) );
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'settings' => __( 'Settings', 'wp-static-export' ),
			'export'   => __( 'Export', 'wp-static-export' ),
			'deploy'   => __( 'Deploy', 'wp-static-export' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}

		echo '<div class="wrap wse-wrap">';
		echo '<div class="wse-brand">';
		echo '<span class="wse-brand-mark" aria-hidden="true">&lt;/&gt;</span>';
		echo '<span class="wse-brand-text">';
		echo '<span class="wse-brand-title">' . esc_html__( 'WP Static Export', 'wp-static-export' ) . '</span>';
		printf(
			'<span class="wse-brand-sub">%1$s <a href="https://abdulaouwal.com" target="_blank" rel="noopener noreferrer">%2$s</a></span>',
			esc_html__( 'by', 'wp-static-export' ),
			esc_html__( 'Abdul Aouwal', 'wp-static-export' )
		);
		echo '</span>';
		echo '<a class="wse-brand-site" href="https://abdulaouwal.com" target="_blank" rel="noopener noreferrer">abdulaouwal.com</a>';
		echo '</div>';

		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wp-static-export' ) . '</p></div>';
		}

		echo '<h2 class="nav-tab-wrapper wse-tabs">';
		foreach ( $tabs as $key => $label ) {
			$url   = add_query_arg(
				array(
					'page' => 'wp-static-export',
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
				require WSE_PLUGIN_DIR . 'admin/views/export.php';
				break;
			case 'deploy':
				require WSE_PLUGIN_DIR . 'admin/views/deploy.php';
				break;
			default:
				require WSE_PLUGIN_DIR . 'admin/views/settings.php';
				break;
		}

		echo '</div>';
	}

	/**
	 * Verify AJAX request.
	 *
	 * @return void
	 */
	protected function verify() {
		check_ajax_referer( 'wse_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-static-export' ) ), 403 );
		}
	}

	/**
	 * AJAX: start export.
	 *
	 * @return void
	 */
	public function ajax_start() {
		$this->verify();
		$exporter = new WSE_Exporter();
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
		$this->verify();
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$batch  = (int) WSE_Settings::get( 'batch_size', 5 );

		$exporter = new WSE_Exporter();
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
		$this->verify();
		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		$exporter = new WSE_Exporter();
		$result   = $exporter->finalize( $job_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( $result );
	}
}

new WSE_Admin();
