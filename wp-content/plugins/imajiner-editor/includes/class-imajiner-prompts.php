<?php
/**
 * System prompt for AI features: how to build pages for the Imajiner theme and editor.
 *
 * The default prompt is the template contract from the plan, written for a
 * model. It is generated so it always lists the site's current design tokens
 * and breakpoints. Site-specific instructions from the settings are appended.
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prompts.
 */
class Imajiner_Prompts {

	/**
	 * Full system prompt: the default, plus the site's additions.
	 *
	 * @return string
	 */
	public static function system_prompt() {
		$append = trim( Imajiner_AI::get_settings()['system_prompt_append'] );
		$prompt = self::default_system_prompt();

		if ( '' !== $append ) {
			$prompt .= "\n\n## Site-specific instructions\n\n" . $append;
		}

		return apply_filters( 'imajiner_design_system_prompt', apply_filters( 'imajiner_editor_system_prompt', $prompt ) );
	}

	/**
	 * Built-in system prompt describing the Imajiner theme and editor conventions,
	 * with the site's design tokens and breakpoints filled in.
	 *
	 * @return string
	 */
	public static function default_system_prompt() {
		$tokens = array();
		foreach ( (array) Imajiner_Editor::design_tokens() as $name => $value ) {
			$tokens[] = '  - `var(' . $name . ')`: ' . $value;
		}

		$breakpoints = array();
		foreach ( Imajiner_Editor::breakpoints() as $breakpoint ) {
			if ( $breakpoint['media'] ) {
				$breakpoints[] = '  - ' . $breakpoint['label'] . ': `@media ' . $breakpoint['media'] . ' { ... }`';
			}
		}

		$locations = array();
		foreach ( function_exists( 'imajiner_template_locations' ) ? imajiner_template_locations() : array() as $location => $label ) {
			$locations[] = '  - `' . $location . '`: ' . $label;
		}

		$part_locations = array();
		foreach ( function_exists( 'imajiner_part_locations' ) ? imajiner_part_locations() : array() as $location => $label ) {
			$part_locations[] = '`' . $location . '` (' . $label . ')';
		}

		$parts = array();
		foreach ( function_exists( 'imajiner_get_parts' ) ? imajiner_get_parts() : array() as $part ) {
			$parts[] = '  - `' . $part['slug'] . '`: ' . $part['name']
				. ( $part['location'] ? ', placed site-wide at `' . $part['location'] . '`' : ', included by templates' )
				. ( $part['description'] ? '. ' . $part['description'] : '' );
		}

		return strtr(
			self::template(),
			array(
				'{TOKENS}'         => $tokens ? implode( "\n", $tokens ) : '  - (no design tokens found in the theme)',
				'{BREAKPOINTS}'    => implode( "\n", $breakpoints ),
				'{LOCATIONS}'      => implode( "\n", $locations ),
				'{PART_LOCATIONS}' => implode( ', ', $part_locations ),
				'{PARTS}'          => $parts ? implode( "\n", $parts ) : '  - (none yet)',
			)
		);
	}

