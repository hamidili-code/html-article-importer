<?php
/**
 * Parser — reads article.html and converts it to a normalized block array.
 *
 * @package HtmlArticleImporter
 */

declare( strict_types=1 );

namespace HtmlArticleImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Parser {

	/** Allowed image extensions. */
	private const IMAGE_EXTS = [ 'webp', 'jpg', 'jpeg', 'png', 'gif' ];

	/**
	 * Parse an article.html file and return structured content blocks.
	 *
	 * @param string $html_file Absolute path to article.html.
	 * @param array  $images    Image map from ImageUploader.
	 * @return array Structured content blocks.
	 */
	public function parse( string $html_file, array $images ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file, not a remote URL.
		$raw = file_get_contents( $html_file );
		if ( false === $raw ) {
			return [];
		}

		$raw = $this->pre_process_image_placeholders( $raw );
		$dom = $this->build_dom( $raw );

		if ( ! $dom ) {
			return [];
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return [];
		}

		return $this->collect_blocks( $body, $images );
	}

	private function pre_process_image_placeholders( string $html ): string {
		return preg_replace_callback(
			'/\[\[IMAGE:([a-zA-Z0-9_\-]+)\]\]/',
			fn( array $m ): string => '<hte-image-placeholder data-key="' . htmlspecialchars( $m[1], ENT_QUOTES ) . '"></hte-image-placeholder>',
			$html
		) ?? $html;
	}

	private function build_dom( string $html ): \DOMDocument|false {
		$dom = new \DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );

		$is_full_document = (bool) preg_match( '/<html[\s>]/i', $html );

		if ( $is_full_document ) {
			$loaded = $dom->loadHTML( $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		} else {
			$wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
			$loaded  = $dom->loadHTML( $wrapped, LIBXML_NOWARNING | LIBXML_NOERROR );
		}

		libxml_clear_errors();
		return $loaded ? $dom : false;
	}


	private function collect_blocks( \DOMNode $parent, array $images ): array {
		$blocks = [];

		foreach ( $parent->childNodes as $node ) {
			$result = $this->parse_node( $node, $images );

			if ( null === $result ) {
				continue;
			}

			if ( isset( $result['_multi'] ) && $result['_multi'] === true ) {
				foreach ( $result['blocks'] as $sub ) {
					if ( ! empty( $sub ) ) {
						$blocks[] = $sub;
					}
				}
			} else {
				$blocks[] = $result;
			}
		}

		return $blocks;
	}

	private function parse_node( \DOMNode $node, array $images ): array|null {
		if ( $node->nodeType !== XML_ELEMENT_NODE ) {
			return null;
		}

		/** @var \DOMElement $node */
		$tag = strtolower( $node->nodeName );

		return match ( true ) {
			in_array( $tag, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) => $this->parse_heading( $node ),
			'p'                     === $tag => $this->parse_paragraph( $node, $images ),
			'img'                   === $tag => $this->parse_img( $node, $images ),
			'hte-image-placeholder' === $tag => $this->parse_image_placeholder( $node, $images ),
			'table'                 === $tag => $this->parse_table( $node ),
			'div'                   === $tag => $this->parse_div( $node, $images ),
			default                          => null,
		};
	}

	private function parse_heading( \DOMElement $node ): array {
		return [
			'type'    => 'heading',
			'level'   => strtolower( $node->nodeName ),
			'content' => $this->inner_html( $node ),
		];
	}

	private function parse_paragraph( \DOMElement $node, array $images ): array|null {
		if ( '' === trim( $node->textContent ) && ! $this->node_has_images( $node ) ) {
			return null;
		}

		foreach ( $node->childNodes as $child ) {
			if ( $child->nodeType !== XML_ELEMENT_NODE ) {
				continue;
			}
			$ctag = strtolower( $child->nodeName );
			if ( 'hte-image-placeholder' === $ctag ) {
				/** @var \DOMElement $child */
				return $this->parse_image_placeholder( $child, $images );
			}
			if ( 'img' === $ctag ) {
				/** @var \DOMElement $child */
				return $this->parse_img( $child, $images );
			}
		}

		return [
			'type'    => 'text',
			'content' => $this->inner_html( $node ),
		];
	}

