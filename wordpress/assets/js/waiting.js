/**
 * Page d'attente : interroge le statut de la commande jusqu'au statut final du paiement
 * (le serveur relit le paiement chez LabPay au besoin), puis recharge la page.
 */
( function ( config ) {
	'use strict';

	var box = document.getElementById( 'labpay-wc-waiting' );
	if ( ! config || ! box || ! window.fetch ) {
		return;
	}

	var started = Date.now();
	var message = box.querySelector( '.labpay-wc-message' );

	function done( text, url, label ) {
		box.classList.remove( 'labpay-wc-waiting' );
		var spinner = box.querySelector( '.labpay-wc-spinner' );
		if ( spinner ) {
			spinner.remove();
		}
		message.textContent = text;
		if ( url ) {
			var link = document.createElement( 'a' );
			link.className = 'button';
			link.href = url;
			link.textContent = label;
			message.appendChild( document.createTextNode( ' ' ) );
			message.appendChild( link );
		}
	}

	function poll() {
		if ( Date.now() - started > config.timeout ) {
			done( config.i18n.timeout, window.location.href, config.i18n.retry );
			return;
		}
		var body = new URLSearchParams();
		body.append( 'action', config.action );
		body.append( 'nonce', config.nonce );
		body.append( 'order_id', String( config.orderId ) );
		body.append( 'key', config.key );

		window
			.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				var data = json && json.success ? json.data : null;
				if ( ! data ) {
					window.setTimeout( poll, config.interval * 2 );
					return;
				}
				if ( data.state === 'completed' || data.state === 'on-hold' ) {
					done( data.message );
					window.location.replace( data.redirect || window.location.href );
					return;
				}
				if ( data.state === 'failed' ) {
					box.classList.add( 'labpay-wc-failed' );
					done( data.message, data.retry_url, config.i18n.retry );
					return;
				}
				message.textContent = data.message;
				window.setTimeout( poll, config.interval );
			} )
			.catch( function () {
				window.setTimeout( poll, config.interval * 2 );
			} );
	}

	window.setTimeout( poll, config.interval );
} )( window.labpayWcWaiting );
