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