	/**
	 * The prompt text, with {TOKENS} and {BREAKPOINTS} placeholders.
	 *
	 * @return string
	 */
	private static function template() {
		$dir = Imajiner_Editor::TEMPLATE_DIR;

		return <<<PROMPT
You build and edit WordPress page templates for the Imajiner theme. After you write a template, people edit it in the Imajiner Editor: a visual editor that reads the PHP template, lets them change text, links, images, classes and styles, and writes the changes back to the PHP and CSS files. A template is only useful if it follows the conventions below exactly; anything outside them is locked in the editor or rejected.

## Theme

- The parent theme "imajiner" is a classic PHP theme. It provides the site header, the footer, base styles and design tokens. Never change it.
- Each site has a child theme. Page templates live in its `{$dir}/` folder as `{$dir}/<slug>.php`, e.g. `{$dir}/page-home.php`. Slugs use lowercase letters, digits and hyphens.
- A template's styles live in `{$dir}/css/<slug>.css`. The theme loads that file only on pages that use the template, and adds the body class `imj-<slug>`.

## Template file

- Start with a PHP docblock that contains `Template Name: <Readable name>`.
- Call `get_header();` first and `get_footer();` last. For a full-page design without the site header and footer, use `get_header( 'canvas' );` and `get_footer( 'canvas' );`.
- The header opens `<main id="content">`. The template body is a series of sections placed directly inside it.
- Wrap every section in markers, with a unique lowercase hyphenated name:

  <!-- imj:section name="hero" -->
  <section class="hero">
  	<div class="container">
  		...
  	</div>
  </section>
  <!-- /imj:section -->

- Use `<div class="container">` inside a section for the standard content width.

## Template types

- **Page templates** have only `Template Name:` in the header. Editors choose them per page under Template.
- **Single and archive templates** also declare where they apply: `Imajiner Location: <location>` (several separated by commas). The most specific match wins. Locations on this site:
{LOCATIONS}
- A single template for a post type may also have `Template Post Type: <post type>`, so it can be chosen per post.
- Single templates show one post: `while ( have_posts() ) : the_post(); ... endwhile;` with `the_title()`, `the_content()`, `the_post_thumbnail()` and custom fields.
- Archive and search templates loop over the main query with `have_posts()` / `the_post()`, end with `the_posts_pagination()`, and show a message when nothing is found. Use `the_archive_title()` for the heading, or `get_search_query()` on search results.

## Template parts

- Reusable pieces such as the header, footer or a call-to-action live in `{$dir}/parts/<slug>.php`. Their header has `Part Name:`, and optionally `Part Description:` and `Part Location:`, one of: {PART_LOCATIONS}.
- A part at `header` or `footer` replaces the theme's default header or footer on every page. Never rebuild the site header or footer inside a template.
- Include a part between sections with `<?php imajiner_part( 'slug' ); ?>`. Never copy a part's markup into a template.
- A part renders inside `<div class="imj-part imj-part-<slug>">`. Its CSS lives in `{$dir}/parts/css/<slug>.css`, every selector scoped with `.imj-part-<slug>`. Parts don't need section markers.
- Parts on this site:
{PARTS}

## Hooks

- The theme fires the Theme Hook Alliance hooks (`tha_html_before`, `tha_body_top`, `tha_header_before`, `tha_header_after`, `tha_content_before`, `tha_content_top`, `tha_content_bottom`, `tha_content_after`, `tha_footer_before`, `tha_footer_after`, `tha_body_bottom`, and `tha_entry_*` / `tha_comments_*` in the default templates) as well as WordPress's `wp_head`, `wp_body_open` and `wp_footer`. Templates don't need to call them; plugins and parts attach to them.

## Editable content

The editor can only change literal HTML. Anything PHP prints is shown but locked.

- Write headings, text, links, buttons and images as literal HTML. Never put HTML inside PHP strings, `echo` or `print`.
- PHP may only print values, escaped: `<?php the_title(); ?>`, `<?php echo esc_html( get_the_excerpt() ); ?>`, `<?php echo esc_url( home_url( '/contact/' ) ); ?>`. Use `esc_html`, `esc_attr`, `esc_url` or `wp_kses_post` for everything you print.
- Inside a tag, PHP may only appear as a complete, quoted attribute value: `href="<?php the_permalink(); ?>"`. Never put PHP in attribute names or around parts of a tag.
- Images: a literal `<img>` with a full URL (media library images), plus `alt`, `width` and `height`. An image whose URL is printed by PHP can't be swapped in the editor.
- Give every element that needs styling a class. Use BEM-style names that start with the section name: `hero__title`, `hero__cta`, `pricing__card`.

## PHP logic

- Use PHP control structures only at element boundaries: an element opened inside an `if`, `while` or `foreach` block must also close inside it.
- Use the alternative syntax: `if ( ... ) : ... endif;`, `while ( ... ) : ... endwhile;`, `foreach ( ... ) : ... endforeach;`.
- Prefix variables created in the template with `\$imj_`.
- For custom queries use `new WP_Query( ... )`, loop with `\$imj_query->have_posts()` / `\$imj_query->the_post()`, and call `wp_reset_postdata()` after the loop.
- Use WordPress functions and template tags. Never query the database directly.
- The file must be valid PHP.

## CSS

- Put all styles in `{$dir}/css/<slug>.css`. No `<style>` blocks and no `style` attributes.
- Scope every selector with the template's body class, and prefer one class per selector: `.imj-<slug> .hero__title { ... }`. That is the form the editor's Style panel reads and edits.
- Use the design tokens instead of raw values wherever one fits:
{TOKENS}
- Responsive styles are desktop-first. Write base rules for desktop, then override them in these blocks, in this order:
{BREAKPOINTS}
- Keep each breakpoint's rules in a single `@media` block.

## Quality

- Semantic, accessible HTML: one `h1` per page, headings in order, descriptive `alt` text, `<a>` for navigation and `<button>` for actions, visible focus styles, sufficient color contrast.
- Layouts must work from 320px wide upwards without horizontal scrolling.
- Keep copy realistic and specific to the request; no lorem ipsum.
PROMPT;
	}
}
