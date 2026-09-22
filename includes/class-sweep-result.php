<?php
/**
 * What one batch of a sweep did.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of a single batch, and where the next one should pick up.
 */
final class Sweep_Result {

	/**
	 * Build a result.
	 *
	 * @param int  $cursor    Highest parent post ID this batch dealt with.
	 * @param int  $posts     Posts whose revisions were looked at.
	 * @param int  $revisions Revisions removed, or that would have been removed on a dry run.
	 * @param bool $finished  Whether there is nothing left after this batch.
	 * @param bool $dry_run   Whether anything was actually deleted.
	 */
	public function __construct(
		public readonly int $cursor,
		public readonly int $posts,
		public readonly int $revisions,
		public readonly bool $finished,
		public readonly bool $dry_run
	) {}

	/**
	 * Add another batch's outcome to this one.
	 *
	 * @param Sweep_Result $next The batch that ran after this one.
	 *
	 * @return Sweep_Result
	 */
	public function add( Sweep_Result $next ): Sweep_Result {
		return new Sweep_Result(
			$next->cursor,
			$this->posts + $next->posts,
			$this->revisions + $next->revisions,
			$next->finished,
			$this->dry_run
		);
	}
}
