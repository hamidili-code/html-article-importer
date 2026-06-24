<?php
/**
 * Elementor Builder — converts parsed content blocks into valid Elementor page data.
 *
 * @package HtmlArticleImporter
 */

declare( strict_types=1 );

namespace HtmlArticleImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ElementorBuilder {

	/**
	 * Build Elementor JSON from parsed content blocks.
	 *
	 * @param  array $blocks Normalized blocks from Parser.
	 * @param  array $images Image map from ImageUploader.
	 * @return string JSON-encoded Elementor data.
	 */
	public function build( array $blocks, array $images ): string {
		$widgets = [];

		foreach ( $blocks as $block ) {
			$widget = $this->block_to_widget( $block, $images );

			if ( null !== $widget ) {
				$widgets[] = $widget;
			}
		}

		$root_container = $this->make_container( $widgets );
		$json           = wp_json_encode( [ $root_container ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return '[]';
		}

		return $json;
	}

	private function block_to_widget( array $block, array $images ): ?array {
		return match ( $block['type'] ?? '' ) {
			'heading' => $this->make_heading( $block ),
			'text'    => $this->make_text_editor( $block ),
			'image'   => $this->make_image( $block, $images ),
			'table'   => $this->make_table( $block ),
			'box'     => $this->make_box( $block ),
			'faq'     => $this->make_faq( $block ),
			default   => null,
		};
	}

	private function make_container( array $elements, array $extra_settings = [] ): array {
		return [
			'id'       => $this->unique_id(),
			'elType'   => 'container',
			'settings' => array_merge(
				[
					'flex_direction' => 'column',
					'content_width'  => 'full',
					'padding'        => [
						'unit'     => 'px',
						'top'      => '0',
						'right'    => '0',
						'bottom'   => '0',
						'left'     => '0',
						'isLinked' => false,
					],
				],
				$extra_settings
			),
			'elements' => $elements,
			'isInner'  => false,
		];
	}

	private function make_heading( array $block ): array {
		$level_map = [
			'h1' => 'h1',
			'h2' => 'h2',
			'h3' => 'h3',
			'h4' => 'h4',
			'h5' => 'h5',
			'h6' => 'h6',
		];

		$tag = $level_map[ $block['level'] ?? 'h2' ] ?? 'h2';

		return [
			'id'         => $this->unique_id(),
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => [
				'title'       => wp_kses_post( $block['content'] ?? '' ),
				'header_size' => $tag,
				'align'       => 'right',
			],
			'elements'   => [],
		];
	}

	private function make_text_editor( array $block ): array {
		return [
			'id'         => $this->unique_id(),
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'settings'   => [
				'editor' => wp_kses_post( $block['content'] ?? '' ),
			],
			'elements'   => [],
		];
	}

	private function make_image( array $block, array $images ): array {
		$key        = $block['image_key'] ?? '';
		$image_data = $images[ $key ] ?? ( $block['image'] ?? null );

		if ( ! empty( $image_data['url'] ) ) {
			$image_setting = [
				'url' => esc_url_raw( $image_data['url'] ),
				'id'  => (int) ( $image_data['id'] ?? 0 ),
			];
		} else {
			$image_setting = [
				'url' => '',
				'id'  => 0,
			];
		}

		return [
			'id'         => $this->unique_id(),
			'elType'     => 'widget',
			'widgetType' => 'image',
			'settings'   => [
				'image'        => $image_setting,
				'image_size'   => 'full',
				'align'        => 'center',
				'caption_type' => '',
			],
			'elements'   => [],
		];
	}

	private function make_table( array $block ): array {
		$html    = $block['content'] ?? '';
		$wrapped = '<div class="widgee-table-wrap" style="overflow-x:auto;">' . $html . '</div>';

		return [
			'id'         => $this->unique_id(),
			'elType'     => 'widget',
			'widgetType' => 'html',
			'settings'   => [
				'html' => $wrapped,
			],
			'elements'   => [],
		];
	}

	private function make_box( array $block ): array {
		return [
			'id'         => $this->unique_id(),
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'settings'   => [
				'editor'           => wp_kses_post( $block['content'] ?? '' ),
				'_css_classes'     => 'widgee-box',
				'background_color' => '#f0f7ff',
				'border_border'    => 'solid',
				'border_width'     => [
					'unit'     => 'px',
					'top'      => '0',
					'right'    => '0',
					'bottom'   => '0',
					'left'     => '4',
					'isLinked' => false,
				],
				'border_color'     => '#2563eb',
				'padding'          => [
					'unit'     => 'px',
					'top'      => '16',
					'right'    => '20',
					'bottom'   => '16',
					'left'     => '20',
					'isLinked' => false,
				],
			],
			'elements'   => [],
		];
	}

	private function make_faq( array $block ): array {
		$items = $block['items'] ?? [];

		if ( empty( $items ) ) {
			return $this->make_text_editor( [ 'type' => 'text', 'content' => '' ] );
		}

		$tabs = [];
		foreach ( $items as $item ) {
			$tabs[] = [
				'tab_title'   => sanitize_text_field( $item['question'] ?? '' ),
				'tab_content' => wp_kses_post( $item['answer'] ?? '' ),
				'_id'         => $this->unique_id(),
			];
		}

		return [
			'id'         => $this->unique_id(),
			'elType'     => 'widget',
			'widgetType' => 'nested-accordion',
			'settings'   => [
				'faq_schema'  => 'yes',
				'tabs'        => $tabs,
				'active_item' => 1,
			],
			'elements'   => [],
		];
	}

	private function unique_id(): string {
		return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
	}
}
