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
