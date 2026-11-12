<?php
/**
 * Chargement : passerelle, route REST, blocs, traductions, désinstallation, chiffrement.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce\Tests\Unit;

use LabPay\WooCommerce\Crypto;
use LabPay\WooCommerce\Gateway;
use LabPay\WooCommerce\Phone;
use LabPay\WooCommerce\Plugin;
use LabPay\WooCommerce\Settings;
use LabPay\WooCommerce\Tests\Support\TestCase;
use LabPay\WooCommerce\Uninstaller;

final class PluginTest extends TestCase {

	public function test_gateway_and_webhook_route_are_registered(): void {
		$this->assertArrayHasKey( Gateway::ID, WC()->payment_gateways()->payment_gateways() );
		$this->assertContains( 'refunds', $this->gateway()->supports );

		do_action( 'rest_api_init', rest_get_server() );
		$this->assertArrayHasKey( '/labpay/v1/webhook', rest_get_server()->get_routes() );
	}

	public function test_hpos_and_block_checkout_compatibility_is_declared(): void {
		$this->assertNotFalse( has_action( 'plugins_loaded', array( Plugin::class, 'boot' ) ) );
		$this->assertNotFalse( has_action( 'before_woocommerce_init', array( Plugin::class, 'declare_woocommerce_compatibility' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_blocks_payment_method_type_registration', array( Plugin::instance(), 'register_blocks' ) ) );
	}

	public function test_public_pages_never_call_the_api(): void {
		$this->configure();
		$this->go_to( home_url( '/' ) );
		do_action( 'wp_enqueue_scripts' );

		$this->assertCount( 0, $this->http->requests );
		$this->assertFalse( wp_script_is( 'labpay-wc-checkout', 'enqueued' ), 'not on the home page' );
	}

	public function test_block_payment_method_data(): void {
		$this->configure();
		$method = new \LabPay\WooCommerce\Blocks\Payment_Method();
		$method->initialize();

		$this->assertTrue( $method->is_active() );
		$data = $method->get_payment_method_data();
		$this->assertTrue( $data['testMode'] );
		$this->assertContains( 'refunds', $data['supports'] );
		$this->assertSame( array( 'labpay-wc-blocks' ), $method->get_payment_method_script_handles() );
		$this->assertArrayNotHasKey( 'test_key', $data );
	}

	public function test_crypto_round_trip_and_tampering(): void {
		$cipher = Crypto::encrypt( 'secret' );

		$this->assertNotSame( $cipher, Crypto::encrypt( 'secret' ), 'random nonce' );
		$this->assertSame( 'secret', Crypto::decrypt( $cipher ) );
		$this->assertSame( '', Crypto::decrypt( substr( $cipher, 0, -2 ) . 'AA' ) );
		$this->assertSame( '', Crypto::decrypt( 'secret' ) );
		$this->assertSame( '', Crypto::encrypt( '' ) );
	}

	public function test_phone_normalization(): void {
		$this->assertSame( '0812345678', Phone::normalize( ' 081 234-5678 ' ) );
		$this->assertSame( '+243812345678', Phone::normalize( '00243 81 234 5678' ) );
		$this->assertSame( '+243812345678', Phone::normalize( '+243 (81) 234.5678' ) );
		$this->assertSame( '', Phone::normalize( 'abc' ) );
		$this->assertSame( '', Phone::normalize( '12' ) );
		$this->assertSame( 'AIRTEL_COD', Phone::provider( 'airtel_cod' ) );
		$this->assertNull( Phone::provider( 'x<y' ) );
		$this->assertNull( Phone::provider( '' ) );
	}

	public function test_french_fallback_for_other_french_locales(): void {
		$plugin = Plugin::instance();

		$this->assertSame( 'fr_FR', $plugin->french_fallback( 'fr_BE', 'labpay-for-woocommerce' ) );
		$this->assertSame( 'en_US', $plugin->french_fallback( 'en_US', 'labpay-for-woocommerce' ) );
		$this->assertSame( 'fr_BE', $plugin->french_fallback( 'fr_BE', 'other' ) );
	}

	public function test_french_translation_is_shipped(): void {
		$this->assertFileExists( LABPAY_WC_DIR . 'languages/labpay-for-woocommerce-fr_FR.mo' );
		unload_textdomain( 'labpay-for-woocommerce' );
		load_textdomain( 'labpay-for-woocommerce', LABPAY_WC_DIR . 'languages/labpay-for-woocommerce-fr_FR.mo', 'fr_FR' );

		$this->assertSame( 'Détection automatique', __( 'Detect automatically', 'labpay-for-woocommerce' ) );
		/* translators: %s: order number. */
		$this->assertSame( 'Commande 42', sprintf( __( 'Order %s', 'labpay-for-woocommerce' ), '42' ) );
		unload_textdomain( 'labpay-for-woocommerce' );
	}

	public function test_uninstall_removes_settings_and_caches(): void {
		$this->configure();
		set_transient( 'labpay_wc_providers_test', array(), HOUR_IN_SECONDS );
		add_option( 'labpay_wc_lock_12', '1' );

		Uninstaller::run();
		wp_cache_flush();

		$this->assertFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( get_transient( 'labpay_wc_providers_test' ) );
		$this->assertFalse( get_option( 'labpay_wc_lock_12' ) );
	}
}
