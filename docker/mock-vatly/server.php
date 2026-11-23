<?php
/**
 * Minimal mock of the Vatly REST API for local development.
 *
 * Implements just enough surface to exercise the PMPro plugin end-to-end:
 *
 *   POST /v1/customers                 → create customer
 *   GET  /v1/customers/{id}            → fetch customer
 *   POST /v1/checkouts                 → create checkout (returns href)
 *   GET  /v1/checkouts/{id}            → fetch checkout (status flips on first GET)
 *   GET  /v1/subscriptions/{id}        → fetch subscription
 *   DELETE /v1/subscriptions/{id}      → cancel subscription
 *   GET  /v1/orders/{id}               → fetch order
 *
 *   POST /__webhook/{event}            → dev helper: signs & POSTs a webhook to
 *                                        a configurable URL
 *
 * State lives in /tmp/mock-vatly-state.json. Safe to nuke; the next call
 * recreates anything it needs.
 */

declare(strict_types=1);

final class MockVatly {

	private const STATE_FILE     = '/tmp/mock-vatly-state.json';
	private const WEBHOOK_SECRET = 'mock-secret-for-dev-only-do-not-use-in-prod';
	private const HOSTED_BASE    = 'http://localhost:8091/__hosted';

	public function handle(string $method, string $path): void {
		header('Content-Type: application/json');

		try {
			$response = $this->route($method, $path);
		} catch (\Throwable $e) {
			http_response_code(500);
			echo json_encode(['error' => $e->getMessage()]);

			return;
		}

		// HTML routes (hosted checkout / invoice) and redirects emit their own
		// output and return null — don't append a JSON "null" after them.
		if ($response !== null) {
			echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
		}
	}

	private function route(string $method, string $path) {
		$method = strtoupper($method);

		if ($method === 'POST' && $path === '/v1/customers') {
			return $this->create_customer();
		}

		if ($method === 'POST' && $path === '/v1/checkouts') {
			return $this->create_checkout();
		}

		if ($method === 'GET' && preg_match('#^/v1/customers/(.+)$#', $path, $m)) {
			return $this->get_customer($m[1]);
		}

		if ($method === 'GET' && preg_match('#^/v1/checkouts/(.+)$#', $path, $m)) {
			return $this->get_checkout($m[1]);
		}

		if ($method === 'GET' && preg_match('#^/v1/subscriptions/(.+)$#', $path, $m)) {
			return $this->get_subscription($m[1]);
		}

		if ($method === 'DELETE' && preg_match('#^/v1/subscriptions/(.+)$#', $path, $m)) {
			return $this->cancel_subscription($m[1]);
		}

		if ($method === 'GET' && preg_match('#^/v1/orders/(.+)$#', $path, $m)) {
			return $this->get_order($m[1]);
		}

		// Dev helper for forging webhook deliveries.
		if ($method === 'POST' && preg_match('#^/__webhook/(.+)$#', $path, $m)) {
			return $this->forge_webhook($m[1]);
		}

		// Static "hosted checkout" landing page so a browser can complete a
		// checkout without a real Vatly account.
		if ($method === 'GET' && str_starts_with($path, '/__hosted/')) {
			$this->hosted_page(substr($path, strlen('/__hosted/')));

			return null;
		}

		http_response_code(404);

		return ['error' => "no mock route for {$method} {$path}"];
	}

	// --- API endpoints ---

	private function create_customer(): array {
		$body = $this->json_body();
		$id   = 'customer_' . bin2hex(random_bytes(6));

		$customer = [
			'resource' => 'customer',
			'id'       => $id,
			'email'    => $body['email'] ?? null,
			'name'     => $body['name'] ?? null,
			'testmode' => true,
			'createdAt'=> date('c'),
		];

		$this->store('customers', $id, $customer);

		return $customer;
	}

	private function get_customer(string $id): array {
		return $this->load('customers', $id) ?? [
			'resource' => 'customer',
			'id'       => $id,
			'email'    => null,
			'testmode' => true,
		];
	}

	private function create_checkout(): array {
		$body = $this->json_body();
		$id   = 'checkout_' . bin2hex(random_bytes(6));

		$products = $body['products'] ?? [];
		$plan_id  = is_array($products) && isset($products[0]['id'])
			? (string) $products[0]['id']
			: 'plan_unknown';

		$checkout = [
			'resource'           => 'checkout',
			'id'                 => $id,
			'status'             => 'open',
			'customerId'         => (string) ($body['customerId'] ?? ''),
			'redirectUrlSuccess' => (string) ($body['redirectUrlSuccess'] ?? ''),
			'redirectUrlCanceled'=> (string) ($body['redirectUrlCanceled'] ?? ''),
			'planId'             => $plan_id,
			'metadata'           => $body['metadata'] ?? null,
			'href'               => self::HOSTED_BASE . '/' . $id,
			'createdAt'          => date('c'),
			'testmode'           => true,
		];

		$this->store('checkouts', $id, $checkout);

		return $checkout;
	}

