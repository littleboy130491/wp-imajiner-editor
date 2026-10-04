<?php
/**
 * Reads an Imajiner template into a structure the editor can display.
 *
 * A template is HTML mixed with PHP. Each PHP block is swapped for a
 * placeholder so the rest is plain HTML that the WordPress HTML API can walk:
 *
 * - in text position:           <!--imj-php:N-->
 * - inside a tag (attributes):  {{imj-php:N}}
 *
 * Placeholders are swapped back for the original PHP byte-for-byte, so any
 * change made through the HTML API leaves the PHP untouched.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

/** Exposes the current token's byte span to the scanner. */
class Imajiner_Source_Processor extends WP_HTML_Tag_Processor {
	/** @return int[] Start and end byte offsets. */
	public function span() {
		$this->set_bookmark( 'imajiner-span' );
		$span = $this->bookmarks['imajiner-span'];
		return array( 'start' => $span->start, 'end' => $span->start + $span->length );
	}
}

/** Reads and safely changes the editable structure of a template. */
class Imajiner_Template_Scanner {

	/**
	 * Elements that never have children.
	 */
	const VOID_TAGS = array( 'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'SOURCE', 'TRACK', 'WBR' );

	/**
	 * Elements whose whole content the HTML API returns as a single token.
	 */
	const RAW_TEXT_TAGS = array( 'SCRIPT', 'STYLE', 'TEXTAREA', 'TITLE', 'IFRAME', 'NOEMBED', 'NOFRAMES', 'XMP' );

	/**
	 * Functions that only escape or translate. Skipped when naming a dynamic value.
	 */
	const WRAPPER_FUNCTIONS = array( 'esc_html', 'esc_attr', 'esc_url', 'esc_textarea', 'esc_js', 'wp_kses', 'wp_kses_post', '__', '_x', 'esc_html__', 'esc_attr__', 'absint', 'intval', 'sprintf', 'printf', 'wpautop', 'do_shortcode' );

	/**
	 * Functions that print output without an explicit echo.
	 */
	const OUTPUT_FUNCTIONS = array( 'bloginfo', 'body_class', 'post_class', 'wp_nav_menu', 'comments_template', 'get_search_form', 'wp_link_pages', 'printf', '_e', 'esc_html_e', 'esc_attr_e', 'wp_head', 'wp_footer', 'wp_body_open' );

	/**
	 * Original template source.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Source with every PHP block replaced by a placeholder.
	 *
	 * @var string
	 */
	private $masked = '';

	private $spans = array();

	/**
	 * PHP blocks, indexed by placeholder number.
	 *
	 * @var array[]
	 */
	private $php = array();

	/**
	 * Template contract violations found while scanning.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Whether a missing <!-- imj:section --> marker is reported. Parts don't need sections.
	 *
	 * @var bool
	 */
	private $require_sections;

	/**
	 * @param string $source  Template PHP source.
	 * @param array  $options Optional. require_sections (default true): warn when the template has no section markers.
	 */
	public function __construct( $source, array $options = array() ) {
		$this->source           = $source;
		$this->require_sections = ! isset( $options['require_sections'] ) || $options['require_sections'];
		$this->mask();
	}

	/**
	 * Returns the template as a tree of sections, elements, text and PHP blocks.
	 *
	 * Element ids (e0, e1, ...) number tag openers in document order, the same
	 * numbering get_instrumented_source() writes into data-imj-id.
	 *
	 * @return array {
	 *     @type object[] $tree     Top-level nodes.
	 *     @type array[]  $php      PHP block details, indexed by placeholder number.
	 *     @type string[] $warnings Template contract violations.
	 * }
	 */
	public function get_structure() {
		$root      = (object) array(
			'type'     => 'root',
			'children' => array(),
		);
		$stack     = array( $root );
		$elements  = 0;
		$texts     = 0;
		$sections  = 0;
		$processor = new Imajiner_Source_Processor( $this->masked );
		$this->spans = array();

		while ( $processor->next_token() ) {
			$parent = end( $stack );
			$span   = $processor->span();

			switch ( $processor->get_token_type() ) {
				case '#tag':
					$tag = $processor->get_tag();

					if ( $processor->is_tag_closer() ) {
						foreach ( array_reverse( $stack ) as $open ) {
							if ( 'element' === $open->type && strtoupper( $open->tag ) === $tag ) {
								$this->spans[ $open->id ]['end'] = $span['end'];
								$this->spans[ $open->id ]['inside'] = $span['start'];
								break;
							}
						}
						$this->pop_to(
							$stack,
							function ( $node ) use ( $tag ) {
								return 'element' === $node->type && strtoupper( $node->tag ) === $tag;
							},
							'</' . strtolower( $tag ) . '>'
						);
						break;
					}

					$node = (object) array(
						'type'     => 'element',
						'id'       => 'e' . $elements++,
						'tag'      => strtolower( $tag ),
						'attrs'    => $this->read_attributes( $processor ),
						'children' => array(),
					);

					if ( in_array( $tag, self::RAW_TEXT_TAGS, true ) ) {
						$node->text = $processor->get_modifiable_text();
					}

					$parent->children[] = $node;

					$is_leaf = in_array( $tag, self::VOID_TAGS, true ) || in_array( $tag, self::RAW_TEXT_TAGS, true ) || $processor->has_self_closing_flag();
					$this->spans[ $node->id ] = array_merge( $span, array( 'inside' => null, 'parent' => isset( $parent->id ) ? $parent->id : null ) );
					if ( ! $is_leaf ) {
						$stack[] = $node;
					}
					break;

				case '#text':
					$text = $processor->get_modifiable_text();
					if ( '' === trim( $text ) ) {
						break;
					}
					$parent->children[] = (object) array(
						'type' => 'text',
						'id'   => 't' . $texts++,
						'text' => $text,
					);
					break;

				case '#comment':
					$comment = trim( $processor->get_modifiable_text() );

					if ( preg_match( '/^imj-php:(\d+)$/', $comment, $match ) ) {
						$this->place_php_block( $stack, (int) $match[1] );
					} elseif ( preg_match( '/^imj:section\s+name="([^"]*)"$/', $comment, $match ) ) {
						$node               = (object) array(
							'type'     => 'section',
							'id'       => 's' . $sections++,
							'name'     => $match[1],
							'children' => array(),
						);
						$parent->children[] = $node;
						$this->spans[ $node->id ] = array_merge( $span, array( 'inside' => null, 'parent' => isset( $parent->id ) ? $parent->id : null ) );
						$stack[]            = $node;
					} elseif ( '/imj:section' === $comment ) {
						foreach ( array_reverse( $stack ) as $open ) {
							if ( 'section' === $open->type ) {
								$this->spans[ $open->id ]['end'] = $span['end'];
								$this->spans[ $open->id ]['inside'] = $span['start'];
								break;
							}
						}
						$this->pop_to(
							$stack,
							function ( $node ) {
								return 'section' === $node->type;
							},
							'<!-- /imj:section -->'
						);
					}
					break;
			}
		}

		foreach ( array_slice( $stack, 1 ) as $node ) {
			$this->warnings[] = sprintf( '%s is never closed.', $this->describe( $node ) );
		}

		if ( 0 === $sections && $this->require_sections ) {
			$this->warnings[] = 'No <!-- imj:section --> markers found.';
		}
		$this->mark_mutable( $root->children );

		return array(
			'tree'     => $root->children,
			'php'      => $this->php_for_output(),
			'warnings' => array_values( array_unique( $this->warnings ) ),
		);
	}

	/**
	 * Returns the template with a data-imj-id attribute on every element.
	 *
	 * Used to render the editor preview, so a click in the preview maps back
	 * to a node in the structure.
	 *
	 * @return string PHP source.
	 */
	public function get_instrumented_source() {
		$processor = new Imajiner_Source_Processor( $this->masked );
		$elements  = 0;
		$texts = 0;
		$markers = array();
		$prefix = self::text_marker_prefix( $this->masked );

		while ( $processor->next_token() ) {
			if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
				$processor->set_attribute( 'data-imj-id', 'e' . $elements++ );
			} elseif ( '#text' === $processor->get_token_type() && '' !== trim( $processor->get_modifiable_text() ) ) {
				$key = $prefix . $texts;
				$markers[ $key ] = '<!--imj-text:t' . $texts . '-->' . substr( $this->masked, $processor->span()['start'], $processor->span()['end'] - $processor->span()['start'] ) . '<!--/imj-text:t' . $texts++ . '-->';
				$processor->set_modifiable_text( $key );
			}
		}

		return $this->unmask( strtr( $processor->get_updated_html(), $markers ) );
	}

