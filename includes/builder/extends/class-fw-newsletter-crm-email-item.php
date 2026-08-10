<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Base class for every email-builder block.
 *
 * Modelled on the Learning extension's quiz-builder items, which are the closest
 * precedent in the codebase: a builder whose items render their OWN bespoke HTML
 * from the item class, rather than delegating to shortcodes the way the page
 * builder does. That is exactly what email needs, because shortcode output is
 * div/flex/CSS-class markup and email cannot use any of it.
 *
 * A block therefore implements one extra method beyond the framework's four:
 * compile(), which returns table-based HTML with INLINE styles.
 */
abstract class FW_Newsletter_CRM_Email_Item extends FW_Option_Type_Builder_Item {

	/** @var array Set by the subclass in _init() via set_options(). */
	private $options = array();

	/** @var string Inline SVG path markup, recorded by thumbnail(). */
	protected $icon = '';

	/** @var string Human label, recorded by thumbnail(). */
	protected $title = '';

	/**
	 * {@inheritdoc}
	 */
	final public function get_builder_type() {
		return 'email-builder';
	}

	/**
	 * Compile this block to email HTML.
	 *
	 * MUST return a SELF-CONTAINED table — not a bare <tr> — because the compiler
	 * places a block either in a full-width row or inside a column, and only a
	 * self-contained table works in both. Use wrap_block() and you get that for
	 * free. (This is the same reason MJML makes every component a table.)
	 *
	 * Inline styles only: no <style> dependency, no classes, no flex, no float.
	 * Outlook renders with the Word engine and Gmail strips <style> in clipped
	 * views, so anything else is a coin toss.
	 *
	 * @param array $atts The block's saved values.
	 * @param array $ctx  Resolved global styles (font, colours, width…).
	 *
	 * @return string
	 */
	abstract public function compile( array $atts, array $ctx );

	/**
	 * Wrap a block's content in its own full-width table.
	 *
	 * @param string $content
	 * @param int    $padding
	 * @param string $align
	 * @param array  $cell_styles Extra declarations for the content cell.
	 *
	 * @return string
	 */
	protected function wrap_block( $content, $padding = 12, $align = 'left', array $cell_styles = array() ) {
		if ( '' === $content ) {
			return '';
		}

		$style = $this->style( array_merge( array(
			'padding'    => (int) $padding . 'px',
			'text-align' => $align,
		), $cell_styles ) );

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="width:100%;border-collapse:collapse">'
			. '<tr><td align="' . esc_attr( $align ) . '" style="' . esc_attr( $style ) . '">'
			. $content
			. '</td></tr></table>';
	}

	/**
	 * Blocks need no front-end JS of their own — their settings render in the
	 * builder's shared options modal. Declared here so no subclass has to write
	 * an empty method.
	 *
	 * @internal
	 */
	public function enqueue_static() {
	}

	/**
	 * @param array $options
	 */
	final protected function set_options( array $options ) {
		$this->options = $options;
	}

	/**
	 * {@inheritdoc}
	 */
	final public function get_options() {
		return $this->options;
	}

	/**
	 * Shared padding control — every block has one, so it lives here rather than
	 * being copy-pasted four times.
	 *
	 * @param string $default
	 *
	 * @return array
	 */
	protected function padding_option( $default = '12' ) {
		return array(
			'type'  => 'text',
			'label' => __( 'Padding (px)', 'fw' ),
			'desc'  => __( 'Space around this block, in pixels.', 'fw' ),
			'value' => $default,
		);
	}

	/**
	 * Which saved option(s) the canvas should show as this block's one-line
	 * summary, best first. Keeps the builder JS generic — it reads these rather
	 * than carrying a switch over every block type.
	 *
	 * @return array
	 */
	public function get_preview_keys() {
		return array();
	}

	/**
	 * Human label — used by the item tray, the canvas and the options modal, so
	 * a block names itself in exactly one place.
	 *
	 * @return string
	 */
	public function get_title() {
		$this->ensure_meta();

		return $this->title;
	}

	/**
	 * The block's icon, as inline SVG. Shown in the tray and on the canvas.
	 *
	 * @return string
	 */
	public function get_icon_svg() {
		$this->ensure_meta();

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" '
			. 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
			. $this->icon
			. '</svg>';
	}

	/**
	 * Build the thumbnail box shown in the builder's item tray. Also records the
	 * label and icon so get_title() / get_icon_svg() need no duplication.
	 *
	 * @param string $svg   Inline SVG path markup (24x24 viewBox).
	 * @param string $label
	 *
	 * @return array
	 */
	/**
	 * The label and icon are recorded as a side effect of get_thumbnails(), which
	 * the framework may not have called yet when we localize the JS — so make
	 * sure it has.
	 */
	private function ensure_meta() {
		if ( '' === $this->title ) {
			$this->get_thumbnails();
		}
	}

	protected function thumbnail( $svg, $label ) {
		$this->icon  = $svg;
		$this->title = $label;

		return array(
			array(
				'html' =>
					'<div class="fw-crm-eb-thumb" data-hover-tip="' . esc_attr( $label ) . '">'
					. '<span class="fw-crm-eb-thumb__icon">'
					. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" '
					. 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
					. $svg
					. '</svg>'
					. '</span>'
					. '<span class="fw-crm-eb-thumb__label">' . esc_html( $label ) . '</span>'
					. '</div>',
			),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Compile helpers, shared by every block
	 * ---------------------------------------------------------------------- */

	/**
	 * Turn an array of CSS declarations into an inline style attribute value,
	 * dropping empties so blocks can pass conditionals without guarding.
	 *
	 * @param array $rules [ 'color' => '#333', 'font-size' => '' ]
	 *
	 * @return string
	 */
	protected function style( array $rules ) {
		$out = array();

		foreach ( $rules as $property => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}

			$out[] = $property . ':' . $value;
		}

		return implode( ';', $out );
	}

	/**
	 * @param mixed $value
	 * @param int   $default
	 *
	 * @return int
	 */
	protected function px( $value, $default = 0 ) {
		return '' === $value || null === $value ? (int) $default : (int) $value;
	}

	/**
	 * left|center|right, defaulting safely.
	 *
	 * @param mixed  $value
	 * @param string $default
	 *
	 * @return string
	 */
	protected function align( $value, $default = 'left' ) {
		return in_array( $value, array( 'left', 'center', 'right' ), true ) ? $value : $default;
	}

	/**
	 * The URL out of an `upload` option value, which stores an array.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	protected function image_url( $value ) {
		if ( is_array( $value ) ) {
			return isset( $value['url'] ) ? (string) $value['url'] : '';
		}

		return (string) $value;
	}

	/**
	 * A reusable left/center/right control.
	 *
	 * @param string $default
	 *
	 * @return array
	 */
	protected function align_option( $default = 'left' ) {
		return array(
			'type'    => 'radio-text',
			'label'   => __( 'Alignment', 'fw' ),
			'value'   => $default,
			'choices' => array(
				'left'   => __( 'Left', 'fw' ),
				'center' => __( 'Center', 'fw' ),
				'right'  => __( 'Right', 'fw' ),
			),
		);
	}

	/**
	 * Wrap content in a link when a URL is set, otherwise return it untouched.
	 *
	 * @param string $content
	 * @param string $url
	 * @param string $style
	 *
	 * @return string
	 */
	protected function maybe_link( $content, $url, $style = '' ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return $content;
		}

		return '<a href="' . esc_url( $url ) . '" target="_blank"'
			. ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' )
			. '>' . $content . '</a>';
	}
}
