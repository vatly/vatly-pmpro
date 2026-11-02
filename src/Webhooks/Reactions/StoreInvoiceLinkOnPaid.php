<?php

declare(strict_types=1);

namespace Vatly\PMPro\Webhooks\Reactions;

use Vatly\Fluent\Actions\GetOrder;
use Vatly\Fluent\Contracts\OrderRepositoryInterface;
use Vatly\Fluent\Contracts\WebhookReactionInterface;
use Vatly\API\Webhooks\Events\OrderPaid;
use Vatly\PMPro\Repositories\OrderModel;

/**
 * Persist Vatly's hosted invoice URL onto the PMPro order.
 *
 * Vatly is the Merchant of Record, so it issues the legally-compliant invoice
 * (correct VAT lines, Vatly as seller, reverse-charge notes where applicable).
 * That document lives on the Vatly order's `links.customerInvoice`. We stamp its
 * URL as order meta so member- and admin-facing surfaces can link to the real
 * MoR invoice instead of PMPro's self-rendered one (see {@see \Vatly\PMPro\Gateway\InvoiceSurface}).
 *
 * `OrderPaid` doesn't expose `links`, so we re-fetch the order via {@see GetOrder}
 * — the same call {@see PMProStoreOrderOnPaid} already makes for metadata
 * routing. This reaction must run after that one so the PMPro order row exists.
 * A missing link (older order, API hiccup) is a logged-free no-op: the member
 * simply falls back to PMPro's invoice, and a later webhook replay can stamp it.
 */
class StoreInvoiceLinkOnPaid implements WebhookReactionInterface {

	/** Order-meta key: absolute URL of Vatly's hosted customer invoice. */
	public const ORDER_META_INVOICE_URL = 'vatly_customer_invoice_url';

	public function __construct(
		private OrderRepositoryInterface $orders,
		private GetOrder $getOrder,
	) {}

	public function supports( object $event ): bool {
		return $event instanceof OrderPaid;
	}

	public function handle( object $event ): void {
		if ( ! $event instanceof OrderPaid ) {
			return;
		}

		$order = $this->orders->findByVatlyId( $event->orderId );

		if ( ! $order instanceof OrderModel || $order->id === 0 ) {
			return;
		}

		try {
			$api_order = $this->getOrder->execute( $event->orderId );
		} catch ( \Throwable $e ) {
			// Transient API error — a later webhook replay will stamp it.
			return;
		}

		$href = ( isset( $api_order->links ) && $api_order->links->customerInvoice !== null )
			? $api_order->links->customerInvoice->href
			: '';

		if ( $href === '' ) {
			return;
		}

		if ( function_exists( 'update_pmpro_membership_order_meta' ) ) {
			update_pmpro_membership_order_meta( $order->id, self::ORDER_META_INVOICE_URL, $href );
		}
	}
}
