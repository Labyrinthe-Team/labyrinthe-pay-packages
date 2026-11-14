<?php
/**
 * Paiement LabPay dans la validation de commande en blocs (WooCommerce Blocks).
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use LabPay\WooCommerce\Ajax;
use LabPay\WooCommerce\Gateway;
use LabPay\WooCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Le script (assets/js/blocks.js, sans étape de build) affiche les mêmes champs que la
 * validation classique et les transmet dans payment_data : WooCommerce les remet dans
 * $_POST avant d'appeler Gateway::process_payment(), le traitement est donc identique.
 */
final class Payment_Method extends AbstractPaymentMethodType {

	/**
	 * Identifiant de la méthode (celui de la passerelle).
	 *
	 * @var string
	 */
	protected $name = Gateway::ID;

	/**
	 * Lit les réglages.
	 */
	public function initialize(): void {
		$settings       = get_option( Settings::OPTION, array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	/**
	 * Active si la passerelle est disponible (activée, clé du mode, devise acceptée : CDF ou USD par défaut, filtre `labpay_wc_currencies`).
	 */
	public function is_active(): bool {
		$gateways = function_exists( 'WC' ) && null !== WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways[ Gateway::ID ] ?? null;

		return $gateway instanceof Gateway && $gateway->is_available();
	}

	/**
	 * Script de la méthode.
	 *
	 * @return list<string>
	 */
	public function get_payment_method_script_handles(): array {
		$asset = include LABPAY_WC_DIR . 'assets/js/blocks.asset.php';
		$asset = is_array( $asset ) ? $asset : array();
		wp_register_script(
			'labpay-wc-blocks',
			LABPAY_WC_URL . 'assets/js/blocks.js',
			(array) ( $asset['dependencies'] ?? array() ),
			(string) ( $asset['version'] ?? LABPAY_WC_VERSION ),
			true
		);
		wp_set_script_translations( 'labpay-wc-blocks', 'labpay-for-woocommerce', LABPAY_WC_DIR . 'languages' );
		wp_register_style( 'labpay-wc', LABPAY_WC_URL . 'assets/css/labpay.css', array(), LABPAY_WC_VERSION );
		wp_enqueue_style( 'labpay-wc' );

		return array( 'labpay-wc-blocks' );
	}

	/**
	 * Données transmises au script.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		$gateways = function_exists( 'WC' ) && null !== WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		$gateway  = $gateways[ Gateway::ID ] ?? null;
		$settings = new Settings();

		return array(
			'title'       => $gateway instanceof Gateway ? $gateway->get_title() : '',
			'description' => $gateway instanceof Gateway ? wp_strip_all_tags( $gateway->get_description() ) : '',
			'supports'    => $gateway instanceof Gateway ? array_values( array_filter( $gateway->supports, array( $gateway, 'supports' ) ) ) : array( 'products' ),
			'testMode'    => 'test' === $settings->mode(),
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( Ajax::CHECKOUT_NONCE ),
		);
	}
}
