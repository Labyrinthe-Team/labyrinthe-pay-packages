<?php
/**
 * Actions AJAX publiques : statut d'une commande (page d'attente), opérateurs et prédiction.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Enum\Operation;

defined( 'ABSPATH' ) || exit;

/**
 * Toutes les actions vérifient un nonce ; le statut exige en plus la clé de la commande
 * (secret de l'URL de confirmation WooCommerce). La prédiction est limitée par adresse IP.
 */
final class Ajax {

	public const STATUS_ACTION    = 'labpay_wc_status';
	public const PROVIDERS_ACTION = 'labpay_wc_providers';
	public const PREDICT_ACTION   = 'labpay_wc_predict';
	public const CHECKOUT_NONCE   = 'labpay_wc_checkout';
	public const PREDICT_LIMIT    = 30;

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Fabrique du client.
	 *
	 * @var Client_Factory
	 */
	private Client_Factory $clients;

	/**
	 * Paiements des commandes.
	 *
	 * @var Order_Payments
	 */
	private Order_Payments $payments;

	/**
	 * Journal.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructeur.
	 *
	 * @param Settings       $settings Réglages.
	 * @param Client_Factory $clients  Fabrique du client.
	 * @param Order_Payments $payments Paiements.
	 * @param Logger         $logger   Journal.
	 */
	public function __construct( Settings $settings, Client_Factory $clients, Order_Payments $payments, Logger $logger ) {
		$this->settings = $settings;
		$this->clients  = $clients;
		$this->payments = $payments;
		$this->logger   = $logger;
	}

	/**
	 * Branche les actions (connecté ou non).
	 */
	public function register(): void {
		foreach ( array(
			self::STATUS_ACTION    => 'handle_status',
			self::PROVIDERS_ACTION => 'handle_providers',
			self::PREDICT_ACTION   => 'handle_predict',
		) as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Statut de la commande (page d'attente).
	 */
	public function handle_status(): void {
		check_ajax_referer( self::STATUS_ACTION, 'nonce' );
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$key      = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

		$payload = $this->status( $order_id, $key );
		if ( null === $payload ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'labpay-for-woocommerce' ) ), 403 );
		}
		wp_send_json_success( $payload );
	}

	/**
	 * Opérateurs proposés au paiement dans la devise de la boutique.
	 */
	public function handle_providers(): void {
		check_ajax_referer( self::CHECKOUT_NONCE, 'nonce' );
		wp_send_json_success( array( 'providers' => $this->providers( get_woocommerce_currency() ) ) );
	}

	/**
	 * Opérateur prédit pour un numéro.
	 */
	public function handle_predict(): void {
		check_ajax_referer( self::CHECKOUT_NONCE, 'nonce' );
		$phone = isset( $_POST['phone'] ) ? Phone::normalize( sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) : '';
		wp_send_json_success( $this->predict( $phone, self::client_ip() ) );
	}

	/**
	 * État de la commande ; null si elle n'existe pas, n'est pas payée par LabPay ou si la clé est fausse.
	 *
	 * @param int    $order_id Commande.
	 * @param string $key      Clé de la commande (wc_order_…).
	 * @return array<string, string>|null
	 */
	public function status( int $order_id, string $key ): ?array {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || Gateway::ID !== $order->get_payment_method() || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}

		$state   = $this->payments->sync( $order );
		$payload = array(
			'state'   => $state,
			'message' => self::message( $state ),
		);
		if ( 'completed' === $state || 'on-hold' === $state ) {
			$payload['redirect'] = $order->get_checkout_order_received_url();
		}
		if ( 'failed' === $state && $order->needs_payment() ) {
			$payload['retry_url'] = $order->get_checkout_payment_url();
		}

