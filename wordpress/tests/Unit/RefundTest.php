<?php
/**
 * Remboursements WooCommerce → remboursements LabPay.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce\Tests\Unit;

use LabPay\WooCommerce\Order_Payments;
use LabPay\WooCommerce\Tests\Support\TestCase;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Webhook\Webhook;

final class RefundTest extends TestCase {

	/**
	 * Commande payée par LabPay.
	 */
	private function paid_order(): \WC_Order {
		$order = $this->order_with_deposit();
		$order->update_meta_data( Order_Payments::META_STATUS, 'completed' );
		$order->payment_complete( 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6' );

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Remboursement tel que renvoyé par l'API.
	 *
	 * @param array<string, mixed> $overrides Champs modifiés.
	 * @return array<string, mixed>
	 */
	private static function api_refund( array $overrides = array() ): array {
		return self::api_payment(
			array_merge(
				array(
					'id'                  => 'RFD-01JCX9',
					'operation'           => 'refund',
					'amount'              => '500.00',
					'fee'                 => '0.00',
					'total_amount'        => '500.00',
					'refunded_payment_id' => 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6',
				),
				$overrides
			)
		);
	}

	public function test_partial_refund_calls_labpay(): void {
		$this->configure();
		$order = $this->paid_order();
		$this->http->push( 202, array( 'data' => self::api_refund() ) );

		$result = $this->gateway()->process_refund( $order->get_id(), 500, 'Article abîmé' );

		$this->assertTrue( $result );
		$request = $this->http->last();
		$this->assertStringEndsWith( '/api/V2/refunds', $request['url'] );
		$this->assertSame( 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', $request['body']['payment_id'] );
		$this->assertSame( '500.00', $request['body']['amount'] );
		$this->assertSame( 'WC-' . $order->get_id() . '-R1-1', $request['body']['reference'] );
		$this->assertSame( array( 'RFD-01JCX9' => 'pending' ), wc_get_order( $order->get_id() )->get_meta( Order_Payments::META_REFUNDS ) );
	}

	public function test_refused_refund_returns_an_error(): void {
		$this->configure();
		$order = $this->paid_order();
		$this->http->push_error( 409, 'REFUND_IN_PROGRESS' );

		$result = $this->gateway()->process_refund( $order->get_id(), 500 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringContainsString( 'REFUND_IN_PROGRESS', $result->get_error_message() );
	}

	public function test_unpaid_order_cannot_be_refunded(): void {
		$this->configure();
		$order = $this->order_with_deposit();

		$this->assertFalse( $this->gateway()->can_refund_order( $order ) );
		$this->assertInstanceOf( \WP_Error::class, $this->gateway()->process_refund( $order->get_id(), 100 ) );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_refund_notifications_add_a_note(): void {
		$this->configure();
		do_action( 'rest_api_init', rest_get_server() );
		$order = $this->paid_order();
		$this->http->push( 202, array( 'data' => self::api_refund() ) );
		$this->gateway()->process_refund( $order->get_id(), 500 );

		$body    = (string) wp_json_encode(
			array(
				'event'   => 'payment.failed',
				'payment' => self::api_refund(
					array(
						'status'   => 'failed',
						'failure'  => array(
							'code'    => 'RECIPIENT_NOT_FOUND',
							'message' => null,
						),
						'metadata' => array( 'wc_order_id' => (string) $order->get_id() ),
					)
				),
				'sent_at' => '2026-11-11T11:00:00+00:00',
			)
		);
		$request = new \WP_REST_Request( 'POST', '/labpay/v1/webhook' );
		$request->set_body( $body );
		$request->set_header( 'X-Labyrinthe-Signature', Webhook::sign( $body, self::WEBHOOK_SECRET ) );

		$this->assertSame( 'failed', rest_do_request( $request )->get_data()['result'] );
		$this->assertSame( 'failed', rest_do_request( $request )->get_data()['result'] );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'RFD-01JCX9' => 'failed' ), $order->get_meta( Order_Payments::META_REFUNDS ) );
		$failed = array_filter( self::notes( $order ), static fn( string $n ): bool => false !== strpos( $n, 'RECIPIENT_NOT_FOUND' ) );
		$this->assertCount( 1, $failed, 'one note even when delivered twice' );
		$this->assertTrue( $order->is_paid(), 'the order itself is unchanged' );
	}
}
