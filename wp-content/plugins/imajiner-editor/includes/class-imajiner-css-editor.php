<?php
/**
 * Edits single declarations in a template stylesheet.
 *
 * Reads and changes rules at the top level and inside @media blocks (one
 * level deep). Everything else in the file (comments, other at-rules, other
 * rules, formatting) is left byte-for-byte as it was: a change rewrites just
 * the value, declaration, rule or block it touches.
 *
 * Responsive styles live in @media blocks. New rules and blocks are placed so
 * the cascade works desktop-first: base rules before any breakpoint block, and
 * each breakpoint block before the blocks for narrower breakpoints.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Template CSS editor.
 */
class Imajiner_Css_Editor {

	/**
	 * Class names the editor may target.
	 */
	const CLASS_PATTERN = '-?[_a-zA-Z][_a-zA-Z0-9-]*';

	/**
	 * Property names, including custom properties.
	 */
	const PROPERTY_PATTERN = '/^(--[a-zA-Z0-9-]+|-?[a-zA-Z][a-zA-Z0-9-]*)$/';

	/**
	 * Stylesheet source.
	 *
	 * @var string
	 */
	private $css;

	/**
	 * Style rules with their positions in $css and the @media condition they sit in ('' for none).
	 *
	 * @var array[]
	 */
	private $rules = array();

	/**
	 * Top-level @media blocks with their positions in $css.
	 *
	 * @var array[]
	 */
	private $media_blocks = array();

	/**
	 * Whether the stylesheet has an unclosed block, which makes edits unsafe.
	 *
	 * @var bool
	 */
	private $broken = false;

	/**
	 * @param string $css Stylesheet source.
	 */
	public function __construct( $css ) {
		$this->css = $css;
		$this->parse();
	}

	/**
	 * @return string Stylesheet source, with changes applied.
	 */
	public function get_css() {
		return $this->css;
	}

	/**
	 * AI styles may contain scoped rules and media/supports groups only.
	 *
	 * @param string $css   Stylesheet source.
	 * @param string $scope Required leading selector.
	 * @return true|WP_Error
	 */
	public static function validate_scope( $css, $scope ) {
		$editor = new self( $css );
		if ( $editor->broken || ! $editor->scoped_block( 0, strlen( $css ), $scope ) ) {
			return new WP_Error( 'imajiner_css_scope', 'CSS must contain balanced rules scoped under ' . $scope . ', with only @media or @supports groups and no nested selectors.' );
		}
		return true;
	}

	private function scoped_block( $from, $to, $scope ) {
		$start = $from;
		$depth = 0;
		for ( $i = $from; $i < $to; ++$i ) {
			$char = $this->css[ $i ];
			if ( '/' === $char && isset( $this->css[ $i + 1 ] ) && '*' === $this->css[ $i + 1 ] ) {
				$end = strpos( $this->css, '*/', $i + 2 );
				if ( false === $end || $end >= $to ) {
					return false;
				}
				$i = $end + 1;
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$i = $this->skip_string( $i ) - 1;
				if ( $i >= $to || $this->css[ $i ] !== $char ) {
					return false;
				}
				continue;
			}
			if ( '(' === $char || '[' === $char ) {
				++$depth;
			} elseif ( ')' === $char || ']' === $char ) {
				if ( --$depth < 0 ) {
					return false;
				}
			} elseif ( '}' === $char || ';' === $char || '\\' === $char ) {
				return false;
			} elseif ( '{' === $char && 0 === $depth ) {
				$close   = $this->matching_brace( $i );
				$prelude = $this->normalize( substr( $this->css, $start, $i - $start ) );
				if ( null === $close || $close >= $to || '' === $prelude ) {
					return false;
				}
				if ( preg_match( '/^@(media|supports)\s+.+$/is', $prelude ) ) {
					if ( ! $this->scoped_block( $i + 1, $close, $scope ) ) {
						return false;
					}
				} else {
					foreach ( explode( ',', $prelude ) as $selector ) {
						$selector = trim( $selector );
						$tail     = substr( $selector, strlen( $scope ) );
						if ( 0 !== strpos( $selector, $scope ) || ( '' !== $tail && ! ctype_space( $tail[0] ) && '>' !== $tail[0] ) || preg_match( '/^[+~]/', ltrim( $tail ) ) ) {
							return false;
						}
					}
					for ( $j = $i + 1; $j < $close; ++$j ) {
						if ( '/' === $this->css[ $j ] && '*' === $this->css[ $j + 1 ] ) {
							$j = $this->skip_comment( $j ) - 1;
						} elseif ( '"' === $this->css[ $j ] || "'" === $this->css[ $j ] ) {
							$j = $this->skip_string( $j ) - 1;
						} elseif ( '{' === $this->css[ $j ] || '}' === $this->css[ $j ] ) {
							return false;
						}
					}
				}
				$i     = $close;
				$start = $close + 1;
			}
		}
		return 0 === $depth && '' === $this->normalize( substr( $this->css, $start, $to - $start ) );
	}

