<?php
/**
 * Admin Page — ZIP upload form, nonce validation, upload handling.
 *
 * @package HtmlArticleImporter
 */

declare( strict_types=1 );

namespace HtmlArticleImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminPage {

	private const NONCE_ACTION  = 'html_to_elementor_upload';
	private const NONCE_FIELD   = 'html_to_elementor_nonce';
	private const UPLOAD_FIELD  = 'html_to_elementor_zip';


	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'html-article-importer' ) );
		}

		$result = null;

		if ( $this->is_form_submitted() ) {
			$result = $this->handle_upload();
		}

		$this->output_page( $result );
	}


	private function is_form_submitted(): bool {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return false;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
		return wp_verify_nonce( $nonce, self::NONCE_ACTION ) !== false;
	}

	/**
	 * Process the uploaded ZIP and return a result array.
	 *
	 * @return array{success:bool,message:string,post_id?:int,post_url?:string,edit_url?:string}
	 */
	private function handle_upload(): array {

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD ) ) {
			return $this->error( __( 'Security check failed. Please try again.', 'html-article-importer' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error( __( 'Insufficient permissions.', 'html-article-importer' ) );
		}

		if ( ! isset( $_FILES[ self::UPLOAD_FIELD ] ) || empty( $_FILES[ self::UPLOAD_FIELD ]['tmp_name'] ) ) {
			return $this->error( __( 'No file was uploaded.', 'html-article-importer' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated via finfo below.
		$file = $_FILES[ self::UPLOAD_FIELD ];

		$finfo     = new \finfo( FILEINFO_MIME_TYPE );
		$mime_type = $finfo->file( $file['tmp_name'] );

		if ( ! in_array( $mime_type, [ 'application/zip', 'application/x-zip-compressed', 'application/octet-stream' ], true ) ) {
			return $this->error( __( 'Uploaded file must be a ZIP archive.', 'html-article-importer' ) );
		}

		if ( $file['size'] > 100 * 1024 * 1024 ) {
			return $this->error( __( 'ZIP file must not exceed 100 MB.', 'html-article-importer' ) );
		}

		$tmp_path = $this->move_to_temp( $file['tmp_name'] );
		if ( ! $tmp_path ) {
			return $this->error( __( 'Failed to move uploaded file.', 'html-article-importer' ) );
		}

		try {
			return $this->process_zip( $tmp_path );
		} finally {
			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			if ( $wp_filesystem->exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
		}
	}


	private function move_to_temp( string $tmp_name ): string|false {
		$upload_dir = wp_upload_dir();
		$work_dir   = trailingslashit( $upload_dir['basedir'] ) . 'html-article-importer-tmp';

		if ( ! wp_mkdir_p( $work_dir ) ) {
			return false;
		}

		$dest = $work_dir . '/' . wp_unique_filename( $work_dir, 'import.zip' );

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem->move( $tmp_name, $dest, true ) ) {
			return false;
		}

		return $dest;
	}

	/**
	 * Extract ZIP, parse, build Elementor JSON, create post.
	 *
	 * @return array{success:bool,message:string,post_id?:int,post_url?:string,edit_url?:string}
	 */
	private function process_zip( string $zip_path ): array {
		$upload_dir = wp_upload_dir();
		$extract_to = trailingslashit( $upload_dir['basedir'] ) . 'html-article-importer-tmp/extract-' . uniqid( '', true );

		$zip = new \ZipArchive();
		$res = $zip->open( $zip_path );

		if ( true !== $res ) {
			return $this->error(
				sprintf(
					/* translators: %d: ZipArchive error code */
					__( 'Could not open ZIP file. ZipArchive error code: %d', 'html-article-importer' ),
					$res
				)
			);
		}

		if ( ! wp_mkdir_p( $extract_to ) ) {
			$zip->close();
			return $this->error( __( 'Could not create extraction directory.', 'html-article-importer' ) );
		}

		$zip->extractTo( $extract_to );
		$zip->close();

		try {
			$html_file = $this->find_file( $extract_to, 'article.html' );
			if ( ! $html_file ) {
				return $this->error( __( 'article.html not found inside the ZIP.', 'html-article-importer' ) );
			}

			$uploader = new ImageUploader();
			$images   = $uploader->upload_from_directory( $extract_to );

			$parser = new Parser();
			$blocks = $parser->parse( $html_file, $images );

			if ( empty( $blocks ) ) {
				return $this->error( __( 'No content blocks were parsed from article.html.', 'html-article-importer' ) );
			}

			$builder        = new ElementorBuilder();
			$elementor_data = $builder->build( $blocks, $images );

			$title = $this->extract_title( $blocks );

			$creator = new PostCreator();
			$post_id = $creator->create( $title, $elementor_data );

			if ( is_wp_error( $post_id ) ) {
				return $this->error( $post_id->get_error_message() );
			}

			return [
				'success'  => true,
				'message'  => sprintf(
					/* translators: %d: The ID of the newly created draft post */
					__( 'Article imported successfully! Draft post #%d created.', 'html-article-importer' ),
					$post_id
				),
				'post_id'  => $post_id,
				'post_url' => get_permalink( $post_id ),
				'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
			];
		} finally {
			$this->delete_directory( $extract_to );
		}
	}


	private function find_file( string $dir, string $filename ): string|null {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getFilename() === $filename ) {
				return $file->getPathname();
			}
		}

		return null;
	}


	private function extract_title( array $blocks ): string {
		foreach ( $blocks as $block ) {
			if ( 'heading' === $block['type'] && 'h1' === $block['level'] ) {
				return wp_strip_all_tags( $block['content'] );
			}
		}
		return __( 'Imported Article', 'html-article-importer' );
	}


	private function delete_directory( string $dir ): void {
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem->is_dir( $dir ) ) {
			return;
		}

		$wp_filesystem->delete( $dir, true );
	}


	private function error( string $message ): array {
		return [ 'success' => false, 'message' => $message ];
	}


	private function output_page( ?array $result ): void {
		?>
		<div class="wrap widgee-wrap">
			<h1><?php esc_html_e( 'HTML to Elementor', 'html-article-importer' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Upload a ZIP file containing article.html and images to generate an Elementor draft post automatically.', 'html-article-importer' ); ?></p>

			<?php if ( null !== $result ) : ?>
				<div class="notice <?php echo esc_attr( $result['success'] ? 'notice-success' : 'notice-error' ); ?> is-dismissible">
					<p><?php echo esc_html( $result['message'] ); ?></p>
					<?php if ( ! empty( $result['edit_url'] ) ) : ?>
						<p>
							<a href="<?php echo esc_url( $result['edit_url'] ); ?>" class="button button-primary" target="_blank">
								<?php esc_html_e( 'Edit in Elementor', 'html-article-importer' ); ?>
							</a>
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $result['post_id'] . '&action=edit' ) ); ?>" class="button" target="_blank">
								<?php esc_html_e( 'Edit in WordPress', 'html-article-importer' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="widgee-card">
				<h2><?php esc_html_e( 'Upload ZIP Package', 'html-article-importer' ); ?></h2>

				<form method="post" enctype="multipart/form-data" action="">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( self::UPLOAD_FIELD ); ?>">
									<?php esc_html_e( 'ZIP File', 'html-article-importer' ); ?>
								</label>
							</th>
							<td>
								<input
									type="file"
									id="<?php echo esc_attr( self::UPLOAD_FIELD ); ?>"
									name="<?php echo esc_attr( self::UPLOAD_FIELD ); ?>"
									accept=".zip,application/zip,application/x-zip-compressed"
									required
									class="regular-text"
								/>
								<p class="description">
									<?php esc_html_e( 'Upload a ZIP containing article.html and image files (webp, jpg, png, gif). Max size: 100 MB.', 'html-article-importer' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary button-hero">
							<span class="dashicons dashicons-upload" style="vertical-align:middle;margin-right:4px;"></span>
							<?php esc_html_e( 'Import Article', 'html-article-importer' ); ?>
						</button>
					</p>
				</form>
			</div>

			<div class="widgee-card widgee-instructions">
				<h2><?php esc_html_e( 'ZIP Package Format', 'html-article-importer' ); ?></h2>
				<pre>
article.zip
├── article.html
├── hero.webp
├── infographic.webp
└── image-1.webp
				</pre>
				<h3><?php esc_html_e( 'Supported content blocks in article.html', 'html-article-importer' ); ?></h3>
				<ul>
					<li><code>&lt;h1&gt;</code>, <code>&lt;h2&gt;</code>, <code>&lt;h3&gt;</code> — <?php esc_html_e( 'Headings', 'html-article-importer' ); ?></li>
					<li><code>&lt;p&gt;</code> — <?php esc_html_e( 'Paragraphs', 'html-article-importer' ); ?></li>
					<li><code>[[IMAGE:filename]]</code> — <?php esc_html_e( 'Image placeholder (without extension)', 'html-article-importer' ); ?></li>
					<li><code>&lt;div class="box"&gt;</code> — <?php esc_html_e( 'Highlighted box', 'html-article-importer' ); ?></li>
					<li><code>&lt;div class="faq"&gt;</code> — <?php esc_html_e( 'FAQ accordion', 'html-article-importer' ); ?></li>
					<li><code>&lt;table&gt;</code> — <?php esc_html_e( 'HTML table', 'html-article-importer' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}
