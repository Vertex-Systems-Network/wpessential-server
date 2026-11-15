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
	// Back/forward cache: a restored page must not keep spinners.
	window.addEventListener( 'pageshow', function () {
		document.querySelectorAll( '.is-loading' ).forEach( function ( el ) {
			el.classList.remove( 'is-loading' );
			el.removeAttribute( 'aria-busy' );
		} );
	} );
}() );
