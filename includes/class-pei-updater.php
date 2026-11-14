<?php
/**
 * Self-hosted plugin updates via GitHub Releases.
 *
 * Lets the plugin show an "update available" notice and one-click "Update now"
 * on the Plugins screen, sourced from the latest published GitHub Release of a
 * configured repository — no WordPress.org listing required.
 *
 * Each release should attach a built plugin .zip as a release asset. If no zip
 * asset is present, the GitHub source zipball is used and the extracted folder
 * is renamed to the correct plugin slug on install.
 *
 * @package Smart_Plugin_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PEI_Updater
 */
class PEI_Updater {

	/**
	 * Absolute path to the plugin main file.
	 *
	 * @var string
	 */
	private $file;

	/**
	 * Plugin basename (dir/file.php).
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Plugin directory slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * GitHub owner (user or org).
	 *
	 * @var string
	 */
	private $owner;

	/**
	 * GitHub repository name.
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * Optional GitHub token (for private repositories).
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Transient cache key for the remote release data.
	 *
	 * @var string
	 */
	private $cache_key;

	/**
	 * How long to cache the GitHub response.
	 *
	 * @var int
	 */
	private $cache_ttl;

	/**
	 * Constructor.
	 *
	 * @param string $file      Plugin main file (__FILE__).
	 * @param string $repo_slug "owner/repo" on GitHub.
	 * @param string $token     Optional access token for private repos.
	 */
	public function __construct( $file, $repo_slug, $token = '' ) {
		$this->file      = $file;
		$this->basename  = plugin_basename( $file );
		$this->slug      = dirname( $this->basename );
		$this->token     = (string) $token;
		$this->cache_key = 'pei_updater_' . md5( $this->basename );
		$this->cache_ttl = 6 * HOUR_IN_SECONDS;

		$parts       = explode( '/', trim( (string) $repo_slug, '/' ) );
		$this->owner = isset( $parts[0] ) ? $parts[0] : '';
		$this->repo  = isset( $parts[1] ) ? $parts[1] : '';

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * Inject an available update into the plugins update transient.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_remote();
		if ( ! $release || empty( $release['package'] ) ) {
			return $transient;
		}

		$remote_version  = ltrim( $release['tag'], 'vV' );
		$current_version = $this->current_version();

		$item = array(
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $remote_version,
			'url'         => $release['homepage'],
			'package'     => $release['package'],
			'icons'       => array(),
			'banners'     => array(),
			'tested'      => $this->header( 'Tested up to' ),
			'requires_php'=> $this->header( 'Requires PHP' ),
		);

		if ( version_compare( $remote_version, $current_version, '>' ) ) {
			$transient->response[ $this->basename ] = (object) $item;
		} else {
			// Tell WordPress there is no update (keeps the UI tidy).
			$item['new_version']                     = $current_version;
			$transient->no_update[ $this->basename ] = (object) $item;
		}

		return $transient;
	}

	/**
	 * Provide the "View version details" information popup.
	 *
	 * @param mixed  $result Result.
	 * @param string $action Action.
	 * @param object $args   Args.
	 * @return mixed
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}
		$release = $this->get_remote();
		if ( ! $release ) {
			return $result;
		}

		$header_name = $this->header( 'Name' );

		return (object) array(
			'name'          => $header_name ? $header_name : $this->slug,
			'slug'          => $this->slug,
			'version'       => ltrim( $release['tag'], 'vV' ),
			'author'        => $this->header( 'Author' ),
			'homepage'      => $release['homepage'],
			'download_link' => $release['package'],
			'requires'      => $this->header( 'Requires at least' ),
			'requires_php'  => $this->header( 'Requires PHP' ),
			'tested'        => $this->header( 'Tested up to' ),
			'last_updated'  => $release['published'],
			'sections'      => array(
				'description' => $this->header( 'Description' ),
				'changelog'   => $this->format_changelog( $release['changelog'] ),
			),
			'banners'       => array(),
		);
	}

	/**
	 * Ensure the extracted update folder matches the installed plugin slug so
	 * WordPress updates in place instead of creating a new folder.
	 *
	 * @param string $source        Extracted source path.
	 * @param string $remote_source Parent temp path.
	 * @param object $upgrader      Upgrader instance.
	 * @param array  $hook_extra    Extra data.
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}
		if ( $wp_filesystem->move( $source, $desired ) ) {
			return $desired;
		}
		return $source;
	}

	/**
	 * Clear the cached release info after an update runs.
	 *
	 * @param object $upgrader Upgrader.
	 * @param array  $data     Data.
	 */
	public function clear_cache( $upgrader, $data ) {
		if ( isset( $data['type'], $data['action'] ) && 'plugin' === $data['type'] && 'update' === $data['action'] ) {
			delete_transient( $this->cache_key );
		}
	}

	/* --------------------------------------------------------------------- *
	 *  Internals
	 * --------------------------------------------------------------------- */

	/**
	 * Fetch (and cache) the latest GitHub release.
	 *
	 * @return array|false { tag, package, homepage, changelog, published, name }
	 */
	private function get_remote() {
		if ( empty( $this->owner ) || empty( $this->repo ) ) {
			return false;
		}

		$cached = get_transient( $this->cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : false;
		}

		$url     = sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', rawurlencode( $this->owner ), rawurlencode( $this->repo ) );
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'Smart-Plugin-Inspector-Updater',
		);
		if ( $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $headers ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly so we don't hammer the API.
			set_transient( $this->cache_key, 'none', HOUR_IN_SECONDS );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( $this->cache_key, 'none', HOUR_IN_SECONDS );
			return false;
		}

		// Prefer an attached .zip release asset; fall back to the source zipball.
		$package = '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && '.zip' === strtolower( substr( (string) $asset['name'], -4 ) ) ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
		}
		if ( ! $package && ! empty( $body['zipball_url'] ) ) {
			$package = $body['zipball_url'];
		}

		$data = array(
			'tag'       => (string) $body['tag_name'],
			'package'   => $package,
			'homepage'  => ! empty( $body['html_url'] ) ? $body['html_url'] : '',
			'changelog' => ! empty( $body['body'] ) ? $body['body'] : '',
			'published' => ! empty( $body['published_at'] ) ? $body['published_at'] : '',
			'name'      => ! empty( $body['name'] ) ? $body['name'] : '',
		);

		set_transient( $this->cache_key, $data, $this->cache_ttl );
		return $data;
	}

	/**
	 * Current installed version from the plugin header.
	 *
	 * @return string
	 */
	private function current_version() {
		$data = get_file_data( $this->file, array( 'Version' => 'Version' ) );
		return ! empty( $data['Version'] ) ? $data['Version'] : '0';
	}

	/**
	 * Read a header field from the plugin file.
	 *
	 * @param string $field Header label.
	 * @return string
	 */
	private function header( $field ) {
		$data = get_file_data( $this->file, array( $field => $field ) );
		return isset( $data[ $field ] ) ? (string) $data[ $field ] : '';
	}

	/**
	 * Render the release notes as safe HTML.
	 *
	 * @param string $changelog Raw release body (markdown).
	 * @return string
	 */
	private function format_changelog( $changelog ) {
		if ( '' === trim( (string) $changelog ) ) {
			return __( 'See the GitHub release page for details.', 'smart-plugin-inspector' );
		}
		return wp_kses_post( wpautop( make_clickable( esc_html( $changelog ) ) ) );
	}
}
