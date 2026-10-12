<?php
/**
 * Registration of the dahu_kit product type with WooCommerce.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Product_Type {

	public static function init() {
		add_filter( 'product_type_selector', array( __CLASS__, 'add_type_to_selector' ) );
		add_filter( 'woocommerce_product_class', array( __CLASS__, 'product_class' ), 10, 2 );
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_data_tabs' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'process_product_object' ), 20 );
	}

	/**
	 * @param array $types Types.
	 * @return array
	 */
	public static function add_type_to_selector( $types ) {
		$types[ DAHU_COMPOSITE_PRODUCT_TYPE ] = __( 'Configurable kit (Dahu)', 'dahu-composite' );
		return $types;
	}

	/**
	 * @param string $classname    Proposed class.
	 * @param string $product_type Type.
	 * @return string
	 */
	public static function product_class( $classname, $product_type ) {
		return DAHU_COMPOSITE_PRODUCT_TYPE === $product_type ? 'WC_Product_Dahu_Kit' : $classname;
	}

	/**
	 * Tabs of the “Product data” metabox visible for a kit: inventory (SKU) and
	 * shipping (own weight of the container, shipping class). The “General” tab
	 * shows no price: the price of a kit is computed from the components.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function product_data_tabs( $tabs ) {
		foreach ( array( 'inventory', 'shipping' ) as $key ) {
			if ( isset( $tabs[ $key ]['class'] ) ) {
				$tabs[ $key ]['class'][] = 'show_if_' . DAHU_COMPOSITE_PRODUCT_TYPE;
			}
		}
		return $tabs;
	}

	/**
	 * When a kit is saved:
	 *  - its own price forced to 0 (the container is never billed: its child
	 *    rows are the ones that carry the prices, as under WCCP);
	 *  - “Configurator” shipping class applied when none is set (dahu-post
	 *    relies on the class of the CONTAINER product).
	 *
	 * @param WC_Product $product Product.
	 */
	public static function process_product_object( $product ) {
		if ( ! $product instanceof WC_Product_Dahu_Kit ) {
			return;
		}

		$product->set_regular_price( 0 );
		$product->set_sale_price( '' );
		$product->set_price( 0 );
		$product->set_virtual( false );

		if ( ! $product->get_shipping_class_id() ) {
			$term_id = self::get_container_shipping_class_id();
			if ( $term_id ) {
				$product->set_shipping_class_id( $term_id );
			}
		}
	}

	/**
	 * ID of the shipping class of the kit containers (setting, default
	 * “choisir-son-kit”). 0 when the class does not exist.
	 *
	 * @return int
	 */
	public static function get_container_shipping_class_id() {
		$slug = (string) get_option( 'dahu_composite_container_shipping_class', 'choisir-son-kit' );
		$slug = (string) apply_filters( 'dahu_composite_container_shipping_class_slug', $slug );
		if ( '' === $slug ) {
			return 0;
		}
		$term = get_term_by( 'slug', $slug, 'product_shipping_class' );
		return $term ? (int) $term->term_id : 0;
	}
}
