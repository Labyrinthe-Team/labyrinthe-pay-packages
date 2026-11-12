<?php
/**
 * Serveur HTTP simulé : intercepte wp_remote_request() (filtre pre_http_request).
 *
 * @package LabPayWooCommerce
 */

namespace LabPay\WooCommerce\Tests\Support;

/**
 * Les réponses sont servies dans l'ordre ; toute requête inattendue à l'API fait échouer le test.
 */
final class HttpMock {

	/**
	 * Requêtes reçues : method, url, headers, body (JSON décodé).
	 *
	 * @var list<array{method: string, url: string, headers: array<string, string>, body: array<mixed>|null}>
	 */
	public array $requests = array();

	/**
	 * Réponses à servir.
	 *
	 * @var list<array{status: int, body: array<mixed>|string, headers: array<string, string>}|\WP_Error>
	 */
	private array $queue = array();

	public function install(): void {
		add_filter( 'pre_http_request', array( $this, 'handle' ), 10, 3 );
	}

	public function uninstall(): void {
		remove_filter( 'pre_http_request', array( $this, 'handle' ), 10 );
	}

	/**
	 * Ajoute une réponse JSON.
	 *
	 * @param int                   $status  Statut HTTP.
	 * @param array<mixed>|string   $body    Corps (tableau encodé en JSON).
	 * @param array<string, string> $headers En-têtes.
	 */
	public function push( int $status, $body, array $headers = array() ): self {
		$this->queue[] = array(
			'status'  => $status,
			'body'    => $body,
			'headers' => $headers + array(
				'content-type'      => 'application/json',
				'x-labyrinthe-mode' => 'test',
			),
		);

		return $this;
	}

	/**
	 * Ajoute une erreur API au format { message, code }.
	 *
	 * @param int    $status Statut HTTP.
	 * @param string $code   Code d'erreur.
	 */
	public function push_error( int $status, string $code ): self {
		return $this->push(
			$status,
			array(
				'message' => 'Erreur ' . $code,
				'code'    => $code,
			)
		);
	}

	/**
	 * Ajoute une erreur réseau (WP_Error).
	 *
	 * @param string $message Message.
	 */
	public function push_network_error( string $message = 'cURL error 7: Failed to connect' ): self {
