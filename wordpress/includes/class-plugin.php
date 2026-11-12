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
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html__( 'LabPay for WooCommerce needs WooCommerce: install and activate it.', 'labpay-for-woocommerce' ) );
	}

	/**
	 * Traductions livrées avec le plugin (languages/).
	 */
	public function load_textdomain(): void {
		// Traductions livrées : elles servent avant les paquets de translate.wordpress.org, hors wordpress.org et pour les variantes du français.
		load_plugin_textdomain( 'labpay-for-woocommerce', false, dirname( plugin_basename( LABPAY_WC_FILE ) ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}

	/**
	 * Site en français d'une autre variante (fr_BE, fr_CA…, il n'existe pas de fr_CD) : on
	 * charge la traduction fr_FR livrée plutôt que d'afficher l'anglais.
	 *
	 * @param string $locale Langue.
	 * @param string $domain Domaine de traduction.
	 */
	public function french_fallback( $locale, $domain ): string {
		$locale = (string) $locale;
		if ( 'labpay-for-woocommerce' !== $domain || 'fr_FR' === $locale || 0 !== strpos( $locale, 'fr' ) ) {
			return $locale;
		}
		$own  = LABPAY_WC_DIR . 'languages/labpay-for-woocommerce-' . $locale . '.mo';
		$pack = WP_LANG_DIR . '/plugins/labpay-for-woocommerce-' . $locale . '.mo';

		return is_file( $own ) || is_file( $pack ) ? $locale : 'fr_FR';
	}

	/**
	 * Même repli pour les traductions JavaScript (paiement en blocs).
	 *
	 * @param string|false $file   Fichier de traduction.
	 * @param string       $handle Script.
	 * @param string       $domain Domaine.
	 * @return string|false
	 */
	public function french_script_fallback( $file, $handle, $domain ) {
		if ( 'labpay-for-woocommerce' !== $domain || ! is_string( $file ) || is_file( $file ) ) {
			return $file;
		}
		$fallback = (string) preg_replace( '/-fr_[A-Z]{2}(_[a-z]+)?-/', '-fr_FR-', $file );

		return is_file( $fallback ) ? $fallback : $file;
	}
}
