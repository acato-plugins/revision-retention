<?php
/**
 * Abilities for the WordPress Abilities API (WordPress 7.1+).
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention\Includes;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every ability of this plugin: one register_*() method per
 * ability; the shared steps (category, capability check, schemas,
 * annotations, personal-data guard) live in helpers.
 *
 * All of them work on the current site and need `manage_options`, the same
 * as the settings screen; run-sweep also needs Settings::may_sweep(), the
 * same rule as the Sweep now tab. They are not public and not on the REST
 * API: only code on the site itself can run them. None of them returns who
 * ran a sweep, user IDs or post titles, so nothing personal reaches an AI
 * client through them.
 *
 * @author Paul van Impelen <paul@acato.nl>
 */
final class Abilities extends Singleton {

	/**
	 * Ability namespace (`revision-retention/{ability}`) and category slug. Equal to the plugin slug.
	 *
	 * @var string
	 */
	private const ABILITY_NAMESPACE = 'revision-retention';

	/**
	 * Category slug.
	 *
	 * @var string
	 */
	private const CATEGORY = 'revision-retention';

	/**
	 * Capability every ability needs: whoever may change the plugin's settings.
	 * Confirmed with the user on 2026-11-12.
	 *
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Most batches a preview counts through in one call, so it stays a short request.
	 *
	 * @var int
	 */
	private const MAX_PREVIEW_BATCHES = 20;

	/**
	 * Most log entries one call returns.
	 *
	 * @var int
	 */
	private const MAX_LOG_ENTRIES = 50;

	/**
	 * Keys that hold personal data (GDPR / AVG). Input or output of a guarded
	 * ability containing one of these keys, or an e-mail address anywhere, is
	 * refused before it reaches an AI client.
	 *
	 * @var string[]
	 */
	private const PERSONAL_DATA_KEYS = [
		'email',
		'user_email',
		'author_email',
		'billing_email',
		'user_login',
		'login',
		'username',
		'display_name',
		'nickname',
		'first_name',
		'last_name',
		'full_name',
		'phone',
		'telephone',
		'mobile',
		'address',
		'street',
		'postcode',
		'zipcode',
		'ip',
		'ip_address',
		'author_ip',
		'birthdate',
		'date_of_birth',
		'bsn',
		'iban',
		'user_id',
	];

	/**
	 * Abilities allowed to handle personal data anyway. Only after the user
	 * explicitly confirmed a lawful basis and a processing agreement (DPA)
	 * with the AI provider, with the ticket and DPA in a comment.
	 *
	 * @var string[]
	 */
	private const PERSONAL_DATA_APPROVED = [];

