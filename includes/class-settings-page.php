<?php
/**
 * The settings screens.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the settings, wherever they live on this install.
 *
 * There are three shapes of this screen. A single site gets one form holding
 * the whole policy. A multisite network gets the same form in the network
 * admin, plus the switch deciding whether its sites may override anything. And
 * a site on that network gets either an override form, where an empty field
 * inherits the network value shown behind it, or a read only summary when the
 * network keeps the policy to itself.
 */
class Settings_Page {

	/**
	 * Slug of the settings screen.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'revision-retention';

	/**
	 * Action a site's settings form posts to.
	 *
	 * @var string
	 */
	private const SAVE_ACTION = 'rvrt_save_settings';

	/**
	 * Action the network settings form posts to.
	 *
	 * @var string
	 */
	private const NETWORK_ACTION = 'rvrt_save_network_settings';

	/**
	 * Action the sweep buttons post to.
	 *
	 * @var string
	 */
	private const RUN_ACTION = 'rvrt_run_sweep';

	/**
	 * Hook the screens into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		$basename = plugin_basename( RVRT_PLUGIN_FILE );

		add_filter( 'plugin_action_links_' . $basename, array( $this, 'add_action_link' ) );
		add_filter( 'network_admin_plugin_action_links_' . $basename, array( $this, 'add_action_link' ) );

		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save_settings' ) );
		add_action( 'admin_post_' . self::RUN_ACTION, array( $this, 'run_sweep' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_menu', array( $this, 'add_page' ) );

		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'add_network_page' ) );
			add_action( 'network_admin_edit_' . self::NETWORK_ACTION, array( $this, 'save_network_settings' ) );
		}
	}

	/**
	 * Add the screen below Settings in a site's admin.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Revision Retention', 'revision-retention' ),
			__( 'Revision Retention', 'revision-retention' ),
			Settings::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Add the screen below Settings in the network admin.
	 *
	 * @return void
	 */
	public function add_network_page(): void {
		add_submenu_page(
			'settings.php',
			__( 'Revision Retention', 'revision-retention' ),
			__( 'Revision Retention', 'revision-retention' ),
			Settings::capability( true ),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load the screen's stylesheet and script.
	 *
	 * @param string $hook_suffix Screen the hook fired for.
	 *
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( ! is_string( $hook_suffix ) || ! str_contains( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		$url = plugin_dir_url( RVRT_PLUGIN_FILE );

		wp_enqueue_style( 'rvrt-settings', $url . 'assets/settings.css', array(), RVRT_VERSION );
		wp_enqueue_script( 'rvrt-settings', $url . 'assets/settings.js', array(), RVRT_VERSION, true );
	}

	/**
	 * Put a link to the settings screen on the plugin's row.
	 *
	 * Left untyped on purpose: this is a filter callback in a file under
	 * strict_types, so a loosely typed value from a third party would
	 * otherwise be fatal.
	 *
	 * @param mixed $links Action links of this plugin.
	 *
	 * @return mixed
	 */
	public function add_action_link( $links ) {
		$is_network = is_network_admin();

		if ( ! is_array( $links ) || ! current_user_can( Settings::capability( $is_network ) ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::page_url( $is_network ) ),
				esc_html__( 'Settings', 'revision-retention' )
			)
		);

		return $links;
	}

	/**
	 * URL of the settings screen in one of the two admins.
	 *
	 * @param bool $network Whether the network screen is meant.
	 *
	 * @return string
	 */
	private static function page_url( bool $network = false ): string {
		return $network
			? network_admin_url( 'settings.php?page=' . self::PAGE_SLUG )
			: admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Render whichever shape of the screen applies here.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$is_network = is_network_admin();

		if ( ! current_user_can( Settings::capability( $is_network ) ) ) {
			return;
		}

		$read_only = ! $is_network && is_multisite() && ! Settings::allows_site_override();
		?>
		<div class="wrap rvrt-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php
			$this->render_notice();
			$this->render_intro( $is_network, $read_only );

			if ( $read_only ) {
				$this->render_summary();
				$this->render_sweep_form();

				return;
			}

			$this->render_form( $is_network );

			if ( ! $is_network ) {
				$this->render_sweep_form();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Explain, in a sentence, what this screen decides.
	 *
	 * @param bool $is_network Whether the network screen is being rendered.
	 * @param bool $read_only  Whether this site may not override anything.
	 *
	 * @return void
	 */
	private function render_intro( bool $is_network, bool $read_only ): void {
		if ( $is_network ) {
			$text = __( 'These are the defaults for every site on this network. Sites may override them only while the switch at the bottom allows it.', 'revision-retention' );
		} elseif ( $read_only ) {
			$text = __( 'The retention policy for this site is set network wide and cannot be changed here.', 'revision-retention' );
		} elseif ( is_multisite() ) {
			$text = __( 'These settings override the network defaults for this site. Leave a field empty to inherit the value shown behind it.', 'revision-retention' );
		} else {
			$text = __( 'Revisions are kept per post: the newest few are always retained, and anything older than the threshold is removed by a scheduled sweep.', 'revision-retention' );
		}

		printf( '<p class="rvrt-intro">%s</p>', esc_html( $text ) );
	}

	/**
	 * Show the outcome of the last thing this screen did.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only flags set by our own redirect after a nonce checked request.
		$notice = isset( $_GET['rvrt-notice'] ) ? sanitize_key( wp_unslash( $_GET['rvrt-notice'] ) ) : '';

		if ( '' === $notice ) {
			return;
		}

		$revisions = isset( $_GET['rvrt-revisions'] ) ? absint( wp_unslash( $_GET['rvrt-revisions'] ) ) : 0;
		$posts     = isset( $_GET['rvrt-posts'] ) ? absint( wp_unslash( $_GET['rvrt-posts'] ) ) : 0;
		$finished  = isset( $_GET['rvrt-finished'] ) && '1' === $_GET['rvrt-finished'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = match ( $notice ) {
			'saved' => __( 'Settings saved.', 'revision-retention' ),
			'preview' => sprintf(
				/* translators: 1: number of revisions, 2: number of posts. */
				_n(
					'%1$d revision in %2$d post would be removed. Nothing has been deleted.',
					'%1$d revisions across %2$d posts would be removed. Nothing has been deleted.',
					$revisions,
					'revision-retention'
				),
				$revisions,
				$posts
			),
			'swept' => sprintf(
				/* translators: 1: number of revisions, 2: number of posts. */
				_n(
					'Removed %1$d revision from %2$d post.',
					'Removed %1$d revisions from %2$d posts.',
					$revisions,
					'revision-retention'
				),
				$revisions,
				$posts
			),
			default => '',
		};

		if ( '' === $message ) {
			return;
		}

		if ( 'swept' === $notice && ! $finished ) {
			$message .= ' ' . __( 'There is more to do; the rest continues in the background.', 'revision-retention' );
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Render the network policy as plain text, for a site that cannot change it.
	 *
	 * @return void
	 */
	private function render_summary(): void {
		$counts = Cleaner::counts();
		?>
		<table class="widefat striped rvrt-summary">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Post type', 'revision-retention' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Revisions', 'revision-retention' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Keep', 'revision-retention' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Remove older than', 'revision-retention' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stored now', 'revision-retention' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( Post_Types::eligible() as $post_type => $label ) : ?>
				<?php $rule = Policy::for_post_type( $post_type ); ?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td><?php echo esc_html( Post_Types::supports_revisions( $post_type ) ? __( 'On', 'revision-retention' ) : __( 'Off', 'revision-retention' ) ); ?></td>
					<td><?php echo esc_html( self::describe_keep( $rule->keep ) ); ?></td>
					<td><?php echo esc_html( self::describe_age( $rule->max_age_days ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $counts[ $post_type ] ?? 0 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the settings form.
	 *
	 * @param bool $is_network Whether the network screen is being rendered.
	 *
	 * @return void
	 */
	private function render_form( bool $is_network ): void {
		$inheritable = ! $is_network && is_multisite();
		$stored      = $is_network ? Settings::network() : ( $inheritable ? Settings::site() : Settings::resolved() );
		$inherited   = Settings::network();
		$action      = $is_network
			? network_admin_url( 'edit.php?action=' . self::NETWORK_ACTION )
			: admin_url( 'admin-post.php' );
		?>
		<form method="post" action="<?php echo esc_url( $action ); ?>">
			<?php
			wp_nonce_field( $is_network ? self::NETWORK_ACTION : self::SAVE_ACTION );

			if ( ! $is_network ) {
				printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::SAVE_ACTION ) );
			}
			?>

			<h2><?php esc_html_e( 'Retention policy', 'revision-retention' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="rvrt-keep"><?php esc_html_e( 'Always keep', 'revision-retention' ); ?></label>
					</th>
					<td>
						<?php
						$this->render_number( 'keep', 'rvrt-keep', $stored, $inherited, $inheritable, -1 );
						?>
						<p class="description">
							<?php esc_html_e( 'The newest revisions of every post, which are never removed however old they get. Use 0 to keep none, or -1 to keep every revision and never purge anything.', 'revision-retention' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="rvrt-max-age-days"><?php esc_html_e( 'Remove older than', 'revision-retention' ); ?></label>
					</th>
					<td>
						<?php
						$this->render_number( 'max_age_days', 'rvrt-max-age-days', $stored, $inherited, $inheritable, 0 );
						echo ' <span class="rvrt-unit">' . esc_html__( 'days', 'revision-retention' ) . '</span>';
						?>
						<p class="description">
							<?php esc_html_e( 'Revisions past this age are removed by the sweep, except for the newest ones above. Use 0 to switch the age threshold off and only cap the count.', 'revision-retention' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Post types', 'revision-retention' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Leave a field empty to use the policy above. Switching revisions on for a post type that does not store them applies from the next time such a post is saved.', 'revision-retention' ); ?>
			</p>
			<?php $this->render_post_types_table( $stored, ! $is_network ); ?>

			<h2><?php esc_html_e( 'Scheduled sweep', 'revision-retention' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Run automatically', 'revision-retention' ); ?></th>
					<td>
						<?php $this->render_bool( 'cron_enabled', 'rvrt-cron-enabled', __( 'Sweep old revisions in the background', 'revision-retention' ), $stored, $inherited, $inheritable ); ?>
						<p class="description"><?php esc_html_e( 'Each run works through the site in batches and books the next batch itself, so a large site is cleaned up over several runs instead of one long one.', 'revision-retention' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="rvrt-cron-interval"><?php esc_html_e( 'How often', 'revision-retention' ); ?></label>
					</th>
					<td><?php $this->render_interval( $stored, $inherited, $inheritable ); ?></td>
				</tr>
				<tr>
					<th scope="row">
						<label for="rvrt-batch-size"><?php esc_html_e( 'Posts per batch', 'revision-retention' ); ?></label>
					</th>
					<td>
						<?php $this->render_number( 'batch_size', 'rvrt-batch-size', $stored, $inherited, $inheritable, 10 ); ?>
						<p class="description"><?php esc_html_e( 'Lower this if a sweep is too heavy for the server, raise it to get through a large site sooner.', 'revision-retention' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Uninstall', 'revision-retention' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'On removal', 'revision-retention' ); ?></th>
					<td>
						<?php $this->render_bool( 'remove_data_on_uninstall', 'rvrt-remove-data', __( 'Remove all data of this plugin when it is uninstalled', 'revision-retention' ), $stored, $inherited, $inheritable ); ?>
						<p class="description"><?php esc_html_e( 'Deletes these settings. Revisions already removed cannot be brought back either way.', 'revision-retention' ); ?></p>
					</td>
				</tr>
			</table>

			<?php if ( $is_network ) : ?>
				<h2><?php esc_html_e( 'Sites', 'revision-retention' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Overrides', 'revision-retention' ); ?></th>
						<td>
							<label for="rvrt-allow-site-override">
								<input type="checkbox" id="rvrt-allow-site-override" name="rvrt_settings[allow_site_override]" value="1" <?php checked( ! empty( $stored['allow_site_override'] ) ); ?> />
								<?php esc_html_e( 'Let each site override these defaults', 'revision-retention' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'With this off, every site follows the policy above and its own screen only reports it. Overrides a site saved earlier are kept and take effect again when this is switched back on.', 'revision-retention' ); ?></p>
						</td>
					</tr>
				</table>
			<?php endif; ?>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render the per post type table.
	 *
	 * @param array<string, mixed> $stored      Values as stored for this screen.
	 * @param bool                 $show_counts Whether to report what is stored right now.
	 *
	 * @return void
	 */
	private function render_post_types_table( array $stored, bool $show_counts ): void {
		// A network screen has no single site to count revisions on, so the
		// column would report the main site's numbers as if they were everyone's.
		$counts    = $show_counts ? Cleaner::counts() : array();
		$enabled   = isset( $stored['enable_revisions'] ) && is_array( $stored['enable_revisions'] ) ? $stored['enable_revisions'] : array();
		$overrides = isset( $stored['post_types'] ) && is_array( $stored['post_types'] ) ? $stored['post_types'] : array();
		?>
		<table class="widefat striped rvrt-post-types">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Post type', 'revision-retention' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Revisions', 'revision-retention' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Always keep', 'revision-retention' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Remove older than', 'revision-retention' ); ?></th>
					<?php if ( $show_counts ) : ?>
						<th scope="col"><?php esc_html_e( 'Stored now', 'revision-retention' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach ( Post_Types::eligible() as $post_type => $label ) :
				$native   = Post_Types::supports_revisions( $post_type ) && ! in_array( $post_type, $enabled, true );
				$rule     = Policy::for_post_type( $post_type );
				$override = isset( $overrides[ $post_type ] ) && is_array( $overrides[ $post_type ] ) ? $overrides[ $post_type ] : array();
				?>
				<tr>
					<th scope="row">
						<?php echo esc_html( $label ); ?>
						<code><?php echo esc_html( $post_type ); ?></code>
					</th>
					<td>
						<?php if ( $native ) : ?>
							<span class="rvrt-native"><?php esc_html_e( 'Supported', 'revision-retention' ); ?></span>
						<?php else : ?>
							<label>
								<input type="checkbox" name="rvrt_settings[enable_revisions][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $enabled, true ) ); ?> />
								<?php esc_html_e( 'Enable', 'revision-retention' ); ?>
							</label>
						<?php endif; ?>
					</td>
					<td>
						<input
							type="number"
							min="-1"
							step="1"
							class="small-text"
							name="rvrt_settings[post_types][<?php echo esc_attr( $post_type ); ?>][keep]"
							value="<?php echo esc_attr( isset( $override['keep'] ) ? (string) $override['keep'] : '' ); ?>"
							placeholder="<?php echo esc_attr( (string) $rule->keep ); ?>"
							aria-label="<?php echo esc_attr( sprintf( /* translators: %s: post type label. */ __( 'Revisions to always keep for %s', 'revision-retention' ), $label ) ); ?>"
						/>
					</td>
					<td>
						<input
							type="number"
							min="0"
							step="1"
							class="small-text"
							name="rvrt_settings[post_types][<?php echo esc_attr( $post_type ); ?>][max_age_days]"
							value="<?php echo esc_attr( isset( $override['max_age_days'] ) ? (string) $override['max_age_days'] : '' ); ?>"
							placeholder="<?php echo esc_attr( (string) $rule->max_age_days ); ?>"
							aria-label="<?php echo esc_attr( sprintf( /* translators: %s: post type label. */ __( 'Age in days after which revisions of %s are removed', 'revision-retention' ), $label ) ); ?>"
						/>
						<span class="rvrt-unit"><?php esc_html_e( 'days', 'revision-retention' ); ?></span>
					</td>
					<?php if ( $show_counts ) : ?>
						<td><?php echo esc_html( number_format_i18n( $counts[ $post_type ] ?? 0 ) ); ?></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the preview and run buttons, plus what the last sweep did.
	 *
	 * @return void
	 */
	private function render_sweep_form(): void {
		$state = Scheduler::state();
		$next  = wp_next_scheduled( Scheduler::HOOK );
		?>
		<h2><?php esc_html_e( 'Sweep now', 'revision-retention' ); ?></h2>
		<p class="description">
			<?php
			if ( $state['cursor'] > 0 ) {
				esc_html_e( 'A sweep is part way through this site and continues from where it stopped.', 'revision-retention' );
			} elseif ( $state['finished'] > 0 ) {
				printf(
					/* translators: 1: how long ago the last sweep finished, 2: number of revisions it removed. */
					esc_html__( 'The last sweep finished %1$s ago and removed %2$s revisions.', 'revision-retention' ),
					esc_html( human_time_diff( $state['finished'] ) ),
					esc_html( number_format_i18n( $state['removed'] ) )
				);
			} else {
				esc_html_e( 'No sweep has finished on this site yet.', 'revision-retention' );
			}

			if ( $next ) {
				echo ' ';
				printf(
					/* translators: %s: time until the next scheduled sweep. */
					esc_html__( 'The next one is due in %s.', 'revision-retention' ),
					esc_html( human_time_diff( (int) $next ) )
				);
			}
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="rvrt-sweep">
			<?php wp_nonce_field( self::RUN_ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::RUN_ACTION ); ?>" />
			<button type="submit" name="mode" value="preview" class="button">
				<?php esc_html_e( 'Preview', 'revision-retention' ); ?>
			</button>
			<button type="submit" name="mode" value="run" class="button button-primary">
				<?php esc_html_e( 'Run one batch now', 'revision-retention' ); ?>
			</button>
			<p class="description">
				<?php esc_html_e( 'Preview reports what the policy would remove without deleting anything. Running a batch deletes for real and hands the rest back to the schedule.', 'revision-retention' ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Render one numeric field.
	 *
	 * @param string               $key         Setting key.
	 * @param string               $id          HTML id.
	 * @param array<string, mixed> $stored      Values as stored for this screen.
	 * @param array<string, mixed> $inherited   Network values to show behind an empty field.
	 * @param bool                 $inheritable Whether an empty field inherits.
	 * @param int                  $min         Lowest value the field accepts.
	 *
	 * @return void
	 */
	private function render_number( string $key, string $id, array $stored, array $inherited, bool $inheritable, int $min ): void {
		$value = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';

		printf(
			'<input type="number" min="%1$s" step="1" class="small-text" id="%2$s" name="rvrt_settings[%3$s]" value="%4$s"%5$s />',
			esc_attr( (string) $min ),
			esc_attr( $id ),
			esc_attr( $key ),
			esc_attr( $value ),
			$inheritable
				? sprintf( ' placeholder="%s"', esc_attr( (string) ( $inherited[ $key ] ?? '' ) ) )
				: ''
		);
	}

	/**
	 * Render one checkbox, or a three way choice when it can be inherited.
	 *
	 * @param string               $key         Setting key.
	 * @param string               $id          HTML id.
	 * @param string               $text        Label next to the control.
	 * @param array<string, mixed> $stored      Values as stored for this screen.
	 * @param array<string, mixed> $inherited   Network values to fall back to.
	 * @param bool                 $inheritable Whether an empty value inherits.
	 *
	 * @return void
	 */
	private function render_bool( string $key, string $id, string $text, array $stored, array $inherited, bool $inheritable ): void {
		if ( ! $inheritable ) {
			printf(
				'<label for="%1$s"><input type="checkbox" id="%1$s" name="rvrt_settings[%2$s]" value="1"%3$s /> %4$s</label>',
				esc_attr( $id ),
				esc_attr( $key ),
				checked( ! empty( $stored[ $key ] ), true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed, safe attribute string.
				esc_html( $text )
			);

			return;
		}

		$value = isset( $stored[ $key ] ) ? ( $stored[ $key ] ? '1' : '0' ) : '';
		?>
		<label for="<?php echo esc_attr( $id ); ?>" class="rvrt-inherit-label"><?php echo esc_html( $text ); ?></label>
		<select id="<?php echo esc_attr( $id ); ?>" name="rvrt_settings[<?php echo esc_attr( $key ); ?>]">
			<option value="" <?php selected( '', $value ); ?>>
				<?php
				printf(
					/* translators: %s: the value inherited from the network. */
					esc_html__( 'Inherit from network (%s)', 'revision-retention' ),
					empty( $inherited[ $key ] ) ? esc_html__( 'off', 'revision-retention' ) : esc_html__( 'on', 'revision-retention' )
				);
				?>
			</option>
			<option value="1" <?php selected( '1', $value ); ?>><?php esc_html_e( 'On', 'revision-retention' ); ?></option>
			<option value="0" <?php selected( '0', $value ); ?>><?php esc_html_e( 'Off', 'revision-retention' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Render the interval choice.
	 *
	 * @param array<string, mixed> $stored      Values as stored for this screen.
	 * @param array<string, mixed> $inherited   Network values to fall back to.
	 * @param bool                 $inheritable Whether an empty value inherits.
	 *
	 * @return void
	 */
	private function render_interval( array $stored, array $inherited, bool $inheritable ): void {
		$value     = isset( $stored['cron_interval'] ) ? (string) $stored['cron_interval'] : '';
		$intervals = Settings::intervals();
		?>
		<select id="rvrt-cron-interval" name="rvrt_settings[cron_interval]">
			<?php if ( $inheritable ) : ?>
				<option value="" <?php selected( '', $value ); ?>>
					<?php
					printf(
						/* translators: %s: the interval inherited from the network. */
						esc_html__( 'Inherit from network (%s)', 'revision-retention' ),
						esc_html( $intervals[ (string) ( $inherited['cron_interval'] ?? '' ) ] ?? '' )
					);
					?>
				</option>
			<?php endif; ?>
			<?php foreach ( $intervals as $interval => $label ) : ?>
				<option value="<?php echo esc_attr( $interval ); ?>" <?php selected( $interval, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Persist a site's settings form.
	 *
	 * @return void
	 */
	public function save_settings(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'revision-retention' ) );
		}

		check_admin_referer( self::SAVE_ACTION );

		if ( is_multisite() && ! Settings::allows_site_override() ) {
			wp_die( esc_html__( 'The retention policy for this site is set network wide.', 'revision-retention' ) );
		}

		Settings::update_site( Settings::sanitize( self::posted_settings() ) );
		Policy::flush();
		Scheduler::reschedule();

		$this->redirect( array( 'rvrt-notice' => 'saved' ) );
	}

	/**
	 * Persist the network settings form.
	 *
	 * Network screens cannot post to options.php, so this handles the save.
	 *
	 * @return void
	 */
	public function save_network_settings(): void {
		if ( ! current_user_can( Settings::capability( true ) ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'revision-retention' ) );
		}

		check_admin_referer( self::NETWORK_ACTION );

		Settings::update_network( Settings::sanitize( self::posted_settings(), true ) );
		Policy::flush();

		$this->redirect( array( 'rvrt-notice' => 'saved' ), true );
	}

	/**
	 * Run one batch, or report what one would do.
	 *
	 * @return void
	 */
	public function run_sweep(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'revision-retention' ) );
		}

		check_admin_referer( self::RUN_ACTION );

		$dry_run = ! isset( $_POST['mode'] ) || 'run' !== sanitize_key( wp_unslash( $_POST['mode'] ) );
		$cursor  = $dry_run ? 0 : Scheduler::state()['cursor'];
		$result  = ( new Cleaner() )->sweep( (int) Settings::get( 'batch_size' ), $dry_run, $cursor );

		if ( ! $dry_run ) {
			// Hand the rest back to the schedule rather than pushing on here,
			// so a big site does not hold the request open.
			Scheduler::reschedule( $result->finished ? 0 : MINUTE_IN_SECONDS );

			if ( $result->finished ) {
				Scheduler::reset_cursor();
			} else {
				$state           = Scheduler::state();
				$state['cursor'] = $result->cursor;

				update_option( Scheduler::CURSOR_OPTION, $state );
			}
		}

		$this->redirect(
			array(
				'rvrt-notice'    => $dry_run ? 'preview' : 'swept',
				'rvrt-revisions' => (string) $result->revisions,
				'rvrt-posts'     => (string) $result->posts,
				'rvrt-finished'  => $result->finished ? '1' : '0',
			)
		);
	}

	/**
	 * The settings as submitted, unslashed but not yet sanitized.
	 *
	 * @return array<string, mixed>
	 */
	private static function posted_settings(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Both callers check the nonce before getting here.
		if ( ! isset( $_POST['rvrt_settings'] ) || ! is_array( $_POST['rvrt_settings'] ) ) {
			return array();
		}

		// Settings::sanitize() reduces this to a fixed set of known keys and
		// scalar types, so the raw value never reaches the database.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked by both callers; sanitized by Settings::sanitize().
		return (array) wp_unslash( $_POST['rvrt_settings'] );
	}

	/**
	 * Send the administrator back to the screen they came from.
	 *
	 * @param array<string, string> $arguments Query arguments carrying the outcome.
	 * @param bool                  $network   Whether to return to the network screen.
	 *
	 * @return void
	 */
	private function redirect( array $arguments, bool $network = false ): void {
		wp_safe_redirect( add_query_arg( $arguments, self::page_url( $network ) ) );

		exit;
	}

	/**
	 * Put a keep count into words.
	 *
	 * @param int $keep Number of revisions kept.
	 *
	 * @return string
	 */
	private static function describe_keep( int $keep ): string {
		if ( $keep < 0 ) {
			return __( 'All', 'revision-retention' );
		}

		return 0 === $keep ? __( 'None', 'revision-retention' ) : number_format_i18n( $keep );
	}

	/**
	 * Put an age threshold into words.
	 *
	 * @param int $days Age threshold in days.
	 *
	 * @return string
	 */
	private static function describe_age( int $days ): string {
		if ( $days < 1 ) {
			return __( 'Never', 'revision-retention' );
		}

		/* translators: %s: number of days. */
		return sprintf( _n( '%s day', '%s days', $days, 'revision-retention' ), number_format_i18n( $days ) );
	}
}
