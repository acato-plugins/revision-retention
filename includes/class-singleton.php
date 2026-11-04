<?php
/**
 * Base for the classes the plugin needs exactly one of.
 *
 * @package RevisionRetention
 */

declare( strict_types=1 );

namespace Acato\RevisionRetention\Includes;

defined( 'ABSPATH' ) || exit;

/**
 * One instance per class, created on first use, which then hooks itself in.
 *
 * @author Paul van Impelen <paul@acato.nl>
 */
abstract class Singleton {

	/**
	 * The instance of each class, keyed by class name.
	 *
	 * @var array<class-string, Singleton>
	 */
	private static array $instances = [];

	/**
	 * The single instance of the called class, hooked into WordPress on first use.
	 *
	 * @return static
	 */
	final public static function instance(): static {
		$class = static::class;

		if ( ! isset( self::$instances[ $class ] ) ) {
			$instance                  = new static();
			self::$instances[ $class ] = $instance;

			$instance->init();
		}

		/**
		 * The instance stored under this class's own name.
		 *
		 * @var static
		 */
		return self::$instances[ $class ];
	}

	/**
	 * Hook the class into WordPress.
	 *
	 * @return void
	 */
	protected function init(): void {}

	/**
	 * Use static::instance() instead.
	 */
	final protected function __construct() {}

	/**
	 * There is only ever one.
	 */
	private function __clone() {}
}