	/**
	 * Declarations of the rules written as "<scope> .<class>", per breakpoint, merged in cascade order.
	 *
	 * @param string $scope       Template scope selector, e.g. ".imj-page-home".
	 * @param array  $breakpoints Breakpoint name => @media condition ('' for the base styles).
	 * @return array Breakpoint name => class name => property => value.
	 */
	public function get_class_styles( $scope, array $breakpoints ) {
		$names = array();
		foreach ( $breakpoints as $name => $condition ) {
			$names[ self::media_key( $condition ) ] = $name;
		}

		$pattern = '/^' . preg_quote( $scope, '/' ) . ' \.(' . self::CLASS_PATTERN . ')$/';
		$styles  = array();

		foreach ( $this->rules as $rule ) {
			if ( isset( $names[ $rule['media'] ] ) && preg_match( $pattern, $rule['selector'], $match ) ) {
				foreach ( $rule['declarations'] as $declaration ) {
					$styles[ $names[ $rule['media'] ] ][ $match[1] ][ $declaration['property'] ] = $declaration['value'];
				}
			}
		}

		return $styles;
	}

	/**
	 * Sets, adds or removes one declaration.
	 *
	 * Changes the last rule with exactly this selector in the given @media
	 * condition, since that one wins the cascade. When there is none, a new
	 * rule is added: inside the last matching @media block, or in a new block.
	 * New rules and blocks go before the first block listed in $later_media,
	 * so narrower breakpoints keep overriding wider ones.
	 *
	 * @param string      $selector    Selector, e.g. ".imj-page-home .hero__title".
	 * @param string      $property    Property name.
	 * @param string|null $value       New value, or null to remove the declaration.
	 * @param string      $media       @media condition, e.g. "(max-width: 767px)", or '' for base styles.
	 * @param string[]    $later_media Conditions whose blocks must stay after this one.
	 * @return true|WP_Error
	 */
	public function set( $selector, $property, $value, $media = '', array $later_media = array() ) {
		if ( $this->broken ) {
			return new WP_Error( 'imajiner_css_broken', __( 'The template stylesheet has an unclosed block, so it can’t be edited safely. Fix it in code first.', 'imajiner-editor' ) );
		}

		$selector  = $this->normalize( $selector );
		$property  = strtolower( $property );
		$media_key = self::media_key( $media );
		$later     = array_map( array( __CLASS__, 'media_key' ), $later_media );

		$rule = null;
		foreach ( $this->rules as $candidate ) {
			if ( $candidate['selector'] === $selector && $candidate['media'] === $media_key ) {
				$rule = $candidate;
			}
		}

		if ( null === $rule ) {
			if ( null !== $value ) {
				$this->add_rule( $selector, $property, $value, $media, $later );
			}
			$this->parse();
			return true;
		}

		$declaration = null;
		foreach ( $rule['declarations'] as $candidate ) {
			if ( $candidate['property'] === $property ) {
				$declaration = $candidate;
			}
		}

		if ( $declaration && null !== $value ) {
			$this->splice( $declaration['value_start'], $declaration['value_end'], $value );
		} elseif ( $declaration ) {
			$this->remove_declaration( $declaration );
		} elseif ( null !== $value ) {
			$this->add_declaration( $rule, $property, $value );
		}

		$this->parse();
		return true;
	}

	/**
	 * Checks a style change from the editor.
	 *
	 * @param mixed $change Change: class, property and value (null removes).
	 * @return true|WP_Error
	 */
	public static function validate_change( $change ) {
		$invalid = new WP_Error( 'imajiner_invalid_style', __( 'Invalid style change.', 'imajiner-editor' ) );

		if (
			! is_array( $change ) ||
			! isset( $change['class'], $change['property'] ) ||
			! is_string( $change['class'] ) ||
			! is_string( $change['property'] ) ||
			! preg_match( '/^' . self::CLASS_PATTERN . '$/', $change['class'] ) ||
			! preg_match( self::PROPERTY_PATTERN, $change['property'] )
		) {
			return $invalid;
		}

		$value = isset( $change['value'] ) ? $change['value'] : null;
		if ( null === $value ) {
			return true;
		}

		// A value must stay inside its declaration: no new declarations, rules or comments.
		if ( ! is_string( $value ) || strlen( $value ) > 500 || preg_match( '#[{};<>\\\\]|/\*|\*/#', $value ) ) {
			return new WP_Error(
				'imajiner_invalid_style',
				/* translators: %s: CSS property name. */
				sprintf( __( 'The value for %s contains characters that aren’t allowed in a style value.', 'imajiner-editor' ), $change['property'] )
			);
		}

		return true;
	}