	private static function text_marker_prefix( $source ) {
		$prefix = 'IMAJINER_TEXT_MARKER_';
		while ( false !== strpos( $source, $prefix ) ) {
			$prefix .= '_';
		}
		return $prefix;
	}

	/**
	 * Applies editor changes to static HTML and returns the new template source.
	 *
	 * Changes address nodes by the ids from get_structure():
	 * - array( 'type' => 'text', 'id' => 't3', 'value' => 'New text' )
	 * - array( 'type' => 'attr', 'id' => 'e4', 'name' => 'href', 'value' => '/about/' ), value null removes it
	 *
	 * PHP blocks are placeholders while the HTML is edited, so they come back unchanged.
	 *
	 * @param array[] $changes Changes.
	 * @return string|WP_Error New source, or an error if a change is invalid.
	 */
	public function apply_changes( array $changes ) {
		$by_id = array();
		$marker_source = $this->masked;
		foreach ( $changes as $change ) {
			$error = $this->validate_change( $change );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			$by_id[ $change['id'] ][] = $change;
			if ( is_string( $change['value'] ) ) {
				$marker_source .= $change['value'];
			}
		}
		$prefix = self::text_marker_prefix( $marker_source );

		$processor = new WP_HTML_Tag_Processor( $this->masked );
		$elements  = 0;
		$texts     = 0;
		$applied   = 0;
		$new_texts = array();

		while ( $processor->next_token() ) {
			$type = $processor->get_token_type();

			if ( '#tag' === $type && ! $processor->is_tag_closer() ) {
				$id = 'e' . $elements++;
				foreach ( isset( $by_id[ $id ] ) ? $by_id[ $id ] : array() as $change ) {
					$result = $this->apply_attribute_change( $processor, $change );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					++$applied;
				}
			} elseif ( '#text' === $type ) {
				$text = $processor->get_modifiable_text();
				if ( '' === trim( $text ) ) {
					continue;
				}
				$id = 't' . $texts++;
				if ( ! isset( $by_id[ $id ] ) ) {
					continue;
				}

				// Keep the whitespace around the text so the source layout doesn't change.
				preg_match( '/^(\s*).*?(\s*)$/su', $text, $space );
				$change = end( $by_id[ $id ] );
				$key    = $prefix . count( $new_texts );

				// The HTML API escapes quotes in text as &quot;/&apos;. Write a marker
				// instead and swap in text escaped only where HTML requires it.
				$new_texts[ $key ] = $space[1] . strtr(
					$change['value'],
					array(
						'&' => '&amp;',
						'<' => '&lt;',
						'>' => '&gt;',
					)
				) . $space[2];
				$processor->set_modifiable_text( $key );
				$applied += count( $by_id[ $id ] );
			}
		}

		if ( count( $changes ) !== $applied ) {
			return new WP_Error( 'imajiner_unknown_node', __( 'Some changes point to elements that are not in the template. Reload the editor and try again.', 'imajiner-editor' ) );
		}

		$html = strtr( $processor->get_updated_html(), $new_texts );
		// Remove only the whitespace left by attribute removal on the edited tag.
		$cleanup = new Imajiner_Source_Processor( $html );
		$edits = array();
		$number = 0;
		while ( $cleanup->next_tag() ) {
			$id = 'e' . $number++;
			foreach ( isset( $by_id[ $id ] ) ? $by_id[ $id ] : array() as $change ) {
				if ( 'attr' === $change['type'] && null === $change['value'] ) {
					$span = $cleanup->span();
					$tag = substr( $html, $span['start'], $span['end'] - $span['start'] );
					$edits[] = array( $span, preg_replace( '/\s+(\/?>)$/', '$1', $tag ) );
					break;
				}
			}
		}
		foreach ( array_reverse( $edits ) as $edit ) {
			$html = substr_replace( $html, $edit[1], $edit[0]['start'], $edit[0]['end'] - $edit[0]['start'] );
		}
		return $this->unmask( $html );
	}

