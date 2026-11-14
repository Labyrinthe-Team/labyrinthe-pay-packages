<?php
/**
 * Plugin Name:          LabPay for WooCommerce
 * Plugin URI:           https://docs.pay.labyrinthe-rdc.com
 * Description:          Mobile Money payments for WooCommerce with LabPay: M-Pesa, Airtel Money, Orange Money and more, across Africa in the currency of each open country.
 * Version:              0.1.0
 * Requires at least:    6.4
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               Labyrinthe Innovations SARL
 * Author URI:           https://pay.labyrinthe-rdc.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          labpay-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 *
 * @package LabPayWooCommerce
 */

// Ce fichier reste lisible par un vieux PHP (pas de typage ni de syntaxe récente) :
// sur un hébergement trop ancien, il affiche un avis au lieu d'une erreur fatale.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LABPAY_WC_VERSION', '0.1.0' );
define( 'LABPAY_WC_FILE', __FILE__ );
define( 'LABPAY_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'LABPAY_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'LABPAY_WC_MIN_PHP', '8.1' );

if ( version_compare( PHP_VERSION, LABPAY_WC_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'labpay_wc_php_notice' );
	return;
}

if ( ! is_file( LABPAY_WC_DIR . 'vendor-prefixed/autoload.php' ) ) {
	add_action( 'admin_notices', 'labpay_wc_build_notice' );
	return;
}

require_once LABPAY_WC_DIR . 'vendor-prefixed/autoload.php';
require_once LABPAY_WC_DIR . 'includes/autoload.php';

// Compatibilité WooCommerce : tables de commandes HPOS et validation de commande en blocs.
add_action( 'before_woocommerce_init', array( 'LabPay\\WooCommerce\\Plugin', 'declare_woocommerce_compatibility' ) );

add_action( 'plugins_loaded', array( 'LabPay\\WooCommerce\\Plugin', 'boot' ) );

/**
 * Avis affiché quand la version de PHP est trop ancienne.
 *
 * @return void
 */
function labpay_wc_php_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'LabPay for WooCommerce needs PHP %1$s or newer (this site runs PHP %2$s). Ask your host to upgrade PHP: the plugin stays inactive until then.', 'labpay-for-woocommerce' ),
				LABPAY_WC_MIN_PHP,
				PHP_VERSION
			)
		)
	);
}

/**
 * Avis affiché quand le plugin est installé depuis les sources sans dépendances.
 *
 * @return void
 */
function labpay_wc_build_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'LabPay for WooCommerce is incomplete (vendor-prefixed/ is missing). Install the plugin from its zip file, or run "composer install" in its folder.', 'labpay-for-woocommerce' )
	);
}
