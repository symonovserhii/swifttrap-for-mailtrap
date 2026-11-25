<?php
namespace SwiftTrapForMailtrap\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use WP_Error;
use Mockery;

/**
 * Send pipeline behaviour fixed or added in 3.1.0.
 */
class SendPipelineTest extends TestCase {
	private array $settings = array(
		'token'             => 'test_token',
		'enabled'           => 1,
		'sender_email'      => 'site@example.com',
		'sender_name'       => 'Site',
		'enable_categories' => 0,
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		swifttrap_mailtrap_flush_settings_cache();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function atts( array $over = array() ): array {
		return array_merge(
			array(
				'to'          => 'user@example.com',
				'subject'     => 'Hello',
				'message'     => 'Body',
				'headers'     => array(),
				'attachments' => array(),
			),
			$over
		);
	}

	public function test_invalid_recipient_makes_wp_mail_return_false_and_reports_the_mail(): void {
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $this->settings );

		Monkey\Actions\expectDone( 'wp_mail_failed' )
			->once()
			->with( Mockery::on( function ( $error ) {
				$data = $error->get_error_data();
				return 'swifttrap_invalid_recipient' === $error->get_error_code()
					&& 'not-an-address' === $data['to']
					&& 'Hello' === $data['subject'];
			} ) );

		$this->assertFalse( swifttrap_mailtrap_pre_wp_mail( null, $this->atts( array( 'to' => 'not-an-address' ) ) ) );
	}

	public function test_a_message_already_handled_by_another_plugin_is_left_alone(): void {
		Monkey\Functions\expect( 'swifttrap_mailtrap_send' )->never();

		$this->assertTrue( swifttrap_mailtrap_pre_wp_mail( true, $this->atts() ) );
		$this->assertFalse( swifttrap_mailtrap_pre_wp_mail( false, $this->atts() ) );
	}

	public function test_no_fallback_when_backup_delivery_is_off(): void {
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $this->settings + array( 'fallback' => 0 ) );
		Monkey\Functions\when( 'swifttrap_mailtrap_is_recipient_suppressed' )->justReturn( false );
		Monkey\Functions\when( 'swifttrap_mailtrap_send' )->justReturn( new WP_Error( 'swifttrap_api_error', 'HTTP 401' ) );

