<?php
/**
 * Paiements LabPay d'une commande WooCommerce : dépôt, suivi du statut, remboursements.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Enum\Operation;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Exception\AmountOutOfRangeException;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Exception\ApiException;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Exception\ConnectionException;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Exception\InvalidArgumentException;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Exception\InvalidPhoneNumberException;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Exception\RateLimitException;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Exception\ServiceUnavailableException;
use LabPay\WooCommerce\Vendor\Labyrinthe\LabPay\Model\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * Règles :
 *
 * - la référence d'un dépôt est « <préfixe>-<id de commande>-<tentative> » : renvoyer la
 *   même requête (double clic, erreur réseau) rend le paiement existant au lieu d'en créer
 *   un second ; une nouvelle tentative n'est ouverte qu'après un échec définitif ;
 * - une commande n'est payée que sur le statut `completed`, montant et devise vérifiés ;
 * - chaque mise à jour (webhook, page d'attente, synchronisation) passe par apply(), sous
 *   un verrou par commande : un même statut reçu deux fois ne change rien (idempotent) ;
 * - un paiement est toujours suivi avec la clé du mode dans lequel il a été créé.
 */
final class Order_Payments {

	public const META_PAYMENT_ID   = '_labpay_payment_id';
	public const META_PAYMENT_IDS  = '_labpay_payment_ids';
	public const META_STATUS       = '_labpay_status';
	public const META_MODE         = '_labpay_mode';
	public const META_ATTEMPT      = '_labpay_attempt';
	public const META_REFERENCE    = '_labpay_reference';
	public const META_LAST_SYNC    = '_labpay_last_sync';
	public const META_MISMATCH     = '_labpay_mismatch';
	public const META_DUPLICATES   = '_labpay_duplicates';
	public const META_REFUNDS      = '_labpay_refunds';
	public const META_REFUND_NEXT  = '_labpay_refund_next';
	public const MIN_SYNC_INTERVAL = 8;

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
	 * @param Logger         $logger   Journal.
	 */
	public function __construct( Settings $settings, Client_Factory $clients, Logger $logger ) {
		$this->settings = $settings;
		$this->clients  = $clients;
		$this->logger   = $logger;
	}

	/**
	 * Demande le paiement au client (dépôt V2) : il reçoit une demande de code PIN sur son téléphone.
	 *
	 * @param \WC_Order   $order    Commande.
	 * @param string      $phone    Numéro Mobile Money (local ou international).
	 * @param string|null $provider Code opérateur choisi (null : déduit du numéro par LabPay).
	 * @throws Configuration_Exception Clé du mode actif absente.
	 * @throws \Throwable Erreur du SDK (ApiException, ConnectionException, InvalidArgumentException).
	 */
	public function create_deposit( \WC_Order $order, string $phone, ?string $provider ): Payment {
		$mode    = $this->settings->mode();
		$client  = $this->clients->make( $mode );
		$attempt = max( 1, (int) $order->get_meta( self::META_ATTEMPT ) );
		$current = (string) $order->get_meta( self::META_PAYMENT_ID );

		if ( '' !== $current ) {
			$status = (string) $order->get_meta( self::META_STATUS );
			if ( 'pending' === $status ) {
				// Le client revient payer alors qu'une demande est en cours : on relit d'abord son statut.
				$this->sync( $order, true );
				$order  = $this->fresh( $order );
				$status = (string) $order->get_meta( self::META_STATUS );
			}
			if ( 'failed' === $status || (string) $order->get_meta( self::META_MODE ) !== $mode ) {
				++$attempt;
			}
		}

		$reference = $this->reference( $order, $attempt );
		$order->update_meta_data( self::META_ATTEMPT, (string) $attempt );
		$order->update_meta_data( self::META_REFERENCE, $reference );
		$order->update_meta_data( self::META_MODE, $mode );
		$order->save();

		$this->logger->log(
			'info',
			'Deposit requested.',
			array(
				'order'     => $order->get_id(),
				'reference' => $reference,
				'mode'      => $mode,
				'phone'     => Logger::mask_phone( $phone ),
				'provider'  => $provider,
			)
		);

		$payment = $client->deposits->create(
			amount: self::amount( $order ),
			currency: $order->get_currency(),
			phone: $phone,
			provider: $provider,
			reference: $reference,
			callbackUrl: $this->callback_url(),
			customerMessage: self::customer_message( $order ),
			metadata: array(
				'wc_order_id' => (string) $order->get_id(),
				'site'        => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			),
		);

		$this->remember( $order, $payment );
		$this->logger->log(
			'info',
			'Deposit created.',
			array(
				'order'          => $order->get_id(),
				'payment'        => $payment->id,
				'status'         => $payment->status->value,
				'gateway_status' => $payment->gatewayStatus, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété du SDK.
				'failure'        => null !== $payment->failure ? $payment->failure->code : null,
			)
		);

		return $payment;
	}

