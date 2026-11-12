<?php
/**
 * Journal WooCommerce (WooCommerce > État > Journaux, source « labpay »).
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Les erreurs et avertissements sont toujours journalisés ; le reste seulement avec
 * l'option « Journal détaillé ». Jamais de token, de clé, de secret ni de signature :
 * uniquement des identifiants de paiement, des codes d'erreur, des statuts et des
 * numéros masqués.
 */
final class Logger {

	/** Source des journaux WooCommerce. */
	public const SOURCE = 'labpay';

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Journalise un message.
	 *
	 * @param string               $level   debug, info, warning ou error.
	 * @param string               $message Message (sans secret).
	 * @param array<string, mixed> $context Contexte (sans secret).
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! in_array( $level, array( 'warning', 'error' ), true ) && ! $this->settings->debug() ) {
			return;
		}
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		if ( array() !== $context ) {
			$message .= ' ' . (string) wp_json_encode( self::redact( $context ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
	}

	/**
	 * Numéro masqué : 0812345678 → 081****678.
	 *
	 * @param string $phone Numéro.
	 */
	public static function mask_phone( string $phone ): string {
		$length = strlen( $phone );
		if ( $length <= 6 ) {
			return str_repeat( '*', $length );
		}

		return substr( $phone, 0, 3 ) . str_repeat( '*', $length - 6 ) . substr( $phone, -3 );
	}

	/**
	 * Retire du contexte toute valeur dont la clé évoque un secret (défense en profondeur).
	 *
	 * @param array<string, mixed> $context Contexte.
	 * @return array<string, mixed>
	 */
	private static function redact( array $context ): array {
		foreach ( $context as $key => $value ) {
			if ( 1 === preg_match( '/token|key|secret|signature|authorization|password/i', (string) $key ) ) {
				$context[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
			}
		}

		return $context;
	}
}
