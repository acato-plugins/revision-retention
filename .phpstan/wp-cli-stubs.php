<?php
/**
 * Just enough of WP-CLI for static analysis.
 *
 * The php-stubs/wp-cli-stubs package pins php-stubs/wordpress-stubs to 6.x,
 * which would mean analysing this plugin against an older WordPress than it
 * targets. Only the handful of symbols the CLI command actually uses is
 * declared here instead; nothing in this folder ships or is ever loaded.
 *
 * @package RevisionRetention
 */

// phpcs:ignoreFile

namespace {

	/**
	 * WP-CLI's entry point.
	 */
	class WP_CLI {

		/**
		 * Register a command.
		 *
		 * @param string               $name     Command name.
		 * @param callable|string      $callable Implementation.
		 * @param array<string, mixed> $args     Command arguments.
		 *
		 * @return bool
		 */
		public static function add_command( $name, $callable, $args = array() ) {
			return true;
		}

		/**
		 * Write a line to STDOUT.
		 *
		 * @param string $message Message.
		 *
		 * @return void
		 */
		public static function log( $message ) {}

		/**
		 * Write a success message and exit successfully.
		 *
		 * @param string $message Message.
		 *
		 * @return void
		 */
		public static function success( $message ) {}

		/**
		 * Write a warning.
		 *
		 * @param string $message Message.
		 *
		 * @return void
		 */
		public static function warning( $message ) {}

		/**
		 * Write an error and halt.
		 *
		 * @param string $message Message.
		 *
		 * @return void
		 */
		public static function error( $message ) {}

		/**
		 * Ask for confirmation unless --yes was passed.
		 *
		 * @param string               $question   Question.
		 * @param array<string, mixed> $assoc_args Associative arguments.
		 *
		 * @return void
		 */
		public static function confirm( $question, $assoc_args = array() ) {}
	}
}

namespace WP_CLI\Utils {

	/**
	 * Read a flag, falling back to a default.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string               $flag       Flag name.
	 * @param mixed                $default    Fallback value.
	 *
	 * @return mixed
	 */
	function get_flag_value( $assoc_args, $flag, $default = null ) {
		return $default;
	}

	/**
	 * Render rows in the requested format.
	 *
	 * @param string             $format Output format.
	 * @param array<int, mixed>  $items  Rows.
	 * @param array<int, string> $fields Columns.
	 *
	 * @return void
	 */
	function format_items( $format, $items, $fields ) {}
}
