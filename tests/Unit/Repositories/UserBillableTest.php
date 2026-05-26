<?php

declare(strict_types=1);

namespace Vatly\PMPro\Tests\Unit\Repositories;

use Brain\Monkey\Functions;
use Vatly\PMPro\Repositories\UserBillable;
use Vatly\PMPro\Tests\TestCase;
use WP_User;

/**
 * @covers \Vatly\PMPro\Repositories\UserBillable
 */
class UserBillableTest extends TestCase {

	public function test_get_vatly_id_reads_user_meta(): void {
		$user                = new WP_User();
		$user->ID            = 7;
		$user->user_email    = 'pat@example.com';

		Functions\when('get_user_meta')->alias(static function (int $id, string $key, bool $single) {
			return ($id === 7 && $key === UserBillable::META_KEY) ? 'cus_123' : '';
		});

		$billable = new UserBillable($user);

		self::assertSame('cus_123', $billable->getVatlyId());
		self::assertTrue($billable->hasVatlyId());
	}

	public function test_has_vatly_id_false_when_meta_is_empty_string(): void {
		$user     = new WP_User();
		$user->ID = 7;

		Functions\when('get_user_meta')->justReturn('');

		self::assertFalse((new UserBillable($user))->hasVatlyId());
		self::assertNull((new UserBillable($user))->getVatlyId());
	}

	public function test_set_vatly_id_writes_user_meta(): void {
		$user     = new WP_User();
		$user->ID = 7;

		Functions\expect('update_user_meta')
			->once()
			->with(7, UserBillable::META_KEY, 'cus_999')
			->andReturn(true);

		(new UserBillable($user))->setVatlyId('cus_999');

		$this->assertHookExpectations();
	}

	public function test_name_falls_back_to_first_last_when_display_name_empty(): void {
		$user               = new WP_User();
		$user->ID           = 8;
		$user->display_name = '';
		$user->first_name   = 'Alex';
		$user->last_name    = 'Doe';

		self::assertSame('Alex Doe', (new UserBillable($user))->getVatlyName());
	}

	public function test_name_returns_null_when_nothing_set(): void {
		$user     = new WP_User();
		$user->ID = 9;

		self::assertNull((new UserBillable($user))->getVatlyName());
	}

	public function test_email_returns_null_when_blank(): void {
		$user             = new WP_User();
		$user->ID         = 9;
		$user->user_email = '';

		self::assertNull((new UserBillable($user))->getVatlyEmail());
	}

	public function test_get_key_returns_int_user_id(): void {
		$user     = new WP_User();
		$user->ID = 12;

		self::assertSame(12, (new UserBillable($user))->getKey());
	}
}