	/** Original selected source, including PHP, for read-only inspection. */
	public function get_node_source( $id ) {
		if ( ! is_string( $id ) ) {
			return new WP_Error( 'imajiner_unknown_node', __( 'Unknown selected node.', 'imajiner-editor' ) );
		}
		$this->get_structure();
		if ( preg_match( '/^p(\d+)$/', $id, $match ) && isset( $this->php[ (int) $match[1] ] ) ) {
			return $this->php[ (int) $match[1] ]['source'];
		}
		if ( ! isset( $this->spans[ $id ] ) ) {
			return new WP_Error( 'imajiner_unknown_node', __( 'Unknown selected node.', 'imajiner-editor' ) );
		}
		$span = $this->spans[ $id ];
		return $this->unmask( substr( $this->masked, $span['start'], $span['end'] - $span['start'] ) );
	}

	/** Replace a static selection with literal, safe markup; never accept PHP. */
	public function replace_node( $id, $markup ) {
		$source = $this->get_node_source( $id );
		$invalid = new WP_Error( 'imajiner_replacement', __( 'Only static elements or sections can be replaced with safe literal HTML.', 'imajiner-editor' ) );
		if ( is_wp_error( $source ) || ! preg_match( '/^[es]\d+$/', $id ) || ! is_string( $markup ) || ! trim( $markup ) || strlen( $markup ) > 262144 || preg_match( '/<\?|imj-php:|data-imj-|imj-text:/i', $markup ) || false !== strpos( $source, '<?' ) || wp_kses_post( $markup ) !== $markup ) {
			return $invalid;
		}
		$span = $this->spans[ $id ];
		$new = $this->unmask( substr_replace( $this->masked, $markup, $span['start'], $span['end'] - $span['start'] ) );
		$check = new self( $new, array( 'require_sections' => $this->require_sections ) );
		if ( $check->get_structure()['warnings'] || $check->get_php_sources() !== $this->get_php_sources() || ! $check->is_lossless() ) {
			return $invalid;
		}
		return $new;
	}

