<?php
/**
 * Stored settings, and how a site's effective settings are resolved.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's options.
 *
 * On a single site install there is one option and it is the policy. On
 * multisite there are two: the network sets the defaults, and every site may
 * override them field by field when the network allows it. A site field left
 * empty inherits, which is why the stored site values are nullable.
 */
class Settings {

	/**
	 * Option holding a single site's settings, or its overrides on multisite.
	 *
	 * @var string
	 */
	public const OPTION = 'rvrt_settings';

	/**
	 * Site option holding the network wide defaults.
	 *
	 * @var string
	 */
	public const NETWORK_OPTION = 'rvrt_network_settings';

	/**
	 * How often a full sweep may be scheduled.
	 *
	 * @var array<string, int>
	 */
	private const INTERVALS = array(
		'hourly'  => HOUR_IN_SECONDS,
		'daily'   => DAY_IN_SECONDS,
		'weekly'  => WEEK_IN_SECONDS,
		'monthly' => MONTH_IN_SECONDS,
	);

	/**
	 * Smallest and largest number of posts one batch may work through.
	 *
	 * @var int
	 */
	private const MIN_BATCH_SIZE = 10;

	/**
	 * Upper bound on the batch size.
	 *
	 * @var int
	 */
	private const MAX_BATCH_SIZE = 5000;

	/**
	 * Every setting and the value it falls back to.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'keep'                     => 5,
			'max_age_days'             => 365,
			'post_types'               => array(),
			'enable_revisions'         => array(),
			'cron_enabled'             => true,
			'cron_interval'            => 'daily',
			'batch_size'               => 200,
			'remove_data_on_uninstall' => false,
		);
	}

	/**
	 * The network defaults, which add one setting of their own.
	 *
	 * @return array<string, mixed>
	 */
	public static function network_defaults(): array {
		return self::defaults() + array( 'allow_site_override' => true );
	}

	/**
	 * The keys a site may override, and the type each one holds.
	 *
	 * `enable_revisions` is deliberately absent: it is merged as a union rather
	 * than overridden, so a network can switch revisions on everywhere without
	 * stopping a site from adding post types of its own.
	 *
	 * @return array<string, string>
	 */
	public static function overridable(): array {
		return array(
			'keep'                     => 'int',
			'max_age_days'             => 'int',
			'cron_enabled'             => 'bool',
			'cron_interval'            => 'enum',
			'batch_size'               => 'int',
			'remove_data_on_uninstall' => 'bool',
		);
	}

	/**
	 * The intervals a full sweep can be scheduled at, labelled for the screen.
	 *
	 * @return array<string, string>
	 */
	public static function intervals(): array {
		return array(
			'hourly'  => __( 'Every hour', 'revision-retention' ),
			'daily'   => __( 'Once a day', 'revision-retention' ),
			'weekly'  => __( 'Once a week', 'revision-retention' ),
			'monthly' => __( 'Once a month', 'revision-retention' ),
		);
	}

	/**
	 * Seconds between two full sweeps, for the interval currently configured.
	 *
	 * @return int
	 */
	public static function interval_seconds(): int {
		$interval = (string) self::get( 'cron_interval' );

		return self::INTERVALS[ $interval ] ?? DAY_IN_SECONDS;
	}

	/**
	 * The network defaults as stored, filled out with the fallbacks.
	 *
	 * @return array<string, mixed>
	 */
	public static function network(): array {
		$stored = is_multisite() ? get_site_option( self::NETWORK_OPTION, array() ) : array();

		return self::fill( is_array( $stored ) ? $stored : array(), self::network_defaults() );
	}

