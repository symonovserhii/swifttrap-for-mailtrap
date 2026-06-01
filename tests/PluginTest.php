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
}
