<?php
/**
 * Lecture des réglages de la passerelle (option woocommerce_labpay_settings).
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Les réglages sont édités par la page WooCommerce de la passerelle (Gateway) ; cette
 * classe les lit sans instancier la passerelle (webhook, AJAX, remboursements) et
 * déchiffre les secrets à la demande, jamais à l'avance.
 */
final class Settings {

	/** Option WordPress (nom imposé par WC_Settings_API pour l'identifiant « labpay »). */
	public const OPTION = 'woocommerce_labpay_settings';

	/** Champs chiffrés en base. */
	public const SECRET_FIELDS = array( 'api_token', 'test_key', 'webhook_secret' );

	/** Devises acceptées par l'API V2 (pays ouverts : RD Congo). */
	public const CURRENCIES = array( 'CDF', 'USD' );

	/**
	 * Valeurs par défaut.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			'enabled'          => 'no',
			'title'            => '',
			'description'      => '',
			'testmode'         => 'yes',
			'api_token'        => '',
			'test_key'         => '',
			'webhook_secret'   => '',
			'reference_prefix' => 'WC',
			'debug'            => 'no',
		);
	}

	/**
	 * Valeur brute d'un réglage.
	 *
	 * @param string $key Clé.
	 */
	public function get( string $key ): string {
		$options = get_option( self::OPTION, array() );
		$options = is_array( $options ) ? $options : array();
		$value   = $options[ $key ] ?? ( self::defaults()[ $key ] ?? '' );

