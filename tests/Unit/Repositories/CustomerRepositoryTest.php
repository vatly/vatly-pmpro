<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use Vatly\Fluent\Exceptions\InvalidCustomerException;
use Vatly\PMPro\Repositories\CustomerRepository;
use Vatly\PMPro\Repositories\UserBillable;
use Vatly\PMPro\Tests\TestCase;
use WP_User;

/**
 * @covers \Vatly\PMPro\Repositories\CustomerRepository
 */
class CustomerRepositoryTest extends TestCase {

	public function test_find_by_vatly_id_returns_user_billable_when_user_found(): void {
		$user             = new WP_User();
		$user->ID         = 42;
		$user->user_email = 'pat@example.com';

		Functions\expect('get_users')
			->once()
			->with(\Mockery::on(function (array $args): bool {
				return $args['meta_key'] === UserBillable::META_KEY
					&& $args['meta_value'] === 'cus_123'
					&& $args['number'] === 1;
			}))
			->andReturn([$user]);

		$billable = (new CustomerRepository())->findByVatlyId('cus_123');

		self::assertInstanceOf(UserBillable::class, $billable);
		self::assertSame(42, $billable->getKey());
	}

	public function test_find_by_vatly_id_returns_null_when_no_match(): void {
		Functions\when('get_users')->justReturn([]);

		self::assertNull((new CustomerRepository())->findByVatlyId('cus_404'));
	}

	public function test_find_by_vatly_id_or_fail_throws_when_missing(): void {
		Functions\when('get_users')->justReturn([]);

		$this->expectException(InvalidCustomerException::class);

		(new CustomerRepository())->findByVatlyIdOrFail('cus_404');
	}

	public function test_for_user_wraps_a_wp_user(): void {
		$user     = new WP_User();
		$user->ID = 9;

		$billable = (new CustomerRepository())->forUser($user);

		self::assertSame(9, $billable->getKey());
	}
}
