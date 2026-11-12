<?php
/**
 * Passerelle : disponibilité, réglages chiffrés, demande de paiement.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce\Tests\Unit;

use LabPay\WooCommerce\Crypto;
use LabPay\WooCommerce\Order_Payments;
use LabPay\WooCommerce\Settings;
use LabPay\WooCommerce\Tests\Support\TestCase;

final class GatewayTest extends TestCase {

	public function test_available_only_with_the_key_of_the_mode_and_cdf_or_usd(): void {
		$this->configure( array( 'test_key' => '' ) );
		$this->assertFalse( $this->gateway()->is_available(), 'test mode without test key' );

		$this->configure();
		$this->assertTrue( $this->gateway()->is_available() );

		update_option( 'woocommerce_currency', 'USD' );
		$this->assertTrue( $this->gateway()->is_available() );

		update_option( 'woocommerce_currency', 'EUR' );
		$this->assertFalse( $this->gateway()->is_available(), 'EUR is not accepted' );

		update_option( 'woocommerce_currency', 'CDF' );
		$this->configure(
			array(
				'testmode'  => 'no',
				'api_token' => '',
			)
		);
		$this->assertFalse( $this->gateway()->is_available(), 'live mode without token' );
	}

	public function test_secrets_are_encrypted_kept_when_unchanged_and_checked(): void {
		$this->configure();
		$gateway = $this->gateway();

		$encrypted = $gateway->validate_api_token_field( 'api_token', 'live_new_token_123' );
		$this->assertTrue( Crypto::is_encrypted( $encrypted ) );
		$this->assertSame( 'live_new_token_123', Crypto::decrypt( $encrypted ) );

		$stored = (string) $gateway->get_option( 'test_key' );
		$this->assertSame( $stored, $gateway->validate_test_key_field( 'test_key', $stored ), 'unchanged ciphertext is kept' );
		$this->assertSame( '', $gateway->validate_test_key_field( 'test_key', Crypto::encrypt( 'test_forged' ) ), 'another ciphertext is not accepted' );
		$this->assertSame( '', $gateway->validate_webhook_secret_field( 'webhook_secret', '' ), 'empty removes the secret' );

		// Une clé de test dans le champ live est refusée : l'ancienne valeur reste.
		$this->assertSame( (string) $gateway->get_option( 'api_token' ), $gateway->validate_api_token_field( 'api_token', 'test_abc' ) );
		$this->assertSame( 'WC', $gateway->validate_reference_prefix_field( 'reference_prefix', '  !!' ) );
		$this->assertSame( 'SHOP2', $gateway->validate_reference_prefix_field( 'reference_prefix', 'SHOP 2' ) );
	}

	public function test_pending_deposit_redirects_to_the_waiting_page(): void {
		$this->configure();
		$order = $this->order();
		$this->http->push( 201, array( 'data' => self::api_payment( array( 'reference' => 'WC-' . $order->get_id() . '-1' ) ) ) );
		$_POST['labpay_phone']    = '081 345-6789';
		$_POST['labpay_provider'] = 'vodacom_mpesa_cod';

		$result = $this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( $order->get_checkout_order_received_url(), $result['redirect'] );

		$request = $this->http->last();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertStringEndsWith( '/api/V2/deposits', $request['url'] );
		$this->assertSame( 'Bearer ' . self::TEST_KEY, $request['headers']['authorization'] );
		$this->assertStringContainsString( 'labpay-woocommerce/', $request['headers']['user-agent'] );
		$this->assertSame( '1500.00', $request['body']['amount'] );
		$this->assertSame( 'CDF', $request['body']['currency'] );
		$this->assertSame( '0813456789', $request['body']['phone'] );
		$this->assertSame( 'VODACOM_MPESA_COD', $request['body']['provider'] );
		$this->assertSame( 'WC-' . $order->get_id() . '-1', $request['body']['reference'] );
		$this->assertSame( (string) $order->get_id(), $request['body']['metadata']['wc_order_id'] );
		$this->assertSame( 'Order ' . $order->get_order_number(), $request['body']['customer_message'] );
		$this->assertArrayNotHasKey( 'callback_url', $request['body'], 'http site: no callback_url' );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->has_status( 'pending' ) );
		$this->assertSame( 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', $order->get_meta( Order_Payments::META_PAYMENT_ID ) );
		$this->assertSame( 'test', $order->get_meta( Order_Payments::META_MODE ) );
		$this->assertSame( '', $order->get_transaction_id() );
	}

	public function test_callback_url_is_sent_over_https(): void {
		$this->configure();
		$order = $this->order();
		add_filter( 'labpay_wc_callback_url', static fn() => 'https://shop.example/wp-json/labpay/v1/webhook' );
		$this->http->push( 201, array( 'data' => self::api_payment() ) );
		$_POST['labpay_phone'] = '0813456789';

		$this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'https://shop.example/wp-json/labpay/v1/webhook', $this->http->last()['body']['callback_url'] );
	}

	public function test_completed_at_once_pays_the_order(): void {
		$this->configure();
		$order = $this->order();
		$this->http->push(
			200,
			array(
				'data' => self::api_payment(
					array(
						'status'                  => 'completed',
						'provider_transaction_id' => 'MP123',
					)
				),
			)
		);
		$_POST['labpay_phone'] = '0813456789';

		$result = $this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', $order->get_transaction_id() );
		$this->assertStringContainsString( 'MP123', implode( "\n", self::notes( $order ) ) );
	}

	public function test_refused_payment_fails_and_the_next_attempt_uses_a_new_reference(): void {
		$this->configure();
		$order = $this->order();
		$this->http->push(
			422,
			array(
				'data' => self::api_payment(
					array(
						'status'  => 'failed',
						'failure' => array(
							'code'    => 'INSUFFICIENT_FUNDS',
							'message' => 'Solde insuffisant',
						),
					)
				),
			)
		);
		$_POST['labpay_phone'] = '0813456789';

		$result = $this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 1, wc_notice_count( 'error' ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->has_status( 'failed' ) );
		$this->assertStringContainsString( 'INSUFFICIENT_FUNDS', implode( "\n", self::notes( $order ) ) );

		// Nouvelle tentative : nouvelle référence (la première est définitivement échouée).
		$this->http->push( 201, array( 'data' => self::api_payment( array( 'id' => 'DEP-SECOND' ) ) ) );
		$result = $this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'WC-' . $order->get_id() . '-2', $this->http->last()['body']['reference'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( array( 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', 'DEP-SECOND' ), $order->get_meta( Order_Payments::META_PAYMENT_IDS ) );
	}

	public function test_network_error_keeps_the_same_reference_for_the_retry(): void {
		$this->configure();
		$order = $this->order();
		// Le SDK relance une fois (référence fournie : sans risque), puis abandonne.
		$this->http->push_network_error()->push_network_error();
		$_POST['labpay_phone'] = '0813456789';

		$result = $this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertCount( 2, $this->http->requests );
		$this->assertSame( $this->http->requests[0]['body']['reference'], $this->http->requests[1]['body']['reference'] );

		$this->http->push( 201, array( 'data' => self::api_payment() ) );
		$this->gateway()->process_payment( $order->get_id() );
		$this->assertSame( 'WC-' . $order->get_id() . '-1', $this->http->last()['body']['reference'] );
	}

	public function test_invalid_phone_is_refused_before_any_request(): void {
		$this->configure();
		$order = $this->order();
		$order->set_billing_phone( '' );
		$order->save();
		$_POST['labpay_phone'] = 'abc';

		$this->assertFalse( $this->gateway()->validate_fields() );
		$this->assertSame( 'failure', $this->gateway()->process_payment( $order->get_id() )['result'] );
		$this->assertCount( 0, $this->http->requests );
	}

	public function test_api_error_shows_a_customer_message_and_logs_no_secret(): void {
		$this->configure( array( 'debug' => 'yes' ) );
		$logged = array();
		add_filter(
			'woocommerce_logger_log_message',
			static function ( $message ) use ( &$logged ) {
				$logged[] = $message;

				return $message;
			}
		);
		$order = $this->order();
		$this->http->push_error( 422, 'INVALID_PHONE_NUMBER' );
		$_POST['labpay_phone'] = '0813456789';

		$result = $this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$notices = wc_get_notices( 'error' );
		$this->assertStringContainsString( 'not valid', $notices[0]['notice'] );
		$this->assertNotEmpty( $logged );
		$all = implode( "\n", $logged );
		$this->assertStringContainsString( 'INVALID_PHONE_NUMBER', $all );
		$this->assertStringNotContainsString( self::TEST_KEY, $all );
		$this->assertStringNotContainsString( '0813456789', $all, 'phone numbers are masked' );
	}

	public function test_live_mode_uses_the_live_token(): void {
		$this->configure( array( 'testmode' => 'no' ) );
		$order = $this->order();
		$this->http->push( 201, array( 'data' => self::api_payment( array( 'livemode' => true ) ) ) );
		$_POST['labpay_phone'] = '+243813456789';

		$this->gateway()->process_payment( $order->get_id() );

		$this->assertSame( 'Bearer ' . self::LIVE_TOKEN, $this->http->last()['headers']['authorization'] );
		$this->assertSame( '+243813456789', $this->http->last()['body']['phone'] );
		$this->assertSame( 'live', wc_get_order( $order->get_id() )->get_meta( Order_Payments::META_MODE ) );
		$this->assertSame( 'live', ( new Settings() )->mode() );
	}
}