	private function get_checkout(string $id): array {
		$checkout = $this->load('checkouts', $id);

		if (!$checkout) {
			http_response_code(404);

			return ['error' => "checkout {$id} not found"];
		}

		return $checkout;
	}

	private function get_subscription(string $id): array {
		return $this->load('subscriptions', $id) ?? [
			'resource'           => 'subscription',
			'id'                 => $id,
			'subscriptionPlanId' => 'plan_local_dev',
			'name'               => 'Mock Subscription',
			'quantity'           => 1,
			'status'             => 'active',
			'cancelledAt'        => null,
			'endedAt'            => null,
			'testmode'           => true,
		];
	}

	private function cancel_subscription(string $id): array {
		$sub = $this->load('subscriptions', $id) ?? [
			'resource' => 'subscription',
			'id'       => $id,
		];

		$sub['status']      = 'canceled';
		$sub['cancelledAt'] = date('c');
		$sub['endedAt']     = date('c');

		$this->store('subscriptions', $id, $sub);

		return $sub;
	}

	private function get_order(string $id): array {
		return $this->load('orders', $id) ?? [
			'resource'      => 'order',
			'id'            => $id,
			'status'        => 'paid',
			'invoiceNumber' => 'INV-' . substr($id, 0, 6),
			'paymentMethod' => 'creditcard',
			'customerId'    => 'customer_unknown',
			'total'         => ['value' => '23.00', 'currency' => 'EUR'],
			'subtotal'      => ['value' => '19.00', 'currency' => 'EUR'],
			'taxSummary'    => [
				[
					'taxRate' => ['name' => 'NL standard', 'percentage' => 21.0, 'taxablePercentage' => 100.0],
					'amount'  => ['value' => '4.00', 'currency' => 'EUR'],
				],
			],
			'lines'         => [],
			'testmode'      => true,
		];
	}

	// --- Hosted-checkout simulator ---

