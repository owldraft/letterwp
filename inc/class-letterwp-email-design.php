<?php
/**
 * How every newsletter looks: the header logo, the site name and one brand
 * colour. One set for every email, with the footer credit always on.
 *
 * The template has more settings than the screen shows (fonts, sizes,
 * which post details show, full post or excerpt). They keep their
 * defaults, and the letterwp_email_design filter can change any of them.
 *
 * Only stored inputs live here; LetterWP_Email reads them while it builds,
 * and LetterWP_Design_Admin is the screen that edits them.
 *
 * Fonts are stacks every mail client already has: a web font is dropped by
 * Gmail and Outlook, and loading one from Google tells Google who opened
 * the email.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Email_Design {

	const OPTION = 'letterwp_email_design';

	/** @return array<string,array{label:string,stack:string}> */
	public static function fonts() {
		return array(
			'sans'      => array(
				'label' => __( 'System sans-serif', 'letterwp' ),
				'stack' => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
			),
			'helvetica' => array(
				'label' => 'Helvetica / Arial',
				'stack' => "'Helvetica Neue',Helvetica,Arial,sans-serif",
			),
			'verdana'   => array(
				'label' => 'Verdana',
				'stack' => 'Verdana,Geneva,sans-serif',
			),
			'trebuchet' => array(
				'label' => 'Trebuchet MS',
				'stack' => "'Trebuchet MS','Lucida Grande',sans-serif",
			),
			'georgia'   => array(
				'label' => 'Georgia',
				'stack' => "Georgia,'Times New Roman',Times,serif",
			),
			'palatino'  => array(
				'label' => 'Palatino',
				'stack' => "'Palatino Linotype',Palatino,'Book Antiqua',Georgia,serif",
			),
			'times'     => array(
				'label' => 'Times New Roman',
				'stack' => "'Times New Roman',Times,serif",
			),
			'mono'      => array(
				'label' => __( 'Monospace', 'letterwp' ),
				'stack' => "Menlo,Consolas,'Courier New',monospace",
			),
		);
	}

	/**
	 * The look LetterWP shipped with: saving the screen untouched changes
	 * nothing in the email.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'heading_font'      => 'sans',
			'body_font'         => 'sans',
			'title_size'        => 32,
			'title_size_mobile' => 26,
			'body_size'         => 17,
			'body_size_mobile'  => 17,
			'brand_color'       => '#15171a',
			'link_color'        => '#15171a',
			'button_color'      => '#15171a',
			'button_text_color' => '#ffffff',
			'button_radius'     => 4,
			'image_radius'      => 6,
			'logo'              => 0,
			'logo_width'        => 120,
			'site_name'         => true,
			'featured'          => true,
			'categories'        => false,
			'author'            => true,
			'date'              => true,
			'content'           => 'full',
			'read_more'         => '',
			// Always on in LetterWP: see get(). LetterWP Pro removes it.
			'credit'            => true,
		);
	}

	/** @return array<string,array{0:int,1:int}> Number fields: key => min, max. */
	private static function ranges() {
		return array(
			'title_size'        => array( 18, 56 ),
			'title_size_mobile' => array( 18, 48 ),
			'body_size'         => array( 14, 22 ),
			'body_size_mobile'  => array( 14, 22 ),
			'button_radius'     => array( 0, 30 ),
			'image_radius'      => array( 0, 30 ),
			'logo_width'        => array( 40, LetterWP_Email::WIDTH - 2 * LetterWP_Email::PAD ),
		);
	}

	/** @return array<string,mixed> What is saved, over the defaults. */
	private static function stored() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) ) : self::defaults();
	}

	/**
	 * @param array|null $override Unsaved values to show instead, as the
	 *                             screen's form sends them (the preview).
	 * @return array<string,mixed> Every key, always valid.
	 */
	public static function get( $override = null ) {
		$design = is_array( $override ) ? self::sanitize( array_merge( self::stored(), $override ) ) : self::stored();

		// The brand colour is the links, the categories and the buttons; the
		// button text is whichever of dark or white reads on it.
		$design['link_color']        = $design['brand_color'];
		$design['button_color']      = $design['brand_color'];
		$design['button_text_color'] = self::is_light( $design['brand_color'] ) ? '#15171a' : '#ffffff';

		// The footer credit is how the free plugin is paid for.
		$design['credit'] = true;

		/**
		 * Filters the design every newsletter is built with.
		 *
		 * @param array      $design   Every key of defaults(), valid.
		 * @param array|null $override Unsaved values from the screen, or null.
		 */
		return apply_filters( 'letterwp_email_design', $design, $override );
	}

	/** @return bool Dark text reads better than white on this colour. */
	private static function is_light( $hex ) {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		list( $r, $g, $b ) = array_map( 'hexdec', str_split( $hex, 2 ) );

		// Perceived brightness (ITU-R BT.601), 0-255.
		return ( 299 * $r + 587 * $g + 114 * $b ) / 1000 > 160;
	}

	/**
	 * Every setting in, a complete and valid set out. A checkbox left out is
	 * off, so the screen posts a 0 before each one.
	 *
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$out      = array();

		foreach ( $defaults as $key => $default ) {
			$value = isset( $input[ $key ] ) ? $input[ $key ] : null;

			if ( is_bool( $default ) ) {
				$out[ $key ] = ! empty( $value );
			} elseif ( isset( self::ranges()[ $key ] ) ) {
				list( $min, $max ) = self::ranges()[ $key ];
				$out[ $key ]       = is_numeric( $value ) ? max( $min, min( $max, (int) $value ) ) : $default;
			} elseif ( '_color' === substr( $key, -6 ) ) {
				$color       = sanitize_hex_color( is_string( $value ) ? $value : '' );
				$out[ $key ] = $color ? strtolower( $color ) : $default;
			} elseif ( '_font' === substr( $key, -5 ) ) {
				$out[ $key ] = is_string( $value ) && isset( self::fonts()[ $value ] ) ? $value : $default;
			} elseif ( 'logo' === $key ) {
				$id          = absint( $value );
				$out[ $key ] = $id && wp_attachment_is_image( $id ) ? $id : 0;
			} elseif ( 'content' === $key ) {
				$out[ $key ] = 'excerpt' === $value ? 'excerpt' : 'full';
			} else {
				$out[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $default;
			}
		}

		return $out;
	}

	/** Saves what the form sent; settings it does not show keep their values. */
	public static function update( array $input ) {
		update_option( self::OPTION, self::sanitize( array_merge( self::stored(), $input ) ), true );
	}

	public static function reset() {
		delete_option( self::OPTION );
	}

	/** @return string A font stack for a style attribute. */
	public static function stack( $font ) {
		$fonts = self::fonts();

		return isset( $fonts[ $font ] ) ? $fonts[ $font ]['stack'] : $fonts['sans']['stack'];
	}

	/** @return string The Read more button's words. */
	public static function read_more_label( array $design ) {
		return '' !== $design['read_more'] ? $design['read_more'] : __( 'Read more', 'letterwp' );
	}
}
