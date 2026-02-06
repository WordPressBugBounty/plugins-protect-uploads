/**
 * Handle password protection in the media library
 *
 * @package Protect_Uploads
 */

/* global jQuery, ajaxurl */
( function( $ ) {
	'use strict';

	/**
	 * Initialize password protection functionality
	 */
	function initPasswordProtection() {
		$( document ).on( 'click', '.add-password-button', function( e ) {
			e.preventDefault();
			const $container = $( this ).closest( '.protect-uploads-passwords' );
			const $label = $container.find( 'input[name="protect_uploads_password_label"]' );
			const $password = $container.find( 'input[name="protect_uploads_password"]' );
			const attachmentId = $container.closest( '.compat-field-protect_uploads_passwords' ).data( 'attachment-id' );

			if ( ! $label.val() || ! $password.val() ) {
				alert( protectUploadsL10n.enterBothFields );
				return;
			}

			addPassword( attachmentId, $label.val(), $password.val() )
				.then( function( response ) {
					if ( response.success ) {
						updatePasswordsList( $container, response.data.passwords );
						$label.val( '' );
						$password.val( '' );
					} else {
						alert( response.data.message );
					}
				} );
		} );

		$( document ).on( 'click', '.delete-password', function( e ) {
			e.preventDefault();
			const $container = $( this ).closest( '.protect-uploads-passwords' );
			const attachmentId = $container.closest( '.compat-field-protect_uploads_passwords' ).data( 'attachment-id' );
			const passwordId = $( this ).data( 'id' );

			if ( confirm( protectUploadsL10n.confirmDelete ) ) {
				deletePassword( attachmentId, passwordId )
					.then( function( response ) {
						if ( response.success ) {
							updatePasswordsList( $container, response.data.passwords );
						} else {
							alert( response.data.message );
						}
					} );
			}
		} );
	}

	/**
	 * Add a password to an attachment
	 *
	 * @param {number} attachmentId Attachment ID.
	 * @param {string} label       Password label.
	 * @param {string} password    Password.
	 * @return {Promise} jQuery promise.
	 */
	function addPassword( attachmentId, label, password ) {
		return $.post( ajaxurl, {
			action: 'protect_uploads_add_password',
			attachment_id: attachmentId,
			label: label,
			password: password,
			nonce: protectUploadsL10n.nonce
		} );
	}

	/**
	 * Delete a password
	 *
	 * @param {number} attachmentId Attachment ID.
	 * @param {number} passwordId   Password ID.
	 * @return {Promise} jQuery promise.
	 */
	function deletePassword( attachmentId, passwordId ) {
		return $.post( ajaxurl, {
			action: 'protect_uploads_delete_password',
			attachment_id: attachmentId,
			password_id: passwordId,
			nonce: protectUploadsL10n.nonce
		} );
	}

	/**
	 * Update the passwords list in the UI
	 *
	 * @param {jQuery} $container Container element.
	 * @param {Array}  passwords  Array of passwords.
	 */
	function updatePasswordsList( $container, passwords ) {
		const $list = $container.find( '.existing-passwords' );
		let html = '';

		if ( passwords.length ) {
			html += '<h4>' + protectUploadsL10n.existingPasswords + '</h4>';
			html += '<ul>';
			passwords.forEach( function( password ) {
				html += '<li>';
				html += password.label;
				html += ' <a href="#" class="delete-password" data-id="' + password.id + '">';
				html += protectUploadsL10n.delete;
				html += '</a>';
				html += '</li>';
			} );
			html += '</ul>';
		}

		$list.html( html );
	}

	// Initialize when document is ready.
	$( initPasswordProtection );

} )( jQuery ); 