	private function parse_img( \DOMElement $node, array $images ): array|null {
		$src = $node->getAttribute( 'src' );
		if ( '' === $src ) {
			return null;
		}

		$filename   = pathinfo( basename( $src ), PATHINFO_FILENAME );
		$image_data = $images[ $filename ] ?? null;

		return [
			'type'      => 'image',
			'image_key' => $filename,
			'image'     => $image_data,
		];
	}

	private function parse_image_placeholder( \DOMElement $node, array $images ): array|null {
		$key = $node->getAttribute( 'data-key' );
		if ( '' === $key ) {
			return null;
		}

		return [
			'type'      => 'image',
			'image_key' => $key,
			'image'     => $images[ $key ] ?? null,
		];
	}

	private function parse_table( \DOMElement $node ): array {
		return [
			'type'    => 'table',
			'content' => $this->outer_html( $node ),
		];
	}

	private function parse_div( \DOMElement $node, array $images ): array|null {
		$class   = $node->getAttribute( 'class' );
		$classes = array_filter( array_map( 'trim', explode( ' ', $class ) ) );

		if ( in_array( 'faq', $classes, true ) ) {
			return $this->parse_faq( $node );
		}

		if ( in_array( 'box', $classes, true ) ) {
			return $this->parse_box( $node );
		}

		$child_blocks = $this->collect_blocks( $node, $images );

		if ( empty( $child_blocks ) ) {
			return null;
		}

		return [
			'_multi' => true,
			'blocks' => $child_blocks,
		];
	}

	private function parse_faq( \DOMElement $node ): array {
		$items       = [];
		$found_items = false;

		foreach ( $node->getElementsByTagName( '*' ) as $child ) {
			if ( $child->nodeType !== XML_ELEMENT_NODE ) {
				continue;
			}
			$child_classes = array_filter( array_map( 'trim', explode( ' ', $child->getAttribute( 'class' ) ) ) );
			if ( ! in_array( 'faq-item', $child_classes, true ) ) {
				continue;
			}

			$found_items = true;
			$question    = '';
			$answer      = '';

			foreach ( $child->childNodes as $grandchild ) {
				if ( $grandchild->nodeType !== XML_ELEMENT_NODE ) {
					continue;
				}
				$gtag = strtolower( $grandchild->nodeName );
				if ( in_array( $gtag, [ 'h2', 'h3', 'h4', 'dt', 'strong' ], true ) && '' === $question ) {
					$question = trim( $grandchild->textContent );
				} elseif ( in_array( $gtag, [ 'p', 'dd', 'div' ], true ) && '' === $answer ) {
					$answer = $this->inner_html( $grandchild );
				}
			}

			if ( $question ) {
				$items[] = [ 'question' => $question, 'answer' => $answer ];
			}
		}

		if ( ! $found_items ) {
			$pending_question = null;
			foreach ( $node->childNodes as $child ) {
				if ( $child->nodeType !== XML_ELEMENT_NODE ) {
					continue;
				}
				$ctag = strtolower( $child->nodeName );
				if ( in_array( $ctag, [ 'h2', 'h3', 'h4', 'dt', 'strong' ], true ) ) {
					$pending_question = trim( $child->textContent );
				} elseif ( in_array( $ctag, [ 'p', 'dd' ], true ) && null !== $pending_question ) {
					$items[]          = [ 'question' => $pending_question, 'answer' => $this->inner_html( $child ) ];
					$pending_question = null;
				}
			}
		}

		return [
			'type'  => 'faq',
			'items' => $items,
		];
	}

	private function parse_box( \DOMElement $node ): array {
		return [
			'type'    => 'box',
			'content' => $this->inner_html( $node ),
		];
	}

	private function inner_html( \DOMElement $node ): string {
		$html = '';
		$doc  = $node->ownerDocument;
		foreach ( $node->childNodes as $child ) {
			$html .= $doc->saveHTML( $child );
		}
		return trim( $html );
	}

	private function outer_html( \DOMElement $node ): string {
		return trim( $node->ownerDocument->saveHTML( $node ) );
	}

	private function node_has_images( \DOMNode $node ): bool {
		foreach ( $node->childNodes as $child ) {
			if ( $child->nodeType !== XML_ELEMENT_NODE ) {
				continue;
			}
			if ( in_array( strtolower( $child->nodeName ), [ 'img', 'hte-image-placeholder' ], true ) ) {
				return true;
			}
		}
		return false;
	}
}
