<?php
/**
 * Public “what is included” functions: ONE single set of calls for a Product
 * Bundles bundle (assembled product) and for a supply variation. The caller
 * (the theme) does not have to know which of the two it is handling.
 *
 * Every function reuses the name, the parameters and the return shape of its
 * Product Bundles counterpart (includes/wc-pb-functions.php), with the
 * “dahu_composite_” prefix instead of “wc_pb_”: it answers first as Product
 * Bundles does (when that plugin is active), then for the supply. Contract and
 * correspondence table: RESUME_TECHNIQUE.md, §2 bis.
 *
 * Attaching a supply: by the group keys alone, never by product — cart:
 * dahu_supply_children / dahu_kit_parent; order: _dahu_supply +
 * _composite_cart_key / _composite_parent.
 *
 * At the end of the file: configuration serial of a product
 * (dahu_composite_configuration_serial() / _url()).
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

/*
|--------------------------------------------------------------------------
| Product
|--------------------------------------------------------------------------
*/

/**
 * Included items of a product: items of a bundle, or accessories of a supply
 * variation. Empty list for any other product — a variable product includes
 * nothing by itself: call the function with the variation (or read
 * variation.bundled_items from the variation form data).
 *
 * @param WC_Product|int $product Product, variation or ID.
 * @return array[] [ { product_id, name, quantity, image, optional, variation_id, visible, source } ]
 *                 See Dahu_Composite_Included::get_bundled_items().
 */
function dahu_composite_get_bundled_items( $product ) {
	return Dahu_Composite_Included::get_bundled_items( $product );
}

/*
|--------------------------------------------------------------------------
| Cart — counterparts of wc_pb_*_cart_item*()
|--------------------------------------------------------------------------
*/

/**
 * Row that includes other rows: a bundle, or a supply variation.
 * Counterpart of wc_pb_is_bundle_container_cart_item().
 *
 * @param array $cart_item Cart row.
 * @return bool
 */
function dahu_composite_is_bundle_container_cart_item( $cart_item ) {
	if ( function_exists( 'wc_pb_is_bundle_container_cart_item' ) && wc_pb_is_bundle_container_cart_item( $cart_item ) ) {
		return true;
	}
	return Dahu_Composite_Cart::is_supply_parent( $cart_item );
}

/**
 * Row included in another one (bundle item, accessory) whose parent is in the
 * cart. Counterpart of wc_pb_is_bundled_cart_item().
 *
 * @param array      $cart_item     Cart row.
 * @param array|bool $cart_contents Cart contents (false: the current cart).
 * @return bool
 */
function dahu_composite_is_bundled_cart_item( $cart_item, $cart_contents = false ) {
	return false !== dahu_composite_get_bundled_cart_item_container( $cart_item, $cart_contents );
}

/**
 * Parent of an included row. Counterpart of wc_pb_get_bundled_cart_item_container().
 *
 * @param array      $bundled_cart_item Included row.
 * @param array|bool $cart_contents     Cart contents (false: the current cart).
 * @param bool       $return_id         true: the cart key of the parent.
 * @return array|string|false
 */
function dahu_composite_get_bundled_cart_item_container( $bundled_cart_item, $cart_contents = false, $return_id = false ) {
	if ( function_exists( 'wc_pb_get_bundled_cart_item_container' ) ) {
		$container = wc_pb_get_bundled_cart_item_container( $bundled_cart_item, $cart_contents, $return_id );
		if ( $container ) {
			return $container;
		}
	}
	$container = Dahu_Composite_Included::cart_container( $bundled_cart_item, $cart_contents );
	if ( ! $container ) {
		return false;
	}
	return $return_id ? $bundled_cart_item[ Dahu_Composite_Cart::K_PARENT ] : $container;
}

/**
 * Included rows of a parent, present in the cart, in the order they were
 * added. Counterpart of wc_pb_get_bundled_cart_items().
 *
 * @param array      $container_cart_item Parent row.
 * @param array|bool $cart_contents       Cart contents (false: the current cart).
 * @param bool       $return_ids          true: the cart keys only.
 * @return array
 */
function dahu_composite_get_bundled_cart_items( $container_cart_item, $cart_contents = false, $return_ids = false ) {
	if ( function_exists( 'wc_pb_is_bundle_container_cart_item' ) && wc_pb_is_bundle_container_cart_item( $container_cart_item ) ) {
		return wc_pb_get_bundled_cart_items( $container_cart_item, $cart_contents, $return_ids );
	}
	$items = Dahu_Composite_Included::cart_children( $container_cart_item, $cart_contents );
	return $return_ids ? array_keys( $items ) : $items;
}

/*
|--------------------------------------------------------------------------
| Order — counterparts of wc_pb_*_order_item*()
|--------------------------------------------------------------------------
*/

/**
 * Internal use: order rows of an order or of an array of rows.
 *
 * @param WC_Order_Item       $order_item Row (to find its order again).
 * @param WC_Order|array|bool $order      Order, rows, or false.
 * @return array
 */
function _dahu_composite_order_line_items( $order_item, $order ) {
	if ( false === $order && is_callable( array( $order_item, 'get_order' ) ) ) {
		$order = $order_item->get_order();
	}
	if ( $order instanceof WC_Order ) {
		return $order->get_items( 'line_item' );
	}
	return is_array( $order ) ? $order : array();
}

