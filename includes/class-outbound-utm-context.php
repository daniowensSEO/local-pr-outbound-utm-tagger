<?php
/**
 * Works out what the placeholders mean on the page being rendered.
 *
 * Everything here describes the page, never the visitor, because a tagged page
 * is cached and served to everyone who asks for it.
 *
 * Placeholders are worked out only when a setting actually uses them, so a site
 * that never mentions {category} never pays for the term lookup it would need.
 */

defined( 'ABSPATH' ) || exit;

final class Outbound_UTM_Context {

	/** Placeholders this plugin understands. Anything else is left alone. */
	private static $known = array(
		'slug',
		'path',
		'post_id',
		'post_type',
		'category',
		'author',
		'parent',
		'year',
		'month',
		'domain',
		'link_text',
		'none',
	);

	/**
	 * Placeholder values already worked out for this request.
	 *
	 * @var array
	 */
	private static $values = array();

	/**
	 * Values taken straight off the queried object, worked out once.
	 *
	 * @var array|null
	 */
	private static $page = null;

	/** This site's domain without "www.". */
	public static function domain() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Fills the placeholders in one field and tidies the result.
	 *
	 * @param string $value     The value as typed in the settings.
	 * @param string $link_text Text of the link being tagged, for {link_text}.
	 * @param bool   $defer     Leave {link_text} in place for the browser to fill in.
	 * @return string
	 */
	public static function apply( $value, $link_text = '', $defer = false ) {
		$value = (string) $value;
		if ( '' === $value || false === strpos( $value, '{' ) ) {
			return self::finish( $value );
		}

		if ( ! $defer && false !== strpos( $value, '{link_text}' ) ) {
			$value = str_replace( '{link_text}', (string) $link_text, $value );
		}

		if ( false !== strpos( $value, '{term:' ) ) {
			$value = (string) preg_replace_callback(
				'/\{term:([A-Za-z0-9_-]+)\}/',
				function ( $matches ) {
					return self::primary_term_slug( $matches[1] );
				},
				$value
			);
		}

		$value = (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			function ( $matches ) use ( $defer ) {
				// In click mode the browser fills this one in, so it stays put.
				if ( $defer && 'link_text' === $matches[1] ) {
					return $matches[0];
				}
				// Anything this plugin does not know stays as it was typed.
				return in_array( $matches[1], self::$known, true ) ? self::token( $matches[1] ) : $matches[0];
			},
			$value
		);

		return self::finish( $value );
	}

	private static function finish( $value ) {
		$value = trim( $value );
		if ( '' !== $value && Outbound_UTM_Settings::tidy_enabled() ) {
			$value = self::tidy( $value );
		}
		return $value;
	}