	/** Explicit source replacement is restricted to isolated text-output calls. */
	public function change_source( $id, $source, $field = '' ) {
		$invalid = new WP_Error( 'imajiner_dynamic_source', __( 'This PHP value is not an allowlisted text source.', 'imajiner-editor' ) );
		if ( ! is_string( $id ) || ! preg_match( '/^p(\d+)$/', $id, $match ) || ! isset( $this->php[ (int) $match[1] ] ) ) {
			return $invalid;
		}
		if ( 'acf' === $source && ! function_exists( 'get_field' ) ) {
			return new WP_Error( 'imajiner_acf_missing', __( 'Install ACF before choosing an ACF source.', 'imajiner-editor' ) );
		}
		$block = $this->php[ (int) $match[1] ];
		$allowed = '/^\s*(?:the_title\(\s*\)|echo\s+esc_html\(\s*(?:get_the_title\(\s*\)|get_post_meta\(\s*get_the_ID\(\s*\)\s*,\s*\x27[a-zA-Z0-9_-]+\x27\s*,\s*true\s*\)|get_field\(\s*\x27[a-zA-Z0-9_-]+\x27\s*\))\s*\))\s*;\s*$/';
		if ( ! preg_match( $allowed, $block['code'] ) || false === strpos( $this->masked, '<!--imj-php:' . $match[1] . '-->' ) ) {
			return $invalid;
		}
		if ( 'title' === $source ) {
			$code = '<?php echo esc_html( get_the_title() ); ?>';
		} elseif ( in_array( $source, array( 'custom-field', 'acf' ), true ) && is_string( $field ) && preg_match( '/^[a-zA-Z0-9_-]{1,100}$/', $field ) ) {
			$code = 'acf' === $source ? "<?php echo esc_html( get_field( '" . $field . "' ) ); ?>" : "<?php echo esc_html( get_post_meta( get_the_ID(), '" . $field . "', true ) ); ?>";
		} else {
			return $invalid;
		}
		$masked = str_replace( '<!--imj-php:' . $match[1] . '-->', $code, $this->masked );
		return $this->unmask( $masked );
	}

	private function mark_mutable( array $nodes ) {
		$group = 0;
		foreach ( $nodes as $node ) {
			$barrier = ! isset( $this->spans[ $node->id ] );
			if ( isset( $this->spans[ $node->id ] ) ) {
				$span = $this->spans[ $node->id ];
				$html = substr( $this->masked, $span['start'], $span['end'] - $span['start'] );
				$node->mutable = false === strpos( $html, 'imj-php:' );
				$node->container = null !== $span['inside'];
				$barrier = ! $node->mutable;
			}
			if ( $barrier ) {
				++$group;
			}
			if ( isset( $this->spans[ $node->id ] ) ) {
				$node->group = $group;
				$this->spans[ $node->id ]['group'] = $group;
			}
			if ( isset( $node->children ) ) {
				$this->mark_mutable( $node->children );
			}
			if ( $barrier ) {
				++$group;
			}
		}
	}

	/**
	 * Edits literal HTML ranges. PHP-containing subtrees stay locked.
	 *
	 * @param array $change Structural operation with type, id, target, position or starter.
	 * @return string|WP_Error
	 */
	public function apply_structure( array $change ) {
		$structure = $this->get_structure();
		$invalid = new WP_Error( 'imajiner_structure', __( 'This structural edit is not allowed. PHP blocks stay locked, and moves must stay within the same parent.', 'imajiner-editor' ) );
		if ( $structure['warnings'] || ! isset( $change['type'] ) || ! is_string( $change['type'] ) ) {
			return $invalid;
		}
		$type = $change['type'];
		if ( 'replace' === $type ) {
			return $this->replace_node( isset( $change['id'] ) ? $change['id'] : '', isset( $change['markup'] ) ? $change['markup'] : null );
		}
		$id = isset( $change['id'] ) && is_string( $change['id'] ) ? $change['id'] : '';
		$span = isset( $this->spans[ $id ] ) ? $this->spans[ $id ] : null;
		$html = $span ? substr( $this->masked, $span['start'], $span['end'] - $span['start'] ) : '';
		if ( 'insert' !== $type && ( ! $span || false !== strpos( $html, 'imj-php:' ) ) ) {
			return $invalid;
		}
		$new = $this->masked;
		if ( 'delete' === $type ) {
			$new = substr_replace( $new, '', $span['start'], $span['end'] - $span['start'] );
		} elseif ( 'duplicate' === $type ) {
			$new = substr_replace( $new, "\n" . $html, $span['end'], 0 );
		} elseif ( 'insert' === $type || 'move' === $type ) {
			$target = isset( $change['target'] ) && is_string( $change['target'] ) ? $change['target'] : '';
			$position = isset( $change['position'] ) ? $change['position'] : 'inside';
			$destination = isset( $this->spans[ $target ] ) ? $this->spans[ $target ] : null;
			if ( 'root' === $target && 'insert' === $type && 'inside' === $position ) {
				$at = strlen( $new );
				if ( $this->require_sections ) {
					$at = null;
					foreach ( $this->php as $number => $block ) {
						$offset = strpos( $new, '<!--imj-php:' . $number . '-->' );
						if ( 'Site footer' === $block['label'] && false !== $offset ) {
							$at = $offset;
							break;
						}
					}
					if ( null === $at ) {
						return $invalid;
					}
				}
			} elseif ( $destination && 'inside' === $position && null !== $destination['inside'] ) {
				$at = $destination['inside'];
			} elseif ( $destination && in_array( $position, array( 'before', 'after' ), true ) ) {
				$at = 'before' === $position ? $destination['start'] : $destination['end'];
			} else {
				return $invalid;
			}
			if ( 'move' === $type ) {
				if ( ! $destination || ! in_array( $position, array( 'before', 'after' ), true ) || $id === $target || $span['parent'] !== $destination['parent'] || $span['group'] !== $destination['group'] ) {
					return $invalid;
				}
				$new = substr_replace( $new, '', $span['start'], $span['end'] - $span['start'] );
				if ( $at >= $span['end'] ) {
					$at -= $span['end'] - $span['start'];
				}
			} else {
				$starters = self::element_starters();
				$starter = isset( $change['starter'] ) && is_string( $change['starter'] ) ? $change['starter'] : '';
				if ( ! isset( $starters[ $starter ] ) || ( $this->require_sections && 'root' === $target && 'section' !== $starter ) ) {
					return $invalid;
				}
				$html = $starters[ $starter ];
			}
			$new = substr_replace( $new, "\n" . $html . "\n", $at, 0 );
		} else {
			return $invalid;
		}
		$new = $this->unmask( $new );
		$check = new self( $new, array( 'require_sections' => $this->require_sections ) );
		if ( $check->get_php_sources() !== $this->get_php_sources() || $check->get_structure()['warnings'] || ! $check->is_lossless() ) {
			return $invalid;
		}
		return $new;
	}