	/**
	 * Applique le statut d'un paiement LabPay à la commande (webhook, synchronisation, création).
	 *
	 * @param \WC_Order $order   Commande.
	 * @param Payment   $payment Paiement (dépôt ou remboursement).
	 * @param string    $source  Origine : webhook, sync, checkout.
	 * @return string completed, failed, pending, on-hold ou ignored.
	 * @throws Lock_Exception La commande est déjà en cours de mise à jour.
	 */
	public function apply( \WC_Order $order, Payment $payment, string $source ): string {
		if ( Operation::Refund === $payment->operation ) {
			return $this->with_lock( $order->get_id(), fn(): string => $this->apply_refund( $this->fresh( $order ), $payment ) );
		}
		if ( Operation::Deposit !== $payment->operation || ! $this->knows( $order, $payment->id ) ) {
			$this->logger->log(
				'warning',
				'Payment not linked to this order: ignored.',
				array(
					'order'   => $order->get_id(),
					'payment' => $payment->id,
					'source'  => $source,
				)
			);

			return 'ignored';
		}

		return $this->with_lock( $order->get_id(), fn(): string => $this->apply_deposit( $this->fresh( $order ), $payment, $source ) );
	}

	/**
	 * Relit le statut chez LabPay (POST /payments/{id}/sync) et l'applique.
	 *
	 * @param \WC_Order $order Commande.
	 * @param bool      $force Ignorer l'intervalle minimal entre deux synchronisations.
	 * @return string État de la commande (voir state()).
	 */
	public function sync( \WC_Order $order, bool $force = false ): string {
		$payment_id = (string) $order->get_meta( self::META_PAYMENT_ID );
		if ( '' === $payment_id || $order->is_paid() || 'pending' !== (string) $order->get_meta( self::META_STATUS ) ) {
			return $this->state( $order );
		}

		$last = (int) $order->get_meta( self::META_LAST_SYNC );
		if ( ! $force && time() - $last < self::MIN_SYNC_INTERVAL ) {
			return $this->state( $order );
		}
		$order->update_meta_data( self::META_LAST_SYNC, (string) time() );
		$order->save();

		try {
			$payment = $this->clients->make( $this->mode_of( $order ), 15.0, 0 )->payments->sync( $payment_id );
			$this->apply( $order, $payment, 'sync' );
		} catch ( Lock_Exception $e ) {
			// Une autre requête (webhook) met la commande à jour en ce moment.
			unset( $e );
		} catch ( \Throwable $e ) {
			$this->logger->log(
				'warning',
				'Status sync failed.',
				array(
					'order'   => $order->get_id(),
					'payment' => $payment_id,
					'error'   => self::error_code( $e ),
				)
			);
		}

		return $this->state( $this->fresh( $order ) );
	}

	/**
	 * État de la commande pour la page d'attente.
	 *
	 * @param \WC_Order $order Commande.
	 * @return string completed, failed, on-hold ou pending.
	 */
	public function state( \WC_Order $order ): string {
		if ( $order->is_paid() ) {
			return 'completed';
		}
		if ( $order->has_status( array( 'failed', 'cancelled', 'refunded' ) ) || 'failed' === (string) $order->get_meta( self::META_STATUS ) ) {
			return 'failed';
		}
		if ( $order->has_status( 'on-hold' ) ) {
			return 'on-hold';
		}

		return 'pending';
	}

