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
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_filter( 'plugin_locale', array( $this, 'french_fallback' ), 10, 2 );
		add_filter( 'load_script_translation_file', array( $this, 'french_script_fallback' ), 10, 3 );

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
