<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use Vatly\PMPro\Repositories\UserMetaCustomerBindings;
use Vatly\PMPro\Tests\TestCase;

/**
 * @covers \Vatly\PMPro\Repositories\UserMetaCustomerBindings
 */
class UserMetaCustomerBindingsTest extends TestCase {

	public function test_bind_writes_user_meta(): void {
		Functions\expect( 'update_user_meta' )
			->once()
			->with( 7, UserMetaCustomerBindings::USER_META_KEY, 'cus_abc' )
			->andReturn( true );

		Functions\when( 'get_option' )->justReturn( [] );

		( new UserMetaCustomerBindings() )->bind( 'cus_abc', '7' );

		$this->assertHookExpectations();
	}

	public function test_bind_is_noop_for_zero_or_invalid_host_id(): void {
		Functions\expect( 'update_user_meta' )->never();

		( new UserMetaCustomerBindings() )->bind( 'cus_abc', '0' );
		( new UserMetaCustomerBindings() )->bind( 'cus_abc', '' );

		$this->assertHookExpectations();
	}

	public function test_vatly_id_for_returns_user_meta_value(): void {
		Functions\when( 'get_user_meta' )->alias(
			static fn( int $id, string $key, bool $single ) => ( $id === 7 && $key === UserMetaCustomerBindings::USER_META_KEY ) ? 'cus_known' : ''
		);

		self::assertSame( 'cus_known', ( new UserMetaCustomerBindings() )->vatlyCustomerIdFor( '7' ) );
	}

	public function test_vatly_id_for_returns_null_when_meta_missing(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );

		self::assertNull( ( new UserMetaCustomerBindings() )->vatlyCustomerIdFor( '99' ) );
	}

	public function test_host_id_for_resolves_via_meta_query(): void {
		Functions\expect( 'get_users' )
			->once()
			->with( \Mockery::on( static fn( array $args ): bool =>
				$args['meta_key'] === UserMetaCustomerBindings::USER_META_KEY
				&& $args['meta_value'] === 'cus_X'
				&& $args['fields'] === 'ID'
			) )
			->andReturn( [ 42 ] );

		self::assertSame( '42', ( new UserMetaCustomerBindings() )->hostCustomerIdFor( 'cus_X' ) );
	}

	public function test_host_id_for_returns_null_when_no_user_matches(): void {
		Functions\when( 'get_users' )->justReturn( [] );

		self::assertNull( ( new UserMetaCustomerBindings() )->hostCustomerIdFor( 'cus_unknown' ) );
	}

	public function test_record_stashes_orphan_ids_in_option(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		Functions\expect( 'update_option' )
			->once()
			->with( UserMetaCustomerBindings::UNBOUND_OPTION_KEY, [ 'cus_orphan' ], false );

		( new UserMetaCustomerBindings() )->record( 'cus_orphan' );

		$this->assertHookExpectations();
	}

	public function test_record_is_idempotent(): void {
		Functions\when( 'get_option' )->justReturn( [ 'cus_orphan' ] );

		Functions\expect( 'update_option' )->never();

		( new UserMetaCustomerBindings() )->record( 'cus_orphan' );

		$this->assertHookExpectations();
	}

	public function test_bind_cleans_up_orphan_record(): void {
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( [ 'cus_xyz', 'cus_other' ] );

		Functions\expect( 'update_option' )
			->once()
			->with( UserMetaCustomerBindings::UNBOUND_OPTION_KEY, [ 'cus_other' ], false );

		( new UserMetaCustomerBindings() )->bind( 'cus_xyz', '7' );

		$this->assertHookExpectations();
	}
}
