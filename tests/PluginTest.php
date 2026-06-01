<?php
namespace SwiftTrapForMailtrap\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use WP_Error;
use Mockery;

class PluginTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test recipient parsing logic.
	 */
	public function test_parse_recipients() {
		// Single plain email
		$res = swifttrap_mailtrap_parse_recipients( 'user@example.com' );
		$this->assertIsArray( $res );
		$this->assertCount( 1, $res );
		$this->assertEquals( 'user@example.com', $res[0]['email'] );
		$this->assertArrayNotHasKey( 'name', $res[0] );

		// Single email with name
		$res = swifttrap_mailtrap_parse_recipients( 'John Doe <john@example.com>' );
		$this->assertIsArray( $res );
		$this->assertCount( 1, $res );
		$this->assertEquals( 'john@example.com', $res[0]['email'] );
		$this->assertEquals( 'John Doe', $res[0]['name'] );

		// Comma-separated emails
		$res = swifttrap_mailtrap_parse_recipients( [ 'user1@example.com', 'Jane <jane@example.com>' ] );
		$this->assertCount( 2, $res );
		$this->assertEquals( 'user1@example.com', $res[0]['email'] );
		$this->assertEquals( 'jane@example.com', $res[1]['email'] );
		$this->assertEquals( 'Jane', $res[1]['name'] );

		// Invalid email address
		$res = swifttrap_mailtrap_parse_recipients( 'invalid-email' );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertEquals( 'swifttrap_invalid_recipient', $res->get_error_code() );
	}

	/**
	 * Test attachment normalization.
	 */
	public function test_normalize_attachments() {
		// Empty attachments
		$res = swifttrap_mailtrap_normalize_attachments( [] );
		$this->assertEquals( [], $res );

		// Non-existent attachment file
		$res = swifttrap_mailtrap_normalize_attachments( [ '/path/to/non-existent.txt' ] );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertEquals( 'swifttrap_missing_attachment', $res->get_error_code() );

		// Fake a file path using a temporary file
		$temp_file = tempnam( sys_get_temp_dir(), 'mailtrap_test_' );
		file_put_contents( $temp_file, 'Test Attachment Content' );

		// Setup filesystem mock
		$fs = Mockery::mock( 'WP_Filesystem_Base' );
		$fs->shouldReceive( 'get_contents' )
			->with( $temp_file )
			->andReturn( 'Test Attachment Content' );
		$GLOBALS['wp_filesystem'] = $fs;

		// Mock file_exists and is_readable? Since PHP's file_exists works on temp_file, we don't need to mock PHP built-ins.
		$res = swifttrap_mailtrap_normalize_attachments( [ $temp_file ] );
		$this->assertIsArray( $res );
		$this->assertCount( 1, $res );
		$this->assertEquals( basename( $temp_file ), $res[0]['filename'] );
		$this->assertEquals( base64_encode( 'Test Attachment Content' ), $res[0]['content'] );

		unlink( $temp_file );
	}

	/**
	 * Test building the API payload.
	 */
	public function test_build_payload() {
		$normalized = [
			'to'           => [ [ 'email' => 'to@example.com', 'name' => 'To User' ] ],
			'cc'           => [ [ 'email' => 'cc@example.com' ] ],
			'bcc'          => [],
			'reply_to'     => [ [ 'email' => 'reply@example.com', 'name' => 'Reply' ] ],
			'subject'      => 'Test Subject',
			'message'      => '<p>Hello HTML</p>',
			'headers'      => [ 'X-Custom' => 'Value' ],
			'content_type' => 'text/html',
			'attachments'  => [],
			'from_email'   => 'from@example.com',
			'from_name'    => 'From User',
		];

		Monkey\Functions\expect( 'wp_strip_all_tags' )
			->once()
			->with( '<p>Hello HTML</p>' )
			->andReturn( 'Hello HTML' );

		$payload = swifttrap_mailtrap_build_payload( $normalized, 'welcome' );

		$this->assertEquals( 'from@example.com', $payload['from']['email'] );
		$this->assertEquals( 'From User', $payload['from']['name'] );
		$this->assertEquals( 'Test Subject', $payload['subject'] );
		$this->assertEquals( '<p>Hello HTML</p>', $payload['html'] );
		$this->assertEquals( 'Hello HTML', $payload['text'] );
		$this->assertEquals( 'welcome', $payload['category'] );
		$this->assertEquals( 'cc@example.com', $payload['cc'][0]['email'] );
		$this->assertEquals( 'Reply <reply@example.com>', $payload['headers']['Reply-To'] );
		$this->assertEquals( 'Value', $payload['headers']['X-Custom'] );
	}

	/**
	 * Test bulk stream routing logic.
	 */
	public function test_should_use_bulk_stream() {
		// Default filters
		Monkey\Filters\expectApplied( 'swifttrap_mailtrap_use_bulk_stream' )
			->twice()
			->andReturnFirstArg();

		// Promotional -> true
		$this->assertTrue( swifttrap_mailtrap_should_use_bulk_stream( 'promotional', [] ) );

		// Transactional -> false
		$this->assertFalse( swifttrap_mailtrap_should_use_bulk_stream( 'transactional', [] ) );
	}

	/**
	 * Test email category detection from subject line.
	 */
	public function test_detect_email_category() {
		$data = [ 'subject' => 'Please confirm your email', 'message' => '' ];
		$this->assertEquals( 'verification', swifttrap_mailtrap_detect_email_category( $data ) );

		$data = [ 'subject' => 'Reset Password Link', 'message' => '' ];
		$this->assertEquals( 'password-reset', swifttrap_mailtrap_detect_email_category( $data ) );

		$data = [ 'subject' => 'Welcome to our platform!', 'message' => '' ];
		$this->assertEquals( 'welcome', swifttrap_mailtrap_detect_email_category( $data ) );

		$data = [ 'subject' => 'Invoice #12345', 'message' => '' ];
		$this->assertEquals( 'transactional', swifttrap_mailtrap_detect_email_category( $data ) );

		$data = [ 'subject' => 'Daily Newsletter', 'message' => '' ];
		$this->assertEquals( 'promotional', swifttrap_mailtrap_detect_email_category( $data ) );

		$data = [ 'subject' => 'Random Hello', 'message' => '' ];
		$this->assertEquals( 'general', swifttrap_mailtrap_detect_email_category( $data ) );
	}

	/**
	 * Test log parsing resilience to JSON errors.
	 */
	public function test_log_json_error_resilience() {
		// Mock log file retrieval
		Monkey\Functions\expect( 'swifttrap_mailtrap_get_log_file' )
			->andReturn( '/dummy/path.log' );

		// Setup filesystem with corrupted/invalid JSON line + valid JSON line
		$invalid_line = "{invalid_json_here}\n";
		$valid_line   = json_encode( [
			'timestamp' => '2026-05-31 12:00:00',
			'status'    => 'success',
			'to'        => [ [ 'email' => 'test@example.com' ] ],
			'from'      => 'from@example.com',
			'subject'   => 'Valid',
		] ) . "\n";

		$fs = Mockery::mock( 'WP_Filesystem_Base' );
		$fs->shouldReceive( 'exists' )->andReturn( true );
		$fs->shouldReceive( 'is_readable' )->andReturn( true );
		$fs->shouldReceive( 'get_contents' )->andReturn( $invalid_line . $valid_line );

		$GLOBALS['wp_filesystem'] = $fs;

		// Verify read_email_logs skips corrupted lines and successfully parses valid ones
		$res = swifttrap_mailtrap_read_email_logs( 20, 0 );
		$this->assertCount( 1, $res['entries'] );
		$this->assertEquals( 'Valid', $res['entries'][0]['subject'] );

		// Verify compute_log_stats handles corrupted lines gracefully
		Monkey\Functions\expect( 'wp_date' )->andReturn( '2026-05-31' );

		$stats = swifttrap_mailtrap_compute_log_stats( 7 );
		$this->assertEquals( 1, $stats['total_sent'] );
		$this->assertEquals( 1, $stats['total_success'] );
	}

	/**
	 * Test sending retry/backoff on network errors or timeouts.
	 */
	public function test_send_retry_timeout() {
		$normalized = [
			'to'           => [ [ 'email' => 'to@example.com' ] ],
			'from_email'   => 'from@example.com',
			'from_name'    => 'Sender',
			'subject'      => 'Retry Test',
			'message'      => 'Hello',
			'content_type' => 'text/plain',
		];
		$settings = [
			'token'   => 'test_token',
			'enabled' => 1,
		];

		// Mock helper functions inside send
		Monkey\Functions\expect( 'swifttrap_mailtrap_get_email_category' )->andReturn( 'general' );
		Monkey\Functions\expect( 'swifttrap_mailtrap_should_use_bulk_stream' )->andReturn( false );
		Monkey\Functions\expect( 'swifttrap_mailtrap_build_payload' )->andReturn( [] );
		Monkey\Functions\expect( 'wp_json_encode' )->andReturn( '{}' );
		Monkey\Functions\expect( 'swifttrap_mailtrap_log_email' )->andReturnNull();

		// Mock remote post to fail twice on timeout, then succeed on 3rd attempt
		Monkey\Functions\expect( 'wp_remote_post' )
			->times( 3 )
			->andReturn(
				new WP_Error( 'http_request_failed', 'Connection timed out' ),
				new WP_Error( 'http_request_failed', 'Connection timed out' ),
				[ 'response' => [ 'code' => 200 ], 'body' => '{"success":true}' ]
			);

		Monkey\Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		Monkey\Functions\expect( 'wp_remote_retrieve_body' )->andReturn( '{"success":true}' );

		$res = swifttrap_mailtrap_send( $normalized, $settings );
		$this->assertTrue( $res );
	}

	/**
	 * Test sending failure after maximum retries are exhausted.
	 */
	public function test_send_exhaust_retries() {
		$normalized = [
			'to'           => [ [ 'email' => 'to@example.com' ] ],
			'from_email'   => 'from@example.com',
			'from_name'    => 'Sender',
			'subject'      => 'Retry Exhaust Test',
			'message'      => 'Hello',
			'content_type' => 'text/plain',
		];
		$settings = [
			'token'   => 'test_token',
			'enabled' => 1,
		];

		Monkey\Functions\expect( 'swifttrap_mailtrap_get_email_category' )->andReturn( 'general' );
		Monkey\Functions\expect( 'swifttrap_mailtrap_should_use_bulk_stream' )->andReturn( false );
		Monkey\Functions\expect( 'swifttrap_mailtrap_build_payload' )->andReturn( [] );
		Monkey\Functions\expect( 'wp_json_encode' )->andReturn( '{}' );
		Monkey\Functions\expect( 'swifttrap_mailtrap_log_email' )->andReturnNull();

		// Mock remote post to consistently fail on 500 error code
		Monkey\Functions\expect( 'wp_remote_post' )
			->times( 3 )
			->andReturn(
				[ 'response' => [ 'code' => 500 ], 'body' => 'Server Error' ],
				[ 'response' => [ 'code' => 500 ], 'body' => 'Server Error' ],
				[ 'response' => [ 'code' => 500 ], 'body' => 'Server Error' ]
			);

		Monkey\Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 500 );
		Monkey\Functions\expect( 'wp_remote_retrieve_body' )->andReturn( 'Server Error' );

		$res = swifttrap_mailtrap_send( $normalized, $settings );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertEquals( 'swifttrap_api_error', $res->get_error_code() );
	}

	/**
	 * Test that if API sending fails, the pre_wp_mail hook returns null,
	 * allowing WordPress to fallback to its native wp_mail handler.
	 */
	public function test_fallback() {
		Monkey\Functions\expect( 'swifttrap_mailtrap_get_settings' )
			->andReturn( array(
				'token'        => 'test_token',
				'enabled'      => 1,
				'sender_email' => 'from@example.com',
				'sender_name'  => 'From Name',
			) );

		$atts = array(
			'to'          => 'test@example.com',
			'subject'     => 'Fallback Test',
			'message'     => 'Hello World',
			'headers'     => array(),
			'attachments' => array(),
		);

		Monkey\Functions\expect( 'swifttrap_mailtrap_is_recipient_suppressed' )
			->andReturn( false );

		Monkey\Functions\expect( 'swifttrap_mailtrap_send' )
			->andReturn( new WP_Error( 'api_failure', 'Mailtrap API was unreachable' ) );

		Monkey\Functions\expect( 'swifttrap_mailtrap_trigger_failed' )
			->once();

		$res = swifttrap_mailtrap_pre_wp_mail( null, $atts );

		$this->assertNull( $res );
	}

	/**
	 * Test that if all recipients are suppressed, sending is aborted,
	 * a failed entry is logged, and a WP_Error is returned.
	 */
	public function test_suppression_skip_all() {
		Monkey\Functions\expect( 'swifttrap_mailtrap_get_settings' )
			->andReturn( array(
				'token'        => 'test_token',
				'enabled'      => 1,
				'sender_email' => 'from@example.com',
				'sender_name'  => 'From Name',
			) );

		$atts = array(
			'to'          => 'suppressed@example.com',
			'subject'     => 'All Suppressed Test',
			'message'     => 'Hello World',
			'headers'     => array(),
			'attachments' => array(),
		);

		Monkey\Functions\expect( 'swifttrap_mailtrap_is_recipient_suppressed' )
			->with( 'suppressed@example.com', Mockery::any() )
			->andReturn( true );

		Monkey\Functions\expect( 'swifttrap_mailtrap_log_email' )
			->once();
		Monkey\Functions\expect( 'swifttrap_mailtrap_trigger_failed' )
			->once();

		$res = swifttrap_mailtrap_pre_wp_mail( null, $atts );

		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertEquals( 'swifttrap_all_recipients_suppressed', $res->get_error_code() );
	}

	/**
	 * Test that if only some recipients are suppressed, they are filtered out
	 * and the remaining active ones are successfully sent.
	 */
	public function test_suppression_skip_partial() {
		Monkey\Functions\expect( 'swifttrap_mailtrap_get_settings' )
			->andReturn( array(
				'token'        => 'test_token',
				'enabled'      => 1,
				'sender_email' => 'from@example.com',
				'sender_name'  => 'From Name',
			) );

		$atts = array(
			'to'          => 'suppressed@example.com, active@example.com',
			'subject'     => 'Partial Suppressed Test',
			'message'     => 'Hello World',
			'headers'     => array(),
			'attachments' => array(),
		);

		Monkey\Functions\expect( 'swifttrap_mailtrap_is_recipient_suppressed' )
			->andReturnUsing( function( $email ) {
				return $email === 'suppressed@example.com';
			} );

		Monkey\Functions\expect( 'swifttrap_mailtrap_send' )
			->once()
			->with( Mockery::on( function( $normalized ) {
				$to_emails = array_map( function( $rec ) { return $rec['email']; }, $normalized['to'] );
				return count( $to_emails ) === 1 && $to_emails[0] === 'active@example.com' && in_array( 'suppressed@example.com', $normalized['skipped_recipients'], true );
			} ), Mockery::any() )
			->andReturn( true );

		$res = swifttrap_mailtrap_pre_wp_mail( null, $atts );

		$this->assertTrue( $res );
	}

	/**
	 * Test webhook delivery status update endpoint.
	 */
	public function test_webhook_status_update() {
		Monkey\Functions\expect( 'swifttrap_mailtrap_get_settings' )
			->andReturn( array(
				'webhook_secret' => 'super_secret_webhook_token',
			) );

		$request = new \WP_REST_Request( 'POST', '/swifttrap/v1/webhook' );
		$request->set_header( 'X-Mailtrap-Secret', 'super_secret_webhook_token' );
		$request->set_body( json_encode( array(
			array(
				'message_id' => 'msg-uuid-456',
				'event'      => 'delivered',
			)
		) ) );

		Monkey\Functions\expect( 'swifttrap_mailtrap_update_log_status' )
			->once()
			->with( 'msg-uuid-456', 'delivered' )
			->andReturn( true );

		$response = swifttrap_mailtrap_handle_webhook_request( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( array( 'success' => true, 'updated' => 1 ), $response->get_data() );
	}

	/**
	 * Test webhook endpoint denies access with an invalid secret.
	 */
	public function test_webhook_status_update_unauthorized() {
		Monkey\Functions\expect( 'swifttrap_mailtrap_get_settings' )
			->andReturn( array(
				'webhook_secret' => 'super_secret_webhook_token',
			) );

		$request = new \WP_REST_Request( 'POST', '/swifttrap/v1/webhook' );
		$request->set_header( 'X-Mailtrap-Secret', 'wrong_secret' );

		$response = swifttrap_mailtrap_handle_webhook_request( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertEquals( 'swifttrap_webhook_unauthorized', $response->get_error_code() );
	}

	/**
	 * Test mapping of log entries to CSV rows.
	 */
	public function test_csv_row_building() {
		$entry = array(
			'timestamp'   => '2026-06-01 10:00:00',
			'status'      => 'delivered',
			'from'        => 'sender@example.com',
			'to'          => array(
				array( 'email' => 'to1@example.com', 'name' => 'John Doe' ),
				array( 'email' => 'to2@example.com' ),
			),
			'subject'     => '=1+1',
			'category'    => 'promotional',
			'http_status' => 200,
			'message'     => '@payload',
		);

		$row = swifttrap_mailtrap_build_csv_row( $entry );

		$this->assertIsArray( $row );
		$this->assertCount( 8, $row );
		$this->assertEquals( '2026-06-01 10:00:00', $row[0] );
		$this->assertEquals( 'delivered', $row[1] );
		$this->assertEquals( 'sender@example.com', $row[2] );
		$this->assertEquals( 'John Doe <to1@example.com>, to2@example.com', $row[3] );
		$this->assertEquals( "'=1+1", $row[4] );
		$this->assertEquals( 'promotional', $row[5] );
		$this->assertEquals( 200, $row[6] );
		$this->assertEquals( "'@payload", $row[7] );
	}

	/**
	 * Test aggregation of email log statistics.
	 */
	public function test_analytics_aggregation() {
		Monkey\Functions\expect( 'swifttrap_mailtrap_get_log_file' )
			->andReturn( '/dummy/path.log' );

		$now_time       = time();
		$today_date     = date( 'Y-m-d', $now_time );
		$yesterday_date = date( 'Y-m-d', strtotime( '-1 day', $now_time ) );

		$entry1 = array(
			'timestamp' => date( 'Y-m-d H:i:s', $now_time ),
			'status'    => 'success',
			'category'  => 'promotional',
		);
		$entry2 = array(
			'timestamp' => date( 'Y-m-d H:i:s', $now_time ),
			'status'    => 'failed',
			'category'  => 'promotional',
		);
		$entry3 = array(
			'timestamp' => date( 'Y-m-d H:i:s', strtotime( '-1 day', $now_time ) ),
			'status'    => 'success',
			'category'  => 'transactional',
		);
		$entry4 = array(
			'timestamp' => date( 'Y-m-d H:i:s', strtotime( '-10 days', $now_time ) ),
			'status'    => 'success',
			'category'  => 'transactional',
		);

		$log_content = json_encode( $entry1 ) . "\n"
			. json_encode( $entry2 ) . "\n"
			. json_encode( $entry3 ) . "\n"
			. json_encode( $entry4 ) . "\n";

		$fs = Mockery::mock( 'WP_Filesystem_Base' );
		$fs->shouldReceive( 'exists' )->andReturn( true );
		$fs->shouldReceive( 'is_readable' )->andReturn( true );
		$fs->shouldReceive( 'get_contents' )->andReturn( $log_content );

		$GLOBALS['wp_filesystem'] = $fs;

		Monkey\Functions\expect( 'set_transient' )->andReturn( true );

		$stats = swifttrap_mailtrap_compute_log_stats( 7 );

		$this->assertEquals( 3, $stats['total_sent'] );
		$this->assertEquals( 2, $stats['total_success'] );
		$this->assertEquals( 1, $stats['total_failed'] );
		$this->assertEquals( 66.7, $stats['success_rate'] );
		$this->assertEquals( array( 'promotional' => 2, 'transactional' => 1 ), $stats['by_category'] );
		$this->assertEquals( 2, $stats['daily_volume'][ $today_date ] );
		$this->assertEquals( 1, $stats['daily_volume'][ $yesterday_date ] );
	}
}