	private function hosted_page(string $checkout_id): void {
		header('Content-Type: text/html; charset=utf-8');

		// Merchant-of-Record invoice page (linked from order.links.customerInvoice).
		if (str_starts_with($checkout_id, 'invoice/')) {
			$this->hosted_invoice(substr($checkout_id, strlen('invoice/')));

			return;
		}

		$checkout = $this->load('checkouts', $checkout_id);
		if (!$checkout) {
			http_response_code(404);
			echo "<h1>Unknown checkout</h1>";

			return;
		}

		// On click of "Pay", flip status to paid, fire a webhook, and redirect.
		if (($_GET['action'] ?? '') === 'pay') {
			$checkout['status']  = 'paid';
			$checkout['orderId'] = 'order_' . bin2hex(random_bytes(6));
			$this->store('checkouts', $checkout_id, $checkout);

			// Build a full order resource (shape = api-php `Order`, byte-identical
			// to `GET /v1/orders/{id}`). The webhook factory hydrates the typed
			// event straight from this `object`, so it must carry every field the
			// event reads — total/subtotal as Money (`value`), the flat
			// taxSummary list, testmode, lines and the routing metadata.
			$sub_id = 'subscription_' . bin2hex(random_bytes(6));
			$order  = [
				'resource'      => 'order',
				'id'            => $checkout['orderId'],
				'createdAt'     => date('c'),
				'status'        => 'paid',
				'invoiceNumber' => 'INV-' . substr($checkout['orderId'], 0, 6),
				'paymentMethod' => 'creditcard',
				'customerId'    => $checkout['customerId'],
				'total'         => ['value' => '23.00', 'currency' => 'EUR'],
				'subtotal'      => ['value' => '19.00', 'currency' => 'EUR'],
				'taxSummary'    => [
					[
						'taxRate' => ['name' => 'NL standard', 'percentage' => 21.0, 'taxablePercentage' => 100.0],
						'amount'  => ['value' => '4.00', 'currency' => 'EUR'],
					],
				],
				'lines'         => [],
				// Merchant-of-Record invoice: the driver reads
				// links.customerInvoice to surface Vatly's compliant invoice.
				'links'         => [
					'self'            => ['href' => self::HOSTED_BASE . '/../v1/orders/' . $checkout['orderId'], 'type' => 'application/json'],
					'customer'        => ['href' => self::HOSTED_BASE . '/../v1/customers/' . $checkout['customerId'], 'type' => 'application/json'],
					'customerInvoice' => ['href' => self::HOSTED_BASE . '/invoice/' . $checkout['orderId'], 'type' => 'text/html'],
				],
				// Propagate the checkout metadata (carries `pmpro_order_code`)
				// onto the order so the driver can route the paid webhook to the
				// exact PMPro token order and convert it in place.
				'metadata'      => $checkout['metadata'] ?? null,
				'testmode'      => true,
			];
			$this->store('orders', $checkout['orderId'], $order);

			// Build a full subscription resource (shape = api-php `Subscription`,
			// byte-identical to `GET /v1/subscriptions/{id}`). Must carry the
			// fields the started event reads: customerId, subscriptionPlanId,
			// name, quantity, testmode.
			$subscription = [
				'resource'           => 'subscription',
				'id'                 => $sub_id,
				'customerId'         => $checkout['customerId'],
				'subscriptionPlanId' => $checkout['planId'],
				'name'               => 'Mock Subscription',
				'quantity'           => 1,
				'status'             => 'active',
				'startedAt'          => date('c'),
				'canceledAt'         => null,
				'endedAt'            => null,
				'testmode'           => true,
			];
			$this->store('subscriptions', $sub_id, $subscription);

			$this->store('chk_to_sub', $checkout_id, ['subscription_id' => $sub_id]);

			$webhook_url = getenv('VATLY_WEBHOOK_URL') ?: 'http://wordpress/wp-json/vatly/v1/webhook';

			$this->emit_webhook($webhook_url, 'subscription.started', $subscription, 'subscription', $sub_id);

			$this->emit_webhook($webhook_url, 'order.paid', $order, 'order', $checkout['orderId']);

			header('Location: ' . $checkout['redirectUrlSuccess']);

			return;
		}

		?>
		<!doctype html>
		<html lang="en">
			<head>
				<meta charset="utf-8">
				<title>Mock Vatly hosted checkout</title>
				<style>
					body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 480px; margin: 4rem auto; padding: 2rem; border: 1px solid #ddd; border-radius: 12px; }
					h1 { font-size: 1.25rem; margin: 0 0 1rem; }
					.kv { color: #666; font-size: 0.875rem; }
					.kv code { color: #111; }
					button { background: #111; color: #fff; border: 0; padding: 0.75rem 1.25rem; border-radius: 8px; font-size: 1rem; cursor: pointer; }
					button.cancel { background: #fff; color: #111; border: 1px solid #ddd; margin-left: 0.5rem; }
				</style>
			</head>
			<body>
				<h1>Mock Vatly hosted checkout</h1>
				<p class="kv">Checkout: <code><?= htmlspecialchars($checkout_id) ?></code></p>
				<p class="kv">Customer: <code><?= htmlspecialchars((string) ($checkout['customerId'] ?? '')) ?></code></p>
				<p class="kv">Plan: <code><?= htmlspecialchars((string) ($checkout['planId'] ?? '')) ?></code></p>
				<p>
					<form method="get">
						<input type="hidden" name="action" value="pay">
						<button type="submit">Pay €19.00</button>
						<a class="cancel" href="<?= htmlspecialchars((string) $checkout['redirectUrlCanceled']) ?>">
							<button type="button" class="cancel">Cancel</button>
						</a>
					</form>
				</p>
				<p class="kv" style="margin-top: 2rem;">This is the mock Vatly server. No real money will move.</p>
			</body>
		</html>
		<?php
	}

	/**
	 * Render a stand-in Merchant-of-Record invoice for a paid order. Stands in
	 * for the compliant PDF/HTML invoice Vatly generates as MoR.
	 */
	private function hosted_invoice(string $order_id): void {
		$order = $this->load('orders', $order_id);

		if (!$order) {
			http_response_code(404);
			echo '<h1>Unknown order</h1>';

			return;
		}

		$invoice  = (string) ($order['invoiceNumber'] ?? '');
		$total    = (string) ($order['total']['value'] ?? '');
		$subtotal = (string) ($order['subtotal']['value'] ?? '');
		$currency = (string) ($order['total']['currency'] ?? 'EUR');
		$tax      = $order['taxSummary'][0] ?? [];
		$tax_name = (string) ($tax['taxRate']['name'] ?? 'VAT');
		$tax_pct  = (string) ($tax['taxRate']['percentage'] ?? '');
		$tax_amt  = (string) ($tax['amount']['value'] ?? '');

		$e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES);

		?>
		<!doctype html>
		<html lang="en">
			<head>
				<meta charset="utf-8">
				<title>Invoice <?= $e($invoice) ?> — Vatly</title>
				<style>
					body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 540px; margin: 3rem auto; padding: 2rem; border: 1px solid #ddd; border-radius: 12px; color: #111; }
					h1 { font-size: 1.1rem; letter-spacing: .02em; text-transform: uppercase; color: #666; margin: 0 0 .25rem; }
					.num { font-size: 1.5rem; font-weight: 700; margin: 0 0 1.5rem; }
					table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
					td { padding: .5rem 0; border-bottom: 1px solid #eee; }
					td.r { text-align: right; }
					tr.total td { border-bottom: 0; font-weight: 700; font-size: 1.1rem; padding-top: 1rem; }
					.mor { margin-top: 2rem; font-size: .8rem; color: #666; line-height: 1.5; }
				</style>
			</head>
			<body>
				<h1>Vatly · Invoice</h1>
				<p class="num"><?= $e($invoice) ?></p>
				<table>
					<tr><td>Subtotal</td><td class="r"><?= $e($currency) ?> <?= $e($subtotal) ?></td></tr>
					<tr><td><?= $e($tax_name) ?> (<?= $e($tax_pct) ?>%)</td><td class="r"><?= $e($currency) ?> <?= $e($tax_amt) ?></td></tr>
					<tr class="total"><td>Total</td><td class="r"><?= $e($currency) ?> <?= $e($total) ?></td></tr>
				</table>
				<p class="mor">Sold by Vatly as Merchant of Record. Vatly has collected and
				will remit the <?= $e($tax_name) ?> shown above. This is the mock Vatly server —
				no real money moved.</p>
			</body>
		</html>
		<?php
	}

	// --- Dev helper: forge & deliver a signed webhook ---

	private function forge_webhook(string $event_name): array {
		$body = $this->json_body();

		$target = (string) ($body['url'] ?? (getenv('VATLY_WEBHOOK_URL') ?: 'http://wordpress/wp-json/vatly/v1/webhook'));
		$entity_type = (string) ($body['entityType'] ?? 'subscription');
		$entity_id   = (string) ($body['entityId'] ?? 'sub_dev');
		$object      = (array) ($body['object'] ?? []);

		$delivered = $this->emit_webhook($target, $event_name, $object, $entity_type, $entity_id);

		return [
			'delivered' => $delivered['ok'],
			'status'    => $delivered['status'],
			'url'       => $target,
		];
	}

	/**
	 * @param  array<string, mixed> $object
	 * @return array{ok: bool, status: int}
	 */
	private function emit_webhook(string $url, string $event_name, array $object, string $entity_type, string $entity_id): array {
		$resource = explode('.', $event_name)[0];

		$payload = [
			'id'         => 'evt_' . bin2hex(random_bytes(6)),
			'resource'   => $resource,
			'eventName'  => $event_name,
			'entityType' => $entity_type,
			'entityId'   => $entity_id,
			'testmode'   => true,
			'createdAt'  => date('c'),
			'object'     => array_merge(['customerId' => $object['customerId'] ?? null], $object),
		];

		$body      = json_encode($payload, JSON_UNESCAPED_SLASHES);
		$timestamp = (string) time();
		$signature = $this->sign($timestamp, $body);

		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Vatly-Signature: t=' . $timestamp . ',v1=' . $signature,
			],
			CURLOPT_TIMEOUT        => 10,
		]);

		curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		return ['ok' => $status >= 200 && $status < 300, 'status' => $status];
	}

	private function sign(string $timestamp, string $body): string {
		return hash_hmac('sha256', $timestamp . '.' . $body, self::WEBHOOK_SECRET);
	}

	// --- Tiny state store ---

	private function store(string $bucket, string $id, array $value): void {
		$state              = $this->state();
		$state[$bucket]     = $state[$bucket] ?? [];
		$state[$bucket][$id] = $value;

		file_put_contents(self::STATE_FILE, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function load(string $bucket, string $id): ?array {
		$state = $this->state();

		return $state[$bucket][$id] ?? null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function state(): array {
		if (!file_exists(self::STATE_FILE)) {
			return [];
		}

		return json_decode((string) file_get_contents(self::STATE_FILE), true) ?: [];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function json_body(): array {
		$raw = file_get_contents('php://input') ?: '';

		return $raw === '' ? [] : (array) json_decode($raw, true);
	}
}
