<?php
/**
 * Inventory collector: every plugin, mu-plugin, and drop-in on the site.
 *
 * Reads WordPress directly. Makes no network calls. Knows nothing about MCP.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects the full plugin inventory as plain PHP arrays.
 */
class PluginLens_Inventory_Collector {

	const DISK_TRANSIENT  = 'pluginlens_disk_footprint';
	const FILE_SCAN_LIMIT = 10000;

	/**
	 * Memoized records for this request.
	 *
	 * @var array[]|null
	 */
	private $records = null;

	/**
	 * Returns one record per plugin, mu-plugin, and drop-in. Memoized per
	 * instance; WordPress is only read once per request.
	 *
	 * @return array[]
	 */
	public function collect() {
		if ( null !== $this->records ) {
			return $this->records;
		}
		// get_plugins() lives in an admin include that REST requests never load.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$records = array();

		// Never trigger a fresh update check here; read whatever WordPress
		// already knows.
		$update_data  = get_site_transient( 'update_plugins' );
		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
		$network_wide = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$disk_cache   = $this->disk_cache();

		foreach ( get_plugins() as $file => $headers ) {
			$single_file = ( '.' === dirname( $file ) );
			$slug        = $single_file ? basename( $file, '.php' ) : dirname( $file );
			$is_network  = in_array( $file, $network_wide, true );
			$active      = $is_network || is_plugin_active( $file );
			$footprint   = $this->disk_footprint( $slug, $file, $headers, $disk_cache );

			$records[] = array(
				'file'             => $file,
				'slug'             => $slug,
				'name'             => (string) $headers['Name'],
				'version'          => (string) $headers['Version'],
				'author'           => wp_strip_all_tags( (string) $headers['Author'] ),
				'description'      => wp_strip_all_tags( (string) $headers['Description'] ),
				'plugin_uri'       => (string) $headers['PluginURI'],
				'text_domain'      => (string) $headers['TextDomain'],
				'requires_wp'      => (string) $headers['RequiresWP'],
				'requires_php'     => (string) $headers['RequiresPHP'],
				'status'           => $active ? 'active' : 'inactive',
				'network_active'   => $is_network,
				'single_file'      => $single_file,
				'auto_update'      => in_array( $file, $auto_updates, true ),
				'update_available' => isset( $update_data->response[ $file ] ),
				'latest_version'   => isset( $update_data->response[ $file ]->new_version ) ? (string) $update_data->response[ $file ]->new_version : null,
				'disk_size'        => $footprint['size'],
				'file_count'       => $footprint['files'],
			);
		}

		foreach ( get_mu_plugins() as $file => $headers ) {
			$records[] = $this->flat_file_record( $file, $headers, 'mu', WPMU_PLUGIN_DIR );
		}

		foreach ( get_dropins() as $file => $headers ) {
			$records[] = $this->flat_file_record( $file, $headers, 'dropin', WP_CONTENT_DIR );
		}

		$this->save_disk_cache( $disk_cache );

		$this->records = $records;
		return $records;
	}

	/**
	 * Builds a record for the single-file categories (mu-plugins, drop-ins).
	 *
	 * @param string $file    File name relative to its directory.
	 * @param array  $headers Parsed plugin headers.
	 * @param string $status  'mu' or 'dropin'.
	 * @param string $base    Directory the file lives in.
	 * @return array
	 */
	private function flat_file_record( $file, $headers, $status, $base ) {
		$path = trailingslashit( $base ) . $file;
		$size = is_readable( $path ) ? (int) filesize( $path ) : null;

		return array(
			'file'             => $file,
			'slug'             => basename( $file, '.php' ),
			'name'             => '' !== (string) $headers['Name'] ? (string) $headers['Name'] : $file,
			'version'          => (string) $headers['Version'],
			'author'           => wp_strip_all_tags( (string) $headers['Author'] ),
			'description'      => wp_strip_all_tags( (string) $headers['Description'] ),
			'plugin_uri'       => (string) $headers['PluginURI'],
			'text_domain'      => (string) $headers['TextDomain'],
			'requires_wp'      => (string) $headers['RequiresWP'],
			'requires_php'     => (string) $headers['RequiresPHP'],
			'status'           => $status,
			'network_active'   => false,
			'single_file'      => true,
			'auto_update'      => false,
			'update_available' => false,
			'latest_version'   => null,
			'disk_size'        => $size,
			'file_count'       => 1,
		);
	}

	/**
	 * Disk size and file count for a regular plugin, from cache when fresh.
	 *
	 * The cache key includes the version so an update naturally invalidates.
	 *
	 * @param string $slug       Plugin slug.
	 * @param string $file       Plugin file relative to the plugins directory.
	 * @param array  $headers    Plugin headers.
	 * @param array  $disk_cache Cache array, passed by reference.
	 * @return array{size: ?int, files: ?int}
	 */
	private function disk_footprint( $slug, $file, $headers, &$disk_cache ) {
		$key = $slug . '|' . $headers['Version'];
		if ( isset( $disk_cache[ $key ] ) ) {
			return $disk_cache[ $key ];
		}

		$single_file = ( '.' === dirname( $file ) );
		$path        = trailingslashit( WP_PLUGIN_DIR ) . ( $single_file ? $file : dirname( $file ) );
		$footprint   = $this->scan_path( $path );

		$disk_cache[ $key ] = $footprint;
		return $footprint;
	}

	/**
	 * Recursively measures a path, bailing out to null past the file ceiling
	 * rather than timing out.
	 *
	 * @param string $path Absolute path.
	 * @return array{size: ?int, files: ?int}
	 */
	private function scan_path( $path ) {
		if ( is_file( $path ) ) {
			return array(
				'size'  => (int) filesize( $path ),
				'files' => 1,
			);
		}
		if ( ! is_dir( $path ) ) {
			return array(
				'size'  => null,
				'files' => null,
			);
		}

		$size  = 0;
		$count = 0;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $iterator as $item ) {
				if ( ! $item->isFile() ) {
					continue;
				}
				++$count;
				if ( $count > self::FILE_SCAN_LIMIT ) {
					return array(
						'size'  => null,
						'files' => null,
					);
				}
				$size += (int) $item->getSize();
			}
		} catch ( Exception $e ) {
			return array(
				'size'  => null,
				'files' => null,
			);
		}

		return array(
			'size'  => $size,
			'files' => $count,
		);
	}

	/**
	 * Loads the disk footprint cache.
	 *
	 * @return array
	 */
	private function disk_cache() {
		$cache = get_transient( self::DISK_TRANSIENT );
		return is_array( $cache ) ? $cache : array();
	}

	/**
	 * Persists the disk footprint cache for 24 hours.
	 *
	 * @param array $cache Cache array.
	 * @return void
	 */
	private function save_disk_cache( $cache ) {
		set_transient( self::DISK_TRANSIENT, $cache, DAY_IN_SECONDS );
	}
}
