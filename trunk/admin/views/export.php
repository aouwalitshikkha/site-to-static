<?php
/**
 * Export view.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

$aouwal_zip_available = AOUWAL_Zip::available();

$aouwal_exporter = new AOUWAL_Exporter();
$aouwal_state    = $aouwal_exporter->status();
$aouwal_health   = ( '' !== $aouwal_state['job_id'] )
	? $aouwal_exporter->health( $aouwal_state['job_id'] )
	: array(
		'summary' => array(
			'exported' => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'total'    => 0,
		),
		'reasons' => array(),
		'failed'  => array(),
	);
$aouwal_is_done  = ( 'done' === $aouwal_state['phase'] );
?>
<div class="aouwal-export">
	<?php if ( ! $aouwal_zip_available ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'The PHP zip extension is not available. The export is disabled because Aouwal Static Site Export – Static Site Generator cannot package the exported files without it. Ask your host to enable the zip extension, then reload this page.', 'aouwal-static-site-export' ); ?></p>
		</div>
	<?php endif; ?>

	<p class="aouwal-intro">
		<?php esc_html_e( 'Generate a static copy of your site. The plugin crawls every page, downloads and localizes all assets, then packages everything into a ZIP you can push to GitHub and deploy on Cloudflare Pages.', 'aouwal-static-site-export' ); ?>
	</p>

	<p class="description">
		<?php if ( 'changed' === AOUWAL_Settings::get( 'export_mode', 'full' ) ) : ?>
			<?php esc_html_e( 'Changed-only export is on: the next export starts from the last one and re-exports only what changed.', 'aouwal-static-site-export' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Full export is on: every page and asset is crawled again on each run.', 'aouwal-static-site-export' ); ?>
		<?php endif; ?>
	</p>

	<p>
		<button type="button" class="button button-primary button-hero" id="aouwal-start" <?php disabled( ! $aouwal_zip_available ); ?>>
			<?php esc_html_e( 'Start static export', 'aouwal-static-site-export' ); ?>
		</button>
		<button type="button" class="button" id="aouwal-pause" hidden>
			<?php esc_html_e( 'Pause export', 'aouwal-static-site-export' ); ?>
		</button>
		<button type="button" class="button" id="aouwal-resume" hidden>
			<?php esc_html_e( 'Resume export', 'aouwal-static-site-export' ); ?>
		</button>
		<button type="button" class="button" id="aouwal-cancel" hidden>
			<?php esc_html_e( 'Cancel export', 'aouwal-static-site-export' ); ?>
		</button>
		<span class="spinner aouwal-spinner" id="aouwal-spinner"></span>
	</p>

	<div class="aouwal-progress" id="aouwal-progress" hidden>
		<div class="aouwal-bar"><span id="aouwal-bar-fill"></span></div>
		<div class="aouwal-stats">
			<span><strong><?php esc_html_e( 'Phase:', 'aouwal-static-site-export' ); ?></strong> <em id="aouwal-phase">—</em></span>
			<span><strong><?php esc_html_e( 'Processed:', 'aouwal-static-site-export' ); ?></strong> <em id="aouwal-processed">0</em></span>
			<span><strong><?php esc_html_e( 'Total:', 'aouwal-static-site-export' ); ?></strong> <em id="aouwal-total">0</em></span>
			<span><strong><?php esc_html_e( 'Errors:', 'aouwal-static-site-export' ); ?></strong> <em id="aouwal-errors">0</em></span>
		</div>
	</div>

	<div class="aouwal-result" id="aouwal-result" hidden></div>

	<?php if ( $aouwal_is_done ) : ?>
		<h2><?php esc_html_e( 'Crawl health', 'aouwal-static-site-export' ); ?></h2>
		<div class="aouwal-health">
			<p>
				<?php
				printf(
					/* translators: 1: number of exported items, 2: number of skipped items, 3: number of failed items. */
					esc_html__( '%1$s exported, %2$s skipped, %3$s failed.', 'aouwal-static-site-export' ),
					esc_html( number_format_i18n( $aouwal_health['summary']['exported'] ) ),
					esc_html( number_format_i18n( $aouwal_health['summary']['skipped'] ) ),
					esc_html( number_format_i18n( $aouwal_health['summary']['failed'] ) )
				);
				?>
			</p>

			<?php if ( $aouwal_health['summary']['failed'] > 0 ) : ?>
				<p>
					<?php
					$aouwal_reasons = array();
					foreach ( $aouwal_health['reasons'] as $aouwal_reason => $aouwal_count ) {
						/* translators: 1: number of failed items, 2: failure reason such as "HTTP 503" or "timeout". */
						$aouwal_reasons[] = sprintf( __( '%1$d× %2$s', 'aouwal-static-site-export' ), (int) $aouwal_count, (string) $aouwal_reason );
					}
					echo esc_html( implode( ', ', $aouwal_reasons ) );
					?>
				</p>

				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'URL', 'aouwal-static-site-export' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'aouwal-static-site-export' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reason', 'aouwal-static-site-export' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Tries', 'aouwal-static-site-export' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Found on', 'aouwal-static-site-export' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $aouwal_health['failed'] as $aouwal_failure ) : ?>
							<tr>
								<td><code><?php echo esc_html( $aouwal_failure['url'] ); ?></code></td>
								<td><?php echo 'page' === $aouwal_failure['type'] ? esc_html__( 'Page', 'aouwal-static-site-export' ) : esc_html__( 'Asset', 'aouwal-static-site-export' ); ?></td>
								<td>
									<?php echo esc_html( $aouwal_failure['reason'] ); ?>
									<?php if ( '' !== $aouwal_failure['message'] && $aouwal_failure['message'] !== $aouwal_failure['reason'] ) : ?>
										<br /><small><?php echo esc_html( $aouwal_failure['message'] ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( number_format_i18n( max( 1, (int) $aouwal_failure['attempts'] ) ) ); ?></td>
								<td>
									<?php if ( '' !== $aouwal_failure['origin'] ) : ?>
										<code><?php echo esc_html( $aouwal_failure['origin'] ); ?></code>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<button type="button" class="button" id="aouwal-retry" data-job="<?php echo esc_attr( $aouwal_state['job_id'] ); ?>">
						<?php
						printf(
							/* translators: %s: number of failed items. */
							esc_html__( 'Retry failed (%s)', 'aouwal-static-site-export' ),
							esc_html( number_format_i18n( $aouwal_health['summary']['failed'] ) )
						);
						?>
					</button>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Log', 'aouwal-static-site-export' ); ?></h2>
	<pre class="aouwal-log" id="aouwal-log"></pre>
</div>
