/* global jQuery, WSE */
( function ( $ ) {
	'use strict';

	var jobId = null;

	function log( lines ) {
		if ( ! lines ) {
			return;
		}
		if ( ! $.isArray( lines ) ) {
			lines = [ lines ];
		}
		var $log = $( '#wse-log' );
		lines.forEach( function ( line ) {
			if ( line ) {
				$log.append( $( '<div>' ).text( line ) );
			}
		} );
		$log.scrollTop( $log[ 0 ].scrollHeight );
	}

	function phase( text ) {
		$( '#wse-phase' ).text( text );
	}

	function setCounts( counts ) {
		counts = counts || {};
		var done  = counts.done || 0;
		var total = counts.total || 0;
		$( '#wse-processed' ).text( done );
		$( '#wse-total' ).text( total );
		$( '#wse-errors' ).text( counts.error || 0 );
		var pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		$( '#wse-bar-fill' ).css( 'width', pct + '%' );
	}

	function fail( res ) {
		var message = WSE.i18n.error;
		if ( res && res.data && res.data.message ) {
			message = res.data.message;
		} else if ( typeof res === 'string' ) {
			message = res;
		}
		phase( WSE.i18n.error );
		$( '#wse-spinner' ).removeClass( 'is-active' );
		$( '#wse-start' ).prop( 'disabled', false );
		$( '#wse-result' ).removeAttr( 'hidden' ).html(
			$( '<div class="notice notice-error"><p></p></div>' ).find( 'p' ).text( message ).end()
		);
	}

	function finalize() {
		phase( WSE.i18n.finalizing );
		$.post( WSE.ajaxUrl, { action: 'wse_finalize', nonce: WSE.nonce, job_id: jobId } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					fail( res );
					return;
				}
				if ( res.data.done === false ) {
					runBatch();
					return;
				}
				setCounts( res.data.counts );
				$( '#wse-spinner' ).removeClass( 'is-active' );
				$( '#wse-start' ).prop( 'disabled', false );
				phase( WSE.i18n.done );
				log( res.data.log );
				var $notice = $( '<div class="notice notice-success"><p></p></div>' );
				$notice.find( 'p' )
					.text( WSE.i18n.done + ' ' )
					.append( $( '<a class="button button-primary" download></a>' )
						.attr( 'href', res.data.zip )
						.text( 'Download export ZIP' ) );
				$( '#wse-result' ).removeAttr( 'hidden' ).empty().append( $notice );
			} )
			.fail( function () {
				fail();
			} );
	}

	function runBatch() {
		phase( WSE.i18n.crawling );
		$.post( WSE.ajaxUrl, { action: 'wse_batch', nonce: WSE.nonce, job_id: jobId } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					fail( res );
					return;
				}
				setCounts( res.data.counts );
				log( res.data.log );
				if ( res.data.done ) {
					finalize();
				} else {
					runBatch();
				}
			} )
			.fail( function () {
				fail();
			} );
	}

	$( function () {
		$( '#wse-start' ).on( 'click', function () {
			if ( ! window.confirm( WSE.i18n.confirm ) ) {
				return;
			}
			$( '#wse-start' ).prop( 'disabled', true );
			$( '#wse-spinner' ).addClass( 'is-active' );
			$( '#wse-progress' ).removeAttr( 'hidden' );
			$( '#wse-result' ).attr( 'hidden', 'hidden' ).empty();
			$( '#wse-log' ).empty();
			jobId = null;
			setCounts( {} );
			phase( WSE.i18n.starting );
			log( WSE.i18n.starting );

			$.post( WSE.ajaxUrl, { action: 'wse_start', nonce: WSE.nonce } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						fail( res );
						return;
					}
					jobId = res.data.job_id;
					setCounts( res.data.counts );
					log( 'Discovered ' + ( res.data.total || 0 ) + ' URLs.' );
					runBatch();
				} )
				.fail( function () {
					fail();
				} );
		} );
	} );
} )( jQuery );