	/**
	 * Hook registration and the personal-data guard. Requires WordPress 7.1.
	 *
	 * @return void
	 */
	protected function init(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! is_wp_version_compatible( '7.1' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
		add_filter( 'wp_ability_validate_input', [ $this, 'guard_personal_data' ], 10, 3 );
		add_filter( 'wp_ability_validate_output', [ $this, 'guard_personal_data' ], 10, 3 );
	}

	/**
	 * Register the plugin's ability category.
	 *
	 * @return void
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => _x( 'Revision Retention', 'ability category', 'revision-retention' ),
				'description' => _x( 'The revision retention policy of this site, and sweeps that remove old revisions.', 'ability category', 'revision-retention' ),
			]
		);
	}

	/**
	 * Register every ability. One line per ability.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		$this->register_get_status();
		$this->register_get_post_types();
		$this->register_preview_sweep();
		$this->register_get_sweep_log();
		$this->register_run_sweep();
	}

	/*
	 * ------------------------------------------------------------------
	 * Abilities: one register_*() method and one callback per ability.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Ability: revision-retention/get-status.
	 *
	 * @return void
	 */
	private function register_get_status(): void {
		$this->register(
			'get-status',
			[
				'label'            => _x( 'Get the revision retention status', 'ability label', 'revision-retention' ),
				'description'      => _x( 'Returns how many revisions this site stores, the retention policy (newest revisions always kept, age after which the rest are removed), when the next scheduled sweep runs, whether WP-Cron is running, and where the current sweep stands. Never returns who ran a sweep or any post titles.', 'ability description', 'revision-retention' ),
				'output_schema'    => $this->object_schema(
					[
						'revisions_stored'   => $this->integer_schema( _x( 'Revisions Stored', 'ability schema', 'revision-retention' ), _x( 'Revisions stored on this site, autosaves left out.', 'ability schema', 'revision-retention' ), 0 ),
						'post_types_holding' => $this->integer_schema( _x( 'Post Types Holding Them', 'ability schema', 'revision-retention' ), _x( 'How many post types have stored revisions.', 'ability schema', 'revision-retention' ), 0 ),
						'keep'               => $this->integer_schema( _x( 'Always Keep', 'ability schema', 'revision-retention' ), _x( 'Newest revisions of every post that are never removed; -1 keeps every revision.', 'ability schema', 'revision-retention' ), -1 ),
						'max_age_days'       => $this->integer_schema( _x( 'Remove Older Than', 'ability schema', 'revision-retention' ), _x( 'Age in days after which revisions beyond the kept ones are removed; 0 means never.', 'ability schema', 'revision-retention' ), 0 ),
						'schedule_enabled'   => $this->boolean_schema( _x( 'Runs Automatically', 'ability schema', 'revision-retention' ), _x( 'Whether sweeps run on a schedule.', 'ability schema', 'revision-retention' ) ),
						'interval'           => $this->string_schema( _x( 'How Often', 'ability schema', 'revision-retention' ), _x( 'The schedule interval, such as daily or weekly.', 'ability schema', 'revision-retention' ) ),
						'next_sweep'         => $this->string_schema( _x( 'Next Sweep', 'ability schema', 'revision-retention' ), _x( 'When the next scheduled sweep runs, ISO 8601 in UTC; empty when none is booked.', 'ability schema', 'revision-retention' ) ),
						'cron'               => $this->string_schema( _x( 'WP-Cron', 'ability schema', 'revision-retention' ), _x( 'running: triggered by visits; system: a server cron job runs it; stalled: scheduled events are overdue.', 'ability schema', 'revision-retention' ), [ 'enum' => [ CronHealth::RUNNING, CronHealth::SYSTEM, CronHealth::STALLED ] ] ),
						'sweep_in_progress'  => $this->boolean_schema( _x( 'Sweep In Progress', 'ability schema', 'revision-retention' ), _x( 'Whether a sweep has started and not reached the end of the site yet.', 'ability schema', 'revision-retention' ) ),
						'last_finished'      => $this->string_schema( _x( 'Last Sweep Finished', 'ability schema', 'revision-retention' ), _x( 'When the last full sweep finished, ISO 8601 in UTC; empty when none has.', 'ability schema', 'revision-retention' ) ),
						'last_removed'       => $this->integer_schema( _x( 'Last Sweep Removed', 'ability schema', 'revision-retention' ), _x( 'Revisions the last full sweep removed.', 'ability schema', 'revision-retention' ), 0 ),
						'may_sweep'          => $this->boolean_schema( _x( 'May Sweep', 'ability schema', 'revision-retention' ), _x( 'Whether the current user may run a sweep here; on a network that depends on whether sites may set their own policy.', 'ability schema', 'revision-retention' ) ),
					]
				),
				'execute_callback' => [ $this, 'get_status' ],
				'annotations'      => $this->read_only(),
			]
		);
	}

	/**
	 * The status of this site's revision retention.
	 *
	 * @return array<string, mixed>
	 */
	public function get_status(): array {
		$counts = Cleaner::counts();
		$state  = Scheduler::state();
		$next   = wp_next_scheduled( Scheduler::HOOK );

		return [
			'revisions_stored'   => array_sum( $counts ),
			'post_types_holding' => count( array_filter( $counts ) ),
			'keep'               => (int) Settings::get( 'keep' ),
			'max_age_days'       => (int) Settings::get( 'max_age_days' ),
			'schedule_enabled'   => ! empty( Settings::get( 'cron_enabled' ) ),
			'interval'           => (string) Settings::get( 'cron_interval' ),
			'next_sweep'         => self::iso( false === $next ? 0 : (int) $next ),
			'cron'               => CronHealth::check()['status'],
			'sweep_in_progress'  => $state['cursor'] > 0,
			'last_finished'      => self::iso( $state['finished'] ),
			'last_removed'       => $state['removed'],
			'may_sweep'          => Settings::may_sweep(),
		];
	}

	/**
	 * Ability: revision-retention/get-post-types.
	 *
	 * @return void
	 */
	private function register_get_post_types(): void {
		$this->register(
			'get-post-types',
			[
				'label'            => _x( 'Get the policy per post type', 'ability label', 'revision-retention' ),
				'description'      => _x( 'Lists every post type a retention policy can be set for, with how many revisions it stores, whether it keeps revisions at all, and the policy that applies to it. Never returns posts or their titles.', 'ability description', 'revision-retention' ),
				'output_schema'    => $this->list_schema(
					$this->object_schema(
						[
							'post_type'    => $this->string_schema( _x( 'Post Type', 'ability schema', 'revision-retention' ), _x( 'Post type slug.', 'ability schema', 'revision-retention' ) ),
							'label'        => $this->string_schema( _x( 'Label', 'ability schema', 'revision-retention' ), _x( 'Post type name, plain text.', 'ability schema', 'revision-retention' ) ),
							'stored'       => $this->integer_schema( _x( 'Stored', 'ability schema', 'revision-retention' ), _x( 'Revisions stored for this post type.', 'ability schema', 'revision-retention' ), 0 ),
							'revisions_on' => $this->boolean_schema( _x( 'Revisions', 'ability schema', 'revision-retention' ), _x( 'Whether this post type keeps revisions, of its own or because the plugin switched them on.', 'ability schema', 'revision-retention' ) ),
							'keep'         => $this->integer_schema( _x( 'Always Keep', 'ability schema', 'revision-retention' ), _x( 'Newest revisions per post that are never removed; -1 keeps every revision.', 'ability schema', 'revision-retention' ), -1 ),
							'max_age_days' => $this->integer_schema( _x( 'Remove Older Than', 'ability schema', 'revision-retention' ), _x( 'Age in days after which the rest are removed; 0 means never.', 'ability schema', 'revision-retention' ), 0 ),
							'sweepable'    => $this->boolean_schema( _x( 'Swept', 'ability schema', 'revision-retention' ), _x( 'Whether a sweep removes anything from this post type under its policy.', 'ability schema', 'revision-retention' ) ),
						]
					)
				),
				'execute_callback' => [ $this, 'get_post_types' ],
				'annotations'      => $this->read_only(),
			]
		);
	}

	/**
	 * The post types and the policy for each.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_post_types(): array {
		$counts = Cleaner::counts();
		$rows   = [];

		foreach ( PostTypes::eligible() as $post_type => $label ) {
			$rule   = Policy::for_post_type( (string) $post_type );
			$rows[] = [
				'post_type'    => (string) $post_type,
				'label'        => wp_strip_all_tags( (string) $label ),
				'stored'       => $counts[ $post_type ] ?? 0,
				'revisions_on' => PostTypes::supports_revisions( (string) $post_type ),
				'keep'         => $rule->keep,
				'max_age_days' => $rule->max_age_days,
				'sweepable'    => $rule->is_sweepable(),
			];
		}

		return $rows;
	}

	/**
	 * Ability: revision-retention/preview-sweep.
	 *
	 * @return void
	 */
	private function register_preview_sweep(): void {
		$this->register(
			'preview-sweep',
			[
				'label'            => _x( 'Preview a sweep', 'ability label', 'revision-retention' ),
				'description'      => _x( 'Counts what a sweep would remove under the current policy, per post type, without deleting anything. Works through the site a batch at a time and says whether it reached the end. Never returns posts or their titles.', 'ability description', 'revision-retention' ),
				'input_schema'     => $this->input_schema(
					[
						'batches' => $this->integer_schema( _x( 'Batches', 'ability schema', 'revision-retention' ), _x( 'How many batches to count through, each the size set on the Schedule tab.', 'ability schema', 'revision-retention' ), 1, self::MAX_PREVIEW_BATCHES ),
					]
				),
				'output_schema'    => $this->object_schema(
					[
						'revisions' => $this->integer_schema( _x( 'Revisions', 'ability schema', 'revision-retention' ), _x( 'Revisions a sweep would remove in the posts counted.', 'ability schema', 'revision-retention' ), 0 ),
						'posts'     => $this->integer_schema( _x( 'Posts', 'ability schema', 'revision-retention' ), _x( 'Posts a sweep would remove revisions from.', 'ability schema', 'revision-retention' ), 0 ),
						'checked'   => $this->integer_schema( _x( 'Posts Checked', 'ability schema', 'revision-retention' ), _x( 'Posts looked at.', 'ability schema', 'revision-retention' ), 0 ),
						'complete'  => $this->boolean_schema( _x( 'Complete', 'ability schema', 'revision-retention' ), _x( 'Whether the whole site was counted; false means there is more beyond the batches counted.', 'ability schema', 'revision-retention' ) ),
						'per_type'  => $this->list_schema(
							$this->object_schema(
								[
									'label'     => $this->string_schema( _x( 'Post Type', 'ability schema', 'revision-retention' ), _x( 'Post type name, plain text.', 'ability schema', 'revision-retention' ) ),
									'revisions' => $this->integer_schema( _x( 'Revisions', 'ability schema', 'revision-retention' ), _x( 'Revisions that would be removed.', 'ability schema', 'revision-retention' ), 0 ),
									'posts'     => $this->integer_schema( _x( 'Posts', 'ability schema', 'revision-retention' ), _x( 'Posts they would be removed from.', 'ability schema', 'revision-retention' ), 0 ),
								]
							)
						),
					]
				),
				'execute_callback' => [ $this, 'preview_sweep' ],
				'annotations'      => $this->read_only(),
			]
		);
	}

	/**
	 * Count what a sweep would remove, without removing anything.
	 *
	 * @param array{batches?: int} $input Validated input.
	 *
	 * @return array<string, mixed>
	 */
	public function preview_sweep( array $input = [] ): array {
		$batches  = min( self::MAX_PREVIEW_BATCHES, max( 1, (int) ( $input['batches'] ?? 5 ) ) );
		$cursor   = 0;
		$checked  = 0;
		$complete = false;
		$per_type = [];

		for ( $i = 0; $i < $batches && ! $complete; $i++ ) {
			$result = Scheduler::run_batch( Log::SOURCE_ABILITY, true, $cursor );

			if ( null === $result ) {
				break;
			}

			$checked += $result->posts;
			$cursor   = $result->cursor;
			$complete = $result->finished;

			// Only the post type and the count of each item are kept; the
			// item's title and link never leave this method.
			foreach ( $result->items as $item ) {
				$label                           = wp_strip_all_tags( (string) ( $item['type'] ?? '' ) );
				$per_type[ $label ]['revisions'] = ( $per_type[ $label ]['revisions'] ?? 0 ) + (int) ( $item['revisions'] ?? 0 );
				$per_type[ $label ]['posts']     = ( $per_type[ $label ]['posts'] ?? 0 ) + 1;
			}
		}

		$rows = [];

		foreach ( $per_type as $label => $totals ) {
			$rows[] = [
				'label'     => (string) $label,
				'revisions' => $totals['revisions'],
				'posts'     => $totals['posts'],
			];
		}

		return [
			'revisions' => (int) array_sum( array_column( $rows, 'revisions' ) ),
			'posts'     => (int) array_sum( array_column( $rows, 'posts' ) ),
			'checked'   => $checked,
			'complete'  => $complete,
			'per_type'  => $rows,
		];
	}

	/**
	 * Ability: revision-retention/get-sweep-log.
	 *
	 * @return void
	 */
	private function register_get_sweep_log(): void {
		$this->register(
			'get-sweep-log',
			[
				'label'            => _x( 'Get the sweep log', 'ability label', 'revision-retention' ),
				'description'      => _x( 'Lists the most recent sweeps that removed revisions on this site: where each came from (the schedule, the settings screen, WP-CLI or an ability), how many revisions it removed from how many posts, per post type, and when. Never returns who ran a sweep.', 'ability description', 'revision-retention' ),
				'input_schema'     => $this->input_schema(
					[
						'limit'  => $this->integer_schema( _x( 'Limit', 'ability schema', 'revision-retention' ), _x( 'Most entries to return, newest first.', 'ability schema', 'revision-retention' ), 1, self::MAX_LOG_ENTRIES ),
						'source' => $this->string_schema( _x( 'Source', 'ability schema', 'revision-retention' ), _x( 'Only sweeps from this source.', 'ability schema', 'revision-retention' ), [ 'enum' => self::sources() ] ),
					]
				),
				'output_schema'    => $this->object_schema(
					[
						'enabled' => $this->boolean_schema( _x( 'Log Kept', 'ability schema', 'revision-retention' ), _x( 'Whether this site keeps a log of sweeps.', 'ability schema', 'revision-retention' ) ),
						'total'   => $this->integer_schema( _x( 'Total', 'ability schema', 'revision-retention' ), _x( 'Entries in the log that match.', 'ability schema', 'revision-retention' ), 0 ),
						'entries' => $this->list_schema(
							$this->object_schema(
								[
									'source'     => $this->string_schema( _x( 'Source', 'ability schema', 'revision-retention' ), _x( 'Where the sweep came from.', 'ability schema', 'revision-retention' ), [ 'enum' => self::sources() ] ),
									'revisions'  => $this->integer_schema( _x( 'Revisions', 'ability schema', 'revision-retention' ), _x( 'Revisions removed.', 'ability schema', 'revision-retention' ), 0 ),
									'posts'      => $this->integer_schema( _x( 'Posts', 'ability schema', 'revision-retention' ), _x( 'Posts they were removed from.', 'ability schema', 'revision-retention' ), 0 ),
									'post_types' => [
										'type'        => 'object',
										'title'       => _x( 'Per Post Type', 'ability schema', 'revision-retention' ),
										'description' => _x( 'Revisions removed, keyed by post type name.', 'ability schema', 'revision-retention' ),
										'additionalProperties' => [ 'type' => 'integer' ],
									],
									'status'     => $this->string_schema( _x( 'Status', 'ability schema', 'revision-retention' ), _x( 'finished, running or stopped.', 'ability schema', 'revision-retention' ), [ 'enum' => [ 'finished', 'running', 'stopped' ] ] ),
									'started'    => $this->string_schema( _x( 'Started', 'ability schema', 'revision-retention' ), _x( 'ISO 8601, UTC.', 'ability schema', 'revision-retention' ) ),
									'ended'      => $this->string_schema( _x( 'Ended', 'ability schema', 'revision-retention' ), _x( 'ISO 8601, UTC; the last batch for a sweep still running.', 'ability schema', 'revision-retention' ) ),
								]
							)
						),
					]
				),
				'execute_callback' => [ $this, 'get_sweep_log' ],
				'annotations'      => $this->read_only(),
			]
		);
	}

	/**
	 * The newest log entries, without who ran them.
	 *
	 * @param array{limit?: int, source?: string} $input Validated input.
	 *
	 * @return array<string, mixed>
	 */
	public function get_sweep_log( array $input = [] ): array {
		$limit     = min( self::MAX_LOG_ENTRIES, max( 1, (int) ( $input['limit'] ?? 10 ) ) );
		$requested = $input['source'] ?? '';
		$source    = in_array( $requested, self::sources(), true ) ? $requested : '';
		$found     = Log::entries( 1, $limit, $source );

		$entries = array_map(
			static function ( array $row ): array {
				$types = json_decode( (string) ( $row['post_types'] ?? '' ), true );
				$types = is_array( $types ) ? $types : [];
				$clean = [];

				foreach ( $types as $type => $count ) {
					$clean[ wp_strip_all_tags( (string) $type ) ] = (int) $count;
				}

				// The row's user_id is left out on purpose: who ran a sweep is
				// personal data and stays in the WordPress admin.
				return [
					'source'     => (string) ( $row['source'] ?? '' ),
					'revisions'  => (int) ( $row['revisions'] ?? 0 ),
					'posts'      => (int) ( $row['posts'] ?? 0 ),
					'post_types' => (object) $clean,
					'status'     => Log::status( $row ),
					'started'    => self::iso( Log::timestamp( (string) ( $row['started_gmt'] ?? '' ) ) ),
					'ended'      => self::iso( Log::timestamp( (string) ( $row['ended_gmt'] ?? '' ) ) ),
				];
			},
			$found['rows']
		);

		return [
			'enabled' => Log::enabled(),
			'total'   => $found['total'],
			'entries' => $entries,
		];
	}

	/**
	 * Ability: revision-retention/run-sweep.
	 *
	 * @return void
	 */
	private function register_run_sweep(): void {
		$this->register(
			'run-sweep',
			[
				'label'               => _x( 'Run a sweep batch', 'ability label', 'revision-retention' ),
				'description'         => _x( 'Deletes old revisions now under the current retention policy, one batch of posts, carrying on from where the current sweep got to; the schedule then works through the rest a minute at a time. Deleted revisions cannot be brought back. Returns how many revisions were removed and whether the sweep reached the end. On a network that keeps the revision policy to itself, only a network administrator may run it. Never returns posts or their titles.', 'ability description', 'revision-retention' ),
				'output_schema'       => $this->object_schema(
					[
						'revisions' => $this->integer_schema( _x( 'Revisions Removed', 'ability schema', 'revision-retention' ), _x( 'Revisions this batch removed.', 'ability schema', 'revision-retention' ), 0 ),
						'posts'     => $this->integer_schema( _x( 'Posts', 'ability schema', 'revision-retention' ), _x( 'Posts this batch removed revisions from.', 'ability schema', 'revision-retention' ), 0 ),
						'checked'   => $this->integer_schema( _x( 'Posts Checked', 'ability schema', 'revision-retention' ), _x( 'Posts this batch looked at.', 'ability schema', 'revision-retention' ), 0 ),
						'finished'  => $this->boolean_schema( _x( 'Finished', 'ability schema', 'revision-retention' ), _x( 'Whether the sweep reached the end of the site; when false the schedule carries on from here.', 'ability schema', 'revision-retention' ) ),
					]
				),
				'execute_callback'    => [ $this, 'run_sweep' ],
				'permission_callback' => [ $this, 'may_run_sweep' ],
				'annotations'         => $this->writes( true, false ),
			]
		);
	}

	/**
	 * Run one real batch, under the same rules as the Sweep now tab.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function run_sweep(): array|WP_Error {
		// Who may sweep was settled in may_run_sweep(), before this runs.
		$result = Scheduler::run_batch( Log::SOURCE_ABILITY, false );

		if ( null === $result ) {
			return new WP_Error(
				'revision_retention_sweep_busy',
				_x( 'Another sweep is running on this site right now, from the schedule, WP-CLI or the settings screen. Wait a minute and try again.', 'ability error', 'revision-retention' ),
				[ 'status' => 409 ]
			);
		}

		return [
			'revisions' => $result->revisions,
			'posts'     => $result->affected,
			'checked'   => $result->posts,
			'finished'  => $result->finished,
		];
	}

	/*
	 * ------------------------------------------------------------------
	 * Shared steps. Extend these instead of repeating them per ability.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Register one ability with the shared defaults: namespace, category,
	 * capability check, an empty-object input schema, annotations and
	 * visibility. Not public and not on REST: confirmed with the user.
	 *
	 * @param lowercase-string     $slug Ability slug (kebab-case, verb first); prefixed with the namespace.
	 * @param array<string, mixed> $args Label, description, schemas, callback and annotations.
	 *
	 * @return void
	 */
	private function register( string $slug, array $args ): void {
		wp_register_ability(
			self::ABILITY_NAMESPACE . '/' . $slug,
			[
				'label'               => $args['label'],
				'description'         => $args['description'],
				'category'            => self::CATEGORY,
				'input_schema'        => $args['input_schema'] ?? $this->input_schema( [] ),
				'output_schema'       => $args['output_schema'],
				'execute_callback'    => $args['execute_callback'],
				'permission_callback' => $args['permission_callback'] ?? [ $this, 'may_run' ],
				'meta'                => [
					'annotations'  => $args['annotations'],
					'public'       => false,
					'show_in_rest' => false,
				],
			]
		);
	}

	/**
	 * Whether the current, logged-in user may run the plugin's abilities.
	 *
	 * @return bool
	 */
	public function may_run(): bool {
		return is_user_logged_in() && current_user_can( self::CAPABILITY );
	}

	/**
	 * Whether the current user may run a real sweep through run-sweep: the
	 * capability, and on a network that keeps the policy to itself, a
	 * network administrator; the same rule as the Sweep now tab.
	 *
	 * @return bool
	 */
	public function may_run_sweep(): bool {
		return $this->may_run() && Settings::may_sweep();
	}

	/**
	 * The log sources an entry can have.
	 *
	 * @return string[]
	 */
	private static function sources(): array {
		return [ Log::SOURCE_CRON, Log::SOURCE_SCREEN, Log::SOURCE_CLI, Log::SOURCE_ABILITY ];
	}

	/**
	 * A Unix timestamp as ISO 8601 in UTC, or '' for none.
	 *
	 * @param int $timestamp Unix timestamp, 0 for none.
	 *
	 * @return string
	 */
	private static function iso( int $timestamp ): string {
		return $timestamp > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) : '';
	}

