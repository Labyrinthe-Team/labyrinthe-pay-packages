/**
 * Validation de commande classique et page « Payer la commande » : liste des opérateurs et
 * détection de l'opérateur d'après le numéro (indicative : LabPay le déduit aussi seul).
 * WooCommerce recrée les champs de paiement à chaque mise à jour du panier (updated_checkout).
 */
( function ( $, config ) {
	'use strict';

	if ( ! config ) {
		return;
	}

	var providers = null;
	var timer = null;

	function post( action, data ) {
		return $.post( config.ajaxUrl, $.extend( { action: action, nonce: config.nonce }, data || {} ) );
	}

	function fillProviders() {
		var $select = $( '#labpay_provider' );
		if ( ! $select.length || $select.data( 'labpayFilled' ) ) {
			return;
		}
		var apply = function ( list ) {
			var current = $select.val();
			$.each( list, function ( _, provider ) {
				$( '<option>' ).val( provider.code ).text( provider.name ).appendTo( $select );
			} );
			$select.val( current ).data( 'labpayFilled', true );
		};
		if ( providers ) {
			apply( providers );
			return;
		}
		post( 'labpay_wc_providers' ).done( function ( response ) {
			providers = response && response.success ? response.data.providers || [] : [];
			apply( providers );
		} );
	}

	function predict() {
		var $phone = $( '#labpay_phone' );
		var $hint = $( '.labpay-wc-detected' );
		var phone = $.trim( $phone.val() || '' );
		$hint.text( '' ).removeClass( 'labpay-wc-invalid' );
		if ( phone.replace( /\D/g, '' ).length < 9 ) {
			return;
		}
		post( 'labpay_wc_predict', { phone: phone } ).done( function ( response ) {
			var data = response && response.success ? response.data : null;
			if ( ! data || data.valid === null ) {
				return;
			}
			if ( data.valid === false ) {
				$hint.text( config.i18n.invalid ).addClass( 'labpay-wc-invalid' );
				return;
			}
			$hint.text( config.i18n.detected.replace( '%s', data.name ) );
			var $select = $( '#labpay_provider' );
			if ( $select.val() === '' && $select.find( 'option[value="' + data.provider + '"]' ).length ) {
				$select.val( data.provider );
			}
		} );
	}

	$( document.body ).on( 'updated_checkout payment_method_selected', fillProviders );
	$( document.body ).on( 'input change', '#labpay_phone', function () {
		window.clearTimeout( timer );
		timer = window.setTimeout( predict, 600 );
	} );
	$( fillProviders );
} )( window.jQuery, window.labpayWcCheckout );
