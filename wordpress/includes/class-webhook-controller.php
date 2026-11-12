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
		$secret = $this->settings->secret( 'webhook_secret' );
		if ( '' === $secret ) {
			$this->logger->log( 'error', 'Notification received but no signing secret is configured (WooCommerce > Settings > Payments > LabPay).' );

			return self::response( 503, 'not_configured' );
		}

		$raw = $request->get_body();
		if ( strlen( $raw ) > self::MAX_BODY ) {
			return self::response( 413, 'too_large' );
		}

		try {
			$event = Webhook::constructEvent( $raw, (string) $request->get_header( 'x_labyrinthe_signature' ), $secret );
		} catch ( SignatureVerificationException | InvalidWebhookPayloadException | InvalidArgumentException $e ) {
			$this->logger->log( 'warning', 'Notification refused: ' . $e->getMessage() );

			return self::response( 400, 'invalid_signature' );
		}

		$payment  = $event->payment;
		$delivery = (string) $request->get_header( 'x_labyrinthe_delivery' );
		$order    = $this->payments->find_order( $payment );
		if ( null === $order ) {
			$this->logger->log(
				'warning',
				'Notification for an unknown order: ignored.',
				array(
					'event'    => $event->name,
					'delivery' => $delivery,
					'payment'  => $payment->id,
				)
			);

			return self::response( 200, 'ignored' );
		}

		try {
			$result = $this->payments->apply( $order, $payment, 'webhook' );
		} catch ( Lock_Exception $e ) {
			return self::response( 503, 'locked' );
		} catch ( \Throwable $e ) {
			$this->logger->log(
				'error',
				'Notification processing failed.',
				array(
					'order'   => $order->get_id(),
					'payment' => $payment->id,
					'error'   => get_class( $e ) . ': ' . $e->getMessage(),
				)
			);

			return self::response( 500, 'error' );
		}

		$this->logger->log(
			'info',
			'Notification processed.',
			array(
				'event'    => $event->name,
				'delivery' => $delivery,
				'order'    => $order->get_id(),
				'payment'  => $payment->id,
				'result'   => $result,
			)
		);

		return self::response( 200, $result );
	}

	/**
	 * Réponse JSON courte (jamais de détail interne).
	 *
	 * @param int    $status HTTP.
	 * @param string $result Résultat.
	 */
	private static function response( int $status, string $result ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'received' => $status < 300,
				'result'   => $result,
			),
			$status
		);
	}
}
