<?php
namespace SwiftTrapForMailtrap\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

/**
 * Bulk/transactional stream routing (3.1.0).
 */
class RoutingTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		swifttrap_mailtrap_flush_settings_cache();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function mail( array $headers ): array {
		return array(
			'to'      => array( array( 'email' => 'reader@example.com' ) ),
			'subject' => 'Weekly news',
			'message' => '<p>Hello</p>',
			'headers' => $headers,
			'stream'  => '',
		);
	}

	/** Headers the Newsletter plugin (9.x) puts on a campaign email. */
	private function newsletter_campaign(): array {
		return array(
			'Precedence'               => 'bulk',
			'X-Newsletter-Email-Id'    => '42',
			'X-Auto-Response-Suppress' => 'OOF, AutoReply',
			'List-Unsubscribe'         => '<https://example.org/?na=uc&nk=1-abc>',
			'List-Unsubscribe-Post'    => 'List-Unsubscribe=One-Click',
		);
	}

	public function test_detects_newsletter_campaign(): void {
		$this->assertSame( 'newsletter', swifttrap_mailtrap_detect_bulk_source( $this->mail( $this->newsletter_campaign() ) ) );
	}

	public function test_header_names_are_matched_case_insensitively(): void {
		$this->assertSame( 'newsletter', swifttrap_mailtrap_detect_bulk_source( $this->mail( array( 'x-newsletter-email-id' => '7' ) ) ) );
	}

	public function test_newsletter_service_email_is_not_bulk(): void {
		// Newsletter::mail() (text confirmation, admin notice): only this custom header.
		$this->assertSame( '', swifttrap_mailtrap_detect_bulk_source( $this->mail( array( 'X-Auto-Response-Suppress' => 'OOF, AutoReply' ) ) ) );
	}

	public function test_detects_fluentcrm_campaign_but_not_double_optin(): void {
		$campaign = array( 'List-Unsubscribe' => '<https://example.org/index.php?fluentcrm=1&route=unsubscribe&secure_hash=abc&ce_id=15>' );
		$optin    = array( 'List-Unsubscribe' => '<https://example.org/index.php?fluentcrm=1&route=unsubscribe&secure_hash=abc>' );

		$this->assertSame( 'fluentcrm', swifttrap_mailtrap_detect_bulk_source( $this->mail( $campaign ) ) );
		$this->assertSame( '', swifttrap_mailtrap_detect_bulk_source( $this->mail( $optin ) ) );
	}

	public function test_detects_icegram_campaign(): void {
		$headers = array( 'List-Unsubscribe' => '<https://example.org/?es=unsubscribe>,<mailto:admin@example.org?subject=Unsubscribe&body=Contact-ID:3,Campaign-ID:9>' );

		$this->assertSame( 'icegram', swifttrap_mailtrap_detect_bulk_source( $this->mail( $headers ) ) );
	}

	public function test_detects_groundhogg_marketing_only(): void {
		$marketing     = array( 'Precedence' => 'bulk', 'List-Id' => 'example.org' );
		$transactional = array( 'Precedence' => 'bulk', 'X-Auto-Response-Suppress' => 'AutoReply' );

		$this->assertSame( 'groundhogg', swifttrap_mailtrap_detect_bulk_source( $this->mail( $marketing ) ) );
		$this->assertSame( '', swifttrap_mailtrap_detect_bulk_source( $this->mail( $transactional ) ) );
	}

	public function test_ordinary_email_is_not_bulk(): void {
		$this->assertSame( '', swifttrap_mailtrap_detect_bulk_source( $this->mail( array() ) ) );
		$this->assertSame( '', swifttrap_mailtrap_detect_bulk_source( $this->mail( array( 'List-Unsubscribe' => '<https://example.org/unsub>' ) ) ) );
	}

	public function test_bulk_source_filter_can_claim_an_email(): void {
		Monkey\Filters\expectApplied( 'swifttrap_mailtrap_bulk_source' )->once()->andReturn( 'my-plugin' );

		$this->assertSame( 'my-plugin', swifttrap_mailtrap_detect_bulk_source( $this->mail( array() ) ) );
	}

	public function test_autodetect_is_opt_in(): void {
		$mail = $this->mail( $this->newsletter_campaign() );

		$this->assertFalse( swifttrap_mailtrap_should_use_bulk_stream( 'general', array( 'bulk_autodetect' => 0 ), $mail ) );
		$this->assertTrue( swifttrap_mailtrap_should_use_bulk_stream( 'general', array( 'bulk_autodetect' => 1 ), $mail ) );
	}

	public function test_autodetect_leaves_ordinary_email_transactional(): void {
		$this->assertFalse( swifttrap_mailtrap_should_use_bulk_stream( 'general', array( 'bulk_autodetect' => 1 ), $this->mail( array() ) ) );
	}

	public function test_stream_header_forces_bulk_without_the_option(): void {
		$mail           = $this->mail( array() );
		$mail['stream'] = 'bulk';

		$this->assertTrue( swifttrap_mailtrap_should_use_bulk_stream( 'general', array(), $mail ) );
	}

	public function test_stream_header_transactional_beats_detection_and_category(): void {
		$mail           = $this->mail( $this->newsletter_campaign() );
		$mail['stream'] = 'transactional';
		$settings       = array( 'bulk_autodetect' => 1, 'category_streams' => array( 'promotional' => 'bulk' ) );

		$this->assertFalse( swifttrap_mailtrap_should_use_bulk_stream( 'promotional', $settings, $mail ) );
	}

	public function test_use_bulk_filter_receives_the_email(): void {
		$mail = $this->mail( array( 'X-My-Plugin' => 'digest' ) );

		Monkey\Filters\expectApplied( 'swifttrap_mailtrap_use_bulk_stream' )
			->once()
			->with( false, 'general', array(), $mail )
			->andReturn( true );

		$this->assertTrue( swifttrap_mailtrap_should_use_bulk_stream( 'general', array(), $mail ) );
	}

	public function test_stream_header_is_read_and_never_forwarded(): void {
		$settings = array( 'sender_email' => 'site@example.com', 'sender_name' => 'Site', 'enable_categories' => 0 );
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $settings );

		$normalized = swifttrap_mailtrap_normalize_atts(
			array(
				'to'      => 'reader@example.com',
				'subject' => 'Digest',
				'message' => 'Hi',
				'headers' => array( 'X-SwiftTrap-Stream: BULK', 'X-Other: 1' ),
			),
			$settings
		);

		$this->assertSame( 'bulk', $normalized['stream'] );
		$this->assertSame( array( 'X-Other' => '1' ), $normalized['headers'] );
	}

	public function test_headers_given_as_one_string_are_parsed(): void {
		$settings = array( 'sender_email' => 'site@example.com', 'sender_name' => 'Site', 'enable_categories' => 0 );
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( $settings );

		$normalized = swifttrap_mailtrap_normalize_atts(
			array(
				'to'      => 'reader@example.com',
				'subject' => 'News',
				'message' => '<p>Hi</p>',
				'headers' => "Reply-To: Editor <editor@example.com>\r\nContent-Type: text/html; charset=UTF-8\r\nX-Newsletter-Email-Id: 5\r\nMIME-Version: 1.0",
			),
			$settings
		);

		$this->assertSame( 'text/html', $normalized['content_type'] );
		$this->assertSame( 'editor@example.com', $normalized['reply_to'][0]['email'] );
		$this->assertSame( array( 'X-Newsletter-Email-Id' => '5' ), $normalized['headers'] );
		$this->assertSame( 'newsletter', swifttrap_mailtrap_detect_bulk_source( $normalized ) );
	}

	public function test_newsletter_signup_emails_are_marked_transactional(): void {
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( array( 'enabled' => 1, 'token' => 't' ) );

		$welcome  = swifttrap_mailtrap_newsletter_headers( array( 'Precedence' => 'bulk' ), (object) array( 'type' => 'welcome' ) );
		$campaign = swifttrap_mailtrap_newsletter_headers( array( 'Precedence' => 'bulk' ), (object) array( 'type' => 'message' ) );

		$this->assertSame( 'transactional', $welcome['X-SwiftTrap-Stream'] );
		$this->assertArrayNotHasKey( 'X-SwiftTrap-Stream', $campaign );
	}

	public function test_newsletter_headers_untouched_when_plugin_is_off(): void {
		Monkey\Functions\when( 'swifttrap_mailtrap_get_settings' )->justReturn( array( 'enabled' => 0, 'token' => 't' ) );

		$headers = swifttrap_mailtrap_newsletter_headers( array( 'Precedence' => 'bulk' ), (object) array( 'type' => 'welcome' ) );

		$this->assertSame( array( 'Precedence' => 'bulk' ), $headers );
	}
}
