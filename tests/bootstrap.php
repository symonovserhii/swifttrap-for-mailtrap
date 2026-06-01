<?php
/**
 * PHPUnit Bootstrap for SwiftTrap for Mailtrap unit tests.
 */

// Initialize Patchwork before any function files are loaded to prevent DefinedTooEarly exception.
if ( file_exists( dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';
}

// Define ABSPATH to avoid exit in plugin files.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// Define WordPress constants used in the plugin.
if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1048576 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

// Load Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Stub core WordPress classes (they don't need Patchwork redefinition as they aren't functions).
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
	class WP_Filesystem_Base {
		public function exists( $path ) { return false; }
		public function put_contents( $path, $contents, $mode = false ) { return false; }
		public function get_contents( $path ) { return false; }
		public function is_writable( $path ) { return false; }
		public function rmdir( $path, $recursive = false ) { return false; }
	}
}

// Load core WordPress function stubs through the stream wrapper so Patchwork can intercept them.
require_once __DIR__ . '/wp-stubs.php';

// Load Brain Monkey hook and helper stubs before loading the plugin files.
if ( file_exists( dirname( __DIR__ ) . '/vendor/brain/monkey/inc/wp-hook-functions.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/brain/monkey/inc/wp-hook-functions.php';
}
if ( file_exists( dirname( __DIR__ ) . '/vendor/brain/monkey/inc/wp-helper-functions.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/brain/monkey/inc/wp-helper-functions.php';
}

// Load the plugin code.
require_once dirname( __DIR__ ) . '/swifttrap-for-mailtrap.php';
