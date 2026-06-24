<?php
/**
 * Image Uploader — discovers and uploads images from extracted ZIP directory.
 *
 * @package HtmlArticleImporter
 */

declare( strict_types=1 );

namespace HtmlArticleImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImageUploader {

	/** Supported image extensions (lowercase). */
	private const ALLOWED_EXTENSIONS = [ 'webp', 'jpg', 'jpeg', 'png', 'gif' ];

	/** MIME types map. */
	private const MIME_MAP = [
		'webp' => 'image/webp',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
	];

	/**
	 * Discover all image files in a directory and upload them to the Media Library.
	 *
	 * @param  string $directory Absolute path to the extracted ZIP directory.
	 * @return array<string, array{id: int, url: string}> Map of image key => attachment data.
	 */
	public function upload_from_directory( string $directory ): array {
		$image_files = $this->discover_images( $directory );
		$images      = [];

		foreach ( $image_files as $key => $file_path ) {
			$attachment = $this->upload_image( $file_path );
			if ( null !== $attachment ) {
				$images[ $key ] = $attachment;
			}
		}

		return $images;
	}

	/**
	 * Discover image files, returning [ key_without_extension => absolute_path ].
	 *
	 * @return array<string, string>
	 */
	private function discover_images( string $directory ): array {
		$found = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$ext = strtolower( $file->getExtension() );

			if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
				continue;
			}

			$key = pathinfo( $file->getFilename(), PATHINFO_FILENAME );

			if ( isset( $found[ $key ] ) ) {
				continue;
			}

			$found[ $key ] = $file->getPathname();
		}

		return $found;
	}

	/**
	 * Upload a single image file to the WordPress Media Library.
	 *
	 * @param  string $file_path Absolute path to the image file.
	 * @return array{id: int, url: string}|null Attachment data or null on failure.
	 */
	private function upload_image( string $file_path ): ?array {
		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		// wp_insert_attachment() is part of wp-includes/post.php, loaded by WordPress core.
		// No additional require needed for wp_insert_attachment itself.

		$filename  = basename( $file_path );
		$ext       = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$mime_type = self::MIME_MAP[ $ext ] ?? 'application/octet-stream';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file, not a remote URL.
		$file_contents = file_get_contents( $file_path );
		if ( false === $file_contents ) {
			return null;
		}

		$upload = wp_upload_bits( $filename, null, $file_contents );

		if ( ! empty( $upload['error'] ) ) {
			return null;
		}

		$attachment = [
			'post_mime_type' => $mime_type,
			'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return null;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return [
			'id'  => (int) $attachment_id,
			'url' => (string) $upload['url'],
		];
	}
}
