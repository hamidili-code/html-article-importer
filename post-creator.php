<?php
/**
 * Post Creator — creates a WordPress draft post and persists Elementor page data.
 *
 * @package HtmlArticleImporter
 */

declare( strict_types=1 );

namespace HtmlArticleImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PostCreator {

	/**
	 * Create a draft post with Elementor data attached.
	 *
	 * @param  string $title          Post title.
	 * @param  string $elementor_json JSON-encoded Elementor data array.
	 * @return int|\WP_Error          Newly created post ID, or WP_Error on failure.
	 */
	public function create( string $title, string $elementor_json ): int|\WP_Error {
		$post_id = $this->insert_post( $title );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->save_elementor_data( $post_id, $elementor_json );
		$this->save_elementor_meta( $post_id );

		return $post_id;
	}


	/**
	 * Insert the WordPress post as a draft.
	 */
	private function insert_post( string $title ): int|\WP_Error {
		$post_data = [
			'post_title'   => sanitize_text_field( $title ),
			'post_content' => '',
			'post_status'  => 'draft',
			'post_type'    => 'post',
			'post_author'  => get_current_user_id(),
		];

		return wp_insert_post( $post_data, true );
	}

	/**
	 * Save the Elementor JSON data as post meta.
	 */
	private function save_elementor_data( int $post_id, string $elementor_json ): void {
		update_post_meta( $post_id, '_elementor_data', wp_slash( $elementor_json ) );
	}

	/**
	 * Save required Elementor meta flags so the editor recognises the post.
	 */
	private function save_elementor_meta( int $post_id ): void {
		update_post_meta( $post_id, '_elementor_edit_mode',     'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-post' );
		update_post_meta( $post_id, '_elementor_version',       $this->get_elementor_version() );
		update_post_meta( $post_id, '_wp_page_template',        'default' );
	}

	/** Retrieve the active Elementor version, or a safe fallback. */
	private function get_elementor_version(): string {
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			return ELEMENTOR_VERSION;
		}

		$elementor_data = get_plugin_data( WP_PLUGIN_DIR . '/elementor/elementor.php', false, false );

		return ! empty( $elementor_data['Version'] ) ? $elementor_data['Version'] : '3.0.0';
	}
}
