<?php
/**
 * Which post types this plugin deals with, and revision support for them.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the post types a retention policy can apply to, and switches revision
 * support on for the ones the settings ask for.
 */
class Post_Types {

	/**
	 * Post types that are never offered, whatever they are registered as.
	 *
	 * These are managed by WordPress itself and either cannot hold revisions
	 * or would break if they did.
	 *
	 * @var array<int, string>
	 */
	private const EXCLUDED = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_global_styles',
		'wp_navigation',
		'wp_template',
		'wp_template_part',
		'wp_font_family',
		'wp_font_face',
	);

	/**
	 * Eligible post types resolved in this request.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $eligible = null;

	/**
	 * Hook revision support into WordPress.
	 *
	 * Runs late on `init` so custom post types registered by themes and other
	 * plugins are already there to add support to.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_revision_support' ), 99 );
	}

	/**
	 * Switch revision support on for the post types the settings name.
	 *
	 * Support is only ever added, never taken away: a post type that already
	 * supports revisions is left alone, and nothing here can stop a post type
	 * from having the revisions it was registered with.
	 *
	 * @return void
	 */
	public function add_revision_support(): void {
		$enabled = Settings::get( 'enable_revisions' );

		if ( ! is_array( $enabled ) ) {
			return;
		}

		foreach ( $enabled as $post_type ) {
			$post_type = (string) $post_type;

			if ( ! post_type_exists( $post_type ) || post_type_supports( $post_type, 'revisions' ) ) {
				continue;
			}

			add_post_type_support( $post_type, 'revisions' );
		}

		// The list is built before support is added, so it would otherwise
		// keep reporting the old answer for the rest of the request.
		self::$eligible = null;
	}

	/**
	 * The post types a retention policy can be set for.
	 *
	 * @return array<string, string> Labels keyed by post type slug.
	 */
	public static function eligible(): array {
		if ( null !== self::$eligible ) {
			return self::$eligible;
		}

		$eligible = array();

		foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
			if ( in_array( $post_type->name, self::EXCLUDED, true ) ) {
				continue;
			}

			// Internal post types other plugins register for bookkeeping have
			// no editing history worth a policy.
			if ( ! $post_type->public && ! $post_type->show_ui ) {
				continue;
			}

			// A revision stores the title, content and excerpt of a post. A
			// post type with no editor has none of that to revise, which is
			// what keeps the likes of ACF's field groups and a theme's
			// template records out of the list. One that already stores
			// revisions stays in regardless, so nothing in use disappears.
			if ( ! post_type_supports( $post_type->name, 'editor' ) && ! post_type_supports( $post_type->name, 'revisions' ) ) {
				continue;
			}

			$eligible[ $post_type->name ] = $post_type->labels->name ?? $post_type->name;
		}

		/**
		 * Filters the post types a retention policy can be set for.
		 *
		 * @param array<string, string> $eligible Labels keyed by post type slug.
		 */
		$eligible = apply_filters( 'revision_retention_eligible_post_types', $eligible );

		self::$eligible = (array) $eligible;

		return self::$eligible;
	}

	/**
	 * Forget the eligible post types.
	 *
	 * Needed after switching to another site, whose plugins and themes register
	 * post types of their own.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$eligible = null;
	}

	/**
	 * Whether a post type stores revisions at all right now.
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return bool
	 */
	public static function supports_revisions( string $post_type ): bool {
		return post_type_exists( $post_type ) && post_type_supports( $post_type, 'revisions' );
	}

	/**
	 * Whether this plugin is the reason a post type stores revisions.
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return bool
	 */
	public static function is_enabled_by_plugin( string $post_type ): bool {
		$enabled = Settings::get( 'enable_revisions' );

		return is_array( $enabled ) && in_array( $post_type, $enabled, true );
	}
}
