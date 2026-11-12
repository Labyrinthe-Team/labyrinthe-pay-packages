<?php
/**
 * Création du client du SDK PHP LabPay à partir des réglages.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

use LabPay\WooCommerce\Http\Wp_Http_Client;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Client;
use LabPay\WooCommerce\Vendor\Psr\Http\Client\ClientInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Fabrique du client LabPay (SDK PHP préfixé).
 */
final class Client_Factory {

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
	 * Client configuré avec la clé du mode demandé.
	 *
	 * Un paiement est toujours suivi (statut, remboursement) avec la clé du mode dans
	 * lequel il a été créé : un paiement de test n'existe pas pour le token live.
	 *
	 * @param string $mode        live ou test.
	 * @param float  $timeout     Délai maximal d'une requête, en secondes.
	 * @param int    $max_retries Relances automatiques (uniquement quand c'est sans risque).
	 * @throws Configuration_Exception Aucune clé configurée pour ce mode.
	 */
	public function make( string $mode, float $timeout = 20.0, int $max_retries = 1 ): Client {
		$api_key = $this->settings->key_for( $mode );
		if ( '' === $api_key ) {
			throw new Configuration_Exception(
				'test' === $mode
					? esc_html__( 'No LabPay test key is configured.', 'labpay-for-woocommerce' )
					: esc_html__( 'No LabPay API token is configured.', 'labpay-for-woocommerce' )
			);
		}

		/**
		 * Client HTTP PSR-18 utilisé par le SDK (par défaut : API HTTP de WordPress).
		 *
		 * @param ClientInterface $http_client Client HTTP (interface préfixée LabPay\WooCommerce\Vendor\Psr\Http\Client\ClientInterface).
		 */
		$http_client = apply_filters( 'labpay_wc_http_client', new Wp_Http_Client( $timeout ) );
		if ( ! $http_client instanceof ClientInterface ) {
			$http_client = new Wp_Http_Client( $timeout );
		}

		return new Client(
			apiKey: $api_key,
			baseUrl: $this->base_url(),
			timeout: $timeout,
			maxRetries: max( 0, min( 3, $max_retries ) ),
			maxRetryWait: 3.0,
			httpClient: $http_client,
			userAgentSuffix: self::user_agent(),
		);
	}

	/**
	 * URL de l'API : https://api.labyrinthe-rdc.com/api par défaut ; constante
	 * LABPAY_WC_BASE_URL ou filtre labpay_wc_base_url pour un autre environnement.
	 */
	public function base_url(): ?string {
		$url = defined( 'LABPAY_WC_BASE_URL' ) && is_string( LABPAY_WC_BASE_URL ) ? LABPAY_WC_BASE_URL : null;

		/**
		 * URL de l'API LabPay (null : production). « /V2 » est ajouté par le SDK.
		 *
		 * @param string|null $url URL, par exemple http://localhost:8000/api.
		 */
		$url = apply_filters( 'labpay_wc_base_url', $url );

		return is_string( $url ) && '' !== trim( $url ) ? trim( $url ) : null;
	}

	/**
	 * Suffixe du User-Agent : « labpay-woocommerce/0.1.0 WordPress/6.8 WooCommerce/10.0 ».
	 */
	public static function user_agent(): string {
		$user_agent = 'labpay-woocommerce/' . LABPAY_WC_VERSION . ' WordPress/' . get_bloginfo( 'version' );
		if ( defined( 'WC_VERSION' ) ) {
			$user_agent .= ' WooCommerce/' . WC_VERSION;
		}

		return $user_agent;
	}
}
