/* global jQuery, AOUWAL */
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
		var $log = $( '#aouwal-log' );
		lines.forEach( function ( line ) {
			if ( line ) {
				$log.append( $( '<div>' ).text( line ) );
			}
		} );
		$log.scrollTop( $log[ 0 ].scrollHeight );
	}

	function phase( text ) {
		$( '#aouwal-phase' ).text( text );
	}

	function setCounts( counts ) {
		counts = counts || {};
		var done  = counts.done || 0;
		var total = counts.total || 0;
		$( '#aouwal-processed' ).text( done );
		$( '#aouwal-total' ).text( total );
		$( '#aouwal-errors' ).text( counts.error || 0 );
		var pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		$( '#aouwal-bar-fill' ).css( 'width', pct + '%' );
	}

	function fail( res ) {
		var message = AOUWAL.i18n.error;
		if ( res && res.data && res.data.message ) {
			message = res.data.message;
		} else if ( typeof res === 'string' ) {
			message = res;
		}
		phase( AOUWAL.i18n.error );
		$( '#aouwal-spinner' ).removeClass( 'is-active' );
		$( '#aouwal-start' ).prop( 'disabled', false );
		$( '#aouwal-result' ).removeAttr( 'hidden' ).html(
			$( '<div class="notice notice-error"><p></p></div>' ).find( 'p' ).text( message ).end()
		);
	}

	function finalize() {
		phase( AOUWAL.i18n.finalizing );
		$.post( AOUWAL.ajaxUrl, { action: 'aouwal_finalize', nonce: AOUWAL.nonce, job_id: jobId } )
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
				$( '#aouwal-spinner' ).removeClass( 'is-active' );
				$( '#aouwal-start' ).prop( 'disabled', false );
				phase( AOUWAL.i18n.done );
				log( res.data.log );
				var $notice = $( '<div class="notice notice-success"><p></p></div>' );
				$notice.find( 'p' )
					.text( AOUWAL.i18n.done + ' ' )
					.append( $( '<a class="button button-primary" download></a>' )
						.attr( 'href', res.data.zip )
						.text( AOUWAL.i18n.download ) );
				$( '#aouwal-result' ).removeAttr( 'hidden' ).empty().append( $notice );
			} )
			.fail( function () {
				fail();
			} );
	}

	function runBatch() {
		phase( AOUWAL.i18n.crawling );
		$.post( AOUWAL.ajaxUrl, { action: 'aouwal_batch', nonce: AOUWAL.nonce, job_id: jobId } )
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
		$( '#aouwal-start' ).on( 'click', function () {
			if ( ! window.confirm( AOUWAL.i18n.confirm ) ) {
				return;
			}
			$( '#aouwal-start' ).prop( 'disabled', true );
			$( '#aouwal-spinner' ).addClass( 'is-active' );
			$( '#aouwal-progress' ).removeAttr( 'hidden' );
			$( '#aouwal-result' ).attr( 'hidden', 'hidden' ).empty();
			$( '#aouwal-log' ).empty();
			jobId = null;
			setCounts( {} );
			phase( AOUWAL.i18n.starting );
			log( AOUWAL.i18n.starting );

			$.post( AOUWAL.ajaxUrl, { action: 'aouwal_start', nonce: AOUWAL.nonce } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						fail( res );
						return;
					}
					jobId = res.data.job_id;
					setCounts( res.data.counts );
					log( AOUWAL.i18n.discovered.replace( '%s', res.data.total || 0 ) );
					runBatch();
				} )
				.fail( function () {
					fail();
				} );
		} );
	} );
} )( jQuery );
