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
	 * Field naming the button that promotes a site's settings to the network.
	 *
	 * @var string
	 */
	private const PROMOTE_FIELD = 'rvrt-promote';

	/**
	 * Action the sweep buttons post to.
	 *
	 * @var string
	 */
	private const RUN_ACTION = 'rvrt_run_sweep';

	/**
	 * Action the network sweep button posts to.
	 *
	 * @var string
	 */
	private const NETWORK_RUN_ACTION = 'rvrt_run_network_sweep';

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
		add_action( 'wp_ajax_' . self::RUN_ACTION, array( $this, 'ajax_sweep_batch' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_menu', array( $this, 'add_page' ) );

		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'add_network_page' ) );
			add_action( 'network_admin_edit_' . self::NETWORK_ACTION, array( $this, 'save_network_settings' ) );
			add_action( 'network_admin_edit_' . self::NETWORK_RUN_ACTION, array( $this, 'sweep_network' ) );
			add_action( 'wp_ajax_' . self::NETWORK_RUN_ACTION, array( $this, 'ajax_network_sweep_batch' ) );
		}
	}

	/**
	 * Add the screen below Settings in a site's admin.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_options_page(
			_x( 'Revision Retention', 'admin menu and page title', 'revision-retention' ),
			_x( 'Revision Retention', 'admin menu and page title', 'revision-retention' ),
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
			_x( 'Revision Retention', 'admin menu and page title', 'revision-retention' ),
			_x( 'Revision Retention', 'admin menu and page title', 'revision-retention' ),
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

		wp_localize_script(
			'rvrt-settings',
			'rvrtSettings',
			array(
				'starting'     => _x( 'Starting…', 'sweep progress', 'revision-retention' ),
				/* translators: 1: number of revisions, 2: number of posts. */
				'previewBusy'  => _x( '%1$s revisions found, %2$s posts checked', 'sweep progress', 'revision-retention' ),
				/* translators: 1: number of revisions, 2: number of posts. */
				'runBusy'      => _x( '%1$s revisions removed, %2$s posts checked', 'sweep progress', 'revision-retention' ),
				/* translators: 1: number of revisions, 2: number of posts. */
				'previewDone'  => _x( '%1$s revisions would be removed from %2$s posts. Nothing has been deleted.', 'sweep result', 'revision-retention' ),
				/* translators: 1: number of revisions, 2: number of posts. */
				'runDone'      => _x( '%1$s revisions removed from %2$s posts.', 'sweep result', 'revision-retention' ),
				'stoppedShort' => _x( 'Stopped. The schedule will finish the rest.', 'sweep result', 'revision-retention' ),
				'stopping'     => _x( 'Stopping after this batch…', 'sweep progress', 'revision-retention' ),
				'failed'       => _x( 'The sweep could not be completed.', 'sweep error', 'revision-retention' ),
				/* translators: %s: number of posts. */
				'more'         => _x( 'And %s more posts.', 'affected posts list', 'revision-retention' ),
				'untitled'     => _x( '(no title)', 'affected posts list', 'revision-retention' ),
			)
		);
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
				esc_html_x( 'Settings', 'plugin action link', 'revision-retention' )
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
		$tabs      = self::tabs( $is_network, $read_only );
		$current   = self::current_tab( $tabs );
		?>
		<div class="wrap rvrt-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php
			$this->render_notice();
			$this->render_intro( $is_network, $read_only );

			if ( ! $is_network ) {
				$this->render_network_link( $read_only );
				$this->render_stats();
			}

			$this->render_tab_nav( $tabs, $current );

			if ( $read_only ) {
				$this->render_panel( 'policy', $current, array( $this, 'render_summary' ) );
				$this->render_sweep_form( $current );

				return;
			}

			$this->render_form( $is_network, $current );

			if ( $is_network ) {
				$this->render_network_sweep_form( $current );
			} else {
				$this->render_sweep_form( $current );
			}
			?>
		</div>
		<?php
	}

	/**
	 * The sections this screen is split into, in order.
	 *
	 * @param bool $is_network Whether the network screen is being rendered.
	 * @param bool $read_only  Whether this site may not override anything.
	 *
	 * @return array<string, string> Labels keyed by tab slug.
	 */
	private static function tabs( bool $is_network, bool $read_only ): array {
		if ( $read_only ) {
			return array(
				'policy' => _x( 'Policy', 'settings tab', 'revision-retention' ),
				'sweep'  => _x( 'Sweep now', 'settings tab', 'revision-retention' ),
			);
		}

		$tabs = array(
			'policy'     => _x( 'Policy', 'settings tab', 'revision-retention' ),
			'post-types' => _x( 'Post types', 'settings tab', 'revision-retention' ),
			'schedule'   => _x( 'Schedule', 'settings tab', 'revision-retention' ),
		);

		if ( $is_network ) {
			$tabs['sites'] = _x( 'Sites', 'settings tab', 'revision-retention' );
		}

		$tabs['sweep']    = _x( 'Sweep now', 'settings tab', 'revision-retention' );
		$tabs['advanced'] = _x( 'Advanced', 'settings tab', 'revision-retention' );

		return $tabs;
	}

	/**
	 * The section being shown.
	 *
	 * @param array<string, string> $tabs Available tabs.
	 *
	 * @return string
	 */
	private static function current_tab( array $tabs ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, and validated against the known tabs below.
		$requested = isset( $_GET['rvrt-tab'] ) ? sanitize_key( wp_unslash( $_GET['rvrt-tab'] ) ) : '';

		return isset( $tabs[ $requested ] ) ? $requested : (string) array_key_first( $tabs );
	}

	/**
	 * Render the row of tabs.
	 *
	 * The tabs are links carrying the section in the URL, so the screen works
	 * without JavaScript and a save comes back to the section it was made in.
	 * The script upgrades them to switch panels in place, which also keeps
	 * unsaved changes in the other sections from being thrown away.
	 *
	 * @param array<string, string> $tabs    Available tabs.
	 * @param string                $current Tab being shown.
	 *
	 * @return void
	 */
	private function render_tab_nav( array $tabs, string $current ): void {
		echo '<nav class="nav-tab-wrapper rvrt-tabs" role="tablist">';

		foreach ( $tabs as $slug => $label ) {
			$active = $slug === $current;

			printf(
				'<a href="%1$s" class="nav-tab%2$s" id="rvrt-tab-%3$s" role="tab" aria-controls="rvrt-panel-%3$s" aria-selected="%4$s" tabindex="%5$s">%6$s</a>',
				esc_url( add_query_arg( 'rvrt-tab', $slug, self::page_url( is_network_admin() ) ) ),
				$active ? ' nav-tab-active' : '',
				esc_attr( $slug ),
				$active ? 'true' : 'false',
				$active ? '0' : '-1',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Wrap one section in its panel.
	 *
	 * @param string   $slug     Tab this section belongs to.
	 * @param string   $current  Tab being shown.
	 * @param callable $contents Renders the section.
	 *
	 * @return void
	 */
	private function render_panel( string $slug, string $current, callable $contents ): void {
		printf(
			'<div class="rvrt-panel" id="rvrt-panel-%1$s" role="tabpanel" aria-labelledby="rvrt-tab-%1$s" data-tab="%1$s"%2$s>',
			esc_attr( $slug ),
			$slug === $current ? '' : ' hidden'
		);

		$contents();

		echo '</div>';
	}

	/**
	 * A line of numbers above the tabs, so the screen opens with the answer to
	 * the question people came with: how much is stored, and what would go.
	 *
	 * @return void
	 */
	private function render_stats(): void {
		$counts = Cleaner::counts();
		$total  = array_sum( $counts );
		$types  = count( array_filter( $counts ) );
		?>
		<ul class="rvrt-stats">
			<li>
				<span class="rvrt-stat-value"><?php echo esc_html( number_format_i18n( $total ) ); ?></span>
				<span class="rvrt-stat-label"><?php echo esc_html_x( 'Revisions stored', 'statistic label', 'revision-retention' ); ?></span>
			</li>
			<li>
				<span class="rvrt-stat-value"><?php echo esc_html( number_format_i18n( $types ) ); ?></span>
				<span class="rvrt-stat-label"><?php echo esc_html_x( 'Post types holding them', 'statistic label', 'revision-retention' ); ?></span>
			</li>
			<li>
				<span class="rvrt-stat-value"><?php echo esc_html( self::describe_keep( (int) Settings::get( 'keep' ) ) ); ?></span>
				<span class="rvrt-stat-label"><?php echo esc_html_x( 'Always kept per post', 'statistic label', 'revision-retention' ); ?></span>
			</li>
			<li>
				<span class="rvrt-stat-value"><?php echo esc_html( Settings::describe_age( (int) Settings::get( 'max_age_days' ) ) ); ?></span>
				<span class="rvrt-stat-label"><?php echo esc_html_x( 'Removed when older than', 'statistic label', 'revision-retention' ); ?></span>
			</li>
		</ul>
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
			$text = _x( 'These are the defaults for every site on this network. Sites may override them only while the switch at the bottom allows it.', 'screen introduction', 'revision-retention' );
		} elseif ( $read_only ) {
			$text = _x( 'The retention policy for this site is set network wide and cannot be changed here.', 'screen introduction', 'revision-retention' );
		} elseif ( is_multisite() ) {
			$text = _x( 'These settings override the network defaults for this site. Leave a field empty to inherit the value shown behind it.', 'screen introduction', 'revision-retention' );
		} else {
			$text = _x( 'Revisions are kept per post: the newest few are always retained, and anything older than the threshold is removed by a scheduled sweep.', 'screen introduction', 'revision-retention' );
		}

		printf( '<p class="rvrt-intro">%s</p>', esc_html( $text ) );
	}

	/**
	 * Point a site on a network at the screen its defaults come from.
	 *
	 * Only a network administrator can open that screen, so anybody else is
	 * told where the policy comes from rather than handed a link they would be
	 * turned away from.
	 *
	 * @param bool $read_only Whether this site may not override anything.
	 *
	 * @return void
	 */
	private function render_network_link( bool $read_only ): void {
		if ( ! is_multisite() ) {
			return;
		}

		if ( ! current_user_can( Settings::capability( true ) ) ) {
			if ( $read_only ) {
				printf(
					'<div class="notice notice-info inline rvrt-network-link"><p>%s</p></div>',
					esc_html_x( 'This policy is set for the whole network. Only a network administrator can change it.', 'network notice', 'revision-retention' )
				);
			}

			return;
		}

		printf(
			'<div class="notice notice-info inline rvrt-network-link"><p><span>%s</span> <a class="button" href="%s">%s</a></p></div>',
			esc_html(
				$read_only
					? _x( 'This site follows the network policy and cannot override it.', 'network notice', 'revision-retention' )
					: _x( 'This site starts from the network defaults and overrides what it sets here.', 'network notice', 'revision-retention' )
			),
			esc_url( self::page_url( true ) ),
			esc_html_x( 'Network settings', 'button label', 'revision-retention' )
		);
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

		$booked    = isset( $_GET['rvrt-booked'] ) ? absint( wp_unslash( $_GET['rvrt-booked'] ) ) : 0;
		$skipped   = isset( $_GET['rvrt-skipped'] ) ? absint( wp_unslash( $_GET['rvrt-skipped'] ) ) : 0;
		$revisions = isset( $_GET['rvrt-revisions'] ) ? absint( wp_unslash( $_GET['rvrt-revisions'] ) ) : 0;
		$posts     = isset( $_GET['rvrt-posts'] ) ? absint( wp_unslash( $_GET['rvrt-posts'] ) ) : 0;
		$finished  = isset( $_GET['rvrt-finished'] ) && '1' === $_GET['rvrt-finished'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$message = match ( $notice ) {
			'saved' => _x( 'Settings saved.', 'admin notice', 'revision-retention' ),
			'promoted' => _x( 'Saved, and these settings are now the defaults for every site on the network. This site has no settings of its own any more and follows those defaults, which is what it was already doing.', 'admin notice', 'revision-retention' ),
			'booked' => sprintf(
				/* translators: %s: number of sites. */
				_nx( 'A sweep is booked on %s site.', 'A sweep is booked on %s sites.', $booked, 'admin notice', 'revision-retention' ),
				number_format_i18n( $booked )
			),
			'preview' => sprintf(
				/* translators: 1: number of revisions, 2: number of posts. */
				_nx(
					'%1$s revision in %2$s post would be removed. Nothing has been deleted.',
					'%1$s revisions across %2$s posts would be removed. Nothing has been deleted.',
					$revisions, 'admin notice', 'revision-retention'
				),
				number_format_i18n( $revisions ),
				number_format_i18n( $posts )
			),
			'swept' => sprintf(
				/* translators: 1: number of revisions, 2: number of posts. */
				_nx(
					'Removed %1$s revision from %2$s post.',
					'Removed %1$s revisions from %2$s posts.',
					$revisions, 'admin notice', 'revision-retention'
				),
				number_format_i18n( $revisions ),
				number_format_i18n( $posts )
			),
			default => '',
		};

		if ( '' === $message ) {
			return;
		}

		if ( 'booked' === $notice && $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %s: number of sites. */
				_nx( '%s site has the scheduled sweep switched off and was left alone.', '%s sites have the scheduled sweep switched off and were left alone.', $skipped, 'admin notice', 'revision-retention' ),
				number_format_i18n( $skipped )
			);
		}

		if ( 'swept' === $notice && ! $finished ) {
			$message .= ' ' . _x( 'There is more to do; the rest continues in the background.', 'admin notice', 'revision-retention' );
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
					<th scope="col"><?php echo esc_html_x( 'Post type', 'column heading', 'revision-retention' ); ?></th>
					<th scope="col"><?php echo esc_html_x( 'Revisions', 'column heading', 'revision-retention' ); ?></th>
					<th scope="col"><?php echo esc_html_x( 'Keep', 'column heading', 'revision-retention' ); ?></th>
					<th scope="col"><?php echo esc_html_x( 'Remove older than', 'field label', 'revision-retention' ); ?></th>
					<th scope="col"><?php echo esc_html_x( 'Stored now', 'column heading', 'revision-retention' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( Post_Types::eligible() as $post_type => $label ) : ?>
				<?php $rule = Policy::for_post_type( $post_type ); ?>
				<tr>
					<th scope="row"><?php echo esc_html( $label ); ?></th>
					<td><?php echo esc_html( Post_Types::supports_revisions( $post_type ) ? _x( 'On', 'revision support state', 'revision-retention' ) : _x( 'Off', 'revision support state', 'revision-retention' ) ); ?></td>
					<td><?php echo esc_html( self::describe_keep( $rule->keep ) ); ?></td>
					<td><?php echo esc_html( Settings::describe_age( $rule->max_age_days ) ); ?></td>
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
	 * Every section sits in the one form, whichever of them is on screen, so a
	 * single Save covers the whole policy rather than only the tab in view.
	 *
	 * @param bool   $is_network Whether the network screen is being rendered.
	 * @param string $current    Tab being shown.
	 *
	 * @return void
	 */
	private function render_form( bool $is_network, string $current ): void {
		$inheritable = ! $is_network && is_multisite();
		$stored      = $is_network ? Settings::network() : ( $inheritable ? Settings::site() : Settings::resolved() );
		$inherited   = Settings::network();
		$action      = $is_network
			? network_admin_url( 'edit.php?action=' . self::NETWORK_ACTION )
			: admin_url( 'admin-post.php' );
		?>
		<form method="post" action="<?php echo esc_url( $action ); ?>" class="rvrt-form">
			<?php
			wp_nonce_field( $is_network ? self::NETWORK_ACTION : self::SAVE_ACTION );

			if ( ! $is_network ) {
				printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::SAVE_ACTION ) );
			}

			// Every section is submitted together, whichever one is on screen,
			// so one Save covers the whole policy.
			printf( '<input type="hidden" name="rvrt-tab" value="%s" class="rvrt-current-tab" />', esc_attr( $current ) );

			$this->render_panel(
				'policy',
				$current,
				function () use ( $stored, $inherited, $inheritable ) {
					$this->render_policy_section( $stored, $inherited, $inheritable );
				}
			);

			$this->render_panel(
				'post-types',
				$current,
				function () use ( $stored, $is_network ) {
					?>
					<p class="description rvrt-panel-intro">
						<?php echo esc_html_x( 'Leave a field empty to use the policy above. Switching revisions on for a post type that does not store them applies from the next time such a post is saved.', 'field description', 'revision-retention' ); ?>
					</p>
					<?php
					$this->render_post_types_table( $stored, ! $is_network );
				}
			);

			$this->render_panel(
				'schedule',
				$current,
				function () use ( $stored, $inherited, $inheritable ) {
					$this->render_schedule_section( $stored, $inherited, $inheritable );
				}
			);

		if ( $is_network ) {
			$this->render_panel(
				'sites',
				$current,
				function () use ( $stored ) {
					$this->render_sites_section( $stored );
				}
			);
		}

			$this->render_panel(
				'advanced',
				$current,
				function () use ( $stored, $inherited, $inheritable ) {
					$this->render_advanced_section( $stored, $inherited, $inheritable );
				}
			);

			// The Save button belongs to this form, which the sweep section is not
			// part of, so it steps aside there.
			printf( '<div class="rvrt-submit"%s><p class="submit">', 'sweep' === $current ? ' hidden' : '' );

			submit_button( _x( 'Save changes', 'button label', 'revision-retention' ), 'primary', 'submit', false );

		if ( $this->can_promote( $is_network ) ) {
			echo ' ';

			submit_button(
				_x( 'Save as network default', 'button label', 'revision-retention' ),
				'secondary',
				self::PROMOTE_FIELD,
				false,
				array(
					'data-confirm' => _x( 'This makes the settings on this screen the defaults for every site on the network. Sites with settings of their own keep them. Continue?', 'confirmation', 'revision-retention' ),
				)
			);
		}

			echo '</p></div>';
		?>
		</form>
		<?php
	}

	/**
	 * Whether this screen may hand its settings up to the network.
	 *
	 * Only from a site, only on a network, and only for somebody who could
	 * have edited those defaults directly: a site administrator changing what
	 * every other site starts from would be a surprise nobody asked for.
	 *
	 * @param bool $is_network Whether the network screen is being rendered.
	 *
	 * @return bool
	 */
	private function can_promote( bool $is_network ): bool {
		return ! $is_network && is_multisite() && current_user_can( Settings::capability( true ) );
	}

	/**
	 * The two numbers the whole plugin turns on.
	 *
	 * @param array<string, mixed> $stored      Values as stored for this screen.
	 * @param array<string, mixed> $inherited   Network values to fall back to.
	 * @param bool                 $inheritable Whether an empty field inherits.
	 *
	 * @return void
	 */
	private function render_policy_section( array $stored, array $inherited, bool $inheritable ): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="rvrt-keep"><?php echo esc_html_x( 'Always keep', 'field label', 'revision-retention' ); ?></label>
				</th>
				<td>
					<?php $this->render_number( 'keep', 'rvrt-keep', $stored, $inherited, $inheritable, -1 ); ?>
					<p class="description">
						<?php echo esc_html_x( 'The newest revisions of every post, which are never removed however old they get. Use 0 to keep none, or -1 to keep every revision and never purge anything.', 'field description', 'revision-retention' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="rvrt-max-age-days"><?php echo esc_html_x( 'Remove older than', 'field label', 'revision-retention' ); ?></label>
				</th>
				<td>
					<?php
					self::render_age_select(
						array(
							'name'        => 'rvrt_settings[max_age_days]',
							'id'          => 'rvrt-max-age-days',
							'value'       => isset( $stored['max_age_days'] ) ? (int) $stored['max_age_days'] : null,
							'empty_label' => $inheritable
								? sprintf(
									/* translators: %s: the age inherited from the network. */
									_x( 'Inherit (%s)', 'inherited setting option', 'revision-retention' ),
									Settings::describe_age( (int) ( $inherited['max_age_days'] ?? 0 ) )
								)
								: '',
						)
					);
					?>
					<p class="description">
						<?php echo esc_html_x( 'Revisions past this age are removed by the sweep, except for the newest ones above. Choose Never to switch the age threshold off and only cap the count.', 'field description', 'revision-retention' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * When the sweep runs, and how much it does at a time.
	 *
	 * @param array<string, mixed> $stored      Values as stored for this screen.
	 * @param array<string, mixed> $inherited   Network values to fall back to.
	 * @param bool                 $inheritable Whether an empty field inherits.
	 *
	 * @return void
	 */
	private function render_schedule_section( array $stored, array $inherited, bool $inheritable ): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html_x( 'Run automatically', 'field label', 'revision-retention' ); ?></th>
				<td>
					<?php $this->render_bool( 'cron_enabled', 'rvrt-cron-enabled', _x( 'Sweep old revisions in the background', 'checkbox label', 'revision-retention' ), $stored, $inherited, $inheritable ); ?>
					<p class="description"><?php echo esc_html_x( 'Each run works through the site in batches and books the next batch itself, so a large site is cleaned up over several runs instead of one long one.', 'field description', 'revision-retention' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="rvrt-cron-interval"><?php echo esc_html_x( 'How often', 'field label', 'revision-retention' ); ?></label>
				</th>
				<td><?php $this->render_interval( $stored, $inherited, $inheritable ); ?></td>
			</tr>
			<tr>
				<th scope="row">
					<label for="rvrt-batch-size"><?php echo esc_html_x( 'Posts per batch', 'field label', 'revision-retention' ); ?></label>
				</th>
				<td>
					<?php $this->render_number( 'batch_size', 'rvrt-batch-size', $stored, $inherited, $inheritable, 10 ); ?>
					<p class="description"><?php echo esc_html_x( 'How many posts one batch looks at. Lower this if a sweep is too heavy for the server, raise it to get through a large site sooner.', 'field description', 'revision-retention' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="rvrt-max-deletions"><?php echo esc_html_x( 'Revisions per batch', 'field label', 'revision-retention' ); ?></label>
				</th>
				<td>
					<?php $this->render_number( 'max_deletions', 'rvrt-max-deletions', $stored, $inherited, $inheritable, 0 ); ?>
					<p class="description">
						<?php echo esc_html_x( 'The most a single batch may delete. It stops once it gets there and carries on next time, which keeps one post with thousands of revisions from turning a batch into a long job. The post being worked on is always finished first, so the count can overshoot a little. Use 0 for no cap.', 'field description', 'revision-retention' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Whether the sites on a network may go their own way.
	 *
	 * @param array<string, mixed> $stored Values as stored for this screen.
	 *
	 * @return void
	 */
	private function render_sites_section( array $stored ): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html_x( 'Overrides', 'field label', 'revision-retention' ); ?></th>
				<td>
					<label for="rvrt-allow-site-override">
						<input type="checkbox" id="rvrt-allow-site-override" name="rvrt_settings[allow_site_override]" value="1" <?php checked( ! empty( $stored['allow_site_override'] ) ); ?> />
						<?php echo esc_html_x( 'Let each site override these defaults', 'checkbox label', 'revision-retention' ); ?>
					</label>
					<p class="description"><?php echo esc_html_x( 'With this off, every site follows the policy above and its own screen only reports it. Overrides a site saved earlier are kept and take effect again when this is switched back on.', 'field description', 'revision-retention' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * What happens when the plugin is removed.
	 *
	 * @param array<string, mixed> $stored      Values as stored for this screen.
	 * @param array<string, mixed> $inherited   Network values to fall back to.
	 * @param bool                 $inheritable Whether an empty field inherits.
	 *
	 * @return void
	 */
	private function render_advanced_section( array $stored, array $inherited, bool $inheritable ): void {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html_x( 'On removal', 'field label', 'revision-retention' ); ?></th>
				<td>
					<?php $this->render_bool( 'remove_data_on_uninstall', 'rvrt-remove-data', _x( 'Remove all data of this plugin when it is uninstalled', 'checkbox label', 'revision-retention' ), $stored, $inherited, $inheritable ); ?>
					<p class="description"><?php echo esc_html_x( 'Deletes these settings. Revisions already removed cannot be brought back either way.', 'field description', 'revision-retention' ); ?></p>
				</td>
			</tr>
		</table>
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
		$rows      = self::order_post_types( $counts );
		$quiet     = 0;

		foreach ( $rows as $post_type => $label ) {
			if ( self::is_quiet( $post_type, $counts, $enabled, $overrides ) ) {
				++$quiet;
			}
		}
		?>
		<table class="widefat striped rvrt-post-types">
			<thead>
				<tr>
					<th scope="col" class="rvrt-col-type"><?php echo esc_html_x( 'Post type', 'column heading', 'revision-retention' ); ?></th>
					<?php if ( $show_counts ) : ?>
						<th scope="col" class="rvrt-col-number"><?php echo esc_html_x( 'Stored', 'column heading', 'revision-retention' ); ?></th>
					<?php endif; ?>
					<th scope="col" class="rvrt-col-support"><?php echo esc_html_x( 'Revisions', 'column heading', 'revision-retention' ); ?></th>
					<th scope="col" class="rvrt-col-field"><?php echo esc_html_x( 'Always keep', 'field label', 'revision-retention' ); ?></th>
					<th scope="col" class="rvrt-col-field"><?php echo esc_html_x( 'Remove older than', 'field label', 'revision-retention' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $rows as $post_type => $label ) :
				$native   = Post_Types::supports_revisions( $post_type ) && ! in_array( $post_type, $enabled, true );
				$rule     = Policy::for_post_type( $post_type );
				$override = isset( $overrides[ $post_type ] ) && is_array( $overrides[ $post_type ] ) ? $overrides[ $post_type ] : array();
				$stored_n = $counts[ $post_type ] ?? 0;
				?>
				<tr<?php echo self::is_quiet( $post_type, $counts, $enabled, $overrides ) ? ' class="rvrt-quiet" hidden' : ''; ?>>
					<th scope="row" class="rvrt-col-type">
						<span class="rvrt-label"><?php echo esc_html( $label ); ?></span>
						<code><?php echo esc_html( $post_type ); ?></code>
					</th>
					<?php if ( $show_counts ) : ?>
						<td class="rvrt-col-number">
							<?php if ( $stored_n > 0 ) : ?>
								<strong><?php echo esc_html( number_format_i18n( $stored_n ) ); ?></strong>
							<?php else : ?>
								<span class="rvrt-none" aria-hidden="true">&mdash;</span>
								<span class="screen-reader-text"><?php echo esc_html_x( 'None', 'stored revision count', 'revision-retention' ); ?></span>
							<?php endif; ?>
						</td>
					<?php endif; ?>
					<td class="rvrt-col-support">
						<?php if ( $native ) : ?>
							<span class="rvrt-native"><?php echo esc_html_x( 'On', 'revision support state', 'revision-retention' ); ?></span>
						<?php else : ?>
							<label>
								<input type="checkbox" name="rvrt_settings[enable_revisions][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, $enabled, true ) ); ?> />
								<span class="screen-reader-text">
									<?php
									printf(
										/* translators: %s: post type label. */
										esc_html_x( 'Store revisions for %s', 'accessibility label', 'revision-retention' ),
										esc_html( $label )
									);
									?>
								</span>
								<span aria-hidden="true"><?php echo esc_html_x( 'Enable', 'checkbox label', 'revision-retention' ); ?></span>
							</label>
						<?php endif; ?>
					</td>
					<td class="rvrt-col-field">
						<input
							type="number"
							min="-1"
							step="1"
							class="small-text"
							name="rvrt_settings[post_types][<?php echo esc_attr( $post_type ); ?>][keep]"
							value="<?php echo esc_attr( isset( $override['keep'] ) ? (string) $override['keep'] : '' ); ?>"
							placeholder="<?php echo esc_attr( (string) $rule->keep ); ?>"
							aria-label="<?php echo esc_attr( sprintf( /* translators: %s: post type label. */ _x( 'Revisions to always keep for %s', 'accessibility label', 'revision-retention' ), $label ) ); ?>"
						/>
					</td>
					<td class="rvrt-col-field">
						<?php
						self::render_age_select(
							array(
								'name'        => 'rvrt_settings[post_types][' . $post_type . '][max_age_days]',
								'value'       => isset( $override['max_age_days'] ) ? (int) $override['max_age_days'] : null,
								'empty_label' => sprintf(
									/* translators: %s: the age set for the whole site. */
									_x( 'Policy above (%s)', 'inherited setting option', 'revision-retention' ),
									Settings::describe_age( $rule->max_age_days )
								),
								'aria_label'  => sprintf(
									/* translators: %s: post type label. */
									_x( 'Age after which revisions of %s are removed', 'accessibility label', 'revision-retention' ),
									$label
								),
							)
						);
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $quiet > 0 ) : ?>
			<p class="rvrt-toggle-wrap">
				<button type="button" class="button-link rvrt-toggle" data-shown="0"
					data-show="<?php echo esc_attr( sprintf( /* translators: %s: number of post types. */ _nx( 'Show %s post type without revisions', 'Show %s post types without revisions', $quiet, 'button label', 'revision-retention' ), number_format_i18n( $quiet ) ) ); ?>"
					data-hide="<?php echo esc_attr_x( 'Hide the post types without revisions', 'button label', 'revision-retention' ); ?>">
					<?php
					printf(
						/* translators: %s: number of post types. */
						esc_html( _nx( 'Show %s post type without revisions', 'Show %s post types without revisions', $quiet, 'button label', 'revision-retention' ) ),
						esc_html( number_format_i18n( $quiet ) )
					);
					?>
				</button>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Eligible post types, the ones worth looking at first.
	 *
	 * Most sites register a long tail of post types that hold nothing. Sorting
	 * on what is actually stored puts the rows an administrator came here for
	 * at the top instead of wherever WordPress happened to register them.
	 *
	 * @param array<string, int> $counts Stored revisions per post type.
	 *
	 * @return array<string, string> Labels keyed by post type slug.
	 */
	private static function order_post_types( array $counts ): array {
		$rows = Post_Types::eligible();

		uksort(
			$rows,
			static function ( string $a, string $b ) use ( $counts, $rows ): int {
				$difference = ( $counts[ $b ] ?? 0 ) <=> ( $counts[ $a ] ?? 0 );

				return 0 !== $difference ? $difference : strcasecmp( $rows[ $a ], $rows[ $b ] );
			}
		);

		return $rows;
	}

	/**
	 * Whether a row can start out folded away.
	 *
	 * A post type with nothing stored, no revisions being kept for it and no
	 * rule of its own is noise on a screen about cleaning revisions up. It is
	 * still rendered, just behind a toggle.
	 *
	 * @param string               $post_type Post type slug.
	 * @param array<string, int>   $counts    Stored revisions per post type.
	 * @param array<int, string>   $enabled   Post types revisions were switched on for.
	 * @param array<string, mixed> $overrides Per post type rules.
	 *
	 * @return bool
	 */
	private static function is_quiet( string $post_type, array $counts, array $enabled, array $overrides ): bool {
		if ( ! empty( $counts[ $post_type ] ) ) {
			return false;
		}

		if ( in_array( $post_type, $enabled, true ) || isset( $overrides[ $post_type ] ) ) {
			return false;
		}

		return ! Post_Types::supports_revisions( $post_type );
	}

	/**
	 * Render the preview and run buttons, plus what the last sweep did.
	 *
	 * @param string $current Tab being shown.
	 *
	 * @return void
	 */
	private function render_sweep_form( string $current ): void {
		$state = Scheduler::state();
		$next  = wp_next_scheduled( Scheduler::HOOK );

		$this->render_panel(
			'sweep',
			$current,
			function () use ( $state, $next ) {
				?>
		<form
			method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			class="rvrt-sweep"
			data-scope="site"
			data-request="<?php echo esc_attr( self::RUN_ACTION ); ?>"
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( self::RUN_ACTION ) ); ?>"
		>
				<?php wp_nonce_field( self::RUN_ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::RUN_ACTION ); ?>" />

			<p class="rvrt-sweep-status">
				<?php
				if ( $state['cursor'] > 0 ) {
					echo esc_html_x( 'A sweep is part way through this site and continues from where it stopped.', 'sweep status', 'revision-retention' );
				} elseif ( $state['finished'] > 0 ) {
					printf(
						/* translators: 1: how long ago the last sweep finished, 2: number of revisions it removed. */
						esc_html_x( 'The last sweep finished %1$s ago and removed %2$s revisions.', 'sweep status', 'revision-retention' ),
						esc_html( human_time_diff( $state['finished'] ) ),
						esc_html( number_format_i18n( $state['removed'] ) )
					);
				} else {
					echo esc_html_x( 'No sweep has finished on this site yet.', 'sweep status', 'revision-retention' );
				}

				if ( $next ) {
					echo ' ';
					printf(
						/* translators: %s: time until the next scheduled sweep. */
						esc_html_x( 'The next one is due in %s.', 'sweep status', 'revision-retention' ),
						esc_html( human_time_diff( (int) $next ) )
					);
				}
				?>
			</p>

			<div class="rvrt-sweep-buttons">
				<button type="submit" name="mode" value="preview" class="button">
					<?php echo esc_html_x( 'Preview', 'button label', 'revision-retention' ); ?>
				</button>
				<?php if ( Settings::may_sweep() ) : ?>
					<button type="submit" name="mode" value="run" class="button button-primary">
						<?php echo esc_html_x( 'Run now', 'button label', 'revision-retention' ); ?>
					</button>
				<?php endif; ?>
				<button type="button" class="button rvrt-stop" hidden>
					<?php echo esc_html_x( 'Stop', 'button label', 'revision-retention' ); ?>
				</button>
			</div>

			<div class="rvrt-progress" hidden>
				<div
					class="rvrt-progress-bar"
					role="progressbar"
					aria-valuemin="0"
					aria-valuemax="100"
					aria-valuenow="0"
					aria-label="<?php echo esc_attr_x( 'Sweep progress', 'accessibility label', 'revision-retention' ); ?>"
				><span class="rvrt-progress-fill"></span></div>
				<p class="rvrt-progress-text" aria-live="polite"></p>
			</div>

			<div class="rvrt-sweep-result notice inline" role="status" hidden><p></p></div>

			<div class="rvrt-affected" hidden>
				<div class="rvrt-affected-scroll">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php echo esc_html_x( 'Post', 'column heading', 'revision-retention' ); ?></th>
								<th scope="col"><?php echo esc_html_x( 'Type', 'column heading', 'revision-retention' ); ?></th>
								<th scope="col" class="rvrt-col-number"><?php echo esc_html_x( 'Revisions', 'column heading', 'revision-retention' ); ?></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
				<p class="rvrt-affected-more description" hidden></p>
			</div>

			<p class="description">
				<?php echo esc_html_x( 'Preview goes through the whole site and reports what the policy would remove, without deleting anything. Run now does the same and deletes as it goes, batch after batch, until the site is clean. Either one can be stopped, and the schedule picks up whatever is left.', 'field description', 'revision-retention' ); ?>
				<?php
				if ( ! Settings::may_sweep() ) {
					echo ' ';
					echo esc_html_x( 'This network keeps the retention policy to itself, so only a network administrator can sweep this site. The scheduled sweep still runs if the network has it switched on.', 'field description', 'revision-retention' );
				}
				?>
			</p>
		</form>
				<?php
			}
		);
	}

	/**
	 * Run one batch and report where it got to.
	 *
	 * The plain form posts one batch and reloads, which is all the screen can
	 * do without JavaScript. With it, the script calls this for batch after
	 * batch until the site is clean, so a preview covers the whole site rather
	 * than the first two hundred posts of it, and progress is visible while it
	 * happens.
	 *
	 * @return void
	 */
	public function ajax_sweep_batch(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_send_json_error( array( 'message' => _x( 'You are not allowed to do this.', 'permission error', 'revision-retention' ) ), 403 );
		}

		check_ajax_referer( self::RUN_ACTION );

		$dry_run = ! isset( $_POST['mode'] ) || 'run' !== sanitize_key( wp_unslash( $_POST['mode'] ) );

		if ( ! $dry_run && ! Settings::may_sweep() ) {
			wp_send_json_error(
				array( 'message' => _x( 'The retention policy for this site is set network wide, so only a network administrator can sweep it.', 'permission error', 'revision-retention' ) ),
				403
			);
		}

		$cursor = isset( $_POST['cursor'] ) ? absint( wp_unslash( $_POST['cursor'] ) ) : 0;

		// A real run resumes whatever the schedule was part way through; a dry
		// run is asked for from the beginning and keeps its own place.
		if ( ! $dry_run && 0 === $cursor ) {
			$cursor = Scheduler::state()['cursor'];
		}

		$result = ( new Cleaner() )->sweep(
			(int) Settings::get( 'batch_size' ),
			$dry_run,
			$cursor,
			array(),
			(int) Settings::get( 'max_deletions' )
		);

		if ( ! $dry_run ) {
			$this->remember_sweep( $result );
		}

		$last = Cleaner::last_parent_id();

		wp_send_json_success(
			array(
				'cursor'    => $result->cursor,
				'posts'     => $result->posts,
				'revisions' => $result->revisions,
				'finished'  => $result->finished,
				'items'     => $result->items,
				'progress'  => $result->finished || $last < 1
					? 100
					: min( 99, (int) floor( ( $result->cursor / $last ) * 100 ) ),
			)
		);
	}

	/**
	 * Preview one batch on one site of the network, then say where to go next.
	 *
	 * A network preview walks the sites in turn, a batch at a time, so the
	 * browser drives it and no single request has to carry a whole network.
	 * Nothing is ever deleted here: the network screen previews, and the
	 * deleting is booked per site where it belongs.
	 *
	 * @return void
	 */
	public function ajax_network_sweep_batch(): void {
		if ( ! is_multisite() || ! current_user_can( Settings::capability( true ) ) ) {
			wp_send_json_error( array( 'message' => _x( 'You are not allowed to do this.', 'permission error', 'revision-retention' ) ), 403 );
		}

		check_ajax_referer( self::NETWORK_RUN_ACTION );

		$sites = array_map( 'intval', (array) get_sites( array( 'fields' => 'ids' ) ) );

		if ( array() === $sites ) {
			wp_send_json_success(
				array(
					'site'      => 0,
					'cursor'    => 0,
					'posts'     => 0,
					'revisions' => 0,
					'items'     => array(),
					'finished'  => true,
					'progress'  => 100,
				)
			);
		}

		$requested = isset( $_POST['site'] ) ? absint( wp_unslash( $_POST['site'] ) ) : 0;
		$cursor    = isset( $_POST['cursor'] ) ? absint( wp_unslash( $_POST['cursor'] ) ) : 0;
		$index     = 0 === $requested ? 0 : array_search( $requested, $sites, true );

		if ( false === $index ) {
			$index  = 0;
			$cursor = 0;
		}

		$site_id = $sites[ $index ];

		switch_to_blog( $site_id );

		// Another site means other post types, other plugins and another policy.
		Policy::flush();
		Post_Types::flush();

		$result = ( new Cleaner() )->sweep(
			(int) Settings::get( 'batch_size' ),
			true,
			$cursor,
			array(),
			(int) Settings::get( 'max_deletions' )
		);

		$name  = get_bloginfo( 'name' );
		$items = array_map(
			static function ( array $item ) use ( $name ): array {
				$item['site'] = $name;

				return $item;
			},
			$result->items
		);

		restore_current_blog();
		Policy::flush();
		Post_Types::flush();

		// A finished site hands over to the next one; the last one ends the run.
		$next_index = $result->finished ? $index + 1 : $index;
		$finished   = $next_index >= count( $sites );

		wp_send_json_success(
			array(
				'site'      => $finished ? 0 : $sites[ $next_index ],
				'cursor'    => $result->finished ? 0 : $result->cursor,
				'posts'     => $result->posts,
				'revisions' => $result->revisions,
				'items'     => $items,
				'finished'  => $finished,
				'progress'  => $finished ? 100 : min( 99, (int) floor( ( $next_index / count( $sites ) ) * 100 ) ),
			)
		);
	}

	/**
	 * Store where a real run got to, so the schedule carries on from there.
	 *
	 * @param Sweep_Result $result What the batch did.
	 *
	 * @return void
	 */
	private function remember_sweep( Sweep_Result $result ): void {
		// Hand the rest back to the schedule rather than pushing on here, so a
		// big site does not hold a request open.
		Scheduler::reschedule( $result->finished ? 0 : MINUTE_IN_SECONDS );

		if ( $result->finished ) {
			Scheduler::reset_cursor();

			return;
		}

		$state           = Scheduler::state();
		$state['cursor'] = $result->cursor;

		update_option( Scheduler::CURSOR_OPTION, $state );
	}

	/**
	 * Render the network sweep, which books a run on each site.
	 *
	 * A network screen has no database of its own to clean. WP-Cron is per
	 * site, and so is a sweep: this books one on every site instead of doing
	 * the work here, which keeps a network of any size out of a single request
	 * and lets each site clean itself in batches the way it always does.
	 *
	 * @param string $current Tab being shown.
	 *
	 * @return void
	 */
	private function render_network_sweep_form( string $current ): void {
		$sites = (int) get_sites( array( 'count' => true ) );

		$this->render_panel(
			'sweep',
			$current,
			function () use ( $sites ) {
				?>
				<form
					method="post"
					action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::NETWORK_RUN_ACTION ) ); ?>"
					class="rvrt-sweep"
					data-scope="network"
					data-request="<?php echo esc_attr( self::NETWORK_RUN_ACTION ); ?>"
					data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::NETWORK_RUN_ACTION ) ); ?>"
				>
					<?php wp_nonce_field( self::NETWORK_RUN_ACTION ); ?>

					<p class="rvrt-sweep-status">
						<?php
						printf(
							/* translators: %s: number of sites on the network. */
							esc_html( _nx( 'This network has %s site.', 'This network has %s sites.', $sites, 'sweep status', 'revision-retention' ) ),
							esc_html( number_format_i18n( $sites ) )
						);
						?>
						<?php echo esc_html_x( 'Revisions live in each site\'s own database, so a sweep belongs to the site rather than to the network.', 'sweep status', 'revision-retention' ); ?>
					</p>

					<div class="rvrt-sweep-buttons">
						<button type="button" name="mode" value="preview" class="button">
							<?php echo esc_html_x( 'Preview every site', 'button label', 'revision-retention' ); ?>
						</button>
						<button type="submit" class="button button-primary">
							<?php echo esc_html_x( 'Sweep every site now', 'button label', 'revision-retention' ); ?>
						</button>
						<button type="button" class="button rvrt-stop" hidden>
							<?php echo esc_html_x( 'Stop', 'button label', 'revision-retention' ); ?>
						</button>
					</div>

					<div class="rvrt-progress" hidden>
						<div
							class="rvrt-progress-bar"
							role="progressbar"
							aria-valuemin="0"
							aria-valuemax="100"
							aria-valuenow="0"
							aria-label="<?php echo esc_attr_x( 'Sweep progress', 'accessibility label', 'revision-retention' ); ?>"
						><span class="rvrt-progress-fill"></span></div>
						<p class="rvrt-progress-text" aria-live="polite"></p>
					</div>

					<div class="rvrt-sweep-result notice inline" role="status" hidden><p></p></div>

					<div class="rvrt-affected" hidden>
						<div class="rvrt-affected-scroll">
							<table class="widefat striped">
								<thead>
									<tr>
										<th scope="col"><?php echo esc_html_x( 'Post', 'column heading', 'revision-retention' ); ?></th>
										<th scope="col"><?php echo esc_html_x( 'Site', 'column heading', 'revision-retention' ); ?></th>
										<th scope="col"><?php echo esc_html_x( 'Type', 'column heading', 'revision-retention' ); ?></th>
										<th scope="col" class="rvrt-col-number"><?php echo esc_html_x( 'Revisions', 'column heading', 'revision-retention' ); ?></th>
									</tr>
								</thead>
								<tbody></tbody>
							</table>
						</div>
						<p class="rvrt-affected-more description" hidden></p>
					</div>

					<p class="description">
						<?php echo esc_html_x( 'Preview every site walks the whole network and reports what the policy would remove, without deleting anything. Books a sweep on every site that has the scheduled sweep switched on, to start within the next few minutes. The work itself happens on each site, in batches, exactly as a scheduled run would. Sites that have switched the sweep off are left alone. For a preview of what one site would lose, use that site\'s own screen.', 'field description', 'revision-retention' ); ?>
					</p>
				</form>
				<?php
			}
		);
	}

	/**
	 * Book a sweep on every site that wants one.
	 *
	 * @return void
	 */
	public function sweep_network(): void {
		if ( ! current_user_can( Settings::capability( true ) ) ) {
			wp_die( esc_html_x( 'You are not allowed to do this.', 'permission error', 'revision-retention' ) );
		}

		check_admin_referer( self::NETWORK_RUN_ACTION );

		$booked  = 0;
		$skipped = 0;
		$offset  = 0;
		$stagger = 0;

		do {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => $offset,
				)
			);
			$found = count( $sites );

			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );

				// A site that switched the sweep off is not overruled from here;
				// Scheduler::reschedule() would refuse anyway, so it is asked
				// first and counted honestly.
				if ( empty( Settings::get( 'cron_enabled' ) ) ) {
					++$skipped;
				} else {
					// Spread the runs out so a network with a system cron does
					// not start every site in the same minute.
					$stagger += 10;

					Scheduler::reschedule( min( $stagger, 15 * MINUTE_IN_SECONDS ) );
					++$booked;
				}

				restore_current_blog();
				Policy::flush();
			}

			$offset += 100;
		} while ( 100 === $found );

		$this->redirect(
			array(
				'rvrt-notice'  => 'booked',
				'rvrt-booked'  => (string) $booked,
				'rvrt-skipped' => (string) $skipped,
				'rvrt-tab'     => 'sweep',
			),
			true
		);
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
	 * Render the age threshold as a list of the durations people reach for.
	 *
	 * A value a site already has that is not one of those durations is added to
	 * the list rather than dropped, so opening this screen and saving it cannot
	 * quietly change a policy that was set from a filter or from WP-CLI.
	 *
	 * @param array{name: string, value: int|null, empty_label: string, id?: string, aria_label?: string} $args Field arguments.
	 *
	 * @return void
	 */
	private static function render_age_select( array $args ): void {
		$value   = $args['value'];
		$choices = Settings::age_choices();

		if ( null !== $value && ! isset( $choices[ $value ] ) ) {
			$choices[ $value ] = Settings::describe_age( $value );
			ksort( $choices );
		}

		printf(
			'<select name="%s"%s%s>',
			esc_attr( $args['name'] ),
			isset( $args['id'] ) ? sprintf( ' id="%s"', esc_attr( $args['id'] ) ) : '',
			isset( $args['aria_label'] ) ? sprintf( ' aria-label="%s"', esc_attr( $args['aria_label'] ) ) : ''
		);

		if ( '' !== $args['empty_label'] ) {
			printf(
				'<option value=""%s>%s</option>',
				selected( null, $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed, safe attribute string.
				esc_html( $args['empty_label'] )
			);
		}

		foreach ( $choices as $days => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $days ),
				selected( $days, $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a fixed, safe attribute string.
				esc_html( $label )
			);
		}

		echo '</select>';
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
		<label for="<?php echo esc_attr( $id ); ?>" class="rvrt-choice">
			<select id="<?php echo esc_attr( $id ); ?>" name="rvrt_settings[<?php echo esc_attr( $key ); ?>]">
				<option value="" <?php selected( '', $value ); ?>>
					<?php
					printf(
						/* translators: %s: the value inherited from the network. */
						esc_html_x( 'Inherit (%s)', 'inherited setting option', 'revision-retention' ),
						empty( $inherited[ $key ] ) ? esc_html_x( 'off', 'inherited boolean value', 'revision-retention' ) : esc_html_x( 'on', 'inherited boolean value', 'revision-retention' )
					);
					?>
				</option>
				<option value="1" <?php selected( '1', $value ); ?>><?php echo esc_html_x( 'On', 'revision support state', 'revision-retention' ); ?></option>
				<option value="0" <?php selected( '0', $value ); ?>><?php echo esc_html_x( 'Off', 'revision support state', 'revision-retention' ); ?></option>
			</select>
			<span class="rvrt-choice-text"><?php echo esc_html( $text ); ?></span>
		</label>
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
						esc_html_x( 'Inherit (%s)', 'inherited setting option', 'revision-retention' ),
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
			wp_die( esc_html_x( 'You are not allowed to change these settings.', 'permission error', 'revision-retention' ) );
		}

		check_admin_referer( self::SAVE_ACTION );

		if ( is_multisite() && ! Settings::allows_site_override() ) {
			wp_die( esc_html_x( 'The retention policy for this site is set network wide.', 'permission error', 'revision-retention' ) );
		}

		Settings::update_site( Settings::sanitize( self::posted_settings() ) );
		Policy::flush();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		$promote = isset( $_POST[ self::PROMOTE_FIELD ] ) && $this->can_promote( false );

		if ( $promote ) {
			// The site is saved first, so what moves up is what was submitted
			// rather than what was stored before it.
			Settings::promote_to_network();
			Policy::flush();
		}

		Scheduler::reschedule();

		$this->redirect( array( 'rvrt-notice' => $promote ? 'promoted' : 'saved' ) + self::posted_tab() );
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
			wp_die( esc_html_x( 'You are not allowed to change these settings.', 'permission error', 'revision-retention' ) );
		}

		check_admin_referer( self::NETWORK_ACTION );

		Settings::update_network( Settings::sanitize( self::posted_settings(), true ) );
		Policy::flush();

		$this->redirect( array( 'rvrt-notice' => 'saved' ) + self::posted_tab(), true );
	}

	/**
	 * Run one batch, or report what one would do.
	 *
	 * @return void
	 */
	public function run_sweep(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html_x( 'You are not allowed to do this.', 'permission error', 'revision-retention' ) );
		}

		check_admin_referer( self::RUN_ACTION );

		$dry_run = ! isset( $_POST['mode'] ) || 'run' !== sanitize_key( wp_unslash( $_POST['mode'] ) );

		if ( ! $dry_run && ! Settings::may_sweep() ) {
			wp_die( esc_html_x( 'The retention policy for this site is set network wide, so only a network administrator can sweep it.', 'permission error', 'revision-retention' ) );
		}

		$cursor = $dry_run ? 0 : Scheduler::state()['cursor'];
		$result  = ( new Cleaner() )->sweep(
			(int) Settings::get( 'batch_size' ),
			$dry_run,
			$cursor,
			array(),
			(int) Settings::get( 'max_deletions' )
		);

		if ( ! $dry_run ) {
			$this->remember_sweep( $result );
		}

		$this->redirect(
			array(
				'rvrt-notice'    => $dry_run ? 'preview' : 'swept',
				'rvrt-revisions' => (string) $result->revisions,
				'rvrt-posts'     => (string) $result->posts,
				'rvrt-finished'  => $result->finished ? '1' : '0',
				'rvrt-tab'       => 'sweep',
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
	 * The section a form was submitted from, if it said.
	 *
	 * @return array<string, string>
	 */
	private static function posted_tab(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked by every caller; the value only decides which section is shown again.
		$tab = isset( $_POST['rvrt-tab'] ) ? sanitize_key( wp_unslash( $_POST['rvrt-tab'] ) ) : '';

		return '' === $tab ? array() : array( 'rvrt-tab' => $tab );
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
			return _x( 'All', 'revisions kept', 'revision-retention' );
		}

		return 0 === $keep ? _x( 'None', 'stored revision count', 'revision-retention' ) : number_format_i18n( $keep );
	}
}
