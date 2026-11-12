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
