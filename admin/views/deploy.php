<?php
/**
 * Deploy view.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

$aouwal_jobs = get_option( AOUWAL_Exporter::JOBS_OPTION, array() );
$aouwal_last = null;
if ( is_array( $aouwal_jobs ) && ! empty( $aouwal_jobs ) ) {
	$aouwal_last = end( $aouwal_jobs );
}
$aouwal_download_url = '';
if ( $aouwal_last && ! empty( $aouwal_last['id'] ) ) {
	$aouwal_exporter     = new AOUWAL_Exporter();
	$aouwal_download_url = $aouwal_exporter->get_download_url( $aouwal_last['id'] );
}

$aouwal_uploads       = wp_upload_dir();
$aouwal_under_uploads = false;
$aouwal_export_rel    = '';
if ( empty( $aouwal_uploads['error'] ) && ! empty( $aouwal_uploads['baseurl'] ) ) {
	$aouwal_default_base = trailingslashit( $aouwal_uploads['basedir'] ) . 'aouwal-static-site-export';
	if ( untrailingslashit( AOUWAL_Exporter::base_dir() ) === untrailingslashit( $aouwal_default_base ) ) {
		$aouwal_under_uploads = true;
		$aouwal_export_rel    = trailingslashit( (string) wp_parse_url( $aouwal_uploads['baseurl'], PHP_URL_PATH ) ) . 'aouwal-static-site-export/';
	}
}
$aouwal_is_nginx = false !== stripos( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '', 'nginx' );

$aouwal_deploy       = AOUWAL_Deploy::all();
$aouwal_has_token    = AOUWAL_Deploy::has_token();
$aouwal_github_ready = AOUWAL_Deploy::github_configured();
$aouwal_has_cf_token = AOUWAL_Deploy::has_cloudflare_token();
$aouwal_cf_ready     = AOUWAL_Deploy::cloudflare_configured();
$aouwal_has_nl_token = AOUWAL_Deploy::has_netlify_token();
$aouwal_nl_ready     = AOUWAL_Deploy::netlify_configured();
$aouwal_any_ready    = $aouwal_github_ready || $aouwal_cf_ready || $aouwal_nl_ready;
$aouwal_targets      = AOUWAL_Deploy::enabled_targets();
$aouwal_any_enabled  = ! empty( $aouwal_targets );
$aouwal_deploy_rows  = ( $aouwal_last && is_array( $aouwal_last ) ) ? AOUWAL_Deploy::last_results( $aouwal_last ) : array();
$aouwal_can_deploy   = $aouwal_last && isset( $aouwal_last['phase'] ) && AOUWAL_Exporter::PHASE_DONE === $aouwal_last['phase'];
?>
<div class="aouwal-deploy">
	<h2><?php esc_html_e( 'Your last export', 'aouwal-static-site-export' ); ?></h2>
	<?php if ( $aouwal_download_url ) : ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $aouwal_download_url ); ?>" download>
				<?php esc_html_e( 'Download export ZIP', 'aouwal-static-site-export' ); ?>
			</a>
			<code><?php echo esc_html( $aouwal_last['id'] ); ?></code>
		</p>
	<?php else : ?>
		<p class="description"><?php esc_html_e( 'No export yet. Run an export from the Export tab first.', 'aouwal-static-site-export' ); ?></p>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Direct deployment', 'aouwal-static-site-export' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Push the finished export to a host from this screen. Deployment targets are off by default, so nothing is sent anywhere until you enable one and save a token.', 'aouwal-static-site-export' ); ?>
	</p>

	<form method="post" action="options.php" class="aouwal-deploy-settings">
		<?php settings_fields( 'aouwal_deploy_group' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'GitHub', 'aouwal-static-site-export' ); ?></th>
				<td>
					<label class="aouwal-inline">
						<input type="checkbox" id="aouwal_github_enabled" name="aouwal_deploy[github_enabled]" value="1" <?php checked( $aouwal_deploy['github_enabled'] ); ?> />
						<?php esc_html_e( 'Enable direct deployment to GitHub', 'aouwal-static-site-export' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Uses a personal access token with contents write access for one repository. No GitHub App or OAuth connection is required.', 'aouwal-static-site-export' ); ?></p>
				</td>
			</tr>
		</table>

		<div id="aouwal-github-fields" <?php echo $aouwal_deploy['github_enabled'] ? '' : 'hidden'; ?>>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aouwal_github_owner"><?php esc_html_e( 'Repository owner', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="aouwal_github_owner" name="aouwal_deploy[github_owner]" value="<?php echo esc_attr( $aouwal_deploy['github_owner'] ); ?>" autocomplete="off" placeholder="aouwalitshikkha" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aouwal_github_repo"><?php esc_html_e( 'Repository name', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="aouwal_github_repo" name="aouwal_deploy[github_repo]" value="<?php echo esc_attr( $aouwal_deploy['github_repo'] ); ?>" autocomplete="off" placeholder="my-static-site" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aouwal_github_branch"><?php esc_html_e( 'Branch', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="aouwal_github_branch" name="aouwal_deploy[github_branch]" value="<?php echo esc_attr( $aouwal_deploy['github_branch'] ); ?>" autocomplete="off" placeholder="main" />
						<p class="description"><?php esc_html_e( 'The branch receives one commit per deployment. A missing branch is created from the default branch on the first push.', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aouwal_github_path"><?php esc_html_e( 'Repository folder', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="aouwal_github_path" name="aouwal_deploy[github_path]" value="<?php echo esc_attr( $aouwal_deploy['github_path'] ); ?>" autocomplete="off" placeholder="public" />
						<p class="description"><?php esc_html_e( 'Optional. Deploy into this subfolder instead of the repository root. Leave blank to use the root.', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aouwal_github_token"><?php esc_html_e( 'Access token', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="aouwal_github_token" name="aouwal_deploy[github_token]" value="" autocomplete="new-password" placeholder="<?php echo $aouwal_has_token ? esc_attr__( 'Saved', 'aouwal-static-site-export' ) : ''; ?>" />
						<p class="description"><?php esc_html_e( 'A fine-grained or classic token with contents read and write access for this repository. The token is stored encrypted, never shown again and never written to the export or the log. Leave it blank to keep the saved token.', 'aouwal-static-site-export' ); ?></p>
						<?php if ( $aouwal_has_token ) : ?>
							<p>
								<label class="aouwal-inline"><input type="checkbox" name="aouwal_deploy[github_clear]" value="1" /> <?php esc_html_e( 'Remove the stored token', 'aouwal-static-site-export' ); ?></label>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aouwal_github_message"><?php esc_html_e( 'Commit message', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="aouwal_github_message" name="aouwal_deploy[github_message]" value="<?php echo esc_attr( $aouwal_deploy['github_message'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Use {date} for the deployment time. Example: Static export {date}', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'aouwal-static-site-export' ); ?></th>
					<td>
						<label class="aouwal-inline">
							<input type="checkbox" name="aouwal_deploy[github_on_export]" value="1" <?php checked( $aouwal_deploy['github_on_export'] ); ?> />
							<?php esc_html_e( 'Deploy automatically after every successful export', 'aouwal-static-site-export' ); ?>
						</label><br />
						<label class="aouwal-inline">
							<input type="checkbox" name="aouwal_deploy[github_prune]" value="1" <?php checked( $aouwal_deploy['github_prune'] ); ?> />
							<?php esc_html_e( 'Delete repository files that are no longer in the export', 'aouwal-static-site-export' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Removal only touches the selected folder and is off by default. Turn it on for a branch dedicated to the static site; leave it off when the branch also holds workflows or documentation.', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Cloudflare Pages', 'aouwal-static-site-export' ); ?></th>
				<td>
					<label class="aouwal-inline">
						<input type="checkbox" id="aouwal_cloudflare_enabled" name="aouwal_deploy[cloudflare_enabled]" value="1" <?php checked( $aouwal_deploy['cloudflare_enabled'] ); ?> />
						<?php esc_html_e( 'Enable direct deployment to Cloudflare Pages', 'aouwal-static-site-export' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Uploads the export to a Pages project with the direct upload API. The project must not be connected to a Git repository. An account ID and a Pages API token with edit access are required.', 'aouwal-static-site-export' ); ?></p>
				</td>
			</tr>
		</table>

		<div id="aouwal-cloudflare-fields" <?php echo $aouwal_deploy['cloudflare_enabled'] ? '' : 'hidden'; ?>>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aouwal_cloudflare_account_id"><?php esc_html_e( 'Account ID', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="aouwal_cloudflare_account_id" name="aouwal_deploy[cloudflare_account_id]" value="<?php echo esc_attr( $aouwal_deploy['cloudflare_account_id'] ); ?>" autocomplete="off" placeholder="023e105f4ecef8ad9ca31a8372d0c353" />
						<p class="description"><?php esc_html_e( 'The 32-character account ID from the Cloudflare dashboard URL or the account overview page.', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aouwal_cloudflare_project"><?php esc_html_e( 'Pages project', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="aouwal_cloudflare_project" name="aouwal_deploy[cloudflare_project]" value="<?php echo esc_attr( $aouwal_deploy['cloudflare_project'] ); ?>" autocomplete="off" placeholder="my-static-site" />
						<p class="description"><?php esc_html_e( 'The project name from Workers &amp; Pages, not the .pages.dev hostname. Direct upload projects only.', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aouwal_cloudflare_token"><?php esc_html_e( 'API token', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="aouwal_cloudflare_token" name="aouwal_deploy[cloudflare_token]" value="" autocomplete="new-password" placeholder="<?php echo $aouwal_has_cf_token ? esc_attr__( 'Saved', 'aouwal-static-site-export' ) : ''; ?>" />
						<p class="description"><?php esc_html_e( 'A Cloudflare API token with the Cloudflare Pages permission set to Edit for this account. Stored encrypted, never shown again and never written to the export or the log. Leave blank to keep the saved token.', 'aouwal-static-site-export' ); ?></p>
						<?php if ( $aouwal_has_cf_token ) : ?>
							<p>
								<label class="aouwal-inline"><input type="checkbox" name="aouwal_deploy[cloudflare_clear]" value="1" /> <?php esc_html_e( 'Remove the stored token', 'aouwal-static-site-export' ); ?></label>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aouwal_cloudflare_branch"><?php esc_html_e( 'Branch', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="aouwal_cloudflare_branch" name="aouwal_deploy[cloudflare_branch]" value="<?php echo esc_attr( $aouwal_deploy['cloudflare_branch'] ); ?>" autocomplete="off" placeholder="main" />
						<p class="description"><?php esc_html_e( 'Optional. Leave blank to deploy to the production branch. Set a branch name for a preview deployment.', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aouwal_cloudflare_zone_id"><?php esc_html_e( 'Zone ID (optional)', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="aouwal_cloudflare_zone_id" name="aouwal_deploy[cloudflare_zone_id]" value="<?php echo esc_attr( $aouwal_deploy['cloudflare_zone_id'] ); ?>" autocomplete="off" placeholder="023e105f4ecef8ad9ca31a8372d0c353" />
						<p class="description"><?php esc_html_e( 'Only needed for cache purging. The 32-character zone ID from the domain overview page, not the account ID.', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cache purge', 'aouwal-static-site-export' ); ?></th>
					<td>
						<label class="aouwal-inline">
							<input type="checkbox" name="aouwal_deploy[cloudflare_purge]" value="1" <?php checked( $aouwal_deploy['cloudflare_purge'] ); ?> />
							<?php esc_html_e( 'Purge the zone cache after every Cloudflare deployment', 'aouwal-static-site-export' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Runs a purge-everything request after a successful deploy, so a removed file stops being served from cache. The API token then also needs Zone > Cache Purge permission, and the zone ID above must be set.', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aouwal_cloudflare_message"><?php esc_html_e( 'Deployment message', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="aouwal_cloudflare_message" name="aouwal_deploy[cloudflare_message]" value="<?php echo esc_attr( $aouwal_deploy['cloudflare_message'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Use {date} for the deployment time. Example: Static export {date}', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'aouwal-static-site-export' ); ?></th>
					<td>
						<label class="aouwal-inline">
							<input type="checkbox" name="aouwal_deploy[cloudflare_on_export]" value="1" <?php checked( $aouwal_deploy['cloudflare_on_export'] ); ?> />
							<?php esc_html_e( 'Deploy automatically after every successful export', 'aouwal-static-site-export' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Only changed files are uploaded. Pages keeps every deployment as a preview, so removing content from the export does not delete the old deployment.', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Netlify', 'aouwal-static-site-export' ); ?></th>
				<td>
					<label class="aouwal-inline">
						<input type="checkbox" id="aouwal_netlify_enabled" name="aouwal_deploy[netlify_enabled]" value="1" <?php checked( $aouwal_deploy['netlify_enabled'] ); ?> />
						<?php esc_html_e( 'Enable direct deployment to Netlify', 'aouwal-static-site-export' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Uses the Netlify file digest API. Only files Netlify does not already have are uploaded. A site ID and a personal access token with access to that site are required.', 'aouwal-static-site-export' ); ?></p>
				</td>
			</tr>
		</table>

		<div id="aouwal-netlify-fields" <?php echo $aouwal_deploy['netlify_enabled'] ? '' : 'hidden'; ?>>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aouwal_netlify_site_id"><?php esc_html_e( 'Site ID', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="aouwal_netlify_site_id" name="aouwal_deploy[netlify_site_id]" value="<?php echo esc_attr( $aouwal_deploy['netlify_site_id'] ); ?>" autocomplete="off" placeholder="3970e0fe-8564-4903-9a55-c5f8de49fb8b" />
						<p class="description"><?php esc_html_e( 'Project configuration > General > Project details > Project information > Project ID. The site domain, such as mysite.netlify.app, also works.', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aouwal_netlify_token"><?php esc_html_e( 'Personal access token', 'aouwal-static-site-export' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="aouwal_netlify_token" name="aouwal_deploy[netlify_token]" value="" autocomplete="new-password" placeholder="<?php echo $aouwal_has_nl_token ? esc_attr__( 'Saved', 'aouwal-static-site-export' ) : ''; ?>" />
						<p class="description"><?php esc_html_e( 'Create one at Netlify: Applications > Personal access tokens > New access token. Stored encrypted, never shown again and never written to the export or the log. Leave blank to keep the saved token.', 'aouwal-static-site-export' ); ?></p>
						<?php if ( $aouwal_has_nl_token ) : ?>
							<p>
								<label class="aouwal-inline"><input type="checkbox" name="aouwal_deploy[netlify_clear]" value="1" /> <?php esc_html_e( 'Remove the stored token', 'aouwal-static-site-export' ); ?></label>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'aouwal-static-site-export' ); ?></th>
					<td>
						<label class="aouwal-inline">
							<input type="checkbox" name="aouwal_deploy[netlify_on_export]" value="1" <?php checked( $aouwal_deploy['netlify_on_export'] ); ?> />
							<?php esc_html_e( 'Deploy automatically after every successful export', 'aouwal-static-site-export' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Netlify deploys are atomic: the new deploy replaces the previous file set, so content removed from the export disappears from the site.', 'aouwal-static-site-export' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Save deployment settings', 'aouwal-static-site-export' ) ); ?>
	</form>

	<h2><?php esc_html_e( 'Deployment actions', 'aouwal-static-site-export' ); ?></h2>
	<p>
		<button type="button" class="button" id="aouwal-deploy-test" <?php disabled( ! $aouwal_any_ready ); ?>>
			<?php esc_html_e( 'Test connection', 'aouwal-static-site-export' ); ?>
		</button>
		<?php foreach ( $aouwal_targets as $aouwal_target ) : ?>
			<button type="button" class="button <?php echo 1 === count( $aouwal_targets ) ? 'button-primary' : ''; ?> aouwal-deploy-target" data-target="<?php echo esc_attr( $aouwal_target ); ?>" data-job="<?php echo $aouwal_can_deploy ? esc_attr( $aouwal_last['id'] ) : ''; ?>" <?php disabled( ! $aouwal_can_deploy ); ?>>
				<?php
				printf(
					/* translators: %s: deployment target name, such as GitHub or Netlify. */
					esc_html__( 'Deploy to %s', 'aouwal-static-site-export' ),
					esc_html( AOUWAL_Deploy::target_label( $aouwal_target ) )
				);
				?>
			</button>
		<?php endforeach; ?>
		<?php if ( count( $aouwal_targets ) > 1 ) : ?>
			<button type="button" class="button button-primary" id="aouwal-deploy-run" data-job="<?php echo $aouwal_can_deploy ? esc_attr( $aouwal_last['id'] ) : ''; ?>" <?php disabled( ! $aouwal_can_deploy ); ?>>
				<?php esc_html_e( 'Deploy all enabled', 'aouwal-static-site-export' ); ?>
			</button>
		<?php endif; ?>
		<span class="spinner" id="aouwal-deploy-spinner" aria-hidden="true"></span>
	</p>
	<?php if ( ! $aouwal_any_ready ) : ?>
		<p class="description"><?php esc_html_e( 'Enable a target, add its credentials and save the deployment settings to use these actions.', 'aouwal-static-site-export' ); ?></p>
	<?php elseif ( ! $aouwal_any_enabled ) : ?>
		<p class="description"><?php esc_html_e( 'Credentials are saved. Tick a target and save the deployment settings to enable its buttons.', 'aouwal-static-site-export' ); ?></p>
	<?php elseif ( ! $aouwal_can_deploy ) : ?>
		<p class="description"><?php esc_html_e( 'Run and finish an export before deploying.', 'aouwal-static-site-export' ); ?></p>
	<?php endif; ?>
	<div id="aouwal-deploy-result" role="status" aria-live="polite"></div>

	<?php if ( ! empty( $aouwal_deploy_rows ) ) : ?>
		<h3><?php esc_html_e( 'Last deployment result', 'aouwal-static-site-export' ); ?></h3>
		<table class="widefat striped aouwal-deploy-results">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Target', 'aouwal-static-site-export' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'aouwal-static-site-export' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Time', 'aouwal-static-site-export' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $aouwal_deploy_rows as $aouwal_row ) : ?>
					<tr>
						<td><?php echo esc_html( isset( $aouwal_row['target'] ) ? $aouwal_row['target'] : '' ); ?></td>
						<td>
							<?php echo ! empty( $aouwal_row['ok'] ) ? esc_html__( 'Succeeded', 'aouwal-static-site-export' ) : esc_html__( 'Failed', 'aouwal-static-site-export' ); ?>:
							<?php echo esc_html( isset( $aouwal_row['message'] ) ? $aouwal_row['message'] : '' ); ?>
							<?php if ( ! empty( $aouwal_row['url'] ) ) : ?>
								<a href="<?php echo esc_url( $aouwal_row['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View commit', 'aouwal-static-site-export' ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( isset( $aouwal_row['time'] ) ? $aouwal_row['time'] : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Deploy to Cloudflare Pages via GitHub', 'aouwal-static-site-export' ); ?></h2>
	<ol class="aouwal-steps">
		<li>
			<p><strong><?php esc_html_e( 'Download the export and unzip it into a new folder.', 'aouwal-static-site-export' ); ?></strong></p>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Create a GitHub repository and push the files.', 'aouwal-static-site-export' ); ?></strong></p>
			<pre class="aouwal-code"># from inside the folder where you unzipped the export
