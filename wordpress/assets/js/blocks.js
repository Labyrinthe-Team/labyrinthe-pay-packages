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
				return props.eventRegistration.onPaymentSetup( function () {
					if ( phone.replace( /\D/g, '' ).length < 6 ) {
						return {
							type: props.emitResponse.responseTypes.ERROR,
							message: __( 'Enter a valid Mobile Money number (for example 0812345678 or +243812345678).', 'labpay-for-woocommerce' ),
						};
					}
					return {
						type: props.emitResponse.responseTypes.SUCCESS,
						meta: { paymentMethodData: { labpay_phone: phone, labpay_provider: provider } },
					};
				} );
			},
			[ phone, provider, props.eventRegistration.onPaymentSetup ]
		);

		return el(
			'div',
			{ className: 'labpay-wc-fields' },
			data.description ? el( 'p', null, htmlEntities.decodeEntities( data.description ) ) : null,
			data.testMode ? el( 'p', { className: 'labpay-wc-test' }, __( 'Test mode: no real money moves.', 'labpay-for-woocommerce' ) ) : null,
			el(
				'div',
				{ className: 'wc-block-components-text-input is-active' },
				el( 'input', {
					id: 'labpay-phone',
					type: 'tel',
					inputMode: 'tel',
					autoComplete: 'tel',
					maxLength: 20,
					value: phone,
					required: true,
					onChange: function ( event ) {
						phoneState[ 1 ]( event.target.value );
					},
				} ),
				el( 'label', { htmlFor: 'labpay-phone' }, __( 'Mobile Money number', 'labpay-for-woocommerce' ) )
			),
			el( 'p', { className: 'labpay-wc-help' }, __( 'You will receive a confirmation request (PIN) on this phone.', 'labpay-for-woocommerce' ) ),
			el(
				'p',
				{ className: 'labpay-wc-select' },
				el( 'label', { htmlFor: 'labpay-provider' }, __( 'Operator', 'labpay-for-woocommerce' ) ),
				el(
					'select',
					{
						id: 'labpay-provider',
						value: provider,
						onChange: function ( event ) {
							providerState[ 1 ]( event.target.value );
						},
					},
					[ el( 'option', { key: '', value: '' }, __( 'Detect automatically', 'labpay-for-woocommerce' ) ) ].concat(
						listState[ 0 ].map( function ( item ) {
							return el( 'option', { key: item.code, value: item.code }, item.name );
						} )
					)
				)
			),
			hintState[ 0 ] ? el( 'p', { className: 'labpay-wc-detected', 'aria-live': 'polite' }, hintState[ 0 ] ) : null
		);
	}

	registry.registerPaymentMethod( {
		name: 'labpay',
		label: el( 'span', null, title ),
		ariaLabel: title,
		content: el( Fields ),
		edit: el( 'p', null, title ),
		canMakePayment: function () {
			return true;
		},
		supports: { features: data.supports || [ 'products' ] },
	} );
} )( window.wc.wcBlocksRegistry, window.wc.wcSettings, window.wp.element, window.wp.htmlEntities, window.wp.i18n );