	/**
	 * This site's own stored values, untouched.
	 *
	 * On multisite a null means "inherit from the network", so this array is
	 * only useful for rendering the override form. Everything else should read
	 * from self::resolved().
	 *
	 * @return array<string, mixed>
	 */
	public static function site(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether the network lets its sites override the defaults.
	 *
	 * @return bool
	 */
	public static function allows_site_override(): bool {
		if ( ! is_multisite() ) {
			return true;
		}

		return ! empty( self::network()['allow_site_override'] );
	}

	/**
	 * The settings actually in effect on this site.
	 *
	 * @return array<string, mixed>
	 */
	public static function resolved(): array {
		if ( ! is_multisite() ) {
			return self::fill( self::site(), self::defaults() );
		}

		$network  = self::network();
		$resolved = self::fill( array(), $network );

		if ( ! self::allows_site_override() ) {
			return $resolved;
		}

		$site = self::site();

		foreach ( array_keys( self::overridable() ) as $key ) {
			if ( isset( $site[ $key ] ) ) {
				$resolved[ $key ] = $site[ $key ];
			}
		}

		$resolved['post_types']       = self::merge_post_types(
			is_array( $network['post_types'] ) ? $network['post_types'] : array(),
			isset( $site['post_types'] ) && is_array( $site['post_types'] ) ? $site['post_types'] : array()
		);
		$resolved['enable_revisions'] = array_values(
			array_unique(
				array_merge(
					is_array( $network['enable_revisions'] ) ? $network['enable_revisions'] : array(),
					isset( $site['enable_revisions'] ) && is_array( $site['enable_revisions'] ) ? $site['enable_revisions'] : array()
				)
			)
		);

		return $resolved;
	}

	/**
	 * Read one effective setting.
	 *
	 * @param string $key Key of the setting, see self::defaults().
	 *
	 * @return mixed
	 */
	public static function get( string $key ) {
		$resolved = self::resolved();

		return $resolved[ $key ] ?? null;
	}

	/**
	 * Store this site's settings.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 *
	 * @return void
	 */
	public static function update_site( array $settings ): void {
		update_option( self::OPTION, $settings );
	}

	/**
	 * Store the network defaults.
	 *
	 * @param array<string, mixed> $settings Sanitized settings.
	 *
	 * @return void
	 */
	public static function update_network( array $settings ): void {
		update_site_option( self::NETWORK_OPTION, $settings );
	}

	/**
	 * Capability required to change the settings on the current screen.
	 *
	 * @param bool $network Whether the network screen is meant.
	 *
	 * @return string
	 */
	public static function capability( bool $network = false ): string {
		return $network ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Reduce submitted input to the known settings.
	 *
	 * Values are nullable only where they can be inherited, which is the site
	 * override form on a multisite install. Anywhere else an empty field falls
	 * back to the default rather than to null.
	 *
	 * @param mixed $input      Raw submitted value.
	 * @param bool  $is_network Whether the network screen was submitted.
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input, bool $is_network = false ): array {
		$input     = is_array( $input ) ? $input : array();
		$defaults  = $is_network ? self::network_defaults() : self::defaults();
		$nullable  = is_multisite() && ! $is_network;
		$sanitized = array();

		foreach ( self::overridable() as $key => $type ) {
			$raw = $input[ $key ] ?? null;

			if ( $nullable && ( null === $raw || '' === $raw ) ) {
				continue;
			}

			$sanitized[ $key ] = match ( $type ) {
				'bool' => (bool) $raw,
				'enum' => self::sanitize_interval( $raw, (string) $defaults[ $key ] ),
				default => self::sanitize_number( $key, $raw, (int) $defaults[ $key ] ),
			};
		}

		$sanitized['post_types']       = self::sanitize_post_types( $input['post_types'] ?? null );
		$sanitized['enable_revisions'] = self::sanitize_post_type_list( $input['enable_revisions'] ?? null );

		if ( $is_network ) {
			$sanitized['allow_site_override'] = ! empty( $input['allow_site_override'] );
		}

		return $sanitized;
	}

	/**
	 * Clamp one of the numeric settings to the range it allows.
	 *
	 * @param string $key      Which setting is being sanitized.
	 * @param mixed  $value    Raw submitted value.
	 * @param int    $fallback Value to use when nothing usable was submitted.
	 *
	 * @return int
	 */
	private static function sanitize_number( string $key, $value, int $fallback ): int {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return $fallback;
		}

		$number = (int) $value;

		return match ( $key ) {
			// Anything below zero means the same thing, so it is normalised.
			'keep' => max( Retention_Rule::UNLIMITED, $number ),
			'batch_size' => min( self::MAX_BATCH_SIZE, max( self::MIN_BATCH_SIZE, $number ) ),
			default => max( 0, $number ),
		};
	}

