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

