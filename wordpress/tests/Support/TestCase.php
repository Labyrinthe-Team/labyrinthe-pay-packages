<?php
/**
 * Base des tests du plugin.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce\Tests\Support;

use LabPay\WooCommerce\Crypto;
use LabPay\WooCommerce\Gateway;
use LabPay\WooCommerce\Order_Payments;
use LabPay\WooCommerce\Settings;

/**
 * Réglages réinitialisés et API simulée à chaque test.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/** Clé de test fictive. */
	public const TEST_KEY = 'test_0123456789abcdefghijklmnopqrstuv';

	/** Token live fictif. */
	public const LIVE_TOKEN = 'live_token_9876543210zyxwvutsrqponmlk';

	/** Secret de signature fictif. */
	public const WEBHOOK_SECRET = 'whsec_test_secret_for_unit_tests';

	protected HttpMock $http;

	public function set_up(): void {
		parent::set_up();
		$this->http = new HttpMock();
		$this->http->install();
		delete_option( Settings::OPTION );
		update_option( 'woocommerce_currency', 'CDF' );
		$_POST                  = array();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		wc_clear_notices();
		$this->reset_gateways();
	}

	public function tear_down(): void {
		$this->http->uninstall();
		$_POST = array();
		wc_clear_notices();
		parent::tear_down();
	}

	/**
	 * Enregistre des réglages (clés chiffrées, mode test, passerelle activée).
	 *
	 * @param array<string, string> $settings Réglages.
	 */
	protected function configure( array $settings = array() ): Settings {
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				array(
					'enabled'        => 'yes',
					'api_token'      => Crypto::encrypt( self::LIVE_TOKEN ),
					'test_key'       => Crypto::encrypt( self::TEST_KEY ),
					'webhook_secret' => Crypto::encrypt( self::WEBHOOK_SECRET ),
				),
				$settings
			)
		);
		$this->reset_gateways();

		return new Settings();
	}

	/**
	 * Passerelle relue depuis les réglages.
	 */
	protected function gateway(): Gateway {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertInstanceOf( Gateway::class, $gateways[ Gateway::ID ] );

		return $gateways[ Gateway::ID ];
	}

	/**
	 * Commande de 1500 CDF payée par LabPay (en attente de paiement).
	 *
	 * @param string $total    Total.
	 * @param string $currency Devise.
	 */
	protected function order( string $total = '1500', string $currency = 'CDF' ): \WC_Order {
		$order = wc_create_order();
		$order->set_currency( $currency );
		$order->set_payment_method( Gateway::ID );
		$order->set_billing_phone( '0813456789' );
		$order->set_billing_email( 'client@example.org' );
		$order->set_total( $total );
		$order->save();

		return $order;
	}

	/**
	 * Commande avec une tentative de dépôt déjà créée.
	 *
	 * @param string $payment_id Identifiant du dépôt.
	 * @param string $mode       Mode.
	 */
	protected function order_with_deposit( string $payment_id = 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', string $mode = 'test' ): \WC_Order {
		$order = $this->order();
		$order->update_meta_data( Order_Payments::META_PAYMENT_ID, $payment_id );
		$order->update_meta_data( Order_Payments::META_PAYMENT_IDS, array( $payment_id ) );
		$order->update_meta_data( Order_Payments::META_STATUS, 'pending' );
		$order->update_meta_data( Order_Payments::META_MODE, $mode );
		$order->update_meta_data( Order_Payments::META_ATTEMPT, '1' );
		$order->save();

		return $order;
	}

	/**
	 * Paiement tel que renvoyé par l'API (PaymentPresenter).
	 *
	 * @param array<string, mixed> $overrides Champs modifiés.
	 * @return array<string, mixed>
	 */
	protected static function api_payment( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                      => 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6',
				'reference'               => 'WC-1-1',
				'operation'               => 'deposit',
				'livemode'                => false,
				'status'                  => 'pending',
				'gateway_status'          => 'ACCEPTED',
				'amount'                  => '1500.00',
				'fee'                     => '52.50',
				'total_amount'            => '1552.50',
				'currency'                => 'CDF',
				'country'                 => 'COD',
				'phone'                   => '0813456789',
				'provider'                => 'VODACOM_MPESA_COD',
				'provider_transaction_id' => null,
				'failure'                 => null,
				'metadata'                => null,
				'created_at'              => '2026-11-11T10:00:00+00:00',
				'completed_at'            => null,
				'refunded_amount'         => '0.00',
			),
			$overrides
		);
	}

	/**
	 * Notes de la commande (texte).
	 *
	 * @param \WC_Order $order Commande.
	 * @return list<string>
	 */
	protected static function notes( \WC_Order $order ): array {
		return array_values( array_map( static fn( $note ): string => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) );
	}

	/**
	 * Recharge les passerelles (réglages relus).
	 */
	private function reset_gateways(): void {
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}
}
