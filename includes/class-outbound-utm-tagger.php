<?php
/**
 * Adds the UTMs to external links in the finished page.
 *
 * WordPress 6.9 hands plugins the whole HTML response through
 * wp_template_enhancement_output_buffer, and the HTML API walks the tags
 * safely, so nothing here uses a regular expression on the page.
 */

defined( 'ABSPATH' ) || exit;

final class Outbound_UTM_Tagger {

	const SKIP_CLASS = 'noutm';

	/** Elements that hold nothing, so they never open a skipped region. */
	private static $void_tags = array(
		'AREA',
		'BASE',
		'BR',
		'COL',
		'EMBED',
		'HR',
		'IMG',
		'INPUT',
		'LINK',
		'META',
		'SOURCE',
		'TRACK',
		'WBR',
	);

	/** Gives up on a skipped region after this many tags, in case its closing tag is missing. */
	const SKIP_LIMIT = 500;

	public static function init() {
		// Registered early: WordPress only buffers the page when this filter already exists.
		add_filter( 'wp_template_enhancement_output_buffer', array( __CLASS__, 'filter_html' ), 10, 1 );
	}

	/**
	 * Rewrites the page. Any failure returns the page exactly as it came in.
	 *
	 * @param string $html The finished page.
	 * @return string
	 */
	public static function filter_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		try {
			$settings = Outbound_UTM_Settings::get();
			if ( empty( $settings['enabled'] ) ) {
				return $html;
			}

			$raw = array();
			foreach ( Outbound_UTM_Settings::fields() as $field ) {
				$value = isset( $settings[ $field ] ) ? (string) $settings[ $field ] : '';
				if ( '' !== $value ) {
					$raw[ $field ] = $value;
				}
			}
			if ( ! $raw ) {
				return $html;
			}

			// {link_text} is the only placeholder that changes from link to link, so
			// fields using it are the only ones worked out more than once per page.
			$fixed    = array();
			$per_link = array();
			foreach ( $raw as $field => $value ) {
				if ( false !== strpos( $value, '{link_text}' ) ) {
					$per_link[ $field ] = $value;
				} else {
					$fixed[ $field ] = $value;
				}
			}

			$needs_text     = ! empty( $per_link );
			$fixed_resolved = self::resolve( $fixed, '' );

			$shared_query = '';
			if ( ! $needs_text ) {
				$shared_query = self::build_query( $fixed_resolved );
				if ( '' === $shared_query ) {
					return $html;
				}
			}

			$link_texts = $needs_text ? self::collect_link_texts( $html ) : array();
			$internal   = self::internal_hosts();
			$skip_hosts = isset( $settings['skip'] ) ? (array) $settings['skip'] : array();

			$processor  = new WP_HTML_Tag_Processor( $html );
			$link_index = -1;
			$skip_tag   = '';
			$skip_depth = 0;
			$skip_left  = 0;

			while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
				$tag       = (string) $processor->get_tag();
				$is_closer = $processor->is_tag_closer();

				if ( '' !== $skip_tag ) {
					--$skip_left;
					if ( $tag === $skip_tag ) {
						$skip_depth += $is_closer ? -1 : 1;
					}
					if ( $skip_depth < 1 || $skip_left < 1 ) {
						$skip_tag   = '';
						$skip_depth = 0;
					}
				} elseif ( ! $is_closer && ! in_array( $tag, self::$void_tags, true ) && ! $processor->has_self_closing_flag() && true === $processor->has_class( self::SKIP_CLASS ) ) {
					$skip_tag   = $tag;
					$skip_depth = 1;
					$skip_left  = self::SKIP_LIMIT;
				}

				if ( 'A' !== $tag || $is_closer ) {
					continue;
				}

				// Counted for every link so the text collected earlier lines up.
				++$link_index;

				// Anchors inside an SVG or MathML drawing are not page links, so they are left alone.
				if ( 'html' !== $processor->get_namespace() ) {
					continue;
				}

				if ( '' !== $skip_tag ) {
					continue;
				}

				$href = $processor->get_attribute( 'href' );
				if ( ! is_string( $href ) ) {
					continue;
				}

				$query = $shared_query;
				if ( $needs_text ) {
					$text  = isset( $link_texts[ $link_index ] ) ? $link_texts[ $link_index ] : '';
					$query = self::build_query( self::merge( $fixed_resolved, $per_link, $text ) );
				}
				if ( '' === $query ) {
					continue;
				}

				$tagged = self::tag_href( $href, $query, $internal, $skip_hosts );
				if ( null !== $tagged ) {
					$processor->set_attribute( 'href', $tagged );
				}
			}

