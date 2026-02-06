/**
 * Handle image protection in the frontend
 *
 * @package Protect_Uploads
 */

/* global jQuery */
( function( $ ) {
	'use strict';

	/**
	 * Disable right-click on images
	 */
	$( document ).on( 'contextmenu', 'img', function( e ) {
		e.preventDefault();
		return false;
	} );

	/**
	 * Disable drag-and-drop on images
	 */
	$( document ).on( 'dragstart', 'img', function( e ) {
		e.preventDefault();
		return false;
	} );

	/**
	 * Disable keyboard shortcuts
	 */
	$( document ).on( 'keydown', function( e ) {
		// Ctrl/Cmd + S.
		if ( ( e.ctrlKey || e.metaKey ) && 83 === e.keyCode ) {
			e.preventDefault();
			return false;
		}

		// Ctrl/Cmd + Shift + I or Ctrl/Cmd + Shift + C.
		if ( ( e.ctrlKey || e.metaKey ) && e.shiftKey && ( 73 === e.keyCode || 67 === e.keyCode ) ) {
			e.preventDefault();
			return false;
		}
	} );

} )( jQuery ); 