	/**
	 * Rembourse tout ou partie du dépôt payé (POST /refunds). Les frais ne sont pas remboursés.
	 *
	 * @param \WC_Order   $order  Commande.
	 * @param string|null $amount Montant (null : tout le reste à rembourser).
	 * @param string      $reason Motif (note de commande seulement).
	 * @throws Configuration_Exception Clé absente.
	 * @throws \Throwable Erreur du SDK.
	 */
	public function refund( \WC_Order $order, ?string $amount, string $reason ): Payment {
		$deposit = $order->get_transaction_id();
		$deposit = '' !== $deposit ? $deposit : (string) $order->get_meta( self::META_PAYMENT_ID );
		$client  = $this->clients->make( $this->mode_of( $order ) );

		// Référence réutilisée si la tentative précédente (même montant) n'a pas eu de réponse.
		$refunds = $this->refunds( $order );
		$next    = $order->get_meta( self::META_REFUND_NEXT );
		if ( is_array( $next ) && ( $next['amount'] ?? null ) === $amount && is_string( $next['reference'] ?? null ) ) {
			$reference = $next['reference'];
		} else {
			$reference = sprintf( '%s-%d-R%d-%d', $this->settings->reference_prefix(), $order->get_id(), count( $refunds ) + 1, max( 1, (int) $order->get_meta( self::META_ATTEMPT ) ) );
			$order->update_meta_data(
				self::META_REFUND_NEXT,
				array(
					'reference' => $reference,
					'amount'    => $amount,
				)
			);
			$order->save();
		}

		$payment = $client->refunds->create(
			paymentId: $deposit,
			amount: $amount,
			reference: $reference,
			callbackUrl: $this->callback_url(),
			metadata: array( 'wc_order_id' => (string) $order->get_id() ),
		);

		$refunds[ $payment->id ] = $payment->status->value;
		$order->update_meta_data( self::META_REFUNDS, $refunds );
		$order->delete_meta_data( self::META_REFUND_NEXT );
		$order->save();

		$this->logger->log(
			'info',
			'Refund created.',
			array(
				'order'   => $order->get_id(),
				'deposit' => $deposit,
				'refund'  => $payment->id,
				'status'  => $payment->status->value,
				'reason'  => '' !== $reason,
			)
		);

		return $payment;
	}

	/**
	 * Commande d'un paiement reçu par webhook : métadonnée wc_order_id, sinon la référence.
	 *
	 * @param Payment $payment Paiement.
	 */
	public function find_order( Payment $payment ): ?\WC_Order {
		$order_id = (int) ( $payment->metadata['wc_order_id'] ?? 0 );
		if ( $order_id <= 0 && null !== $payment->reference ) {
			$pattern = '/^' . preg_quote( $this->settings->reference_prefix(), '/' ) . '-(\d+)-/';
			if ( 1 === preg_match( $pattern, $payment->reference, $matches ) ) {
				$order_id = (int) $matches[1];
			}
		}
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || Gateway::ID !== $order->get_payment_method() ) {
			return null;
		}

