/**
 * The Subscribe block in the editor: the form as it will look, with the
 * heading and line above it, the small print below it and the button's words
 * all editable in place. No build step.
 *
 * The optional lines show while the block is selected, or once they hold
 * words. Styling sits in the sidebar: form size, corner radius, max width, and the button's
 * colours. They reach the page as a size class and CSS variables, the same
 * ones LetterWP_Subscribe::render_block() prints. The heading's level (H1 for
 * a front page hero, H2 or lower inside a post) sits in the block toolbar.
 *
 * Rendered on the site by LetterWP_Subscribe::render_block().
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var be = wp.blockEditor;
	var c = wp.components;
	var ToggleGroup = c.ToggleGroupControl || c.__experimentalToggleGroupControl;
	var ToggleOption = c.ToggleGroupControlOption || c.__experimentalToggleGroupControlOption;

	var icon = el(
		'svg',
		{ className: 'letterwp-icon', xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 18, height: 18, fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true, focusable: false },
		el( 'path', { d: 'M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z' } ),
		el( 'path', { d: 'm21.854 2.147-10.94 10.939' } )
	);

	var sizes = [
		{ value: 'small', label: __( 'Small', 'letterwp' ) },
		{ value: 'normal', label: __( 'Normal', 'letterwp' ) },
		{ value: 'large', label: __( 'Large', 'letterwp' ) },
	];

	wp.blocks.registerBlockType( 'letterwp/subscribe', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var size = a.size || 'normal';
			var level = a.headingLevel || 2;

			var style = {};
			if ( 'number' === typeof a.radius ) {
				style[ '--letterwp-radius' ] = a.radius + 'px';
			}
			if ( 'number' === typeof a.maxWidth ) {
				style.maxWidth = a.maxWidth + 'px';
			}
			if ( a.buttonBackground ) {
				style[ '--letterwp-button-bg' ] = a.buttonBackground;
			}
			if ( a.buttonColor ) {
				style[ '--letterwp-button-color' ] = a.buttonColor;
			}

			var sizeControl = ToggleGroup
				? el(
					ToggleGroup,
					{ label: __( 'Size', 'letterwp' ), value: size, isBlock: true, __next40pxDefaultSize: true, __nextHasNoMarginBottom: true, onChange: function ( v ) { set( { size: v } ); } },
					sizes.map( function ( s ) {
						return el( ToggleOption, { key: s.value, value: s.value, label: s.label } );
					} )
				)
				: el( c.SelectControl, { label: __( 'Size', 'letterwp' ), value: size, options: sizes, __next40pxDefaultSize: true, __nextHasNoMarginBottom: true, onChange: function ( v ) { set( { size: v } ); } } );

			return el(
				wp.element.Fragment,
				null,
				( a.heading || props.isSelected ) && el(
					be.BlockControls,
					{ group: 'block' },
					be.HeadingLevelDropdown
						? el( be.HeadingLevelDropdown, { value: level, onChange: function ( v ) { set( { headingLevel: v } ); } } )
						: el( c.ToolbarDropdownMenu, {
							icon: 'heading',
							label: __( 'Heading level', 'letterwp' ),
							controls: [ 1, 2, 3, 4, 5, 6 ].map( function ( n ) {
								return { title: 'H' + n, isActive: n === level, onClick: function () { set( { headingLevel: n } ); } };
							} ),
						} )
				),
				el(
					be.InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Form', 'letterwp' ) },
						sizeControl,
						el( 'div', { style: { height: 16 } } ),
						el( c.RangeControl, {
							label: __( 'Border radius', 'letterwp' ),
							value: a.radius,
							min: 0,
							max: 40,
							allowReset: true,
							initialPosition: 12,
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
							onChange: function ( v ) { set( { radius: 'number' === typeof v ? v : undefined } ); },
						} ),
						el( 'div', { style: { height: 16 } } ),
						el( c.RangeControl, {
							label: __( 'Max width (px)', 'letterwp' ),
							help: __( 'Empty = as wide as the content.', 'letterwp' ),
							value: a.maxWidth,
							min: 240,
							max: 1200,
							step: 10,
							allowReset: true,
							initialPosition: 500,
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
							onChange: function ( v ) { set( { maxWidth: 'number' === typeof v ? v : undefined } ); },
						} )
					),
					el( be.PanelColorSettings, {
						title: __( 'Button', 'letterwp' ),
						initialOpen: true,
						colorSettings: [
							{
								label: __( 'Background', 'letterwp' ),
								value: a.buttonBackground,
								onChange: function ( v ) { set( { buttonBackground: v || undefined } ); },
							},
							{
								label: __( 'Text', 'letterwp' ),
								value: a.buttonColor,
								onChange: function ( v ) { set( { buttonColor: v || undefined } ); },
							},
						],
					} )
				),
				el(
					'div',
					be.useBlockProps( { className: 'letterwp-subscribe letterwp-size-' + size, style: style } ),
					( a.heading || props.isSelected ) && el( be.RichText, {
						tagName: 'h' + level,
						className: 'letterwp-subscribe__heading',
						value: a.heading,
						onChange: function ( v ) { set( { heading: v } ); },
						placeholder: __( 'Heading (optional)', 'letterwp' ),
						allowedFormats: [ 'core/bold', 'core/italic' ],
					} ),
					( a.text || props.isSelected ) && el( be.RichText, {
						tagName: 'p',
						className: 'letterwp-subscribe__text',
						value: a.text,
						onChange: function ( v ) { set( { text: v } ); },
						placeholder: __( 'A line about your newsletter (optional)', 'letterwp' ),
						allowedFormats: [ 'core/bold', 'core/italic', 'core/link' ],
					} ),
					el(
						'div',
						{ className: 'letterwp-subscribe__form letterwp-row' },
						el( 'input', { type: 'email', className: 'letterwp-input', placeholder: 'you@example.com', disabled: true } ),
						el(
							'span',
							{ className: 'wp-element-button' },
							el( be.RichText, {
								tagName: 'span',
								value: a.button,
								onChange: function ( v ) { set( { button: v } ); },
								placeholder: __( 'Subscribe', 'letterwp' ),
								allowedFormats: [],
								withoutInteractiveFormatting: true,
							} ),
							icon
						)
					),
					( a.note || props.isSelected ) && el( be.RichText, {
						tagName: 'p',
						className: 'letterwp-subscribe__note',
						value: a.note,
						onChange: function ( v ) { set( { note: v } ); },
						placeholder: __( 'Small print below the form: consent, terms, privacy (optional)', 'letterwp' ),
						allowedFormats: [ 'core/bold', 'core/italic', 'core/link' ],
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
