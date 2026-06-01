<?php
/**
 * Core WordPress function stubs for unit tests.
 * This file is loaded after Patchwork is initialized so that they can be mocked.
 */

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof \WP_Error;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) {
		echo $text;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = [] ) {
		return array_merge( (array) $defaults, (array) $args );
	}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
	}
}
if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( $path ) {
		return basename( $path );
	}
}
if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( $filename, $mimes = null ) {
		$ext = pathinfo( $filename, PATHINFO_EXTENSION );
		$type = 'application/octet-stream';
		if ( $ext === 'jpg' || $ext === 'jpeg' ) {
			$type = 'image/jpeg';
		} elseif ( $ext === 'png' ) {
			$type = 'image/png';
		}
		return [ 'ext' => $ext, 'type' => $type ];
	}
}
if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) {
		return $bytes . ' B';
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return 'Mock Site';
	}
}
if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $string, $quote_style = ENT_NOQUOTES ) {
		return html_entity_decode( $string, $quote_style );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return $default;
	}
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
	function get_current_blog_id() {
		return 1;
	}
}

// Stub transient helper functions
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		return true;
	}
}

// Stub HTTP retrieval functions
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) {
		if ( is_array( $response ) && isset( $response['headers'][ $header ] ) ) {
			return $response['headers'][ $header ];
		}
		return '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
			return $response['response']['code'];
		}
		return 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		if ( is_array( $response ) && isset( $response['body'] ) ) {
			return $response['body'];
		}
		return '';
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) {
		return new \WP_Error( 'not_implemented', 'Mock me in tests' );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = [] ) {
		return new \WP_Error( 'not_implemented', 'Mock me in tests' );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		return date( 'Y-m-d H:i:s' );
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $format, $timestamp = null, $timezone = null ) {
		return date( $format, $timestamp ?? time() );
	}
}