		return $order;
	}

	/**
	 * Mode (live / test) dans lequel les paiements de la commande ont été créés.
	 *
	 * @param \WC_Order $order Commande.
	 */
	public function mode_of( \WC_Order $order ): string {
		return 'test' === (string) $order->get_meta( self::META_MODE ) ? 'test' : 'live';
	}

	/**
	 * Montant envoyé à LabPay : total de la commande, 2 décimales au plus.
	 *
	 * @param \WC_Order $order Commande.
	 */
	public static function amount( \WC_Order $order ): string {
		return wc_format_decimal( (string) $order->get_total(), 2 );
	}

	/**
	 * Message affiché au client sur son téléphone (4 à 22 lettres, chiffres, espaces), selon l'opérateur.
	 *
	 * @param \WC_Order $order Commande.
	 */
	public static function customer_message( \WC_Order $order ): ?string {
		/* translators: %s: order number. Letters (without accents), digits and spaces only: 4 to 22 characters. */
		$text = sprintf( __( 'Order %s', 'labpay-for-woocommerce' ), $order->get_order_number() );
		$text = (string) preg_replace( '/[^A-Za-z0-9 ]/', '', remove_accents( $text ) );
		$text = trim( substr( $text, 0, 22 ) );

		return strlen( $text ) >= 4 ? $text : null;
	}

	/**
	 * Message d'erreur montré au client (jamais le message brut de l'API).
	 *
	 * @param \Throwable $error Erreur.
	 */
	public static function customer_error( \Throwable $error ): string {
		if ( $error instanceof InvalidPhoneNumberException || ( $error instanceof InvalidArgumentException && false !== strpos( $error->getMessage(), 'phone' ) ) ) {
			return __( 'This Mobile Money number is not valid. Check it and try again.', 'labpay-for-woocommerce' );
		}
		if ( $error instanceof AmountOutOfRangeException ) {
			return __( 'This amount is outside the limits of your Mobile Money operator. Choose another payment method or contact the shop.', 'labpay-for-woocommerce' );
		}
		if ( $error instanceof ApiException && 'UNSUPPORTED_PROVIDER' === $error->getErrorCode() ) {
			return __( 'This operator cannot be used for this payment. Choose "Detect automatically" or another number.', 'labpay-for-woocommerce' );
		}
		if ( $error instanceof ConnectionException ) {
			return __( 'We could not reach the Mobile Money service. Please try again: you will not be charged twice.', 'labpay-for-woocommerce' );
		}
		if ( $error instanceof ServiceUnavailableException || $error instanceof RateLimitException ) {
			return __( 'Mobile Money payment is temporarily unavailable. Please try again in a few minutes.', 'labpay-for-woocommerce' );
		}

		return __( 'Mobile Money payment is not available for this order. Please choose another payment method or contact the shop.', 'labpay-for-woocommerce' );
	}

	/**
	 * Code d'erreur à journaliser (jamais de secret : code API ou classe d'exception).
	 *
	 * @param \Throwable $error Erreur.
	 */
	public static function error_code( \Throwable $error ): string {
		if ( $error instanceof ApiException ) {
			return $error->getErrorCode() . ' (HTTP ' . $error->getStatusCode() . ')';
		}
		$class = get_class( $error );

		return substr( (string) strrchr( '\\' . $class, '\\' ), 1 );
	}

	/**
	 * Remboursements LabPay de la commande : identifiant → statut.
	 *
	 * @param \WC_Order $order Commande.
	 * @return array<string, string>
	 */
	public function refunds( \WC_Order $order ): array {
		$refunds = $order->get_meta( self::META_REFUNDS );

		return is_array( $refunds ) ? array_map( 'strval', $refunds ) : array();
	}

	/**
	 * Applique le statut d'un dépôt (sous verrou).
	 *
	 * @param \WC_Order $order   Commande relue.
	 * @param Payment   $payment Dépôt.
	 * @param string    $source  Origine.
	 */
	private function apply_deposit( \WC_Order $order, Payment $payment, string $source ): string {
		$current = (string) $order->get_meta( self::META_PAYMENT_ID );
		if ( $payment->id === $current && ! $payment->isPending() ) {
			$order->update_meta_data( self::META_STATUS, $payment->status->value );
			$order->save();
		}

		if ( $payment->isCompleted() ) {
			if ( $order->is_paid() ) {
				if ( $order->get_transaction_id() !== $payment->id ) {
					$this->note_duplicate( $order, $payment );
				}

				return 'completed';
			}
			if ( $order->get_currency() !== $payment->currency || self::amount( $order ) !== $payment->amount ) {
				if ( (string) $order->get_meta( self::META_MISMATCH ) !== $payment->id ) {
					$order->update_meta_data( self::META_MISMATCH, $payment->id );
					$order->update_status(
						'on-hold',
						sprintf(
							/* translators: 1: LabPay payment ID, 2: amount paid, 3: currency, 4: expected amount, 5: order currency. */
							__( 'LabPay payment %1$s completed for %2$s %3$s, but the order expects %4$s %5$s: check it before delivering.', 'labpay-for-woocommerce' ),
							$payment->id,
							$payment->amount,
							$payment->currency,
							self::amount( $order ),
							$order->get_currency()
						)
					);
				}

				return 'on-hold';
			}

			$order->update_meta_data( self::META_PAYMENT_ID, $payment->id );
			$order->update_meta_data( self::META_STATUS, 'completed' );
			$order->save();
			$order->add_order_note( self::completed_note( $payment ) );
			$order->payment_complete( $payment->id );
			$this->logger->log(
				'info',
				'Order paid.',
				array(
					'order'   => $order->get_id(),
					'payment' => $payment->id,
					'source'  => $source,
				)
			);

			return 'completed';
		}

		if ( $payment->isFailed() ) {
			// Seule la tentative en cours fait échouer la commande (un ancien échec ne change rien).
			if ( $payment->id !== $current || $order->is_paid() ) {
				return 'ignored';
			}
			if ( ! $order->has_status( 'failed' ) ) {
				$order->update_status( 'failed', self::failed_note( $payment ) );
				$this->logger->log(
					'info',
					'Payment failed.',
					array(
						'order'   => $order->get_id(),
						'payment' => $payment->id,
						'failure' => null !== $payment->failure ? $payment->failure->code : null,
						'source'  => $source,
					)
				);
			}

			return 'failed';
		}

		return 'pending';
	}

	/**
	 * Applique le statut final d'un remboursement (sous verrou).
	 *
	 * @param \WC_Order $order   Commande relue.
	 * @param Payment   $payment Remboursement.
	 */
	private function apply_refund( \WC_Order $order, Payment $payment ): string {
		$refunds = $this->refunds( $order );
		if ( ! isset( $refunds[ $payment->id ] ) ) {
			return 'ignored';
		}
		if ( $refunds[ $payment->id ] === $payment->status->value || $payment->isPending() ) {
			return $payment->status->value;
		}

		$refunds[ $payment->id ] = $payment->status->value;
		$order->update_meta_data( self::META_REFUNDS, $refunds );
		$order->save();

		if ( $payment->isCompleted() ) {
			/* translators: 1: LabPay refund ID, 2: amount, 3: currency. */
			$order->add_order_note( sprintf( __( 'LabPay refund %1$s completed: %2$s %3$s sent back to the customer.', 'labpay-for-woocommerce' ), $payment->id, $payment->amount, $payment->currency ) );

			return 'completed';
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: LabPay refund ID, 2: failure code. */
				__( 'LabPay refund %1$s failed (%2$s): no money was sent and your LabPay balance was credited back. The WooCommerce refund still exists: delete it or refund the customer another way.', 'labpay-for-woocommerce' ),
				$payment->id,
				null !== $payment->failure ? $payment->failure->code : 'FAILED'
			)
		);

		return 'failed';
	}

	/**
	 * Mémorise un dépôt créé (identifiant courant et historique des tentatives).
	 *
	 * @param \WC_Order $order   Commande.
	 * @param Payment   $payment Dépôt.
	 */
	private function remember( \WC_Order $order, Payment $payment ): void {
		$ids = $order->get_meta( self::META_PAYMENT_IDS );
		$ids = is_array( $ids ) ? array_values( array_map( 'strval', $ids ) ) : array();
		if ( ! in_array( $payment->id, $ids, true ) ) {
			$ids[] = $payment->id;
		}
		$order->update_meta_data( self::META_PAYMENT_IDS, $ids );
		$order->update_meta_data( self::META_PAYMENT_ID, $payment->id );
		$order->update_meta_data( self::META_STATUS, $payment->status->value );
		$order->save();
	}

	/**
	 * Vrai si le dépôt appartient à la commande (une de ses tentatives).
	 *
	 * @param \WC_Order $order      Commande.
	 * @param string    $payment_id Identifiant.
	 */
	private function knows( \WC_Order $order, string $payment_id ): bool {
		$ids = $order->get_meta( self::META_PAYMENT_IDS );

		return is_array( $ids ) && in_array( $payment_id, array_map( 'strval', $ids ), true );
	}

	/**
	 * Note (une seule fois) : un second paiement réussi pour une commande déjà payée.
	 *
	 * @param \WC_Order $order   Commande.
	 * @param Payment   $payment Paiement en trop.
	 */
	private function note_duplicate( \WC_Order $order, Payment $payment ): void {
		$noted = $order->get_meta( self::META_DUPLICATES );
		$noted = is_array( $noted ) ? $noted : array();
		if ( in_array( $payment->id, $noted, true ) ) {
			return;
		}
		$noted[] = $payment->id;
		$order->update_meta_data( self::META_DUPLICATES, $noted );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: 1: LabPay payment ID, 2: amount, 3: currency. */
				__( 'A second LabPay payment %1$s (%2$s %3$s) completed for this already paid order. Refund it from your LabPay dashboard.', 'labpay-for-woocommerce' ),
				$payment->id,
				$payment->amount,
				$payment->currency
			)
		);
		$this->logger->log(
			'warning',
			'Second completed payment for a paid order.',
			array(
				'order'   => $order->get_id(),
				'payment' => $payment->id,
			)
		);
	}

	/**
	 * Note de paiement réussi.
	 *
	 * @param Payment $payment Dépôt.
	 */
	private static function completed_note( Payment $payment ): string {
		return sprintf(
			/* translators: 1: LabPay payment ID, 2: amount, 3: currency, 4: provider code, 5: provider transaction ID, 6: " (test)" or "". */
			__( 'LabPay payment %1$s completed: %2$s %3$s via %4$s (operator reference: %5$s)%6$s.', 'labpay-for-woocommerce' ),
			$payment->id,
			$payment->amount,
			$payment->currency,
			(string) $payment->provider,
			$payment->providerTransactionId ?? '—', // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propriété du SDK.
			$payment->isTest() ? ' ' . __( '(test mode)', 'labpay-for-woocommerce' ) : ''
		);
	}

	/**
	 * Note de paiement échoué.
	 *
	 * @param Payment $payment Dépôt.
	 */
	private static function failed_note( Payment $payment ): string {
		$failure = $payment->failure;

		return sprintf(
			/* translators: 1: LabPay payment ID, 2: failure code, 3: failure message. */
			__( 'LabPay payment %1$s failed: %2$s %3$s', 'labpay-for-woocommerce' ),
			$payment->id,
			null !== $failure ? $failure->code : 'FAILED',
			null !== $failure && null !== $failure->message ? '(' . wp_strip_all_tags( $failure->message ) . ')' : ''
		);
	}

	/**
	 * Adresse de notification : la route REST du plugin, seulement en HTTPS (LabPay refuse les
	 * adresses non publiques). Sans elle, la page d'attente et la synchronisation suffisent.
	 */
	private function callback_url(): ?string {
		$url = rest_url( 'labpay/v1/webhook' );
		$url = 0 === strpos( $url, 'https://' ) ? $url : null;

		/**
		 * Adresse envoyée comme callback_url (null : aucune notification).
		 *
		 * @param string|null $url URL publique HTTPS.
		 */
		$url = apply_filters( 'labpay_wc_callback_url', $url );

		return is_string( $url ) && '' !== $url ? $url : null;
	}

	/**
	 * Référence d'une tentative de paiement.
	 *
	 * @param \WC_Order $order   Commande.
	 * @param int       $attempt Numéro de tentative.
	 */
	private function reference( \WC_Order $order, int $attempt ): string {
		$reference = sprintf( '%s-%d-%d', $this->settings->reference_prefix(), $order->get_id(), $attempt );

		/**
		 * Référence LabPay d'une tentative de paiement (unique par token et par mode, 100 caractères au plus :
		 * lettres, chiffres, . _ : -). Gardez « <préfixe>-<id de commande>- » au début pour que le webhook
		 * retrouve la commande.
		 *
		 * @param string    $reference Référence.
		 * @param \WC_Order $order     Commande.
		 * @param int       $attempt   Tentative.
		 */
		$filtered = apply_filters( 'labpay_wc_reference', $reference, $order, $attempt );

		return is_string( $filtered ) && 1 === preg_match( '/^[A-Za-z0-9._:\-]{1,100}$/', $filtered ) ? $filtered : $reference;
	}

	/**
	 * Commande relue en base (état à jour sous le verrou).
	 *
	 * @param \WC_Order $order Commande.
	 */
	private function fresh( \WC_Order $order ): \WC_Order {
		$fresh = wc_get_order( $order->get_id() );

		return $fresh instanceof \WC_Order ? $fresh : $order;
	}

	/**
	 * Exécute $callback sous un verrou propre à la commande (ligne d'option insérée atomiquement).
	 *
	 * @template T
	 * @param int           $order_id Commande.
	 * @param callable(): T $callback Traitement.
	 * @return T
	 * @throws Lock_Exception Verrou déjà pris (et récent).
	 */
	private function with_lock( int $order_id, callable $callback ) {
		global $wpdb;

		$name = 'labpay_wc_lock_' . $order_id;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- verrou : jamais mis en cache.
		$acquired = 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
		if ( ! $acquired ) {
			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( $since < time() - 60 ) {
				// Verrou abandonné (processus interrompu) : repris.
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", (string) time(), $name ) );
				$acquired = true;
			}
		}
		// phpcs:enable
		if ( ! $acquired ) {
			throw new Lock_Exception( 'Order is locked.' );
		}

		try {
			return $callback();
		} finally {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- verrou.
		}
	}
}
