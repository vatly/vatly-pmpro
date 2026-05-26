<?php

declare(strict_types=1);

namespace Vatly\PMPro\Webhooks;

use Vatly\Fluent\Contracts\EventDispatcherInterface;

/**
 * Bridges fluent's typed events onto WordPress `do_action()`.
 *
 * Hook names follow the pattern `vatly_pmpro_<EventClassBasename>`, e.g.
 * `vatly_pmpro_OrderPaid`, so existing PMPro snippets can `add_action()` and
 * react with familiar machinery.
 */
class EventDispatcher implements EventDispatcherInterface {

	public function dispatch( object $event ): void {
		$class = get_class( $event );
		$hook  = $this->hook_name( $class );

		do_action( $hook, $event );

		// Also dispatch on a catch-all hook for tooling that wants every event.
		do_action( 'vatly_pmpro_event', $event );
	}

	private function hook_name( string $class ): string {
		$parts    = explode( '\\', $class );
		$basename = (string) end( $parts );

		return 'vatly_pmpro_' . $basename;
	}
}