	/**
	 * Finds rules, @media blocks and declarations.
	 */
	private function parse() {
		$this->rules        = array();
		$this->media_blocks = array();
		$this->broken       = false;
		$this->parse_block( 0, strlen( $this->css ), '' );
	}

	/**
	 * Finds the rules between $from and $to (exclusive).
	 *
	 * @param int    $from  Start position.
	 * @param int    $to    End position.
	 * @param string $media Key of the @media condition the block is in, '' at the top level.
	 */
	private function parse_block( $from, $to, $media ) {
		$css   = $this->css;
		$start = $from;
		$i     = $from;

		while ( $i < $to && ! $this->broken ) {
			$char = $css[ $i ];

			if ( '/' === $char && isset( $css[ $i + 1 ] ) && '*' === $css[ $i + 1 ] ) {
				$i = $this->skip_comment( $i );
			} elseif ( '"' === $char || "'" === $char ) {
				$i = $this->skip_string( $i );
			} elseif ( ';' === $char || '}' === $char ) {
				// End of a statement at-rule such as @import, or a stray brace.
				$start = ++$i;
			} elseif ( '{' === $char ) {
				$close = $this->matching_brace( $i );
				if ( null === $close || $close >= $to ) {
					$this->broken = true;
					return;
				}

				// Where the rule starts, including any comment written just above it.
				$rule_start = $start;
				while ( $rule_start < $i && ctype_space( $css[ $rule_start ] ) ) {
					++$rule_start;
				}

				$prelude = $this->normalize( substr( $css, $start, $i - $start ) );
				if ( '' === $media && preg_match( '/^@media\s+(.+)$/is', $prelude, $match ) ) {
					$key                  = self::media_key( $match[1] );
					$this->media_blocks[] = array(
						'condition' => $key,
						'start'     => $rule_start,
						'open'      => $i,
						'close'     => $close,
					);
					$this->parse_block( $i + 1, $close, $key );
				} elseif ( '' !== $prelude && '@' !== $prelude[0] ) {
					$this->rules[] = array(
						'selector'     => $prelude,
						'media'        => $media,
						'start'        => $rule_start,
						'open'         => $i,
						'close'        => $close,
						'declarations' => $this->parse_declarations( $i + 1, $close ),
					);
				}

				$i     = $close + 1;
				$start = $i;
			} else {
				++$i;
			}
		}
	}

	/**
	 * Adds a rule with one declaration where the cascade needs it.
	 *
	 * @param string   $selector Normalized selector.
	 * @param string   $property Property name.
	 * @param string   $value    Value.
	 * @param string   $media    @media condition as written, '' for base styles.
	 * @param string[] $later    Keys of conditions whose blocks must stay after the new rule.
	 */
	private function add_rule( $selector, $property, $value, $media, array $later ) {
		$media_key = self::media_key( $media );

		if ( '' === $media_key ) {
			$this->insert_top_level( $selector . " {\n\t" . $property . ': ' . $value . ";\n}\n", $later );
			return;
		}

		$block = null;
		foreach ( $this->media_blocks as $candidate ) {
			if ( $candidate['condition'] === $media_key ) {
				$block = $candidate;
			}
		}

		if ( null === $block ) {
			$this->insert_top_level( '@media ' . trim( $media ) . " {\n\t" . $selector . " {\n\t\t" . $property . ': ' . $value . ";\n\t}\n}\n", $later );
			return;
		}

		// Append inside the existing block, after its last rule.
		$position = $block['close'];
		while ( $position > $block['open'] + 1 && ctype_space( $this->css[ $position - 1 ] ) ) {
			--$position;
		}
		$empty = $position === $block['open'] + 1;
		$rule  = ( $empty ? "\n\t" : "\n\n\t" ) . $selector . " {\n\t\t" . $property . ': ' . $value . ";\n\t}";
		if ( false === strpos( substr( $this->css, $position, $block['close'] - $position ), "\n" ) ) {
			$rule .= "\n";
		}
		$this->splice( $position, $position, $rule );
	}

