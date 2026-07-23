/**
 * Editor script for the Store Locator block.
 *
 * Uses the global `wp` object (no build step required). Renders a lightweight
 * placeholder in the editor and a height control in the inspector; the live map
 * is produced by the server-side render_callback on the frontend.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;
	var Placeholder = wp.components.Placeholder;

	registerBlockType( 'product-store-locator/map', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Map Settings', 'product-store-locator' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Map height (px)', 'product-store-locator' ),
							value: attributes.height,
							min: 200,
							max: 900,
							step: 10,
							onChange: function ( value ) {
								setAttributes( { height: value || 500 } );
							},
						} )
					)
				),
				el(
					Placeholder,
					{
						icon: 'location-alt',
						label: __( 'Store Locator', 'product-store-locator' ),
						instructions: __(
							'The interactive Google Map with all store markers and ZIP/postcode search will appear here on the published page.',
							'product-store-locator'
						),
					}
				)
			);
		},
		save: function () {
			// Dynamic block: rendered server-side.
			return null;
		},
	} );
} )( window.wp );
