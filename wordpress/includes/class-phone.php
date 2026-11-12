<?php
/**
 * Numéro Mobile Money saisi par le client.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Nettoyage seulement : la validation complète (pays, opérateur) est faite par LabPay.
 */
final class Phone {

	/**
	 * « +243 81 234-5678 » → « +243812345678 », « 00243… » → « +243… », « 081 234 5678 » → « 0812345678 ».
	 * '' si la saisie ne ressemble pas à un numéro (6 à 15 chiffres, « + » facultatif).
	 *
	 * @param string $raw Saisie.
	 */
	public static function normalize( string $raw ): string {
		$phone = (string) preg_replace( '/[\s\-\.\(\)\/]/u', '', trim( $raw ) );
		if ( 0 === strpos( $phone, '00' ) ) {
			$phone = '+' . substr( $phone, 2 );
		}

		return 1 === preg_match( '/^\+?\d{6,15}$/', $phone ) ? $phone : '';
	}

	/**
	 * Code opérateur valide (lettres, chiffres, _), sinon null (détection automatique).
	 *
	 * @param string $raw Saisie.
	 */
	public static function provider( string $raw ): ?string {
		$provider = strtoupper( trim( $raw ) );

		return 1 === preg_match( '/^[A-Z0-9_]{1,64}$/', $provider ) ? $provider : null;
	}
}