		$this->assertFalse( swifttrap_mailtrap_pre_wp_mail( null, $this->atts() ) );
	}

	public function test_blocked_bulk_mail_never_falls_back_to_the_default_mailer(): void {
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $this->settings + array( 'fallback' => 1 ) );
		Monkey\Functions\when( 'swifttrap_mailtrap_is_recipient_suppressed' )->justReturn( false );
		Monkey\Functions\when( 'swifttrap_mailtrap_send' )->justReturn( new WP_Error( 'swifttrap_bulk_blocked', 'blocked' ) );

		$this->assertFalse( swifttrap_mailtrap_pre_wp_mail( null, $this->atts() ) );
	}

	public function test_newsletter_campaign_goes_to_the_bulk_host_and_suppression_uses_that_stream(): void {
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $this->settings + array( 'bulk_autodetect' => 1 ) );

		Monkey\Functions\expect( 'swifttrap_mailtrap_is_recipient_suppressed' )
			->once()
			->with( 'user@example.com', Mockery::any(), 'bulk' )
			->andReturn( false );

		$url = null;
		Monkey\Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing( function ( $host, $args ) use ( &$url ) {
				$url = $host;
				return array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true}' );
			} );
		Monkey\Functions\when( 'swifttrap_mailtrap_review_record_success' )->justReturn( null );

		$res = swifttrap_mailtrap_pre_wp_mail(
			null,
			$this->atts( array( 'headers' => array( 'X-Newsletter-Email-Id: 12', 'Precedence: bulk' ) ) )
		);

		$this->assertTrue( $res );
		$this->assertSame( 'https://bulk.api.mailtrap.io/api/send', $url );
	}

	public function test_ordinary_mail_goes_to_the_transactional_host(): void {
		$url = null;
		Monkey\Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing( function ( $host ) use ( &$url ) {
				$url = $host;
				return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
			} );

		$normalized = swifttrap_mailtrap_normalize_atts( $this->atts(), $this->settings );
		$this->assertNotInstanceOf( WP_Error::class, $normalized );

		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $this->settings );
		$this->assertTrue( swifttrap_mailtrap_send( $normalized, $this->settings + array( 'bulk_autodetect' => 1 ) ) );
		$this->assertSame( 'https://send.api.mailtrap.io/api/send', $url );
	}

	public function test_api_error_message_explains_the_reason_without_the_token(): void {
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $this->settings );
		Monkey\Functions\when( 'wp_remote_post' )->justReturn(
			array( 'response' => array( 'code' => 401 ), 'body' => '{"success":false,"errors":["Unauthorized"]}' )
		);

		$normalized = swifttrap_mailtrap_normalize_atts( $this->atts(), $this->settings );
		$res        = swifttrap_mailtrap_send( $normalized, $this->settings );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'Mailtrap API returned HTTP 401: Unauthorized', $res->get_error_message() );
		$this->assertStringNotContainsString( 'test_token', $res->get_error_message() );
	}

	public function test_api_error_detail_shapes(): void {
		$this->assertSame( 'a; b', swifttrap_mailtrap_api_error_detail( '{"errors":["a","b"]}' ) );
		$this->assertSame( 'Incorrect API token', swifttrap_mailtrap_api_error_detail( '{"error":"Incorrect API token"}' ) );
		$this->assertSame( "'from' is invalid", swifttrap_mailtrap_api_error_detail( '{"errors":{"from":["\'from\' is invalid"]}}' ) );
		$this->assertSame( '', swifttrap_mailtrap_api_error_detail( '<html>Bad gateway</html>' ) );
		$this->assertLessThanOrEqual( 300, strlen( swifttrap_mailtrap_api_error_detail( json_encode( array( 'errors' => array( str_repeat( 'x', 900 ) ) ) ) ) ) );
	}

	public function test_from_filters_run_for_each_message(): void {
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $this->settings );

		Monkey\Filters\expectApplied( 'wp_mail_from' )->once()->with( 'site@example.com' )->andReturn( 'shop@example.com' );
		Monkey\Filters\expectApplied( 'wp_mail_from_name' )->once()->with( 'Site' )->andReturn( 'Shop' );

		$normalized = swifttrap_mailtrap_normalize_atts( $this->atts(), $this->settings );

		$this->assertSame( 'shop@example.com', $normalized['from_email'] );
		$this->assertSame( 'Shop', $normalized['from_name'] );
	}

	public function test_sender_priority_setting_category_header(): void {
		$settings = $this->settings + array( 'category_senders' => array( 'general' => array( 'email' => 'cat@example.com', 'name' => 'Cat' ) ) );
		$settings['enable_categories'] = 1;
		$settings['auto_categorize']   = 0;
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $settings );

		$by_category = swifttrap_mailtrap_normalize_atts( $this->atts(), $settings );
		$by_header   = swifttrap_mailtrap_normalize_atts( $this->atts( array( 'headers' => 'From: "Form" <form@example.com>' ) ), $settings );

		$this->assertSame( 'cat@example.com', $by_category['from_email'] );
		$this->assertSame( 'Cat', $by_category['from_name'] );
		$this->assertSame( 'form@example.com', $by_header['from_email'] );
		$this->assertSame( 'Form', $by_header['from_name'] );
	}

	public function test_declared_charset_is_converted_to_utf8(): void {
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $this->settings );

		$latin1     = mb_convert_encoding( 'Grüße aus Köln', 'ISO-8859-1', 'UTF-8' );
		$normalized = swifttrap_mailtrap_normalize_atts(
			$this->atts( array( 'subject' => $latin1, 'message' => $latin1, 'headers' => array( 'Content-Type: text/plain; charset="ISO-8859-1"' ) ) ),
			$this->settings
		);

		$this->assertSame( 'Grüße aus Köln', $normalized['subject'] );
		$this->assertSame( 'Grüße aus Köln', $normalized['message'] );
		$this->assertNotFalse( json_encode( $normalized['message'] ) );
	}

	public function test_broken_bytes_never_break_the_json_body(): void {
		Monkey\Functions\when( 'get_bloginfo' )->justReturn( 'UTF-8' );

		$this->assertNotFalse( json_encode( swifttrap_mailtrap_to_utf8( "ok \xC3\x28 end" ) ) );
		$this->assertSame( 'Zażółć', swifttrap_mailtrap_to_utf8( 'Zażółć', 'ISO-8859-2' ) );
	}

	public function test_named_attachment_and_inline_image(): void {
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $this->settings );

		$file = tempnam( sys_get_temp_dir(), 'swt' );
		file_put_contents( $file, 'PNGDATA' );

		$fs = Mockery::mock( 'WP_Filesystem_Base' );
		$fs->shouldReceive( 'get_contents' )->with( $file )->andReturn( 'PNGDATA' );
		$GLOBALS['wp_filesystem'] = $fs;

		$normalized = swifttrap_mailtrap_normalize_atts(
			$this->atts( array( 'attachments' => array( 'invoice.png' => $file ), 'embeds' => array( 'logo' => $file, 'gone' => '/no/such/file.png' ) ) ),
			$this->settings
		);
		unlink( $file );
		unset( $GLOBALS['wp_filesystem'] );

		$this->assertNotInstanceOf( WP_Error::class, $normalized );

		Monkey\Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		$payload = swifttrap_mailtrap_build_payload( $normalized, '' );

		$this->assertCount( 2, $payload['attachments'] );
		$this->assertSame( 'invoice.png', $payload['attachments'][0]['filename'] );
		$this->assertSame( 'image/png', $payload['attachments'][0]['type'] );
		$this->assertSame( 'attachment', $payload['attachments'][0]['disposition'] );
		$this->assertSame( 'inline', $payload['attachments'][1]['disposition'] );
		$this->assertSame( 'logo', $payload['attachments'][1]['content_id'] );
	}

	public function test_missing_attachment_error_hides_the_server_path(): void {
		$res = swifttrap_mailtrap_normalize_attachments( array( '/var/www/secret/uploads/missing.pdf' ), 25 );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertStringNotContainsString( '/var/www', $res->get_error_message() );
		$this->assertStringContainsString( 'missing.pdf', $res->get_error_message() );
	}

	public function test_bulk_unsubscribe_does_not_block_transactional_mail(): void {
		$list = array(
			'items' => array(
				array( 'email' => 'Reader@example.com', 'stream' => 'bulk' ),
				array( 'email' => 'bounced@example.com', 'stream' => '' ),
			),
		);
		Monkey\Functions\when( 'swifttrap_mailtrap_fetch_suppressions' )->justReturn( $list );

		$this->assertFalse( swifttrap_mailtrap_is_recipient_suppressed( 'reader@example.com', array( 'token' => 't' ), 'transactional' ) );
		$this->assertTrue( swifttrap_mailtrap_is_recipient_suppressed( 'reader@example.com', array( 'token' => 't' ), 'bulk' ) );
		$this->assertTrue( swifttrap_mailtrap_is_recipient_suppressed( 'reader@example.com', array( 'token' => 't' ) ) );
		$this->assertTrue( swifttrap_mailtrap_is_recipient_suppressed( 'bounced@example.com', array( 'token' => 't' ), 'transactional' ) );
	}

	public function test_suppression_check_backs_off_when_the_api_is_down(): void {
		Monkey\Functions\expect( 'swifttrap_mailtrap_fetch_suppressions' )->once()->andReturn( new WP_Error( 'x', 'down' ) );
		Monkey\Functions\expect( 'get_transient' )->twice()->andReturn( false, 1 );
		Monkey\Functions\expect( 'set_transient' )->once()->with( Mockery::pattern( '/^swifttrap_suppressions_down_/' ), 1, 300 );

		$this->assertFalse( swifttrap_mailtrap_is_recipient_suppressed( 'a@example.com', array( 'token' => 't' ) ) );
		$this->assertFalse( swifttrap_mailtrap_is_recipient_suppressed( 'b@example.com', array( 'token' => 't' ) ) );
	}

	public function test_test_email_reports_mailtrap_refusal_even_if_backup_delivery_worked(): void {
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $this->settings );

		$listener = null;
		Monkey\Actions\expectAdded( 'wp_mail_failed' )->once()->whenHappen( function ( $callback ) use ( &$listener ) {
			$listener = $callback;
		} );
		Monkey\Functions\expect( 'wp_mail' )->once()->andReturnUsing( function () use ( &$listener ) {
			$listener( new WP_Error( 'swifttrap_api_error', 'Mailtrap API returned HTTP 401: Unauthorized' ) );
			return true; // The default mailer delivered it.
		} );

		$res = swifttrap_mailtrap_send_test_email( 'me@example.com' );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'swifttrap_api_error', $res->get_error_code() );
	}

	public function test_test_email_needs_a_valid_address_and_a_token(): void {
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $this->settings );
		$this->assertSame( 'swifttrap_invalid_recipient', swifttrap_mailtrap_send_test_email( 'nope' )->get_error_code() );

		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( array( 'enabled' => 1, 'token' => '' ) );
		$this->assertSame( 'swifttrap_not_ready', swifttrap_mailtrap_send_test_email( 'me@example.com' )->get_error_code() );
	}
}