git init
git add .
git commit -m "Initial static export"
git branch -M main
git remote add origin https://github.com/USERNAME/REPO.git
git push -u origin main</pre>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Connect the repository to Cloudflare Pages.', 'aouwal-static-site-export' ); ?></strong></p>
			<ol class="aouwal-sublist">
				<li><?php esc_html_e( 'Open the Cloudflare dashboard → Workers &amp; Pages → Create → Pages → Connect to Git.', 'aouwal-static-site-export' ); ?></li>
				<li><?php esc_html_e( 'Select your repository.', 'aouwal-static-site-export' ); ?></li>
				<li><?php esc_html_e( 'Build command: leave blank.', 'aouwal-static-site-export' ); ?></li>
				<li><?php esc_html_e( 'Build output directory: / (the repository root).', 'aouwal-static-site-export' ); ?></li>
				<li><?php esc_html_e( 'Click Save and Deploy.', 'aouwal-static-site-export' ); ?></li>
			</ol>
		</li>
		<li>
			<p><strong><?php esc_html_e( 'Every future push redeploys automatically.', 'aouwal-static-site-export' ); ?></strong></p>
		</li>
	</ol>

	<div class="notice notice-info inline">
		<p>
			<?php esc_html_e( 'When enabled in Settings, the export includes a _redirects file (Cloudflare Pages) and a _headers file for caching. Query-string URLs such as /?p=123 cannot be redirected by Cloudflare Pages; use path-based URLs going forward.', 'aouwal-static-site-export' ); ?>
		</p>
	</div>

	<div class="notice notice-info inline">
		<?php if ( $aouwal_under_uploads ) : ?>
			<p>
				<?php esc_html_e( 'Export files are stored in a subdirectory of your uploads folder, protected from browsing with an index.php file and, on Apache, a .htaccess deny rule. The ZIP is downloadable only through the signed link above, which expires and stops working when the job is pruned.', 'aouwal-static-site-export' ); ?>
			</p>
			<?php if ( $aouwal_is_nginx ) : ?>
				<p>
					<?php esc_html_e( 'This server runs Nginx, which ignores .htaccess. To block direct access to the export folder, add this to your server block and reload Nginx, or move exports outside the web root with the aouwal_export_dir filter:', 'aouwal-static-site-export' ); ?>
				</p>
				<pre class="aouwal-code">location ^~ <?php echo esc_html( $aouwal_export_rel ); ?> {
	deny all;
}</pre>
			<?php endif; ?>
		<?php else : ?>
			<p>
				<?php esc_html_e( 'Export files are stored outside the web root, so they cannot be reached by any direct URL on this server. The ZIP is downloadable only through the signed link above, which expires and stops working when the job is pruned.', 'aouwal-static-site-export' ); ?>
			</p>
		<?php endif; ?>
	</div>
</div>