	/**
	 * Strict input schema: an object, no unknown keys, empty object when no input is given.
	 *
	 * @param array<string, array<string, mixed>> $properties Property schemas.
	 * @param string[]                            $required   Required property names.
	 *
	 * @return array<string, mixed>
	 */
	private function input_schema( array $properties, array $required = [] ): array {
		return $this->object_schema( $properties, $required ) + [ 'default' => [] ];
	}

	/**
	 * Object schema without unknown keys. Leaves out empty `properties` and
	 * `required`, which would encode as JSON arrays.
	 *
	 * @param array<string, array<string, mixed>> $properties Property schemas.
	 * @param string[]                            $required   Required property names.
	 *
	 * @return array<string, mixed>
	 */
	private function object_schema( array $properties, array $required = [] ): array {
		return array_filter(
			[
				'type'                 => 'object',
				'properties'           => $properties,
				'required'             => $required,
				'additionalProperties' => false,
			],
			static fn( $value ): bool => [] !== $value
		);
	}

	/**
	 * List (JSON array) schema.
	 *
	 * @param array<string, mixed> $items Item schema.
	 *
	 * @return array<string, mixed>
	 */
	private function list_schema( array $items ): array {
		return [
			'type'  => 'array',
			'items' => $items,
		];
	}

	/**
	 * String schema with title and description; `enum`, `maxLength` or `format` go in $extra.
	 *
	 * @param string               $title       Title Case, translated.
	 * @param string               $description Translated description.
	 * @param array<string, mixed> $extra       Extra keywords.
	 *
	 * @return array<string, mixed>
	 */
	private function string_schema( string $title, string $description, array $extra = [] ): array {
		return [
			'type'        => 'string',
			'title'       => $title,
			'description' => $description,
		] + $extra;
	}

