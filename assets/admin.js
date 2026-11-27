/* WPEssential Server admin: spinner on submit buttons and top-bar links while the page loads. */
( function () {
	'use strict';
	function busy( el ) {
		el.classList.add( 'is-loading' );
		el.setAttribute( 'aria-busy', 'true' );
	}
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var button = event.submitter || ( form.querySelector && form.querySelector( '[type="submit"], button:not([type])' ) );
		window.setTimeout( function () {
			if ( ! event.defaultPrevented && button ) {
				busy( button );
			}
		}, 0 );
	} );
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( '.wpes-nav__item, .page-title-action[href]' );
		if ( link && ! event.defaultPrevented && 0 === event.button && ! event.metaKey && ! event.ctrlKey ) {
			busy( link );
		}
	} );
	// Settings: sidebar tabs (hash), Replace / Delete on saved secrets.
	var panels = document.querySelectorAll( '[data-wpes-panel]' );
	if ( panels.length ) {
		var field = document.querySelector( '[data-wpes-tab-field]' );
		var show = function ( id ) {
			if ( ! document.getElementById( id ) || ! document.getElementById( id ).hasAttribute( 'data-wpes-panel' ) ) {
				id = panels[ 0 ].id;
			}
			panels.forEach( function ( panel ) {
				panel.hidden = panel.id !== id;
			} );
			document.querySelectorAll( '[data-wpes-tab]' ).forEach( function ( link ) {
				var on = link.getAttribute( 'data-wpes-tab' ) === id;
				link.classList.toggle( 'is-active', on );
				if ( on ) {
					link.setAttribute( 'aria-current', 'page' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			} );
			if ( field ) {
				field.value = id;
			}
		};
		window.addEventListener( 'hashchange', function () {
			show( window.location.hash.slice( 1 ) );
		} );
		show( window.location.hash.slice( 1 ) );
	}
	document.addEventListener( 'click', function ( event ) {
		var replace = event.target.closest && event.target.closest( '[data-wpes-replace]' );
		if ( replace ) {
			var box = replace.closest( '[data-wpes-secret]' ).querySelector( '.wpes-secret__new' );
			box.hidden = false;
			box.querySelector( 'input' ).focus();
			replace.hidden = true;
		}
		var confirmButton = event.target.closest && event.target.closest( '[data-wpes-confirm]' );
		if ( confirmButton && ! window.confirm( confirmButton.getAttribute( 'data-wpes-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
	// Back/forward cache: a restored page must not keep spinners.
	window.addEventListener( 'pageshow', function () {
		document.querySelectorAll( '.is-loading' ).forEach( function ( el ) {
			el.classList.remove( 'is-loading' );
			el.removeAttribute( 'aria-busy' );
		} );
	} );
}() );
