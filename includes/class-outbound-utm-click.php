<?php
/**
 * JavaScript mode, chosen on the settings screen.
 *
 * The page keeps its links exactly as written. PHP works out the values for this
 * page and hands them to a small script, which adds them to a link at the moment
 * someone activates it, so anything that only reads the page sees a clean link.
 */

defined( 'ABSPATH' ) || exit;

final class Outbound_UTM_Click {

	const HANDLE = 'outbound-utm-click';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( is_feed() || is_embed() ) {
			return;
		}

		$settings = Outbound_UTM_Settings::get();
		if ( empty( $settings['enabled'] ) || 'click' !== $settings['mode'] ) {
			return;
		}

		$params = array();
		foreach ( Outbound_UTM_Settings::fields() as $field ) {
			$value = isset( $settings[ $field ] ) ? (string) $settings[ $field ] : '';
			if ( '' === $value ) {
				continue;
			}
			// {link_text} is left in place, since only the browser knows which link was clicked.
			$resolved = Outbound_UTM_Context::apply( $value, '', true );
			if ( '' !== $resolved ) {
				$params[ $field ] = $resolved;
			}
		}

		if ( ! $params ) {
			return;
		}

		$config = array(
			'params'    => $params,
			'internal'  => Outbound_UTM_Tagger::internal_hosts(),
			'exclude'   => array_values( (array) $settings['skip'] ),
			'skipClass' => Outbound_UTM_Tagger::SKIP_CLASS,
			'tidy'      => empty( $settings['tidy'] ) ? 0 : 1,
		);

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'assets/outbound-utm-click.js', OUTBOUND_UTM_FILE ),
			array(),
			Outbound_UTM_Settings::asset_version(),
			true
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.outboundUtmConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