	/**
	 * Boolean schema with title and description.
	 *
	 * @param string $title       Title Case, translated.
	 * @param string $description Translated description.
	 *
	 * @return array<string, mixed>
	 */
	private function boolean_schema( string $title, string $description ): array {
		return [
			'type'        => 'boolean',
			'title'       => $title,
			'description' => $description,
		];
	}

	/**
	 * Bounded integer schema with title and description.
	 *
	 * @param string   $title       Title Case, translated.
	 * @param string   $description Translated description.
	 * @param int      $minimum     Minimum value.
	 * @param int|null $maximum     Maximum value; always set one for input.
	 *
	 * @return array<string, mixed>
	 */
	private function integer_schema( string $title, string $description, int $minimum, ?int $maximum = null ): array {
		return array_filter(
			[
				'type'        => 'integer',
				'title'       => $title,
				'description' => $description,
				'minimum'     => $minimum,
				'maximum'     => $maximum,
			],
			static fn( $value ): bool => null !== $value
		);
	}

	/**
	 * Annotations for an ability that changes nothing (REST: GET).
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	private function read_only(): array {
		return [
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		];
	}

	/**
	 * Annotations for an ability that changes data.
	 *
	 * @param bool $destructive Deletes or overwrites data.
	 * @param bool $idempotent  Repeating the same call has no further effect.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	private function writes( bool $destructive, bool $idempotent ): array {
		return [
			'readonly'    => false,
			'destructive' => $destructive,
			'idempotent'  => $idempotent,
		];
	}

	/**
	 * Refuse input or output carrying personal data, so it never reaches an
	 * AI client, and tell the person asking why. Hooked to
	 * wp_ability_validate_input and wp_ability_validate_output.
	 *
	 * @param bool|WP_Error $is_valid     Result of the schema validation so far.
	 * @param mixed         $value        Ability input or output.
	 * @param string        $ability_name Ability name.
	 *
	 * @return bool|WP_Error
	 */
	public function guard_personal_data( bool|WP_Error $is_valid, mixed $value, string $ability_name ): bool|WP_Error {
		if ( true !== $is_valid || ! $this->is_guarded( $ability_name ) || ! $this->contains_personal_data( $value ) ) {
			return $is_valid;
		}

		return new WP_Error(
			'revision_retention_personal_data',
			_x( 'This request involves personal data, such as names or e-mail addresses. Sending personal data to an AI service can breach the GDPR, so it is not shared. Please do not ask for personal data through AI; look it up in the WordPress admin instead.', 'ability error', 'revision-retention' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Whether the guard covers this ability: every one of ours, unless approved.
	 *
	 * @param string $ability_name Ability name.
	 *
	 * @return bool
	 */
	private function is_guarded( string $ability_name ): bool {
		return ! in_array( $ability_name, self::approved_for_personal_data(), true )
			&& str_starts_with( $ability_name, self::ABILITY_NAMESPACE . '/' );
	}

	/**
	 * The abilities approved to handle personal data, none so far. Read
	 * through here so the guard keeps checking the list once one is added.
	 *
	 * @return string[]
	 */
	private static function approved_for_personal_data(): array {
		return self::PERSONAL_DATA_APPROVED;
	}

	/**
	 * Whether a value holds a personal-data key, a requested personal-data
	 * field or an e-mail address, at any depth.
	 *
	 * @param mixed $value Value to inspect.
	 *
	 * @return bool
	 */
	private function contains_personal_data( mixed $value ): bool {
		if ( is_string( $value ) ) {
			return (bool) preg_match( '/[^\s@<>]+@[^\s@<>]+\.[a-z]{2,}/i', $value );
		}

		if ( ! is_array( $value ) && ! is_object( $value ) ) {
			return false;
		}

		foreach ( (array) $value as $key => $item ) {
			$name = is_string( $key ) ? $key : ( is_string( $item ) ? $item : '' );

			if ( in_array( strtolower( $name ), self::PERSONAL_DATA_KEYS, true ) || $this->contains_personal_data( $item ) ) {
				return true;
			}
		}

		return false;
	}
}
