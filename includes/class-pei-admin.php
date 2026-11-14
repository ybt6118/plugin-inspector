<?php
/**
 * Tools > Smart Plugin Inspector report page + AJAX scanner.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PEI_Admin {

	const SLUG = 'smart-plugin-inspector';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'wp_ajax_pei_scan_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_pei_scan_batch', array( __CLASS__, 'ajax_batch' ) );
		add_action( 'wp_ajax_pei_scan_finish', array( __CLASS__, 'ajax_finish' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PEI_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ) . '">Open report</a>' );
		return $links;
	}

	public static function menu() {
		$hook = add_management_page( 'Smart Plugin Inspector', 'Smart Plugin Inspector', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		add_action( 'admin_print_scripts-' . $hook, array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		wp_enqueue_style( 'pei-admin', PEI_URL . 'assets/admin.css', array(), PEI_VERSION );
		wp_enqueue_script( 'pei-admin', PEI_URL . 'assets/admin.js', array(), PEI_VERSION, true );
		wp_localize_script(
			'pei-admin',
			'PEI_ADMIN',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'pei_scan' ),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * AJAX
	 * ------------------------------------------------------------------ */

	private static function guard() {
		check_ajax_referer( 'pei_scan', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
	}

	public static function ajax_start() {
		self::guard();
		$ids = PEI_Scanner::get_ids();
		update_option(
			PEI_Scanner::OPTION,
			array( 'items' => array(), 'scanned' => 0, 'total' => count( $ids ), 'started' => time(), 'finished' => 0 ),
			false
		);
		wp_send_json_success( array( 'ids' => array_map( 'intval', $ids ) ) );
	}

	public static function ajax_batch() {
		self::guard();
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$res = get_option( PEI_Scanner::OPTION );
		if ( ! is_array( $res ) ) {
			wp_send_json_error( 'no scan running' );
		}
		foreach ( $ids as $id ) {
			foreach ( PEI_Scanner::scan_post( $id ) as $key => $count ) {
				if ( ! isset( $res['items'][ $key ] ) ) {
					list( $kind, $name ) = explode( '|', $key, 2 );
					$res['items'][ $key ] = array( 'kind' => $kind, 'name' => $name, 'posts' => array() );
				}
				$res['items'][ $key ]['posts'][ $id ] = $count;
			}
			$res['scanned']++;
		}
		update_option( PEI_Scanner::OPTION, $res, false );
		wp_send_json_success( array( 'scanned' => $res['scanned'] ) );
	}

	public static function ajax_finish() {
		self::guard();
		$res = get_option( PEI_Scanner::OPTION );
		if ( is_array( $res ) ) {
			$res['finished'] = time();
			update_option( PEI_Scanner::OPTION, $res, false );
		}
		wp_send_json_success();
	}

	/* ------------------------------------------------------------------ *
	 * Report building
	 * ------------------------------------------------------------------ */

	private static function build_groups( $res ) {
		$map    = PEI_Resolver::elementor_widget_map();
		$groups = array();

		foreach ( $res['items'] as $item ) {
			$src = PEI_Resolver::resolve( $item['kind'], $item['name'] );
			$gk  = $src['key'];
			if ( ! isset( $groups[ $gk ] ) ) {
				$groups[ $gk ] = array(
					'key'   => $gk,
					'name'  => $src['name'],
					'type'  => $src['type'],
					'items' => array(),
					'total' => 0,
					'posts' => array(),
					'kinds' => array( 'elementor' => 0, 'shortcode' => 0, 'block' => 0 ),
				);
			}
			$label = $item['name'];
			if ( 'elementor' === $item['kind'] && isset( $map[ $item['name'] ] ) ) {
				$label = $map[ $item['name'] ]['title'] . ' (' . $item['name'] . ')';
			} elseif ( 'shortcode' === $item['kind'] ) {
				$label = '[' . $item['name'] . ']';
			}
			$total                                   = array_sum( $item['posts'] );
			$groups[ $gk ]['items'][]                = array( 'kind' => $item['kind'], 'label' => $label, 'total' => $total, 'posts' => $item['posts'] );
			$groups[ $gk ]['total']                 += $total;
			$groups[ $gk ]['kinds'][ $item['kind'] ] += $total;
			foreach ( array_keys( $item['posts'] ) as $pid ) {
				$groups[ $gk ]['posts'][ $pid ] = true;
			}
		}

		$rank = function ( $g ) {
			return ( 'unknown' === $g['type'] ) ? 2 : ( ( 'core' === $g['type'] ) ? 1 : 0 );
		};
		uasort(
			$groups,
			function ( $a, $b ) use ( $rank ) {
				$r = $rank( $a ) - $rank( $b );
				return $r ? $r : $b['total'] - $a['total'];
			}
		);
		foreach ( $groups as &$g ) {
			usort(
				$g['items'],
				function ( $a, $b ) {
					return $b['total'] - $a['total'];
				}
			);
		}
		unset( $g );
		return $groups;
	}

	private static function active_plugins() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return array_unique( $active );
	}

	private static function post_links( $posts, $limit = 60 ) {
		arsort( $posts );
		$out = array();
		$i   = 0;
		foreach ( $posts as $pid => $count ) {
			if ( ++$i > $limit ) {
				$out[] = '<li><em>+ ' . ( count( $posts ) - $limit ) . ' more</em></li>';
				break;
			}
			$p = get_post( $pid );
			if ( ! $p ) {
				continue;
			}
			$type_obj = get_post_type_object( $p->post_type );
			$title    = $p->post_title ? $p->post_title : '(no title #' . $pid . ')';
			$is_el    = 'builder' === get_post_meta( $pid, '_elementor_edit_mode', true );
			$edit     = $is_el ? admin_url( 'post.php?post=' . $pid . '&action=elementor' ) : get_edit_post_link( $pid, 'raw' );
			$view     = get_permalink( $pid );
			$line     = '<strong>' . esc_html( $title ) . '</strong> <span class="pei-muted">' . esc_html( $type_obj ? $type_obj->labels->singular_name : $p->post_type );
			if ( 'publish' !== $p->post_status ) {
				$line .= ' · ' . esc_html( $p->post_status );
			}
			$line .= ' · ' . intval( $count ) . '×</span>';
			if ( $edit ) {
				$line .= ' <a href="' . esc_url( $edit ) . '" target="_blank">' . ( $is_el ? 'Edit in Elementor' : 'Edit' ) . '</a>';
			}
			if ( $view && 'publish' === $p->post_status && 'elementor_library' !== $p->post_type ) {
				$line .= ' · <a href="' . esc_url( add_query_arg( 'pei_inspect', '1', $view ) ) . '" target="_blank">Inspect on page</a>';
			}
			$out[] = '<li>' . $line . '</li>';
		}
		return '<ul class="pei-posts">' . implode( '', $out ) . '</ul>';
	}

	/* ------------------------------------------------------------------ *
	 * Page
	 * ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$res = get_option( PEI_Scanner::OPTION );
		?>
		<div class="wrap pei-wrap">
			<h1>Smart Plugin Inspector</h1>
			<p>Scans every page, post, Elementor template and other content for <strong>Elementor widgets, shortcodes and blocks</strong>, and tells you which plugin each one comes from.</p>

			<p>
				<button class="button button-primary" id="pei-scan">Scan site now</button>
				<span id="pei-progress" class="pei-muted"></span>
			</p>
			<div class="pei-bar" hidden><div></div></div>

			<?php
			if ( ! is_array( $res ) || empty( $res['finished'] ) ) {
				echo '<div class="notice notice-info inline"><p>No completed scan yet. Click <strong>Scan site now</strong>.</p></div></div>';
				return;
			}

			$groups  = self::build_groups( $res );
			$active  = self::active_plugins();
			$plugins = PEI_Resolver::plugins();
			$self    = plugin_basename( PEI_FILE );

			printf(
				'<p class="pei-muted">Last scan: %s · %d items of content scanned.</p>',
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $res['finished'] ) ),
				intval( $res['scanned'] )
			);

			/* ---------- 1. Used by plugin ---------- */
			echo '<h2>1. Elements found, grouped by plugin</h2>';
			echo '<table class="widefat striped pei-table"><thead><tr><th>Source</th><th>Status</th><th>Elementor widgets</th><th>Shortcodes</th><th>Blocks</th><th>Pages / templates</th></tr></thead><tbody>';
			foreach ( $groups as $g ) {
				if ( 'plugin' === $g['type'] ) {
					$status = in_array( $g['key'], $active, true ) ? '<span class="pei-ok">Active</span>' : '<span class="pei-warn">Not active</span>';
				} elseif ( 'unknown' === $g['type'] ) {
					$status = '<span class="pei-bad">Missing</span>';
				} else {
					$status = '<span class="pei-muted">' . esc_html( ucfirst( $g['type'] ) ) . '</span>';
				}
				echo '<tr class="pei-type-' . esc_attr( $g['type'] ) . '"><td><details><summary><strong>' . esc_html( $g['name'] ) . '</strong></summary>';
				foreach ( $g['items'] as $it ) {
					echo '<details class="pei-item"><summary>' . esc_html( $it['label'] ) . ' <span class="pei-muted">' . esc_html( $it['kind'] ) . ' · ' . intval( $it['total'] ) . ' uses on ' . count( $it['posts'] ) . ' page(s)</span></summary>';
					echo self::post_links( $it['posts'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
					echo '</details>';
				}
				echo '</details></td><td>' . $status . '</td>'; // phpcs:ignore
				foreach ( array( 'elementor', 'shortcode', 'block' ) as $k ) {
					echo '<td>' . ( $g['kinds'][ $k ] ? intval( $g['kinds'][ $k ] ) : '<span class="pei-muted">–</span>' ) . '</td>';
				}
				echo '<td>' . count( $g['posts'] ) . '</td></tr>';
			}
			if ( ! $groups ) {
				echo '<tr><td colspan="6">Nothing found.</td></tr>';
			}
			echo '</tbody></table>';

			/* ---------- 2. Active plugins with no elements ---------- */
			$unused = array();
			foreach ( $active as $basename ) {
				if ( $basename === $self || isset( $groups[ $basename ] ) ) {
					continue;
				}
				$unused[ $basename ] = isset( $plugins[ $basename ] ) ? $plugins[ $basename ]['Name'] : $basename;
			}
			natcasesort( $unused );
			echo '<h2>2. Active plugins with NO elements found in your content</h2>';
			echo '<p class="pei-note"><strong>Careful:</strong> this does not automatically mean the plugin is safe to remove. Many plugins work behind the scenes and never place an element on a page (SEO, security, backup, caching, SMTP, WooCommerce gateways, Elementor itself as an editor, licence managers, etc). Treat this as a <em>shortlist to check</em>, and always test on a staging copy first.</p>';
			if ( $unused ) {
				echo '<ul class="pei-unused">';
				foreach ( $unused as $basename => $name ) {
					echo '<li><strong>' . esc_html( $name ) . '</strong> <code>' . esc_html( $basename ) . '</code></li>';
				}
				echo '</ul>';
			} else {
				echo '<p>Every active plugin has at least one element in your content.</p>';
			}

			/* ---------- 3. Elementor addon inventory ---------- */
			$map = PEI_Resolver::elementor_widget_map();
			if ( $map ) {
				$used = array();
				foreach ( $res['items'] as $item ) {
					if ( 'elementor' === $item['kind'] ) {
						$used[ $item['name'] ] = true;
					}
				}
				$by = array();
				foreach ( $map as $wname => $src ) {
					if ( ! isset( $by[ $src['key'] ] ) ) {
						$by[ $src['key'] ] = array( 'name' => $src['name'], 'used' => array(), 'unused' => array() );
					}
					$by[ $src['key'] ][ isset( $used[ $wname ] ) ? 'used' : 'unused' ][] = $src['title'] . ' (' . $wname . ')';
				}
				uasort(
					$by,
					function ( $a, $b ) {
						return count( $b['used'] ) - count( $a['used'] );
					}
				);
				echo '<h2>3. Elementor widget inventory (registered vs actually used)</h2>';
				echo '<p class="pei-note">Use this to decide whether an Elementor add-on is worth keeping. If an add-on loads 80 widgets but you only use 2, you can either replace those 2 with Elementor/Pro widgets and remove the add-on, or switch off the unused widgets in the add-on\'s own settings to speed up the site.</p>';
				echo '<table class="widefat striped pei-table"><thead><tr><th>Plugin</th><th>Widgets registered</th><th>Widgets used</th><th>Details</th></tr></thead><tbody>';
				foreach ( $by as $row ) {
					$total = count( $row['used'] ) + count( $row['unused'] );
					$cls   = $row['used'] ? '' : ' class="pei-row-zero"';
					echo '<tr' . $cls . '><td><strong>' . esc_html( $row['name'] ) . '</strong></td><td>' . intval( $total ) . '</td><td>' . ( $row['used'] ? intval( count( $row['used'] ) ) : '<span class="pei-bad">0</span>' ) . '</td><td>'; // phpcs:ignore
					if ( $row['used'] ) {
						echo '<details><summary>Used</summary><p>' . esc_html( implode( ', ', $row['used'] ) ) . '</p></details>';
					}
					if ( $row['unused'] ) {
						echo '<details><summary>Not used (' . count( $row['unused'] ) . ')</summary><p class="pei-muted">' . esc_html( implode( ', ', $row['unused'] ) ) . '</p></details>';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			}
			?>
			<h2>Next step: check the live pages</h2>
			<p>The scan reads saved content. To also see <strong>header/footer templates, popups, and which plugins load CSS/JS files</strong> on a page, open any page while logged in and click <strong>Inspect Plugins</strong> in the top admin bar (or click "Inspect on page" in the lists above).</p>
		</div>
		<?php
	}
}
