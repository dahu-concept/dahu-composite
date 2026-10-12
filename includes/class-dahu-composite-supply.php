<?php
/**
 * Supply of a variation: accessories always shipped with a variation of a
 * VARIABLE product (e.g. a battery and its cable), without changing the type
 * of the product (a WooCommerce product has only one type).
 *
 * TRACEABILITY: the variation and every accessory are FULL cart rows, then
 * order rows, each with its own product or its own variation. dahu-serials
 * asks for serial numbers row by row (variation_id, else product_id): folding
 * them into a single row, with the detail in a meta, would make them disappear
 * without an error.
 *
 * Product data, on the VARIATION:
 *   _dahu_supply  array  [ { product_id, variation_id, quantity } ]
 *                        quantity: per unit of the variation. Accessory at 0,
 *                        with no role price (“included” mode, the only mode),
 *                        always in the parcel of the variation: no “shipped
 *                        separately” setting (see RESUME_TECHNIQUE, no link of
 *                        the shipping chain honoured it). A shipped_individually
 *                        key from an older list is ignored when reading, and
 *                        disappears the next time the variation is saved.
 *
 * Cart: own keys (Dahu_Composite_Cart::K_SUPPLY on the variation, child keys +
 * K_SUPPLY_CHILD on the accessory) — never dahu_kit_container on the variation
 * (see Dahu_Composite_Cart). Order: see Dahu_Composite_Order (same write
 * functions as the kits).
 *
 * RULE: _dahu_supply is read for the CART only (adding, rebuilding). A placed
 * order never reads it again: only its rows count (accessory rows,
 * _dahu_supply_data snapshot). Changing the list of a variation changes no
 * existing order.
 *
 * Temporary limit: a supply variation chosen as a component of a kit does not
 * ship its accessories yet (is_eligible_item(), validate_add_to_cart()). Fix
 * under way. Do not convert a bundle product into a supply variation before
 * then. In the meantime the configurator announces nothing for such an option
 * (“bundled” empty).
 *
 * Every anomaly (deleted accessory, unreadable list) is logged (source
 * “dahu-composite”) and the customer journey carries on. Only an accessory
 * refused at the moment the customer adds the variation cancels that addition
 * (rollback), with a message.
 *
 * ORDER SCREEN (phone order, after-sales): a variation added by hand receives
 * its accessories (Dahu_Composite_Order::add_supply_to_order(), same metas as
 * the cart path); its quantity, changed afterwards, is followed, and removing
 * it removes its accessories (Dahu_Composite_Order::reconcile_supply()). See
 * “Order screen” further down.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Supply {

	/** Meta of the variation: supply list. */
	const META = '_dahu_supply';

	/**
	 * Item data set by “order again” on the old row of an accessory: that row is
	 * discarded when the cart loads, and the variation receives the CURRENT
	 * list.
	 */
	const K_REORDER_DROP = 'dahu_supply_reorder_drop';

	/** WooCommerce log source. */
	const LOG_SOURCE = 'dahu-composite';

	/** @var bool Accessories being added (guard against recursion). */
	private static $adding = false;

	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 20, 6 );
		// Priority 5: before the persistent cart is saved (WooCommerce,
		// priority 10), which therefore never sees a variation without accessories.
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 5, 6 );
		// After Dahu_Composite_Cart::check_integrity() (20).
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'reconcile_cart' ), 30 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( __CLASS__, 'order_again_item_data' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_minimum', array( __CLASS__, 'store_api_quantity_limit' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( __CLASS__, 'store_api_quantity_limit' ), 10, 3 );
		// Cart display (count, classes, “Includes”): Dahu_Composite_Included.

		if ( is_admin() ) {
			add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_field' ), 10, 3 );
			add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_field' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );

			// Order screen (manual addition of a variation).
			add_action( 'woocommerce_ajax_add_order_item_meta', array( __CLASS__, 'add_admin_order_item' ), 10, 3 );
			// Before WC_AJAX::remove_order_item() (priority 10), like Product
			// Bundles (priority 1): the variation takes its accessories along.
			add_action( 'wp_ajax_woocommerce_remove_order_item', array( __CLASS__, 'extend_admin_order_item_removal' ), 5 );
			add_action( 'woocommerce_ajax_order_items_removed', array( __CLASS__, 'after_admin_order_items_removed' ), 10, 4 );
			add_action( 'woocommerce_saved_order_items', array( __CLASS__, 'after_admin_order_items_saved' ), 10, 2 );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Supply list
	|--------------------------------------------------------------------------
	*/

	/**
	 * Supply list of a variation, normalised. Cart only: never for a placed
	 * order (see the file header).
	 *
	 * @param int $variation_id Variation.
	 * @return array[] { product_id, variation_id, quantity }
	 */
	public static function get_supply( $variation_id ) {
		$variation_id = (int) $variation_id;
		if ( $variation_id <= 0 ) {
			return array();
		}
		$raw = get_post_meta( $variation_id, self::META, true );
		if ( empty( $raw ) ) {
			return array();
		}
		if ( ! is_array( $raw ) ) {
			self::log( 'warning', sprintf( 'Variation #%d: supply list unreadable, ignored.', $variation_id ) );
			return array();
		}

		$out  = array();
		$seen = array();
		foreach ( $raw as $entry ) {
			$entry = self::normalize_entry( $entry );
			if ( ! $entry ) {
				self::log( 'warning', sprintf( 'Variation #%d: supply entry unreadable, ignored.', $variation_id ) );
				continue;
			}
			$id = self::entry_id( $entry );
			if ( $id === $variation_id || isset( $seen[ $id ] ) ) {
				self::log( 'warning', sprintf( 'Variation #%1$d: accessory #%2$d is a duplicate or the variation itself, ignored.', $variation_id, $id ) );
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = $entry;
		}
		return $out;
	}

	/**
	 * @param mixed $entry Raw entry.
	 * @return array|null
	 */
	private static function normalize_entry( $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['product_id'] ) || (int) $entry['product_id'] <= 0 ) {
			return null;
		}
		return array(
			'product_id'   => (int) $entry['product_id'],
			'variation_id' => ! empty( $entry['variation_id'] ) ? (int) $entry['variation_id'] : 0,
			'quantity'     => max( 1, isset( $entry['quantity'] ) ? (int) $entry['quantity'] : 1 ),
		);
	}

	/**
	 * Identifier of the accessory's row: variation, else product.
	 *
	 * @param array $entry Normalised entry.
	 * @return int
	 */
	public static function entry_id( array $entry ) {
		return $entry['variation_id'] ? $entry['variation_id'] : $entry['product_id'];
	}

	/**
	 * Product of an accessory when it can be put in the cart, null otherwise
	 * (configuration anomaly, logged by the caller).
	 *
	 * @param array $entry Normalised entry.
	 * @return WC_Product|null
	 */
	public static function accessory_product( array $entry ) {
		$product = wc_get_product( self::entry_id( $entry ) );
		if ( ! $product || ! $product->exists() || ! $product->is_purchasable() ) {
			return null;
		}
		if ( $entry['variation_id'] ) {
			return ( $product->is_type( 'variation' ) && (int) $product->get_parent_id() === $entry['product_id'] ) ? $product : null;
		}
		return $product->is_type( 'simple' ) ? $product : null;
	}

	/**
	 * Cart item that can receive a supply: a variation bought for itself. Not a
	 * kit container, a bundle item (Product Bundles) or a WCCP composite item,
	 * and not an accessory. Not yet a kit component: temporary limit, see the
	 * file header.
	 *
	 * @param array $item Cart item.
	 * @return bool
	 */
	private static function is_eligible_item( $item ) {
		return is_array( $item )
			&& ! empty( $item['variation_id'] )
			&& ! Dahu_Composite_Cart::is_container( $item )
			&& ! Dahu_Composite_Cart::is_child( $item )
			&& empty( $item['bundled_by'] )
			&& empty( $item['composite_parent'] )
			&& empty( $item[ self::K_REORDER_DROP ] );
	}

	/*
	|--------------------------------------------------------------------------
	| Cart
	|--------------------------------------------------------------------------
	*/

	/**
	 * Before a variation is added (form, AJAX, Store API, “order again”): stock
	 * of every accessory for the requested quantity, counting what is already
	 * in the cart. The internal addition of a kit component does not go through
	 * this filter (WC_Cart::add_to_cart() does not apply it).
	 *
	 * @param bool  $passed         Validation.
	 * @param int   $product_id     Product.
	 * @param int   $quantity       Quantity.
	 * @param int   $variation_id   Variation.
	 * @param array $variations     Attributes.
	 * @param array $cart_item_data Item data.
	 * @return bool
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		if ( ! $passed || empty( $variation_id ) || ! WC()->cart ) {
			return $passed;
		}
		$cart_item_data = is_array( $cart_item_data ) ? $cart_item_data : array();
		if ( ! empty( $cart_item_data[ Dahu_Composite_Cart::K_PARENT ] ) || isset( $cart_item_data[ Dahu_Composite_Cart::K_CONTAINER ] )
			|| ! empty( $cart_item_data['bundled_by'] ) || ! empty( $cart_item_data[ self::K_REORDER_DROP ] ) ) {
			return $passed;
		}

		$supply = self::get_supply( $variation_id );
		if ( ! $supply ) {
			return $passed;
		}

		$quantity = max( 1, wc_stock_amount( $quantity ) );
		$needed   = array();
		$products = array();
		foreach ( $supply as $entry ) {
			$product = self::accessory_product( $entry );
			if ( ! $product ) {
				self::log( 'warning', sprintf( 'Variation #%1$d: accessory #%2$d not found or not purchasable, ignored.', $variation_id, self::entry_id( $entry ) ) );
				continue;
			}
			$stock_id              = $product->get_stock_managed_by_id();
			$needed[ $stock_id ]   = ( isset( $needed[ $stock_id ] ) ? $needed[ $stock_id ] : 0 ) + $entry['quantity'] * $quantity;
			$products[ $stock_id ] = $product;
		}

		$in_cart = WC()->cart->get_cart_item_quantities();
		foreach ( $needed as $stock_id => $qty ) {
			$product = $products[ $stock_id ];
			$total   = $qty + ( isset( $in_cart[ $stock_id ] ) ? $in_cart[ $stock_id ] : 0 );
			if ( $product->is_in_stock() && $product->has_enough_stock( $total ) ) {
				continue;
			}
			$variation = wc_get_product( $variation_id );
			wc_add_notice(
				sprintf(
					/* translators: 1: variation, 2: accessory */
					__( '“%1$s” cannot be added: not enough stock of its accessory “%2$s”.', 'dahu-composite' ),
					$variation ? wp_strip_all_tags( $variation->get_name() ) : '#' . (int) $variation_id,
					wp_strip_all_tags( $product->get_name() )
				),
				'error'
			);
			return false;
		}
		return $passed;
	}

	/**
	 * After a variation is added: adds its accessories (child rows).
	 *
	 * Merge (the same variation already in the cart): WooCommerce increases the
	 * quantity of the existing row; its accessories follow the new quantity, no
	 * new accessory is added.
	 *
	 * Failure of an accessory: rollback (accessories added here are removed, the
	 * variation is removed or brought back to its quantity before the merge),
	 * then an exception — WC_Cart::add_to_cart() turns it into a message and
	 * returns false; the Store API returns it as an error (RouteException).
	 *
	 * @param string $cart_item_key  Key.
	 * @param int    $product_id     Product.
	 * @param int    $quantity       Quantity added.
	 * @param int    $variation_id   Variation.
	 * @param array  $variation      Attributes.
	 * @param array  $cart_item_data Item data.
	 * @throws Exception Accessory refused.
	 */
	public static function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		if ( self::$adding || ! WC()->cart ) {
			return;
		}
		$cart = WC()->cart;
		$item = $cart->get_cart_item( $cart_item_key );
		if ( ! self::is_eligible_item( $item ) ) {
			return;
		}

		if ( Dahu_Composite_Cart::is_supply_parent( $item ) ) {
			Dahu_Composite_Cart::sync_children_quantity( $cart_item_key, $item['quantity'], 0, $cart );
			return;
		}

		$supply = self::get_supply( $item['variation_id'] );
		if ( ! $supply ) {
			return;
		}

		$before_qty = max( 0, wc_stock_amount( $item['quantity'] ) - wc_stock_amount( $quantity ) );
		$notices    = function_exists( 'wc_get_notices' ) ? wc_get_notices() : array();
		$result     = self::attach( $cart, $cart_item_key, $supply, true );
		if ( ! is_wp_error( $result ) ) {
			return;
		}

		$details  = Dahu_Composite_Cart::collect_new_error_notices( $notices );
		$contents = $cart->get_cart_contents();
		if ( $before_qty > 0 ) {
			$contents[ $cart_item_key ]['quantity'] = $before_qty;
		} else {
			unset( $contents[ $cart_item_key ] );
		}
		$cart->set_cart_contents( $contents );
		$cart->calculate_totals();

		$name    = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? wp_strip_all_tags( $item['data']->get_name() ) : '#' . (int) $item['variation_id'];
		$message = sprintf(
			/* translators: 1: variation, 2: accessory */
			__( '“%1$s” could not be added: its accessory “%2$s” is unavailable.', 'dahu-composite' ),
			$name,
			$result->get_error_message()
		);
		if ( $details ) {
			$message .= ' ' . implode( ' ', $details );
		}
		self::log( 'warning', sprintf( 'Addition cancelled, variation #%1$d: %2$s', $item['variation_id'], $message ) );
		self::throw_add_error( $message );
	}

	/**
	 * Adds to the cart the accessories of a variation already in the cart, and
	 * records their keys on it.
	 *
	 * @param WC_Cart $cart       Cart.
	 * @param string  $parent_key Key of the variation.
	 * @param array[] $supply     get_supply().
	 * @param bool    $strict     true (customer addition): a refused accessory
	 *                            removes those already added here and returns a
	 *                            WP_Error (message = name of the accessory).
	 *                            false (rebuild): refusal logged, the other
	 *                            accessories are kept.
	 * @return string[]|WP_Error Keys of the accessories.
	 */
	private static function attach( WC_Cart $cart, $parent_key, array $supply, $strict ) {
		$parent = $cart->get_cart_item( $parent_key );
		if ( ! $parent ) {
			return array();
		}
		$parent_qty = max( 1, wc_stock_amount( $parent['quantity'] ) );
		$keys       = array();
		$error      = null;

		self::$adding = true;
		try {
			foreach ( $supply as $entry ) {
				$product = self::accessory_product( $entry );
				if ( ! $product ) {
					self::log( 'warning', sprintf( 'Variation #%1$d: accessory #%2$d not found or not purchasable, ignored.', $parent['variation_id'], self::entry_id( $entry ) ) );
					continue;
				}
				$child_data = array(
					Dahu_Composite_Cart::K_PARENT       => $parent_key,
					Dahu_Composite_Cart::K_COMPONENT    => 'supply-' . self::entry_id( $entry ),
					Dahu_Composite_Cart::K_UNIT_QTY     => $entry['quantity'],
					Dahu_Composite_Cart::K_SUPPLY_CHILD => true,
				);
				$attributes = $entry['variation_id'] ? wc_get_product_variation_attributes( $entry['variation_id'] ) : array();
				$key        = $cart->add_to_cart( $entry['product_id'], $entry['quantity'] * $parent_qty, $entry['variation_id'], $attributes, $child_data );

				if ( ! $key ) {
					if ( $strict ) {
						$error = new WP_Error( 'dahu_supply_child_failed', wp_strip_all_tags( $product->get_name() ) );
						break;
					}
					self::log( 'error', sprintf( 'Variation #%1$d: accessory #%2$d not added to the cart (stock?), variation kept without it.', $parent['variation_id'], self::entry_id( $entry ) ) );
					continue;
				}
				$keys[] = $key;
			}
		} catch ( Exception $e ) {
			$error = new WP_Error( 'dahu_supply_child_failed', $e->getMessage() );
		} finally {
			self::$adding = false;
		}

		$keys = array_values( array_unique( $keys ) );

		if ( $error && $strict ) {
			$contents = $cart->get_cart_contents();
			foreach ( $keys as $key ) {
				unset( $contents[ $key ] );
			}
			$cart->set_cart_contents( $contents );
			return $error;
		}
		if ( $error ) {
			self::log( 'error', sprintf( 'Variation #%1$d: %2$s', $parent['variation_id'], $error->get_error_message() ) );
		}

		$cart->cart_contents[ $parent_key ][ Dahu_Composite_Cart::K_SUPPLY ] = $keys;
		return $keys;
	}

	/**
	 * When the cart loads (every request, Store API included):
	 *  1. “Order again”: the old accessory rows are discarded (otherwise they
	 *     would come back as separate items, at full price);
	 *  2. variation missing an accessory (product withdrawn from sale in the
	 *     meantime, WooCommerce removed it from the cart): logged, kept;
	 *  3. variation with no supply attached — cart opened before the list was
	 *     set, or “order again” —: CURRENT accessories added. A refusal is
	 *     logged; the variation is never removed.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function reconcile_cart( $cart ) {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		try {
			$contents = $cart->get_cart_contents();
			$changed  = false;

			foreach ( $contents as $key => $item ) {
				if ( ! empty( $item[ self::K_REORDER_DROP ] ) ) {
					unset( $contents[ $key ] );
					$changed = true;
				}
			}

			foreach ( $contents as $key => $item ) {
				if ( ! Dahu_Composite_Cart::is_supply_parent( $item ) ) {
					continue;
				}
				$children = (array) $item[ Dahu_Composite_Cart::K_SUPPLY ];
				$present  = array();
				foreach ( $children as $child_key ) {
					if ( isset( $contents[ $child_key ] ) ) {
						$present[] = $child_key;
					}
				}
				if ( count( $present ) !== count( $children ) ) {
					$contents[ $key ][ Dahu_Composite_Cart::K_SUPPLY ] = $present;
					$changed = true;
					self::log( 'warning', sprintf( 'Variation #%d: an accessory left the cart (no longer purchasable?), variation kept without it.', $item['variation_id'] ) );
				}
			}

			if ( $changed ) {
				$cart->set_cart_contents( $contents );
			}

			foreach ( $cart->get_cart_contents() as $key => $item ) {
				if ( ! self::is_eligible_item( $item ) || Dahu_Composite_Cart::is_supply_parent( $item ) ) {
					continue;
				}
				$supply = self::get_supply( $item['variation_id'] );
				if ( ! $supply ) {
					continue;
				}
				// Messages of the internal addition are not shown to the customer: log.
				$notices = function_exists( 'wc_get_notices' ) ? wc_get_notices() : array();
				self::attach( $cart, $key, $supply, false );
				foreach ( Dahu_Composite_Cart::collect_new_error_notices( $notices ) as $notice ) {
					self::log( 'warning', sprintf( 'Variation #%1$d, rebuild: %2$s', $item['variation_id'], $notice ) );
				}
				$changed = true;
			}

			if ( $changed ) {
				$cart->calculate_totals();
			}
		} catch ( Exception $e ) {
			self::log( 'error', 'Supply rebuild interrupted: ' . $e->getMessage() );
		}
	}

	/**
	 * “Order again”: the old row of an accessory is marked to be discarded when
	 * the cart loads (reconcile_cart()). Read from the ROW (_dahu_supply), never
	 * from the product.
	 *
	 * @param array                 $cart_item_data Item data.
	 * @param WC_Order_Item_Product $item           Row of the old order.
	 * @param WC_Order              $order          Order.
	 * @return array
	 */
	public static function order_again_item_data( $cart_item_data, $item, $order = null ) {
		if ( 'child' === Dahu_Composite_Order::supply_role( $item ) ) {
			$cart_item_data                         = (array) $cart_item_data;
			$cart_item_data[ self::K_REORDER_DROP ] = 1;
		}
		return $cart_item_data;
	}

	/**
	 * Block cart: the quantity of an accessory is pinned to its value (it
	 * follows the variation's), like a bundle item in Product Bundles
	 * (WC_PB_Store_API::filter_bundled_cart_item_quantity_limits()): minimum =
	 * maximum = current quantity. The selector keeps its “− / +” buttons, greyed
	 * out, instead of a plain box with no buttons: the same rendering as a
	 * bundle child. The Store API refuses any other quantity
	 * (QuantityLimits::validate_cart_item_quantity()), and
	 * Dahu_Composite_Cart::enforce_child_quantities() recomputes it anyway.
	 * Filters woocommerce_store_api_product_quantity_minimum / _maximum.
	 *
	 * @param mixed      $value     Value.
	 * @param WC_Product $product   Product.
	 * @param array|null $cart_item Cart item.
	 * @return mixed
	 */
	public static function store_api_quantity_limit( $value, $product, $cart_item = null ) {
		return Dahu_Composite_Cart::is_supply_child( $cart_item ) ? max( 1, wc_stock_amount( $cart_item['quantity'] ) ) : $value;
	}

	/**
	 * @param string $message Message.
	 * @throws Exception Always.
	 */
	private static function throw_add_error( $message ) {
		$route_exception = 'Automattic\WooCommerce\StoreApi\Exceptions\RouteException';
		if ( class_exists( $route_exception ) && self::is_store_api_request() ) {
			throw new $route_exception( 'dahu_supply_add_failed', $message, 409 );
		}
		throw new Exception( $message );
	}

	/**
	 * @return bool
	 */
	private static function is_store_api_request() {
		if ( function_exists( 'WC' ) && is_callable( array( WC(), 'is_store_api_request' ) ) ) {
			return (bool) WC()->is_store_api_request();
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return defined( 'REST_REQUEST' ) && REST_REQUEST && false !== strpos( $uri, '/wc/store/' );
	}

	/*
	|--------------------------------------------------------------------------
	| Order screen
	|--------------------------------------------------------------------------
	|
	| Product Bundles' path (WC_PB_Admin_Order::add_bundled_items(), on
	| woocommerce_ajax_add_order_item_meta): WC_AJAX::maybe_add_order_item()
	| adds the row through $order->add_product(), with no stock movement, then
	| fires this hook; the accessories are added there, BEFORE
	| woocommerce_ajax_order_items_added, where dahu-pricing records then
	| restores the amount of the rows carrying _composite_parent against Addify.
	|
	| Product Bundles follows neither a quantity changed afterwards nor the
	| removal of the bundle: those two points are specific to the supply
	| (Dahu_Composite_Order::reconcile_supply()). Every anomaly is logged; the
	| order never fails because of an accessory.
	|
	| No refusal on addition. A product added by hand is an ordinary row, at the
	| price of the product, even when it is also the accessory of a variation of
	| the order (spare part, after-sales reshipment); every variation added
	| receives its OWN accessory rows. INVARIANT: quantity tracking, linked
	| removal and display identify an accessory by its group metas alone
	| (_dahu_supply = child, _composite_parent = _composite_cart_key of the
	| variation), never by its product.
	*/

	/**
	 * After a row is added by hand in the order screen: a supply variation
	 * receives its accessories. Never for a row already attached (kit, bundle,
	 * supply). Order note like Product Bundles (“Added bundled line items”).
	 *
	 * @param int           $item_id Row added.
	 * @param WC_Order_Item $item    Row (filtered by woocommerce_ajax_order_item).
	 * @param WC_Order      $order   Order.
	 */
	public static function add_admin_order_item( $item_id, $item, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		try {
			$line = $order->get_item( $item_id, false );
			if ( ! $line instanceof WC_Order_Item_Product || ! $line->get_variation_id() || ! self::is_standalone_line( $line ) ) {
				return;
			}
			$supply = self::get_supply( $line->get_variation_id() );
			if ( ! $supply ) {
				return;
			}

			$result = Dahu_Composite_Order::add_supply_to_order( $order, $line, $supply );
			$name   = wp_strip_all_tags( $line->get_name() );
			if ( $result['added'] ) {
				/* translators: 1: variation, 2: accessories */
				$order->add_order_note( sprintf( __( 'Accessories added with “%1$s”: %2$s.', 'dahu-composite' ), $name, implode( ', ', $result['added'] ) ), 0, true );
			}
			if ( $result['skipped'] ) {
				/* translators: 1: variation, 2: accessories */
				$message = sprintf( __( 'Accessories not added with “%1$s” (missing or not purchasable): %2$s.', 'dahu-composite' ), $name, implode( ', ', $result['skipped'] ) );
				$order->add_order_note( $message, 0, true );
				self::log( 'warning', sprintf( 'Order #%1$d: %2$s', $order->get_id(), $message ) );
			}
		} catch ( Throwable $e ) {
			self::log( 'error', sprintf( 'Order #%1$d, row #%2$d: accessories not added, %3$s', $order->get_id(), (int) $item_id, $e->getMessage() ) );
		}
	}

	/**
	 * Removal in the order screen: the row of a supply variation takes its
	 * accessories along. The rows are added to the request before WooCommerce
	 * handles it: WC_AJAX::remove_order_item() is what deletes them, with its
	 * own stock return and its own note. Invalid request (nonce, rights):
	 * nothing is touched.
	 */
	public static function extend_admin_order_item_removal() {
		if ( ! current_user_can( 'edit_shop_orders' ) || ! check_ajax_referer( 'order-item', 'security', false ) ) {
			return;
		}
		try {
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
			$order = isset( $_POST['order_id'] ) ? wc_get_order( absint( wp_unslash( $_POST['order_id'] ) ) ) : false;
			$ids   = isset( $_POST['order_item_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order_item_ids'] ) ) : array();
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			if ( ! $order instanceof WC_Order || ! $ids ) {
				return;
			}
			$all = $ids;
			foreach ( $ids as $id ) {
				$line = $order->get_item( $id );
				if ( $line instanceof WC_Order_Item_Product ) {
					$all = array_merge( $all, array_keys( Dahu_Composite_Order::supply_children( $order, $line ) ) );
				}
			}
			$all = array_values( array_unique( array_map( 'absint', $all ) ) );
			if ( count( $all ) !== count( $ids ) ) {
				$_POST['order_item_ids'] = $all;
			}
		} catch ( Throwable $e ) {
			self::log( 'error', 'Removal of the accessories with their variation interrupted: ' . $e->getMessage() );
		}
	}

	/**
	 * After a removal (a lone accessory, or a variation removed through another
	 * path): groups put back in order.
	 *
	 * @param int                 $item_id Last row removed.
	 * @param WC_Order_Item|false $item    Row.
	 * @param mixed               $changed Result of the stock handling.
	 * @param WC_Order|false      $order   Order.
	 */
	public static function after_admin_order_items_removed( $item_id, $item = false, $changed = false, $order = false ) {
		self::reconcile_admin_order( $order );
	}

	/**
	 * After the rows are saved (“Save” button, “Recalculate”, order update):
	 * quantities of the accessories followed.
	 *
	 * @param int   $order_id Order.
	 * @param array $items    Posted data.
	 */
	public static function after_admin_order_items_saved( $order_id, $items = array() ) {
		self::reconcile_admin_order( wc_get_order( $order_id ) );
	}

	/**
	 * @param WC_Order|mixed $order Order.
	 */
	private static function reconcile_admin_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		try {
			Dahu_Composite_Order::reconcile_supply( $order );
		} catch ( Throwable $e ) {
			self::log( 'error', sprintf( 'Order #%1$d: accessories not put back in order, %2$s', $order->get_id(), $e->getMessage() ) );
		}
	}

	/**
	 * Row set by hand, attached to nothing: neither kit, nor bundle, nor WCCP
	 * composite, nor supply.
	 *
	 * @param WC_Order_Item $line Row.
	 * @return bool
	 */
	private static function is_standalone_line( $line ) {
		return $line instanceof WC_Order_Item_Product
			&& '' === Dahu_Composite_Order::supply_role( $line )
			&& '' === Dahu_Composite_Order::kit_role( $line )
			&& '' === (string) $line->get_meta( '_composite_parent', true )
			&& '' === (string) $line->get_meta( '_composite_cart_key', true )
			&& '' === (string) $line->get_meta( '_bundled_by', true )
			&& '' === (string) $line->get_meta( '_bundle_cart_key', true );
	}


	/*
	|--------------------------------------------------------------------------
	| Administration (variation)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Script of the field, product screen only. The selector is WooCommerce's
	 * own (wc-enhanced-select, searching products AND variations); WooCommerce
	 * also initialises it on the variations loaded through AJAX
	 * (`wc-enhanced-select-init` at the end of variations_loaded()).
	 */
	public static function enqueue_admin() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		wp_enqueue_script( 'dahu-composite-supply-admin', DAHU_COMPOSITE_URL . 'assets/js/dahu-composite-supply-admin.js', array( 'jquery', 'wc-enhanced-select' ), DAHU_COMPOSITE_VERSION, true );
		wp_localize_script(
			'dahu-composite-supply-admin',
			'dahuSupplyAdmin',
			array(
				'remove' => __( 'Remove', 'dahu-composite' ),
			)
		);
	}

	/**
	 * “Supplied accessories” field of a variation, below its attributes: one row
	 * per item (an item added twice = quantity 2 per unit), and a selector to
	 * add one.
	 *
	 * Posted fields: dahu_supply_present[loop] (always: tells an emptied list
	 * apart from an absent field), dahu_supply[loop][row][id].
	 *
	 * @param int     $loop           Index of the variation.
	 * @param array   $variation_data Data.
	 * @param WP_Post $variation      Variation.
	 */
	public static function render_variation_field( $loop, $variation_data, $variation ) {
		$loop         = (int) $loop;
		$variation_id = (int) $variation->ID;
		$search_id    = 'dahu_supply_search_' . $loop;
		// Only a simple product or a variation can be put in the cart as an
		// accessory: the other types are kept out of the search (they would be
		// refused on saving anyway).
		$exclude_types = function_exists( 'wc_get_product_types' ) ? array_diff( array_keys( wc_get_product_types() ), array( 'simple' ) ) : array();

		echo '<div class="form-row form-row-full dahu-supply" data-loop="' . esc_attr( $loop ) . '">';
		echo '<input type="hidden" class="dahu-supply__present" name="dahu_supply_present[' . esc_attr( $loop ) . ']" value="1" />';
		echo '<label for="' . esc_attr( $search_id ) . '">' . esc_html__( 'Supplied accessories', 'dahu-composite' ) . '</label>';
		echo '<ul class="dahu-supply__list">';
		$row = 0;
		foreach ( self::get_supply( $variation_id ) as $entry ) {
			for ( $i = 0; $i < $entry['quantity']; $i++ ) {
				self::render_row( $loop, (string) $row, $entry );
				++$row;
			}
		}
		echo '</ul>';
		printf(
			'<select id="%1$s" class="wc-product-search dahu-supply__search" style="width:100%%;" data-placeholder="%2$s" data-action="woocommerce_json_search_products_and_variations" data-exclude="%3$s" data-exclude_type="%4$s" data-allow_clear="true"></select>',
			esc_attr( $search_id ),
			esc_attr__( 'Add an accessory…', 'dahu-composite' ),
			esc_attr( (string) $variation_id ),
			esc_attr( implode( ',', $exclude_types ) )
		);
		echo '</div>';
	}

	/**
	 * One row of the field: one unit of an accessory.
	 *
	 * @param int    $loop  Index of the variation.
	 * @param string $row   Index of the row.
	 * @param array  $entry Normalised entry.
	 */
	private static function render_row( $loop, $row, array $entry ) {
		$id      = self::entry_id( $entry );
		$product = wc_get_product( $id );
		$state   = '';
		if ( ! $product ) {
			$state = __( 'not found', 'dahu-composite' );
		} elseif ( ! self::accessory_product( $entry ) ) {
			$state = __( 'not purchasable, ignored in the cart', 'dahu-composite' );
		}
		$name = $product ? wp_strip_all_tags( $product->get_formatted_name() ) : '#' . $id;
		$base = 'dahu_supply[' . $loop . '][' . $row . ']';

		echo '<li class="dahu-supply__row" data-id="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $base . '[id]' ) . '" value="' . esc_attr( (string) $id ) . '" />';
		echo '<span class="dahu-supply__name">' . esc_html( $name ) . '</span>';
		if ( '' !== $state ) {
			echo ' <strong class="dahu-supply__state">' . esc_html( $state ) . '</strong>';
		}
		echo '<button type="button" class="button-link js-dahu-supply-remove">' . esc_html__( 'Remove', 'dahu-composite' ) . '</button>';
		echo '</li>';
	}

	/**
	 * Saving. Like dahu-serials: field absent from the request = value kept;
	 * empty list = list deleted. An invalid row saves nothing and shows a
	 * message (the previous list is kept).
	 *
	 * Stored format unchanged (array of entries, see the file header): only the
	 * input has changed. A list saved with the older text field is read back
	 * as it is.
	 *
	 * @param int $variation_id Variation.
	 * @param int $loop         Index of the variation.
	 */
	public static function save_variation_field( $variation_id, $loop ) {
		// WooCommerce has already checked the nonce before this hook.
		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by WooCommerce.
		if ( ! isset( $_POST['dahu_supply_present'] ) || ! is_array( $_POST['dahu_supply_present'] ) || ! isset( $_POST['dahu_supply_present'][ $loop ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked by WooCommerce; every row is parsed.
		$rows   = isset( $_POST['dahu_supply'] ) && is_array( $_POST['dahu_supply'] ) && isset( $_POST['dahu_supply'][ $loop ] ) && is_array( $_POST['dahu_supply'][ $loop ] ) ? wp_unslash( $_POST['dahu_supply'][ $loop ] ) : array();
		$parsed = self::parse_rows( $rows, (int) $variation_id );

		if ( $parsed['errors'] ) {
			$message = sprintf(
				/* translators: 1: variation, 2: errors */
				__( 'Variation #%1$d: accessories not saved. %2$s', 'dahu-composite' ),
				(int) $variation_id,
				implode( ' ', $parsed['errors'] )
			);
			if ( class_exists( 'WC_Admin_Meta_Boxes' ) && method_exists( 'WC_Admin_Meta_Boxes', 'add_error' ) ) {
				WC_Admin_Meta_Boxes::add_error( esc_html( $message ) );
			}
			self::log( 'notice', $message );
			return;
		}

		if ( $parsed['entries'] ) {
			update_post_meta( (int) $variation_id, self::META, $parsed['entries'] );
		} else {
			delete_post_meta( (int) $variation_id, self::META );
		}
	}

	/**
	 * Parsing of the posted rows. The same item on several rows = one entry,
	 * quantity = number of rows. Accepted accessory: a simple product, or a
	 * variation whose attributes are all fixed (a variation with “Any” cannot
	 * be put in the cart without a choice). An “apart” key still being posted
	 * (older form left open) is ignored.
	 *
	 * @param array $rows         [ row => [ id ] ].
	 * @param int   $variation_id Variation being edited.
	 * @return array { entries: array[], errors: string[] }
	 */
	private static function parse_rows( array $rows, $variation_id ) {
		$entries = array();
		$errors  = array();
		$index   = array();
		$invalid = array();

		foreach ( $rows as $row ) {
			$id = is_array( $row ) && isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			if ( ! $id ) {
				continue;
			}

			if ( isset( $index[ $id ] ) ) {
				++$entries[ $index[ $id ] ]['quantity'];
				continue;
			}
			if ( isset( $invalid[ $id ] ) ) {
				continue;
			}
			// One row refused below: the other rows of the same item are
			// ignored (a single message).
			$invalid[ $id ] = true;

			$product = wc_get_product( $id );
			if ( ! $product ) {
				/* translators: %d: ID */
				$errors[] = sprintf( __( '#%d: product not found.', 'dahu-composite' ), $id );
				continue;
			}
			if ( $id === $variation_id ) {
				/* translators: %d: ID */
				$errors[] = sprintf( __( '#%d: the variation itself.', 'dahu-composite' ), $id );
				continue;
			}

			if ( $product->is_type( 'variation' ) ) {
				if ( in_array( '', wc_get_product_variation_attributes( $id ), true ) ) {
					/* translators: %d: ID */
					$errors[] = sprintf( __( '#%d: a variation with an “Any” attribute, which is not supported.', 'dahu-composite' ), $id );
					continue;
				}
				$entry = array(
					'product_id'   => (int) $product->get_parent_id(),
					'variation_id' => $id,
				);
			} elseif ( $product->is_type( 'simple' ) ) {
				$entry = array(
					'product_id'   => $id,
					'variation_id' => 0,
				);
			} elseif ( $product->is_type( 'variable' ) ) {
				/* translators: %d: ID */
				$errors[] = sprintf( __( '#%d: a variable product; choose one of its variations.', 'dahu-composite' ), $id );
				continue;
			} else {
				/* translators: 1: ID, 2: type */
				$errors[] = sprintf( __( '#%1$d: type “%2$s” is not supported (simple product or variation only).', 'dahu-composite' ), $id, $product->get_type() );
				continue;
			}

			unset( $invalid[ $id ] );
			$index[ $id ] = count( $entries );
			$entries[]    = $entry + array( 'quantity' => 1 );
		}

		return array(
			'entries' => $entries,
			'errors'  => $errors,
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Log
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param string $level   WooCommerce level (warning, error, notice…).
	 * @param string $message Message.
	 */
	private static function log( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
		}
	}
}
