<?php
/**
 * Erreur réseau de l'API HTTP de WordPress, au format PSR-18.
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce\Http;

use LabPay\WooCommerce\Vendor\Psr\Http\Client\NetworkExceptionInterface;
use LabPay\WooCommerce\Vendor\Psr\Http\Message\RequestInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Le SDK la transforme en ConnectionException (et ne relance que si c'est sans risque).
 */
final class Network_Exception extends \RuntimeException implements NetworkExceptionInterface {

	/**
	 * Requête concernée.
	 *
	 * @var RequestInterface
	 */
	private RequestInterface $request;

	/**
	 * Constructeur.
	 *
	 * @param string           $message Message (celui de WP_Error).
	 * @param RequestInterface $request Requête.
	 */
	public function __construct( string $message, RequestInterface $request ) {
		parent::__construct( $message );
		$this->request = $request;
	}

	/**
	 * Requête concernée.
	 */
	public function getRequest(): RequestInterface { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- interface PSR-18.
		return $this->request;
	}
}
