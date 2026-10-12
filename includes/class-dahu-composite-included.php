<?php
/**
 * “What is included”: a Product Bundles bundle (assembled product) and a
 * supply variation (Dahu_Composite_Supply) answer the SAME calls, under the
 * SAME names. Full contract, correspondence table included:
 * RESUME_TECHNIQUE.md, §2 bis.
 *
 * Principle: everywhere the site detects, counts, displays or exports a
 * bundle, a supply variation answers the same way. Where Product Bundles has
 * a name (field, CSS class, label), its own name is reused. The CSS rules are
 * NOT copied over: the rows receive Product Bundles' presentation classes, and
 * its own rules (already loaded) apply to both mechanisms.
 *
 * What this class does, on the customer side only (no cart or order row and no
 * meta is created or modified here):
 *  - cart count (classic badge, Store API items_count): accessories do not
 *    count towards it, just like the items of a bundle;
 *  - row classes: ONE convention for both mechanisms
 *    (dahu-included-parent / -child / -last), classic and block cart,
 *    mini-cart, order summary, order; plus, for a supply, Product Bundles'
 *    presentation classes;
 *  - Store API, “dahu_supply” namespace of the rows: same fields as Product
 *    Bundles' “bundles” namespace (bundled_items / bundle_data on the
 *    variation, bundled_by / bundled_item_data on the accessory); the script
 *    assets/js/dahu-composite-supply-blocks.js derives the row classes from it;
 *  - “Includes: <accessory> × <quantity>” on the variation, for the Store API
 *    only, like Product Bundles;
 *  - order item count under My account: without the accessories;
 *  - product page: list of the included items of every variation in the
 *    variation data (bundled_items key);
 *  - get_bundled_items(): included items of a product, bundle or variation, in
 *    the configurator's format.
 *
 * Every anomaly is logged (source “dahu-composite”) and the original value is
 * returned: the display never makes the journey fail.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Included {

	/** Store API namespace on the rows (counterpart of “bundles”). */
	const STORE_API_NAMESPACE = 'dahu_supply';

	/** WooCommerce log source. */
	const LOG_SOURCE = 'dahu-composite';

	/*
	 * “What is included” row-class convention, ONE for both mechanisms (bundle
	 * child and supply accessory alike, no distinction): classic and block
	 * cart, mini-cart, order summary, order (customer account, e-mails).
	 */

	/** Row that includes other rows (bundle, supply variation). */
	const CLASS_PARENT = 'dahu-included-parent';

	/** Included row (bundle item, accessory). */
	const CLASS_CHILD = 'dahu-included-child';

	/** Last included row of a parent. */
	const CLASS_LAST = 'dahu-included-last';

	public static function init() {
		// A single place for the classic badge AND the Store API
		// items_count: WC_Cart::get_cart_contents_count() applies this filter,
		// and CartSchema::get_item_response() reads get_cart_contents_count().
		// Same hook as WC_PB_Display::cart_contents_count().
		add_filter( 'woocommerce_cart_contents_count', array( __CLASS__, 'cart_contents_count' ) );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_item_count', array( __CLASS__, 'order_item_count' ), 10, 3 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'available_variation' ), 10, 3 );
		// Row classes (block cart: assets/js/dahu-composite-supply-blocks.js).
		add_filter( 'woocommerce_cart_item_class', array( __CLASS__, 'cart_item_class' ), 20, 3 );
		add_filter( 'woocommerce_mini_cart_item_class', array( __CLASS__, 'mini_cart_item_class' ), 20, 3 );
		add_filter( 'woocommerce_order_item_class', array( __CLASS__, 'order_item_class' ), 20, 3 );
		// An accessory's price and subtotal are hidden outside the block cart
		// too, like a bundle item not priced individually (WC_PB_Display::
		// cart_item_price(), cart_item_subtotal(), order_item_subtotal()).
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'hide_cart_amount' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'hide_cart_amount' ), 10, 3 );
		add_filter( 'woocommerce_checkout_item_subtotal', array( __CLASS__, 'hide_cart_amount' ), 10, 3 );
		add_filter( 'woocommerce_order_formatted_line_subtotal', array( __CLASS__, 'hide_order_subtotal' ), 10, 3 );

		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::extend_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'extend_store_api' ) );
		}
		// Same blocks as Product Bundles (WC_PB_Blocks_Compatibility).
		foreach ( array( 'cart', 'mini-cart', 'checkout' ) as $block ) {
			add_action( 'woocommerce_blocks_' . $block . '_block_registration', array( __CLASS__, 'register_blocks_integration' ) );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Included items of a product
	|--------------------------------------------------------------------------
	*/

	/**
	 * Included items of a product: Product Bundles bundle or supply variation.
	 * Empty list for any other product (a VARIABLE product includes nothing by
	 * itself: its variations each include their own).
	 *
	 * Format, first five keys identical to the “bundled” field of a
	 * configurator option (Dahu_Composite_Engine::schema_bundled()):
	 *   product_id   int        product shipped (for a variation: its parent)
	 *   name         string     displayed title (the bundle's own title if it has one)
	 *   quantity     int        per unit of the product
	 *   image        string|null thumbnail (woocommerce_gallery_thumbnail size)
	 *   optional     bool       optional item of a bundle (shipped when ticked)
	 *   variation_id int        variation shipped, 0 otherwise
	 *   visible      bool       shown on the product page by Product Bundles
	 *                           (the bundle's “Hide”); always true for a supply
	 *   source       string     'bundle' or 'supply'
	 *
	 * Supply: only the accessories the cart will actually add
	 * (Dahu_Composite_Supply::accessory_product()); an accessory that cannot be
	 * found or is not purchasable is not announced.
	 *
	 * @param WC_Product|int $product Product, variation or ID.
	 * @return array[]
	 */
	public static function get_bundled_items( $product ) {
		try {
			$product = $product instanceof WC_Product ? $product : wc_get_product( $product );
			if ( ! $product ) {
				return array();
			}
			if ( $product->is_type( 'bundle' ) ) {
				return self::bundle_items( $product );
			}
			if ( $product->is_type( 'variation' ) ) {
				return self::supply_items( $product );
			}
		} catch ( Throwable $e ) {
			self::log( 'Included items unreadable: ' . $e->getMessage() );
		}
		return array();
	}

	/**
	 * Items of a Product Bundles bundle, all of them (including those hidden on
	 * the product page: the charger shipped with a battery is still a product
	 * that is supplied). No prices.
	 *
	 * @param WC_Product $bundle Bundle.
	 * @return array[] See get_bundled_items().
	 */
	public static function bundle_items( WC_Product $bundle ) {
		if ( ! method_exists( $bundle, 'get_bundled_items' ) ) {
			return array();
		}
		$out = array();
		foreach ( $bundle->get_bundled_items() as $bundled_item ) {
			$item_product = $bundled_item->get_product();
			if ( ! $item_product ) {
				continue;
			}
			$title    = method_exists( $bundled_item, 'get_raw_title' ) ? (string) $bundled_item->get_raw_title( true ) : '';
			$image_id = (int) $item_product->get_image_id();
			$out[]    = array(
				'product_id'   => (int) $item_product->get_id(),
				'name'         => '' !== $title ? $title : $item_product->get_name(),
				'quantity'     => max( 1, (int) $bundled_item->get_quantity( 'default' ) ),
				'image'        => $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_gallery_thumbnail' ) : null,
				// Optional item of the bundle: shipped only when it is ticked.
				'optional'     => (bool) $bundled_item->is_optional(),
				'variation_id' => 0,
				'visible'      => method_exists( $bundled_item, 'is_visible' ) ? (bool) $bundled_item->is_visible( 'product' ) : true,
				'source'       => 'bundle',
			);
		}
		return $out;
	}

	/**
	 * Accessories of a supply variation.
	 *
	 * @param WC_Product $variation Variation.
	 * @return array[] See get_bundled_items().
	 */
	public static function supply_items( WC_Product $variation ) {
		$out = array();
		foreach ( Dahu_Composite_Supply::get_supply( $variation->get_id() ) as $entry ) {
			$item_product = Dahu_Composite_Supply::accessory_product( $entry );
			if ( ! $item_product ) {
				continue;
			}
			$image_id = (int) $item_product->get_image_id();
			$out[]    = array(
				'product_id'   => (int) $entry['product_id'],
				'name'         => $item_product->get_name(),
				'quantity'     => (int) $entry['quantity'],
				'image'        => $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_gallery_thumbnail' ) : null,
				'optional'     => false,
				'variation_id' => (int) $entry['variation_id'],
				'visible'      => true,
				'source'       => 'supply',
			);
		}
		return $out;
	}

	/**
	 * Page of a variable product: included items of every variation in its data
	 * (variation form, theme JS: variation.bundled_items). Key absent when the
	 * variation includes nothing.
	 *
	 * @param array      $data      Variation data.
	 * @param WC_Product $product   Variable product.
	 * @param WC_Product $variation Variation.
	 * @return array
	 */
	public static function available_variation( $data, $product, $variation ) {
		if ( ! is_array( $data ) || ! $variation instanceof WC_Product ) {
			return $data;
		}
		$items = self::get_bundled_items( $variation );
		if ( $items ) {
			$data['bundled_items'] = $items;
		}
		return $data;
	}

	/*
	|--------------------------------------------------------------------------
	| Cart
	|--------------------------------------------------------------------------
	*/

	/**
	 * Supply variation of an accessory in the cart, null when the row is not an
	 * accessory or when its variation is no longer in the cart. Matched by the
	 * group keys alone (never by product).
	 *
	 * @param array      $cart_item     Row.
	 * @param array|bool $cart_contents Cart contents (false: the current cart).
	 * @return array|null
	 */
	public static function cart_container( $cart_item, $cart_contents = false ) {
		if ( ! Dahu_Composite_Cart::is_supply_child( $cart_item ) ) {
			return null;
		}
		$cart_contents = self::cart_contents( $cart_contents );
		$parent_key    = $cart_item[ Dahu_Composite_Cart::K_PARENT ];
		$parent        = isset( $cart_contents[ $parent_key ] ) ? $cart_contents[ $parent_key ] : null;
		return Dahu_Composite_Cart::is_supply_parent( $parent ) ? $parent : null;
	}

	/**
	 * Accessories of a supply variation present in the cart, in the order they
	 * were added.
	 *
	 * @param array      $cart_item     Row of the variation.
	 * @param array|bool $cart_contents Cart contents (false: the current cart).
	 * @return array[] Rows, keyed by cart key.
	 */
	public static function cart_children( $cart_item, $cart_contents = false ) {
		$out = array();
		if ( ! Dahu_Composite_Cart::is_supply_parent( $cart_item ) ) {
			return $out;
		}
		$cart_contents = self::cart_contents( $cart_contents );
		foreach ( Dahu_Composite_Cart::group_children( $cart_item ) as $key ) {
			if ( isset( $cart_contents[ $key ] ) && Dahu_Composite_Cart::is_supply_child( $cart_contents[ $key ] ) ) {
				$out[ $key ] = $cart_contents[ $key ];
			}
		}
		return $out;
	}

	/**
	 * @param array|bool $cart_contents Supplied contents, or false.
	 * @return array
	 */
	private static function cart_contents( $cart_contents ) {
		if ( is_array( $cart_contents ) ) {
			return $cart_contents;
		}
		return function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart() : array();
	}

	/**
	 * Cart count without the accessories (always shown indented under their
	 * variation, like the items of a bundle in “Grouped” mode).
	 *
	 * @param int $count Count.
	 * @return int
	 */
	public static function cart_contents_count( $count ) {
		try {
			$cart_contents = self::cart_contents( false );
			$subtract      = 0;
			foreach ( $cart_contents as $cart_item ) {
				if ( self::cart_container( $cart_item, $cart_contents ) ) {
					$subtract += (int) $cart_item['quantity'];
				}
			}
			return $count - $subtract;
		} catch ( Throwable $e ) {
			self::log( 'Cart count: ' . $e->getMessage() );
			return $count;
		}
	}

	/**
	 * Classic cart: classes of the shared convention, plus, for a supply only,
	 * those Product Bundles sets itself on a bundle
	 * (WC_PB_Display::cart_item_class(): bundle_table_item,
	 * bundled_table_item) — it only sets them on its own rows.
	 *
	 * @param string $class     Classes.
	 * @param array  $cart_item Row.
	 * @param string $key       Cart key.
	 * @return string
	 */
	public static function cart_item_class( $class, $cart_item, $key ) {
		return self::add_cart_classes( $class, $cart_item, $key, true );
	}

	/**
	 * Classic mini-cart: classes of the shared convention only (Product Bundles
	 * sets none there).
	 *
	 * @param string $class     Classes.
	 * @param array  $cart_item Row.
	 * @param string $key       Cart key.
	 * @return string
	 */
	public static function mini_cart_item_class( $class, $cart_item, $key ) {
		return self::add_cart_classes( $class, $cart_item, $key, false );
	}

	/**
	 * @param string $class      Classes.
	 * @param array  $cart_item  Row.
	 * @param string $key        Cart key.
	 * @param bool   $pb_classes Add the Product Bundles classes of a supply.
	 * @return string
	 */
	private static function add_cart_classes( $class, $cart_item, $key, $pb_classes ) {
		try {
			if ( dahu_composite_is_bundle_container_cart_item( $cart_item ) ) {
				if ( dahu_composite_get_bundled_cart_items( $cart_item, false, true ) ) {
					$class .= ' ' . self::CLASS_PARENT;
					if ( $pb_classes && Dahu_Composite_Cart::is_supply_parent( $cart_item ) ) {
						$class .= ' bundle_table_item';
					}
				}
				return $class;
			}
			$container_key = dahu_composite_get_bundled_cart_item_container( $cart_item, false, true );
			if ( ! $container_key || ! WC()->cart ) {
				return $class;
			}
			$class   .= ' ' . self::CLASS_CHILD;
			$contents = WC()->cart->get_cart();
			$siblings = isset( $contents[ $container_key ] ) ? dahu_composite_get_bundled_cart_items( $contents[ $container_key ], false, true ) : array();
			if ( $siblings && end( $siblings ) === $key ) {
				$class .= ' ' . self::CLASS_LAST;
			}
			if ( $pb_classes && Dahu_Composite_Cart::is_supply_child( $cart_item ) ) {
				$class .= ' bundled_table_item';
			}
		} catch ( Throwable $e ) {
			self::log( 'Cart row classes: ' . $e->getMessage() );
		}
		return $class;
	}

	/**
	 * Order rows on the customer side (account, e-mails): classes of the shared
	 * convention, plus, for a supply, those of Product Bundles
	 * (WC_PB_Display::order_item_class()), including its e-mail styles.
	 *
	 * @param string        $class Classes.
	 * @param WC_Order_Item $item  Row.
	 * @param WC_Order      $order Order.
	 * @return string
	 */
	public static function order_item_class( $class, $item, $order ) {
		try {
			$order = $order instanceof WC_Order ? $order : false;
			if ( dahu_composite_is_bundle_container_order_item( $item ) ) {
				if ( dahu_composite_get_bundled_order_items( $item, $order, true ) ) {
					$class .= ' ' . self::CLASS_PARENT;
					if ( 'parent' === Dahu_Composite_Order::supply_role( $item ) ) {
						$class .= ' bundle_table_item';
					}
				}
				return $class;
			}
			$container = dahu_composite_get_bundled_order_item_container( $item, $order );
			if ( ! $container ) {
				return $class;
			}
			$class   .= ' ' . self::CLASS_CHILD;
			$siblings = dahu_composite_get_bundled_order_items( $container, $order, true );
			if ( $siblings && (int) end( $siblings ) === (int) $item->get_id() ) {
				$class .= ' ' . self::CLASS_LAST;
			}
			if ( 'child' === Dahu_Composite_Order::supply_role( $item ) ) {
				$class .= ' bundled_table_item';
				if ( $order && '' === (string) $order->get_formatted_line_subtotal( $item ) ) {
					$class .= ' no_line_subtotal';
				}
			}
		} catch ( Throwable $e ) {
			self::log( 'Order row classes: ' . $e->getMessage() );
		}
		return $class;
	}

	/**
	 * Classic cart and checkout, mini-cart: an accessory's price and subtotal
	 * are empty, like a bundle item not priced individually
	 * (WC_PB_Display::get_child_cart_item_price() / _subtotal()).
	 *
	 * @param string $html      Amount.
	 * @param array  $cart_item Row.
	 * @param string $key       Cart key.
	 * @return string
	 */
	public static function hide_cart_amount( $html, $cart_item, $key = '' ) {
		try {
			return self::cart_container( $cart_item ) ? '' : $html;
		} catch ( Throwable $e ) {
			self::log( 'Cart row amount: ' . $e->getMessage() );
			return $html;
		}
	}

	/**
	 * Order (customer account, e-mails, confirmation page): an accessory's
	 * subtotal is empty when its row is not priced individually and amounts to
	 * 0 — the same rule, read from the ROW, as WC_PB_Display::
	 * order_item_subtotal() (_bundled_item_priced_individually = no and
	 * subtotal 0; here _component_priced_individually = no).
	 *
	 * @param string        $subtotal Formatted subtotal.
	 * @param WC_Order_Item $item     Row.
	 * @param WC_Order      $order    Order.
	 * @return string
	 */
	public static function hide_order_subtotal( $subtotal, $item, $order = null ) {
		try {
			if ( 'child' !== Dahu_Composite_Order::supply_role( $item )
				|| 'no' !== $item->get_meta( '_component_priced_individually', true )
				|| 0.0 !== (float) $item->get_subtotal( 'edit' ) ) {
				return $subtotal;
			}
			return dahu_composite_get_bundled_order_item_container( $item, $order instanceof WC_Order ? $order : false ) ? '' : $subtotal;
		} catch ( Throwable $e ) {
			self::log( 'Order row subtotal: ' . $e->getMessage() );
			return $subtotal;
		}
	}

	/**
	 * “Includes: <accessory> × <quantity>” on the variation, one entry per
	 * accessory, Store API only: exactly what Product Bundles does for a bundle
	 * (WC_PB_Display::cart_item_data(), aggregated = false). The block cart
	 * hides it on the cart page (is-bundle__meta_hidden, where the accessories
	 * are indented rows) and shows it in the order summary (where the
	 * accessories are hidden).
	 *
	 * The accessory itself no longer gets “Supplied with”: a bundle item carries
	 * no such mention (“Part of” only outside “Grouped” mode).
	 *
	 * @param array $item_data Displayed data.
	 * @param array $cart_item Row.
	 * @return array
	 */
	public static function cart_item_data( $item_data, $cart_item ) {
		try {
			if ( ! Dahu_Composite_Cart::is_supply_parent( $cart_item ) || ! self::is_store_api_request() ) {
				return $item_data;
			}
			$children = self::cart_children( $cart_item );
			if ( ! $children ) {
				return $item_data;
			}
			$item_data = (array) $item_data;
			$label     = self::includes_label();
			foreach ( $children as $child ) {
				if ( empty( $child['data'] ) || ! $child['data'] instanceof WC_Product ) {
					continue;
				}
				$item_data[] = array(
					'key'   => $label,
					'value' => self::format_title( $child['data']->get_name(), $child['quantity'] ),
				);
			}
		} catch ( Throwable $e ) {
			self::log( '“Includes” mention: ' . $e->getMessage() );
		}
		return $item_data;
	}

	/**
	 * “Includes” label of the mention set on a supply variation.
	 *
	 * The label ends up inside the class name the block cart builds for the
	 * mention (wc-block-components-product-details__<label>), and Product
	 * Bundles styles that class for its own label. A site that wants the two
	 * mechanisms to look exactly alike therefore has to make the two labels
	 * match.
	 *
	 * That cannot be done by reading the other plugin's text domain here: a
	 * plugin may only load its own, borrowing one is refused in review, and it
	 * yields nothing at all when that plugin is absent. So the label is an
	 * ordinary string of this plugin, and this filter is what lets a site align
	 * it:
	 *
	 *     add_filter( 'dahu_composite_includes_label', 'my_theme_includes_label' );
	 *
	 * The callback returns the label the other plugin uses, asking that plugin
	 * for its own translation of it.
	 *
	 * @return string
	 */
	private static function includes_label() {

		/**
		 * Filters the “Includes” label of the mention.
		 *
		 * @param string $label Label.
		 */
		$label = apply_filters( 'dahu_composite_includes_label', __( 'Includes', 'dahu-composite' ) );

		return (string) $label;
	}

	/**
	 * “Name × quantity”, formatted the way Product Bundles does.
	 *
	 * @param string $name Name.
	 * @param int    $qty  Quantity.
	 * @return string
	 */
	private static function format_title( $name, $qty ) {
		if ( class_exists( 'WC_PB_Helpers' ) && is_callable( array( 'WC_PB_Helpers', 'format_product_shop_title' ) ) ) {
			return WC_PB_Helpers::format_product_shop_title( $name, $qty );
		}
		return sprintf( '%1$s &times; %2$s', $name, $qty );
	}

	/**
	 * Store API request, block-cart hydration included. Same detection as
	 * Product Bundles when it is active (it also follows the internal hydration
	 * request, which WC()->is_store_api_request() does not see).
	 *
	 * @return bool
	 */
	private static function is_store_api_request() {
		if ( class_exists( 'WC_PB_Core_Compatibility' ) && is_callable( array( 'WC_PB_Core_Compatibility', 'is_store_api_request' ) ) ) {
			return (bool) WC_PB_Core_Compatibility::is_store_api_request();
		}
		return function_exists( 'WC' ) && is_callable( array( WC(), 'is_store_api_request' ) ) && WC()->is_store_api_request();
	}

	/*
	|--------------------------------------------------------------------------
	| Store API and block cart
	|--------------------------------------------------------------------------
	*/

	/**
	 * “dahu_supply” namespace of the Store API rows.
	 */
	public static function extend_store_api() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		$endpoint = class_exists( 'Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema' )
			? \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER
			: 'cart-item';
		try {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => $endpoint,
					'namespace'       => self::STORE_API_NAMESPACE,
					'data_callback'   => array( __CLASS__, 'store_api_cart_item_data' ),
					'schema_callback' => array( __CLASS__, 'store_api_cart_item_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		} catch ( Throwable $e ) {
			self::log( 'Store API extension not registered: ' . $e->getMessage() );
		}
	}

	/**
	 * Data of a row: same fields, same flags as Product Bundles' “bundles”
	 * namespace (WC_PB_Store_API::extend_cart_item_data()), with the values of a
	 * bundle in “Grouped” mode whose items are not priced individually. No
	 * bundled_item_id: an accessory has no bundle-item identifier (no
	 * is-bundled__iid_* class).
	 *
	 * @param array $cart_item Row.
	 * @return array
	 */
	public static function store_api_cart_item_data( $cart_item ) {
		try {
			if ( Dahu_Composite_Cart::is_supply_parent( $cart_item ) ) {
				$children = self::cart_children( $cart_item );
				if ( ! $children ) {
					return array();
				}
				return array(
					'bundled_items' => array_keys( $children ),
					'bundle_data'   => array(
						'is_editable'               => false,
						'is_price_hidden'           => false,
						'is_subtotal_hidden'        => false,
						'is_hidden'                 => false,
						// Cart page: accessories as indented rows, “Includes”
						// hidden. Order summary: “Includes” shown, accessories hidden.
						'is_meta_hidden_in_cart'    => true,
						'is_meta_hidden_in_summary' => false,
					),
				);
			}

			$container = self::cart_container( $cart_item );
			if ( $container ) {
				$siblings = array_keys( self::cart_children( $container ) );
				return array(
					'bundled_by'        => $cart_item[ Dahu_Composite_Cart::K_PARENT ],
					'bundled_item_data' => array(
						// Identifier of the parent row in the Store API (id of the
						// variation): is-bundled__cid_X = is-bundle__cid_X of the parent.
						'bundle_id'              => isset( $container['data'] ) && $container['data'] instanceof WC_Product ? (int) $container['data']->get_id() : 0,
						// Removing an accessory would remove the whole variation:
						// link hidden, as for a mandatory item of a bundle.
						'is_removable'           => false,
						'is_indented'            => true,
						// The subtotal of the variation is the subtotal of the group
						// (accessory at 0).
						'is_subtotal_aggregated' => true,
						'is_parent_visible'      => true,
						'is_last'                => isset( $cart_item['key'] ) && end( $siblings ) === $cart_item['key'],
						// Decision: the accessory's price and subtotal are hidden.
						'is_price_hidden'        => true,
						'is_subtotal_hidden'     => true,
						'is_thumbnail_hidden'    => false,
						'is_hidden_in_cart'      => false,
						'is_hidden_in_summary'   => true,
					),
				);
			}
		} catch ( Throwable $e ) {
			self::log( 'Store API data of a row: ' . $e->getMessage() );
		}
		return array();
	}

	/**
	 * Schema: that of Product Bundles (WC_PB_Store_API::extend_cart_item_schema()).
	 *
	 * @return array
	 */
	public static function store_api_cart_item_schema() {
		return array(
			'bundled_by'        => array(
				'description' => __( 'Cart key of the variation supplying this accessory.', 'dahu-composite' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'bundled_items'     => array(
				'description' => __( 'Cart keys of the accessories supplied with this variation.', 'dahu-composite' ),
				'type'        => array( 'array', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'bundle_data'       => array(
				'description' => __( 'Variation display (bundle plugin fields).', 'dahu-composite' ),
				'type'        => array( 'object', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'bundled_item_data' => array(
				'description' => __( 'Accessory display (bundle plugin fields).', 'dahu-composite' ),
				'type'        => array( 'object', 'null' ),
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Script for the row classes (cart, mini-cart, checkout).
	 *
	 * @param object $registry IntegrationRegistry of the block.
	 */
	public static function register_blocks_integration( $registry ) {
		try {
			if ( ! interface_exists( 'Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface' ) || ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
				return;
			}
			require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-blocks-integration.php';
			if ( method_exists( $registry, 'is_registered' ) && $registry->is_registered( Dahu_Composite_Blocks_Integration::NAME ) ) {
				return;
			}
			$registry->register( new Dahu_Composite_Blocks_Integration() );
		} catch ( Throwable $e ) {
			self::log( 'Cart blocks integration not registered: ' . $e->getMessage() );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Orders
	|--------------------------------------------------------------------------
	*/

	/**
	 * My account: item count of an order without the accessories, like Product
	 * Bundles (WC_PB_Display::order_item_count(), My account page only).
	 *
	 * @param int      $count Count.
	 * @param string   $type  Row type.
	 * @param WC_Order $order Order.
	 * @return int
	 */
	public static function order_item_count( $count, $type, $order ) {
		try {
			if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || ! $order instanceof WC_Order ) {
				return $count;
			}
			$subtract = 0;
			foreach ( $order->get_items() as $item ) {
				if ( 'parent' !== Dahu_Composite_Order::supply_role( $item ) ) {
					continue;
				}
				foreach ( Dahu_Composite_Order::supply_children( $order, $item ) as $child ) {
					$subtract += (int) $child->get_quantity();
				}
			}
			return $count - $subtract;
		} catch ( Throwable $e ) {
			self::log( 'Order item count: ' . $e->getMessage() );
			return $count;
		}
	}

	/**
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => self::LOG_SOURCE ) );
		}
	}
}
