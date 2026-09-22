<?php
/**
 * The retention rule in effect for one post type.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * How many revisions to keep for a post, and how old the rest may get.
 *
 * The two numbers work together rather than independently:
 *
 * - `keep` is a floor. The newest N revisions of a post are never deleted,
 *   however old they are. This is what stops a post that was last edited years
 *   ago from losing its entire history the first time a sweep runs.
 * - `max_age_days` is a ceiling. Beyond the floor, revisions older than the
 *   cutoff are removed.
 *
 * An unlimited floor therefore beats any ceiling: when `keep` is -1 nothing is
 * ever purged for that post type.
 */
final class Retention_Rule {

	/**
	 * Value of `keep` that means "retain every revision".
	 *
	 * @var int
	 */
	public const UNLIMITED = -1;

	/**
	 * Build a rule.
	 *
	 * @param int $keep         Newest revisions to always retain, or self::UNLIMITED for all of them.
	 * @param int $max_age_days Age in days beyond which revisions are removed, or 0 to never remove on age.
	 */
	public function __construct(
		public readonly int $keep,
		public readonly int $max_age_days
	) {}

	/**
	 * Whether this rule retains every revision, whatever its age.
	 *
	 * @return bool
	 */
	public function keeps_everything(): bool {
		return $this->keep < 0;
	}

	/**
	 * Whether a sweep has anything to do for this post type.
	 *
	 * Without an age rule there is nothing to clean up after the fact: the
	 * `keep` count is already enforced by WordPress itself every time a post is
	 * saved, through the filters in Limits.
	 *
	 * @return bool
	 */
	public function is_sweepable(): bool {
		return ! $this->keeps_everything() && $this->max_age_days > 0;
	}

	/**
	 * The GMT datetime revisions must predate to be swept.
	 *
	 * @return string Datetime in `Y-m-d H:i:s`, empty when this rule has no age component.
	 */
	public function cutoff_gmt(): string {
		if ( ! $this->is_sweepable() ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', time() - ( $this->max_age_days * DAY_IN_SECONDS ) );
	}
}
