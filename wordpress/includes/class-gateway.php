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
		$this->method_description = __( 'Mobile Money payments (M-Pesa, Airtel Money, Orange Money…) across Africa with LabPay. The customer confirms the payment on their phone with their PIN.', 'labpay-for-woocommerce' );
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
		if ( ! in_array( get_woocommerce_currency(), self::accepted_currencies(), true ) ) {
			/* translators: %s: shop currency code. */
			$notices[] = sprintf( __( 'The shop currency %s is not accepted (CDF and USD by default; add the currency of a country open on LabPay with the labpay_wc_currencies filter): the payment method is hidden at checkout.', 'labpay-for-woocommerce' ), get_woocommerce_currency() );
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
	 * Disponible si la passerelle est activée, la clé du mode configurée et la devise acceptée (filtre `labpay_wc_currencies`).
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		$settings   = new Settings();
		$currencies = self::accepted_currencies();
		$currency   = get_woocommerce_currency();
		$order_id   = absint( get_query_var( 'order-pay' ) );
		if ( $order_id > 0 ) {
			$order    = wc_get_order( $order_id );
			$currency = $order instanceof \WC_Order ? $order->get_currency() : $currency;
		}

		return in_array( $currency, $currencies, true ) && '' !== $settings->key_for( $settings->mode() );
	}

	/**
	 * Devises acceptées : celles du filtre, sinon CDF et USD (même liste pour l'alerte d'administration et la validation de commande).
	 *
	 * @return list<string>
	 */
	private static function accepted_currencies(): array {
		/**
		 * Devises proposées au paiement Mobile Money (CDF et USD par défaut ; ajouter celles des autres pays ouverts sur LabPay, ex. XOF).
		 *
		 * @param list<string> $currencies Codes ISO 4217.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'labpay_wc_currencies', Settings::CURRENCIES ) ) );
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

		return true;
	}

	/**
	 * Demande le paiement (dépôt LabPay) et redirige vers la page d'attente.
	 *
	 * @param int $order_id Commande.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return array( 'result' => 'failure' );
		}

		$phone = self::posted_phone();
		$phone = '' !== $phone ? $phone : Phone::normalize( (string) $order->get_billing_phone() );
		if ( '' === $phone ) {
			wc_add_notice( __( 'Enter a valid Mobile Money number (for example 0812345678 or +243812345678).', 'labpay-for-woocommerce' ), 'error' );

			return array( 'result' => 'failure' );
		}

		$plugin   = Plugin::instance();
		$payments = $plugin->payments;

		try {
			$payment = $payments->create_deposit( $order, $phone, self::posted_provider() );
		} catch ( Configuration_Exception $e ) {
			$plugin->logger->log( 'error', 'Payment method not configured: ' . $e->getMessage(), array( 'order' => $order->get_id() ) );
			wc_add_notice( Order_Payments::customer_error( $e ), 'error' );

			return array( 'result' => 'failure' );
		} catch ( \Throwable $e ) {
			$code = Order_Payments::error_code( $e );
			$plugin->logger->log(
				'warning',
				'Deposit request refused.',
				array(
					'order' => $order->get_id(),
					'error' => $code,
				)
			);
			/* translators: %s: error code. */
			$order->add_order_note( sprintf( __( 'LabPay payment request not accepted: %s.', 'labpay-for-woocommerce' ), $code ) );
			wc_add_notice( Order_Payments::customer_error( $e ), 'error' );

			return array( 'result' => 'failure' );
		}

		try {
			$state = $payments->apply( $order, $payment, 'checkout' );
		} catch ( Lock_Exception $e ) {
			$state = 'pending';
		}

		if ( 'failed' === $state || $payment->isFailed() ) {
			$message = __( 'Your operator refused the payment. Check your balance and your number, then try again.', 'labpay-for-woocommerce' );
			if ( null !== $payment->failure && null !== $payment->failure->message ) {
				$message .= ' (' . wp_strip_all_tags( $payment->failure->message ) . ')';
			}
			wc_add_notice( $message, 'error' );

			return array( 'result' => 'failure' );
		}

		if ( 'pending' === $state ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: LabPay payment ID, 2: masked phone number. */
					__( 'LabPay payment %1$s requested: waiting for the customer to confirm on %2$s.', 'labpay-for-woocommerce' ),
					$payment->id,
					Logger::mask_phone( $phone )
				)
			);
		}

		if ( function_exists( 'WC' ) && null !== WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Remboursement WooCommerce → remboursement LabPay (POST /refunds) du dépôt payé.
	 *
	 * @param int        $order_id Commande.
	 * @param float|null $amount   Montant (null : tout).
	 * @param string     $reason   Motif.
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->can_refund_order( $order ) ) {
			return new \WP_Error( 'labpay_refund', __( 'Only a paid order can be refunded with LabPay.', 'labpay-for-woocommerce' ) );
		}
		$value = null === $amount || '' === $amount ? null : wc_format_decimal( (string) $amount, 2 );
		if ( null !== $value && (float) $value <= 0 ) {
			return new \WP_Error( 'labpay_refund', __( 'The refund amount must be greater than zero.', 'labpay-for-woocommerce' ) );
		}

		$plugin = Plugin::instance();
		try {
			$refund = $plugin->payments->refund( $order, $value, (string) $reason );
		} catch ( \Throwable $e ) {
			$plugin->logger->log(
				'warning',
				'Refund refused.',
				array(
					'order' => $order->get_id(),
					'error' => Order_Payments::error_code( $e ),
				)
			);
			$message = $e instanceof ApiException ? $e->getErrorCode() . ' — ' . $e->getMessage() : Order_Payments::customer_error( $e );

			/* translators: %s: error from LabPay. */
			return new \WP_Error( 'labpay_refund', sprintf( __( 'LabPay refused the refund: %s', 'labpay-for-woocommerce' ), wp_strip_all_tags( $message ) ) );
		}

		if ( $refund->isFailed() ) {
			return new \WP_Error(
				'labpay_refund',
				/* translators: %s: failure code. */
				sprintf( __( 'The LabPay refund failed (%s): no money was sent.', 'labpay-for-woocommerce' ), null !== $refund->failure ? $refund->failure->code : 'FAILED' )
			);
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: LabPay refund ID, 2: amount, 3: currency, 4: status. */
				__( 'LabPay refund %1$s requested: %2$s %3$s (%4$s). Fees are not refunded.', 'labpay-for-woocommerce' ),
				$refund->id,
				$refund->amount,
				$refund->currency,
				$refund->isCompleted() ? __( 'completed', 'labpay-for-woocommerce' ) : __( 'in progress, the final status arrives by notification', 'labpay-for-woocommerce' )
			)
		);

		return true;
	}

	/**
	 * Remboursement possible : commande payée par LabPay (dépôt connu).
	 *
	 * @param \WC_Order $order Commande.
	 */
	public function can_refund_order( $order ): bool {
		return $order instanceof \WC_Order && '' !== (string) $order->get_meta( Order_Payments::META_PAYMENT_ID ) && $order->get_transaction_id() !== '';
	}

	/**
	 * Token live : chiffré ; une clé de test est refusée ici.
	 *
	 * @param string $key   Champ.
	 * @param mixed  $value Valeur postée.
	 */
	public function validate_api_token_field( $key, $value ): string {
		return $this->validate_secret( $key, $value, static fn( string $v ): bool => 0 !== strpos( $v, 'test_' ), __( 'The live API token cannot be a test key (test_…): paste it in "Test key".', 'labpay-for-woocommerce' ) );
	}

	/**
	 * Clé de test : chiffrée, doit commencer par test_.
	 *
	 * @param string $key   Champ.
	 * @param mixed  $value Valeur postée.
	 */
	public function validate_test_key_field( $key, $value ): string {
		return $this->validate_secret( $key, $value, static fn( string $v ): bool => 0 === strpos( $v, 'test_' ), __( 'The test key starts with test_.', 'labpay-for-woocommerce' ) );
	}

	/**
	 * Secret de signature : chiffré.
	 *
	 * @param string $key   Champ.
	 * @param mixed  $value Valeur postée.
	 */
	public function validate_webhook_secret_field( $key, $value ): string {
		return $this->validate_secret( $key, $value, static fn( string $v ): bool => 1 === preg_match( '/^\S{8,}$/', $v ), __( 'The signing secret looks invalid (whsec_…).', 'labpay-for-woocommerce' ) );
	}

	/**
	 * Préfixe des références : caractères autorisés par l'API seulement.
	 *
	 * @param string $key   Champ.
	 * @param mixed  $value Valeur postée.
	 */
	public function validate_reference_prefix_field( $key, $value ): string {
		$prefix = (string) preg_replace( '/[^A-Za-z0-9._:\-]/', '', is_string( $value ) ? $value : '' );

		return '' === $prefix ? 'WC' : substr( $prefix, 0, 20 );
	}

	/**
	 * Champ secret : vide = supprimé, valeur chiffrée inchangée = conservée, sinon chiffrée.
	 *
	 * @param string                 $key     Champ.
	 * @param mixed                  $value   Valeur postée.
	 * @param callable(string): bool $valid   Contrôle du format.
	 * @param string                 $message Erreur affichée si le format est invalide.
	 */
	private function validate_secret( string $key, $value, callable $valid, string $message ): string {
		$value  = trim( stripslashes( is_string( $value ) ? $value : '' ) );
		$stored = (string) $this->get_option( $key );
		if ( '' === $value ) {
			return '';
		}
		if ( Crypto::is_encrypted( $value ) ) {
			return hash_equals( $stored, $value ) ? $stored : '';
		}
		if ( ! $valid( $value ) ) {
			\WC_Admin_Settings::add_error( $message );

			return $stored;
		}

		return Crypto::encrypt( $value );
	}

	/**
	 * Numéro posté (formulaire classique, ou payment_data du paiement en blocs).
	 */
	private static function posted_phone(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par WooCommerce (validation de commande / Store API).
		$raw = isset( $_POST['labpay_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['labpay_phone'] ) ) : '';

		return Phone::normalize( $raw );
	}

	/**
	 * Opérateur posté (vide : détection automatique).
	 */
	private static function posted_provider(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par WooCommerce (validation de commande / Store API).
		$raw = isset( $_POST['labpay_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['labpay_provider'] ) ) : '';

		return Phone::provider( $raw );
	}
}
