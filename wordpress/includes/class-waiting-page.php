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

		if ( 'failed' === $state ) {
			echo '<div class="woocommerce-error labpay-wc-status" role="alert">' . esc_html( Ajax::message( 'failed' ) );
			if ( $order->needs_payment() ) {
				printf( ' <a class="button" href="%s">%s</a>', esc_url( $order->get_checkout_payment_url() ), esc_html__( 'Try again', 'labpay-for-woocommerce' ) );
			}
			echo '</div>';

			return;
		}

		if ( 'on-hold' === $state ) {
			echo '<div class="woocommerce-info labpay-wc-status">' . esc_html( Ajax::message( 'on-hold' ) ) . '</div>';

			return;
		}

		wp_enqueue_style( 'labpay-wc', LABPAY_WC_URL . 'assets/css/labpay.css', array(), LABPAY_WC_VERSION );
		wp_enqueue_script( 'labpay-wc-waiting', LABPAY_WC_URL . 'assets/js/waiting.js', array(), LABPAY_WC_VERSION, true );
		wp_localize_script(
			'labpay-wc-waiting',
			'labpayWcWaiting',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'action'   => Ajax::STATUS_ACTION,
				'nonce'    => wp_create_nonce( Ajax::STATUS_ACTION ),
				'orderId'  => $order->get_id(),
				'key'      => $order->get_order_key(),
				'interval' => 5000,
				'timeout'  => 600000,
				'i18n'     => array(
					'retry'   => __( 'Try again', 'labpay-for-woocommerce' ),
					'timeout' => __( 'Still no confirmation. If you confirmed the payment, it will be taken into account automatically and you will receive an email; otherwise, try again.', 'labpay-for-woocommerce' ),
				),
			)
		);

		$payment = (string) $order->get_meta( Order_Payments::META_PAYMENT_ID );
		?>
		<div id="labpay-wc-waiting" class="woocommerce-info labpay-wc-status labpay-wc-waiting" role="status" aria-live="polite">
			<span class="labpay-wc-spinner" aria-hidden="true"></span>
			<strong class="labpay-wc-title"><?php esc_html_e( 'Waiting for your confirmation', 'labpay-for-woocommerce' ); ?></strong>
			<span class="labpay-wc-message"><?php echo esc_html( Ajax::message( 'pending' ) ); ?></span>
			<?php if ( '' !== $payment ) : ?>
				<small class="labpay-wc-ref">
					<?php
					/* translators: %s: LabPay payment ID. */
					echo esc_html( sprintf( __( 'LabPay reference: %s', 'labpay-for-woocommerce' ), $payment ) );
					?>
				</small>
			<?php endif; ?>
		</div>
		<?php
	}
}