	/**
	 * Keep the interval to one this plugin knows how to schedule.
	 *
	 * @param mixed  $value    Raw submitted value.
	 * @param string $fallback Value to use when nothing usable was submitted.
	 *
	 * @return string
	 */
	private static function sanitize_interval( $value, string $fallback ): string {
		$value = is_string( $value ) ? $value : '';

		return isset( self::INTERVALS[ $value ] ) ? $value : $fallback;
	}

	/**
	 * Reduce the per post type overrides to registered post types and numbers.
	 *
	 * @param mixed $input Raw submitted value.
	 *
	 * @return array<string, array<string, int>>
	 */
	private static function sanitize_post_types( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$eligible  = Post_Types::eligible();
		$sanitized = array();

		foreach ( $input as $post_type => $values ) {
			$post_type = (string) $post_type;

			if ( ! isset( $eligible[ $post_type ] ) || ! is_array( $values ) ) {
				continue;
			}

			$rule = array();

			foreach ( array( 'keep', 'max_age_days' ) as $key ) {
				$raw = $values[ $key ] ?? null;

				// A per post type field is always inheritable: left empty it
				// falls back to the site or network wide number.
				if ( null === $raw || '' === $raw || ! is_numeric( $raw ) ) {
					continue;
				}

				$rule[ $key ] = self::sanitize_number( $key, $raw, 0 );
			}

			if ( array() !== $rule ) {
				$sanitized[ $post_type ] = $rule;
			}
		}

		return $sanitized;
	}

	/**
	 * Reduce a submitted list of post types to registered, eligible ones.
	 *
	 * @param mixed $input Raw submitted value.
	 *
	 * @return array<int, string>
	 */
	private static function sanitize_post_type_list( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$eligible = Post_Types::eligible();
		$list     = array();

		foreach ( $input as $post_type ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( isset( $eligible[ $post_type ] ) ) {
				$list[] = $post_type;
			}
		}

		return array_values( array_unique( $list ) );
	}

	/**
	 * Lay stored values over a set of defaults, ignoring unknown keys.
	 *
	 * @param array<string, mixed> $stored   Stored values.
	 * @param array<string, mixed> $defaults Defaults to fall back to.
	 *
	 * @return array<string, mixed>
	 */
	private static function fill( array $stored, array $defaults ): array {
		$filled = $defaults;

		foreach ( $defaults as $key => $default ) {
			if ( ! isset( $stored[ $key ] ) ) {
				continue;
			}

			$filled[ $key ] = is_array( $default ) && ! is_array( $stored[ $key ] ) ? $default : $stored[ $key ];
		}

		return $filled;
	}

	/**
	 * Lay a site's per post type overrides over the network's.
	 *
	 * @param array<string, mixed> $network Network per post type rules.
	 * @param array<string, mixed> $site    Site per post type rules.
	 *
	 * @return array<string, array<string, int>>
	 */
	private static function merge_post_types( array $network, array $site ): array {
		$merged = array();

		foreach ( array_keys( $network + $site ) as $post_type ) {
			$rule = array();

			if ( isset( $network[ $post_type ] ) && is_array( $network[ $post_type ] ) ) {
				$rule = $network[ $post_type ];
			}

			if ( isset( $site[ $post_type ] ) && is_array( $site[ $post_type ] ) ) {
				$rule = array_merge( $rule, $site[ $post_type ] );
			}

			if ( array() !== $rule ) {
				$merged[ (string) $post_type ] = $rule;
			}
		}

		return $merged;
	}
}
