<?php
/**
 * Route REST des notifications LabPay : POST /wp-json/labpay/v1/webhook.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Exception\InvalidArgumentException;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Exception\InvalidWebhookPayloadException;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Exception\SignatureVerificationException;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Webhook\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Chaque notification est vérifiée sur le corps brut (X-Labyrinthe-Signature : HMAC-SHA256
 * de « t.corps », comparaison à temps constant, 300 s de tolérance) par le SDK, puis
 * appliquée à la commande. Une même notification peut arriver plusieurs fois : l'état de
 * la commande rend le traitement idempotent.
 *
 * Réponses : 200 traité ou ignoré ; 400 signature ou corps invalide ; 413 corps trop gros ;
 * 503 secret non configuré ou commande verrouillée (LabPay renvoie plus tard) ; 500 erreur.
 */
final class Webhook_Controller {

	public const NAMESPACE = 'labpay/v1';
	public const ROUTE     = '/webhook';
	public const MAX_BODY  = 65536;

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private Settings $settings;

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
	 * @param Order_Payments $payments Paiements.
	 * @param Logger         $logger   Journal.
	 */
	public function __construct( Settings $settings, Order_Payments $payments, Logger $logger ) {
		$this->settings = $settings;
		$this->payments = $payments;
		$this->logger   = $logger;
	}

	/**
	 * Enregistre la route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Route publique : l'authentification est la signature HMAC, vérifiée dans handle().
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Traite une notification.
	 *
	 * @param \WP_REST_Request $request Requête.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
