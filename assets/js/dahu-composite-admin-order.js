/* global jQuery, dahuCompositeAdminOrder, woocommerce_admin_meta_boxes */
/**
 * Order screen: assembling a kit on a row.
 *
 * A “Configure” / “Edit” button under a kit row (rendered by
 * Dahu_Composite_Admin_Order::display_configure_button()), a
 * $.WCBackboneModal window with one block per component: a choice list
 * (selectWoo, WooCommerce's own), a preview of the product retained (image,
 * description, stock), checkboxes for a multiple-choice component, and a
 * “Done” button.
 *
 * The engine stays on the server: every change sends the selections back to
 * the server, which answers with the corrected state (hiding, single variant,
 * missing components, errors) — like the front-end configurator with /state.
 * “Done” is only enabled when the configuration is complete.
 *
 * Only targets button.js-dahu-kit-configure: the button and the window of
 * WooCommerce Composite Products (button.configure_composite /
 * button.edit_composite) are left alone.
 */
jQuery( function ( $ ) {
	'use strict';

	var params = window.dahuCompositeAdminOrder;
	if ( ! params || ! $.WCBackboneModal ) {
		return;
	}
	var i18n = params.i18n;
	var $orderItems = $( '#woocommerce-order-items' );
	var view = null;
	var ctx = null;

	$orderItems.on( 'click', 'button.js-dahu-kit-configure', function ( event ) {
		event.preventDefault();
		open( $( this ).closest( 'tr.item' ).attr( 'data-order_item_id' ), $( this ).data( 'title' ) );
	} );

	// Window closed again (cross, backdrop, Esc, “Done”): lists destroyed,
	// so that no open panel is left behind in <body>.
	$( document.body ).on( 'wc_backbone_modal_before_remove', function ( event, target ) {
		if ( 'dahu-kit-modal' === target && view ) {
			destroySelects( view.$el );
		}
	} );

	/*
	 * Window
	 */

	function open( itemId, title ) {
		var Modal = $.WCBackboneModal.View.extend( { addButton: done } );
		view = new Modal( { target: 'dahu-kit-modal', string: { title: title } } );
		ctx = { itemId: itemId, components: [], selections: {}, state: null, touched: {}, seq: 0, busy: false };

		block( content() );
		request( 'dahu_composite_admin_get_kit_schema', {} )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					window.alert( message( response, i18n.loadError ) );
					close();
					return;
				}
				ctx.components = response.data.components;
				apply( response.data.state );
				unblock( content() );
			} )
			.fail( function () {
				window.alert( i18n.loadError );
				close();
			} );
	}

	function content() {
		return view.$el.find( '.wc-backbone-modal-content' );
	}

	function close() {
		if ( view ) {
			view.closeButton( { preventDefault: function () {} } );
		}
	}

	function request( action, data ) {
		var payload = $.extend(
			{
				action: action,
				security: params.nonce,
				order_id: woocommerce_admin_meta_boxes.post_id,
				item_id: ctx.itemId
			},
			data
		);
		// Customer chosen on the screen, even before it is saved: the server
		// applies their role (prices) and the options reserved for them.
		if ( $( '#customer_user' ).length ) {
			payload.customer_user = $( '#customer_user' ).val() || '';
		}
		return $.ajax( { url: params.ajaxUrl, type: 'POST', dataType: 'json', data: payload } );
	}

	function message( response, fallback ) {
		return response && response.data && response.data.message ? response.data.message : fallback;
	}

	/*
	 * State
	 */

	function apply( state ) {
		ctx.state = state;
		ctx.selections = $.isPlainObject( state.selections ) ? state.selections : {};
		render();
	}

	// Recomputation on the server; only the last answer counts.
	function refresh() {
		var seq = ++ctx.seq;
		setBusy( true );
		request( 'dahu_composite_admin_kit_state', { selections: JSON.stringify( ctx.selections ) } )
			.done( function ( response ) {
				if ( seq !== ctx.seq ) {
					return;
				}
				if ( response && response.success ) {
					apply( response.data );
				} else {
					status( message( response, i18n.loadError ), true );
				}
			} )
			.fail( function () {
				if ( seq === ctx.seq ) {
					status( i18n.loadError, true );
				}
			} )
			.always( function () {
				if ( seq === ctx.seq ) {
					setBusy( false );
				}
			} );
	}

	function setBusy( busy ) {
		ctx.busy = busy;
		view.$el.find( '.dahu-kit-modal__form' ).toggleClass( 'is-busy', busy );
		updateDone();
	}

	function updateDone() {
		view.$el.find( '#btn-ok' ).prop( 'disabled', ctx.busy || ! ctx.state || ! ctx.state.valid );
	}

	function status( text, isError ) {
		view.$el.find( '.dahu-kit-modal__status' ).text( text || '' ).toggleClass( 'is-error', !! isError );
	}

	function hiddenOptions( component ) {
		var hidden = ctx.state && ctx.state.hidden_options ? ctx.state.hidden_options[ component.id ] : null;
		var set = {};
		$.each( hidden || [], function ( index, value ) {
			set[ String( value ) ] = true;
		} );
		return set;
	}

	/*
	 * “Done”
	 */

	function done( event ) {
		if ( event && event.preventDefault ) {
			event.preventDefault();
		}
		if ( ctx.busy || ! ctx.state || ! ctx.state.valid ) {
			$.each( ctx.components, function ( index, component ) {
				ctx.touched[ component.id ] = true;
			} );
			renderMessages();
			return;
		}

		block( content() );
		status( '' );
		request(
			'dahu_composite_admin_configure_kit_line',
			$.extend( { selections: JSON.stringify( ctx.selections ) }, taxableAddress() )
		)
			.done( function ( response ) {
				if ( response && response.success ) {
					// The same refresh as WooCommerce (and WCCP) after an
					// item is added: item table and notes.
					$orderItems.find( '.inside' ).empty().append( response.data.html );
					$orderItems.trigger( 'wc_order_items_reloaded' );
					if ( response.data.notes_html ) {
						$( 'ul.order_notes' ).empty().append( $( response.data.notes_html ).find( 'li' ) );
					}
					close();
					return;
				}
				unblock( content() );
				status( message( response, i18n.saveError ), true );
			} )
			.fail( function () {
				unblock( content() );
				status( i18n.saveError, true );
			} );
	}

	// Taxation address entered on the screen (read the same way as WooCommerce).
	function taxableAddress() {
		var prefix = 'shipping' === woocommerce_admin_meta_boxes.tax_based_on ? '#_shipping_' : '#_billing_';
		if ( ! $( prefix + 'country' ).val() ) {
			prefix = '#_billing_';
		}
		return {
			country: $( prefix + 'country' ).val() || '',
			state: $( prefix + 'state' ).val() || '',
			postcode: $( prefix + 'postcode' ).val() || '',
			city: $( prefix + 'city' ).val() || ''
		};
	}

	/*
	 * Rendering
	 */

	function render() {
		var $form = view.$el.find( '.dahu-kit-modal__form' );
		var hidden = {};
		$.each( ctx.state.hidden_components || [], function ( index, id ) {
			hidden[ id ] = true;
		} );

		destroySelects( $form );
		$form.empty();

		$form.append( $( '<p class="dahu-kit-modal__blocked" />' ).text( i18n.blocked ).toggle( !! ( ctx.state.blocked && ctx.state.blocked.length ) ) );

		$.each( ctx.components, function ( index, component ) {
			// Component hidden by the configuration: absent, as on the front end.
			if ( hidden[ component.id ] ) {
				return;
			}
			$form.append( renderComponent( component ) );
		} );

		// Panel of the lists attached to <body> (the default setting), like the
		// WooCommerce “Add item(s)” window: selectWoo only removes the
		// HORIZONTAL offset of another dropdownParent, not the
		// vertical one — inside .wc-backbone-modal-content (fixed position +
		// transform), the panel dropped by the page scroll plus the
		// margin of the window. WooCommerce's .select2-dropdown { z-index:
		// 999999 } rule keeps it in front of the window; selectWoo puts it
		// back in place when the window scrolls.
		$form.find( 'select.enhanced' ).each( function () {
			var $select = $( this );
			$select.selectWoo( {
				width: '100%',
				allowClear: !! $select.data( 'allow-clear' ),
				placeholder: $select.data( 'placeholder' ) || ''
			} );
		} );

		renderMessages();
		updateDone();
	}

	function destroySelects( $scope ) {
		$scope.find( 'select.enhanced' ).each( function () {
			if ( $( this ).data( 'select2' ) ) {
				$( this ).selectWoo( 'destroy' );
			}
		} );
	}

	function renderComponent( component ) {
		var $block = $( '<div class="dahu-kit-component" />' ).attr( 'data-component', component.id );
		var $title = $( '<h3 class="dahu-kit-component__title" />' ).text( component.title );
		if ( component.optional ) {
			$title.append( ' ', $( '<span class="dahu-kit-component__optional" />' ).text( i18n.optional ) );
		}
		$block.append( $title );

		if ( 'multiple' === component.type ) {
			renderMultiple( component, $block );
		} else {
			renderSingle( component, $block );
		}

		$block.append( '<div class="dahu-kit-component__messages" />' );
		return $block;
	}

	// Single-choice component (or a branch): one choice list,
	// variants grouped under their product, then the preview.
	function renderSingle( component, $block ) {
		var hidden = hiddenOptions( component );
		var selection = ctx.selections[ component.id ] || null;
		var current = selectionValue( component, selection );
		var $select = $( '<select class="enhanced dahu-kit-component__select" />' )
			.attr( 'data-placeholder', component.optional ? i18n.none : i18n.choose )
			.attr( 'data-allow-clear', component.optional ? '1' : '' );

		$select.append( '<option value=""></option>' );
		$.each( component.options, function ( index, option ) {
			if ( 'path' === component.type ) {
				if ( hidden[ option.value ] ) {
					return;
				}
				$select.append(
					$( '<option />' )
						.val( option.value )
						.text( option.name + ( option.coming_soon ? ' (' + i18n.comingSoon + ')' : '' ) )
						.prop( 'disabled', option.coming_soon )
				);
				return;
			}
			if ( hidden[ String( option.product_id ) ] ) {
				return;
			}
			if ( option.variations.length ) {
				var $group = $( '<optgroup />' ).attr( 'label', option.name );
				$.each( option.variations, function ( vIndex, variation ) {
					if ( hidden[ option.product_id + ':' + variation.variation_id ] ) {
						return;
					}
					$group.append( $( '<option />' ).val( option.product_id + ':' + variation.variation_id ).text( option.name + ' — ' + variation.name + idSuffix( variation.variation_id ) + stockSuffix( variation ) ) );
				} );
				if ( $group.children().length ) {
					$select.append( $group );
				}
				return;
			}
			$select.append( $( '<option />' ).val( String( option.product_id ) ).text( option.name + idSuffix( option.product_id ) + stockSuffix( option ) ) );
		} );
		$select.val( current );

		$select.on( 'change', function () {
			ctx.touched[ component.id ] = true;
			setSingle( component, $( this ).val() || '' );
			refresh();
		} );
		$select.on( 'select2:close', function () {
			ctx.touched[ component.id ] = true;
			renderMessages();
		} );

		var $field = $( '<div class="dahu-kit-component__field" />' ).append( $select );
		if ( 'path' !== component.type && selection && component.quantity_editable && component.quantity_max > component.quantity_min ) {
			$field.append( quantityInput( component, selection ) );
		}
		$block.append( $field );

		if ( 'path' === component.type ) {
			var pathOption = findPathOption( component, current );
			// Branch: a preview only when it brings more than the label does.
			if ( pathOption && ( pathOption.image || pathOption.description ) ) {
				$block.append( preview( { name: pathOption.name, description: pathOption.description, image: pathOption.image } ) );
			}
			return;
		}
		if ( selection && selection.product_id ) {
			var found = findProduct( component, selection );
			if ( found ) {
				$block.append( preview( productPreview( found.option, found.variation ) ) );
				$block.append( bundleControls( component, found.option, selection, function ( conf ) {
					selection.bundle = conf;
				} ) );
			}
		}
	}

	// Multiple-choice component: one checkbox per option, the variant beside
	// a ticked option that has variants, then its preview.
	function renderMultiple( component, $block ) {
		var hidden = hiddenOptions( component );
		var items = $.isArray( ctx.selections[ component.id ] ) ? ctx.selections[ component.id ] : [];
		var $list = $( '<ul class="dahu-kit-component__choices" />' );

		$.each( component.options, function ( index, option ) {
			if ( hidden[ String( option.product_id ) ] ) {
				return;
			}
			var item = findItem( items, option.product_id );
			var $row = $( '<li class="dahu-kit-component__choice" />' );
			var $check = $( '<input type="checkbox" />' ).prop( 'checked', !! item );
			var $label = $( '<label />' ).append( $check, ' ', $( '<span />' ).text( option.name ), ' ', stockBadge( option ) );
			$row.append( $label );

			$check.on( 'change', function () {
				ctx.touched[ component.id ] = true;
				var list = items.filter( function ( other ) {
					return Number( other.product_id ) !== Number( option.product_id );
				} );
				if ( this.checked ) {
					list.push( { product_id: option.product_id, quantity: Math.max( 1, component.quantity_min ) } );
				}
				if ( list.length ) {
					ctx.selections[ component.id ] = list;
				} else {
					delete ctx.selections[ component.id ];
				}
				refresh();
			} );

			if ( item ) {
				if ( option.variations.length ) {
					var $variation = $( '<select class="dahu-kit-component__variation" />' ).append( $( '<option value="" />' ).text( i18n.choose ) );
					$.each( option.variations, function ( vIndex, variation ) {
						if ( hidden[ option.product_id + ':' + variation.variation_id ] ) {
							return;
						}
						$variation.append( $( '<option />' ).val( variation.variation_id ).text( variation.name + stockSuffix( variation ) ) );
					} );
					$variation.val( item.variation_id ? String( item.variation_id ) : '' );
					$variation.on( 'change', function () {
						item.variation_id = Number( $( this ).val() ) || null;
						refresh();
					} );
					$row.append( $( '<label class="dahu-kit-component__variation-label" />' ).append( $( '<span />' ).text( i18n.variation + ' ' ), $variation ) );
				}
				var variation = findVariation( option, item.variation_id );
				$row.append( preview( productPreview( option, variation ) ) );
				$row.append( bundleControls( component, option, item, function ( conf ) {
					item.bundle = conf;
				} ) );
			}
			$list.append( $row );
		} );
		$block.append( $list );
	}

	// Bundle items to settle: optional ones (a checkbox) and a variant to choose.
	function bundleControls( component, option, selection, setConf ) {
		var $wrap = $( '<div class="dahu-kit-component__bundle" />' );
		if ( ! option.bundle || ! option.bundle.length ) {
			return $wrap;
		}
		var conf = $.isPlainObject( selection.bundle ) ? $.extend( true, {}, selection.bundle ) : {};

		$.each( option.bundle, function ( index, bundled ) {
			var key = String( bundled.bundled_item_id );
			var entry = conf[ key ] || { selected: ! bundled.optional };
			var included = ! bundled.optional || !! entry.selected;
			var $row = $( '<div class="dahu-kit-component__bundle-item" />' );

			if ( bundled.optional ) {
				var $check = $( '<input type="checkbox" />' ).prop( 'checked', included );
				$row.append( $( '<label />' ).append( $check, ' ', $( '<span />' ).text( bundled.title ) ) );
				$check.on( 'change', function () {
					conf[ key ] = $.extend( {}, conf[ key ], { selected: this.checked } );
					setConf( conf );
					refresh();
				} );
			} else {
				$row.append( $( '<span class="dahu-kit-component__bundle-title" />' ).text( bundled.title ) );
			}

			if ( included && bundled.variations.length > 1 ) {
				var $select = $( '<select />' ).append( $( '<option value="" />' ).text( i18n.choose ) );
				$.each( bundled.variations, function ( vIndex, variation ) {
					$select.append( $( '<option />' ).val( variation.variation_id ).text( variation.name + stockSuffix( variation ) ) );
				} );
				$select.val( entry.variation_id ? String( entry.variation_id ) : '' );
				$select.on( 'change', function () {
					conf[ key ] = $.extend( { selected: true }, conf[ key ], { variation_id: Number( $( this ).val() ) || 0 } );
					setConf( conf );
					refresh();
				} );
				$row.append( ' ', $select );
			}
			$wrap.append( $row );
		} );
		return $wrap;
	}

	function quantityInput( component, selection ) {
		var $input = $( '<input type="number" class="dahu-kit-component__quantity" step="1" />' )
			.attr( { min: Math.max( 1, component.quantity_min ), max: component.quantity_max, 'aria-label': i18n.quantity } )
			.val( selection.quantity || Math.max( 1, component.quantity_min ) );
		$input.on( 'change', function () {
			var value = parseInt( $( this ).val(), 10 ) || 1;
			selection.quantity = Math.min( component.quantity_max, Math.max( Math.max( 1, component.quantity_min ), value ) );
			refresh();
		} );
		return $( '<label class="dahu-kit-component__quantity-label" />' ).append( $( '<span />' ).text( i18n.quantity + ' ' ), $input );
	}

	function preview( data ) {
		var $card = $( '<div class="dahu-kit-preview" />' );
		if ( data.image ) {
			$card.append( $( '<img class="dahu-kit-preview__image" alt="" />' ).attr( 'src', data.image ) );
		}
		var $body = $( '<div class="dahu-kit-preview__body" />' );
		$body.append( $( '<strong class="dahu-kit-preview__name" />' ).text( data.name ) );
		if ( data.realName && data.realName !== data.name ) {
			$body.append( $( '<div class="dahu-kit-preview__meta" />' ).text( i18n.product + ' : ' + data.realName ) );
		}
		if ( data.sku ) {
			$body.append( $( '<div class="dahu-kit-preview__meta" />' ).text( i18n.sku + ' : ' + data.sku ) );
		}
		if ( data.stock ) {
			$body.append( $( '<div />' ).append( stockBadge( data.stock ) ) );
		}
		if ( data.description ) {
			// Description passed through wp_kses_post() on the server.
			$body.append( $( '<div class="dahu-kit-preview__description" />' ).html( data.description ) );
		}
		return $card.append( $body );
	}

	function productPreview( option, variation ) {
		var facts = variation || option;
		return {
			name: variation ? option.name + ' — ' + variation.name : option.name,
			realName: facts.real_name,
			sku: facts.sku,
			image: ( variation && variation.image ) || option.image,
			description: ( variation && variation.description ) || option.description,
			stock: facts
		};
	}

	function stockBadge( facts ) {
		return $( '<span class="dahu-kit-stock" />' ).addClass( 'is-' + facts.stock_status ).text( facts.stock_label );
	}

	// “(#ID)”, like WooCommerce's and WCCP's product search.
	function idSuffix( id ) {
		return ' (#' + id + ')';
	}

	function stockSuffix( facts ) {
		return 'outofstock' === facts.stock_status ? ' (' + facts.stock_label + ')' : '';
	}

	// Messages under every component: errors from the engine, and a mandatory
	// component left without a choice once it has been touched.
	function renderMessages() {
		var missing = {};
		$.each( ( ctx.state && ctx.state.missing ) || [], function ( index, id ) {
			missing[ id ] = true;
		} );
		view.$el.find( '.dahu-kit-component' ).each( function () {
			var id = $( this ).attr( 'data-component' );
			var $messages = $( this ).find( '.dahu-kit-component__messages' ).empty();
			$.each( ( ctx.state && ctx.state.errors ) || [], function ( index, error ) {
				if ( error.component === id ) {
					$messages.append( $( '<p class="dahu-kit-component__error" />' ).text( error.message ) );
				}
			} );
			if ( missing[ id ] && ctx.touched[ id ] ) {
				$messages.append( $( '<p class="dahu-kit-component__error" />' ).text( i18n.missingChoice ) );
			}
			$( this ).toggleClass( 'has-error', $messages.children().length > 0 );
		} );
	}

	/*
	 * Selections
	 */

	function selectionValue( component, selection ) {
		if ( ! selection ) {
			return '';
		}
		if ( 'path' === component.type ) {
			return selection.option_id ? String( selection.option_id ) : '';
		}
		if ( ! selection.product_id ) {
			return '';
		}
		return selection.variation_id ? selection.product_id + ':' + selection.variation_id : String( selection.product_id );
	}

	function setSingle( component, value ) {
		if ( ! value ) {
			delete ctx.selections[ component.id ];
			return;
		}
		if ( 'path' === component.type ) {
			ctx.selections[ component.id ] = { option_id: value };
			return;
		}
		var parts = value.split( ':' );
		var productId = Number( parts[ 0 ] );
		var previous = ctx.selections[ component.id ] || {};
		var same = Number( previous.product_id ) === productId;
		var selection = {
			product_id: productId,
			// The same rule as the front end (select() of dahu-composite-front.js).
			quantity: ( same && previous.quantity ) || component.quantity_min || 1
		};
		if ( parts[ 1 ] ) {
			selection.variation_id = Number( parts[ 1 ] );
		}
		if ( same && previous.bundle ) {
			selection.bundle = previous.bundle;
		}
		ctx.selections[ component.id ] = selection;
	}

	function findPathOption( component, value ) {
		var found = null;
		$.each( component.options, function ( index, option ) {
			if ( option.value === value ) {
				found = option;
			}
		} );
		return found;
	}

	function findProduct( component, selection ) {
		var found = null;
		$.each( component.options, function ( index, option ) {
			if ( Number( option.product_id ) === Number( selection.product_id ) ) {
				found = { option: option, variation: findVariation( option, selection.variation_id ) };
			}
		} );
		return found;
	}

	function findVariation( option, variationId ) {
		var found = null;
		$.each( option.variations, function ( index, variation ) {
			if ( variationId && Number( variation.variation_id ) === Number( variationId ) ) {
				found = variation;
			}
		} );
		return found;
	}

	function findItem( items, productId ) {
		var found = null;
		$.each( items, function ( index, item ) {
			if ( Number( item.product_id ) === Number( productId ) ) {
				found = item;
			}
		} );
		return found;
	}

	/*
	 * Waiting
	 */

	function block( $target ) {
		$target.block( { message: null, overlayCSS: { background: '#fff', opacity: 0.6 } } );
	}

	function unblock( $target ) {
		$target.unblock();
	}
} );
