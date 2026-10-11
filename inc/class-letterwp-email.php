<?php
/**
 * A post, as an email.
 *
 * One template: logo and site name, the categories, title, byline, "View in
 * browser", featured image, the post (or its excerpt and a Read more
 * button), and a footer with the postal address and the unsubscribe link.
 * A campaign has no page on the site, so it has no categories, byline,
 * "View in browser" or Read more: the whole email is in the email.
 * Fonts, colours and which of those show come from LetterWP_Email_Design.
 *
 * Block HTML is web HTML: layout in classes, srcset on images, embeds as
 * iframes. Mail clients honour almost none of it, so the rendered post is
 * walked once and every element gets the inline style it needs, every URL
 * is made absolute, and every embed becomes a picture that links to it.
 *
 * Rendered without the_content filters: other plugins hook them to add
 * share buttons, related posts or a paywall, none of which belong in an
 * inbox.
 *
 * The unsubscribe link goes to this site's one-click unsubscribe, carrying
 * the reader's key through a merge tag (LetterWP_Login::unsubscribe_url()).
 * Before Setup has made the key's contact property, it is Resend's own
 * unsubscribe page instead. Either way the tag only resolves in a Broadcast
 * sent to a segment; a test is a single email, so a test links home
 * instead and says so at the top. Gmail's own Unsubscribe button is
 * Resend's: it adds the List-Unsubscribe headers to every Broadcast.
 *
 * @package LetterWP
 */

defined( 'ABSPATH' ) || exit;

final class LetterWP_Email {

	/** {address: postal address}. */
	const OPTION = 'letterwp_email';

	/** Triple braces: Resend prints a double-brace tag as literal text. */
	const UNSUBSCRIBE = '{{{RESEND_UNSUBSCRIBE_URL}}}';

	/** Each reader's unsubscribe key, from the contact property Setup makes. */
	const KEY_TAG = '{{{contact.letterwp_key}}}';

	/** The column every mail client's reading pane was sized for. */
	const WIDTH = 600;

	/** Side padding inside it: content is WIDTH - 2 * PAD wide. */
	const PAD = 32;

	/** The footer credit's link. Its icon ships with the plugin: see document(). */
	const CREDIT_URL = 'https://letterwp.com';

	/** Bump to rebuild every embed thumbnail. */
	const THUMB_VERSION = '1';

	const INK = '#15171a';

	const BODY = '#3c484e';

	const MUTED = '#738a94';

	const LINE = '#e5eff5';

	const TINT = '#f4f8fb';

	/** @var bool True while build() renders blocks, so render_block only touches ours. */
	private static $rendering = false;

	/** @var array|null The design build() is using; the saved one otherwise. */
	private static $design = null;

	/** @return mixed One design setting. */
	private static function t( $key ) {
		if ( null === self::$design ) {
			self::$design = LetterWP_Email_Design::get();
		}

		return self::$design[ $key ];
	}

	/** @return string The body font's stack. */
	private static function sans() {
		return LetterWP_Email_Design::stack( self::t( 'body_font' ) );
	}

