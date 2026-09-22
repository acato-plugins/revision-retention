<?php
/**
 * WP-CLI commands.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and apply the retention policy from the command line.
 *
 * Everything here is a thin wrapper over the same Cleaner the scheduled sweep
 * uses, so a run from the command line and a run from cron do the same thing
 * to the same data. On multisite, pass `--url=` to pick the site.
 */
class CLI {

	/**
	 * Register the command with WP-CLI.
	 *
	 * @return void
	 */
	public static function register(): void {
		\WP_CLI::add_command( 'revision-retention', self::class );
	}

	/**
	 * Show the retention policy in effect and what it still has to do.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp revision-retention status
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );

		$state  = Scheduler::state();
		$next   = wp_next_scheduled( Scheduler::HOOK );
		$counts = Cleaner::counts();

		$rows = array(
			array(
				'setting' => 'keep',
				'value'   => self::describe_keep( (int) Settings::get( 'keep' ) ),
			),
			array(
				'setting' => 'max_age_days',
				'value'   => Settings::describe_age( (int) Settings::get( 'max_age_days' ) ),
			),
			array(
				'setting' => 'batch_size',
				'value'   => (string) Settings::get( 'batch_size' ),
			),
			array(
				'setting' => 'max_deletions',
				'value'   => ( (int) Settings::get( 'max_deletions' ) ) > 0
					? sprintf( '%s per run', number_format_i18n( (int) Settings::get( 'max_deletions' ) ) )
					: 'no cap',
			),
			array(
				'setting' => 'scheduled',
				'value'   => empty( Settings::get( 'cron_enabled' ) )
					? 'off'
					: sprintf( '%s, next run %s', (string) Settings::get( 'cron_interval' ), $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) . ' GMT' : 'not booked' ),
			),
			array(
				'setting' => 'revisions_stored',
				'value'   => (string) array_sum( $counts ),
			),
			array(
				'setting' => 'sweep_in_progress',
				'value'   => $state['cursor'] > 0 ? sprintf( 'yes, resuming after post %d', $state['cursor'] ) : 'no',
			),
			array(
				'setting' => 'last_completed_sweep',
				'value'   => $state['finished'] > 0
					? sprintf( '%s GMT, removed %d', gmdate( 'Y-m-d H:i:s', $state['finished'] ), $state['removed'] )
					: 'never',
			),
		);

		\WP_CLI\Utils\format_items( self::format( $assoc_args ), $rows, array( 'setting', 'value' ) );
	}

	/**
	 * Show the rule and the stored revisions of every eligible post type.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp revision-retention post-types
	 *
	 * @subcommand post-types
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function post_types( array $args, array $assoc_args ): void {
		unset( $args );

		$counts = Cleaner::counts();
		$rows   = array();

		foreach ( Post_Types::eligible() as $post_type => $label ) {
			$rule = Policy::for_post_type( $post_type );

			$rows[] = array(
				'post_type' => $post_type,
				'label'     => $label,
				'revisions' => Post_Types::supports_revisions( $post_type )
					? ( Post_Types::is_enabled_by_plugin( $post_type ) ? 'on (by this plugin)' : 'on' )
					: 'off',
				'keep'      => self::describe_keep( $rule->keep ),
				'max_age'   => Settings::describe_age( $rule->max_age_days ),
				'stored'    => (string) ( $counts[ $post_type ] ?? 0 ),
			);
		}

		\WP_CLI\Utils\format_items(
			self::format( $assoc_args ),
			$rows,
			array( 'post_type', 'label', 'revisions', 'keep', 'max_age', 'stored' )
		);
	}

	/**
	 * Apply the retention policy, removing the revisions that fall outside it.
	 *
	 * Runs batch after batch until the site is clean. A sweep already in
	 * progress is continued rather than restarted, unless --restart is passed.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be removed without removing anything.
	 *
	 * [--post-type=<post-type>]
	 * : Only sweep this post type. Repeatable as a comma separated list.
	 *
	 * [--batch=<number>]
	 * : Posts to work through per batch. Defaults to the configured batch size.
	 *
	 * [--max-batches=<number>]
	 * : Stop after this many batches instead of running to completion.
	 *
	 * [--max-deletions=<number>]
	 * : Revisions one batch may remove. Defaults to the configured cap, 0 lifts it.
	 *
	 * [--restart]
	 * : Start from the first post again instead of resuming.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     # See what the policy would remove.
	 *     wp revision-retention run --dry-run
	 *
	 *     # Apply it to pages only.
	 *     wp revision-retention run --post-type=page --yes
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function run( array $args, array $assoc_args ): void {
		unset( $args );

		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$restart = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'restart', false );
		$batch   = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', (int) Settings::get( 'batch_size' ) );
		$max     = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'max-batches', 0 );
		$cap     = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'max-deletions', (int) Settings::get( 'max_deletions' ) );

		$post_types = self::requested_post_types( $assoc_args );
		$rules      = Policy::sweepable();

		if ( array() !== $post_types ) {
			$rules = array_intersect_key( $rules, array_flip( $post_types ) );
		}

		if ( array() === $rules ) {
			\WP_CLI::warning( 'No post type has an age threshold set, so a sweep has nothing to do. Set one on the settings screen, or see `wp revision-retention post-types`.' );

			return;
		}

		if ( ! $dry_run ) {
			\WP_CLI::confirm(
				sprintf(
					'This permanently deletes revisions of %s. Continue?',
					implode( ', ', array_keys( $rules ) )
				),
				$assoc_args
			);
		}

		if ( $restart ) {
			Scheduler::reset_cursor();
		}

		$cursor  = $dry_run ? 0 : Scheduler::state()['cursor'];
		$cleaner = new Cleaner();
		$total   = new Sweep_Result( $cursor, 0, 0, false, $dry_run );
		$batches = 0;

		do {
			$result = $cleaner->sweep( max( 1, $batch ), $dry_run, $total->cursor, $post_types, max( 0, $cap ) );
			$total  = $total->add( $result );
			++$batches;

			\WP_CLI::log(
				sprintf(
					'Batch %d: %d posts checked, %d revisions %s.',
					$batches,
					$result->posts,
					$result->revisions,
					$dry_run ? 'would be removed' : 'removed'
				)
			);

			if ( ! $dry_run ) {
				self::remember( $total );
			}
		} while ( ! $total->finished && ( 0 === $max || $batches < $max ) );

		\WP_CLI::success(
			sprintf(
				'%d posts checked, %d revisions %s.%s',
				$total->posts,
				$total->revisions,
				$dry_run ? 'would be removed' : 'removed',
				$total->finished ? '' : ' Stopped early; run again to continue.'
			)
		);
	}

	/**
	 * Persist how far a real run got, so cron can carry on from there.
	 *
	 * @param Sweep_Result $total Everything this run has done so far.
	 *
	 * @return void
	 */
	private static function remember( Sweep_Result $total ): void {
		if ( $total->finished ) {
			Scheduler::reset_cursor();

			return;
		}

		$state = Scheduler::state();

		update_option(
			Scheduler::CURSOR_OPTION,
			array(
				'cursor'    => $total->cursor,
				'revisions' => $total->revisions,
				'posts'     => $total->posts,
				'started'   => $state['started'] > 0 ? $state['started'] : time(),
				'finished'  => $state['finished'],
				'removed'   => $state['removed'],
			)
		);
	}

	/**
	 * The post types the command was limited to, if any.
	 *
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return array<int, string>
	 */
	private static function requested_post_types( array $assoc_args ): array {
		$requested = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'post-type', '' );

		if ( '' === $requested ) {
			return array();
		}

		$post_types = array_filter( array_map( 'trim', explode( ',', $requested ) ) );
		$eligible   = Post_Types::eligible();

		foreach ( $post_types as $post_type ) {
			if ( ! isset( $eligible[ $post_type ] ) ) {
				\WP_CLI::error( sprintf( 'Unknown or ineligible post type: %s', $post_type ) );
			}
		}

		return array_values( $post_types );
	}

	/**
	 * The output format asked for.
	 *
	 * @param array<string, string> $assoc_args Associative arguments.
	 *
	 * @return string
	 */
	private static function format( array $assoc_args ): string {
		return (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
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
			return 'all (never purged)';
		}

		return 0 === $keep ? 'none' : (string) $keep;
	}
}
