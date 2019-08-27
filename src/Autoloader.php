<?php
/**
 * WPS Mu Plugins Autoloader
 *
 * Entirely taken from Roots Bedrock Autoloader.
 *
 * @package    WPS\WP\mu_plugins
 * @author     Travis Smith <t@wpsmith.net>
 * @copyright  2015-2018 WP Smith, Travis Smith
 * @link       https://github.com/wpsmith/MuAutoloader/
 * @link       https://github.com/roots/bedrock/blob/master/web/app/mu-plugins/bedrock-autoloader.php
 * @license    http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @version    1.0.0
 * @since      0.1.0
 */

namespace WPS\WP\MuPlugins;

use WPS\Core\Singleton;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Autoloader' ) ) {

	if ( ! is_blog_installed() ) {
		return;
	}

	/**
	 * Class Autoloader
	 * @package WPS\WP\mu_plugins
	 */
	class Autoloader extends Singleton {

		/** @var array Store Autoloader cache and site option */
		private $cache;

		/** @var array Autoloaded plugins */
		private $auto_plugins;

		/** @var array Autoloaded mu-plugins */
		private $mu_plugins;

		/** @var int Number of plugins */
		private $count;

		/** @var array Newly activated plugins */
		private $activated;

		/** @var string Relative path to the mu-plugins dir */
		private $relative_path;

		private $option_name = 'wps_autoloader';

		protected function __construct( $args = array() ) {
			$this->relative_path = '/../' . basename( __DIR__ );

			if ( is_admin() ) {
				add_filter( 'show_advanced_plugins', [ $this, 'show_in_admin' ], 0, 2 );
			}

			$this->load_plugins();
		}

		/**
		 * Run some checks then autoload our plugins.
		 */
		public function load_plugins() {
			$this->check_cache();
			$this->validate_plugins();
			$this->count_plugins();

			array_map( static function () {
				include_once trailingslashit( WPMU_PLUGIN_DIR ) . func_get_args()[0];
			}, array_keys( $this->cache['plugins'] ) );

			$this->plugin_hooks();
		}

		/**
		 * Filter show_advanced_plugins to display the autoloaded plugins.
		 *
		 * @param $show bool Whether to show the advanced plugins for the specified plugin type.
		 * @param $type string The plugin type, i.e., `mustuse` or `dropins`
		 *
		 * @return bool We return `false` to prevent WordPress from overriding our work
		 * {@internal We add the plugin details ourselves, so we return false to disable the filter.}
		 */
		public function show_in_admin( $show, $type ) {
			$screen  = get_current_screen();
			$current = is_multisite() ? 'plugins-network' : 'plugins';

			if ( $screen->base !== $current || $type !== 'mustuse' || ! current_user_can( 'activate_plugins' ) ) {
				return $show;
			}

			$this->update_cache();

			$this->auto_plugins = array_map( function ( $auto_plugin ) {
				$auto_plugin['Name'] .= ' *';

				return $auto_plugin;
			}, $this->auto_plugins );

			$GLOBALS['plugins']['mustuse'] = array_unique( array_merge( $this->auto_plugins, $this->mu_plugins ), SORT_REGULAR );

			return false;
		}

		/**
		 * This sets the cache or calls for an update
		 */
		private function check_cache() {
			$cache = get_site_option( $this->option_name );

			if ( $cache === false || ( isset( $cache['plugins'], $cache['count'] ) && count( $cache['plugins'] ) !== $cache['count'] ) ) {
				$this->update_cache();

				return;
			}

			$this->cache = $cache;
		}

		/**
		 * Get the plugins and mu-plugins from the mu-plugin path and remove duplicates.
		 * Check cache against current plugins for newly activated plugins.
		 * After that, we can update the cache.
		 */
		private function update_cache() {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$this->auto_plugins = get_plugins( $this->relative_path );
			$this->mu_plugins   = get_mu_plugins();
			$plugins            = array_diff_key( $this->auto_plugins, $this->mu_plugins );
			$rebuild            = ! is_array( $this->cache['plugins'] );
			$this->activated    = $rebuild ? $plugins : array_diff_key( $plugins, $this->cache['plugins'] );
			$this->cache        = [ 'plugins' => $plugins, 'count' => $this->count_plugins() ];

			update_site_option( $this->option_name, $this->cache );
		}

		/**
		 * This accounts for the plugin hooks that would run if the plugins were
		 * loaded as usual. Plugins are removed by deletion, so there's no way
		 * to deactivate or uninstall.
		 */
		private function plugin_hooks() {
			if ( ! is_array( $this->activated ) ) {
				return;
			}

			foreach ( $this->activated as $plugin_file => $plugin_info ) {
				do_action( 'activate_' . $plugin_file );
			}
		}

		/**
		 * Check that the plugin file exists, if it doesn't update the cache.
		 */
		private function validate_plugins() {
			foreach ( $this->cache['plugins'] as $plugin_file => $plugin_info ) {
				if ( ! file_exists( trailingslashit( WPMU_PLUGIN_DIR ) . $plugin_file ) ) {
					$this->update_cache();
					break;
				}
			}
		}

		/**
		 * Count the number of autoloaded plugins.
		 *
		 * Count our plugins (but only once) by counting the top level folders in the
		 * mu-plugins dir. If it's more or less than last time, update the cache.
		 *
		 * @return int Number of autoloaded plugins.
		 */
		private function count_plugins() {
			if ( isset( $this->count ) ) {
				return $this->count;
			}

			$count = count( glob( WPMU_PLUGIN_DIR . '/*/', GLOB_ONLYDIR | GLOB_NOSORT ) );

			if ( ! isset( $this->cache['count'] ) || $count !== $this->cache['count'] ) {
				$this->count = $count;
				$this->update_cache();
			}

			return $this->count;
		}
	}
}
