<?php
/**
 * Point d'entrée du plugin.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

use LabPay\WooCommerce\Blocks\Payment_Method;

defined( 'ABSPATH' ) || exit;

/**
 * Assemble les services et branche les hooks.
 *
 * Aucune requête à LabPay au chargement d'une page publique : l'API n'est appelée qu'au
 * paiement, par la page d'attente (statut), par les actions AJAX de la validation de
 * commande (opérateurs, prédiction) et lors d'un remboursement.
 */
final class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	public Settings $settings;

	/**
	 * Fabrique du client.
	 *
	 * @var Client_Factory
	 */
	public Client_Factory $clients;

	/**
	 * Journal.
	 *
	 * @var Logger
	 */
	public Logger $logger;

	/**
	 * Paiements des commandes.
	 *
	 * @var Order_Payments
	 */
	public Order_Payments $payments;

	/**
	 * Actions AJAX.
	 *
	 * @var Ajax
	 */
	public Ajax $ajax;

	/**
	 * Constructeur.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->clients  = new Client_Factory( $this->settings );
		$this->logger   = new Logger( $this->settings );
		$this->payments = new Order_Payments( $this->settings, $this->clients, $this->logger );
		$this->ajax     = new Ajax( $this->settings, $this->clients, $this->payments, $this->logger );
	}

	/**
	 * Instance unique.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Démarrage (plugins_loaded).
	 */
	public static function boot(): void {
		self::instance()->register_hooks();
	}

	/**
	 * Compatibilité WooCommerce : HPOS (tables de commandes) et validation de commande en blocs.
	 */
	public static function declare_woocommerce_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LABPAY_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', LABPAY_WC_FILE, true );
		}
	}

	/**
	 * Branche les hooks.
	 */
	private function register_hooks(): void {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_notice' ) );

			return;
		}

		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_gateway' ) );
		add_action(
			'rest_api_init',
			function (): void {
				( new Webhook_Controller( $this->settings, $this->payments, $this->logger ) )->register_routes();
			}
		);
		$this->ajax->register();
		( new Waiting_Page( $this->payments ) )->register();
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_blocks' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LABPAY_WC_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Ajoute la passerelle à WooCommerce.
	 *
	 * @param array<int|string, mixed> $gateways Passerelles.
	 * @return array<int|string, mixed>
	 */
	public function add_gateway( $gateways ): array {
		$gateways   = is_array( $gateways ) ? $gateways : array();
		$gateways[] = Gateway::class;

		return $gateways;
	}

	/**
	 * Méthode de paiement des blocs.
	 *
	 * @param object $registry Registre (PaymentMethodRegistry).
	 */
	public function register_blocks( $registry ): void {
		if ( class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) && is_object( $registry ) && method_exists( $registry, 'register' ) ) {
			$registry->register( new Payment_Method() );
		}
	}

	/**
	 * Script de la validation de commande classique (opérateurs, prédiction) et de « Payer la commande ».
	 */
	public function enqueue_checkout(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || 'yes' !== $this->settings->get( 'enabled' ) ) {
			return;
		}
		wp_enqueue_style( 'labpay-wc', LABPAY_WC_URL . 'assets/css/labpay.css', array(), LABPAY_WC_VERSION );
		wp_enqueue_script( 'labpay-wc-checkout', LABPAY_WC_URL . 'assets/js/checkout.js', array( 'jquery' ), LABPAY_WC_VERSION, true );
		wp_localize_script(
			'labpay-wc-checkout',
			'labpayWcCheckout',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( Ajax::CHECKOUT_NONCE ),
				'i18n'    => array(
					/* translators: %s: operator name. */
					'detected' => __( 'Detected operator: %s', 'labpay-for-woocommerce' ),
					'invalid'  => __( 'This number does not look like a valid Mobile Money number.', 'labpay-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Lien « Réglages » dans la liste des extensions.
	 *
	 * @param array<int|string, string> $links Liens.
	 * @return array<int|string, string>
	 */
	public function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		$url   = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . Gateway::ID );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'labpay-for-woocommerce' ) . '</a>' );

		return $links;
	}

	/**
	 * Avis : WooCommerce est nécessaire.
	 */
	public function woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		// Avis limité au tableau de bord et à la page des extensions (règle 11 de wordpress.org).
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'LabPay for WooCommerce needs WooCommerce: install and activate it.', 'labpay-for-woocommerce' ) );
	}
}