	/**
	 * Inserts a top-level rule or block before the first @media block in $later, or at the end.
	 *
	 * @param string   $text  CSS to insert, ending with a newline.
	 * @param string[] $later Keys of conditions whose blocks must stay after it.
	 */
	private function insert_top_level( $text, array $later ) {
		foreach ( $this->media_blocks as $block ) {
			if ( in_array( $block['condition'], $later, true ) ) {
				$this->splice( $block['start'], $block['start'], $text . "\n" );
				return;
			}
		}

		$css       = rtrim( $this->css );
		$this->css = ( '' === $css ? '' : $css . "\n\n" ) . $text;
	}

	/**
	 * Compares @media conditions regardless of spacing and case.
	 *
	 * @param string $condition Condition, e.g. "(max-width: 767px)".
	 * @return string
	 */
	public static function media_key( $condition ) {
		return strtolower( preg_replace( '/\s+/', '', $condition ) );
	}

	/**
	 * Reads the declarations between $from and $to (exclusive).
	 *
	 * @param int $from Position after "{".
	 * @param int $to   Position of "}".
	 * @return array[]
	 */
	private function parse_declarations( $from, $to ) {
		$css          = $this->css;
		$declarations = array();
		$start        = $from;
		$depth        = 0;
		$i            = $from;

		while ( $i <= $to ) {
			$char = $i < $to ? $css[ $i ] : ';';

			if ( $i < $to && '/' === $char && isset( $css[ $i + 1 ] ) && '*' === $css[ $i + 1 ] ) {
				$i = $this->skip_comment( $i );
				continue;
			}
			if ( $i < $to && ( '"' === $char || "'" === $char ) ) {
				$i = $this->skip_string( $i );
				continue;
			}
			if ( '(' === $char ) {
				++$depth;
			} elseif ( ')' === $char ) {
				--$depth;
			} elseif ( '{' === $char ) {
				// A nested rule: skip it and start over after it.
				$close = $this->matching_brace( $i );
				$i     = null === $close ? $to : $close + 1;
				$start = $i;
				continue;
			} elseif ( ';' === $char && $depth <= 0 ) {
				$declaration = $this->read_declaration( $start, $i, $i < $to );
				if ( $declaration ) {
					$declarations[] = $declaration;
				}
				$start = $i + 1;
				$depth = 0;
			}
			++$i;
		}

		return $declarations;
	}

	/**
	 * Reads one "property: value" between $from and $to.
	 *
	 * @param int  $from          Start of the segment.
	 * @param int  $to            Position of its ";" or of the rule's "}".
	 * @param bool $has_semicolon Whether the segment ends with ";".
	 * @return array|null
	 */
	private function read_declaration( $from, $to, $has_semicolon ) {
		$css = $this->css;

		// Skip whitespace and comments in front of the property.
		$start = $from;
		while ( $start < $to ) {
			if ( ctype_space( $css[ $start ] ) ) {
				++$start;
			} elseif ( '/' === $css[ $start ] && isset( $css[ $start + 1 ] ) && '*' === $css[ $start + 1 ] ) {
				$start = $this->skip_comment( $start );
			} else {
				break;
			}
		}

		$colon = strpos( $css, ':', $start );
		if ( $start >= $to || false === $colon || $colon >= $to ) {
			return null;
		}

		$property = strtolower( trim( substr( $css, $start, $colon - $start ) ) );
		if ( ! preg_match( self::PROPERTY_PATTERN, $property ) ) {
			return null;
		}

		$value_start = $colon + 1;
		while ( $value_start < $to && ctype_space( $css[ $value_start ] ) ) {
			++$value_start;
		}
		$value_end = $to;
		while ( $value_end > $value_start && ctype_space( $css[ $value_end - 1 ] ) ) {
			--$value_end;
		}

		return array(
			'property'      => $property,
			'value'         => substr( $css, $value_start, $value_end - $value_start ),
			'start'         => $start,
			'value_start'   => $value_start,
			'value_end'     => $value_end,
			'end'           => $to,
			'has_semicolon' => $has_semicolon,
		);
	}

	/**
	 * Removes a declaration, and its line when it has one to itself.
	 *
	 * @param array $declaration Declaration from parse_declarations().
	 */
	private function remove_declaration( array $declaration ) {
		$start = $declaration['start'];
		$end   = $declaration['has_semicolon'] ? $declaration['end'] + 1 : $declaration['value_end'];

		$line_start = strrpos( substr( $this->css, 0, $start ), "\n" );
		if ( false !== $line_start && '' === trim( substr( $this->css, $line_start + 1, $start - $line_start - 1 ) ) ) {
			$start = $line_start;
		}

		$this->splice( $start, $end, '' );
	}