	public static function element_starters() {
		return array(
			'heading' => '<h2>New heading</h2>',
			'paragraph' => '<p>New paragraph</p>',
			'link' => '<a href="#">New link</a>',
			'image' => '<img src="" alt="New image">',
			'div' => '<div><p>New content</p></div>',
			'section' => '<!-- imj:section name="new-section" -->' . "\n" . '<section class="section"><div class="container"><h2>New section</h2><p>Add your content here.</p></div></section>' . "\n" . '<!-- /imj:section -->',
		);
	}

	/**
	 * Source of every PHP block, in order. Used to prove an edit left PHP untouched.
	 *
	 * @return string[]
	 */
	public function get_php_sources() {
		return array_column( $this->php, 'source' );
	}

	/**
	 * Whether unmasking the masked source gives back the original exactly.
	 *
	 * @return bool
	 */
	public function is_lossless() {
		return $this->unmask( $this->masked ) === $this->source;
	}

	/**
	 * Checks a change's shape before it is applied.
	 *
	 * @param mixed $change Change from the editor.
	 * @return true|WP_Error
	 */
	private function validate_change( $change ) {
		$invalid = new WP_Error( 'imajiner_invalid_change', __( 'Invalid change.', 'imajiner-editor' ) );

		if ( ! is_array( $change ) || ! isset( $change['type'], $change['id'] ) || ! is_string( $change['id'] ) ) {
			return $invalid;
		}

		$value = isset( $change['value'] ) ? $change['value'] : null;

		// Placeholders are swapped for PHP on save, so they must never come from input.
		if ( is_string( $value ) && false !== strpos( $value, 'imj-php:' ) ) {
			return $invalid;
		}

		if ( 'text' === $change['type'] ) {
			if ( ! preg_match( '/^t\d+$/', $change['id'] ) || ! is_string( $value ) ) {
				return $invalid;
			}
			// Empty text would drop the node and shift the ids of every node after it.
			if ( '' === trim( $value ) ) {
				return new WP_Error( 'imajiner_empty_text', __( 'Text cannot be empty.', 'imajiner-editor' ) );
			}
			return true;
		}

		if ( 'attr' === $change['type'] ) {
			$name = isset( $change['name'] ) ? $change['name'] : '';
			if (
				! preg_match( '/^e\d+$/', $change['id'] ) ||
				! is_string( $name ) ||
				! preg_match( '/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $name ) ||
				0 === stripos( $name, 'data-imj-' ) ||
				( null !== $value && ! is_string( $value ) )
			) {
				return $invalid;
			}
			return true;
		}

		return $invalid;
	}

	/**
	 * Sets or removes an attribute on the element the processor is on.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor matched on the element.
	 * @param array                 $change    Attribute change.
	 * @return true|WP_Error
	 */
	private function apply_attribute_change( WP_HTML_Tag_Processor $processor, array $change ) {
		$current = $processor->get_attribute( $change['name'] );
		if ( is_string( $current ) && false !== strpos( $current, '{{imj-php:' ) ) {
			return new WP_Error(
				'imajiner_dynamic_attribute',
				/* translators: %s: attribute name. */
				sprintf( __( 'The %s attribute is set by PHP and cannot be edited here.', 'imajiner-editor' ), $change['name'] )
			);
		}

		if ( null === $change['value'] ) {
			$processor->remove_attribute( $change['name'] );
		} else {
			$processor->set_attribute( $change['name'], $change['value'] );
		}
		return true;
	}

	/**
	 * Splits the source into HTML and PHP blocks and builds the masked source.
	 */
	private function mask() {
		$context = 'text';
		$quote   = null;
		$block   = null;

		foreach ( token_get_all( $this->source ) as $token ) {
			$id   = is_array( $token ) ? $token[0] : null;
			$text = is_array( $token ) ? $token[1] : $token;

			if ( null === $block ) {
				if ( T_INLINE_HTML === $id ) {
					$this->masked .= $text;
					$this->advance_context( $text, $context, $quote );
					continue;
				}

				// Outside a PHP block, anything but inline HTML is an opening tag.
				$block = array(
					'source'  => '',
					'tokens'  => array(),
					'context' => $context,
					'quoted'  => null !== $quote,
				);
			}

			$block['source'] .= $text;

			if ( T_CLOSE_TAG === $id ) {
				$this->add_php_block( $block );
				$block = null;
			} elseif ( T_OPEN_TAG_WITH_ECHO === $id ) {
				$block['tokens'][] = array( T_ECHO, 'echo' );
			} elseif ( T_OPEN_TAG !== $id ) {
				$block['tokens'][] = $token;
			}
		}

		// A template may end inside PHP without a closing tag.
		if ( null !== $block ) {
			$this->add_php_block( $block );
		}
	}

	/**
	 * Stores a PHP block and appends its placeholder to the masked source.
	 *
	 * @param array $block Block collected by mask().
	 */
	private function add_php_block( array $block ) {
		$number = count( $this->php );

		$this->php[ $number ] = array_merge(
			array(
				'source'  => $block['source'],
				'context' => 'text' === $block['context'] ? 'text' : 'attribute',
			),
			$this->classify( $block['tokens'] )
		);

		if ( 'text' === $block['context'] ) {
			$this->masked .= '<!--imj-php:' . $number . '-->';
			return;
		}

		if ( 'tag' === $block['context'] && ! $block['quoted'] ) {
			$this->warnings[] = sprintf( 'PHP outside a quoted attribute value: %s', $this->php[ $number ]['code'] );
		}

		// Inside a tag or an HTML comment, a comment placeholder would break the markup.
		$this->masked .= '{{imj-php:' . $number . '}}';
	}

	/**
	 * Swaps placeholders back for the original PHP.
	 *
	 * @param string $html Masked source.
	 * @return string
	 */
	private function unmask( $html ) {
		return preg_replace_callback(
			'/<!--imj-php:(\d+)-->|\{\{imj-php:(\d+)\}\}/',
			function ( $match ) {
				$number = (int) ( '' !== $match[1] ? $match[1] : $match[2] );
				return $this->php[ $number ]['source'];
			},
			$html
		);
	}

	/**
	 * Tracks whether the HTML read so far leaves us in text, inside a tag or inside a comment.
	 *
	 * @param string      $html    Inline HTML chunk.
	 * @param string      $context 'text', 'tag' or 'comment'. Updated in place.
	 * @param string|null $quote   Open attribute quote character inside a tag. Updated in place.
	 */
	private function advance_context( $html, &$context, &$quote ) {
		$length = strlen( $html );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $html[ $i ];

			if ( 'comment' === $context ) {
				if ( '-->' === substr( $html, $i, 3 ) ) {
					$context = 'text';
					$i      += 2;
				}
			} elseif ( 'tag' === $context ) {
				if ( null !== $quote ) {
					if ( $char === $quote ) {
						$quote = null;
					}
				} elseif ( '"' === $char || "'" === $char ) {
					$quote = $char;
				} elseif ( '>' === $char ) {
					$context = 'text';
				}
			} elseif ( '<' === $char ) {
				if ( '<!--' === substr( $html, $i, 4 ) ) {
					$context = 'comment';
					$i      += 3;
				} elseif ( $i + 1 < $length && preg_match( '/[a-zA-Z\/!?]/', $html[ $i + 1 ] ) ) {
					$context = 'tag';
				}
			}
		}
	}

