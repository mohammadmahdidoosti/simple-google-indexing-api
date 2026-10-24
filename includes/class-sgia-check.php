<?php
/**
 * Pre-send checks: don't spend quota on URLs Google will not index anyway.
 *
 * Internal checks read WordPress / Rank Math / Yoast / WooCommerce data (free).
 * The HTTP check fetches the page itself and only rejects on clear signals
 * (404/410, redirect, noindex, canonical elsewhere). Anything ambiguous —
 * timeouts, 403/429/5xx from a CDN or firewall — lets the URL through.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SGIA_Check {

	const UA = 'Mozilla/5.0 (compatible; SGIA-PreCheck/2.2)';

	private static function pass( $code = 0, $unknown = false ) {
		return [ 'ok' => true, 'reason' => '', 'code' => $code, 'unknown' => $unknown ];
	}

	private static function reject( $reason, $code = 0 ) {
		return [ 'ok' => false, 'reason' => $reason, 'code' => $code, 'unknown' => false ];
	}

	/** Full check according to settings. */
	public static function run( $url, $post_id = 0 ) {
		$s = SGIA_API::settings();
		if ( ! $s['precheck'] ) {
			return self::pass();
		}
		$r = self::internal( $url, $post_id );
		if ( ! $r['ok'] || ! $s['precheck_http'] ) {
			return $r;
		}
		return self::http( $url );
	}

	public static function internal( $url, $post_id = 0 ) {
		$p = $post_id ? get_post( $post_id ) : null;
		if ( ! $p ) {
			return self::pass();
		}

		if ( 'publish' !== $p->post_status ) {
			return self::reject( 'منتشر نشده (' . $p->post_status . ')' );
		}
		if ( '' !== $p->post_password ) {
			return self::reject( 'صفحه رمزدار است' );
		}

		$rm = get_post_meta( $p->ID, 'rank_math_robots', true );
		if ( is_array( $rm ) && in_array( 'noindex', $rm, true ) ) {
			return self::reject( 'noindex در Rank Math' );
		}
		if ( '1' === (string) get_post_meta( $p->ID, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return self::reject( 'noindex در Yoast' );
		}

		foreach ( [ 'rank_math_canonical_url', '_yoast_wpseo_canonical' ] as $key ) {
			$canon = trim( (string) get_post_meta( $p->ID, $key, true ) );
			if ( $canon && ! self::same( $canon, $url ) ) {
				return self::reject( 'canonical به آدرس دیگری اشاره می‌کند: ' . rawurldecode( $canon ) );
			}
		}

		if ( SGIA_API::settings()['woo_skip_oos'] && 'product' === $p->post_type && 'outofstock' === get_post_meta( $p->ID, '_stock_status', true ) ) {
			return self::reject( 'محصول ناموجود' );
		}

		return self::pass();
	}

	public static function http( $url ) {
		$res = wp_remote_get( $url, [
			'timeout'             => 10,
			'redirection'         => 0,
			'user-agent'          => self::UA,
			'limit_response_size' => 400000,
			'headers'             => [ 'Accept' => 'text/html' ],
		] );
		if ( is_wp_error( $res ) ) {
			return self::pass( 0, true );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );

		if ( in_array( $code, [ 404, 410 ], true ) ) {
			return self::reject( 'صفحه وجود ندارد (' . $code . ')', $code );
		}

		if ( $code >= 300 && $code < 400 ) {
			$loc = (string) wp_remote_retrieve_header( $res, 'location' );
			if ( $loc && 0 === strpos( $loc, '/' ) ) {
				$loc = untrailingslashit( preg_replace( '~^(https?://[^/]+).*$~i', '$1', $url ) ) . $loc;
			}
			if ( $loc && ! self::same( $loc, $url ) ) {
				return self::reject( 'ریدایرکت به ' . rawurldecode( $loc ), $code );
			}
			return self::pass( $code, true );
		}

		if ( 200 !== $code ) {
			return self::pass( $code, true ); // 403/429/5xx: CDN/firewall/temporary — don't block.
		}

		$xrt = strtolower( implode( ',', (array) wp_remote_retrieve_header( $res, 'x-robots-tag' ) ) );
		if ( false !== strpos( $xrt, 'noindex' ) ) {
			return self::reject( 'noindex در هدر X-Robots-Tag', $code );
		}

		$html = (string) wp_remote_retrieve_body( $res );
		$head = ( false !== ( $pos = stripos( $html, '</head>' ) ) ) ? substr( $html, 0, $pos ) : $html;

		if ( preg_match_all( '/<meta\b[^>]*>/i', $head, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$name = strtolower( self::attr( $tag, 'name' ) );
				if ( in_array( $name, [ 'robots', 'googlebot' ], true ) && false !== stripos( self::attr( $tag, 'content' ), 'noindex' ) ) {
					return self::reject( 'تگ meta robots صفحه noindex است', $code );
				}
			}
		}

		if ( preg_match_all( '/<link\b[^>]*>/i', $head, $m ) ) {
			foreach ( $m[0] as $tag ) {
				if ( preg_match( '/(^|\s)canonical(\s|$)/i', self::attr( $tag, 'rel' ) ) ) {
					$href = html_entity_decode( self::attr( $tag, 'href' ), ENT_QUOTES, 'UTF-8' );
					if ( $href && ! self::same( $href, $url ) ) {
						return self::reject( 'canonical صفحه: ' . rawurldecode( $href ), $code );
					}
					break;
				}
			}
		}

		return self::pass( $code );
	}

	private static function attr( $tag, $name ) {
		return preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $tag, $m ) ? trim( $m[2] ) : '';
	}

	/** Same page? Ignores case of scheme/host, percent-encoding, trailing slash, fragment. */
	public static function same( $a, $b ) {
		return self::norm( $a ) === self::norm( $b );
	}

	private static function norm( $u ) {
		$u = trim( html_entity_decode( (string) $u, ENT_QUOTES, 'UTF-8' ) );
		$u = preg_replace( '/#.*$/', '', $u );
		if ( preg_match( '~^(https?)://([^/?]+)(.*)$~i', $u, $m ) ) {
			$host = preg_replace( '/^www\./', '', strtolower( $m[2] ) );
			$u    = strtolower( $m[1] ) . '://' . $host . rawurldecode( $m[3] );
		}
		$parts = explode( '?', $u, 2 );
		$parts[0] = untrailingslashit( $parts[0] );
		return implode( '?', $parts );
	}
}