		return $payload;
	}

	/**
	 * Opérateurs pour le dépôt dans une devise (cache de 6 h par mode).
	 *
	 * @param string $currency Devise.
	 * @return list<array{code: string, name: string}>
	 */
	public function providers( string $currency ): array {
		$mode      = $this->settings->mode();
		$transient = 'labpay_wc_providers_' . $mode;
		$cached    = get_transient( $transient );
		if ( ! is_array( $cached ) ) {
			$cached = array();
			try {
				foreach ( $this->clients->make( $mode, 10.0, 0 )->providers->list( Operation::Deposit ) as $provider ) {
					$cached[] = array(
						'code'       => $provider->provider,
						'name'       => (string) ( $provider->nameDisplayedToCustomer ?? $provider->name ?? $provider->provider ), // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété du SDK.
						'currencies' => array_keys( $provider->currencies ),
					);
				}
				set_transient( $transient, $cached, 6 * HOUR_IN_SECONDS );
			} catch ( \Throwable $e ) {
				$this->logger->log( 'warning', 'Provider list unavailable.', array( 'error' => Order_Payments::error_code( $e ) ) );
				// Court cache négatif : pas un appel à l'API par visiteur pendant une panne.
				set_transient( $transient, array(), 5 * MINUTE_IN_SECONDS );
			}
		}

		$list = array();
		foreach ( $cached as $provider ) {
			if ( is_array( $provider ) && ( array() === ( $provider['currencies'] ?? array() ) || in_array( $currency, (array) $provider['currencies'], true ) ) ) {
				$list[] = array(
					'code' => (string) $provider['code'],
					'name' => (string) $provider['name'],
				);
			}
		}

		return $list;
	}

	/**
	 * Valide un numéro et prédit son opérateur (sans créer de paiement).
	 *
	 * @param string $phone Numéro normalisé ('' si invalide).
	 * @param string $ip    Adresse du visiteur (limite de requêtes).
	 * @return array<string, mixed>
	 */
	public function predict( string $phone, string $ip ): array {
		if ( '' === $phone ) {
			return array( 'valid' => false );
		}
		$bucket = 'labpay_wc_rate_' . md5( $ip );
		$count  = (int) get_transient( $bucket );
		if ( $count >= self::PREDICT_LIMIT ) {
			return array(
				'valid'   => null,
				'limited' => true,
			);
		}
		set_transient( $bucket, $count + 1, 10 * MINUTE_IN_SECONDS );

		try {
			$prediction = $this->clients->make( $this->settings->mode(), 10.0, 0 )->providers->predict( $phone );
		} catch ( \LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Exception\InvalidRequestException $e ) {
			return array( 'valid' => false );
		} catch ( \Throwable $e ) {
			// Prédiction indisponible : le paiement reste possible (LabPay déduit l'opérateur).
			return array( 'valid' => null );
		}

		$name = $prediction->provider;
		foreach ( $this->providers( get_woocommerce_currency() ) as $provider ) {
			if ( $provider['code'] === $prediction->provider ) {
				$name = $provider['name'];
			}
		}

		return array(
			'valid'    => true,
			'provider' => $prediction->provider,
			'name'     => $name,
			'country'  => $prediction->country,
		);
	}

	/**
	 * Message du statut.
	 *
	 * @param string $state État.
	 */
	public static function message( string $state ): string {
		switch ( $state ) {
			case 'completed':
				return __( 'Payment received. Thank you!', 'labpay-for-woocommerce' );
			case 'on-hold':
				return __( 'Payment received: the shop is checking it.', 'labpay-for-woocommerce' );
			case 'failed':
				return __( 'The payment did not go through (refused, cancelled or expired). You have not been charged.', 'labpay-for-woocommerce' );
			default:
				return __( 'Confirm the payment on your phone: enter your Mobile Money PIN when asked.', 'labpay-for-woocommerce' );
		}
	}

	/**
	 * Adresse IP du visiteur (REMOTE_ADDR : les en-têtes de proxy se falsifient).
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' !== $ip ? $ip : 'unknown';
	}
}
