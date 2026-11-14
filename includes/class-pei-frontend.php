<?php
/**
 * Front-end "Inspect Plugins" mode (admins only, only when ?pei_inspect=1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PEI_Frontend {

	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
		add_action( 'wp', array( __CLASS__, 'maybe_activate' ) );
	}

	private static function allowed() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	private static function active() {
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only view toggle.
		return ! is_admin() && isset( $_GET['pei_inspect'] ) && self::allowed();
	}

	public static function admin_bar( $bar ) {
		if ( ! self::allowed() ) {
			return;
		}
		if ( is_admin() ) {
			$bar->add_node( array( 'id' => 'pei', 'title' => 'Smart Plugin Inspector', 'href' => admin_url( 'tools.php?page=' . PEI_Admin::SLUG ) ) );
			return;
		}
		$scheme = is_ssl() ? 'https://' : 'http://';
		$url    = $scheme . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' );
		$bar->add_node(
			array(
				'id'    => 'pei',
				'title' => self::active() ? 'Stop Inspecting' : 'Inspect Plugins',
				'href'  => self::active() ? remove_query_arg( 'pei_inspect', $url ) : add_query_arg( 'pei_inspect', '1', $url ),
			)
		);
	}

	public static function maybe_activate() {
		if ( ! self::active() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'wrap_shortcode' ), PHP_INT_MAX, 2 );
		add_filter( 'render_block', array( __CLASS__, 'wrap_block' ), PHP_INT_MAX, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'print_assets' ), 1000 );
	}

	private static function marker( $kind, $name, $src, $html ) {
		// Only wrap real HTML output. Plain-text output may live inside an HTML attribute.
		if ( ! is_string( $html ) || false === strpos( $html, '<' ) ) {
			return $html;
		}
		$json = wp_json_encode( array( 'k' => $kind, 'n' => $name, 'p' => $src['name'], 's' => $src['key'] ) );
		$json = str_replace( '--', '--', $json );
		return '<!--pei-start ' . $json . '-->' . $html . '<!--pei-end-->';
	}

	public static function wrap_shortcode( $output, $tag ) {
		return self::marker( 'Shortcode', '[' . $tag . ']', PEI_Resolver::resolve( 'shortcode', $tag ), $output );
	}

	public static function wrap_block( $content, $block ) {
		if ( empty( $block['blockName'] ) || 0 === strpos( $block['blockName'], 'core/' ) ) {
			return $content;
		}
		return self::marker( 'Block', $block['blockName'], PEI_Resolver::block_source( $block['blockName'] ), $content );
	}

	public static function enqueue() {
		wp_enqueue_style( 'pei-inspector', PEI_URL . 'assets/inspector.css', array(), PEI_VERSION );
		wp_enqueue_script( 'pei-inspector', PEI_URL . 'assets/inspector.js', array(), PEI_VERSION, true );

		$widgets = array();
		foreach ( PEI_Resolver::elementor_widget_map() as $name => $src ) {
			$widgets[ $name ] = array( 'p' => $src['name'], 'k' => $src['key'], 't' => $src['title'] );
		}
		wp_localize_script(
			'pei-inspector',
			'PEI_DATA',
			array(
				'widgets'  => $widgets,
				'report'   => admin_url( 'tools.php?page=' . PEI_Admin::SLUG ),
				'exitUrl'  => remove_query_arg( 'pei_inspect' ),
			)
		);
	}

	/** Prints every script/stylesheet that loaded on this page, with its owning plugin. */
	public static function print_assets() {
		$out = array( 'scripts' => array(), 'styles' => array() );
		foreach ( array( 'scripts' => wp_scripts(), 'styles' => wp_styles() ) as $type => $deps ) {
			foreach ( (array) $deps->done as $handle ) {
				if ( 0 === strpos( $handle, 'pei-' ) || empty( $deps->registered[ $handle ] ) || ! $deps->registered[ $handle ]->src ) {
					continue;
				}
				$src            = PEI_Resolver::from_src( $deps->registered[ $handle ]->src );
				$out[ $type ][] = array( 'h' => $handle, 'p' => $src['name'], 'k' => $src['key'] );
			}
		}
		echo '<script id="pei-assets">window.PEI_ASSETS=' . wp_json_encode( $out ) . ';</script>';
	}
}
