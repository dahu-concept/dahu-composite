<?php
/**
 * Order: row metadata and display (HPOS admin, e-mails, customer account, PDF
 * invoices through the visible metas).
 *
 * COMPATIBILITY RULE — row metadata IDENTICAL to WCCP 11.1.2
 * (CP/includes/class-wc-cp-order.php, add_order_item_meta(), l.1022-1092), same
 * meaning, same format:
 *
 *   Container:
 *     _composite_children  array  cart keys of the child rows
 *     _composite_data      array  configuration per component (WCCP format;
 *                                 multiple-choice component: the entry keeps the
 *                                 FIRST item under the WCCP keys and lists every
 *                                 item under “dahu_items”)
 *     _composite_cart_key  string cart key of the container
 *     _composite_weight    float  weight of the parcel per kit unit (container +
 *                                 components not shipped individually), written
 *                                 only when at least one component is
 *                                 aggregated. DELIBERATE DEPARTURE from WCCP,
 *                                 which writes it as soon as the container is in
 *                                 a parcel: the Dahu container is never virtual
 *                                 (“Configurator” class), and for a kit entirely
 *                                 in “shipped individually” (a virtual container
 *                                 under WCCP, and therefore without this meta)
 *                                 writing it would make dahu-post lose the
 *                                 weight of the components (the
 *                                 dahu_post_get_effective_item_weight()
 *                                 fallback).
 *     _dahu_composition_id string composition identifier (specific to the
 *                                 engine, not WCCP) — see
 *                                 Dahu_Composite_Composition
 *     _dahu_composition    array  the same composition, structured
 *   Child:
 *     _composite_parent    string cart key of the container
 *     _composite_item      string id of the component
 *     _composite_cart_key  string cart key of the child
 *     _composite_data      array  the same configuration as the container
 *     _component_priced_individually 'yes'
 *     _composite_item_needs_shipping 'yes'|'no'
 *
 *   Items of a component of the bundle type: _bundled_by and
 *   _bundled_item_priced_individually are written by Product Bundles itself
 *   (WC_PB_Order, l.1095 and l.1117): the engine adds the bundle to the cart
 *   through PB's API, which guarantees exactly the same keys.
 *
 *   Kit assembled in the order screen (add_kit_to_order()): the same metas,
 *   without a cart; the “cart keys” there (_composite_cart_key,
 *   _composite_parent, _composite_children) are md5( ID of the row ), the
 *   convention of WCCP and of Product Bundles for an addition in an order.
 *
 *   Supply of a variation (Dahu_Composite_Supply) — the same write functions
 *   (add_container_meta(), add_child_meta()), never a copy:
 *   Variation (its row, its variation_id, its price):
 *     _composite_children, _composite_cart_key, _composite_weight (its
 *     accessories are always packed with it) — NOT _composite_data (the WCCP
 *     per-component format, which has no object here);
 *     _dahu_supply         'parent'
 *     _dahu_supply_data    array  list applied, frozen (real rows)
 *   Accessory (its own row, its own product / variation, 0):
 *     _composite_parent, _composite_item (“supply-<ID>”), _composite_cart_key,
 *     _component_priced_individually 'no', _composite_item_needs_shipping 'no';
 *     _dahu_supply 'child'; visible meta “Supplied with”.
 *   The _dahu_kit marker is not set there: it feeds the order reconfigurator,
 *   which would wrongly assume a kit.
 *   RULE: a placed order never reads _dahu_supply on the product again; only its
 *   rows count.
 *
 * Known consumers: dahu-post (weight / carrier), dahu-sav (return-label,
 * order-builder), dahu-serials (one row per component), dahu-pricing
 * (class-dahu-pricing-admin-order-tax-fix.php).
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Order {

	/** Marker specific to the engine (tells a Dahu kit from a WCCP composite). */
	const META_MARKER = '_dahu_kit';

	/** Marker of a supply row: 'parent' (variation) or 'child' (accessory). */
	const META_SUPPLY = '_dahu_supply';

	/** Row of the variation: supply list applied to this order. */
	const META_SUPPLY_DATA = '_dahu_supply_data';

	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'add_order_item_meta' ), 10, 4 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_meta' ) );
		add_filter( 'woocommerce_order_item_class', array( __CLASS__, 'order_item_class' ), 10, 3 );
		add_filter( 'woocommerce_admin_html_order_item_class', array( __CLASS__, 'admin_order_item_class' ), 10, 3 );
		// “Preview” window of the order list: same classes (like
		// Product Bundles).
		add_filter( 'woocommerce_admin_html_order_preview_item_class', array( __CLASS__, 'admin_order_item_class' ), 10, 3 );
		add_filter( 'woocommerce_order_item_name', array( __CLASS__, 'order_item_name' ), 10, 2 );
		add_filter( 'woocommerce_email_styles', array( __CLASS__, 'email_styles' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_styles' ) );
	}

	/**
	 * @param WC_Order_Item_Product $item      Row.
	 * @param string                $cart_key  Cart key.
	 * @param array                 $cart_item Cart item.
	 * @param WC_Order              $order     Order.
	 */
	public static function add_order_item_meta( $item, $cart_key, $cart_item, $order ) {
		// Supply first: an accessory carries the child keys too.
		if ( Dahu_Composite_Cart::is_supply_parent( $cart_item ) ) {
			self::add_supply_parent_meta( $item, $cart_key, $cart_item );
			return;
		}
		if ( Dahu_Composite_Cart::is_supply_child( $cart_item ) ) {
			self::add_supply_child_meta( $item, $cart_key, $cart_item );
			return;
		}

		if ( Dahu_Composite_Cart::is_container( $cart_item ) ) {
			$kit = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product_Dahu_Kit ? $cart_item['data'] : null;

			// Composition identifier, computed when the item is added to the cart;
			// recomputed from the selections for an item put in the cart before the
			// identifier existed. Internal metas (hidden), written by the CRUD layer
			// of the row: WooCommerce saves them with the order (HPOS).
			// An older format (cart filled before format 2): recomputed as well.
			$composition = isset( $cart_item[ Dahu_Composite_Cart::K_CONTAINER ]['composition']['id'] ) ? $cart_item[ Dahu_Composite_Cart::K_CONTAINER ]['composition'] : null;
			if ( $composition && ( ! isset( $composition['data']['format'] ) || Dahu_Composite_Composition::FORMAT !== (int) $composition['data']['format'] ) ) {
				$composition = null;
			}
			if ( ! $composition && $kit && isset( $cart_item[ Dahu_Composite_Cart::K_CONTAINER ]['selections'] ) ) {
				$composition = Dahu_Composite_Composition::build( $kit, (array) $cart_item[ Dahu_Composite_Cart::K_CONTAINER ]['selections'] );
			}
			self::add_container_meta(
				$item,
				(array) $cart_item[ Dahu_Composite_Cart::K_CHILDREN ],
				self::composite_data( $kit, $cart_item ),
				$cart_key,
				Dahu_Composite_Cart::get_container_package_weight( $cart_key ),
				$composition
			);
			return;
		}

		if ( Dahu_Composite_Cart::is_child( $cart_item ) ) {
			$parent = WC()->cart ? WC()->cart->get_cart_item( $cart_item[ Dahu_Composite_Cart::K_PARENT ] ) : null;
			$kit    = $parent && isset( $parent['data'] ) && $parent['data'] instanceof WC_Product_Dahu_Kit ? $parent['data'] : null;

			self::add_child_meta(
				$item,
				$kit,
				$cart_item[ Dahu_Composite_Cart::K_PARENT ],
				$cart_item[ Dahu_Composite_Cart::K_COMPONENT ],
				$parent ? self::composite_data( $kit, $parent ) : null,
				$cart_key,
				! empty( $cart_item[ Dahu_Composite_Cart::K_SHIPPED ] ) && isset( $cart_item['data'] ) && $cart_item['data']->needs_shipping()
			);
		}
	}

	/**
	 * Metas of the container row — same keys, same order, whether the item comes
	 * from the cart (add_order_item_meta()) or from the order screen
	 * (add_kit_to_order()). The only place this contract is written on the parent
	 * side: the row of a supply variation goes through here too
	 * (add_supply_parent_meta()), without _composite_data and with its own marker.
	 *
	 * @param WC_Order_Item_Product $item        Container row.
	 * @param string[]              $children    Keys of the child rows.
	 * @param array|null            $data        _composite_data; null: not written.
	 * @param string                $cart_key    Key of the container.
	 * @param float|null            $weight      Weight of the parcel per kit, null when no component is aggregated.
	 * @param array|null            $composition Composition identifier { id, data }.
	 * @param array                 $marker      Marker meta(s) [ key => value ].
	 */
	public static function add_container_meta( WC_Order_Item_Product $item, array $children, $data, $cart_key, $weight, $composition, array $marker = array( self::META_MARKER => 'container' ) ) {
		$item->add_meta_data( '_composite_children', array_values( $children ), true );
		if ( null !== $data ) {
			$item->add_meta_data( '_composite_data', (array) $data, true );
		}
		$item->add_meta_data( '_composite_cart_key', $cart_key, true );

		if ( null !== $weight ) {
			$item->add_meta_data( '_composite_weight', wc_format_decimal( $weight ), true );
		}

		if ( $composition ) {
			$item->add_meta_data( Dahu_Composite_Composition::META_ID, (string) $composition['id'], true );
			$item->add_meta_data( Dahu_Composite_Composition::META_DATA, (array) $composition['data'], true );
		}

		foreach ( $marker as $marker_key => $marker_value ) {
			$item->add_meta_data( $marker_key, $marker_value, true );
		}
	}

	/**
	 * Metas of a component row — cart or order screen. The only place this
	 * contract is written on the child side: the accessory of a variation goes
	 * through here too (add_supply_child_meta()).
	 *
	 * @param WC_Order_Item_Product    $item           Component row.
	 * @param WC_Product_Dahu_Kit|null $kit            Kit.
	 * @param string                   $parent_key     Key of the container.
	 * @param string                   $component_id   Component.
	 * @param array|null               $data           _composite_data of the kit, null when it is unknown.
	 * @param string                   $cart_key       Key of the row.
	 * @param bool                     $needs_shipping Shipped individually (and to be shipped).
	 * @param array                    $args           {
	 *     @type bool       $priced_individually _component_priced_individually 'yes' (default) or 'no'.
	 *     @type array      $marker              Marker meta(s) [ key => value ]; default _dahu_kit = child.
	 *     @type array|null $label               Visible meta [ label, value ]; null: title of the component of the kit.
	 * }
	 */
	public static function add_child_meta( WC_Order_Item_Product $item, $kit, $parent_key, $component_id, $data, $cart_key, $needs_shipping, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'priced_individually' => true,
				'marker'              => array( self::META_MARKER => 'child' ),
				'label'               => null,
			)
		);

		$item->add_meta_data( '_composite_parent', $parent_key, true );
		$item->add_meta_data( '_composite_item', $component_id, true );
		$item->add_meta_data( '_component_priced_individually', $args['priced_individually'] ? 'yes' : 'no', true );
		if ( null !== $data ) {
			$item->add_meta_data( '_composite_data', $data, true );
		}
		$item->add_meta_data( '_composite_cart_key', $cart_key, true );
		$item->add_meta_data( '_composite_item_needs_shipping', $needs_shipping ? 'yes' : 'no', true );
		foreach ( (array) $args['marker'] as $marker_key => $marker_value ) {
			$item->add_meta_data( $marker_key, $marker_value, true );
		}

		// VISIBLE meta: name of the component. Makes the row readable everywhere
		// the row metas are displayed (admin, e-mails, customer account,
		// PDF invoices — the PDF invoice plugin has no composite support).
		if ( is_array( $args['label'] ) && 2 === count( $args['label'] ) ) {
			$item->add_meta_data( (string) $args['label'][0], (string) $args['label'][1], true );
			return;
		}
		$component = $kit ? $kit->get_kit_component( $component_id ) : null;
		if ( $component ) {
			$item->add_meta_data( __( 'Component', 'dahu-composite' ), $component['title'], true );
		}
	}

	/**
	 * Row of the supply variation: its price and its variation_id are those of
	 * the variation (a full row of its own). Nothing is written when no accessory
	 * is in the cart (no row to attach).
	 *
	 * @param WC_Order_Item_Product $item      Row.
	 * @param string                $cart_key  Cart key of the variation.
	 * @param array                 $cart_item Cart item.
	 */
	private static function add_supply_parent_meta( WC_Order_Item_Product $item, $cart_key, array $cart_item ) {
		$cart     = WC()->cart;
		$children = array();
		$applied  = array();

		foreach ( Dahu_Composite_Cart::group_children( $cart_item ) as $child_key ) {
			$child = $cart ? $cart->get_cart_item( $child_key ) : null;
			if ( ! Dahu_Composite_Cart::is_supply_child( $child ) ) {
				continue;
			}
			$children[] = $child_key;
			$applied[]  = array(
				'product_id'   => (int) $child['product_id'],
				'variation_id' => (int) $child['variation_id'],
				'quantity'     => max( 1, (int) $child[ Dahu_Composite_Cart::K_UNIT_QTY ] ),
				'name'         => isset( $child['data'] ) && $child['data'] instanceof WC_Product ? wp_strip_all_tags( $child['data']->get_name() ) : '',
				'cart_key'     => (string) $child_key,
			);
		}

		if ( empty( $children ) ) {
			return;
		}

		self::add_container_meta(
			$item,
			$children,
			null,
			$cart_key,
			Dahu_Composite_Cart::get_container_package_weight( $cart_key ),
			null,
			array( self::META_SUPPLY => 'parent' )
		);
		$item->add_meta_data(
			self::META_SUPPLY_DATA,
			array(
				// Format 2: no more shipped_individually (an accessory always
				// leaves with its variation). Format 1: earlier orders.
				'format' => 2,
				'items'  => $applied,
			),
			true
		);
	}

	/**
	 * Row of an accessory: its own row, its own product or its own variation, at
	 * 0 (supplied with the variation).
	 *
	 * @param WC_Order_Item_Product $item      Row.
	 * @param string                $cart_key  Cart key of the accessory.
	 * @param array                 $cart_item Cart item.
	 */
	private static function add_supply_child_meta( WC_Order_Item_Product $item, $cart_key, array $cart_item ) {
		$parent_key  = $cart_item[ Dahu_Composite_Cart::K_PARENT ];
		$parent      = WC()->cart ? WC()->cart->get_cart_item( $parent_key ) : null;
		$parent_name = $parent && isset( $parent['data'] ) && $parent['data'] instanceof WC_Product ? wp_strip_all_tags( $parent['data']->get_name() ) : '';

		self::add_child_meta(
			$item,
			null,
			$parent_key,
			isset( $cart_item[ Dahu_Composite_Cart::K_COMPONENT ] ) ? $cart_item[ Dahu_Composite_Cart::K_COMPONENT ] : '',
			null,
			$cart_key,
			// Never shipped separately: always in the parcel of the variation.
			false,
			array(
				'priced_individually' => false,
				'marker'              => array( self::META_SUPPLY => 'child' ),
				'label'               => '' !== $parent_name ? array( __( 'Supplied with', 'dahu-composite' ), $parent_name ) : null,
			)
		);
	}

	/**
	 * @param WC_Order_Item $item Row.
	 * @return string '', 'parent' or 'child'
	 */
	public static function supply_role( $item ) {
		return ( $item instanceof WC_Order_Item_Product ) ? (string) $item->get_meta( self::META_SUPPLY, true ) : '';
	}

	/**
	 * Adds to an order, without a cart, the accessories of a variation just added
	 * to it (order screen: phone order, after-sales). The counterpart, for the
	 * supply, of add_kit_to_order() and of WC_PB_Order::add_bundle_to_order():
	 * same key convention without a cart (md5( ID of the row )), same write
	 * functions as the cart path (add_container_meta(), add_child_meta()) — the
	 * resulting order carries the same metas as an order placed on the front end.
	 *
	 * The row of the variation is KEPT as WooCommerce created it (Product Bundles
	 * instead removes the added row and recreates the bundle): its price, and the
	 * role pricing that then applies to it, are left alone. Accessory at 0,
	 * quantity = quantity per unit × quantity of the variation. A refused
	 * accessory (not found, not purchasable) is skipped: the others are added, the
	 * variation stays. No stock check and no stock movement, like the manual
	 * addition of an item by WooCommerce.
	 *
	 * @param WC_Order              $order  Order.
	 * @param WC_Order_Item_Product $parent Row of the variation (already in the order).
	 * @param array[]               $supply Dahu_Composite_Supply::get_supply().
	 * @return array { added: string[], skipped: string[] } Labels for the order note.
	 */
	public static function add_supply_to_order( WC_Order $order, WC_Order_Item_Product $parent, array $supply ) {
		$parent_key  = md5( $parent->get_id() );
		$parent_qty  = max( 1, (int) $parent->get_quantity() );
		$parent_name = wp_strip_all_tags( $parent->get_name() );
		$variation   = $parent->get_product();
		$weight      = $variation ? (float) $variation->get_weight() : 0.0;
		$children    = array();
		$applied     = array();
		$result      = array(
			'added'   => array(),
			'skipped' => array(),
		);

		foreach ( $supply as $entry ) {
			$id      = Dahu_Composite_Supply::entry_id( $entry );
			$product = Dahu_Composite_Supply::accessory_product( $entry );
			$item_id = $product ? $order->add_product(
				$product,
				$entry['quantity'] * $parent_qty,
				array(
					'subtotal' => 0,
					'total'    => 0,
				)
			) : false;
			$item    = $item_id ? $order->get_item( $item_id, false ) : null;
			if ( ! $item instanceof WC_Order_Item_Product ) {
				$result['skipped'][] = $product ? wp_strip_all_tags( $product->get_name() ) : '#' . $id;
				continue;
			}

			$key = md5( $item_id );
			self::add_child_meta(
				$item,
				null,
				$parent_key,
				'supply-' . $id,
				null,
				$key,
				// Never shipped separately: always in the parcel of the variation.
				false,
				array(
					'priced_individually' => false,
					'marker'              => array( self::META_SUPPLY => 'child' ),
					'label'               => '' !== $parent_name ? array( __( 'Supplied with', 'dahu-composite' ), $parent_name ) : null,
				)
			);
			$item->save();

			$name       = wp_strip_all_tags( $product->get_name() );
			$children[] = $key;
			$applied[]  = array(
				'product_id'   => (int) $entry['product_id'],
				'variation_id' => (int) $entry['variation_id'],
				'quantity'     => (int) $entry['quantity'],
				'name'         => $name,
				'cart_key'     => $key,
			);
			// Weight of the parcel per unit of the variation, as in the cart
			// (Dahu_Composite_Cart::get_container_package_weight()).
			$weight += (float) $product->get_weight() * (int) $entry['quantity'];

			$result['added'][] = $item->get_quantity() > 1 ? $name . ' ×' . $item->get_quantity() : $name;
		}

		if ( $children ) {
			self::add_container_meta( $parent, $children, null, $parent_key, $weight, null, array( self::META_SUPPLY => 'parent' ) );
			$parent->add_meta_data(
				self::META_SUPPLY_DATA,
				array(
					'format' => 2,
					'items'  => $applied,
				),
				true
			);
			$parent->save();
		}

		return $result;
	}

	/**
	 * Accessory rows of a supply variation, in the order: same attachment as
	 * dahu-post and Product Bundles (_composite_parent of the child =
	 * _composite_cart_key of the parent). Cart path as well as order screen.
	 *
	 * @param WC_Order              $order  Order.
	 * @param WC_Order_Item_Product $parent Row of the variation.
	 * @return WC_Order_Item_Product[] Keyed by row ID, in the order of the order.
	 */
	public static function supply_children( WC_Order $order, WC_Order_Item_Product $parent ) {
		$key      = (string) $parent->get_meta( '_composite_cart_key', true );
		$children = array();
		if ( '' === $key || 'parent' !== self::supply_role( $parent ) ) {
			return $children;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( 'child' === self::supply_role( $item ) && $key === (string) $item->get_meta( '_composite_parent', true ) ) {
				$children[ $item_id ] = $item;
			}
		}
		return $children;
	}

	/**
	 * Puts the supply groups of an order back in order after a change in the
	 * order screen (cart path as well as screen):
	 *  - quantity of an accessory = quantity per unit (_dahu_supply_data
	 *    snapshot) × quantity of the variation — the variation has changed, or
	 *    the accessory has been modified on its own;
	 *  - accessory whose variation has gone: removed;
	 *  - key of a removed accessory: taken out of _composite_children.
	 * Stock: as WooCommerce does for a modified or deleted row
	 * (wc_maybe_adjust_line_item_product_stock(), the same status condition as
	 * wc_save_order_items() for a quantity change). The amounts are left alone (0
	 * stays 0). An order note when something has changed.
	 *
	 * @param WC_Order $order Order.
	 * @return bool Something has changed.
	 */
	public static function reconcile_supply( WC_Order $order ) {
		$parents = array();
		$groups  = array();
		$notes   = array();
		$orphans = false;

		foreach ( $order->get_items() as $item ) {
			if ( 'parent' === self::supply_role( $item ) ) {
				$parents[ (string) $item->get_meta( '_composite_cart_key', true ) ] = $item;
			}
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( 'child' !== self::supply_role( $item ) ) {
				continue;
			}
			$parent_key = (string) $item->get_meta( '_composite_parent', true );
			if ( isset( $parents[ $parent_key ] ) ) {
				$groups[ $parent_key ][ (string) $item->get_meta( '_composite_cart_key', true ) ] = $item;
				continue;
			}
			// Variation removed: the accessory leaves with it, stock given back
			// as for a row deleted by WooCommerce.
			$changed = wc_maybe_adjust_line_item_product_stock( $item, 0 );
			$notes[] = ( $changed && ! is_wp_error( $changed ) )
				/* translators: 1: accessory, 2: stock before, 3: stock after */
				? sprintf( __( '%1$s removed along with its variation (stock %2$s → %3$s)', 'dahu-composite' ), $item->get_name(), $changed['from'], $changed['to'] )
				/* translators: %s: accessory */
				: sprintf( __( '%s removed along with its variation', 'dahu-composite' ), $item->get_name() );
			$order->remove_item( $item_id );
			$orphans = true;
		}

		$adjust_stock = in_array( $order->get_status(), array( 'processing', 'completed', 'on-hold' ), true );

		foreach ( $parents as $parent_key => $parent ) {
			$present = isset( $groups[ $parent_key ] ) ? $groups[ $parent_key ] : array();
			$listed  = array_values( (array) $parent->get_meta( '_composite_children', true ) );
			$kept    = array_values( array_intersect( $listed, array_map( 'strval', array_keys( $present ) ) ) );
			if ( $kept !== $listed ) {
				$parent->update_meta_data( '_composite_children', $kept );
				$parent->save();
			}

			$units    = array();
			$snapshot = $parent->get_meta( self::META_SUPPLY_DATA, true );
			if ( is_array( $snapshot ) && ! empty( $snapshot['items'] ) ) {
				foreach ( (array) $snapshot['items'] as $applied ) {
					if ( isset( $applied['cart_key'], $applied['quantity'] ) ) {
						$units[ (string) $applied['cart_key'] ] = max( 1, (int) $applied['quantity'] );
					}
				}
			}

			$parent_qty = max( 1, (int) $parent->get_quantity() );
			foreach ( $present as $child_key => $child ) {
				if ( ! isset( $units[ $child_key ] ) ) {
					continue;
				}
				$expected = $units[ $child_key ] * $parent_qty;
				$current  = (int) $child->get_quantity();
				if ( $current === $expected ) {
					continue;
				}
				$child->set_quantity( $expected );
				$child->save();
				$changed = $adjust_stock ? wc_maybe_adjust_line_item_product_stock( $child ) : false;
				$notes[] = ( $changed && ! is_wp_error( $changed ) )
					/* translators: 1: accessory, 2: quantity before, 3: quantity after, 4: stock before, 5: stock after */
					? sprintf( __( '%1$s: quantity %2$d → %3$d (stock %4$s → %5$s)', 'dahu-composite' ), $child->get_name(), $current, $expected, $changed['from'], $changed['to'] )
					/* translators: 1: accessory, 2: quantity before, 3: quantity after */
					: sprintf( __( '%1$s: quantity %2$d → %3$d', 'dahu-composite' ), $child->get_name(), $current, $expected );
			}
		}

		if ( $orphans ) {
			$order->save();
		}
		if ( $notes ) {
			/* translators: %s: list of the changes */
			$order->add_order_note( sprintf( __( 'Supplied accessories updated: %s.', 'dahu-composite' ), implode( ', ', $notes ) ), 0, true );
		}
		return $orphans || (bool) $notes;
	}

	/**
	 * Adds a configured kit to an order, without a cart: order screen (phone
	 * order, after-sales replacement). The same result as a kit that went through
	 * the cart and then the order: one container row, one row per component (per
	 * item of a multiple choice), the items of a bundle added by Product Bundles,
	 * and the metas of add_order_item_meta().
	 *
	 * The counterpart of WC_CP_Order::add_composite_to_order() (WCCP) and of
	 * WC_PB_Order::add_bundle_to_order() (Product Bundles), whose convention it
	 * reuses: without a cart, the “cart key” of a row is md5( ID of the row )
	 * (_composite_cart_key, _composite_parent, _composite_children).
	 *
	 * Prices: container at 0, every component at the unit price of the role of the
	 * customer, through the same resolver as the cart
	 * (Dahu_Composite_Pricing::get_raw_price(), customer set by the caller with
	 * set_customer()); the conversion to a tax-exclusive amount is WooCommerce's
	 * own (wc_get_price_excluding_tax(), like WC_Order::add_product()). Taxes and
	 * totals of the order: up to the caller (calculate_totals()). No stock check
	 * (like the manual addition of an item by WooCommerce).
	 *
	 * On failure, the rows already added are removed.
	 *
	 * @param WC_Order            $order    Order.
	 * @param WC_Product_Dahu_Kit $kit      Kit.
	 * @param array               $built    Dahu_Composite_Cart::build_kit_lines().
	 * @param int                 $quantity Number of kits.
	 * @return int|WP_Error ID of the container row.
	 */
	public static function add_kit_to_order( WC_Order $order, WC_Product_Dahu_Kit $kit, array $built, $quantity = 1 ) {
		$quantity = max( 1, (int) $quantity );
		$before   = array_keys( $order->get_items() );
		$data     = self::composite_data( $kit, array( Dahu_Composite_Cart::K_CONTAINER => array( 'selections' => $built['selections'] ) ) );
		$error    = null;
		$children = array();

		$container_id = $order->add_product(
			$kit,
			$quantity,
			array(
				'subtotal' => 0,
				'total'    => 0,
			)
		);
		$container    = $container_id ? $order->get_item( $container_id, false ) : null;
		if ( ! $container ) {
			return new WP_Error( 'dahu_kit_add_failed', __( 'The kit could not be added to the order.', 'dahu-composite' ) );
		}
		$container_key = md5( $container_id );

		foreach ( $built['lines'] as $line ) {
			$qty = (int) $line['quantity'] * $quantity;
			if ( $qty < 1 ) {
				continue;
			}
			$selection    = $line['selection'];
			$line_product = wc_get_product( $line['variation_id'] ? $line['variation_id'] : $line['product_id'] );
			$item_id      = false;

			if ( $line_product ) {
				$price  = Dahu_Composite_Pricing::get_raw_price( $line_product, $qty );
				$amount = wc_get_price_excluding_tax(
					$line_product,
					array(
						'qty'   => $qty,
						'price' => $price,
					)
				);

				if ( 'bundle' === $line['type'] ) {
					if ( ! function_exists( 'WC_PB' ) || ! WC_PB()->order ) {
						$error = new WP_Error( 'dahu_kit_pb_missing', __( 'This component requires a product bundle plugin.', 'dahu-composite' ) );
						break;
					}
					// Items of the bundle: added and priced by Product Bundles
					// itself, as in the cart.
					$item_id = WC_PB()->order->add_bundle_to_order(
						$line_product,
						$order,
						$qty,
						array(
							'configuration' => Dahu_Composite_Cart::bundle_configuration( $line_product, $selection ),
							'subtotal'      => $amount,
							'total'         => $amount,
						)
					);
				} else {
					$item_id = $order->add_product(
						$line_product,
						$qty,
						array(
							'variation' => $line['variation_id'] ? Dahu_Composite_Engine::variation_attributes( $line_product, (array) $selection['attributes'] ) : array(),
							'subtotal'  => $amount,
							'total'     => $amount,
						)
					);
				}
			}

			$item = ( $item_id && ! is_wp_error( $item_id ) ) ? $order->get_item( $item_id, false ) : null;
			if ( ! $item ) {
				/* translators: %s: product */
				$message = sprintf( __( '“%s” could not be added to the order.', 'dahu-composite' ), $line['name'] );
				$error   = new WP_Error( 'dahu_kit_add_failed', is_wp_error( $item_id ) ? $message . ' ' . $item_id->get_error_message() : $message );
				break;
			}

			$key        = md5( $item_id );
			$children[] = $key;
			self::add_child_meta( $item, $kit, $container_key, $line['component_id'], $data, $key, ! empty( $line['shipped_individually'] ) && $line_product->needs_shipping() );
			// Bundle packed with the kit: in the cart, its weight goes into the parcel
			// of the container (Dahu_Composite_Cart::shipping_packages()) and Product
			// Bundles, which no longer finds it in the parcels, does not write
			// _bundle_weight. add_bundle_to_order() always writes it: removed,
			// for the same row as a kit that went through the cart.
			if ( 'bundle' === $line['type'] && empty( $line['shipped_individually'] ) ) {
				$item->delete_meta_data( '_bundle_weight' );
			}
			$item->save();
		}

		if ( ! $error && empty( $children ) ) {
			$error = new WP_Error( 'dahu_kit_add_failed', __( 'Select at least one component.', 'dahu-composite' ) );
		}

		if ( $error ) {
			foreach ( array_diff( array_keys( $order->get_items() ), $before ) as $added_id ) {
				$order->remove_item( $added_id );
			}
			$order->save();
			return $error;
		}

		// Weight of the parcel per kit: the kit and its components not shipped
		// individually (rows of the engine, the same computation as /quote). Written
		// only when at least one component is aggregated, as in the cart.
		$weight = null;
		if ( Dahu_Composite_Engine::has_packaged_lines( $built['lines'] ) ) {
			$weight = (float) $kit->get_weight();
			foreach ( $built['lines'] as $line ) {
				if ( ! $line['shipped_individually'] ) {
					$weight += (float) $line['line_weight'];
				}
			}
		}

		self::add_container_meta( $container, $children, $data, $container_key, $weight, $built['composition'] );
		$container->save();

		return (int) $container_id;
	}

	/**
	 * Rows of a kit in an order: the container, its components and the items of
	 * the bundles of those components. A kit row not configured yet (kit product
	 * added on its own) is returned alone.
	 *
	 * @param WC_Order              $order     Order.
	 * @param WC_Order_Item_Product $container Container row.
	 * @return WC_Order_Item_Product[] Keyed by row ID.
	 */
	public static function kit_items( WC_Order $order, WC_Order_Item_Product $container ) {
		$items = array( $container->get_id() => $container );
		$key   = (string) $container->get_meta( '_composite_cart_key', true );
		if ( '' === $key || 'container' !== self::kit_role( $container ) ) {
			return $items;
		}

		$bundle_keys = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( 'child' === self::kit_role( $item ) && $key === (string) $item->get_meta( '_composite_parent', true ) ) {
				$items[ $item_id ] = $item;
				$bundle_key        = (string) $item->get_meta( '_bundle_cart_key', true );
				if ( '' !== $bundle_key ) {
					$bundle_keys[ $bundle_key ] = true;
				}
			}
		}
		if ( $bundle_keys ) {
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( isset( $bundle_keys[ (string) $item->get_meta( '_bundled_by', true ) ] ) ) {
					$items[ $item_id ] = $item;
				}
			}
		}
		return $items;
	}

	/**
	 * Configuration in WCCP's _composite_data format
	 * (parse_composite_configuration(), class-wc-cp-cart.php l.486-521).
	 *
	 * @param WC_Product_Dahu_Kit|null $kit       Kit.
	 * @param array                    $container Container item.
	 * @return array
	 */
	private static function composite_data( $kit, $container ) {
		$data       = array();
		$selections = isset( $container[ Dahu_Composite_Cart::K_CONTAINER ]['selections'] ) ? (array) $container[ Dahu_Composite_Cart::K_CONTAINER ]['selections'] : array();
		$hidden     = $kit ? array_flip( $kit->evaluate_scenarios( $selections )['hidden_components'] ) : array();

		// Like WCCP: one entry per component of the kit, including the
		// components not selected or hidden (product_id empty, with no type).
		$components = $kit ? $kit->get_kit_components() : array();
		if ( empty( $components ) ) {
			foreach ( array_keys( $selections ) as $cid ) {
				$components[] = array(
					'id'           => $cid,
					'title'        => $cid,
					'quantity_min' => 1,
					'quantity_max' => 1,
					'optional'     => false,
				);
			}
		}

		foreach ( $components as $component ) {
			$cid   = $component['id'];
			$items = array();
			if ( isset( $selections[ $cid ] ) && ! isset( $hidden[ $cid ] ) ) {
				foreach ( Dahu_Composite_Engine::selection_items( $selections[ $cid ] ) as $item ) {
					if ( ! empty( $item['product_id'] ) && (int) $item['quantity'] > 0 ) {
						$items[] = $item;
					}
				}
			}
			$selection = $items ? $items[0] : null;

			$entry = array(
				'product_id'   => '',
				'quantity'     => 0,
				'variation_id' => '',
				'attributes'   => array(),
				'quantity_min' => (int) $component['quantity_min'],
				'quantity_max' => (int) $component['quantity_max'],
				'discount'     => '',
				'optional'     => $component['optional'] ? 'yes' : 'no',
				'static'       => 'no',
				'title'        => $component['title'],
				'composite_id' => $kit ? (int) $kit->get_id() : 0,
			);

			if ( $selection ) {
				$product = wc_get_product( $selection['product_id'] );
				$attrs   = array();
				if ( ! empty( $selection['variation_id'] ) ) {
					$variation = wc_get_product( $selection['variation_id'] );
					$attrs     = $variation ? Dahu_Composite_Engine::variation_attributes( $variation, (array) $selection['attributes'] ) : array();
				}
				$entry['product_id']   = (int) $selection['product_id'];
				$entry['quantity']     = (int) $selection['quantity'];
				$entry['variation_id'] = ! empty( $selection['variation_id'] ) ? (int) $selection['variation_id'] : '';
				$entry['attributes']   = $attrs;
				$entry['type']         = $product ? $product->get_type() : '';
			}

			// Multiple choice: one component, several products. The WCCP
			// format has only one place per component (the first item above);
			// the full list follows, in the same item shape.
			if ( isset( $component['selection_mode'] ) && 'multiple' === $component['selection_mode'] ) {
				$entry['dahu_items'] = array();
				foreach ( $items as $item ) {
					$item_product = wc_get_product( $item['product_id'] );
					$item_attrs   = array();
					if ( ! empty( $item['variation_id'] ) ) {
						$variation  = wc_get_product( $item['variation_id'] );
						$item_attrs = $variation ? Dahu_Composite_Engine::variation_attributes( $variation, (array) $item['attributes'] ) : array();
					}
					$entry['dahu_items'][] = array(
						'product_id'   => (int) $item['product_id'],
						'quantity'     => (int) $item['quantity'],
						'variation_id' => ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : '',
						'attributes'   => $item_attrs,
						'type'         => $item_product ? $item_product->get_type() : '',
					);
				}
			}

			$data[ $cid ] = $entry;
		}
		return $data;
	}

	/**
	 * @param string[] $hidden Hidden keys.
	 * @return string[]
	 */
	public static function hidden_meta( $hidden ) {
		return array_merge(
			$hidden,
			array(
				'_composite_children',
				'_composite_data',
				'_composite_cart_key',
				'_composite_weight',
				'_composite_parent',
				'_composite_item',
				'_component_priced_individually',
				'_composite_item_needs_shipping',
				self::META_MARKER,
				Dahu_Composite_Composition::META_ID,
				Dahu_Composite_Composition::META_DATA,
				self::META_SUPPLY,
				self::META_SUPPLY_DATA,
			)
		);
	}

	/**
	 * @param WC_Order_Item $item Row.
	 * @return string '', 'container' or 'child'
	 */
	public static function kit_role( $item ) {
		return ( $item instanceof WC_Order_Item_Product ) ? (string) $item->get_meta( self::META_MARKER, true ) : '';
	}

	/**
	 * @param string        $class Classes.
	 * @param WC_Order_Item $item  Row.
	 * @param WC_Order      $order Order.
	 * @return string
	 */
	public static function order_item_class( $class, $item, $order ) {
		$role = self::kit_role( $item );
		if ( $role ) {
			return $class . ' dahu-kit-' . $role;
		}
		$supply = self::supply_role( $item );
		return $supply ? $class . ' dahu-supply-' . $supply : $class;
	}

	/**
	 * @param string        $class Classes.
	 * @param WC_Order_Item $item  Row.
	 * @param WC_Order      $order Order.
	 * @return string
	 */
	public static function admin_order_item_class( $class, $item, $order = null ) {
		$class = self::order_item_class( $class, $item, $order );
		// “last” on the last accessory of a group, like Product Bundles
		// for the last item of a bundle (WC_PB_Order::html_order_item_class()).
		if ( 'child' === self::supply_role( $item ) && $order instanceof WC_Order ) {
			$parent_key = (string) $item->get_meta( '_composite_parent', true );
			foreach ( $order->get_items() as $line ) {
				if ( 'parent' === self::supply_role( $line ) && $parent_key === (string) $line->get_meta( '_composite_cart_key', true ) ) {
					$ids = array_keys( self::supply_children( $order, $line ) );
					if ( end( $ids ) === $item->get_id() ) {
						$class .= ' last';
					}
					break;
				}
			}
		}
		return $class;
	}

	/**
	 * “↳” prefix in front of the components and the accessories (customer
	 * account, e-mails).
	 *
	 * @param string        $name Name.
	 * @param WC_Order_Item $item Row.
	 * @return string
	 */
	public static function order_item_name( $name, $item ) {
		// Text prefix (no tag): some templates (PDF) escape the name.
		return ( 'child' === self::kit_role( $item ) || 'child' === self::supply_role( $item ) ) ? "\u{21B3} " . $name : $name;
	}

	/**
	 * @param string $css E-mail CSS.
	 * @return string
	 */
	public static function email_styles( $css ) {
		return $css . "\n.dahu-kit-child td.td:first-child, .dahu-supply-child td.td:first-child { padding-left: 2.5em !important; }\n.dahu-kit-container td { font-weight: bold; }\n";
	}

	/**
	 * Indentation of the components in the admin order screen. Supplied
	 * accessories: attached to their variation like the items of a Product Bundles
	 * bundle (assets/css/admin/meta-boxes-order.css: smaller text, indent, faded
	 * separator until the last one), order screen and “Preview” window of the
	 * order list.
	 */
	public static function admin_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		echo '<style>#order_line_items tr.dahu-kit-child td.name,#order_line_items tr.dahu-supply-child td.name{padding-left:2.5em;}#order_line_items tr.dahu-kit-child td.name:before,#order_line_items tr.dahu-supply-child td.name:before{content:"\21B3";margin-right:.4em;opacity:.6;}'
			. '#order_line_items tr.dahu-supply-child *{font-size:12px;}'
			. '#order_line_items tr.dahu-supply-parent td,#order_line_items tr.dahu-supply-child:not(.last) td{border-bottom-color:#f8f8ff;}'
			. 'table.wc-order-preview-table tr.dahu-supply-child *{font-size:.94em;}'
			. 'table.wc-order-preview-table tr.dahu-supply-child td.wc-order-preview-table__column--product{padding-left:3em;}'
			. 'table.wc-order-preview-table tr.dahu-supply-parent td,table.wc-order-preview-table tr.dahu-supply-child:not(.last) td{border-bottom-color:#f8f8ff;}</style>';
	}
}