	/**
	 * Lower case, spaces turned into hyphens, so Reviews and reviews are one campaign.
	 */
	private static function tidy( $value ) {
		$value = wp_strip_all_tags( $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = (string) preg_replace( '/\s+/u', '-', $value );
		$value = (string) preg_replace( '/-{2,}/', '-', $value );
		return trim( $value, '-' );
	}

	/**
	 * One placeholder's value, worked out on first use and kept for the rest of the request.
	 */
	private static function token( $name ) {
		if ( array_key_exists( $name, self::$values ) ) {
			return self::$values[ $name ];
		}

		$page  = self::page();
		$value = '';

		switch ( $name ) {
			case 'slug':
			case 'post_id':
			case 'post_type':
			case 'year':
			case 'month':
				$value = isset( $page[ $name ] ) ? $page[ $name ] : '';
				break;

			case 'path':
				$value = self::path();
				break;

			case 'domain':
				$value = self::domain();
				break;

			case 'category':
				$value = self::category();
				break;

			case 'author':
				$value = self::author();
				break;

			case 'parent':
				$value = self::parent_slug();
				break;
		}

		self::$values[ $name ] = $value;
		return $value;
	}

	/**
	 * Values that come straight off the queried object, with no extra database work.
	 */
	private static function page() {
		if ( null !== self::$page ) {
			return self::$page;
		}

		$page   = array(
			'slug'      => '',
			'post_id'   => '',
			'post_type' => '',
			'year'      => '',
			'month'     => '',
		);
		$object = get_queried_object();

		if ( is_singular() && $object instanceof WP_Post ) {
			$page['slug']      = rawurldecode( $object->post_name );
			$page['post_id']   = (string) $object->ID;
			$page['post_type'] = $object->post_type;
			$page['year']      = (string) get_post_time( 'Y', false, $object );
			$page['month']     = (string) get_post_time( 'm', false, $object );
		} elseif ( is_home() ) {
			// The posts page has a slug of its own. A front page of latest posts does not.
			$posts_page = (int) get_option( 'page_for_posts' );
			if ( $posts_page && ! is_front_page() ) {
				$page['slug']      = rawurldecode( (string) get_post_field( 'post_name', $posts_page ) );
				$page['post_id']   = (string) $posts_page;
				$page['post_type'] = 'page';
			}
		} elseif ( $object instanceof WP_Term ) {
			$page['slug'] = rawurldecode( $object->slug );
		} elseif ( $object instanceof WP_Post_Type ) {
			$page['slug']      = $object->name;
			$page['post_type'] = $object->name;
		} elseif ( $object instanceof WP_User ) {
			$page['slug'] = $object->user_nicename;
		}

		self::$page = $page;
		return $page;
	}

	/** The post's primary category, or the category page's own term. */
	private static function category() {
		$object = get_queried_object();
		if ( $object instanceof WP_Term && 'category' === $object->taxonomy ) {
			return rawurldecode( $object->slug );
		}
		return self::primary_term_slug( 'category' );
	}

	/** The author's name as it appears in URLs. */
	private static function author() {
		$object = get_queried_object();
		if ( $object instanceof WP_User ) {
			return $object->user_nicename;
		}
		if ( is_singular() && $object instanceof WP_Post ) {
			$author = get_userdata( (int) $object->post_author );
			if ( $author ) {
				return $author->user_nicename;
			}
		}
		return '';
	}

	/** The parent page's slug. */
	private static function parent_slug() {
		$object = get_queried_object();
		if ( is_singular() && $object instanceof WP_Post && $object->post_parent ) {
			$parent = get_post( $object->post_parent );
			if ( $parent instanceof WP_Post ) {
				return rawurldecode( $parent->post_name );
			}
		}
		return '';
	}

	/**
	 * The path part of the current URL, without the site's own folder, query, or slashes.
	 */
	private static function path() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$request = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path    = (string) wp_parse_url( $request, PHP_URL_PATH );
		$base    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( '' !== $base && '/' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}

		$path = '/' . ltrim( rawurldecode( $path ), '/' );
		$path = rtrim( $path, '/' );

		// The home page is everything after the domain too, which is just a slash.
		return '' === $path ? '/' : $path;
	}

	/**
	 * The primary term of a taxonomy for the current post, falling back to its first term.
	 *
	 * Yoast and Rank Math both store a primary term, so either one is used when present.
	 */
	private static function primary_term_slug( $taxonomy ) {
		// The queried object, not get_post(), because this runs after the template
		// has finished and the global post may belong to a secondary loop.
		$post = get_queried_object();
		if ( ! is_singular() || ! $post instanceof WP_Post || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		$keys = array( '_yoast_wpseo_primary_' . $taxonomy, 'rank_math_primary_' . $taxonomy );
		foreach ( $keys as $key ) {
			$term_id = (int) get_post_meta( $post->ID, $key, true );
			if ( $term_id ) {
				$term = get_term( $term_id, $taxonomy );
				if ( $term instanceof WP_Term ) {
					return rawurldecode( $term->slug );
				}
			}
		}

		$terms = get_the_terms( $post, $taxonomy );
		if ( is_array( $terms ) && $terms ) {
			$first = reset( $terms );
			if ( $first instanceof WP_Term ) {
				return rawurldecode( $first->slug );
			}
		}

		return '';
	}
}
