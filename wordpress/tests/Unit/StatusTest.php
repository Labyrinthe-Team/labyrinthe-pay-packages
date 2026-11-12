<?php
/**
 * Page d'attente : statut de la commande, synchronisation, prédiction de l'opérateur.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce\Tests\Unit;

use LabPay\WooCommerce\Order_Payments;
use LabPay\WooCommerce\Plugin;
use LabPay\WooCommerce\Tests\Support\TestCase;

final class StatusTest extends TestCase {

	public function test_status_needs_the_order_key(): void {
		$this->configure();
		$order = $this->order_with_deposit();

		$this->assertNull( Plugin::instance()->ajax->status( $order->get_id(), 'wc_order_wrong' ) );
		$this->assertNull( Plugin::instance()->ajax->status( $order->get_id(), '' ) );
		$this->assertNull( Plugin::instance()->ajax->status( 999999, $order->get_order_key() ) );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_pending_status_is_synced_then_rate_limited(): void {
		$this->configure();
		$order = $this->order_with_deposit();
		$this->http->push( 200, array( 'data' => self::api_payment( array( 'metadata' => array( 'wc_order_id' => (string) $order->get_id() ) ) ) ) );

		$status = Plugin::instance()->ajax->status( $order->get_id(), $order->get_order_key() );

		$this->assertSame( 'pending', $status['state'] );
		$this->assertStringEndsWith( '/api/V2/payments/DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6/sync', $this->http->last()['url'] );
		$this->assertSame( 'Bearer ' . self::TEST_KEY, $this->http->last()['headers']['authorization'], 'test payment: test key' );

		// Seconde interrogation dans l'intervalle : pas d'appel à l'API.
		$status = Plugin::instance()->ajax->status( $order->get_id(), $order->get_order_key() );
		$this->assertSame( 'pending', $status['state'] );
		$this->assertCount( 1, $this->http->requests );
	}

	public function test_completed_sync_pays_the_order_and_redirects(): void {
		$this->configure( array( 'testmode' => 'no' ) );
		$order = $this->order_with_deposit( 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', 'test' );
		$this->http->push( 200, array( 'data' => self::api_payment( array( 'status' => 'completed' ) ) ) );

		$status = Plugin::instance()->ajax->status( $order->get_id(), $order->get_order_key() );

		$this->assertSame( 'completed', $status['state'] );
		$this->assertSame( wc_get_order( $order->get_id() )->get_checkout_order_received_url(), $status['redirect'] );
		$this->assertTrue( wc_get_order( $order->get_id() )->is_paid() );
		// Le paiement a été créé en test : il est suivi avec la clé de test, même passé en live.
		$this->assertSame( 'Bearer ' . self::TEST_KEY, $this->http->last()['headers']['authorization'] );
	}

	public function test_failed_status_offers_to_pay_again(): void {
		$this->configure();
		$order = $this->order_with_deposit();
		$this->http->push( 200, array( 'data' => self::api_payment( array( 'status' => 'failed' ) ) ) );

		$status = Plugin::instance()->ajax->status( $order->get_id(), $order->get_order_key() );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( wc_get_order( $order->get_id() )->get_checkout_payment_url(), $status['retry_url'] );
	}

	public function test_sync_error_keeps_waiting(): void {
		$this->configure();
		$order = $this->order_with_deposit();
		$this->http->push_error( 503, 'MAINTENANCE' );

		$status = Plugin::instance()->ajax->status( $order->get_id(), $order->get_order_key() );

		$this->assertSame( 'pending', $status['state'] );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_meta( Order_Payments::META_STATUS ) );
	}

	public function test_waiting_page_renders_the_polling_box(): void {
		$this->configure();
		$order = $this->order_with_deposit();

		ob_start();
		do_action( 'woocommerce_thankyou_labpay', $order->get_id() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="labpay-wc-waiting"', $html );
		$this->assertStringContainsString( 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', $html );
		$this->assertTrue( wp_script_is( 'labpay-wc-waiting', 'enqueued' ) );
	}

	public function test_prediction_and_rate_limit(): void {
		$this->configure();
		set_transient(
			'labpay_wc_providers_test',
			array(
				array(
					'code'       => 'VODACOM_MPESA_COD',
					'name'       => 'M-Pesa',
					'currencies' => array( 'CDF', 'USD' ),
				),
			),
			HOUR_IN_SECONDS
		);
		$this->http->push(
			200,
			array(
				'data' => array(
					'phone'    => '243813456789',
					'provider' => 'VODACOM_MPESA_COD',
					'country'  => 'COD',
				),
			)
		);

		$result = Plugin::instance()->ajax->predict( '0813456789', '198.51.100.7' );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'M-Pesa', $result['name'] );
		$this->assertSame( array( 'phone' => '0813456789' ), $this->http->last()['body'] );

		$this->http->push_error( 422, 'INVALID_PHONE_NUMBER' );
		$this->assertFalse( Plugin::instance()->ajax->predict( '0000000', '198.51.100.7' )['valid'] );

		set_transient( 'labpay_wc_rate_' . md5( '198.51.100.7' ), 30, MINUTE_IN_SECONDS );
		$this->assertTrue( Plugin::instance()->ajax->predict( '0813456789', '198.51.100.7' )['limited'] );
		$this->assertSame( 0, $this->http->pending() );
	}

	public function test_providers_are_filtered_by_currency_and_cached(): void {
		$this->configure();
		$this->http->push(
			200,
			array(
				'data' => array(
					array(
						'provider'   => 'VODACOM_MPESA_COD',
						'country'    => 'COD',
						'name'       => 'Vodacom M-Pesa',
						'currencies' => array(
							'CDF' => array( 'min_amount' => '100' ),
							'USD' => array(),
						),
					),
					array(
						'provider'                   => 'ORANGE_COD',
						'country'                    => 'COD',
						'name'                       => 'Orange',
						'name_displayed_to_customer' => 'Orange Money',
						'currencies'                 => array( 'USD' => array() ),
					),
				),
			)
		);

		$cdf = Plugin::instance()->ajax->providers( 'CDF' );
		$usd = Plugin::instance()->ajax->providers( 'USD' );

		$this->assertSame(
			array(
				array(
					'code' => 'VODACOM_MPESA_COD',
					'name' => 'Vodacom M-Pesa',
				),
			),
			$cdf
		);
		$this->assertCount( 2, $usd );
		$this->assertSame( 'Orange Money', $usd[1]['name'] );
		$this->assertCount( 1, $this->http->requests, 'cached' );
		$this->assertStringContainsString( 'operation=deposit', $this->http->last()['url'] );
	}
}
