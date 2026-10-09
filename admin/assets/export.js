/* global jQuery, STS */
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
		var $log = $( '#sts-log' );
		lines.forEach( function ( line ) {
			if ( line ) {
				$log.append( $( '<div>' ).text( line ) );
			}
		} );
		$log.scrollTop( $log[ 0 ].scrollHeight );
	}

	function phase( text ) {
		$( '#sts-phase' ).text( text );
	}

	function setCounts( counts ) {
		counts = counts || {};
		var done  = counts.done || 0;
		var total = counts.total || 0;
		$( '#sts-processed' ).text( done );
		$( '#sts-total' ).text( total );
		$( '#sts-errors' ).text( counts.error || 0 );
		var pct = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;
		$( '#sts-bar-fill' ).css( 'width', pct + '%' );
	}

	function fail( res ) {
		var message = STS.i18n.error;
		if ( res && res.data && res.data.message ) {
			message = res.data.message;
		} else if ( typeof res === 'string' ) {
			message = res;
		}
		phase( STS.i18n.error );
		$( '#sts-spinner' ).removeClass( 'is-active' );
		$( '#sts-start' ).prop( 'disabled', false );
		$( '#sts-result' ).removeAttr( 'hidden' ).html(
			$( '<div class="notice notice-error"><p></p></div>' ).find( 'p' ).text( message ).end()
		);
	}

	function finalize() {
		phase( STS.i18n.finalizing );
		$.post( STS.ajaxUrl, { action: 'sts_finalize', nonce: STS.nonce, job_id: jobId } )
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
				$( '#sts-spinner' ).removeClass( 'is-active' );
				$( '#sts-start' ).prop( 'disabled', false );
				phase( STS.i18n.done );
				log( res.data.log );
				var $notice = $( '<div class="notice notice-success"><p></p></div>' );
				$notice.find( 'p' )
					.text( STS.i18n.done + ' ' )
					.append( $( '<a class="button button-primary" download></a>' )
						.attr( 'href', res.data.zip )
						.text( STS.i18n.download ) );
				$( '#sts-result' ).removeAttr( 'hidden' ).empty().append( $notice );
			} )
			.fail( function () {
				fail();
			} );
	}

	function runBatch() {
		phase( STS.i18n.crawling );
		$.post( STS.ajaxUrl, { action: 'sts_batch', nonce: STS.nonce, job_id: jobId } )
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
		$( '#sts-start' ).on( 'click', function () {
			if ( ! window.confirm( STS.i18n.confirm ) ) {
				return;
			}
			$( '#sts-start' ).prop( 'disabled', true );
			$( '#sts-spinner' ).addClass( 'is-active' );
			$( '#sts-progress' ).removeAttr( 'hidden' );
			$( '#sts-result' ).attr( 'hidden', 'hidden' ).empty();
			$( '#sts-log' ).empty();
			jobId = null;
			setCounts( {} );
			phase( STS.i18n.starting );
			log( STS.i18n.starting );

			$.post( STS.ajaxUrl, { action: 'sts_start', nonce: STS.nonce } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						fail( res );
						return;
					}
					jobId = res.data.job_id;
					setCounts( res.data.counts );
					log( STS.i18n.discovered.replace( '%s', res.data.total || 0 ) );
					runBatch();
				} )
				.fail( function () {
					fail();
				} );
		} );
	} );
} )( jQuery );
