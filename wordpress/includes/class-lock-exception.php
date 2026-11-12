<?php
/**
 * Commande déjà en cours de mise à jour par une autre requête.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Le webhook répond alors 503 : LabPay renvoie la notification plus tard.
 */
final class Lock_Exception extends \RuntimeException {
}
