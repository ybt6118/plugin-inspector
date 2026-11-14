<?php
/**
 * Scans saved content (Elementor data, blocks, shortcodes) for elements.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PEI_Scanner {

	const OPTION = 'pei_scan_results';

	public static function post_types() {
		$types = get_post_types( array( 'show_ui' => true ), 'names' );
		$skip  = array( 'attachment', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_order_placehold', 'shop_subscription', 'wp_navigation' );
		foreach ( $skip as $s ) {
			unset( $types[ $s ] );
		}
		if ( post_type_exists( 'elementor_library' ) ) {
			$types['elementor_library'] = 'elementor_library';
		}
		return array_values( apply_filters( 'pei_scan_post_types', $types ) );
	}

	public static function statuses() {
		return apply_filters( 'pei_scan_statuses', array( 'publish', 'private', 'future', 'pending', 'draft' ) );
	}

	public static function get_ids() {
		return get_posts(
			array(
				'post_type'        => self::post_types(),
				'post_status'      => self::statuses(),
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		);
	}

	/**
	 * Returns [ "kind|name" => count ] for one post.
	 */
	public static function scan_post( $post_id ) {
		$found = array();
		$post  = get_post( $post_id );
		if ( ! $post ) {
			return $found;
		}

		$is_elementor = 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );

		if ( $is_elementor ) {
			$data = get_post_meta( $post_id, '_elementor_data', true );
			if ( is_string( $data ) ) {
				$data = json_decode( $data, true );
			}
			if ( is_array( $data ) ) {
				self::walk_elementor( $data, $found );
			}
		} elseif ( '' !== $post->post_content ) {
			// For Elementor pages post_content is only a plain fallback copy, so it is skipped.
			if ( function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {
				self::walk_blocks( parse_blocks( $post->post_content ), $found );
			}
			self::find_shortcodes( $post->post_content, $found );
		}

		return $found;
	}

	private static function add( &$found, $kind, $name ) {
		$key           = $kind . '|' . $name;
		$found[ $key ] = isset( $found[ $key ] ) ? $found[ $key ] + 1 : 1;
	}

	private static function walk_elementor( $elements, &$found ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['elType'] ) && 'widget' === $el['elType'] && ! empty( $el['widgetType'] ) ) {
				self::add( $found, 'elementor', $el['widgetType'] );

				// Shortcodes typed into widget settings (Shortcode widget, Text Editor, etc).
				if ( ! empty( $el['settings'] ) && is_array( $el['settings'] ) ) {
					$strings = array();
					array_walk_recursive(
						$el['settings'],
						function ( $v ) use ( &$strings ) {
							if ( is_string( $v ) && false !== strpos( $v, '[' ) ) {
								$strings[] = $v;
							}
						}
					);
					if ( $strings ) {
						self::find_shortcodes( implode( "\n", $strings ), $found );
					}
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk_elementor( $el['elements'], $found );
			}
		}
	}

	private static function walk_blocks( $blocks, &$found ) {
		foreach ( $blocks as $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				self::add( $found, 'block', $b['blockName'] );
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				self::walk_blocks( $b['innerBlocks'], $found );
			}
		}
	}

	private static function find_shortcodes( $text, &$found ) {
		if ( false === strpos( $text, '[' ) ) {
			return;
		}
		global $shortcode_tags;
		if ( ! preg_match_all( '/\[([a-zA-Z][\w\-]*)(\s[^\]]*)?\]/', $text, $matches, PREG_SET_ORDER ) ) {
			return;
		}
		foreach ( $matches as $m ) {
			$tag = $m[1];
			if ( isset( $shortcode_tags[ $tag ] ) ) {
				self::add( $found, 'shortcode', $tag );
				continue;
			}
			// Not registered: only count it if it clearly is a shortcode (left behind by a removed plugin).
			$has_attr  = ! empty( $m[2] ) && false !== strpos( $m[2], '=' );
			$has_close = false !== strpos( $text, '[/' . $tag . ']' );
			if ( $has_attr || $has_close ) {
				self::add( $found, 'shortcode', $tag );
			}
		}
	}
}