	/**
	 * Works out what a PHP block does: opens or closes a loop/condition, prints a value, etc.
	 *
	 * @param array $tokens Tokens between the PHP open and close tags.
	 * @return array {
	 *     @type string $role   'open', 'close', 'mid' (else/elseif) or 'leaf'.
	 *     @type int    $opens  Number of blocks opened.
	 *     @type int    $closes Number of blocks closed.
	 *     @type string $kind   loop, condition, end, template, partial, query, dynamic or code.
	 *     @type string $label  Short name for the editor.
	 *     @type string $detail Extra context, e.g. a loop condition.
	 *     @type string $code   The PHP code, without open/close tags.
	 * }
	 */
	private function classify( array $tokens ) {
		$depth    = 0;
		$lowest   = 0;
		$keyword  = null;
		$detail   = '';
		$calls    = array();
		$echoes   = false;
		$query    = false;
		$count    = count( $tokens );
		$methods  = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ? T_NULLSAFE_OBJECT_OPERATOR : -1 );
		$controls = array( T_WHILE, T_FOREACH, T_FOR, T_IF, T_ELSEIF, T_SWITCH );

		for ( $i = 0; $i < $count; $i++ ) {
			$id = $this->token_id( $tokens[ $i ] );

			if ( in_array( $id, $controls, true ) ) {
				$open  = $this->next_significant( $tokens, $i + 1 );
				$close = null !== $open && '(' === $tokens[ $open ] ? $this->matching_paren( $tokens, $open ) : null;
				$after = null !== $close ? $this->next_significant( $tokens, $close + 1 ) : null;
				$next  = null !== $after ? $tokens[ $after ] : null;

				if ( ':' === $next ) {
					if ( T_ELSEIF === $id ) {
						$lowest = min( $lowest, --$depth );
					}
					++$depth;
				}
				if ( ( ':' === $next || '{' === $next ) && null === $keyword ) {
					$keyword = strtolower( $tokens[ $i ][1] );
					$detail  = trim( $this->join( $tokens, $open + 1, $close ) );
				}
				continue;
			}

			switch ( $id ) {
				case T_ELSE:
					$after = $this->next_significant( $tokens, $i + 1 );
					$next  = null !== $after ? $tokens[ $after ] : null;
					if ( ':' === $next ) {
						$lowest = min( $lowest, --$depth );
						++$depth;
					}
					if ( ( ':' === $next || '{' === $next ) && null === $keyword ) {
						$keyword = 'else';
					}
					break;

				case T_ENDWHILE:
				case T_ENDFOREACH:
				case T_ENDFOR:
				case T_ENDIF:
				case T_ENDSWITCH:
				case '}':
					$lowest = min( $lowest, --$depth );
					break;

				case '{':
				case T_CURLY_OPEN:
				case T_DOLLAR_OPEN_CURLY_BRACES:
					++$depth;
					break;

				case T_ECHO:
				case T_PRINT:
					$echoes = true;
					break;

				case T_NEW:
					$class = $this->next_significant( $tokens, $i + 1 );
					if ( null !== $class && is_array( $tokens[ $class ] ) && 'WP_Query' === ltrim( $tokens[ $class ][1], '\\' ) ) {
						$query = true;
					}
					break;

				case T_STRING:
					$paren = $this->next_significant( $tokens, $i + 1 );
					if ( null === $paren || '(' !== $tokens[ $paren ] ) {
						break;
					}
					$prev = $this->previous_significant( $tokens, $i - 1 );
					$prev = null !== $prev ? $this->token_id( $tokens[ $prev ] ) : null;
					if ( T_FUNCTION === $prev || T_NEW === $prev ) {
						break;
					}
					$arg     = $this->next_significant( $tokens, $paren + 1 );
					$calls[] = array(
						'name'   => $tokens[ $i ][1],
						'method' => in_array( $prev, $methods, true ),
						'arg'    => null !== $arg && T_CONSTANT_ENCAPSED_STRING === $this->token_id( $tokens[ $arg ] ) ? $tokens[ $arg ][1] : null,
					);
					break;
			}
		}