	/** @return string The heading font's stack. */
	private static function heading() {
		return LetterWP_Email_Design::stack( self::t( 'heading_font' ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings: the postal address, which anti-spam laws want in every
	 * newsletter footer.
	 * ------------------------------------------------------------------ */

	/** @return string Several lines, or ''. */
	public static function postal_address() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) && isset( $stored['address'] ) ? (string) $stored['address'] : '';
	}

	public static function update_postal_address( $address ) {
		$lines = array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( (string) $address ) ) ) );

		update_option( self::OPTION, array( 'address' => implode( "\n", $lines ) ), true );
	}

	/* ---------------------------------------------------------------------
	 * Build.
	 * ------------------------------------------------------------------ */

	/**
	 * @param WP_Post $post Unsaved edits may be passed in as a copy.
	 * @param array   $args mode: send (to the list), test (one inbox) or
	 *                      preview (a browser tab). thumbnail_id: the featured
	 *                      image, when it differs from what is saved.
	 *                      design: unsaved design settings to use instead
	 *                      of the saved ones (the Email Design screen).
	 * @return array{subject:string,preheader:string,html:string,text:string}
	 */
	public static function build( WP_Post $post, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'mode'         => 'send',
				'thumbnail_id' => null,
				'design'       => null,
			)
		);

		self::$design = LetterWP_Email_Design::get( $args['design'] );

		$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = trim( wp_specialchars_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES ) );
		$subject   = '' !== $subject ? $subject : __( '(no title)', 'letterwp' );
		$campaign  = LetterWP_Campaigns::is( $post );
		$permalink = $campaign ? '' : get_permalink( $post );
		$content   = self::content( $post );
		$preheader = self::preheader( $post, $content );
		$excerpt   = ! $campaign && 'excerpt' === self::t( 'content' );

		if ( 'send' !== $args['mode'] ) {
			$unsubscribe = home_url( '/' );
		} elseif ( LetterWP_Setup::has_key_property() ) {
			$unsubscribe = LetterWP_Login::unsubscribe_url( self::KEY_TAG );
		} else {
			$unsubscribe = self::UNSUBSCRIBE;
		}

		$parts = array(
			'site'        => $site,
			'subject'     => $subject,
			'permalink'   => $permalink,
			'byline'      => $campaign ? '' : self::byline( $post ),
			'categories'  => ! $campaign && self::t( 'categories' ) ? self::categories( $post ) : array(),
			'image'       => self::t( 'featured' ) ? self::featured_image( $post, $args['thumbnail_id'] ) : '',
			'content'     => $excerpt ? self::excerpt( $post, $content ) : $content,
			'read_more'   => $excerpt ? LetterWP_Email_Design::read_more_label( self::$design ) : '',
			'credit'      => self::t( 'credit' ),
			'preheader'   => $preheader,
			'unsubscribe' => $unsubscribe,
			'mode'        => $args['mode'],
		);

		$email = array(
			'subject'   => $subject,
			'preheader' => $preheader,
			'html'      => self::document( $parts ),
			'text'      => self::text( $parts ),
		);

		self::$design = null;

		return $email;
	}

	/**
	 * The post's blocks as email HTML.
	 *
	 * @return string
	 */
	public static function content( WP_Post $post ) {
		$prev_post       = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- dynamic blocks read it; restored below.
		setup_postdata( $post );
		self::$rendering = true;

		add_filter( 'render_block', array( __CLASS__, 'render_block' ), 10, 2 );

		$html = $post->post_content;

		if ( has_blocks( $html ) ) {
			$html = do_blocks( $html );
		} else {
			$html = wpautop( $html );
		}

		$html = wptexturize( $html );
		$html = convert_smilies( $html );
		$html = shortcode_unautop( $html );
		$html = do_shortcode( $html );

		remove_filter( 'render_block', array( __CLASS__, 'render_block' ), 10 );

		self::$rendering = false;
		$GLOBALS['post'] = $prev_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		if ( $prev_post instanceof WP_Post ) {
			setup_postdata( $prev_post );
		}

		return self::inline( $html );
	}

	/**
	 * Blocks that mean nothing in an inbox, swapped as they render.
	 *
	 * @param string $html  The block's HTML.
	 * @param array  $block The parsed block.
	 * @return string
	 */
	public static function render_block( $html, $block ) {
		if ( ! self::$rendering || empty( $block['blockName'] ) ) {
			return $html;
		}

		$name = $block['blockName'];

		// core-embed/* is how embeds were saved before WordPress 5.6.
		if ( 'core/embed' === $name || 0 === strpos( $name, 'core-embed/' ) ) {
			$url = isset( $block['attrs']['url'] ) ? (string) $block['attrs']['url'] : '';

			if ( '' === $url ) {
				return '';
			}

			$caption = preg_match( '#<figcaption[^>]*>(.*?)</figcaption>#s', $html, $m ) ? trim( wp_strip_all_tags( $m[1] ) ) : '';

			return self::embed_card( $url, $caption );
		}

		return $html;
	}

	/* ---------------------------------------------------------------------
	 * Web HTML → email HTML.
	 * ------------------------------------------------------------------ */

	/** @return array<string,string> tag => its inline style. */
	private static function styles() {
		$font = 'font-family:' . self::sans() . ';';
		$head = 'font-family:' . self::heading() . ';';
		$size = 'font-size:' . self::t( 'body_size' ) . 'px;';

		return array(
			'p'          => $font . $size . 'line-height:1.7;margin:0 0 1.4em;color:' . self::BODY . ';',
			'h1'         => $head . 'font-size:28px;line-height:1.3;margin:1.4em 0 .5em;color:' . self::INK . ';font-weight:700;',
			'h2'         => $head . 'font-size:23px;line-height:1.35;margin:1.5em 0 .5em;color:' . self::INK . ';font-weight:700;',
			'h3'         => $head . 'font-size:20px;line-height:1.4;margin:1.5em 0 .5em;color:' . self::INK . ';font-weight:700;',
			'h4'         => $head . 'font-size:17px;line-height:1.4;margin:1.5em 0 .5em;color:' . self::INK . ';font-weight:700;',
			'h5'         => $head . 'font-size:15px;line-height:1.4;margin:1.5em 0 .5em;color:' . self::INK . ';font-weight:700;',
			'h6'         => $head . 'font-size:13px;line-height:1.4;margin:1.5em 0 .5em;color:' . self::MUTED . ';font-weight:700;text-transform:uppercase;letter-spacing:.06em;',
			'a'          => 'color:' . self::t( 'link_color' ) . ';text-decoration:underline;',
			'ul'         => $font . $size . 'line-height:1.7;margin:0 0 1.4em;padding-left:1.4em;color:' . self::BODY . ';',
			'ol'         => $font . $size . 'line-height:1.7;margin:0 0 1.4em;padding-left:1.4em;color:' . self::BODY . ';',
			'li'         => 'margin:0 0 .5em;',
			'blockquote' => $head . 'font-size:19px;line-height:1.6;margin:0 0 1.4em;padding:0 0 0 1.1em;border-left:3px solid ' . self::INK . ';color:' . self::INK . ';font-style:italic;',
			'cite'       => $font . 'display:block;margin-top:.6em;font-size:14px;font-style:normal;color:' . self::MUTED . ';',
			'figure'     => 'margin:0 0 1.6em;',
			'figcaption' => $font . 'font-size:13px;line-height:1.5;color:' . self::MUTED . ';text-align:center;margin-top:.6em;',
			'img'        => 'display:block;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;',
			'hr'         => 'border:0;border-top:1px solid ' . self::LINE . ';margin:2.2em 0;height:1px;',
			'pre'        => 'background:' . self::TINT . ';border-radius:4px;padding:14px 16px;font-family:Menlo,Consolas,Monaco,monospace;font-size:13px;line-height:1.6;margin:0 0 1.4em;white-space:pre-wrap;word-wrap:break-word;color:' . self::INK . ';',
			'code'       => 'font-family:Menlo,Consolas,Monaco,monospace;font-size:14px;background:' . self::TINT . ';padding:2px 5px;border-radius:3px;color:' . self::INK . ';',
			'table'      => $font . 'width:100%;border-collapse:collapse;margin:0 0 1.4em;font-size:15px;color:' . self::BODY . ';',
			'th'         => 'border:1px solid ' . self::LINE . ';padding:8px 10px;text-align:left;background:' . self::TINT . ';font-weight:700;color:' . self::INK . ';',
			'td'         => 'border:1px solid ' . self::LINE . ';padding:8px 10px;text-align:left;vertical-align:top;',
			'strong'     => 'font-weight:700;',
			'b'          => 'font-weight:700;',
			'em'         => 'font-style:italic;',
		);
	}

	/**
	 * Inline every style, make every URL absolute, and drop what an inbox
	 * cannot show.
	 *
	 * @return string
	 */
	private static function inline( $html ) {
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return $html;
		}

		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="letterwp-root">' . $html . '</div></body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath = new DOMXPath( $doc );
		$root  = $doc->getElementById( 'letterwp-root' );

		if ( ! $root ) {
			return $html;
		}

		// Nothing an inbox runs or shows.
		foreach ( array( 'script', 'style', 'noscript', 'template', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'source', 'link', 'meta' ) as $tag ) {
			foreach ( iterator_to_array( $root->getElementsByTagName( $tag ) ) as $node ) {
				$node->parentNode->removeChild( $node );
			}
		}

		// Iframes pasted as HTML (a classic post, a Custom HTML block).
		foreach ( iterator_to_array( $root->getElementsByTagName( 'iframe' ) ) as $iframe ) {
			$url = self::iframe_url( $iframe->getAttribute( 'src' ) );
			self::replace_with_html( $iframe, '' === $url ? '' : self::embed_card( $url ) );
		}

		// Video and audio: a picture or a line that leads to the post.
		foreach ( array( 'video', 'audio' ) as $tag ) {
			foreach ( iterator_to_array( $root->getElementsByTagName( $tag ) ) as $media ) {
				self::replace_with_html( $media, self::media_card( $tag, $media->getAttribute( 'poster' ) ) );
			}
		}

		// A paragraph that is only a URL WordPress knows how to embed: what a
		// classic post's embed looks like before the_content runs.
		foreach ( iterator_to_array( $root->getElementsByTagName( 'p' ) ) as $p ) {
			$text = trim( $p->textContent );
			if ( ! preg_match( '#^https?://\S+$#', $text ) || $xpath->query( './/img|.//a', $p )->length ) {
				continue;
			}
			if ( self::is_embeddable( $text ) ) {
				self::replace_with_html( $p, self::embed_card( $text ) );
			} else {
				$a = $doc->createElement( 'a' );
				$a->setAttribute( 'href', $text );
				$a->appendChild( $doc->createTextNode( $text ) );
				while ( $p->firstChild ) {
					$p->removeChild( $p->firstChild );
				}
				$p->appendChild( $a );
			}
		}

		// Absolute URLs: an email has no "current site" to be relative to.
		foreach ( $xpath->query( './/a[@href]', $root ) as $a ) {
			$a->setAttribute( 'href', self::absolute( $a->getAttribute( 'href' ) ) );
		}
		foreach ( $xpath->query( './/img[@src]', $root ) as $img ) {
			$img->setAttribute( 'src', self::absolute( $img->getAttribute( 'src' ) ) );
		}

		// Class-driven layout that has to become inline.
		foreach ( $xpath->query( './/*[contains(@class,"has-text-align-center") or contains(@class,"aligncenter")]', $root ) as $el ) {
			$el->setAttribute( 'style', 'text-align:center;' . $el->getAttribute( 'style' ) );
		}
		foreach ( $xpath->query( './/*[contains(@class,"has-text-align-right")]', $root ) as $el ) {
			$el->setAttribute( 'style', 'text-align:right;' . $el->getAttribute( 'style' ) );
		}

		// Tag defaults first, then whatever the author set in the editor, so
		// a colour or alignment chosen there still wins. Embed cards are
		// LetterWP's own and already styled.
		foreach ( self::styles() as $tag => $style ) {
			foreach ( $xpath->query( './/' . $tag . '[not(ancestor::*[@data-letterwp])]', $root ) as $el ) {
				$el->setAttribute( 'style', $style . $el->getAttribute( 'style' ) );
			}
		}

		// Inside a quote, text takes the quote's voice, not the paragraph's.
		foreach ( $xpath->query( './/blockquote//p', $root ) as $p ) {
			$p->setAttribute( 'style', 'font-family:inherit;font-size:inherit;line-height:inherit;color:inherit;margin:0 0 .6em;' );
		}
		foreach ( $xpath->query( './/pre//code', $root ) as $code ) {
			$code->setAttribute( 'style', 'font:inherit;background:none;padding:0;color:inherit;' );
		}

		// Pull quotes read as a centred break.
		foreach ( $xpath->query( './/*[contains(@class,"wp-block-pullquote")]', $root ) as $pq ) {
			$pq->setAttribute( 'style', 'margin:2em 0;padding:1.4em 0;border-top:2px solid ' . self::INK . ';border-bottom:2px solid ' . self::INK . ';text-align:center;' );
			foreach ( $xpath->query( './/blockquote', $pq ) as $bq ) {
				$bq->setAttribute( 'style', 'font-family:' . self::heading() . ';font-size:22px;line-height:1.45;margin:0;padding:0;border:0;color:' . self::INK . ';font-style:normal;font-weight:600;' );
			}
		}

		// Buttons look like nothing without their stylesheet.
		foreach ( $xpath->query( './/*[contains(@class,"wp-block-button__link")]', $root ) as $btn ) {
			$btn->setAttribute( 'style', self::button_style() );
		}
		foreach ( $xpath->query( './/*[contains(@class,"wp-block-buttons")]', $root ) as $wrap ) {
			$wrap->setAttribute( 'style', 'margin:0 0 1.4em;' );
		}

		// Images: the responsive machinery out, a width every client honours in.
		$full = self::WIDTH - 2 * self::PAD;
		foreach ( $xpath->query( './/img[not(ancestor::*[@data-letterwp])]', $root ) as $img ) {
			$width = (int) $img->getAttribute( 'width' );
			if ( preg_match( '/(?:^|;)\s*width\s*:\s*(\d+)px/i', $img->getAttribute( 'style' ), $m ) ) {
				$width = (int) $m[1];
			}

			foreach ( array( 'srcset', 'sizes', 'loading', 'decoding', 'fetchpriority', 'width', 'height' ) as $attr ) {
				$img->removeAttribute( $attr );
			}

			if ( $width > 0 && $width < $full ) {
				$img->setAttribute( 'width', (string) $width );
				$img->setAttribute( 'style', $img->getAttribute( 'style' ) . ';width:' . $width . 'px;max-width:100%;height:auto;' . ( self::centred( $img ) ? 'margin:0 auto;' : '' ) );
			} else {
				$img->setAttribute( 'width', (string) $full );
				$img->setAttribute( 'style', $img->getAttribute( 'style' ) . ';width:100%;max-width:100%;height:auto;' );
			}
		}

		// Paragraphs left empty by what was taken out above.
		foreach ( iterator_to_array( $root->getElementsByTagName( 'p' ) ) as $p ) {
			if ( '' === trim( str_replace( "\xc2\xa0", '', $p->textContent ) ) && ! $p->getElementsByTagName( 'img' )->length ) {
				$p->parentNode->removeChild( $p );
			}
		}

		// Classes, ids and data attributes mean nothing without the theme.
		foreach ( $xpath->query( './/*[@class or @id or @data-id or @data-type or @data-letterwp]', $root ) as $el ) {
			foreach ( array( 'class', 'id', 'data-id', 'data-type', 'data-letterwp' ) as $attr ) {
				$el->removeAttribute( $attr );
			}
		}

		$out = '';
		foreach ( $root->childNodes as $child ) {
			$out .= $doc->saveHTML( $child );
		}

		return $out;
	}

	/** @return bool The image sits in something centred. */
	private static function centred( DOMElement $el ) {
		for ( $node = $el; $node instanceof DOMElement; $node = $node->parentNode ) {
			if ( false !== strpos( $node->getAttribute( 'style' ), 'text-align:center' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function replace_with_html( DOMNode $node, $html ) {
		$doc = $node->ownerDocument;

		// Alone in a paragraph (wpautop wraps a pasted iframe in one): the
		// card replaces the paragraph, since a div cannot sit inside a p.
		$parent = $node->parentNode;
		if ( $parent instanceof DOMElement && 'p' === strtolower( $parent->nodeName ) && '' === trim( str_replace( $node->textContent, '', $parent->textContent ) ) && 1 === $parent->getElementsByTagName( '*' )->length - $node->getElementsByTagName( '*' )->length ) {
			$node = $parent;
		}

		if ( '' === $html ) {
			$node->parentNode->removeChild( $node );
			return;
		}

		$tmp  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$tmp->loadHTML( '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="letterwp-tmp">' . $html . '</div></body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$wrap = $tmp->getElementById( 'letterwp-tmp' );

		foreach ( iterator_to_array( $wrap->childNodes ) as $child ) {
			$node->parentNode->insertBefore( $doc->importNode( $child, true ), $node );
		}

		$node->parentNode->removeChild( $node );
	}

	/** @return string */
	private static function absolute( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url || preg_match( '#^(https?:|mailto:|tel:|\#|\{\{\{)#i', $url ) ) {
			return $url;
		}

		if ( 0 === strpos( $url, '//' ) ) {
			return set_url_scheme( 'https:' . $url );
		}

		return WP_Http::make_absolute_url( $url, home_url( '/' ) );
	}

	/** @return string */
	private static function button_style() {
		return 'display:inline-block;background:' . self::t( 'button_color' ) . ';color:' . self::t( 'button_text_color' ) . ';text-decoration:none;padding:12px 22px;border-radius:' . self::t( 'button_radius' ) . 'px;font-weight:600;font-size:15px;line-height:1.2;font-family:' . self::sans() . ';';
	}

	/* ---------------------------------------------------------------------
	 * Embeds → a picture that links to them.
	 * ------------------------------------------------------------------ */

	/**
	 * A thumbnail with a play button for video, a card for anything else.
	 *
	 * @param string $url     What was embedded.
	 * @param string $caption The block's caption, if any.
	 * @return string
	 */
	private static function embed_card( $url, $caption = '' ) {
		$data     = self::oembed( $url );
		$provider = '' !== $data['provider'] ? $data['provider'] : self::host_name( $url );
		$title    = $data['title'];
		$verb     = self::verb( $url, $data );
		$thumb    = '' !== $data['thumb'] ? self::thumbnail( $data['thumb'], $url, 'watch' === $verb ) : '';
		$label    = sprintf( self::verb_label( $verb ), $provider );
		$small    = 'font-family:' . self::sans() . ';font-size:14px;line-height:1.5;margin:0;color:' . self::MUTED . ';';

		if ( '' !== $thumb ) {
			$full = self::WIDTH - 2 * self::PAD;

			return '<div data-letterwp="1" style="margin:0 0 1.6em;">'
				. '<a href="' . esc_url( $url ) . '" style="display:block;text-decoration:none;">'
				. '<img src="' . esc_url( $thumb ) . '" width="' . $full . '" alt="' . esc_attr( $title ) . '" style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:' . self::t( 'image_radius' ) . 'px;" />'
				. '</a>'
				. '<p style="' . $small . 'margin-top:.6em;">'
				. '<a href="' . esc_url( $url ) . '" style="color:' . self::INK . ';font-weight:600;text-decoration:none;">' . esc_html( $label ) . ' &rarr;</a>'
				. ( '' !== $title ? ' &nbsp;' . esc_html( $title ) : '' )
				. '</p>'
				. ( '' !== $caption ? '<p style="' . $small . 'margin-top:.3em;font-size:13px;">' . esc_html( $caption ) . '</p>' : '' )
				. '</div>';
		}

		return '<div data-letterwp="1" style="margin:0 0 1.6em;padding:16px 18px;border:1px solid ' . self::LINE . ';border-radius:6px;">'
			. '<p style="' . $small . 'font-size:12px;text-transform:uppercase;letter-spacing:.06em;">' . esc_html( $provider ) . '</p>'
			. ( '' !== $title ? '<p style="font-family:' . self::sans() . ';font-size:16px;line-height:1.45;margin:.3em 0 0;color:' . self::INK . ';font-weight:600;">' . esc_html( $title ) . '</p>' : '' )
			. '<p style="' . $small . 'margin-top:.5em;"><a href="' . esc_url( $url ) . '" style="color:' . self::INK . ';font-weight:600;">' . esc_html( $label ) . ' &rarr;</a></p>'
			. ( '' !== $caption ? '<p style="' . $small . 'margin-top:.3em;font-size:13px;">' . esc_html( $caption ) . '</p>' : '' )
			. '</div>';
	}

	/**
	 * A video or audio file: its poster, or a line, linking to the post,
	 * where it plays.
	 *
	 * @return string
	 */
	private static function media_card( $tag, $poster ) {
		$post = get_post();
		$url  = $post && is_post_publicly_viewable( $post ) ? get_permalink( $post ) : home_url( '/' );
		$text = 'video' === $tag ? __( 'Watch the video on the web', 'letterwp' ) : __( 'Listen on the web', 'letterwp' );
		$out  = '<div data-letterwp="1" style="margin:0 0 1.6em;">';

		if ( 'video' === $tag && '' !== $poster ) {
			$out .= '<a href="' . esc_url( $url ) . '" style="display:block;"><img src="' . esc_url( self::absolute( $poster ) ) . '" width="' . ( self::WIDTH - 2 * self::PAD ) . '" alt="" style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:' . self::t( 'image_radius' ) . 'px;" /></a>';
		}

		return $out . '<p style="font-family:' . self::sans() . ';font-size:14px;margin:.6em 0 0;"><a href="' . esc_url( $url ) . '" style="color:' . self::INK . ';font-weight:600;text-decoration:none;">' . ( 'video' === $tag ? '&#9654; ' : '&#9835; ' ) . esc_html( $text ) . ' &rarr;</a></p></div>';
	}

	/**
	 * What the provider says about a URL, cached: a month when it answered,
	 * an hour when it did not.
	 *
	 * @return array{title:string,provider:string,thumb:string,type:string}
	 */
	private static function oembed( $url ) {
		$empty = array(
			'title'    => '',
			'provider' => '',
			'thumb'    => '',
			'type'     => '',
		);
		$key   = 'letterwp_oembed_' . md5( $url );
		$data  = get_transient( $key );

		if ( is_array( $data ) ) {
			return array_merge( $empty, $data );
		}

		$data = $empty;
		$got  = _wp_oembed_get_object()->get_data( $url, array( 'discover' => true ) );

		if ( $got ) {
			$data = array(
				'title'    => isset( $got->title ) ? (string) $got->title : ( isset( $got->author_name ) ? (string) $got->author_name : '' ),
				'provider' => isset( $got->provider_name ) ? (string) $got->provider_name : '',
				'thumb'    => isset( $got->thumbnail_url ) ? (string) $got->thumbnail_url : '',
				'type'     => isset( $got->type ) ? (string) $got->type : '',
			);
		}

		set_transient( $key, $data, $got ? MONTH_IN_SECONDS : HOUR_IN_SECONDS );

		return $data;
	}

	/** @return bool WordPress knows how to embed this URL. */
	private static function is_embeddable( $url ) {
		return (bool) _wp_oembed_get_object()->get_provider( $url, array( 'discover' => false ) );
	}

	/** @return string watch, listen or view. */
	private static function verb( $url, array $data ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( preg_match( '/(spotify|soundcloud|mixcloud|anghami|pocketcasts)\./', $host ) ) {
			return 'listen';
		}

		if ( 'video' === $data['type'] || self::youtube_id( $url ) || preg_match( '/(vimeo|tiktok|dailymotion|videopress|wordpress\.tv|ted)\./', $host ) ) {
			return 'watch';
		}

		return 'view';
	}

	/** @return string With %s for the provider's name. */
	private static function verb_label( $verb ) {
		switch ( $verb ) {
			case 'watch':
				/* translators: %s: provider name, e.g. YouTube. */
				return __( 'Watch on %s', 'letterwp' );
			case 'listen':
				/* translators: %s: provider name, e.g. Spotify. */
				return __( 'Listen on %s', 'letterwp' );
		}

		/* translators: %s: provider name, e.g. X. */
		return __( 'View on %s', 'letterwp' );
	}

	/** @return string "youtube.com" for https://www.youtube.com/watch… */
	private static function host_name( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/** @return string The 11-character video id, or ''. */
	private static function youtube_id( $url ) {
		return preg_match( '#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', (string) $url, $m ) ? $m[1] : '';
	}

	/** @return string The page an iframe's player belongs to, or ''. */
	private static function iframe_url( $src ) {
		$src = self::absolute( html_entity_decode( (string) $src ) );
		$yt  = self::youtube_id( $src );

		if ( '' !== $yt ) {
			return 'https://www.youtube.com/watch?v=' . $yt;
		}

		if ( preg_match( '#player\.vimeo\.com/video/(\d+)#', $src, $m ) ) {
			return 'https://vimeo.com/' . $m[1];
		}

		return preg_match( '#^https?://#', $src ) ? $src : '';
	}

	/**
	 * A thumbnail cropped to 16:9, with a play button on it for video, saved
	 * once in uploads/letterwp/embeds/. Falls back to the provider's own
	 * image when it cannot be made (no GD, uploads not writable).
	 *
	 * For YouTube the largest still is tried first: the oEmbed one is 4:3
	 * with black bars, which the crop then removes.
	 *
	 * @return string Its URL.
	 */
	private static function thumbnail( $src, $url, $play ) {
		$sources = array( $src );
		$yt      = self::youtube_id( $url );

		if ( '' !== $yt ) {
			$sources = array( 'https://i.ytimg.com/vi/' . $yt . '/maxresdefault.jpg', 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg' );
		} elseif ( preg_match( '#^(https://i\.vimeocdn\.com/video/[^?]+?)-d_\d+x\d+#', $src, $m ) ) {
			// Vimeo's oEmbed still is 295 wide; its CDN makes any size.
			array_unshift( $sources, $m[1] . '-d_1280x720' );
		}

		if ( ! $play || ! function_exists( 'imagecreatefromstring' ) ) {
			return end( $sources );
		}

		$uploads = wp_upload_dir();

		$file = 'letterwp/embeds/' . md5( implode( '|', $sources ) . '|' . self::THUMB_VERSION ) . '.jpg';
		$path = trailingslashit( $uploads['basedir'] ) . $file;
		$link = trailingslashit( $uploads['baseurl'] ) . $file;

		if ( empty( $uploads['error'] ) && file_exists( $path ) ) {
			return $link;
		}

		$image = null;
		$found = end( $sources );
		foreach ( $sources as $source ) {
			$response = wp_remote_get( $source, array( 'timeout' => 10 ) );
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$image = @imagecreatefromstring( wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a bad image is a fallback, not an error.
				if ( $image ) {
					$found = $source;
					break;
				}
			}
		}

		if ( ! $image || ! empty( $uploads['error'] ) || ! wp_mkdir_p( dirname( $path ) ) ) {
			return $found;
		}

		$made = self::play_still( $image );
		self::free( $image );

		if ( ! @imagejpeg( $made, $path, 85 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unwritable folder is a fallback, not an error.
			self::free( $made );
			return $found;
		}

		self::free( $made );

		return $link;
	}

	/**
	 * Crop to 16:9 from the centre, at most 1200 wide, and draw a play
	 * button: a dark circle with a white triangle, drawn four times larger
	 * and scaled down, because GD does not smooth the edges of shapes.
	 *
	 * @param resource|GdImage $src
	 * @return resource|GdImage
	 */
	private static function play_still( $src ) {
		$sw = imagesx( $src );
		$sh = imagesy( $src );

		if ( $sw / $sh > 16 / 9 ) {
			$cw = (int) round( $sh * 16 / 9 );
			$ch = $sh;
		} else {
			$cw = $sw;
			$ch = (int) round( $sw * 9 / 16 );
		}

		$w   = min( 1200, $cw );
		$h   = (int) round( $w * 9 / 16 );
		$out = imagecreatetruecolor( $w, $h );

		imagecopyresampled( $out, $src, 0, 0, (int) ( ( $sw - $cw ) / 2 ), (int) ( ( $sh - $ch ) / 2 ), $w, $h, $cw, $ch );

		$d   = max( 56, min( 140, (int) round( $w * 0.14 ) ) );
		$big = $d * 4;
		$btn = imagecreatetruecolor( $big, $big );

		imagealphablending( $btn, false );
		imagefill( $btn, 0, 0, imagecolorallocatealpha( $btn, 0, 0, 0, 127 ) );
		imagealphablending( $btn, true );
		imagefilledellipse( $btn, (int) ( $big / 2 ), (int) ( $big / 2 ), $big - 4, $big - 4, imagecolorallocatealpha( $btn, 0, 0, 0, 30 ) );

		$th  = $big * 0.36;
		$tw  = $th * 0.88;
		$cx  = $big / 2 + $big * 0.04;
		$pts = array(
			(int) ( $cx - $tw / 2 ), (int) ( $big / 2 - $th / 2 ),
			(int) ( $cx - $tw / 2 ), (int) ( $big / 2 + $th / 2 ),
			(int) ( $cx + $tw / 2 ), (int) ( $big / 2 ),
		);
		$white = imagecolorallocate( $btn, 255, 255, 255 );

		if ( PHP_VERSION_ID >= 80000 ) {
			imagefilledpolygon( $btn, $pts, $white );
		} else {
			imagefilledpolygon( $btn, $pts, 3, $white );
		}

		imagealphablending( $out, true );
		imagecopyresampled( $out, $btn, (int) ( ( $w - $d ) / 2 ), (int) ( ( $h - $d ) / 2 ), 0, 0, $d, $d, $big, $big );
		self::free( $btn );

		return $out;
	}

	/** GD images free themselves since PHP 8, and imagedestroy() is deprecated in 8.5. */
	private static function free( $image ) {
		if ( PHP_VERSION_ID < 80000 ) {
			imagedestroy( $image );
		}
	}

	/* ---------------------------------------------------------------------
	 * The pieces around the post.
	 * ------------------------------------------------------------------ */

	/** @return string "By Name · 1 October 2026", either half, or '' as the design says. */
	private static function byline( WP_Post $post ) {
		$time   = get_post_timestamp( $post );
		$date   = self::t( 'date' ) ? wp_date( get_option( 'date_format' ), $time ? $time : time() ) : '';
		$author = self::t( 'author' ) ? (string) get_the_author_meta( 'display_name', $post->post_author ) : '';

		if ( '' !== $author && '' !== $date ) {
			/* translators: 1: author name. 2: date. */
			return sprintf( __( 'By %1$s · %2$s', 'letterwp' ), $author, $date );
		}

		/* translators: %s: author name. */
		return '' !== $author ? sprintf( __( 'By %s', 'letterwp' ), $author ) : $date;
	}

	/** @return array<string,string> The post's categories: name => archive URL. */
	private static function categories( WP_Post $post ) {
		$out = array();

		foreach ( get_the_category( $post->ID ) as $term ) {
			$out[ $term->name ] = get_category_link( $term );
		}

		return $out;
	}

	/**
	 * The excerpt, or the post's opening words when it has none, as email
	 * HTML. The Read more button after it is document()'s.
	 *
	 * @param string $content The post as email HTML.
	 * @return string
	 */
	private static function excerpt( WP_Post $post, $content ) {
		$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( $content, 55 );
		$text = trim( preg_replace( '/\s+/', ' ', wp_specialchars_decode( wp_strip_all_tags( $text ), ENT_QUOTES ) ) );

		return '' !== $text ? self::inline( '<p>' . esc_html( $text ) . '</p>' ) : '';
	}

	/** @return string The featured image's URL, or ''. */
	private static function featured_image( WP_Post $post, $thumbnail_id ) {
		$id = null === $thumbnail_id ? (int) get_post_thumbnail_id( $post ) : (int) $thumbnail_id;

		if ( ! $id ) {
			return '';
		}

		$src = wp_get_attachment_image_url( $id, 'large' );

		return $src ? self::absolute( $src ) : '';
	}

	/**
	 * The grey line an inbox shows after the subject: the excerpt, or the
	 * post's opening words.
	 *
	 * @return string
	 */
	private static function preheader( WP_Post $post, $content ) {
		$text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $content;
		$text = trim( preg_replace( '/\s+/', ' ', wp_specialchars_decode( wp_strip_all_tags( $text ), ENT_QUOTES ) ) );

		return mb_substr( $text, 0, 140 );
	}

	/**
	 * The whole email: a centred table, not a div with a max-width, because
	 * Outlook honours neither max-width nor margin:auto. The [if mso] table
	 * gives Outlook its fixed 600px; everyone else gets a fluid column that
	 * stops at 600px and fills a phone.
	 *
	 * @return string
	 */
	private static function document( array $p ) {
		$full   = self::WIDTH - 2 * self::PAD;
		$font   = 'font-family:' . self::sans() . ';';
		$muted  = $font . 'font-size:13px;line-height:1.6;color:' . self::MUTED . ';';
		$head   = 'font-family:' . self::heading() . ';';
		$icon   = get_site_icon_url( 96 );
		$logo   = self::t( 'logo' ) ? wp_get_attachment_image_src( self::t( 'logo' ), 'full' ) : false;
		$postal = self::postal_address();
		$phone  = '.letterwp-pad{padding-left:20px!important;padding-right:20px!important}'
			. '.letterwp-title{font-size:' . self::t( 'title_size_mobile' ) . 'px!important}';

		if ( self::t( 'body_size_mobile' ) !== self::t( 'body_size' ) ) {
			// The post's own paragraphs and lists only: embed cards keep their small print.
			$phone .= '.letterwp-body>p,.letterwp-body>ul,.letterwp-body>ol{font-size:' . self::t( 'body_size_mobile' ) . 'px!important}';
		}

		$html = '<!DOCTYPE html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head>'
			. '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
			. '<meta name="viewport" content="width=device-width, initial-scale=1" />'
			// Stops iOS shrinking the whole email to fit, 17px text with it.
			. '<meta name="x-apple-disable-message-reformatting" />'
			. '<meta name="color-scheme" content="light" /><meta name="supported-color-schemes" content="light" />'
			. '<title>' . esc_html( $p['subject'] ) . '</title>'
			// Clients that read <style> (Apple Mail, Gmail) get tighter phone
			// margins; the rest get the inline values, which also work.
			. '<style>@media only screen and (max-width:620px){' . $phone . '}</style>'
			. '</head><body style="margin:0;padding:0;background:#ffffff;-webkit-font-smoothing:antialiased;-webkit-text-size-adjust:100%;">';

		// The preheader, hidden, then padded so the inbox does not go looking
		// further down for more preview text.
		if ( '' !== $p['preheader'] ) {
			$html .= '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">'
				. esc_html( $p['preheader'] ) . str_repeat( '&#847;&zwnj;&nbsp;', 60 ) . '</div>';
		}

		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;"><tr><td align="center">'
			. '<!--[if mso]><table role="presentation" width="' . self::WIDTH . '" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:' . self::WIDTH . 'px;margin:0 auto;">';

		// A test says it is one.
		if ( 'test' === $p['mode'] ) {
			$html .= '<tr><td class="letterwp-pad" style="padding:16px ' . self::PAD . 'px 0;">'
				. '<p style="' . $font . 'font-size:13px;line-height:1.5;margin:0;padding:10px 14px;background:#fcf9e8;color:#6b4f00;border-radius:4px;">'
				. esc_html__( 'Test email: only you got this. The unsubscribe link works in the real send.', 'letterwp' )
				. '</p></td></tr>';
		}

		// Masthead: the logo, or the site icon; then the site's name.
		$html .= '<tr><td class="letterwp-pad" align="center" style="padding:28px ' . self::PAD . 'px 0;text-align:center;">';
		if ( $logo ) {
			$width  = min( (int) self::t( 'logo_width' ), $full );
			$height = $logo[1] ? (int) round( $width * $logo[2] / $logo[1] ) : 0;
			$html  .= '<a href="' . esc_url( home_url( '/' ) ) . '" style="text-decoration:none;"><img src="' . esc_url( self::absolute( $logo[0] ) ) . '" width="' . $width . '"' . ( $height ? ' height="' . $height . '"' : '' ) . ' alt="' . esc_attr( $p['site'] ) . '" style="display:block;margin:0 auto' . ( self::t( 'site_name' ) ? ' 10px' : '' ) . ';width:' . $width . 'px;max-width:100%;height:auto;border:0;" /></a>';
		} elseif ( $icon ) {
			$html .= '<a href="' . esc_url( home_url( '/' ) ) . '" style="text-decoration:none;"><img src="' . esc_url( $icon ) . '" width="44" height="44" alt="" style="display:block;margin:0 auto 10px;width:44px;height:44px;border:0;border-radius:8px;" /></a>';
		}
		if ( self::t( 'site_name' ) ) {
			$html .= '<a href="' . esc_url( home_url( '/' ) ) . '" style="' . $font . 'font-size:13px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:' . self::MUTED . ';text-decoration:none;">' . esc_html( $p['site'] ) . '</a>';
		}
		$html .= '<div style="border-top:1px solid ' . self::LINE . ';margin:18px 0 0;font-size:0;line-height:0;">&nbsp;</div>'
			. '</td></tr>';

		// Categories, title, byline, View in browser, featured image, the post.
		$html .= '<tr><td class="letterwp-pad letterwp-body" style="padding:30px ' . self::PAD . 'px 0;">';

		if ( $p['categories'] ) {
			$links = array();
			foreach ( $p['categories'] as $name => $url ) {
				$links[] = '<a href="' . esc_url( $url ) . '" style="color:' . self::t( 'link_color' ) . ';text-decoration:none;">' . esc_html( $name ) . '</a>';
			}
			$html .= '<p style="' . $font . 'font-size:12px;line-height:1.5;margin:0 0 10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;text-align:center;">' . implode( ' &middot; ', $links ) . '</p>';
		}

		// A campaign has no page: its title and image link nowhere, and the gap
		// "View in browser" leaves is closed under the title instead.
		$web   = '' !== $p['permalink'];
		$title = esc_html( $p['subject'] );
		$image = '' !== $p['image'] ? '<img src="' . esc_url( $p['image'] ) . '" width="' . $full . '" alt="" style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:' . self::t( 'image_radius' ) . 'px;margin:0 0 30px;" />' : '';

		$html .= '<h1 class="letterwp-title" style="' . $head . 'font-size:' . self::t( 'title_size' ) . 'px;line-height:1.25;margin:0 0 ' . ( $web ? 12 : 28 ) . 'px;color:' . self::INK . ';font-weight:700;text-align:center;">'
			. ( $web ? '<a href="' . esc_url( $p['permalink'] ) . '" style="color:' . self::INK . ';text-decoration:none;">' . $title . '</a>' : $title ) . '</h1>';

		if ( '' !== $p['byline'] ) {
			$html .= '<p style="' . $font . 'font-size:14px;line-height:1.5;margin:0 0 4px;color:' . self::MUTED . ';text-align:center;">' . esc_html( $p['byline'] ) . '</p>';
		}

		if ( $web ) {
			$html .= '<p style="' . $muted . 'font-size:12px;margin:0 0 28px;text-align:center;">'
				. '<a href="' . esc_url( $p['permalink'] ) . '" style="color:' . self::MUTED . ';text-decoration:underline;">' . esc_html__( 'View in browser', 'letterwp' ) . '</a></p>';
		}

		if ( '' !== $image ) {
			$html .= $web ? '<a href="' . esc_url( $p['permalink'] ) . '" style="display:block;text-decoration:none;">' . $image . '</a>' : $image;
		}

		$html .= $p['content'];

		// Excerpt mode: the rest is on the site.
		if ( '' !== $p['read_more'] ) {
			$html .= '<p style="margin:8px 0 12px;text-align:center;"><a href="' . esc_url( $p['permalink'] ) . '" style="' . self::button_style() . 'padding:14px 28px;font-size:16px;">' . esc_html( $p['read_more'] ) . '</a></p>';
		}

		$html .= '</td></tr>';

		// Footer.
		$html .= '<tr><td class="letterwp-pad" style="padding:24px ' . self::PAD . 'px 40px;">'
			. '<div style="border-top:1px solid ' . self::LINE . ';margin:0 0 20px;font-size:0;line-height:0;">&nbsp;</div>'
			. '<p style="' . $muted . 'text-align:center;margin:0;">'
			. sprintf(
				/* translators: %s: site name, linked. */
				esc_html__( 'You get this email because you subscribed to %s.', 'letterwp' ),
				'<a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . self::MUTED . ';">' . esc_html( $p['site'] ) . '</a>'
			)
			. '</p>';

		if ( '' !== $postal ) {
			$html .= '<p style="' . $muted . 'text-align:center;margin:8px 0 0;">' . nl2br( esc_html( $postal ), false ) . '</p>';
		}

		// Merge tags are written raw: esc_url() would mangle the braces.
		if ( self::UNSUBSCRIBE === $p['unsubscribe'] ) {
			$unsubscribe = self::UNSUBSCRIBE;
		} else {
			$unsubscribe = str_replace( 'LETTERWPKEYTAG', self::KEY_TAG, esc_url( str_replace( self::KEY_TAG, 'LETTERWPKEYTAG', $p['unsubscribe'] ) ) );
		}

		$html .= '<p style="' . $muted . 'text-align:center;margin:8px 0 0;">'
			. '<a href="' . $unsubscribe . '" style="color:' . self::MUTED . ';text-decoration:underline;">' . esc_html__( 'Unsubscribe', 'letterwp' ) . '</a>'
			. ( $web ? ' &nbsp;&middot;&nbsp; <a href="' . esc_url( $p['permalink'] ) . '" style="color:' . self::MUTED . ';text-decoration:underline;">' . esc_html__( 'View in browser', 'letterwp' ) . '</a>' : '' )
			. '</p>';

		// The credit, always on in LetterWP (LetterWP Pro removes it). Its icon is
		// a 32px PNG served by this site's own copy of the plugin, so it needs
		// nothing from letterwp.com; not SVG, which Gmail and Outlook drop.
		// In the site's own scheme: a send from WP-CLI would otherwise say http.
		if ( $p['credit'] ) {
			$html .= '<p style="' . $muted . 'font-size:12px;text-align:center;margin:24px 0 0;">'
				. '<a href="' . esc_url( self::CREDIT_URL ) . '" style="color:' . self::MUTED . ';text-decoration:none;">'
				. '<img src="' . esc_url( set_url_scheme( LETTERWP_URL . 'assets/letterwp-icon.png', wp_parse_url( home_url(), PHP_URL_SCHEME ) ) ) . '" width="16" height="16" alt="" style="display:inline-block;width:16px;height:16px;border:0;border-radius:3px;margin:0 6px 0 0;vertical-align:middle;" />'
				. '<span style="vertical-align:middle;">' . esc_html__( 'Newsletter easily with LetterWP', 'letterwp' ) . '</span>'
				. '</a></p>';
		}

		$html .= '</td></tr>';

		$html .= '</table><!--[if mso]></td></tr></table><![endif]--></td></tr></table></body></html>';

		return $html;
	}

	/**
	 * The plain-text part: what a text-only client shows, and what spam
	 * filters like to find next to the HTML.
	 *
	 * @return string
	 */
	private static function text( array $p ) {
		$body = $p['content'];

		// Links keep their address: "text (url)".
		$body = preg_replace_callback(
			'#<a\s[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is',
			function ( $m ) {
				$text = trim( wp_strip_all_tags( $m[2] ) );
				$url  = html_entity_decode( $m[1] );

				if ( '' === $text ) {
					return ''; // A linked image: its link is next to it in words.
				}

				return $text === $url ? $url : $text . ' (' . $url . ')';
			},
			$body
		);

		$body = preg_replace( '#<br\s*/?>#i', "\n", $body );
		$body = preg_replace( '#<li[^>]*>#i', '- ', $body );
		$body = preg_replace( '#</(p|h[1-6]|li|blockquote|figure|figcaption|pre|div|tr|ul|ol|table)>#i', "$0\n\n", $body );
		$body = wp_specialchars_decode( wp_strip_all_tags( $body ), ENT_QUOTES );
		$body = html_entity_decode( $body, ENT_QUOTES, 'UTF-8' );
		$body = preg_replace( "/[ \t]+\n/", "\n", $body );
		$body = trim( preg_replace( "/\n{3,}/", "\n\n", $body ) );

		$lines = array( $p['site'], '', $p['subject'] );

		if ( '' !== $p['byline'] ) {
			$lines[] = $p['byline'];
		}

		$lines[] = '';

		if ( '' !== $p['permalink'] ) {
			/* translators: %s: URL. */
			$lines[] = sprintf( __( 'View in browser: %s', 'letterwp' ), $p['permalink'] );
			$lines[] = '';
		}

		array_push( $lines, $body, '' );

		if ( '' !== $p['read_more'] ) {
			$lines[] = $p['read_more'] . ': ' . $p['permalink'];
			$lines[] = '';
		}

		$lines[] = '---';
		/* translators: %s: site name. */
		$lines[] = sprintf( __( 'You get this email because you subscribed to %s.', 'letterwp' ), $p['site'] );

		if ( '' !== self::postal_address() ) {
			$lines[] = self::postal_address();
		}

		/* translators: %s: URL. */
		$lines[] = sprintf( __( 'Unsubscribe: %s', 'letterwp' ), $p['unsubscribe'] );

		if ( $p['credit'] ) {
			$lines[] = '';
			$lines[] = __( 'Newsletter easily with LetterWP', 'letterwp' ) . ': ' . self::CREDIT_URL;
		}

		return implode( "\n", $lines ) . "\n";
	}
}
