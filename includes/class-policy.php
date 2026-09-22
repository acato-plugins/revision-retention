<?php
/**
 * Resolves the retention rule that applies to a post type.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the stored settings into the rule that actually applies.
 *
 * The site wide numbers are the starting point; a per post type override
 * replaces whichever of the two it sets. On multisite the site wide numbers
 * have themselves already been resolved against the network defaults, so this
 * class only has to deal with the last step.
 */
class Policy {

	/**
	 * Rules already resolved in this request, keyed by post type.
	 *
	 * @var array<string, Retention_Rule>
	 */
	private static array $cache = array();

	/**
	 * The rule in effect for one post type.
	 *
	 * @param string $post_type Post type slug.
	 *
	 * @return Retention_Rule
	 */
	public static function for_post_type( string $post_type ): Retention_Rule {
		if ( isset( self::$cache[ $post_type ] ) ) {
			return self::$cache[ $post_type ];
		}

		$settings = Settings::resolved();
		$keep     = (int) $settings['keep'];
		$max_age  = (int) $settings['max_age_days'];

		$overrides = is_array( $settings['post_types'] ) ? $settings['post_types'] : array();
		$override  = $overrides[ $post_type ] ?? null;

		if ( is_array( $override ) ) {
			$keep    = isset( $override['keep'] ) ? (int) $override['keep'] : $keep;
			$max_age = isset( $override['max_age_days'] ) ? (int) $override['max_age_days'] : $max_age;
		}

		$rule = new Retention_Rule( $keep, $max_age );

		/**
		 * Filters the retention rule that applies to a post type.
		 *
		 * @param Retention_Rule $rule      The rule resolved from the settings.
		 * @param string         $post_type Post type the rule applies to.
		 */
		$filtered = apply_filters( 'revision_retention_rule', $rule, $post_type );

		// A filter that hands back something else is ignored rather than
		// trusted: the rest of the plugin reads the two numbers off this object.
		self::$cache[ $post_type ] = $filtered instanceof Retention_Rule ? $filtered : $rule;

		return self::$cache[ $post_type ];
	}

	/**
	 * Every post type a sweep has work to do for.
	 *
	 * A post type without an age rule is left out: its revisions are already
	 * capped by WordPress on every save, and there is nothing to clean up
	 * after the fact.
	 *
	 * @return array<string, Retention_Rule> Rules keyed by post type slug.
	 */
	public static function sweepable(): array {
		$sweepable = array();

		foreach ( array_keys( Post_Types::eligible() ) as $post_type ) {
			$rule = self::for_post_type( $post_type );

			if ( $rule->is_sweepable() ) {
				$sweepable[ $post_type ] = $rule;
			}
		}

		return $sweepable;
	}

	/**
	 * Forget the resolved rules.
	 *
	 * Only needed after the settings change inside a single request, which
	 * happens when a form is saved and when WP-CLI overrides the stored rule.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = array();
	}
}
