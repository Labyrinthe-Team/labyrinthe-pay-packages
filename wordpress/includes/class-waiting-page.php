<?php
/**
 * Page d'attente : la page de confirmation de commande suit le paiement jusqu'à son statut final.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Tant que le client n'a pas confirmé sur son téléphone, la page affiche une attente et
 * interroge le statut (AJAX, toutes les 5 s, 10 minutes au plus) ; le serveur relit le
 * paiement chez LabPay au besoin (synchronisation). Dès le statut final, la page se recharge.
 */
final class Waiting_Page {

	/**
	 * Paiements des commandes.
	 *
	 * @var Order_Payments
	 */
	private Order_Payments $payments;

	/**
	 * Constructeur.
	 *
	 * @param Order_Payments $payments Paiements.
	 */
	public function __construct( Order_Payments $payments ) {
		$this->payments = $payments;
	}

	/**
	 * Branche le rendu sur la page « Commande reçue ».
	 */
	public function register(): void {
		add_action( 'woocommerce_thankyou_' . Gateway::ID, array( $this, 'render' ), 5 );
	}

	/**
	 * Affiche l'état du paiement.
	 *
	 * @param int $order_id Commande.
	 */
	public function render( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || Gateway::ID !== $order->get_payment_method() ) {
			return;
		}
		$state = $this->payments->state( $order );
		if ( 'completed' === $state ) {
			return;
		}
