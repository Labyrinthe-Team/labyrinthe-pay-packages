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
