<?php
/**
 * Finds the built assets.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the sources in src/ to the files Vite built from them in dist/.
 *
 * The built files carry a hash in their names, and the manifest Vite writes
 * next to them says which is which. The hash changes whenever the file does,
 * so it is the cache buster, and no version is hung on the URL.
 */
final class Assets {

	/**
	 * Folder the build writes to, inside the plugin.
	 *
	 * @var string
	 */
	private const DIST = 'dist/';

	/**
	 * The manifest, read once per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $manifest = null;

	/**
	 * Hook the asset lookup into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'load_script_textdomain_relative_path', array( self::class, 'translation_path' ), 10, 2 );
	}

	/**
	 * The URL of what was built from a source file.
	 *
	 * @param string $source Path of the source inside the plugin, e.g. `src/settings.js`.
	 *
	 * @return string|null Null when the assets have not been built.
	 */
	public static function url( string $source ): ?string {
		$file = self::manifest()[ $source ]['file'] ?? null;

		return is_string( $file ) ? plugin_dir_url( RVRT_PLUGIN_FILE ) . self::DIST . $file : null;
	}

	/**
	 * Point WordPress at the source a built script came from, for its translations.
	 *
	 * WordPress.org reads the strings from src/ and names the JSON
	 * translation file after that path. WordPress looks the file up by the
	 * path of the script it loads, which is the hashed one in dist/, and
	 * would never find it.
	 *
	 * @param string|false $relative Path of the script inside the plugin, or false.
	 * @param string       $src      Full URL of the script.
	 *
	 * @return string|false
	 */
	public static function translation_path( $relative, $src ) {
		unset( $src );

		if ( ! is_string( $relative ) || ! str_starts_with( $relative, self::DIST ) ) {
			return $relative;
		}

		foreach ( self::manifest() as $source => $entry ) {
			if ( self::DIST . ( $entry['file'] ?? '' ) === $relative ) {
				return (string) $source;
			}
		}

		return $relative;
	}

	/**
	 * What Vite built, keyed by source path.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function manifest(): array {
		if ( null !== self::$manifest ) {
			return self::$manifest;
		}

		$path     = plugin_dir_path( RVRT_PLUGIN_FILE ) . self::DIST . 'manifest.json';
		$contents = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file of the plugin's own, not a remote request.
		$decoded  = false === $contents ? null : json_decode( $contents, true );

		self::$manifest = is_array( $decoded ) ? $decoded : array();

		return self::$manifest;
	}
}
