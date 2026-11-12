/**
 * Méthode de paiement LabPay pour la validation de commande en blocs. Mêmes champs que la
 * validation classique ; ils partent dans payment_data (labpay_phone, labpay_provider) et
 * WooCommerce les remet dans $_POST pour Gateway::process_payment().
 */
( function ( registry, settings, element, htmlEntities, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var sprintf = i18n.sprintf;
	var data = settings.getSetting( 'labpay_data', {} );
	var title = htmlEntities.decodeEntities( data.title || __( 'Mobile Money', 'labpay-for-woocommerce' ) );

	function post( action, fields ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', data.nonce );
		Object.keys( fields || {} ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );
		return window
			.fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} );
	}

	function Fields( props ) {
		var billing = props.billing && props.billing.billingAddress ? props.billing.billingAddress : {};
		var phoneState = element.useState( billing.phone || '' );
		var providerState = element.useState( '' );
		var listState = element.useState( [] );
		var hintState = element.useState( '' );
		var phone = phoneState[ 0 ];
		var provider = providerState[ 0 ];

		element.useEffect( function () {
			post( 'labpay_wc_providers' )
				.then( function ( json ) {
					listState[ 1 ]( json && json.success ? json.data.providers || [] : [] );
				} )
				.catch( function () {} );
		}, [] );

		element.useEffect(
			function () {
				hintState[ 1 ]( '' );
				if ( phone.replace( /\D/g, '' ).length < 9 ) {
					return undefined;
				}
				var timer = window.setTimeout( function () {
					post( 'labpay_wc_predict', { phone: phone } )
						.then( function ( json ) {
							var result = json && json.success ? json.data : null;
							if ( ! result || result.valid === null ) {
								return;
							}
							hintState[ 1 ](
								result.valid
									? /* translators: %s: operator name. */
									  sprintf( __( 'Detected operator: %s', 'labpay-for-woocommerce' ), result.name )
									: __( 'This number does not look like a valid Mobile Money number.', 'labpay-for-woocommerce' )
							);
						} )
						.catch( function () {} );
				}, 600 );
				return function () {
					window.clearTimeout( timer );
				};
			},
			[ phone ]
		);

		element.useEffect(
			function () {
