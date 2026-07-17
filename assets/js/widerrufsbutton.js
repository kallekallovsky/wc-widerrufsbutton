/* Widerrufsbutton für WooCommerce – Modal, Fokus-Trap, zweistufiger Ablauf */
( function () {
	'use strict';

	var cfg = window.WDBTN || {};
	var i18n = cfg.i18n || {};

	/*
	 * Bewusst strikt geprueft statt auf Truthiness vertraut: wuerde die
	 * Konfiguration je wieder ueber wp_localize_script laufen, kaeme hier
	 * der String "0" an — und "0" ist in JavaScript truthy. Gaeste haetten
	 * dann wieder als eingeloggt gegolten.
	 */
	var isLoggedIn = ( true === cfg.isLoggedIn || 1 === cfg.isLoggedIn || '1' === cfg.isLoggedIn );

	function t( key ) {
		return i18n[ key ] || '';
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	ready( function () {
		var overlay = document.getElementById( 'wdbtn-overlay' );
		var modal = document.getElementById( 'wdbtn-modal' );
		var form = document.getElementById( 'wdbtn-form' );
		if ( ! overlay || ! modal || ! form ) {
			return;
		}

		var lastFocused = null;
		var submitting = false;
		var desiredOrder = 0;

		var steps = {
			'1': modal.querySelector( '[data-step="1"]' ),
			'2': modal.querySelector( '[data-step="2"]' ),
			'done': modal.querySelector( '[data-step="done"]' )
		};

		var loggedinBlock = modal.querySelector( '.wdbtn-loggedin-only' );
		var guestBlock = modal.querySelector( '.wdbtn-guest-only' );
		var skuField = modal.querySelector( '.wdbtn-sku-field' );
		var orderSelect = document.getElementById( 'wdbtn-order-select' );

		// Eingeloggt vs. Gast.
		if ( isLoggedIn ) {
			if ( loggedinBlock ) { loggedinBlock.hidden = false; }
			loadOrders();
		} else if ( guestBlock ) {
			guestBlock.hidden = false;
		}

		// Produktseiten-Vorbefüllung.
		var prefill = cfg.prefillSku || {};
		// Allein die Produkt-ID entscheidet ueber den Artikelbezug: eine
		// Artikelnummer haben laengst nicht alle Produkte, eine ID immer.
		if ( prefill.id ) {
			if ( skuField ) { skuField.hidden = false; }
			var labelInput = document.getElementById( 'wdbtn-item-label' );
			var skuInput = document.getElementById( 'wdbtn-sku' );
			var pidInput = document.getElementById( 'wdbtn-product-id' );
			var scopeInput = document.getElementById( 'wdbtn-scope' );
			if ( labelInput ) { labelInput.value = prefill.label || prefill.sku || ''; }
			if ( skuInput ) { skuInput.value = prefill.sku || ''; }
			if ( pidInput ) { pidInput.value = prefill.id; }
			if ( scopeInput ) { scopeInput.value = 'item'; }
		}

		function loadOrders() {
			if ( ! isLoggedIn || ! orderSelect || ! cfg.ordersAction ) { return; }
			var data = new FormData();
			data.append( 'action', cfg.ordersAction );
			data.append( 'nonce', cfg.ordersNonce || '' );
			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			} ).then( function ( res ) {
				if ( ! res.ok ) { throw new Error( 'HTTP ' + res.status ); }
				return res.json();
			} ).then( function ( json ) {
				if ( ! json || ! json.success || ! json.data ) {
					throw new Error( 'Unerwartete Antwort' );
				}
				var orders = json.data.orders || [];
				orders.forEach( function ( o ) {
					var opt = document.createElement( 'option' );
					opt.value = o.id;
					opt.textContent = o.label;
					orderSelect.appendChild( opt );
				} );
				if ( ! orders.length ) {
					var hint = modal.querySelector( '.wdbtn-no-orders' );
					if ( hint ) { hint.hidden = false; }
				}
				applyDesiredOrder();
			} ).catch( function () {
				/*
				 * Frueher wurde hier stillschweigend abgebrochen: das Auswahlfeld
				 * blieb einfach leer, ohne jede Erklaerung. Bei einer gesetzlich
				 * vorgeschriebenen Funktion darf ein Fehlschlag nicht unsichtbar
				 * bleiben — der Gast-Pfad ueber die Bestellnummer bleibt nutzbar.
				 */
				showMessage( 'wdbtn-message-1', t( 'ordersFailed' ) );
				if ( guestBlock ) { guestBlock.hidden = false; }
			} );
		}

		function applyDesiredOrder() {
			if ( ! desiredOrder || ! orderSelect ) { return; }
			var opt = orderSelect.querySelector( 'option[value="' + desiredOrder + '"]' );
			if ( opt ) { orderSelect.value = String( desiredOrder ); }
		}

		function showStep( name ) {
			Object.keys( steps ).forEach( function ( key ) {
				if ( steps[ key ] ) { steps[ key ].hidden = ( key !== name ); }
			} );
		}

		function focusables() {
			return Array.prototype.slice.call(
				modal.querySelectorAll(
					'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
				)
			).filter( function ( el ) {
				return el.offsetParent !== null;
			} );
		}

		function focusFirst() {
			var els = focusables();
			if ( els.length ) { els[ 0 ].focus(); }
		}

		function trap( e ) {
			if ( e.key !== 'Tab' ) { return; }
			var els = focusables();
			if ( ! els.length ) { return; }
			var first = els[ 0 ];
			var last = els[ els.length - 1 ];
			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}

		function onKeydown( e ) {
			if ( e.key === 'Escape' && ! submitting ) {
				close();
			} else if ( e.key === 'Tab' ) {
				trap( e );
			}
		}

		function open( trigger ) {
			lastFocused = trigger || document.activeElement;
			// Per-Trigger-Bestellbezug (Kundenkonto).
			if ( trigger ) {
				var oid = trigger.getAttribute( 'data-order-id' ) || '';
				if ( ! oid ) {
					var href = trigger.getAttribute( 'href' ) || '';
					var m = href.match( /wdbtn-order-(\d+)/ );
					if ( m ) { oid = m[ 1 ]; }
				}
				if ( oid ) {
					desiredOrder = parseInt( oid, 10 );
					applyDesiredOrder();
				}
			}
			// Per-Trigger-Artikelbezug.
			if ( trigger ) {
				var sku = trigger.getAttribute( 'data-sku' ) || '';
				var pid = parseInt( trigger.getAttribute( 'data-product-id' ) || '0', 10 );
				if ( sku || pid ) {
					if ( skuField ) { skuField.hidden = false; }
					var si = document.getElementById( 'wdbtn-sku' );
					var pi = document.getElementById( 'wdbtn-product-id' );
					var sc = document.getElementById( 'wdbtn-scope' );
					if ( si && sku ) { si.value = sku; }
					if ( pi && pid ) { pi.value = pid; }
					if ( sc ) { sc.value = 'item'; }
				}
			}
			clearMessages();
			showStep( '1' );
			overlay.hidden = false;
			document.body.style.overflow = 'hidden';
			document.addEventListener( 'keydown', onKeydown, true );
			focusFirst();
		}

		function close() {
			overlay.hidden = true;
			document.body.style.overflow = '';
			document.removeEventListener( 'keydown', onKeydown, true );
			if ( lastFocused && typeof lastFocused.focus === 'function' ) {
				lastFocused.focus();
			}
		}

		function clearMessages() {
			[ 'wdbtn-message-1', 'wdbtn-message-2' ].forEach( function ( id ) {
				var m = document.getElementById( id );
				if ( m ) { m.hidden = true; m.textContent = ''; }
			} );
		}

		function showMessage( id, text ) {
			var m = document.getElementById( id );
			if ( m ) { m.textContent = text; m.hidden = false; }
		}

		function isValidEmail( value ) {
			return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value );
		}

		function val( id ) {
			var el = document.getElementById( id );
			return el ? el.value.trim() : '';
		}

		function selectedOrder() {
			return orderSelect && orderSelect.value ? orderSelect.value : '';
		}

		function validateStep1() {
			clearMessages();
			if ( cfg.isLoggedIn && selectedOrder() ) {
				return true;
			}
			// Gast-Pfad (auch Fallback für eingeloggte Nutzer ohne Auswahl).
			var name = val( 'wdbtn-name' );
			var orderNo = val( 'wdbtn-order-number' );
			var email = val( 'wdbtn-email' );
			if ( ! name || ! orderNo || ! email ) {
				showMessage( 'wdbtn-message-1', t( 'fillRequired' ) );
				return false;
			}
			if ( ! isValidEmail( email ) ) {
				showMessage( 'wdbtn-message-1', t( 'invalidEmail' ) );
				return false;
			}
			return true;
		}

		function buildSummary() {
			var box = document.getElementById( 'wdbtn-summary' );
			if ( ! box ) { return; }
			var rows = [];
			var orderLabel;
			if ( cfg.isLoggedIn && selectedOrder() ) {
				orderLabel = orderSelect.options[ orderSelect.selectedIndex ].text;
			} else {
				orderLabel = val( 'wdbtn-order-number' );
			}
			if ( val( 'wdbtn-name' ) ) { rows.push( [ 'Name', val( 'wdbtn-name' ) ] ); }
			if ( orderLabel ) { rows.push( [ 'Bestellung', orderLabel ] ); }
			if ( val( 'wdbtn-email' ) ) { rows.push( [ 'E-Mail', val( 'wdbtn-email' ) ] ); }
			if ( val( 'wdbtn-sku' ) ) { rows.push( [ 'Artikel', val( 'wdbtn-sku' ) ] ); }

			var dl = document.createElement( 'dl' );
			rows.forEach( function ( r ) {
				var dt = document.createElement( 'dt' );
				dt.textContent = r[ 0 ];
				var dd = document.createElement( 'dd' );
				dd.textContent = r[ 1 ];
				dl.appendChild( dt );
				dl.appendChild( dd );
			} );
			box.innerHTML = '';
			box.appendChild( dl );
		}

		function submit() {
			if ( submitting ) { return; }
			submitting = true;
			var confirmBtn = modal.querySelector( '[data-wdbtn-confirm]' );
			if ( confirmBtn ) { confirmBtn.disabled = true; confirmBtn.textContent = t( 'sending' ); }

			var data = new FormData( form );
			data.append( 'action', cfg.action || 'wdbtn_submit' );
			data.append( 'nonce', cfg.nonce || '' );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: data
			} ).then( function ( res ) {
				return res.text();
			} ).then( function ( text ) {
				var json = null;
				try { json = JSON.parse( text ); } catch ( e ) { json = null; }
				if ( json && json.success ) {
					var doneText = document.getElementById( 'wdbtn-done-text' );
					if ( doneText && json.data && json.data.message ) {
						doneText.textContent = json.data.message;
					}
					var doneTitle = document.getElementById( 'wdbtn-done-title' );
					if ( doneTitle && json.data && json.data.pending ) {
						doneTitle.textContent = ( i18n.checkEmail || doneTitle.textContent );
					}
					showStep( 'done' );
					focusFirst();
				} else {
					var msg = ( json && json.data && json.data.message ) ? json.data.message : t( 'notReady' );
					showMessage( 'wdbtn-message-2', msg );
				}
			} ).catch( function () {
				showMessage( 'wdbtn-message-2', t( 'genericError' ) );
			} ).then( function () {
				submitting = false;
				if ( confirmBtn ) {
					confirmBtn.disabled = false;
					confirmBtn.textContent = confirmBtn.getAttribute( 'data-label' ) || confirmBtn.textContent;
				}
			} );
		}

		// Auslöser.
		document.addEventListener( 'click', function ( e ) {
			var trigger = e.target.closest ? e.target.closest( '.wdbtn-trigger' ) : null;
			if ( trigger ) {
				e.preventDefault();
				open( trigger );
			}
		} );

		// Overlay-Klick (außerhalb des Modals) schließt.
		overlay.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === overlay && ! submitting ) {
				close();
			}
		} );

		// Schließen-/Navigations-Buttons.
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-wdbtn-close]' ) ) {
				e.preventDefault();
				close();
			} else if ( e.target.closest( '[data-wdbtn-next]' ) ) {
				e.preventDefault();
				if ( validateStep1() ) {
					buildSummary();
					showStep( '2' );
					var c = modal.querySelector( '[data-wdbtn-confirm]' );
					if ( c ) { c.focus(); }
				}
			} else if ( e.target.closest( '[data-wdbtn-back]' ) ) {
				e.preventDefault();
				clearMessages();
				showStep( '1' );
				focusFirst();
			}
		} );

		// Merke das Originallabel des Bestätigen-Buttons.
		var confirmBtn = modal.querySelector( '[data-wdbtn-confirm]' );
		if ( confirmBtn ) { confirmBtn.setAttribute( 'data-label', confirmBtn.textContent ); }

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			submit();
		} );
	} );
}() );
