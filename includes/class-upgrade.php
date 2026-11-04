<?php
/**
 * Brings a site's stored data up to date with the code.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention\Includes;

use Acato\RevisionRetention\Admin\RatingNotice;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once per site per version, on activation and after an update.
 *
 * WordPress runs no activation hook when a plugin is updated, so whatever a
 * new version needs in place, on a site that has run an older one for years,
 * is done here instead, the first time the site loads the new code. Every step
 * is safe to repeat and leaves what is already there alone.
 *
 * @author Paul van Impelen <paul@acato.nl>
 */
final class Upgrade extends Singleton {

	/**
	 * Option holding the plugin version this site's data was last brought up to.
	 *
	 * @var string
	 */
	public const VERSION_OPTION = 'rvrt_version';

	/**
	 * Bring the site up to date when its data predates this version.
	 *
	 * The option is autoloaded, so once it is current this costs nothing.
	 *
	 * @return void
	 */
	protected function init(): void {
		if ( RVRT_VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::run();
		}
	}

	/**
	 * Bring the site up to date when the plugin is activated.
	 *
	 * @return void
	 */
	public static function on_activation(): void {
		self::run();
	}

	/**
	 * Every step, in order.
	 *
	 * @return void
	 */
	public static function run(): void {
		// Both are read on every request, the settings on `init` and the log
		// version on every admin screen. Until the first save, or the first
		// logged sweep, neither existed, and a missing option is a query of
		// its own each time. Empty, autoloaded placeholders come along with
		// the rest of the options for free, and read the same as missing.
		self::add_autoloaded( Settings::OPTION, [] );
		self::add_autoloaded( Log::DB_VERSION_OPTION, '' );

		// Installs that predate the rating notice learn when they started here,
		// once, rather than looking it up on every admin screen.
		RatingNotice::remember_install();

		update_option( self::VERSION_OPTION, RVRT_VERSION, true );
	}

	/**
	 * Add an autoloaded option, unless the site already has it.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value it starts with.
	 *
	 * @return void
	 */
	private static function add_autoloaded( string $name, mixed $value ): void {
		if ( false === get_option( $name ) ) {
			add_option( $name, $value, '', true );
		}
	}
}