/**
 * Row that includes other rows: a bundle, or a supply variation.
 * Counterpart of wc_pb_is_bundle_container_order_item().
 *
 * @param WC_Order_Item $order_item Row.
 * @return bool
 */
function dahu_composite_is_bundle_container_order_item( $order_item ) {
	if ( function_exists( 'wc_pb_is_bundle_container_order_item' ) && wc_pb_is_bundle_container_order_item( $order_item ) ) {
		return true;
	}
	return 'parent' === Dahu_Composite_Order::supply_role( $order_item );
}

/**
 * Row included in another one, whose parent is in the order.
 * Counterpart of wc_pb_is_bundled_order_item().
 *
 * @param WC_Order_Item       $order_item Row.
 * @param WC_Order|array|bool $order      Order, rows, or false.
 * @return bool
 */
function dahu_composite_is_bundled_order_item( $order_item, $order = false ) {
	return false !== dahu_composite_get_bundled_order_item_container( $order_item, $order );
}

/**
 * Parent of an included row. Counterpart of wc_pb_get_bundled_order_item_container().
 *
 * @param WC_Order_Item       $bundled_order_item Included row.
 * @param WC_Order|array|bool $order              Order, rows, or false.
 * @param bool                $return_id          true: the ID of the parent row.
 * @return WC_Order_Item_Product|int|false
 */
function dahu_composite_get_bundled_order_item_container( $bundled_order_item, $order = false, $return_id = false ) {
	if ( function_exists( 'wc_pb_get_bundled_order_item_container' ) ) {
		$container = wc_pb_get_bundled_order_item_container( $bundled_order_item, $order, $return_id );
		if ( $container ) {
			return $container;
		}
	}
	if ( 'child' !== Dahu_Composite_Order::supply_role( $bundled_order_item ) ) {
		return false;
	}
	$parent_key = (string) $bundled_order_item->get_meta( '_composite_parent', true );
	if ( '' === $parent_key ) {
		return false;
	}
	foreach ( _dahu_composite_order_line_items( $bundled_order_item, $order ) as $item ) {
		if ( 'parent' === Dahu_Composite_Order::supply_role( $item ) && $parent_key === (string) $item->get_meta( '_composite_cart_key', true ) ) {
			return $return_id ? $item->get_id() : $item;
		}
	}
	return false;
}

/**
 * Included rows of a parent, in order of the order, keyed by row ID.
 * Counterpart of wc_pb_get_bundled_order_items().
 *
 * @param WC_Order_Item       $container_order_item Parent row.
 * @param WC_Order|array|bool $order                Order, rows, or false.
 * @param bool                $return_ids           true: the IDs only.
 * @return array
 */
function dahu_composite_get_bundled_order_items( $container_order_item, $order = false, $return_ids = false ) {
	if ( function_exists( 'wc_pb_is_bundle_container_order_item' ) && wc_pb_is_bundle_container_order_item( $container_order_item ) ) {
		return wc_pb_get_bundled_order_items( $container_order_item, $order, $return_ids );
	}
	$items = array();
	if ( 'parent' === Dahu_Composite_Order::supply_role( $container_order_item ) ) {
		$key = (string) $container_order_item->get_meta( '_composite_cart_key', true );
		foreach ( _dahu_composite_order_line_items( $container_order_item, $order ) as $item_id => $item ) {
			if ( '' !== $key && 'child' === Dahu_Composite_Order::supply_role( $item ) && $key === (string) $item->get_meta( '_composite_parent', true ) ) {
				$items[ $item_id ] = $item;
			}
		}
	}
	return $return_ids ? array_keys( $items ) : $items;
}

/*
|--------------------------------------------------------------------------
| Configuration serial
|--------------------------------------------------------------------------
| For a theme: open the configurator of a kit already initialised with a
| product (a “Configure my kit with this motor” button on a product page).
| Computed on the fly, nothing is stored. Selection rule and format:
| Dahu_Composite_Composition::for_product() and RESUME_TECHNIQUE.md.
*/

/**
 * Serial of a partial configuration that starts with a product.
 *
 * @param int $kit_id     dahu-composite kit (product of type dahu_kit).
 * @param int $product_id Product or variation.
 * @return string '' when the ID is not a kit, or when the product has no
 *                selectable place in it (absent, unpublished, out of stock,
 *                hidden).
 */
function dahu_composite_configuration_serial( $kit_id, $product_id ) {
	$kit = Dahu_Composite_Engine::get_kit( $kit_id );
	return $kit ? Dahu_Composite_Composition::for_product( $kit, $product_id ) : '';
}

/**
 * Address of the configurator of a published kit, opened on the configuration
 * that starts with a product: the customer lands on the next step with that
 * product already chosen.
 *
 * @param int $kit_id     dahu-composite kit.
 * @param int $product_id Product or variation.
 * @return string '' in the cases of dahu_composite_configuration_serial(), or
 *                when the kit is not published: the caller then keeps its
 *                usual link.
 */
function dahu_composite_configuration_url( $kit_id, $product_id ) {
	$kit = Dahu_Composite_Engine::get_kit( $kit_id );
	if ( ! $kit || 'publish' !== $kit->get_status() ) {
		return '';
	}
	return Dahu_Composite_Composition::url( $kit, Dahu_Composite_Composition::for_product( $kit, $product_id ) );
}
