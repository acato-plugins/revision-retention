<?php
/**
 * Lets the plugin's open admin tabs tell each other what changed.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention\Admin;

use Acato\RevisionRetention\Includes\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The server's half of src/channel.js: the script itself, the name of the
 * channel a screen talks on, and the notices it shows when another tab
 * changed something.
 *
 * The notices are rendered here, hidden, so their text is translated with
 * the rest of the screen's; the script only shows them. Without the script
 * they stay hidden, and every tab works on its own as it always did.
 *
 * @author Paul van Impelen <paul@acato.nl>
 */
final class TabSync {

	/**
	 * Handle the channel script is registered under.
	 *
	 * @var string
	 */
	public const HANDLE = 'rvrt-channel';

	/**
	 * Register the channel script, for the screen's own script to depend on.
	 *
	 * @return list<non-empty-string> The handle to add to the dependencies, or none when the script is missing.
	 */
	public static function dependency(): array {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return [ self::HANDLE ];
		}

		$script = Assets::url( 'src/channel.js' );

		if ( null === $script || '' === $script ) {
			return [];
		}

		wp_register_script( self::HANDLE, $script, [ 'wp-a11y', 'wp-i18n' ], null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The hash in the built file name, or the ver on a source, is the version.
		wp_set_script_translations( self::HANDLE, 'revision-retention' );

		return [ self::HANDLE ];
	}

	/**
	 * The channel a site's tabs, or the network admin's, talk on.
	 *
	 * Subdirectory sites on a network share one origin, so the channel is
	 * named for the site; otherwise a sweep on one would reach the others.
	 *
	 * @param bool $network Whether the network admin is meant.
	 *
	 * @return string
	 */
	public static function channel( bool $network ): string {
		return 'acato-revision-retention-' . ( $network ? 'network' : (string) get_current_blog_id() );
	}

	/**
	 * A fingerprint of stored settings, for tabs to tell whether they hold the same.
	 *
	 * @param array<string, mixed> $settings Settings as stored.
	 *
	 * @return string
	 */
	public static function stamp( array $settings ): string {
		return substr( md5( (string) wp_json_encode( $settings ) ), 0, 16 );
	}

	/**
	 * The channels a screen listens on, as the attribute that names them.
	 *
	 * Every channel's sweep that removes something brings the screen's
	 * figures up to date; see src/channel.js.
	 *
	 * @param bool ...$networks For each channel, whether it is the network admin's.
	 *
	 * @return string The attribute, escaped, with a leading space.
	 */
	public static function channels_attribute( bool ...$networks ): string {
		return sprintf( ' data-channels="%s"', esc_attr( implode( ' ', array_map( [ self::class, 'channel' ], $networks ) ) ) );
	}

	/**
	 * What goes at the top of a screen that brings its figures up to date.
	 *
	 * The notices for an update and for a failed one, and the log's totals
	 * for each view on the screen, which the script compares before and after
	 * an update to say what changed. The totals come from the page the server
	 * renders, never from what another tab says.
	 *
	 * @param array<string, bool> $views Whether each view, keyed by name, covers the network.
	 *
	 * @return void
	 */
	public static function render_head( array $views ): void {
		self::render_updated_notice();
		self::render_stale_notice();

		foreach ( $views as $view => $network ) {
			$totals = Log::totals( $network );

			printf(
				'<span hidden data-rvrt-figures="%s" data-revisions="%d" data-posts="%d"></span>',
				esc_attr( $view ),
				(int) $totals['revisions'],
				(int) $totals['posts']
			);
		}
	}

	/**
	 * The notice that says what an update brought in; the script writes it.
	 *
	 * @return void
	 */
	private static function render_updated_notice(): void {
		?>
		<div class="rvrt-updated notice notice-success inline is-dismissible" hidden>
			<p></p>
			<button type="button" class="notice-dismiss"><span class="screen-reader-text"><?php echo esc_html_x( 'Dismiss this notice.', 'button label', 'revision-retention' ); ?></span></button>
		</div>
		<?php
	}

	/**
	 * The notice that what a screen shows is out of date.
	 *
	 * Shown only when the figures could not be brought up to date in place.
	 *
	 * @return void
	 */
	private static function render_stale_notice(): void {
		?>
		<div class="rvrt-stale notice notice-info inline" hidden>
			<p>
				<?php echo esc_html_x( 'A sweep in another tab has removed revisions, so the figures here are out of date.', 'admin notice', 'revision-retention' ); ?>
				<button type="button" class="button-link rvrt-reload"><?php echo esc_html_x( 'Reload', 'button label', 'revision-retention' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * The notice that another tab has a sweep going, beside the sweep's buttons.
	 *
	 * @return void
	 */
	public static function render_elsewhere_notice(): void {
		?>
		<div class="rvrt-sweep-elsewhere notice notice-info inline" hidden>
			<p><?php echo esc_html_x( 'A sweep is running in another tab. A new one can be started here once it ends.', 'admin notice', 'revision-retention' ); ?></p>
		</div>
		<?php
	}
}
