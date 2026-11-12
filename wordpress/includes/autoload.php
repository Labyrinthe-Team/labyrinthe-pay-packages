<?php
/**
 * Chargement automatique des classes du plugin (conventions WordPress).
 *
 * LabPay\WooCommerce\Blocks\Payment_Method → includes/blocks/class-payment-method.php
 *
 * @package LabPayWooCommerce
 */

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'LabPay\\WooCommerce\\';
		if ( 0 !== strpos( $class_name, $prefix ) || 0 === strpos( $class_name, $prefix . 'Vendor\\' ) || 0 === strpos( $class_name, $prefix . 'Tests\\' ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name  = (string) array_pop( $parts );
		$dirs  = array_map( static fn( string $part ): string => strtolower( str_replace( '_', '-', $part ) ) . '/', $parts );
		$file  = __DIR__ . '/' . implode( '', $dirs ) . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';

		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
