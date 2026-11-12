<?php
/**
 * Désinstallation : supprime les réglages et les données temporaires de LabPay.
 *
 * Les métadonnées des commandes (identifiant du paiement LabPay, mode, tentatives) sont
 * conservées : elles font partie de l'historique comptable de la boutique.
 *
 * @package LabPayWooCommerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/autoload.php';

LabPay\WooCommerce\Uninstaller::run();
