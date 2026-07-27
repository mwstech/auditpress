<?php
/**
 * Admin page: enable toggle and token lifecycle.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools > PluginLens. Default WordPress admin markup only.
 */
class PluginLens_Settings {

	const PAGE_SLUG = 'pluginlens';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_pluginlens_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_pluginlens_regenerate_token', array( $this, 'handle_regenerate_token' ) );
	}

	/**
	 * Capability required to manage PluginLens.
	 *
	 * @return string
	 */
	private function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Adds the menu item under Tools.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_management_page(
			__( 'PluginLens', 'pluginlens' ),
			__( 'PluginLens', 'pluginlens' ),
			$this->capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Saves the enable toggle.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage PluginLens.', 'pluginlens' ) );
		}
		check_admin_referer( 'pluginlens_save_settings' );

		$enabled = isset( $_POST['pluginlens_enabled'] ) ? '1' : '0';
		update_option( PluginLens_Token_Auth::OPTION_ENABLED, $enabled );

		$this->redirect_back( 'settings_saved' );
	}

	/**
	 * Generates a token, or revokes and regenerates when one already exists.
	 *
	 * @return void
	 */
	public function handle_regenerate_token() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage PluginLens.', 'pluginlens' ) );
		}
		check_admin_referer( 'pluginlens_regenerate_token' );

		PluginLens_Token_Auth::generate_token();

		$this->redirect_back( 'token_regenerated' );
	}

	/**
	 * Redirects back to the settings page with a notice key.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect_back( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::PAGE_SLUG,
					'pluginlens_notice' => $notice,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to manage PluginLens.', 'pluginlens' ) );
		}

		$enabled   = '1' === get_option( PluginLens_Token_Auth::OPTION_ENABLED, '' );
		$token     = get_option( PluginLens_Token_Auth::OPTION_TOKEN, '' );
		$has_token = is_string( $token ) && '' !== $token;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a notice key set by our own redirect.
		$notice = isset( $_GET['pluginlens_notice'] ) ? sanitize_key( wp_unslash( $_GET['pluginlens_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PluginLens', 'pluginlens' ); ?></h1>

			<?php if ( 'settings_saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'pluginlens' ); ?></p></div>
			<?php elseif ( 'token_regenerated' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'A new token was generated. Any previous token stopped working immediately.', 'pluginlens' ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'PluginLens exposes a read-only MCP endpoint so AI clients can inspect this site\'s plugin estate. The endpoint is off by default and requires a secret token.', 'pluginlens' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pluginlens_save_settings" />
				<?php wp_nonce_field( 'pluginlens_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'MCP endpoint', 'pluginlens' ); ?></th>
						<td>
							<label for="pluginlens_enabled">
								<input name="pluginlens_enabled" type="checkbox" id="pluginlens_enabled" value="1" <?php checked( $enabled ); ?> />
								<?php esc_html_e( 'Enable the MCP endpoint', 'pluginlens' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Enabling exposes read-only information about this site to anyone holding the token: the plugin list with versions and health data, WordPress/PHP/database versions, known vulnerabilities matched to installed versions, autoloaded option weight, cron schedules, database table names and sizes, and shortcode/block usage counts. No post content, user data, or credentials are ever exposed, and nothing can be changed through the endpoint. While disabled, the endpoint answers 404 to everything.', 'pluginlens' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Changes', 'pluginlens' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Access token', 'pluginlens' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pluginlens_regenerate_token" />
				<?php wp_nonce_field( 'pluginlens_regenerate_token' ); ?>
				<?php if ( $has_token ) : ?>
					<p><?php esc_html_e( 'A token exists. Revoking and regenerating invalidates the old token immediately: every existing connection (including any Claude or other MCP connector using the old URL) will stop working until it is updated with the new URL.', 'pluginlens' ); ?></p>
					<?php submit_button( __( 'Revoke and regenerate', 'pluginlens' ), 'secondary', 'submit', false ); ?>
				<?php else : ?>
					<p><?php esc_html_e( 'No token exists yet. Generate one to build the connection URL.', 'pluginlens' ); ?></p>
					<?php submit_button( __( 'Generate token', 'pluginlens' ), 'primary', 'submit', false ); ?>
				<?php endif; ?>
			</form>

			<?php if ( $enabled && $has_token ) : ?>
				<h2><?php esc_html_e( 'Connection URL', 'pluginlens' ); ?></h2>
				<p><?php esc_html_e( 'Add this URL to your MCP client (for example, Claude custom connectors). Treat it like a password.', 'pluginlens' ); ?></p>
				<?php $connection_url = rest_url( PluginLens_MCP_Server::REST_NAMESPACE . '/mcp/' . $token ); ?>
				<p>
					<input type="text" readonly id="pluginlens-connection-url" class="large-text code" value="<?php echo esc_attr( $connection_url ); ?>" onfocus="this.select();" />
				</p>
				<p>
					<button type="button" class="button" id="pluginlens-copy-url"><?php esc_html_e( 'Copy URL', 'pluginlens' ); ?></button>
					<span id="pluginlens-copy-done" style="display:none;"><?php esc_html_e( 'Copied.', 'pluginlens' ); ?></span>
				</p>
				<script>
					document.getElementById( 'pluginlens-copy-url' ).addEventListener( 'click', function () {
						var field = document.getElementById( 'pluginlens-connection-url' );
						field.select();
						var done = function () {
							document.getElementById( 'pluginlens-copy-done' ).style.display = 'inline';
						};
						if ( navigator.clipboard && navigator.clipboard.writeText ) {
							navigator.clipboard.writeText( field.value ).then( done );
						} else {
							document.execCommand( 'copy' );
							done();
						}
					} );
				</script>
			<?php elseif ( $has_token ) : ?>
				<p><?php esc_html_e( 'A token exists but the endpoint is disabled, so no connection URL is shown. Enable the endpoint above.', 'pluginlens' ); ?></p>
			<?php endif; ?>

			<hr />

			<h2><?php esc_html_e( 'External services', 'pluginlens' ); ?></h2>
			<p><?php esc_html_e( 'To enrich its answers, PluginLens contacts three public services. The only data ever sent is plugin slugs and version strings. No site content, no URLs beyond the API hosts, and no personal data leave this site.', 'pluginlens' ); ?></p>
			<table class="widefat striped" style="max-width:700px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Service', 'pluginlens' ); ?></th>
						<th><?php esc_html_e( 'What is sent', 'pluginlens' ); ?></th>
						<th><?php esc_html_e( 'What it answers', 'pluginlens' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>api.wordpress.org</td>
						<td><?php esc_html_e( 'Plugin slugs', 'pluginlens' ); ?></td>
						<td><?php esc_html_e( 'Last updated, tested-up-to, installs, ratings, support activity', 'pluginlens' ); ?></td>
					</tr>
					<tr>
						<td>wpvulnerability.net</td>
						<td><?php esc_html_e( 'Plugin slugs and the WordPress version', 'pluginlens' ); ?></td>
						<td><?php esc_html_e( 'Published vulnerability records', 'pluginlens' ); ?></td>
					</tr>
					<tr>
						<td>endoflife.date</td>
						<td><?php esc_html_e( 'Product names only (php, WordPress, mysql, mariadb)', 'pluginlens' ); ?></td>
						<td><?php esc_html_e( 'Support and end-of-life dates', 'pluginlens' ); ?></td>
					</tr>
				</tbody>
			</table>

			<?php $auth_log = PluginLens_Request_Guard::auth_log(); ?>
			<?php if ( array() !== $auth_log ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Failed authentication attempts', 'pluginlens' ); ?></h2>
				<p><?php esc_html_e( 'The most recent failed attempts against the endpoint (up to 50, newest first). No token material is ever recorded. The log clears when the token is regenerated.', 'pluginlens' ); ?></p>
				<table class="widefat striped" style="max-width:700px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time (UTC)', 'pluginlens' ); ?></th>
							<th><?php esc_html_e( 'IP address', 'pluginlens' ); ?></th>
							<th><?php esc_html_e( 'User agent', 'pluginlens' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $auth_log as $attempt ) : ?>
							<tr>
								<td><?php echo esc_html( isset( $attempt['time'] ) ? $attempt['time'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $attempt['ip'] ) ? $attempt['ip'] : '' ); ?></td>
								<td><?php echo esc_html( isset( $attempt['user_agent'] ) ? $attempt['user_agent'] : '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
