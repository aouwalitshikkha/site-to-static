/* global jQuery, AOUWAL_DEPLOY */
( function ( $ ) {
	'use strict';

	var cfg = window.AOUWAL_DEPLOY || {};
	var i18n = cfg.i18n || {};

	function show( text, ok ) {
		var $box = $( '#aouwal-deploy-result' );
		$box.empty();
		if ( ! text ) {
			return;
		}
		$( '<div>' )
			.addClass( 'notice inline ' + ( ok ? 'notice-success' : 'notice-error' ) )
			.append( $( '<p>' ).text( text ) )
			.appendTo( $box );
	}

	function showResults( results ) {
		var lines = [];
		var ok = true;
		( results || [] ).forEach( function ( row ) {
			if ( row && row.message ) {
				lines.push( row.message );
			}
			if ( row && ! row.ok ) {
				ok = false;
			}
		} );
		show( lines.join( ' ' ), ok );
	}

	function busy( busyState ) {
		$( '#aouwal-deploy-test, #aouwal-deploy-run' ).prop( 'disabled', busyState );
		$( '#aouwal-deploy-spinner' ).toggleClass( 'is-active', busyState );
	}

	$( function () {
		function bindToggle( toggleId, fieldsId ) {
			var $toggle = $( '#' + toggleId );
			var $fields = $( '#' + fieldsId );
			if ( ! $toggle.length || ! $fields.length ) {
				return;
			}
			function sync() {
				if ( $toggle.is( ':checked' ) ) {
					$fields.removeAttr( 'hidden' );
				} else {
					$fields.attr( 'hidden', 'hidden' );
				}
			}
			$toggle.on( 'change', sync );
			sync();
		}

		bindToggle( 'aouwal_github_enabled', 'aouwal-github-fields' );
		bindToggle( 'aouwal_cloudflare_enabled', 'aouwal-cloudflare-fields' );
		bindToggle( 'aouwal_netlify_enabled', 'aouwal-netlify-fields' );

		$( '#aouwal-deploy-test' ).on( 'click', function () {
			busy( true );
			show( i18n.testing || 'Testing connection…', true );
			$.post( cfg.ajaxUrl, {
				action: 'aouwal_deploy_test',
				nonce: cfg.nonce
			} ).done( function ( response ) {
				if ( response && response.success && response.data && response.data.results ) {
					showResults( response.data.results );
				} else {
					show( ( response && response.data && response.data.message ) || i18n.failed || 'Failed.', false );
				}
			} ).fail( function () {
				show( i18n.error || 'The request did not reach the server.', false );
			} ).always( function () {
				busy( false );
			} );
		} );

		$( '#aouwal-deploy-run' ).on( 'click', function () {
			var jobId = $( this ).attr( 'data-job' );
			if ( ! jobId || ! window.confirm( i18n.confirm || 'Deploy now?' ) ) {
				return;
			}
			busy( true );
			show( i18n.deploying || 'Deploying…', true );
			$.post( cfg.ajaxUrl, {
				action: 'aouwal_deploy_run',
				nonce: cfg.nonce,
				job_id: jobId
			} ).done( function ( response ) {
				if ( response && response.success && response.data ) {
					showResults( response.data.results );
				} else {
					show( ( response && response.data && response.data.message ) || i18n.failed || 'Failed.', false );
				}
			} ).fail( function () {
				show( i18n.error || 'The request did not reach the server.', false );
			} ).always( function () {
				busy( false );
			} );
		} );
	} );
}( jQuery ) );