		$closes = -$lowest;
		$opens  = $depth + $closes;
		$code   = trim( $this->join( $tokens, 0, $count ) );

		if ( $opens > 0 ) {
			$role = $closes > 0 ? 'mid' : 'open';
			$kind = in_array( $keyword, array( 'while', 'foreach', 'for' ), true ) ? 'loop' : 'condition';

			$labels = array(
				'while'   => 'Loop',
				'foreach' => 'Loop',
				'for'     => 'Loop',
				'if'      => 'If',
				'elseif'  => 'Else if',
				'else'    => 'Else',
				'switch'  => 'Switch',
			);
			$label  = isset( $labels[ $keyword ] ) ? $labels[ $keyword ] : 'Block';
			if ( 'loop' === $kind && false !== strpos( $detail, 'have_posts' ) ) {
				$label = 'Post loop';
			}
		} elseif ( $closes > 0 ) {
			$role  = 'close';
			$kind  = 'end';
			$label = 'End';
		} else {
			$role = 'leaf';
			list( $kind, $label, $detail ) = $this->classify_leaf( $calls, $echoes, $query, $code );
		}

		return array(
			'role'   => $role,
			'opens'  => $opens,
			'closes' => $closes,
			'kind'   => $kind,
			'label'  => $label,
			'detail' => $detail,
			'code'   => $code,
		);
	}

	/**
	 * Classifies a PHP block that neither opens nor closes a block.
	 *
	 * @param array[] $calls  Function and method calls in the block.
	 * @param bool    $echoes Whether the block echoes or prints.
	 * @param bool    $query  Whether the block creates a WP_Query.
	 * @param string  $code   The PHP code.
	 * @return array Kind, label and detail.
	 */
	private function classify_leaf( array $calls, $echoes, $query, $code ) {
		$functions = array_values(
			array_filter(
				$calls,
				function ( $call ) {
					return ! $call['method'];
				}
			)
		);
		$names     = array_map( 'strtolower', array_column( $functions, 'name' ) );

		foreach ( array( 'get_header', 'get_footer' ) as $name ) {
			$index = array_search( $name, $names, true );
			if ( false !== $index ) {
				$label = 'get_header' === $name ? 'Site header' : 'Site footer';
				$arg   = $functions[ $index ]['arg'];
				return array( 'template', $label, $arg ? trim( $arg, '\'"' ) : '' );
			}
		}

		foreach ( array( 'imajiner_part', 'get_template_part' ) as $name ) {
			$index = array_search( $name, $names, true );
			if ( false !== $index ) {
				$arg = $functions[ $index ]['arg'];
				return array( 'partial', 'Template part', $arg ? trim( $arg, '\'"' ) : '' );
			}
		}

		if ( $query ) {
			return array( 'query', 'Query', 'WP_Query' );
		}

		$prints = $echoes;
		foreach ( $names as $name ) {
			if ( 0 === strpos( $name, 'the_' ) || in_array( $name, self::OUTPUT_FUNCTIONS, true ) ) {
				$prints = true;
			}
		}

		if ( $prints ) {
			// Name the value after the first call that isn't just escaping it.
			foreach ( $functions as $call ) {
				if ( ! in_array( strtolower( $call['name'] ), self::WRAPPER_FUNCTIONS, true ) ) {
					return array( 'dynamic', $call['name'] . '(' . ( $call['arg'] ? ' ' . $call['arg'] . ' ' : '' ) . ')', '' );
				}
			}
			return array( 'dynamic', $functions ? $functions[0]['name'] . '()' : 'echo', '' );
		}

		$first_line = strtok( $code, "\n" );
		return array( 'code', strlen( $first_line ) > 40 ? substr( $first_line, 0, 40 ) . '…' : $first_line, '' );
	}

	/**
	 * Places a PHP block in the tree being built, opening or closing blocks as needed.
	 *
	 * @param object[] $stack  Open nodes. Updated in place.
	 * @param int      $number Placeholder number.
	 */
	private function place_php_block( array &$stack, $number ) {
		$block = $this->php[ $number ];

		for ( $i = 0; $i < $block['closes']; $i++ ) {
			$this->pop_to(
				$stack,
				function ( $node ) {
					return 'block' === $node->type;
				},
				$block['code']
			);
		}

		if ( 'leaf' === $block['role'] ) {
			end( $stack )->children[] = (object) array(
				'type' => 'php',
				'id'   => 'p' . $number,
				'php'  => $number,
			);
			return;
		}

		for ( $i = 0; $i < $block['opens']; $i++ ) {
			$node                     = (object) array(
				'type'     => 'block',
				'id'       => 0 === $i ? 'p' . $number : 'p' . $number . '-' . $i,
				'php'      => $number,
				'children' => array(),
			);
			end( $stack )->children[] = $node;
			$stack[]                  = $node;
		}
	}

	/**
	 * Pops the stack down to and including the nearest node that matches.
	 *
	 * Nodes popped on the way were left open, which means HTML and PHP blocks
	 * overlap. That breaks the template contract, so it is reported.
	 *
	 * @param object[] $stack   Open nodes. Updated in place.
	 * @param callable $matches Returns true for the node to close.
	 * @param string   $closer  What closed it, for warnings.
	 */
	private function pop_to( array &$stack, callable $matches, $closer ) {
		for ( $i = count( $stack ) - 1; $i > 0; $i-- ) {
			if ( $matches( $stack[ $i ] ) ) {
				foreach ( array_slice( $stack, $i + 1 ) as $unclosed ) {
					$this->warnings[] = sprintf( '%s is still open when %s closes its parent.', $this->describe( $unclosed ), $closer );
				}
				array_splice( $stack, $i );
				return;
			}
		}

		$this->warnings[] = sprintf( '%s has nothing to close.', $closer );
	}

	/**
	 * Reads an element's attributes.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor matched on a tag opener.
	 * @return object Attribute name => value (true for boolean attributes).
	 */
	private function read_attributes( WP_HTML_Tag_Processor $processor ) {
		$attrs = array();
		foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
			$attrs[ $name ] = $processor->get_attribute( $name );
		}
		return (object) $attrs;
	}

	/**
	 * Describes a tree node for warnings.
	 *
	 * @param object $node Tree node.
	 * @return string
	 */
	private function describe( $node ) {
		switch ( $node->type ) {
			case 'element':
				return '<' . $node->tag . '>';
			case 'section':
				return sprintf( 'Section "%s"', $node->name );
			case 'block':
				return $this->php[ $node->php ]['code'];
		}
		return $node->type;
	}

	/**
	 * PHP block details for the editor, without the raw source.
	 *
	 * @return array[]
	 */
	private function php_for_output() {
		return array_map(
			function ( $block ) {
				unset( $block['source'] );
				return $block;
			},
			$this->php
		);
	}

	/**
	 * @param array|string $token Token from token_get_all().
	 * @return int|string Token id, or the character for single-character tokens.
	 */
	private function token_id( $token ) {
		return is_array( $token ) ? $token[0] : $token;
	}

	/**
	 * Index of the next token at or after $from that isn't whitespace or a comment.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $from   Start index.
	 * @return int|null
	 */
	private function next_significant( array $tokens, $from ) {
		$count = count( $tokens );
		for ( $i = $from; $i < $count; $i++ ) {
			if ( ! in_array( $this->token_id( $tokens[ $i ] ), array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Index of the previous token at or before $from that isn't whitespace or a comment.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $from   Start index.
	 * @return int|null
	 */
	private function previous_significant( array $tokens, $from ) {
		for ( $i = $from; $i >= 0; $i-- ) {
			if ( ! in_array( $this->token_id( $tokens[ $i ] ), array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Index of the ")" matching the "(" at $open.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $open   Index of "(".
	 * @return int|null
	 */
	private function matching_paren( array $tokens, $open ) {
		$depth = 0;
		$count = count( $tokens );
		for ( $i = $open; $i < $count; $i++ ) {
			if ( '(' === $tokens[ $i ] ) {
				++$depth;
			} elseif ( ')' === $tokens[ $i ] && 0 === --$depth ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Joins tokens [$from, $to) back into code, leaving out comments.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $from   Start index.
	 * @param int   $to     End index, exclusive.
	 * @return string
	 */
	private function join( array $tokens, $from, $to ) {
		$code = '';
		for ( $i = $from; $i < $to; $i++ ) {
			if ( ! in_array( $this->token_id( $tokens[ $i ] ), array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$code .= is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
			}
		}
		return $code;
	}
}
