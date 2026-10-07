/* global jQuery, doieAdmin */
( function ( $ ) {
	'use strict';

	var $form = $( '#doie-import-form' );
	if ( ! $form.length ) {
		return;
	}

	var $box = $( '#doie-progress' );
	var $status = $box.find( '.doie-status' );
	var $bar = $box.find( 'progress' );
	var $summary = $box.find( '.doie-summary' );
	var $messages = $box.find( '.doie-messages' );
	var shown = 0;

	function format( template, values ) {
		return template.replace( /%(\d+)\$s/g, function ( match, i ) {
			return values[ i - 1 ];
		} );
	}

	function render( state ) {
		$bar.attr( 'max', state.total ).val( state.processed );
		$summary.text(
			state.processed + ' / ' + state.total + ' — ' +
			format( doieAdmin.i18n.summary, [ state.created, state.updated, state.skipped, state.failed ] )
		);
		state.messages.slice( shown ).forEach( function ( message ) {
			$( '<li>' ).addClass( 'doie-' + message.type ).text( message.text ).appendTo( $messages );
		} );
		shown = state.messages.length;
	}

	function fail( message ) {
		$status.text( doieAdmin.i18n.failed + ' ' + message );
		$form.find( ':input' ).prop( 'disabled', false );
	}

	function errorMessage( response, xhr ) {
		if ( response && response.data && response.data.message ) {
			return response.data.message;
		}
		return xhr ? xhr.status + ' ' + xhr.statusText : 'Unknown error';
	}

	function batch( id ) {
		$.post( doieAdmin.ajaxUrl, { action: 'doie_import_batch', nonce: doieAdmin.nonce, job: id } )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					fail( errorMessage( response ) );
					return;
				}
				render( response.data );
				if ( response.data.done ) {
					$status.text( doieAdmin.i18n.done );
					$form.find( ':input' ).prop( 'disabled', false );
				} else {
					batch( id );
				}
			} )
			.fail( function ( xhr ) {
				fail( errorMessage( xhr.responseJSON, xhr ) );
			} );
	}

	$form.on( 'submit', function ( event ) {
		event.preventDefault();

		var file = $( '#doie-file' )[ 0 ].files[ 0 ];
		if ( ! file ) {
			window.alert( doieAdmin.i18n.chooseFile );
			return;
		}

		var data = new FormData( this );
		data.append( 'action', 'doie_import_start' );
		data.append( 'nonce', doieAdmin.nonce );

		shown = 0;
		$messages.empty();
		$summary.empty();
		$bar.val( 0 );
		$box.prop( 'hidden', false );
		$status.text( doieAdmin.i18n.uploading );
		$form.find( ':input' ).prop( 'disabled', true );

		$.ajax( { url: doieAdmin.ajaxUrl, method: 'POST', data: data, processData: false, contentType: false } )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					fail( errorMessage( response ) );
					return;
				}
				$status.text( doieAdmin.i18n.importing );
				render( response.data );
				batch( response.data.id );
			} )
			.fail( function ( xhr ) {
				fail( errorMessage( xhr.responseJSON, xhr ) );
			} );
	} );
}( jQuery ) );
