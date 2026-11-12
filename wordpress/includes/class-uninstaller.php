<?php
/**
 * Suppression des données du plugin (désinstallation).
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Supprime les réglages (secrets chiffrés compris), les caches et les verrous. Les
 * métadonnées des commandes restent : elles relient chaque commande à son paiement LabPay.
 */
final class Uninstaller {

	/**
	 * Désinstallation.
	 */
	public static function run(): void {
		global $wpdb;

		delete_option( 'woocommerce_labpay_settings' );
		foreach ( array( 'live', 'test' ) as $mode ) {
			delete_transient( 'labpay_wc_providers_' . $mode );
		}

		// Verrous et compteurs de requêtes restants (préfixes propres au plugin).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- nettoyage ponctuel.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'labpay_wc_lock_' ) . '%',
				$wpdb->esc_like( '_transient_labpay_wc_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_labpay_wc_' ) . '%'
			)
		);
	}
}
