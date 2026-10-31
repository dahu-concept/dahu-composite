<?php
/**
 * Cart: adding a kit (container + one item per component — per option chosen,
 * for a multiple-choice component), prices, parcel weight, quantity
 * synchronisation, grouped removal, display.
 *
 * Cart item keys (naming specific to the engine — only the ORDER ROW metadata
 * reuses the WCCP names, see Dahu_Composite_Order):
 *  - container: dahu_kit_container (uid, selections), dahu_kit_children (cart
 *    keys of the children);
 *  - child:     dahu_kit_parent (cart key of the container), dahu_kit_component
 *    (id of the component), dahu_kit_unit_qty (quantity per kit),
 *    dahu_kit_shipped_individually.
 *
 * The WCCP keys (composite_parent, composite_children…) are deliberately NOT
 * used on the cart side: when WCCP is still active, it must not take over the
 * items of a Dahu kit.
 *
 * Supply of a variation (Dahu_Composite_Supply): the variation carries
 * dahu_supply_children (cart keys of its accessories), NEVER
 * dahu_kit_container — a container is set to 0, displayed at the price of its
 * children and given the “Configurator” class (setup_cart_item(),
 * set_prices(), container_price()), which dahu-post makes free for the whole
 * order. Its accessories carry the child keys above, plus dahu_supply_child.
 * MIND the name: dahu_kit_parent is today SHARED between kit components and
 * supply accessories (a rename is planned in the “the kit ships the supply”
 * work, see RESUME_TECHNIQUE §2 bis); the dahu-post fallback that reads it
 * (dahu_post_billable_package()) only applies to kits, the accessory having
 * already been taken out of the parcel by shipping_packages().
 * “Group parent” (is_group_parent()) = kit container OR supply variation:
 * quantities, linked removal and parcels; prices and display stay specific to
 * the container.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Cart {

	const K_CONTAINER = 'dahu_kit_container';
	const K_CHILDREN  = 'dahu_kit_children';
	// Shared today between kit components and supply
	// accessories; the dahu-post fallback only applies to kits (file header).
	const K_PARENT    = 'dahu_kit_parent';
	const K_COMPONENT = 'dahu_kit_component';
	const K_UNIT_QTY  = 'dahu_kit_unit_qty';
	const K_SHIPPED   = 'dahu_kit_shipped_individually';

	/** Supply variation: cart keys of its accessories. */
	const K_SUPPLY = 'dahu_supply_children';

	/** Accessory of a variation (on top of the child keys). */
	const K_SUPPLY_CHILD = 'dahu_supply_child';

	/** @var bool Grouped removal under way (avoids recursion). */
	private static $removing = false;

	/** @var bool Quantity synchronisation under way. */
	private static $syncing = false;

	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_direct_add' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item', array( __CLASS__, 'setup_cart_item' ), 20, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'setup_cart_item_from_session' ), 20, 3 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'check_integrity' ), 20 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'enforce_child_quantities' ), 1 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_prices' ), 1000 );
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'shipping_packages' ), 20 );

		add_action( 'woocommerce_remove_cart_item', array( __CLASS__, 'remove_linked_items' ), 10, 2 );
		add_action( 'woocommerce_restore_cart_item', array( __CLASS__, 'restore_children' ), 10, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( __CLASS__, 'sync_children_quantity' ), 10, 4 );

		add_filter( 'woocommerce_cart_item_remove_link', array( __CLASS__, 'child_remove_link' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'child_quantity' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_class', array( __CLASS__, 'item_class' ), 10, 3 );
		add_filter( 'woocommerce_mini_cart_item_class', array( __CLASS__, 'item_class' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'item_name' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'container_price' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'container_subtotal' ), 10, 3 );
	}

	/*
	|--------------------------------------------------------------------------
	| Helpers
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param array $item Cart item.
	 * @return bool
	 */
	public static function is_container( $item ) {
		return is_array( $item ) && isset( $item[ self::K_CONTAINER ] );
	}

	/**
	 * @param array $item Cart item.
	 * @return bool
	 */
	public static function is_child( $item ) {
		return is_array( $item ) && ! empty( $item[ self::K_PARENT ] );
	}

	/**
	 * Supply variation (never a kit container).
	 *
	 * @param array $item Cart item.
	 * @return bool
	 */
	public static function is_supply_parent( $item ) {
		return is_array( $item ) && isset( $item[ self::K_SUPPLY ] ) && ! self::is_container( $item );
	}

	/**
	 * Accessory of a variation.
	 *
	 * @param array $item Cart item.
	 * @return bool
	 */
	public static function is_supply_child( $item ) {
		return self::is_child( $item ) && ! empty( $item[ self::K_SUPPLY_CHILD ] );
	}

	/**
	 * Kit container or supply variation.
	 *
	 * @param array $item Cart item.
	 * @return bool
	 */
	public static function is_group_parent( $item ) {
		return self::is_container( $item ) || self::is_supply_parent( $item );
	}

	/**
	 * Cart keys of the children of a group parent.
	 *
	 * @param array $item Cart item.
	 * @return string[]
	 */
	public static function group_children( $item ) {
		if ( self::is_container( $item ) ) {
			return isset( $item[ self::K_CHILDREN ] ) ? (array) $item[ self::K_CHILDREN ] : array();
		}
		if ( self::is_supply_parent( $item ) ) {
			return (array) $item[ self::K_SUPPLY ];
		}
		return array();
	}

	/**
	 * Loads the cart and the session (REST requests: WooCommerce does not
	 * initialise them by itself).
	 */
	public static function ensure_cart() {
		if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
			wc_load_cart();
		}
		if ( WC()->cart ) {
			WC()->cart->get_cart();
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Adding to the cart
	|--------------------------------------------------------------------------
	*/

	/**
	 * A kit is never added through the standard form (?add-to-cart=ID, Store
	 * API): its configuration has to go through /add-to-cart, which revalidates
	 * it. WC_Cart::add_to_cart(), used internally, does not apply this filter.
	 *
	 * @param bool $passed     Validation.
	 * @param int  $product_id Product.
	 * @return bool
	 */
	public static function block_direct_add( $passed, $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product_Dahu_Kit ) {
			wc_add_notice( __( 'This product must be configured before it can be added to the cart.', 'dahu-composite' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Rows of a kit and composition identifier, writing nothing into the cart or
	 * into an order. Shared by adding to the cart (add_kit_to_cart()) and by
	 * configuring a kit in the order screen
	 * (Dahu_Composite_Order::add_kit_to_order()): same normalised selections, same
	 * validation, same rows, same identifier.
	 *
	 * The structure of the kit (components, order, mandatory ones, multiple
	 * choices, scenarios) always comes from the kit itself
	 * (Dahu_Composite_Engine): nothing is redefined here.
	 *
	 * @param WC_Product_Dahu_Kit $kit            Kit.
	 * @param mixed               $raw_selections Raw selections.
	 * @param int                 $quantity       Number of kits.
	 * @param array               $args           {
	 *     @type bool $ignore_stock Accept a component out of stock (order
	 *                              screen). Default: false (cart).
	 * }
	 * @return array|WP_Error {
	 *     @type array $selections  Normalised and validated selections.
	 *     @type array $lines       Dahu_Composite_Engine::build_lines().
	 *     @type array $composition Dahu_Composite_Composition::build() { id, data }.
	 * }
	 */
	public static function build_kit_lines( WC_Product_Dahu_Kit $kit, $raw_selections, $quantity = 1, array $args = array() ) {
		$quantity   = max( 1, (int) $quantity );
		$selections = Dahu_Composite_Engine::normalize_selections( $kit, $raw_selections );
		$validation = Dahu_Composite_Engine::validate( $kit, $selections, $quantity );
		if ( ! empty( $args['ignore_stock'] ) ) {
			$validation = Dahu_Composite_Engine::without_stock_errors( $validation );
		}

		if ( ! $validation['valid'] ) {
			return new WP_Error(
				'dahu_kit_invalid',
				__( 'The configuration is incomplete or invalid.', 'dahu-composite' ),
				array(
					'status'     => 400,
					'validation' => $validation,
				)
			);
		}

		return array(
			'selections'  => $selections,
			'lines'       => Dahu_Composite_Engine::build_lines( $kit, $selections, $quantity ),
			// Composition identifier: computed on the validated configuration;
			// kept on the container of the cart until the order.
			'composition' => Dahu_Composite_Composition::build( $kit, $selections ),
		);
	}

	/**
	 * Adds a configured kit to the cart. Revalidates EVERYTHING server side.
	 *
	 * @param WC_Product_Dahu_Kit $kit            Kit.
	 * @param mixed               $raw_selections Raw selections from the client.
	 * @param int                 $quantity       Number of kits.
	 * @return array|WP_Error ['cart_item_key' => string, 'children' => string[]]
	 */
	public static function add_kit_to_cart( WC_Product_Dahu_Kit $kit, $raw_selections, $quantity = 1 ) {
		self::ensure_cart();
		if ( ! WC()->cart ) {
			return new WP_Error( 'dahu_kit_no_cart', __( 'Cart unavailable.', 'dahu-composite' ), array( 'status' => 500 ) );
		}

		$quantity = max( 1, (int) $quantity );
		$built    = self::build_kit_lines( $kit, $raw_selections, $quantity );
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$selections      = $built['selections'];
		$lines           = $built['lines'];
		$composition     = $built['composition'];
		$uid             = wp_generate_uuid4();
		$notices_before  = function_exists( 'wc_get_notices' ) ? wc_get_notices() : array();
		$container_key   = false;
		$child_keys      = array();
		$failure_message = '';

		$container_key = WC()->cart->add_to_cart(
			$kit->get_id(),
			$quantity,
			0,
			array(),
			array(
				self::K_CONTAINER => array(
					'uid'         => $uid,
					'selections'  => $selections,
					'composition' => $composition,
				),
				self::K_CHILDREN  => array(),
			)
		);

		if ( ! $container_key ) {
			$failure_message = __( 'The product could not be added to the cart.', 'dahu-composite' );
		} else {
			foreach ( $lines as $line ) {
				$child_qty = (int) $line['quantity'] * $quantity;
				if ( $child_qty < 1 ) {
					continue;
				}
				// Item of THE row (build_line()): for a multiple-choice
				// component, several rows share the same component.
				$selection  = $line['selection'];
				$child_data = array(
					self::K_PARENT    => $container_key,
					self::K_COMPONENT => $line['component_id'],
					self::K_UNIT_QTY  => (int) $line['quantity'],
					self::K_SHIPPED   => (bool) $line['shipped_individually'],
					'dahu_kit_uid'    => $uid,
				);

				if ( 'bundle' === $line['type'] ) {
					$result = self::add_bundle_child( $line, $selection, $child_qty, $child_data );
				} else {
					$attributes = array();
					if ( $line['variation_id'] ) {
						$variation  = wc_get_product( $line['variation_id'] );
						$attributes = $variation ? Dahu_Composite_Engine::variation_attributes( $variation, $selection['attributes'] ) : array();
					}
					$result = WC()->cart->add_to_cart( $line['product_id'], $child_qty, (int) $line['variation_id'], $attributes, $child_data );
				}

				if ( ! $result || is_wp_error( $result ) ) {
					/* translators: %s: product */
					$failure_message = sprintf( __( '“%s” could not be added to the cart.', 'dahu-composite' ), $line['name'] );
					if ( is_wp_error( $result ) ) {
						$failure_message .= ' ' . $result->get_error_message();
					}
					break;
				}
				$child_keys[] = $result;
			}

			if ( ! $failure_message && empty( $child_keys ) ) {
				$failure_message = __( 'Select at least one component.', 'dahu-composite' );
			}
		}

		if ( $failure_message ) {
			$details = self::collect_new_error_notices( $notices_before );
			self::rollback( $container_key, $child_keys );
			return new WP_Error(
				'dahu_kit_add_failed',
				$failure_message,
				array(
					'status'  => 409,
					'notices' => $details,
				)
			);
		}

		WC()->cart->cart_contents[ $container_key ][ self::K_CHILDREN ] = $child_keys;
		WC()->cart->calculate_totals();

		if ( WC()->session && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
			WC()->session->set_customer_session_cookie( true );
		}

		do_action( 'dahu_composite_kit_added_to_cart', $container_key, $child_keys, $kit, $selections, $quantity );

		return array(
			'cart_item_key' => $container_key,
			'children'      => $child_keys,
		);
	}

	/**
	 * Adds a component of the Product Bundle type through PB's API, which adds
	 * the items of the bundle itself (bundled_by cart key, and _bundled_by /
	 * _bundled_item_priced_individually row metas on the order).
	 *
	 * @param array $line       Computed row.
	 * @param array $selection  Selection.
	 * @param int   $qty        Total quantity.
	 * @param array $child_data Item data.
	 * @return string|false|WP_Error
	 */
	private static function add_bundle_child( array $line, array $selection, $qty, array $child_data ) {
		if ( ! function_exists( 'WC_PB' ) || ! WC_PB()->cart ) {
			return new WP_Error( 'dahu_kit_pb_missing', __( 'This component requires a product bundle plugin.', 'dahu-composite' ) );
		}
		$bundle = wc_get_product( $line['product_id'] );
		if ( ! $bundle ) {
			return false;
		}
		$configuration = self::bundle_configuration( $bundle, $selection );
		return WC_PB()->cart->add_bundle_to_cart( $line['product_id'], $qty, $configuration, $child_data );
	}

	/**
	 * PB configuration of a bundle, from the customer's selection.
	 *
	 * @param WC_Product $bundle    Bundle.
	 * @param array      $selection Selection.
	 * @return array
	 */
	public static function bundle_configuration( WC_Product $bundle, array $selection ) {
		$configuration = WC_PB()->cart->parse_bundle_configuration( $bundle );

		foreach ( $bundle->get_bundled_items() as $bundled_item_id => $bundled_item ) {
			$conf = isset( $selection['bundle'][ $bundled_item_id ] ) ? $selection['bundle'][ $bundled_item_id ] : null;

			if ( $bundled_item->is_optional() ) {
				$selected = $conf && $conf['selected'];
				$configuration[ $bundled_item_id ]['optional_selected'] = $selected ? 'yes' : 'no';
				if ( ! $selected ) {
					$configuration[ $bundled_item_id ]['quantity'] = 0;
					continue;
				}
			}

			$qty = ( $conf && ! empty( $conf['quantity'] ) ) ? (int) $conf['quantity'] : (int) $bundled_item->get_quantity( 'default' );
			$configuration[ $bundled_item_id ]['quantity'] = max( (int) $bundled_item->get_quantity( 'min' ), max( 1, $qty ) );

			$product = $bundled_item->get_product();
			if ( $product && $product->is_type( 'variable' ) ) {
				$vid = Dahu_Composite_Engine::resolve_bundled_variation( $bundled_item, $conf );
				if ( $vid ) {
					$configuration[ $bundled_item_id ]['variation_id'] = $vid;
					$configuration[ $bundled_item_id ]['attributes']   = wc_get_product_variation_attributes( $vid );
				}
			}
		}

		return apply_filters( 'dahu_composite_bundle_configuration', $configuration, $bundle, $selection );
	}

	/**
	 * Removes a partial addition from the cart (container, children, bundle
	 * items).
	 *
	 * @param string|false $container_key Container key.
	 * @param string[]     $child_keys    Child keys.
	 */
	private static function rollback( $container_key, array $child_keys ) {
		$remove = array_flip( array_filter( array_merge( array( $container_key ), $child_keys ) ) );
		if ( empty( $remove ) ) {
			return;
		}
		$contents = WC()->cart->get_cart_contents();

		// 1. Container + every child attached (including a child that was added
		//    but whose add_to_cart returned an error).
		foreach ( $contents as $key => $item ) {
			if ( $container_key && isset( $item[ self::K_PARENT ] ) && $item[ self::K_PARENT ] === $container_key ) {
				$remove[ $key ] = true;
			}
		}
		// 2. Bundle items of those children.
		foreach ( $contents as $key => $item ) {
			if ( isset( $item['bundled_by'] ) && isset( $remove[ $item['bundled_by'] ] ) ) {
				$remove[ $key ] = true;
			}
		}
		foreach ( array_keys( $remove ) as $key ) {
			unset( $contents[ $key ] );
		}
		WC()->cart->set_cart_contents( $contents );
		WC()->cart->calculate_totals();
	}

	/**
	 * Error notices added during the operation, then the original notices
	 * restored (we do not want to show them to the customer twice). Shared with
	 * the addition of the accessories of a variation.
	 *
	 * @param array $before Notices before the operation.
	 * @return string[]
	 */
	public static function collect_new_error_notices( $before ) {
		if ( ! function_exists( 'wc_get_notices' ) ) {
			return array();
		}
		$all_errors    = wc_get_notices( 'error' );
		$before_errors = isset( $before['error'] ) ? count( $before['error'] ) : 0;
		$new           = array();
		foreach ( array_slice( (array) $all_errors, $before_errors ) as $notice ) {
			$new[] = wp_strip_all_tags( is_array( $notice ) ? $notice['notice'] : (string) $notice );
		}
		wc_set_notices( $before );
		return $new;
	}

	/**
	 * Standard WooCommerce cart fragments (mini-cart), so that the front end
	 * fires the usual added_to_cart jQuery event.
	 *
	 * @return array
	 */
	public static function get_fragments() {
		$mini_cart = '';
		if ( function_exists( 'woocommerce_mini_cart' ) ) {
			ob_start();
			woocommerce_mini_cart();
			$mini_cart = ob_get_clean();
		}

		return array(
			'fragments'  => apply_filters(
				'woocommerce_add_to_cart_fragments',
				array(
					'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
				)
			),
			'cart_hash'  => WC()->cart->get_cart_hash(),
			'cart_url'   => wc_get_cart_url(),
			'cart_count' => WC()->cart->get_cart_contents_count(),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Product data of the items
	|--------------------------------------------------------------------------
	*/

	/**
	 * Container: never virtual (it carries the “Configurator” shipping class that
	 * dahu-post depends on, and the aggregated parcel), price 0.
	 *
	 * @param array  $item Item.
	 * @param string $key  Key.
	 * @return array
	 */
	public static function setup_cart_item( $item, $key ) {
		if ( self::is_container( $item ) && isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
			$item['data']->set_virtual( false );
			$item['data']->set_price( 0 );
			if ( ! $item['data']->get_shipping_class_id() ) {
				$class_id = Dahu_Composite_Product_Type::get_container_shipping_class_id();
				if ( $class_id ) {
					$item['data']->set_shipping_class_id( $class_id );
				}
			}
		}
		return $item;
	}

	/**
	 * @param array  $item   Item rebuilt.
	 * @param array  $values Values in session.
	 * @param string $key    Key.
	 * @return array
	 */
	public static function setup_cart_item_from_session( $item, $values, $key ) {
		foreach ( array( self::K_CONTAINER, self::K_CHILDREN, self::K_PARENT, self::K_COMPONENT, self::K_UNIT_QTY, self::K_SHIPPED, 'dahu_kit_uid', self::K_SUPPLY, self::K_SUPPLY_CHILD ) as $k ) {
			if ( isset( $values[ $k ] ) ) {
				$item[ $k ] = $values[ $k ];
			}
		}
		return self::setup_cart_item( $item, $key );
	}

	/**
	 * Consistency after the session has loaded: a child with no container, or an
	 * incomplete container, is removed (the whole kit). An accessory whose
	 * variation has gone is removed too (it is at 0); a variation missing an
	 * accessory is handled by Dahu_Composite_Supply::reconcile_cart() (logged,
	 * never removed).
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function check_integrity( $cart ) {
		$contents    = $cart->get_cart_contents();
		$remove      = array();
		$kit_removed = false;

		foreach ( $contents as $key => $item ) {
			if ( self::is_container( $item ) ) {
				$children = isset( $item[ self::K_CHILDREN ] ) ? (array) $item[ self::K_CHILDREN ] : array();
				foreach ( $children as $child_key ) {
					if ( ! isset( $contents[ $child_key ] ) ) {
						$remove[ $key ] = true;
						foreach ( $children as $other ) {
							$remove[ $other ] = true;
						}
						$kit_removed = true;
						break;
					}
				}
			} elseif ( self::is_child( $item ) && ! isset( $contents[ $item[ self::K_PARENT ] ] ) ) {
				$remove[ $key ] = true;
				if ( ! self::is_supply_child( $item ) ) {
					$kit_removed = true;
				}
			}
		}

		if ( empty( $remove ) ) {
			return;
		}

		foreach ( $contents as $key => $item ) {
			if ( isset( $item['bundled_by'] ) && isset( $remove[ $item['bundled_by'] ] ) ) {
				$remove[ $key ] = true;
			}
		}
		foreach ( array_keys( $remove ) as $key ) {
			unset( $contents[ $key ] );
		}
		$cart->set_cart_contents( $contents );

		if ( $kit_removed && function_exists( 'wc_add_notice' ) && ! wp_doing_ajax() ) {
			wc_add_notice( __( 'An incomplete product was removed from your cart.', 'dahu-composite' ), 'notice' );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Prices
	|--------------------------------------------------------------------------
	*/

	/**
	 * Safety net: the quantity of a component is always “quantity per kit ×
	 * quantity of the kit”. Covers the block cart (Store API), where the quantity
	 * / removal filters of the classic cart do not apply and where a component
	 * would stay editable.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function enforce_child_quantities( $cart ) {
		foreach ( $cart->cart_contents as $key => $item ) {
			if ( ! self::is_child( $item ) || ! isset( $cart->cart_contents[ $item[ self::K_PARENT ] ] ) ) {
				continue;
			}
			$expected = max( 1, (int) $item[ self::K_UNIT_QTY ] ) * max( 1, (int) $cart->cart_contents[ $item[ self::K_PARENT ] ]['quantity'] );
			if ( (int) $item['quantity'] !== $expected ) {
				$cart->cart_contents[ $key ]['quantity'] = $expected;
			}
		}
	}

	/**
	 * Container at 0, every child at the unit price of the current role — the same
	 * resolver as /quote, so cart = quote. Priority 1000: after Addify and Product
	 * Bundles. The items of a bundle (bundled_by) stay handled by Product Bundles.
	 *
	 * The price is resolved on a freshly read product, not on the one of the item:
	 * at this priority, Addify has already written the role price into the object,
	 * and a percentage rule recomputed on it applied twice. See
	 * Dahu_Composite_Pricing::get_cart_item_price().
	 *
	 * Accessory of a variation: 0 (supplied with it), with no role price. Catalogue
	 * price at 0 and no sale price either: the Store API returns
	 * get_regular_price() (ProductSchema::prepare_product_price_response()), and the
	 * block cart shows “Save” as soon as the catalogue price exceeds the price — a
	 * badge dahu-pricing adds to the total in Customer mode. The variation keeps
	 * its price: it is not a container.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function set_prices( $cart ) {
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			if ( self::is_container( $item ) ) {
				$item['data']->set_price( 0 );
			} elseif ( self::is_supply_child( $item ) ) {
				$item['data']->set_regular_price( 0 );
				$item['data']->set_sale_price( '' );
				$item['data']->set_price( 0 );
			} elseif ( self::is_child( $item ) ) {
				$price = Dahu_Composite_Pricing::get_cart_item_price( $item['data'], (int) $item['quantity'] );
				if ( null !== $price ) {
					$item['data']->set_price( $price );
				}
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Shipping
	|--------------------------------------------------------------------------
	*/

	/**
	 * Parcels: the components not “shipped individually” are taken out of the
	 * parcel and their weight/value are aggregated into the container (the same
	 * principle as WCCP, class-wc-cp-cart.php, woocommerce_cart_shipping_packages
	 * filter). Priority 20: after Product Bundles (5), which has already
	 * aggregated the items of a bundle into the weight of the bundle.
	 *
	 * Same rule for a supply variation: its accessories, always packed with it,
	 * leave the parcel and their weight is added to its own. Its shipping class
	 * stays its own (clone: only the weight changes).
	 *
	 * @param array $packages Parcels.
	 * @return array
	 */
	public static function shipping_packages( $packages ) {
		foreach ( $packages as $package_key => $package ) {
			if ( empty( $package['contents'] ) ) {
				continue;
			}
			foreach ( $package['contents'] as $cart_key => $item ) {
				$children = self::group_children( $item );
				if ( empty( $children ) ) {
					continue;
				}

				$container_qty = max( 1, (int) $item['quantity'] );
				$added_weight  = 0.0;
				$moved         = false;
				$totals        = array(
					'line_subtotal'     => isset( $item['line_subtotal'] ) ? $item['line_subtotal'] : 0,
					'line_total'        => isset( $item['line_total'] ) ? $item['line_total'] : 0,
					'line_subtotal_tax' => isset( $item['line_subtotal_tax'] ) ? $item['line_subtotal_tax'] : 0,
					'line_tax'          => isset( $item['line_tax'] ) ? $item['line_tax'] : 0,
				);

				foreach ( $children as $child_key ) {
					if ( ! isset( $packages[ $package_key ]['contents'][ $child_key ] ) ) {
						continue;
					}
					$child = $packages[ $package_key ]['contents'][ $child_key ];
					if ( self::is_shipped_individually( $child ) || empty( $child['data'] ) ) {
						continue;
					}
					$added_weight += (float) $child['data']->get_weight() * (int) $child['quantity'];
					foreach ( array_keys( $totals ) as $total_key ) {
						$totals[ $total_key ] += isset( $child[ $total_key ] ) ? (float) $child[ $total_key ] : 0;
					}
					unset( $packages[ $package_key ]['contents'][ $child_key ] );
					$moved = true;
				}

				if ( ! $moved ) {
					continue;
				}

				$data = clone $item['data'];
				$data->set_weight( (float) $item['data']->get_weight() + $added_weight / $container_qty );

				$packages[ $package_key ]['contents'][ $cart_key ] = array_merge( $item, $totals, array( 'data' => $data ) );
			}
		}
		return $packages;
	}

	/**
	 * Unit weight of the parcel of a container (or of a supply variation), read
	 * from the computed parcels (the same source as WCCP for _composite_weight).
	 * null when no child is aggregated into it.
	 *
	 * @param string $container_key Cart key.
	 * @return float|null
	 */
	public static function get_container_package_weight( $container_key ) {
		if ( ! WC()->cart ) {
			return null;
		}
		$item = WC()->cart->get_cart_item( $container_key );
		if ( ! self::is_group_parent( $item ) || ! self::has_packaged_children( $item ) ) {
			return null;
		}
		foreach ( WC()->cart->get_shipping_packages() as $package ) {
			if ( isset( $package['contents'][ $container_key ]['data'] ) ) {
				return (float) $package['contents'][ $container_key ]['data']->get_weight( 'edit' );
			}
		}
		return null;
	}

	/**
	 * @param array $container Container item.
	 * @return bool
	 */
	private static function has_packaged_children( $container ) {
		foreach ( self::group_children( $container ) as $child_key ) {
			$child = WC()->cart->get_cart_item( $child_key );
			if ( $child && ! self::is_shipped_individually( $child ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Kit component “shipped individually” (setting of the kit). A supply accessory
	 * never is: it always leaves in the parcel of its variation. A flag left on an
	 * accessory (cart opened before that setting was removed) is ignored.
	 *
	 * @param array $item Cart item.
	 * @return bool
	 */
	public static function is_shipped_individually( $item ) {
		return ! empty( $item[ self::K_SHIPPED ] ) && ! self::is_supply_child( $item );
	}

	/*
	|--------------------------------------------------------------------------
	| Removal / restoration / quantities
	|--------------------------------------------------------------------------
	*/

	/**
	 * Deleting the container deletes the children; deleting a child (outside the
	 * interface, e.g. through the API) deletes the whole kit. Same rule for a
	 * variation and its accessories: the block cart (Store API) offers no way to
	 * hide “Remove” on an accessory, so removing it removes the variation and all
	 * of its accessories.
	 *
	 * @param string  $key  Key deleted.
	 * @param WC_Cart $cart Cart.
	 */
	public static function remove_linked_items( $key, $cart ) {
		if ( self::$removing ) {
			return;
		}
		$item = isset( $cart->cart_contents[ $key ] ) ? $cart->cart_contents[ $key ] : null;
		if ( ! $item ) {
			return;
		}

		self::$removing = true;
		if ( self::is_group_parent( $item ) ) {
			foreach ( self::group_children( $item ) as $child_key ) {
				if ( isset( $cart->cart_contents[ $child_key ] ) ) {
					$cart->remove_cart_item( $child_key );
				}
			}
		} elseif ( self::is_child( $item ) ) {
			$parent_key = $item[ self::K_PARENT ];
			if ( isset( $cart->cart_contents[ $parent_key ] ) ) {
				$parent = $cart->cart_contents[ $parent_key ];
				foreach ( self::group_children( $parent ) as $child_key ) {
					if ( $child_key !== $key && isset( $cart->cart_contents[ $child_key ] ) ) {
						$cart->remove_cart_item( $child_key );
					}
				}
				$cart->remove_cart_item( $parent_key );
			}
		}
		self::$removing = false;
	}

	/**
	 * “Undo” on the removal of a kit restores its children too.
	 *
	 * @param string  $key  Key restored.
	 * @param WC_Cart $cart Cart.
	 */
	public static function restore_children( $key, $cart ) {
		$item = isset( $cart->cart_contents[ $key ] ) ? $cart->cart_contents[ $key ] : null;
		if ( ! self::is_group_parent( $item ) ) {
			return;
		}
		// The product is reloaded on restoration: reapply the container
		// setup (not virtual, shipping class). No effect on a supply
		// variation (setup_cart_item() only touches a
		// container).
		$cart->cart_contents[ $key ] = self::setup_cart_item( $item, $key );

		foreach ( self::group_children( $item ) as $child_key ) {
			if ( isset( $cart->removed_cart_contents[ $child_key ] ) ) {
				$cart->restore_cart_item( $child_key );
			}
		}
	}

	/**
	 * Changing the quantity of a kit (or of a supply variation) scales its
	 * children.
	 *
	 * The only thing both carts have in common: WC_Cart::set_quantity() fires
	 * woocommerce_after_cart_item_quantity_update. The block cart goes through it
	 * too (Store API: CartUpdateItem → CartController::set_cart_item_quantity() →
	 * WC_Cart::set_quantity()), as does the merge of a second addition of the same
	 * variation (WC_Cart::add_to_cart() and CartController::add_to_cart()).
	 * enforce_child_quantities() remains the safety net when the totals are
	 * computed.
	 *
	 * @param string  $key      Key.
	 * @param int     $quantity New quantity.
	 * @param int     $old      Old quantity.
	 * @param WC_Cart $cart     Cart.
	 */
	public static function sync_children_quantity( $key, $quantity, $old, $cart ) {
		if ( self::$syncing ) {
			return;
		}
		$item = isset( $cart->cart_contents[ $key ] ) ? $cart->cart_contents[ $key ] : null;
		if ( ! self::is_group_parent( $item ) ) {
			return;
		}
		self::$syncing = true;
		foreach ( self::group_children( $item ) as $child_key ) {
			if ( isset( $cart->cart_contents[ $child_key ] ) ) {
				$unit = max( 1, (int) $cart->cart_contents[ $child_key ][ self::K_UNIT_QTY ] );
				$cart->set_quantity( $child_key, $unit * (int) $quantity, false );
			}
		}
		self::$syncing = false;
	}

	/*
	|--------------------------------------------------------------------------
	| Cart / mini-cart display
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param string $link Link.
	 * @param string $key  Key.
	 * @return string
	 */
	public static function child_remove_link( $link, $key ) {
		$item = WC()->cart ? WC()->cart->get_cart_item( $key ) : null;
		return self::is_child( $item ) ? '' : $link;
	}

	/**
	 * @param string $html Quantity field.
	 * @param string $key  Key.
	 * @param array  $item Item.
	 * @return string
	 */
	public static function child_quantity( $html, $key, $item = array() ) {
		if ( empty( $item ) && WC()->cart ) {
			$item = WC()->cart->get_cart_item( $key );
		}
		if ( ! self::is_child( $item ) ) {
			return $html;
		}
		// Supply accessory: quantity as text, without the class of a
		// kit component (like a bundle item, WC_PB_Display::cart_item_quantity()).
		if ( self::is_supply_child( $item ) ) {
			return esc_html( $item['quantity'] );
		}
		return '<span class="dahu-kit-child-qty">' . esc_html( $item['quantity'] ) . '</span>';
	}

	/**
	 * @param string $class Classes.
	 * @param array  $item  Item.
	 * @param string $key   Key.
	 * @return string
	 */
	public static function item_class( $class, $item, $key ) {
		if ( self::is_container( $item ) ) {
			$class .= ' dahu-kit-container';
		} elseif ( self::is_supply_parent( $item ) ) {
			$class .= ' dahu-supply-parent';
		} elseif ( self::is_supply_child( $item ) ) {
			// Not dahu-kit-child: an accessory is in no kit, even though it
			// carries the dahu_kit_parent child key (see the file header).
			$class .= ' dahu-supply-child';
		} elseif ( self::is_child( $item ) ) {
			$class .= ' dahu-kit-child';
		}
		return $class;
	}

	/**
	 * @param string $name Name.
	 * @param array  $item Item.
	 * @param string $key  Key.
	 * @return string
	 */
	public static function item_name( $name, $item, $key ) {
		if ( ! self::is_child( $item ) || ! WC()->cart ) {
			return $name;
		}
		$parent = WC()->cart->get_cart_item( $item[ self::K_PARENT ] );
		$kit    = $parent && isset( $parent['data'] ) && $parent['data'] instanceof WC_Product_Dahu_Kit ? $parent['data'] : null;
		$comp   = $kit ? $kit->get_kit_component( $item[ self::K_COMPONENT ] ) : null;
		if ( ! $comp ) {
			return $name;
		}
		return '<span class="dahu-kit-component-label">' . esc_html( $comp['title'] ) . ' : </span>' . $name;
	}

	/**
	 * Display total of a kit (children + billed bundle items).
	 *
	 * @param array $container Container item.
	 * @return float
	 */
	private static function container_display_total( $container ) {
		$total    = 0.0;
		$children = (array) $container[ self::K_CHILDREN ];
		$incl     = 'incl' === get_option( 'woocommerce_tax_display_cart' );

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$linked = in_array( $key, $children, true ) || ( isset( $item['bundled_by'] ) && in_array( $item['bundled_by'], $children, true ) );
			if ( ! $linked || empty( $item['data'] ) ) {
				continue;
			}
			$total += $incl
				? (float) wc_get_price_including_tax( $item['data'], array( 'qty' => $item['quantity'] ) )
				: (float) wc_get_price_excluding_tax( $item['data'], array( 'qty' => $item['quantity'] ) );
		}
		return $total;
	}

	/**
	 * @param string $html Price.
	 * @param array  $item Item.
	 * @param string $key  Key.
	 * @return string
	 */
	public static function container_price( $html, $item, $key ) {
		if ( ! self::is_container( $item ) || ! WC()->cart ) {
			return $html;
		}
		return wc_price( self::container_display_total( $item ) / max( 1, (int) $item['quantity'] ) );
	}

	/**
	 * @param string $html Subtotal.
	 * @param array  $item Item.
	 * @param string $key  Key.
	 * @return string
	 */
	public static function container_subtotal( $html, $item, $key ) {
		if ( ! self::is_container( $item ) || ! WC()->cart ) {
			return $html;
		}
		return wc_price( self::container_display_total( $item ) );
	}
}
