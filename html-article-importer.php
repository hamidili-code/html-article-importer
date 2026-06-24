<?php
/**
 * Plugin Name:       HTML Article Importer
 * Plugin URI:        https://github.com/hamidili-code/html-article-importer
 * Description:       Generate Elementor-based draft posts from a ZIP package. Reduces article publishing time from hours to minutes.
 * Version:           10.1.2
 * Requires at least: 7.0
 * Requires PHP:      8.0
 * Requires Plugins:  elementor
 * Author:            Ali Hamidili
 * Author URI:        https://shelow.ir
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       html-article-importer
 */

declare( strict_types=1 );

namespace HtmlArticleImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HTML_ARTICLE_IMPORTER_VERSION', '10.1.1' );
define( 'HTML_ARTICLE_IMPORTER_FILE', __FILE__ );
define( 'HTML_ARTICLE_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'HTML_ARTICLE_IMPORTER_URL', plugin_dir_url( __FILE__ ) );
define( 'HTML_ARTICLE_IMPORTER_BASENAME', plugin_basename( __FILE__ ) );


spl_autoload_register( function ( string $class ): void {
	$prefix   = 'HtmlArticleImporter\\';
	$base_dir = HTML_ARTICLE_IMPORTER_DIR;

	if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, strlen( $prefix ) );
	$file_map       = [
		'AdminPage'        => 'admin-page.php',
		'Parser'           => 'parser.php',
		'ImageUploader'    => 'image-uploader.php',
		'ElementorBuilder' => 'elementor-builder.php',
		'PostCreator'      => 'post-creator.php',
	];

	if ( isset( $file_map[ $relative_class ] ) ) {
		$file = $base_dir . $file_map[ $relative_class ];
		if ( file_exists( $file ) ) {
			require $file;
		}
	}
} );

/**
 * Main plugin class — singleton bootstrap.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	public function register_admin_menu(): void {
		add_menu_page(
			__( 'HTML to Elementor', 'html-article-importer' ),
			__( 'HTML to Elementor', 'html-article-importer' ),
			'manage_options',
			'html-article-importer',
			[ new AdminPage(), 'render' ],
			'dashicons-upload',
			30
		);
	}

	public function enqueue_admin_assets( string $hook ): void {
		if ( 'toplevel_page_html-article-importer' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'html-article-importer-admin',
			HTML_ARTICLE_IMPORTER_URL . 'templates/admin.css',
			[],
			HTML_ARTICLE_IMPORTER_VERSION
		);
	}
}

// Boot the plugin.
add_action( 'plugins_loaded', [ Plugin::class, 'instance' ] );

// Activation hook — ensure required extensions are available.
register_activation_hook( __FILE__, function (): void {
	if ( ! class_exists( 'ZipArchive' ) ) {
		deactivate_plugins( HTML_ARTICLE_IMPORTER_BASENAME );
		wp_die(
			esc_html__( 'HTML to Elementor Importer requires the PHP ZipArchive extension. Please contact your host.', 'html-article-importer' ),
			esc_html__( 'Plugin Activation Error', 'html-article-importer' ),
			[ 'back_link' => true ]
		);
	}
} );
