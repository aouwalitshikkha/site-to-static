/* global jQuery, AOUWAL */
( function ( $ ) {
	'use strict';

	var jobId   = null;
	var stopped = false;

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

	/**
	 * Show only the controls that make sense for the current state.
	 *
	 * @param {string} state One of starting, running, paused, idle.
	 */
	function setButtons( state ) {
		var active  = ( 'running' === state || 'paused' === state );
		var working = ( 'running' === state || 'starting' === state );

		$( '#aouwal-start' ).prop( 'disabled', active || 'starting' === state );
		$( '#aouwal-pause' ).prop( 'disabled', false );
		$( '#aouwal-resume' ).prop( 'disabled', false );
		$( '#aouwal-cancel' ).prop( 'disabled', false );
		// A paused export is not doing anything, so it must not look busy.
		$( '#aouwal-spinner' ).toggleClass( 'is-active', working );

		if ( 'running' === state ) {
			$( '#aouwal-pause' ).removeAttr( 'hidden' );
		} else {
			$( '#aouwal-pause' ).attr( 'hidden', 'hidden' );
		}

		if ( 'paused' === state ) {
			$( '#aouwal-resume' ).removeAttr( 'hidden' );
		} else {
			$( '#aouwal-resume' ).attr( 'hidden', 'hidden' );
		}

		if ( active ) {
			$( '#aouwal-cancel' ).removeAttr( 'hidden' );
		} else {
			$( '#aouwal-cancel' ).attr( 'hidden', 'hidden' );
		}
	}

	/**
	 * Replace the result area with a notice.
	 *
	 * @param {string} message Message text.
	 * @param {string} type    Notice class.
	 * @return {jQuery} The notice element.
	 */
	function notice( message, type ) {
		var $notice = $( '<div class="notice ' + type + '"><p></p></div>' );
		$notice.find( 'p' ).text( message );
		$( '#aouwal-result' ).removeAttr( 'hidden' ).empty().append( $notice );
		return $notice;
	}

	function downloadLink( $notice, url ) {
		$notice.find( 'p' ).append(
			$( '<a class="button button-primary" download></a>' ).attr( 'href', url ).text( AOUWAL.i18n.download )
		);
	}

	function fail( res ) {
		var message = AOUWAL.i18n.error;
		if ( res && res.data && res.data.message ) {
			message = res.data.message;
		} else if ( typeof res === 'string' ) {
			message = res;
		}
		phase( AOUWAL.i18n.error );
		setButtons( 'idle' );
		notice( message, 'notice-error' );
	}

	function showCancelled( lines ) {
		var message = ( lines && lines.length ) ? lines[ lines.length - 1 ] : AOUWAL.i18n.cancelled;
		stopped = true;
		jobId   = null;
		phase( AOUWAL.i18n.cancelled );
		setButtons( 'idle' );
		notice( message, 'notice-warning' );
	}

	function showPaused( counts ) {
		setCounts( counts );
		phase( AOUWAL.i18n.paused );
		setButtons( 'paused' );
	}

	function finalize() {
		if ( stopped ) {
			return;
		}
		phase( AOUWAL.i18n.finalizing );
		$.post( AOUWAL.ajaxUrl, { action: 'aouwal_finalize', nonce: AOUWAL.nonce, job_id: jobId } )
			.done( function ( res ) {
				if ( stopped ) {
					return;
				}
				if ( ! res || ! res.success ) {
					fail( res );
					return;
				}
				if ( res.data.cancelled ) {
					showCancelled( res.data.log );
					return;
				}
				if ( res.data.paused ) {
					showPaused( res.data.counts );
					return;
				}
				if ( res.data.done === false ) {
					runBatch();
					return;
				}
				setCounts( res.data.counts );
				setButtons( 'idle' );
				phase( AOUWAL.i18n.done );
				log( res.data.log );
				var hasErrors = res.data.counts && res.data.counts.error > 0;
				var $notice = notice(
					( hasErrors ? AOUWAL.i18n.done_with_errors : AOUWAL.i18n.done ) + ' ',
					hasErrors ? 'notice-warning' : 'notice-success'
				);
				downloadLink( $notice, res.data.zip );
			} )
			.fail( function () {
				if ( ! stopped ) {
					fail();
				}
			} );
	}

	function runBatch() {
		if ( stopped ) {
			return;
		}
		phase( AOUWAL.i18n.crawling );
		$.post( AOUWAL.ajaxUrl, { action: 'aouwal_batch', nonce: AOUWAL.nonce, job_id: jobId } )
			.done( function ( res ) {
				if ( stopped ) {
					return;
				}
				if ( ! res || ! res.success ) {
					fail( res );
					return;
				}
				setCounts( res.data.counts );
				log( res.data.log );
				if ( res.data.cancelled ) {
					showCancelled( res.data.log );
				} else if ( res.data.paused ) {
					showPaused( res.data.counts );
				} else if ( res.data.done ) {
					finalize();
				} else {
					runBatch();
				}
			} )
			.fail( function () {
				if ( ! stopped ) {
					fail();
				}
			} );
	}

	/**
	 * Restore the screen for a job that is still stored, for example after a
	 * reload. The loop is never restarted automatically: two tabs would both
	 * drive the same queue.
	 *
	 * @param {Object} state Status payload.
	 */
	function attach( state ) {
		jobId = state.job_id;

		if ( state.paused ) {
			$( '#aouwal-progress' ).removeAttr( 'hidden' );
			setCounts( state.counts );
			log( AOUWAL.i18n.paused + ( state.current_url ? ' ' + state.current_url : '' ) );
			showPaused( state.counts );
			return;
		}

		if ( state.running ) {
			$( '#aouwal-progress' ).removeAttr( 'hidden' );
			setCounts( state.counts );
			if ( state.stale ) {
				setButtons( 'paused' );
				phase( AOUWAL.i18n.interrupted );
				log( AOUWAL.i18n.interrupted );
			} else {
				setButtons( 'running' );
				phase( AOUWAL.i18n.running_elsewhere );
				log( AOUWAL.i18n.running_elsewhere );
			}
			return;
		}

		if ( 'cancelled' === state.phase && state.notice && state.notice.length ) {
			phase( AOUWAL.i18n.cancelled );
			notice( state.notice[ state.notice.length - 1 ], 'notice-warning' );
			jobId = null;
			return;
		}

		if ( state.zip ) {
			var $notice = notice( AOUWAL.i18n.download_last + ' ', 'notice-success' );
			downloadLink( $notice, state.zip );
			jobId = null;
		}
	}

	$( function () {
		$( '#aouwal-start' ).on( 'click', function () {
			if ( ! window.confirm( AOUWAL.i18n.confirm ) ) {
				return;
			}
			stopped = false;
			jobId   = null;
			setButtons( 'starting' );
			$( '#aouwal-progress' ).removeAttr( 'hidden' );
			$( '#aouwal-result' ).attr( 'hidden', 'hidden' ).empty();
			$( '#aouwal-log' ).empty();
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
					setButtons( 'running' );
					runBatch();
				} )
				.fail( function () {
					fail();
				} );
		} );

		$( '#aouwal-pause' ).on( 'click', function () {
			if ( ! jobId ) {
				return;
			}
			$( '#aouwal-pause' ).prop( 'disabled', true );
			$( '#aouwal-cancel' ).prop( 'disabled', true );
			$.post( AOUWAL.ajaxUrl, { action: 'aouwal_pause', nonce: AOUWAL.nonce, job_id: jobId } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						fail( res );
						return;
					}
					log( AOUWAL.i18n.paused );
					showPaused( res.data.counts );
				} )
				.fail( function () {
					fail();
				} );
		} );

		$( '#aouwal-resume' ).on( 'click', function () {
			if ( ! jobId ) {
				return;
			}
			stopped = false;
			$( '#aouwal-resume' ).prop( 'disabled', true );
			$( '#aouwal-cancel' ).prop( 'disabled', true );
			phase( AOUWAL.i18n.resuming );
			$.post( AOUWAL.ajaxUrl, { action: 'aouwal_resume', nonce: AOUWAL.nonce, job_id: jobId } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						fail( res );
						return;
					}
					setCounts( res.data.counts );
					log( AOUWAL.i18n.resuming );
					setButtons( 'running' );
					runBatch();
				} )
				.fail( function () {
					fail();
				} );
		} );

		$( '#aouwal-cancel' ).on( 'click', function () {
			if ( ! jobId ) {
				return;
			}
			if ( ! window.confirm( AOUWAL.i18n.cancel_confirm ) ) {
				return;
			}
			// Stop this tab at once. The request below tells the server, which
			// removes the partial files and stops any batch still in flight.
			stopped = true;
			$( '#aouwal-cancel' ).prop( 'disabled', true );
			$( '#aouwal-pause' ).prop( 'disabled', true );
			phase( AOUWAL.i18n.cancelling );
			$.post( AOUWAL.ajaxUrl, { action: 'aouwal_cancel', nonce: AOUWAL.nonce, job_id: jobId } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						fail( res );
						return;
					}
					setCounts( res.data.counts );
					log( res.data.log );
					showCancelled( res.data.log );
				} )
				.fail( function () {
					$( '#aouwal-cancel' ).prop( 'disabled', false );
					phase( AOUWAL.i18n.cancelled );
					notice( AOUWAL.i18n.cancel_failed, 'notice-error' );
				} );
		} );

		$.post( AOUWAL.ajaxUrl, { action: 'aouwal_status', nonce: AOUWAL.nonce } )
			.done( function ( res ) {
				if ( res && res.success && res.data && res.data.job_id ) {
					attach( res.data );
				}
			} );
	} );
} )( jQuery );
