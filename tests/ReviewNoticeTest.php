<?php
namespace SwiftTrapForMailtrap\Tests;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class ReviewNoticeTest extends TestCase {
	private const NOW = 2000000000;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', sys_get_temp_dir() . '/' );
		}
		if ( ! function_exists( 'add_action' ) ) {
			require_once dirname( __DIR__ ) . '/vendor/brain/monkey/inc/api.php';
		}
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__ ) . '/includes/review-notice.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_not_due_before_first_email(): void {
		$this->assertFalse( swifttrap_mailtrap_review_is_due( array(), self::NOW ) );
	}

	public function test_not_due_within_first_week(): void {
		$this->assertFalse( swifttrap_mailtrap_review_is_due( array( 'first_sent_at' => self::NOW - DAY_IN_SECONDS ), self::NOW ) );
	}

	public function test_due_after_a_week(): void {
		$this->assertTrue( swifttrap_mailtrap_review_is_due( array( 'first_sent_at' => self::NOW - WEEK_IN_SECONDS - 1 ), self::NOW ) );
	}

	public function test_snooze_then_due_again(): void {
		$state = array( 'first_sent_at' => self::NOW - 20 * DAY_IN_SECONDS, 'snooze_until' => self::NOW + DAY_IN_SECONDS );
		$this->assertFalse( swifttrap_mailtrap_review_is_due( $state, self::NOW ) );
		$this->assertTrue( swifttrap_mailtrap_review_is_due( $state, self::NOW + 2 * DAY_IN_SECONDS ) );
	}

	public function test_never_due_once_done(): void {
		$state = array( 'first_sent_at' => 1, 'status' => 'done' );
		$this->assertFalse( swifttrap_mailtrap_review_is_due( $state, self::NOW ) );
	}

	public function test_first_success_is_recorded_once(): void {
		$saved = array();
		Functions\when( 'get_option' )->alias( static function () use ( &$saved ) { return $saved; } );
		Functions\when( 'update_option' )->alias( static function ( $name, $value ) use ( &$saved ) { $saved = $value; return true; } );

		swifttrap_mailtrap_review_record_success();
		$this->assertGreaterThan( 0, $saved['first_sent_at'] );

		$saved['first_sent_at'] = 123;
		swifttrap_mailtrap_review_record_success();
		$this->assertSame( 123, $saved['first_sent_at'] );
	}
}
