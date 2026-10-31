/**
 * Dahu Composite — “Supplied accessories” field of a variation.
 *
 * The selector is WooCommerce's own (.wc-product-search, products and
 * variations), initialised by WooCommerce, including on a variation loaded
 * through AJAX (wc-enhanced-select-init at the end of the load). This script
 * only handles the list: an item that is chosen becomes a row; chosen twice,
 * two rows (quantity 2 per unit). Listeners are delegated on the document:
 * they hold for the variations opened afterwards.
 *
 * Every change fires “change” on a field of the variation, so that
 * WooCommerce marks it to be saved (input_changed()).
 */
( function ( $ ) {
	'use strict';

	var i18n = window.dahuSupplyAdmin || {};
	var counter = 0;

	function markChanged( $box ) {
		$box.find( '.dahu-supply__present' ).trigger( 'change' );
	}

	function buildRow( loop, id, name ) {
		var base = 'dahu_supply[' + loop + '][n' + Date.now().toString( 36 ) + ( counter++ ) + ']';
		var $row = $( '<li class="dahu-supply__row"></li>' ).attr( 'data-id', id );

		$( '<input type="hidden" />' ).attr( 'name', base + '[id]' ).val( id ).appendTo( $row );
		$( '<span class="dahu-supply__name"></span>' ).text( name ).appendTo( $row );
		$( '<button type="button" class="button-link js-dahu-supply-remove"></button>' )
			.text( i18n.remove || 'Retirer' )
			.appendTo( $row );

		return $row;
	}

	$( document.body )
		.on( 'change', '.dahu-supply__search', function () {
			var $select = $( this );
			var id = parseInt( $select.val(), 10 );

			if ( ! id ) {
				return;
			}

			var $box = $select.closest( '.dahu-supply' );
			var name = $.trim( $select.find( 'option:selected' ).last().text() ) || '#' + id;

			$box.find( '.dahu-supply__list' ).append( buildRow( $box.data( 'loop' ), id, name ) );

			// Selector emptied for the next item. “change.select2” only
			// updates the display: this handler is not called again.
			$select.empty().trigger( 'change.select2' );
			markChanged( $box );
		} )
		.on( 'click', '.js-dahu-supply-remove', function ( event ) {
			event.preventDefault();
			var $row = $( this ).closest( '.dahu-supply__row' );
			var $box = $row.closest( '.dahu-supply' );
			$row.remove();
			markChanged( $box );
		} );
}( jQuery ) );
