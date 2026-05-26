<?php
/**
 * Lightweight stubs for PMPro classes and globals our SUT references at load
 * time. Per-test behavioural expectations should still come from Mockery /
 * Brain\Monkey.
 */

declare(strict_types=1);

if (!class_exists('PMProGateway')) {
	#[\AllowDynamicProperties]
	class PMProGateway {
		public function process(&$order) { return true; }
		public function update(&$order) { return true; }
		public function cancel(&$order) { return true; }
		public function cancel_subscription($subscription) { return true; }
		public function update_subscription_info($subscription) { return null; }

		public static function supports($feature) { return false; }
		public static function show_settings_fields() {}
		public static function save_settings_fields() {}
	}
}

// PMPro helper functions referenced via function_exists() guards in the SUT.
// We declare them as no-ops so function_exists() returns true; tests that need
// specific return values override them via Brain\Monkey\Functions\when().
if (!function_exists('get_pmpro_membership_level_meta')) {
	function get_pmpro_membership_level_meta(int $level_id, string $key, bool $single = true) {
		return '';
	}
}

if (!function_exists('update_pmpro_membership_level_meta')) {
	function update_pmpro_membership_level_meta(int $level_id, string $key, $value) {
		return true;
	}
}

if (!function_exists('get_pmpro_membership_order_meta')) {
	function get_pmpro_membership_order_meta(int $order_id, string $key, bool $single = true) {
		return '';
	}
}

if (!function_exists('update_pmpro_membership_order_meta')) {
	function update_pmpro_membership_order_meta(int $order_id, string $key, $value) {
		return true;
	}
}

if (!function_exists('pmpro_changeMembershipLevel')) {
	function pmpro_changeMembershipLevel($level_id, int $user_id, string $reason = 'changed') {
		return true;
	}
}

if (!function_exists('pmpro_getLevel')) {
	function pmpro_getLevel(int $id) {
		return null;
	}
}

if (!function_exists('pmpro_getMembershipLevelForUser')) {
	function pmpro_getMembershipLevelForUser(int $user_id) {
		return null;
	}
}

if (!function_exists('pmpro_url')) {
	function pmpro_url(string $page = '') {
		return 'https://example.org/' . $page;
	}
}

if (!function_exists('get_user_by')) {
	function get_user_by(string $field, $value) {
		return false;
	}
}

if (!class_exists('MemberOrder')) {
	#[\AllowDynamicProperties]
	class MemberOrder {
		public int $id = 0;
		public string $code = '';
		public int $user_id = 0;
		public int $membership_id = 0;
		public string $status = '';
		public string $gateway = '';
		public string $payment_transaction_id = '';
		public string $subscription_transaction_id = '';
		public string $error = '';
		public string $shorterror = '';
		public ?string $vatly_checkout_id = null;

		public function getRandomCode(): string {
			return strtoupper(bin2hex(random_bytes(5)));
		}

		public function saveOrder(): bool {
			if ($this->id === 0) {
				$this->id = random_int(1, PHP_INT_MAX);
			}

			return true;
		}
	}
}
