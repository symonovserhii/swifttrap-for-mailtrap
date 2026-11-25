<?php
namespace SwiftTrapForMailtrap\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

/**
 * Settings save / token handling (3.1.0).
 */
class SettingsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		swifttrap_mailtrap_flush_settings_cache();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_unticked_checkboxes_are_saved_as_off(): void {
		// The form posts a hidden "0" for each unticked checkbox.
		$saved = swifttrap_mailtrap_sanitize_settings(
			array( 'enabled' => '0', 'enable_categories' => '0', 'auto_categorize' => '0', 'fallback' => '0', 'bulk_autodetect' => '1', 'token' => 'abc' )
		);

		$this->assertSame( 0, $saved['enabled'] );
		$this->assertSame( 0, $saved['enable_categories'] );
		$this->assertSame( 0, $saved['auto_categorize'] );
		$this->assertSame( 0, $saved['fallback'] );
		$this->assertSame( 1, $saved['bulk_autodetect'] );
	}

	public function test_bulk_autodetect_is_off_and_backup_on_by_default(): void {
		$defaults = swifttrap_mailtrap_default_settings();

		$this->assertSame( 0, $defaults['bulk_autodetect'] );
		$this->assertSame( 1, $defaults['fallback'] );
	}

	public function test_empty_token_field_keeps_the_saved_token(): void {
		Monkey\Functions\when( 'get_option' )->justReturn( array( 'token' => 'saved-token-1234' ) );

		$saved = swifttrap_mailtrap_sanitize_settings( array( 'token' => '' ) );

		$this->assertSame( 'saved-token-1234', $saved['token'] );
	}

	public function test_new_token_replaces_and_checkbox_removes(): void {
		Monkey\Functions\when( 'get_option' )->justReturn( array( 'token' => 'saved-token-1234' ) );

		$replaced = swifttrap_mailtrap_sanitize_settings( array( 'token' => "  new-token\n" ) );
		$removed  = swifttrap_mailtrap_sanitize_settings( array( 'token' => '', 'token_clear' => '1' ) );

		$this->assertSame( 'new-token', $replaced['token'] );
		$this->assertSame( '', $removed['token'] );
		$this->assertArrayNotHasKey( 'token_clear', $removed );
	}

	public function test_unknown_keys_and_bad_values_are_dropped(): void {
		$saved = swifttrap_mailtrap_sanitize_settings(
			array(
				'token'                  => 'abc',
				'evil'                   => '<script>',
				'sender_email'           => 'not an email',
				'max_attachment_size_mb' => 900,
				'category_streams'       => array( 'promotional' => 'bulk', 'general' => 'weird' ),
				'category_senders'       => array(
					'welcome' => array( 'name' => '', 'email' => '' ),
					'general' => array( 'name' => 'Team', 'email' => 'team@example.com' ),
					'broken'  => 'string',
				),
			)
		);

		$this->assertArrayNotHasKey( 'evil', $saved );
		$this->assertSame( '', $saved['sender_email'] );
		$this->assertSame( 25, $saved['max_attachment_size_mb'] );
		$this->assertSame( array( 'promotional' => 'bulk', 'general' => 'transactional' ), $saved['category_streams'] );
		$this->assertSame( array( 'general' => array( 'name' => 'Team', 'email' => 'team@example.com' ) ), $saved['category_senders'] );
	}

	public function test_token_is_masked_for_display(): void {
		$masked = swifttrap_mailtrap_mask_token( 'abcdef0123456789wxyz' );

		$this->assertStringEndsWith( 'wxyz', $masked );
		$this->assertStringNotContainsString( 'abcdef', $masked );
		$this->assertStringNotContainsString( 'short', swifttrap_mailtrap_mask_token( 'short' ) );
		$this->assertSame( '', swifttrap_mailtrap_mask_token( '' ) );
	}

	public function test_settings_page_never_prints_the_token(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/admin.php' );

		$this->assertStringNotContainsString( "esc_attr( \$settings['token'] )", $source );
	}

	public function test_settings_cache_is_flushed_when_the_option_changes(): void {
		$calls = 0;
		Monkey\Functions\when( 'get_option' )->alias( function ( $name, $default = false ) use ( &$calls ) {
			if ( 'swifttrap_mailtrap_settings' !== $name ) {
				return 'admin@example.com';
			}
			$calls++;
			return array( 'token' => 'token-' . $calls, 'webhook_secret' => 's' );
		} );

		$this->assertSame( 'token-1', swifttrap_mailtrap_get_settings()['token'] );
		$this->assertSame( 'token-1', swifttrap_mailtrap_get_settings()['token'] );

		swifttrap_mailtrap_flush_settings_cache();

		$this->assertSame( 'token-2', swifttrap_mailtrap_get_settings()['token'] );
	}

	public function test_account_check_explains_a_rejected_token(): void {
		Monkey\Functions\when( 'wp_remote_get' )->justReturn( array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"Incorrect API token"}' ) );

		$res = swifttrap_mailtrap_get_account_data( array( 'token' => 'bad' ) );

		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'swifttrap_account_unauthorized', $res->get_error_code() );
	}
}
