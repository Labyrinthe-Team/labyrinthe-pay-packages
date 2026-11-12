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

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Secret déchiffré ('' s'il n'est pas configuré ou illisible).
	 *
	 * @param string $key api_token, test_key ou webhook_secret.
	 */
	public function secret( string $key ): string {
		return in_array( $key, self::SECRET_FIELDS, true ) ? Crypto::decrypt( $this->get( $key ) ) : '';
	}

	/**
	 * Vrai si un secret est enregistré mais illisible (salts WordPress changés).
	 *
	 * @param string $key Clé.
	 */
	public function secret_unreadable( string $key ): bool {
		$stored = $this->get( $key );

		return '' !== $stored && '' === Crypto::decrypt( $stored );
	}

	/**
	 * Mode des nouveaux paiements : test (clé test_…) ou live.
	 */
	public function mode(): string {
		return 'yes' === $this->get( 'testmode' ) ? 'test' : 'live';
	}

	/**
	 * Clé API du mode demandé.
	 *
	 * @param string $mode live ou test.
	 */
	public function key_for( string $mode ): string {
		return 'test' === $mode ? $this->secret( 'test_key' ) : $this->secret( 'api_token' );
	}

	/**
	 * Préfixe des références (lettres, chiffres, . _ : -), « WC » par défaut.
	 */
	public function reference_prefix(): string {
		$prefix = (string) preg_replace( '/[^A-Za-z0-9._:\-]/', '', $this->get( 'reference_prefix' ) );

		return '' === $prefix ? 'WC' : substr( $prefix, 0, 20 );
	}

	/**
	 * Journal détaillé activé.
	 */
	public function debug(): bool {
		return 'yes' === $this->get( 'debug' );
	}
}
