<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Logo block.
 *
 * Structurally an Image with better defaults, which is the whole point: with no
 * image chosen it falls back to the site's own custom logo and links to the home
 * page, so dropping it in produces the right thing without a trip to the Media
 * Library. Sites with no custom logo fall back again to the site title as text,
 * rather than rendering a broken image.
 */
class FW_Newsletter_CRM_Email_Item_Logo extends FW_Newsletter_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'image'   => array(
				'type'  => 'upload',
				'label' => __( 'Logo', 'fw' ),
				'desc'  => __( 'Leave empty to use the site logo from the Customizer. PNG or JPG — email clients do not render SVG.', 'fw' ),
			),
			'url'     => array(
				'type'  => 'text',
				'label' => __( 'Links to', 'fw' ),
				'desc'  => __( 'Leave empty to link to the site home page.', 'fw' ),
				'value' => '',
			),
			'width'   => array( 'type' => 'text', 'label' => __( 'Width (px)', 'fw' ), 'value' => '180' ),
			'align'   => $this->align_option( 'center' ),
			'padding' => $this->padding_option( '20' ),
		) );
	}

	/** {@inheritdoc} */
	public function get_type() {
		return 'logo';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_preview_keys() {
		return array( 'image' );
	}

	/** {@inheritdoc} */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<circle cx="12" cy="12" r="9"/><path d="M8 13.5l2.5-3 2 2.4 1.8-2.2L16.5 14"/>',
			__( 'Logo', 'fw' )
		);
	}

	/**
	 * The site's Customizer logo, if one is set.
	 *
	 * @return string
	 */
	private function site_logo_url() {
		$id = (int) get_theme_mod( 'custom_logo' );

		if ( ! $id ) {
			return '';
		}

		$src = wp_get_attachment_image_src( $id, 'full' );

		return $src ? (string) $src[0] : '';
	}

	/** {@inheritdoc} */
	public function compile( array $atts, array $ctx ) {
		$src = isset( $atts['image'] ) ? $this->image_url( $atts['image'] ) : '';

		if ( '' === $src ) {
			$src = $this->site_logo_url();
		}

		$url     = ! empty( $atts['url'] ) ? $atts['url'] : home_url( '/' );
		$name    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$padding = $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 20 );
		$align   = $this->align( isset( $atts['align'] ) ? $atts['align'] : '', 'center' );

		if ( '' === $src ) {
			// No logo anywhere — the site name as text beats a broken image.
			$inner = '<span style="' . esc_attr( $this->style( array(
				'font-family' => $ctx['font_family'],
				'font-size'   => '20px',
				'font-weight' => '700',
				'color'       => $ctx['text_color'],
			) ) ) . '">' . esc_html( $name ) . '</span>';

			return $this->wrap_block( $this->maybe_link( $inner, $url, 'text-decoration:none' ), $padding, $align );
		}

		$max   = max( 1, (int) $ctx['content_width'] - ( $padding * 2 ) );
		$width = min( $max, max( 1, $this->px( isset( $atts['width'] ) ? $atts['width'] : '', 180 ) ) );

		$img = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $name ) . '"'
			. ' width="' . (int) $width . '"'
			. ' style="display:block;width:100%;max-width:' . (int) $width . 'px;height:auto;border:0" border="0" />';

		$table = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="' . esc_attr( $align ) . '"'
			. ' style="border-collapse:collapse"><tr><td style="padding:0">'
			. $this->maybe_link( $img, $url, 'text-decoration:none' )
			. '</td></tr></table>';

		return $this->wrap_block( $table, $padding, $align );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_Newsletter_CRM_Email_Item_Logo' );
