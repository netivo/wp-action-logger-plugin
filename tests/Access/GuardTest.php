<?php

declare( strict_types=1 );

namespace Netivo\ActionLoggerMU\Tests\Access;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Netivo\ActionLoggerMU\Access\Guard;
use Netivo\ActionLoggerMU\Tests\TestCase;
use WP_User;

final class GuardTest extends TestCase {
	public function test_email_is_privileged_for_allowed_domains(): void {
		self::assertTrue( Guard::email_is_privileged( 'dev@netivo.pl' ) );
		self::assertTrue( Guard::email_is_privileged( 'DEV@FEHU-WEB.DEV' ) );
	}

	public function test_email_is_privileged_rejects_other_domains(): void {
		self::assertFalse( Guard::email_is_privileged( 'client@example.com' ) );
		self::assertFalse( Guard::email_is_privileged( 'not-an-email' ) );
		self::assertFalse( Guard::email_is_privileged( '' ) );
	}

	public function test_allowed_domains_are_filterable(): void {
		Filters\expectApplied( 'netivo_action_logger_mu_allowed_domains' )
			->once()
			->andReturn( [ 'Example.COM' ] );

		self::assertSame( [ 'example.com' ], Guard::allowed_domains() );
	}

	public function test_email_is_privileged_honours_the_filter(): void {
		Filters\expectApplied( 'netivo_action_logger_mu_allowed_domains' )
			->twice()
			->andReturn( [ 'example.com' ] );

		self::assertTrue( Guard::email_is_privileged( 'anyone@example.com' ) );
		self::assertFalse( Guard::email_is_privileged( 'anyone@netivo.pl' ) );
	}

	public function test_current_user_is_privileged_requires_login(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		self::assertFalse( Guard::current_user_is_privileged() );
	}

	public function test_current_user_is_privileged_checks_current_user_email(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( new WP_User( [ 'user_email' => 'dev@netivo.pl' ] ) );

		self::assertTrue( Guard::current_user_is_privileged() );
	}

	public function test_current_user_is_privileged_rejects_other_domains(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'wp_get_current_user' )->justReturn( new WP_User( [ 'user_email' => 'client@example.com' ] ) );

		self::assertFalse( Guard::current_user_is_privileged() );
	}
}
