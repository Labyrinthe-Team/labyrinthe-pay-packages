<?php
/**
 * Webhook : signature, mise à jour de la commande, idempotence.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce\Tests\Unit;

use LabPay\WooCommerce\Order_Payments;
use LabPay\WooCommerce\Tests\Support\TestCase;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Webhook\Webhook;

final class WebhookTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Envoie une notification signée (ou non) à la route du plugin.
	 *
	 * @param array<string, mixed> $payment   Paiement.
	 * @param string|null          $signature En-tête (null : signature valide).
	 * @param string               $event     Événement.
	 * @param int|null             $signed_at Horodatage signé (null : maintenant).
	 */
	private function deliver( array $payment, ?string $signature = null, string $event = 'payment.completed', ?int $signed_at = null ): \WP_REST_Response {
		$body    = (string) wp_json_encode(
			array(
				'event'   => $event,
				'payment' => $payment,
				'sent_at' => '2026-11-11T10:01:00+00:00',
			)
		);
		$request = new \WP_REST_Request( 'POST', '/labpay/v1/webhook' );
		$request->set_body( $body );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-Labyrinthe-Event', $event );
		$request->set_header( 'X-Labyrinthe-Delivery', '42' );
		$request->set_header( 'X-Labyrinthe-Signature', $signature ?? Webhook::sign( $body, self::WEBHOOK_SECRET, $signed_at ) );

		return rest_do_request( $request );
	}

	/**
	 * Paiement de la commande.
	 *
	 * @param \WC_Order            $order     Commande.
	 * @param array<string, mixed> $overrides Champs modifiés.
	 * @return array<string, mixed>
	 */
	private static function payment_of( \WC_Order $order, array $overrides = array() ): array {
		return self::api_payment(
			array_merge(
				array(
					'reference' => 'WC-' . $order->get_id() . '-1',
					'metadata'  => array( 'wc_order_id' => (string) $order->get_id() ),
				),
				$overrides
			)
		);
	}

	public function test_completed_notification_pays_the_order_once(): void {
		$this->configure();
		$order   = $this->order_with_deposit();
		$payment = self::payment_of(
			$order,
			array(
				'status'                  => 'completed',
				'provider_transaction_id' => 'MP-777',
			)
		);

		$response = $this->deliver( $payment );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'completed', $response->get_data()['result'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', $order->get_transaction_id() );
		$notes = count( self::notes( $order ) );

		// Même notification livrée une seconde fois : rien ne change.
		$response = $this->deliver( $payment );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( $notes, self::notes( wc_get_order( $order->get_id() ) ) );
		$this->assertCount( 0, $this->http->requests, 'no API call' );
	}

	public function test_invalid_signature_is_refused(): void {
		$this->configure();
		$order   = $this->order_with_deposit();
		$payment = self::payment_of( $order, array( 'status' => 'completed' ) );

		$this->assertSame( 400, $this->deliver( $payment, 't=' . time() . ',v1=' . str_repeat( 'a', 64 ) )->get_status() );
		$this->assertSame( 400, $this->deliver( $payment, '' )->get_status() );
		// Signature d'un autre secret.
		$this->assertSame( 400, $this->deliver( $payment, Webhook::sign( 'x', 'whsec_other' ) )->get_status() );
		// Signature valide mais trop ancienne (rejeu).
		$this->assertSame( 400, $this->deliver( $payment, null, 'payment.completed', time() - 3600 )->get_status() );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
	}

	public function test_without_secret_notifications_are_postponed(): void {
		$this->configure( array( 'webhook_secret' => '' ) );
		$order = $this->order_with_deposit();

		$this->assertSame( 503, $this->deliver( self::payment_of( $order, array( 'status' => 'completed' ) ) )->get_status() );
	}

	public function test_failed_notification_fails_the_order(): void {
		$this->configure();
		$order = $this->order_with_deposit();

		$response = $this->deliver(
			self::payment_of(
				$order,
				array(
					'status'  => 'failed',
					'failure' => array(
						'code'    => 'PAYER_DECLINED',
						'message' => null,
					),
				)