	/**
	 * Adds a declaration after the rule's last one, matching its indentation.
	 *
	 * @param array  $rule     Rule from parse().
	 * @param string $property Property name.
	 * @param string $value    Value.
	 */
	private function add_declaration( array $rule, $property, $value ) {
		$declarations = $rule['declarations'];

		// Declarations sit one tab deeper than their rule, which is indented inside @media blocks.
		$rule_indent = '';
		$line_start  = strrpos( substr( $this->css, 0, $rule['start'] ), "\n" );
		$leading     = substr( $this->css, false === $line_start ? 0 : $line_start + 1, $rule['start'] - ( false === $line_start ? 0 : $line_start + 1 ) );
		if ( '' === trim( $leading ) ) {
			$rule_indent = $leading;
		}

		if ( ! $declarations ) {
			$inner = substr( $this->css, $rule['open'] + 1, $rule['close'] - $rule['open'] - 1 );
			if ( '' === trim( $inner ) ) {
				$this->splice( $rule['open'] + 1, $rule['close'], "\n" . $rule_indent . "\t" . $property . ': ' . $value . ";\n" . $rule_indent );
				return;
			}
		}

		$indent = $rule_indent . "\t";
		if ( $declarations ) {
			$first      = $declarations[0];
			$line_start = strrpos( substr( $this->css, 0, $first['start'] ), "\n" );
			if ( false !== $line_start ) {
				$leading = substr( $this->css, $line_start + 1, $first['start'] - $line_start - 1 );
				if ( '' === trim( $leading ) ) {
					$indent = $leading;
				}
			}
		}

		$last = end( $declarations );
		if ( $last ) {
			$position = $last['has_semicolon'] ? $last['end'] + 1 : $last['value_end'];
			$prefix   = $last['has_semicolon'] ? '' : ';';
		} else {
			$position = $rule['open'] + 1;
			$prefix   = '';
		}

		$this->splice( $position, $position, $prefix . "\n" . $indent . $property . ': ' . $value . ';' );
	}

	/**
	 * Replaces $css between $from and $to.
	 *
	 * @param int    $from        Start.
	 * @param int    $to          End, exclusive.
	 * @param string $replacement New text.
	 */
	private function splice( $from, $to, $replacement ) {
		$this->css = substr( $this->css, 0, $from ) . $replacement . substr( $this->css, $to );
	}

	/**
	 * Collapses whitespace and drops comments, so selectors compare reliably.
	 *
	 * @param string $selector Selector text.
	 * @return string
	 */
	private function normalize( $selector ) {
		return trim( preg_replace( '/\s+/', ' ', preg_replace( '#/\*.*?\*/#s', '', $selector ) ) );
	}

	/**
	 * Position after the comment starting at $i.
	 *
	 * @param int $i Position of "/*".
	 * @return int
	 */
	private function skip_comment( $i ) {
		$end = strpos( $this->css, '*/', $i + 2 );
		return false === $end ? strlen( $this->css ) : $end + 2;
	}

	/**
	 * Position after the string starting at $i.
	 *
	 * @param int $i Position of the opening quote.
	 * @return int
	 */
	private function skip_string( $i ) {
		$quote  = $this->css[ $i ];
		$length = strlen( $this->css );
		for ( $j = $i + 1; $j < $length; $j++ ) {
			if ( '\\' === $this->css[ $j ] ) {
				++$j;
			} elseif ( $quote === $this->css[ $j ] || "\n" === $this->css[ $j ] ) {
				return $j + 1;
			}
		}
		return $length;
	}

	/**
	 * Position of the "}" matching the "{" at $open.
	 *
	 * @param int $open Position of "{".
	 * @return int|null
	 */
	private function matching_brace( $open ) {
		$length = strlen( $this->css );
		$depth  = 0;
		$i      = $open;

		while ( $i < $length ) {
			$char = $this->css[ $i ];
			if ( '/' === $char && isset( $this->css[ $i + 1 ] ) && '*' === $this->css[ $i + 1 ] ) {
				$i = $this->skip_comment( $i );
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$i = $this->skip_string( $i );
				continue;
			}
			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char && 0 === --$depth ) {
				return $i;
			}
			++$i;
		}

		return null;
	}
}
