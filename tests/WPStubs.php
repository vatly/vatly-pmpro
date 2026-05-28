<?php
/**
 * Minimal WordPress shim for unit tests.
 *
 * We use Brain\Monkey's `Functions::expect()` for hook/filter assertions in
 * individual tests; these globals + classes only need to exist so the SUT
 * loads without fatal-erroring on first reference. Brain\Monkey takes over
 * once the test calls `setUp()` and mocks specific functions there.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

if (!class_exists('WP_User')) {
	#[\AllowDynamicProperties]
	class WP_User {
		public int $ID = 0;
		public string $user_email = '';
		public string $user_login = '';
		public string $display_name = '';
		public string $first_name = '';
		public string $last_name = '';

		public function __construct(int $id = 0, string $email = '', string $display = '') {
			$this->ID           = $id;
			$this->user_email   = $email;
			$this->display_name = $display;
		}
	}
}

if (!class_exists('WP_Error')) {
	#[\AllowDynamicProperties]
	class WP_Error {
		public function __construct(public string $code = '', public string $message = '', public array $data = []) {}

		public function get_error_code(): string  { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}

if (!class_exists('WP_REST_Request')) {
	#[\AllowDynamicProperties]
	class WP_REST_Request {
		public function __construct(public string $body = '', public array $headers = []) {}

		public function get_body(): string {
			return $this->body;
		}

		public function get_header(string $name): string {
			$name = strtolower($name);
			foreach ($this->headers as $key => $val) {
				if (strtolower((string) $key) === $name) {
					return (string) $val;
				}
			}

			return '';
		}
	}
}

if (!class_exists('WP_REST_Response')) {
	#[\AllowDynamicProperties]
	class WP_REST_Response {
		public function __construct(public $data = null, public int $status = 200) {}

		public function get_status(): int {
			return $this->status;
		}

		public function get_data() {
			return $this->data;
		}
	}
}

if (!class_exists('WP_REST_Server')) {
	class WP_REST_Server {
		public const CREATABLE = 'POST';
		public const READABLE  = 'GET';
	}
}

// A tiny wpdb shim with just the call surface we actually use. Specific tests
// can replace this with Mockery doubles where it matters.
if (!class_exists('wpdb')) {
	#[\AllowDynamicProperties]
	class wpdb {
		public string $prefix = 'wp_';
		public string $pmpro_membership_levelmeta = 'wp_pmpro_membership_levelmeta';
		public int $insert_id = 0;

		/** @var array<int, array{0: string, 1: array<int, mixed>}> */
		public array $queries = [];

		/** @var array<string, mixed>|object */
		public $next_row = [];
		/** @var array<int, array<string, mixed>> */
		public array $next_rows = [];
		/** @var mixed */
		public $next_var = null;

		public function prepare(string $sql, ...$args): string {
			// Simple sprintf-ish to keep tests deterministic.
			$replacements = array_map(static function ($v): string {
				if (is_int($v) || is_float($v)) {
					return (string) $v;
				}

				return "'" . addslashes((string) $v) . "'";
			}, $args);

			return vsprintf(str_replace(['%s', '%d', '%f'], '%s', $sql), $replacements);
		}

		public function get_row(string $sql) {
			$this->queries[] = ['get_row', [$sql]];

			return $this->next_row ?: null;
		}

		public function get_results(string $sql) {
			$this->queries[] = ['get_results', [$sql]];

			return $this->next_rows;
		}

		public function get_var(string $sql) {
			$this->queries[] = ['get_var', [$sql]];

			return $this->next_var;
		}

		public function insert(string $table, array $data, array $format): int {
			$this->queries[] = ['insert', [$table, $data, $format]];
			$this->insert_id++;

			return 1;
		}

		public function update(string $table, array $data, array $where, array $format, array $where_format): int {
			$this->queries[] = ['update', [$table, $data, $where, $format, $where_format]];

			return 1;
		}

		public function query(string $sql) {
			$this->queries[] = ['query', [$sql]];

			return 1;
		}

		public function get_charset_collate(): string {
			return 'DEFAULT CHARSET=utf8mb4';
		}

		public function reset(): void {
			$this->queries   = [];
			$this->next_row  = [];
			$this->next_rows = [];
			$this->next_var  = null;
			$this->insert_id = 0;
		}
	}
}

if (!isset($GLOBALS['wpdb'])) {
	$GLOBALS['wpdb'] = new wpdb();
}
