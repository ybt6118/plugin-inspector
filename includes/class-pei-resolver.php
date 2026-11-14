<?php
/**
 * Works out which plugin / theme / core file is responsible for a widget,
 * shortcode, block or asset.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PEI_Resolver {

	private static $plugins    = null;
	private static $file_cache = array();
	private static $widget_map = null;
	private static $block_cache = array();

	/* ------------------------------------------------------------------ *
	 * Basic source records
	 * ------------------------------------------------------------------ */

	public static function core() {
		return array( 'key' => 'wp-core', 'name' => 'WordPress Core', 'type' => 'core' );
	}

	public static function unknown( $label = '' ) {
		return array(
			'key'  => 'unknown',
			'name' => $label ? $label : 'Not found (plugin inactive, deleted, or element disabled)',
			'type' => 'unknown',
		);
	}

	public static function plugins() {
		if ( null === self::$plugins ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			self::$plugins = get_plugins();
		}
		return self::$plugins;
	}

	/** Find an installed plugin by its folder (or single-file) slug. */
	public static function find_plugin( $slug ) {
		foreach ( self::plugins() as $basename => $data ) {
			if ( $basename === $slug || 0 === strpos( $basename, $slug . '/' ) ) {
				return array( 'key' => $basename, 'name' => $data['Name'], 'type' => 'plugin' );
			}
		}
		return null;
	}

	public static function plugin_by_slug( $slug ) {
		$found = self::find_plugin( $slug );
		return $found ? $found : array( 'key' => $slug, 'name' => $slug, 'type' => 'plugin' );
	}

	/* ------------------------------------------------------------------ *
	 * Resolve from PHP file / callback / object
	 * ------------------------------------------------------------------ */

	public static function from_file( $file ) {
		if ( ! $file ) {
			return self::unknown();
		}
		$file = wp_normalize_path( $file );
		if ( isset( self::$file_cache[ $file ] ) ) {
			return self::$file_cache[ $file ];
		}

		$plugin_dir = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$mu_dir     = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );
		$theme_root = trailingslashit( wp_normalize_path( get_theme_root() ) );
		$abs        = trailingslashit( wp_normalize_path( ABSPATH ) );

		$result = self::unknown( 'Unidentified source' );

		if ( 0 === strpos( $file, $plugin_dir ) ) {
			$slug   = strtok( substr( $file, strlen( $plugin_dir ) ), '/' );
			$result = self::plugin_by_slug( $slug );
		} elseif ( 0 === strpos( $file, $mu_dir ) ) {
			$slug   = strtok( substr( $file, strlen( $mu_dir ) ), '/' );
			$result = array( 'key' => 'mu:' . $slug, 'name' => 'Must-use plugin: ' . $slug, 'type' => 'mu-plugin' );
		} elseif ( 0 === strpos( $file, $theme_root ) ) {
			$slug   = strtok( substr( $file, strlen( $theme_root ) ), '/' );
			$theme  = wp_get_theme( $slug );
			$result = array(
				'key'  => 'theme:' . $slug,
				'name' => 'Theme: ' . ( $theme->exists() ? $theme->get( 'Name' ) : $slug ),
				'type' => 'theme',
			);
		} elseif ( 0 === strpos( $file, $abs . 'wp-includes/' ) || 0 === strpos( $file, $abs . 'wp-admin/' ) ) {
			$result = self::core();
		}

		self::$file_cache[ $file ] = $result;
		return $result;
	}

	public static function from_object( $obj ) {
		try {
			$ref = new ReflectionClass( $obj );
			return self::from_file( $ref->getFileName() );
		} catch ( \Throwable $e ) {
			return self::unknown( 'Unidentified source' );
		}
	}

	public static function from_callback( $cb ) {
		try {
			if ( is_string( $cb ) && false !== strpos( $cb, '::' ) ) {
				$cb = explode( '::', $cb, 2 );
			}
			if ( is_array( $cb ) && 2 === count( $cb ) ) {
				// Use the class of the object/class, not the parent that declares the method.
				$ref = new ReflectionClass( $cb[0] );
				return self::from_file( $ref->getFileName() );
			}
			if ( $cb instanceof Closure || is_string( $cb ) ) {
				$ref = new ReflectionFunction( $cb );
				return self::from_file( $ref->getFileName() );
			}
			if ( is_object( $cb ) ) {
				return self::from_object( $cb );
			}
		} catch ( \Throwable $e ) {
			// fall through.
		}
		return self::unknown( 'Unidentified source' );
	}

	/* ------------------------------------------------------------------ *
	 * Resolve from a script / stylesheet URL
	 * ------------------------------------------------------------------ */

	public static function from_src( $src ) {
		$path = wp_parse_url( $src, PHP_URL_PATH );
		if ( ! $path ) {
			return self::unknown( 'Unidentified source' );
		}
		$host = wp_parse_url( $src, PHP_URL_HOST );

		$plugins_path = trailingslashit( (string) wp_parse_url( plugins_url(), PHP_URL_PATH ) );
		$mu_path      = trailingslashit( (string) wp_parse_url( WPMU_PLUGIN_URL, PHP_URL_PATH ) );
		$themes_path  = trailingslashit( (string) wp_parse_url( get_theme_root_uri(), PHP_URL_PATH ) );

		// Path checks first so CDN-rewritten URLs are still recognised.
		if ( 0 === strpos( $path, $plugins_path ) ) {
			return self::plugin_by_slug( strtok( substr( $path, strlen( $plugins_path ) ), '/' ) );
		}
		if ( 0 === strpos( $path, $mu_path ) ) {
			$slug = strtok( substr( $path, strlen( $mu_path ) ), '/' );
			return array( 'key' => 'mu:' . $slug, 'name' => 'Must-use plugin: ' . $slug, 'type' => 'mu-plugin' );
		}
		if ( 0 === strpos( $path, $themes_path ) ) {
			$slug  = strtok( substr( $path, strlen( $themes_path ) ), '/' );
			$theme = wp_get_theme( $slug );
			return array(
				'key'  => 'theme:' . $slug,
				'name' => 'Theme: ' . ( $theme->exists() ? $theme->get( 'Name' ) : $slug ),
				'type' => 'theme',
			);
		}
		if ( false !== strpos( $path, '/wp-includes/' ) || false !== strpos( $path, '/wp-admin/' ) ) {
			return self::core();
		}
		// Files generated into uploads, e.g. /uploads/elementor/css/post-12.css.
		if ( preg_match( '#/uploads/([^/]+)/#', $path, $m ) ) {
			$p = self::find_plugin( $m[1] );
			if ( $p ) {
				return $p;
			}
		}
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host && $site_host && strtolower( $host ) !== strtolower( $site_host ) ) {
			return array( 'key' => 'ext:' . $host, 'name' => 'External: ' . $host, 'type' => 'external' );
		}
		return self::unknown( 'Unidentified source' );
	}

	/* ------------------------------------------------------------------ *
	 * Elementor widgets
	 * ------------------------------------------------------------------ */

	/** widgetType => source (+ title) for every widget Elementor currently has registered. */
	public static function elementor_widget_map() {
		if ( null !== self::$widget_map ) {
			return self::$widget_map;
		}
		self::$widget_map = array();

		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return self::$widget_map;
		}
		$elementor = \Elementor\Plugin::instance();
		if ( empty( $elementor->widgets_manager ) ) {
			return self::$widget_map;
		}

		global $wp_widget_factory;

		foreach ( (array) $elementor->widgets_manager->get_widget_types() as $name => $widget ) {
			$src = self::from_object( $widget );

			// "WordPress" widgets inside Elementor: attribute to the plugin that owns the WP widget.
			if ( 0 === strpos( $name, 'wp-widget-' ) && $wp_widget_factory ) {
				$id_base = substr( $name, 10 );
				foreach ( $wp_widget_factory->widgets as $wp_widget ) {
					if ( isset( $wp_widget->id_base ) && $wp_widget->id_base === $id_base ) {
						$src = self::from_object( $wp_widget );
						break;
					}
				}
			}

			$src['title']             = method_exists( $widget, 'get_title' ) ? wp_strip_all_tags( $widget->get_title() ) : $name;
			self::$widget_map[ $name ] = $src;
		}
		return self::$widget_map;
	}

	/* ------------------------------------------------------------------ *
	 * Gutenberg blocks
	 * ------------------------------------------------------------------ */

	public static function block_source( $name ) {
		if ( isset( self::$block_cache[ $name ] ) ) {
			return self::$block_cache[ $name ];
		}
		if ( 0 === strpos( $name, 'core/' ) ) {
			return self::$block_cache[ $name ] = self::core();
		}

		$reg = class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance()->get_registered( $name ) : null;
		if ( ! $reg ) {
			return self::$block_cache[ $name ] = self::unknown();
		}

		$good = array( 'plugin', 'theme', 'mu-plugin' );

		// 1. render callback defined by a plugin.
		if ( ! empty( $reg->render_callback ) ) {
			$src = self::from_callback( $reg->render_callback );
			if ( in_array( $src['type'], $good, true ) ) {
				return self::$block_cache[ $name ] = $src;
			}
		}

		// 2. scripts / styles registered for the block.
		$handles = array();
		foreach ( array( 'editor_script_handles', 'script_handles', 'view_script_handles', 'editor_style_handles', 'style_handles', 'view_style_handles' ) as $prop ) {
			if ( property_exists( $reg, $prop ) && is_array( $reg->$prop ) ) {
				$handles = array_merge( $handles, $reg->$prop );
			}
		}
		if ( ! $handles ) { // WordPress < 6.1.
			foreach ( array( 'editor_script', 'script', 'editor_style', 'style' ) as $prop ) {
				if ( property_exists( $reg, $prop ) && is_string( $reg->$prop ) && $reg->$prop ) {
					$handles[] = $reg->$prop;
				}
			}
		}
		foreach ( $handles as $h ) {
			$asset = null;
			if ( isset( wp_scripts()->registered[ $h ] ) ) {
				$asset = wp_scripts()->registered[ $h ];
			} elseif ( isset( wp_styles()->registered[ $h ] ) ) {
				$asset = wp_styles()->registered[ $h ];
			}
			if ( $asset && $asset->src ) {
				$src = self::from_src( $asset->src );
				if ( in_array( $src['type'], $good, true ) ) {
					return self::$block_cache[ $name ] = $src;
				}
			}
		}

		// 3. guess by namespace (e.g. "kadence/..." -> kadence-blocks).
		$ns = strtok( $name, '/' );
		foreach ( self::plugins() as $basename => $data ) {
			$slug = strtok( $basename, '/' );
			if ( $ns && false !== strpos( $slug, $ns ) ) {
				return self::$block_cache[ $name ] = array( 'key' => $basename, 'name' => $data['Name'], 'type' => 'plugin' );
			}
		}

		return self::$block_cache[ $name ] = array(
			'key'  => 'unknown-ns:' . $ns,
			'name' => 'Unidentified block source (namespace "' . $ns . '")',
			'type' => 'unknown',
		);
	}

	/* ------------------------------------------------------------------ *
	 * Entry point
	 * ------------------------------------------------------------------ */

	public static function resolve( $kind, $name ) {
		switch ( $kind ) {
			case 'elementor':
				$map = self::elementor_widget_map();
				if ( isset( $map[ $name ] ) ) {
					return $map[ $name ];
				}
				if ( ! did_action( 'elementor/loaded' ) ) {
					return self::unknown( 'Elementor is not active' );
				}
				return self::unknown();

			case 'shortcode':
				global $shortcode_tags;
				if ( isset( $shortcode_tags[ $name ] ) ) {
					return self::from_callback( $shortcode_tags[ $name ] );
				}
				return self::unknown();

			case 'block':
				return self::block_source( $name );
		}
		return self::unknown();
	}
}
