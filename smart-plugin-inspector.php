<?php
/**
 * Plugin Name:       Smart Plugin Inspector
 * Description:       Find out which plugin generates each element on your pages (Elementor widgets, shortcodes, Gutenberg blocks, scripts & styles) and which plugins are not used anywhere in your content.
 * Version:           1.1.0
 * Author:            Uniczen
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       smart-plugin-inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * The old "Plugin Element Inspector" (folder plugin-element-inspector) uses the
 * same code. If it is still active, stop here instead of crashing the site,
 * and ask the admin to remove it.
 */
if ( defined( 'PEI_VERSION' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html( 'Smart Plugin Inspector is paused because the old "Plugin Element Inspector" plugin is still active. Deactivate and delete "Plugin Element Inspector" on the Plugins screen. Smart Plugin Inspector replaces it.' );
			echo '</p></div>';
		}
	);
	return;
}

define( 'PEI_VERSION', '1.1.0' );
define( 'PEI_FILE', __FILE__ );
define( 'PEI_PATH', plugin_dir_path( __FILE__ ) );
define( 'PEI_URL', plugin_dir_url( __FILE__ ) );

require_once PEI_PATH . 'includes/class-pei-resolver.php';
require_once PEI_PATH . 'includes/class-pei-scanner.php';
require_once PEI_PATH . 'includes/class-pei-admin.php';
require_once PEI_PATH . 'includes/class-pei-frontend.php';
require_once PEI_PATH . 'includes/class-pei-updater.php';

/*
 * Self-hosted updates from GitHub Releases: new versions show up on the
 * Plugins screen with an "Update now" button. For a PRIVATE repository, also
 * add  define( 'PEI_GITHUB_TOKEN', 'ghp_xxx' );  to wp-config.php.
 */
if ( ! defined( 'PEI_GITHUB_REPO' ) ) {
	define( 'PEI_GITHUB_REPO', 'ybt6118/plugin-inspector' );
}
new PEI_Updater( __FILE__, PEI_GITHUB_REPO, defined( 'PEI_GITHUB_TOKEN' ) ? PEI_GITHUB_TOKEN : '' );

add_action( 'plugins_loaded', array( 'PEI_Admin', 'init' ) );
add_action( 'plugins_loaded', array( 'PEI_Frontend', 'init' ) );

register_uninstall_hook( __FILE__, 'pei_uninstall' );

/*
 * Declared inside a function_exists() check on purpose: PHP creates plain
 * top-level functions before any code in this file runs, which would bypass
 * the old-plugin check above and cause a "Cannot redeclare pei_uninstall()"
 * fatal error while the old Plugin Element Inspector is still active.
 */
if ( ! function_exists( 'pei_uninstall' ) ) {
	function pei_uninstall() {
		delete_option( 'pei_scan_results' );
	}
}