			return $processor->get_updated_html();
		} catch ( Throwable $error ) {
			return $html;
		}
	}

	/**
	 * Reads the text of every link, keyed by the order the links appear.
	 * Only used when a field contains {link_text}.
	 */
	private static function collect_link_texts( $html ) {
		$texts     = array();
		$open      = array();
		$index     = -1;
		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			if ( '#tag' === $type ) {
				if ( 'A' !== (string) $processor->get_tag() ) {
					continue;
				}
				if ( $processor->is_tag_closer() ) {
					if ( $open ) {
						array_pop( $open );
					}
				} else {
					++$index;
					$texts[ $index ] = '';
					$open[]          = $index;
				}
				continue;
			}

			if ( '#text' === $type && $open ) {
				$current           = end( $open );
				$texts[ $current ] .= $processor->get_modifiable_text();
			}
		}

		foreach ( $texts as $key => $text ) {
			$texts[ $key ] = self::clean_link_text( $text );
		}

		return $texts;
	}

	/** Collapses whitespace and keeps link text short enough to be a usable value. */
	private static function clean_link_text( $text ) {
		// Lengths and positions are counted in characters (code points), exactly as the
		// JavaScript mode counts them, so both modes cut long link text at the same place.
		$text = trim( (string) preg_replace( Outbound_UTM_Context::SPACE, ' ', (string) $text ) );
		if ( mb_strlen( $text ) <= 60 ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, 60 );
		$gap = mb_strrpos( $cut, ' ' );
		return ( false !== $gap && $gap > 20 ) ? mb_substr( $cut, 0, $gap ) : $cut;
	}

	/** Fills the placeholders in every field. */
	private static function resolve( array $raw, $link_text ) {
		$resolved = array();
		foreach ( $raw as $field => $value ) {
			$resolved[ $field ] = Outbound_UTM_Context::apply( $value, $link_text );
		}
		return $resolved;
	}

	/**
	 * Combines the values that stay the same all page with the ones that depend on this
	 * link's text, keeping the fields in their usual order.
	 */
	private static function merge( array $fixed, array $per_link, $link_text ) {
		$params = array();
		foreach ( Outbound_UTM_Settings::fields() as $field ) {
			if ( isset( $per_link[ $field ] ) ) {
				$params[ $field ] = Outbound_UTM_Context::apply( $per_link[ $field ], $link_text );
			} elseif ( isset( $fixed[ $field ] ) ) {
				$params[ $field ] = $fixed[ $field ];
			}
		}
		return $params;
	}

	private static function build_query( array $params ) {
		$parts = array();
		foreach ( $params as $key => $value ) {
			if ( '' === $value ) {
				continue;
			}
			// A slash is allowed inside a link's parameters, so it is left readable
			// rather than written as %2F, which matters for {path}.
			$parts[] = rawurlencode( $key ) . '=' . str_replace( '%2F', '/', rawurlencode( $value ) );
		}
		return implode( '&', $parts );
	}

	/** This site's own hostnames, which are never tagged. */
	public static function internal_hosts() {
		$hosts = array( Outbound_UTM_Context::domain() );

		$site = strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) );
		if ( 0 === strpos( $site, 'www.' ) ) {
			$site = substr( $site, 4 );
		}
		$hosts[] = $site;

		return array_values( array_unique( array_filter( $hosts ) ) );
	}

	/** True when the host is one of the domains, or a subdomain of one. */
	private static function host_matches( $host, array $domains ) {
		foreach ( $domains as $domain ) {
			// Normalized here as well as on save, so entries stored before this existed match too.
			$domain = Outbound_UTM_Context::ascii_host( $domain );
			if ( '' === $domain ) {
				continue;
			}
			if ( $host === $domain || substr( $host, - ( strlen( $domain ) + 1 ) ) === '.' . $domain ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the tagged link, or null when this link should be left alone.
	 */
	private static function tag_href( $href, $query, array $internal, array $skip_hosts ) {
		$href = trim( $href );
		if ( '' === $href || '#' === substr( $href, 0, 1 ) ) {
			return null;
		}

		$scheme = strtolower( (string) wp_parse_url( $href, PHP_URL_SCHEME ) );

		// Protocol-relative links (//example.com/page) point at another site and take on
		// the page's own scheme, so they are treated as external and tagged, matching the
		// JavaScript mode. Email, phone, and true relative paths are still left alone.
		$protocol_relative = ( '' === $scheme && 0 === strpos( $href, '//' ) );
		if ( ! $protocol_relative && 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}

		$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return null;
		}
		$host = Outbound_UTM_Context::ascii_host( rtrim( $host, '.' ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		if ( self::host_matches( $host, $internal ) || self::host_matches( $host, $skip_hosts ) ) {
			return null;
		}

		$existing = (string) wp_parse_url( $href, PHP_URL_QUERY );
		if ( '' !== $existing && preg_match( '/(^|&)utm_[A-Za-z0-9_]+=/i', $existing ) ) {
			return null;
		}

		return self::append_query( $href, $query );
	}

	/** Adds the parameters before any #fragment and keeps the rest of the link as written. */
	private static function append_query( $href, $query ) {
		$fragment = '';
		$hash     = strpos( $href, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $href, $hash );
			$href     = substr( $href, 0, $hash );
		}

		if ( false === strpos( $href, '?' ) ) {
			$separator = '?';
		} elseif ( preg_match( '/[?&]$/', $href ) ) {
			$separator = '';
		} else {
			$separator = '&';
		}

		return $href . $separator . $query . $fragment;
	}
}
