/**
 * Dahu Composite — WooCommerce > Dahu Kits screen: image of the
 * configurator's “None” card.
 *
 * This code used to be a <script> block written in the middle of the page: it
 * ran before the media library scripts, which WordPress puts in the footer,
 * did not find wp.media and bailed out without wiring the click up. It is now
 * loaded in the footer, after “media-editor”.
 *
 * The hidden field carries the ID of the image (the dahu_composite_none_image_id
 * option, format unchanged); nothing is written until the screen is saved.
 * Listeners are delegated on the document. Labels are read off the button
 * (data-*), translated on the PHP side.
 */
( function ( $ ) {
	'use strict';

	var frame = null;

	function field( id ) {
		return $( document.getElementById( id ) );
	}

	/*
	 * Shows or hides the preview through the `hidden` ATTRIBUTE, not through an
	 * inline display.
	 *
	 * An inline style cannot be overridden by the shop's own CSS, does not
	 * show up when somebody reads the stylesheet to find out why an element is
	 * invisible, and — the reason that matters here — leaves the markup saying
	 * nothing about the state. `hidden` says it, and assistive technology
	 * reads it.
	 */
	function showImage( id, url ) {
		field( 'dahu-composite-none-image' ).val( id ? String( id ) : '' );

		var $preview = field( 'dahu-composite-none-preview' );

		if ( id && url ) {
			$preview.attr( 'src', url ).prop( 'hidden', false );
		} else {
			$preview.attr( 'src', '' ).prop( 'hidden', true );
		}

		field( 'dahu-composite-none-clear' ).prop( 'hidden', ! id );
	}

	$( document ).on( 'click', '#dahu-composite-none-pick', function ( event ) {
		event.preventDefault();
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		var $button = $( this );
		if ( ! frame ) {
			frame = window.wp.media( {
				title: $button.data( 'frameTitle' ) || $button.text(),
				button: { text: $button.data( 'frameButton' ) || $button.text() },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var image = frame.state().get( 'selection' ).first().toJSON();
				var url = image.sizes && image.sizes.thumbnail ? image.sizes.thumbnail.url : image.url;
				showImage( image.id, url );
			} );
		}
		frame.open();
	} );

	$( document ).on( 'click', '#dahu-composite-none-clear', function ( event ) {
		event.preventDefault();
		showImage( 0, '' );
	} );
}( jQuery ) );
