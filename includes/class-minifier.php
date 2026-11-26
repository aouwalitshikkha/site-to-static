<?php
/**
 * HTML minification.
 *
 * Conservative: only whitespace between tags and non-conditional comments are
 * removed. Text nodes, inline spacing and protected blocks are left untouched,
 * so the rendered result is identical to the unminified page.
 *
 * @package Aouwal_Static_Export
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class AOUWAL_Minifier
 */
class AOUWAL_Minifier {

	/**
	 * Remove comments and whitespace-only gaps between tags.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function html( $html ) {
		$html = (string) $html;
		if ( '' === $html ) {
			return $html;
		}

		$protected = array();
		$html      = (string) preg_replace_callback(
			'~<(pre|textarea|script|style)\b[^>]*>.*?</\1\s*>~is',
			function ( $matches ) use ( &$protected ) {
				$key               = "\x1A" . count( $protected ) . "\x1A";
				$protected[ $key ] = $matches[0];
				return $key;
			},
			$html
		);

		// Keep conditional comments and the opt-in AOUWAL credit. Everything
		// else is a normal HTML comment and safe to drop.
		$html = (string) preg_replace_callback(
			'~<!--.*?-->~s',
			function ( $matches ) {
				if ( preg_match( '~\[if|\[endif|Aouwal|Abdul Aouwal~i', $matches[0] ) ) {
					return $matches[0];
				}
				return '';
			},
			$html
		);

		$html = (string) preg_replace( '~>\s+<~', '><', $html );
		$html = trim( $html );

		if ( ! empty( $protected ) ) {
			$html = strtr( $html, $protected );
		}

		return $html;
	}
}
