<?php
/**
 * Passerelle de paiement WooCommerce « Mobile Money (LabPay) ».
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Exception\ApiException;

defined( 'ABSPATH' ) || exit;

/**
 * Le client saisit son numéro (et, s'il le souhaite, son opérateur) ; LabPay lui envoie
 * une demande de confirmation par code PIN. La commande reste « en attente de paiement »
 * jusqu'au statut final, reçu par webhook ou relu par la page d'attente.
 */
class Gateway extends \WC_Payment_Gateway {

	/** Identifiant de la passerelle (réglages : woocommerce_labpay_settings). */
	public const ID = 'labpay';

	/**
	 * Constructeur.
	 */
	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = true;
		$this->method_title       = __( 'LabPay (Mobile Money)', 'labpay-for-woocommerce' );
		$this->method_description = __( 'M-Pesa, Airtel Money and Orange Money payments in the DR Congo with LabPay. The customer confirms the payment on their phone with their PIN.', 'labpay-for-woocommerce' );
		$this->supports           = array( 'products', 'refunds' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
		if ( '' === trim( (string) $this->title ) ) {
			$this->title = __( 'Mobile Money (M-Pesa, Airtel Money, Orange Money)', 'labpay-for-woocommerce' );
		}

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			function (): void {
				$this->process_admin_options();
			}
		);
	}

	/**
	 * Champs de réglage.
	 */
	public function init_form_fields(): void {
		$webhook = rest_url( 'labpay/v1/webhook' );

		$this->form_fields = array(
			'enabled'          => array(
				'title'   => __( 'Enable', 'labpay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Accept Mobile Money payments with LabPay', 'labpay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'            => array(
				'title'       => __( 'Title', 'labpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Name of the payment method shown at checkout.', 'labpay-for-woocommerce' ),
				'default'     => __( 'Mobile Money (M-Pesa, Airtel Money, Orange Money)', 'labpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'      => array(
				'title'       => __( 'Description', 'labpay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Text shown under the payment method at checkout.', 'labpay-for-woocommerce' ),
				'default'     => __( 'Pay with your Mobile Money account: you will receive a confirmation request (PIN) on your phone.', 'labpay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'keys'             => array(
				'title'       => __( 'API keys', 'labpay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Find your token, your test key and your signing secret in your LabPay merchant area (Developers). They are stored encrypted and never shown again.', 'labpay-for-woocommerce' ),
			),
			'testmode'         => array(
				'title'       => __( 'Test mode', 'labpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use the test key (sandbox: no real money moves)', 'labpay-for-woocommerce' ),
				'default'     => 'yes',
				'description' => __( 'Orders paid in test mode are marked "test mode" in their notes. Uncheck when your account is activated for production.', 'labpay-for-woocommerce' ),
			),
			'api_token'        => array(
				'title'             => __( 'API token (live)', 'labpay-for-woocommerce' ),
				'type'              => 'password',
				'description'       => __( 'Production token. Leave unchanged to keep the saved value; empty the field to remove it.', 'labpay-for-woocommerce' ),
				'default'           => '',
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			),
			'test_key'         => array(
				'title'             => __( 'Test key', 'labpay-for-woocommerce' ),
				'type'              => 'password',
				'description'       => __( 'Starts with test_. Used when test mode is enabled.', 'labpay-for-woocommerce' ),
				'default'           => '',
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			),
			'webhook_secret'   => array(
				'title'             => __( 'Signing secret', 'labpay-for-woocommerce' ),
				'type'              => 'password',
				'description'       => sprintf(
					/* translators: %s: webhook URL. */
					__( 'whsec_… secret used to check the notifications sent by LabPay to %s (sent automatically with each payment when your site uses HTTPS).', 'labpay-for-woocommerce' ),
					'<code>' . esc_html( $webhook ) . '</code>'
				),
				'default'           => '',
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			),
			'advanced'         => array(
				'title' => __( 'Advanced', 'labpay-for-woocommerce' ),
				'type'  => 'title',
			),
			'reference_prefix' => array(
				'title'       => __( 'Reference prefix', 'labpay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'LabPay references are "<prefix>-<order ID>-<attempt>". Change it if several shops share the same LabPay token (letters, digits, . _ : -).', 'labpay-for-woocommerce' ),
				'default'     => 'WC',
				'desc_tip'    => true,
			),
			'debug'            => array(
				'title'       => __( 'Debug log', 'labpay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log every LabPay request (WooCommerce > Status > Logs, source "labpay")', 'labpay-for-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Errors are always logged. Keys, secrets and signatures are never logged; phone numbers are masked.', 'labpay-for-woocommerce' ),
			),
		);
	}

	/**
	 * Page de réglages : avis de configuration avant le formulaire.
	 */
	public function admin_options(): void {
		$settings = new Settings();
		$notices  = array();
		foreach ( Settings::SECRET_FIELDS as $field ) {
			if ( $settings->secret_unreadable( $field ) ) {
				/* translators: %s: setting name. */
				$notices[] = sprintf( __( '%s can no longer be read (the WordPress salts of wp-config.php changed): enter it again.', 'labpay-for-woocommerce' ), (string) ( $this->form_fields[ $field ]['title'] ?? $field ) );
			}
		}
		if ( '' === $settings->key_for( $settings->mode() ) ) {
			$notices[] = 'test' === $settings->mode()
				? __( 'Test mode is enabled but no test key is saved: the payment method is hidden at checkout.', 'labpay-for-woocommerce' )
				: __( 'No live API token is saved: the payment method is hidden at checkout.', 'labpay-for-woocommerce' );
		}
		if ( ! in_array( get_woocommerce_currency(), Settings::CURRENCIES, true ) ) {
			/* translators: %s: shop currency code. */
			$notices[] = sprintf( __( 'LabPay accepts CDF and USD only; the shop currency is %s: the payment method is hidden at checkout.', 'labpay-for-woocommerce' ), get_woocommerce_currency() );
		}
		if ( '' === $settings->secret( 'webhook_secret' ) ) {
			$notices[] = __( 'No signing secret: LabPay notifications are refused, statuses are only updated by the waiting page.', 'labpay-for-woocommerce' );
		}
		if ( 0 !== strpos( rest_url(), 'https://' ) ) {
			$notices[] = __( 'Your site does not use HTTPS: LabPay cannot send it notifications, statuses are only updated by the waiting page.', 'labpay-for-woocommerce' );
		}
		foreach ( $notices as $notice ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $notice ) );
		}

		parent::admin_options();
	}

	/**
	 * Disponible si la passerelle est activée, la clé du mode configurée et la devise CDF ou USD.
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		$settings = new Settings();

		/**
		 * Devises proposées au paiement Mobile Money (CDF et USD par défaut).
		 *
		 * @param list<string> $currencies Codes ISO 4217.
		 */
		$currencies = (array) apply_filters( 'labpay_wc_currencies', Settings::CURRENCIES );
		$currency   = get_woocommerce_currency();
		$order_id   = absint( get_query_var( 'order-pay' ) );
		if ( $order_id > 0 ) {
			$order    = wc_get_order( $order_id );
			$currency = $order instanceof \WC_Order ? $order->get_currency() : $currency;
		}

		return in_array( $currency, $currencies, true ) && '' !== $settings->key_for( $settings->mode() );
	}

	/**
	 * Champs du paiement (validation de commande classique et page « Payer la commande »).
	 */
	public function payment_fields(): void {
		$description = $this->get_description();
		if ( '' !== $description ) {
			echo wp_kses_post( wpautop( wptexturize( $description ) ) );
		}
		if ( 'test' === ( new Settings() )->mode() ) {
			echo '<p class="labpay-wc-test">' . esc_html__( 'Test mode: no real money moves.', 'labpay-for-woocommerce' ) . '</p>';
		}

		$phone = '';
		if ( function_exists( 'WC' ) && WC()->customer instanceof \WC_Customer ) {
			$phone = (string) WC()->customer->get_billing_phone();
		}
		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			$phone = $order instanceof \WC_Order ? (string) $order->get_billing_phone() : $phone;
		}
		?>
		<fieldset id="labpay-wc-fields" class="labpay-wc-fields">
			<p class="form-row form-row-wide">
				<label for="labpay_phone"><?php esc_html_e( 'Mobile Money number', 'labpay-for-woocommerce' ); ?>&nbsp;<abbr class="required" title="<?php esc_attr_e( 'required', 'labpay-for-woocommerce' ); ?>">*</abbr></label>
				<input id="labpay_phone" name="labpay_phone" type="tel" class="input-text" inputmode="tel" autocomplete="tel" maxlength="20" placeholder="0812345678" value="<?php echo esc_attr( $phone ); ?>" />
				<span class="description"><?php esc_html_e( 'You will receive a confirmation request (PIN) on this phone.', 'labpay-for-woocommerce' ); ?></span>
			</p>
			<p class="form-row form-row-wide">
				<label for="labpay_provider"><?php esc_html_e( 'Operator', 'labpay-for-woocommerce' ); ?></label>
				<select id="labpay_provider" name="labpay_provider">
					<option value=""><?php esc_html_e( 'Detect automatically', 'labpay-for-woocommerce' ); ?></option>
				</select>
				<span class="labpay-wc-detected" aria-live="polite"></span>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Vérifie le numéro saisi.
	 */
	public function validate_fields(): bool {
		if ( '' === self::posted_phone() ) {
			wc_add_notice( __( 'Enter a valid Mobile Money number (for example 0812345678 or +243812345678).', 'labpay-for-woocommerce' ), 'error' );

			return false;
		}
