( function ( $ ) {
	'use strict';

	$( document ).ready( function () {
		// Hide the dropdown menu when clicking outside of it
		$( 'html' ).on( 'click', function ( e ) {
			if ( !e.isDefaultPrevented() ) {
				$( '.dropdown-menu' ).addClass( 'hide' );
			}
		} );
	} );
} )( jQuery );
