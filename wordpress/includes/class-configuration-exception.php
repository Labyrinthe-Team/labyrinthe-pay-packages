<?php
/**
 * Réglage manquant (token, clé de test).
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Levée avant tout appel à l'API quand la clé du mode demandé n'est pas configurée.
 */
final class Configuration_Exception extends \RuntimeException {
}
