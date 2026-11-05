<?php
/**
 * Registration of the ubac_kit product type with WooCommerce.
 *
 * @package Ubac_Kits
 */

defined( 'ABSPATH' ) || exit;

class Ubac_Kits_Product_Type {

	/** Option holding the term id, so the check below costs no query. */
	const TERM_FLAG = 'ubac_kits_product_type_term';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'ensure_term' ) );
		add_filter( 'product_type_selector', array( __CLASS__, 'add_type_to_selector' ) );
		add_filter( 'woocommerce_product_class', array( __CLASS__, 'product_class' ), 10, 2 );
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_data_tabs' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'process_product_object' ), 20 );
	}

	/**
	 * Makes sure the product_type term exists.
	 *
	 * POURQUOI CECI EXISTE. Ajouter un type de produit au selecteur ne suffit
	 * pas : WooCommerce range le type d'un produit dans la taxonomie
	 * `product_type`, et il n'y insere QUE ses propres types, au moment de sa
	 * propre installation. Une boutique qui installe WooCommerce puis cette
	 * extension -- l'ordre habituel -- n'a donc jamais le terme, et un produit
	 * enregistre en « produit compose » retombe en produit simple sans rien
	 * dire. C'est a l'extension qui ajoute le type de creer son terme.
	 *
	 * Trouve le 5 novembre : le banc portait un terme herite d'une
	 * installation anterieure, ce qui masquait le defaut. Le renommage interne
	 * a change le nom du type, le terme n'a pas suivi, et la suite composite
	 * est tombee en erreur fatale -- sur le bon motif, pour une fois.
	 *
	 * L'identifiant du terme est garde en option autochargee : le controle ne
	 * coute donc aucune requete tant qu'il est en place.
	 *
	 * @return void
	 */
	public static function ensure_term() {

		$connu = (int) get_option( self::TERM_FLAG, 0 );

		if ( $connu > 0 && term_exists( $connu, 'product_type' ) ) {
			return;
		}

		$terme = term_exists( UBAC_KITS_PRODUCT_TYPE, 'product_type' );

		if ( ! $terme ) {
			$terme = wp_insert_term( UBAC_KITS_PRODUCT_TYPE, 'product_type' );
		}

		if ( is_wp_error( $terme ) || ! isset( $terme['term_id'] ) ) {
			return;
		}

		update_option( self::TERM_FLAG, (int) $terme['term_id'], true );
	}

	/**
	 * @param array $types Types.
	 * @return array
	 */
	public static function add_type_to_selector( $types ) {
		$types[ UBAC_KITS_PRODUCT_TYPE ] = __( 'Composite product', 'ubac-kits' );
		return $types;
	}

	/**
	 * @param string $classname    Proposed class.
	 * @param string $product_type Type.
	 * @return string
	 */
	public static function product_class( $classname, $product_type ) {
		return UBAC_KITS_PRODUCT_TYPE === $product_type ? 'WC_Product_Ubac_Kit' : $classname;
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
				$tabs[ $key ]['class'][] = 'show_if_' . UBAC_KITS_PRODUCT_TYPE;
			}
		}
		return $tabs;
	}

	/**
	 * When a kit is saved:
	 *  - its own price forced to 0 (the container is never billed: its child
	 *    rows are the ones that carry the prices, as under WCCP);
	 *  - “Configurator” shipping class applied when none is set (ubac-post
	 *    relies on the class of the CONTAINER product).
	 *
	 * @param WC_Product $product Product.
	 */
	public static function process_product_object( $product ) {
		if ( ! $product instanceof WC_Product_Ubac_Kit ) {
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
	 * Slug of the shipping class of the kit containers, as the setting holds it.
	 *
	 * Empty by default, and an empty slug is a deliberate answer: a shop that
	 * names no class wants its containers left alone. No slug is assumed here,
	 * because a slug only means something inside one shop's own taxonomy.
	 *
	 * @return string
	 */
	public static function get_container_shipping_class_slug() {
		$slug = (string) get_option( 'ubac_kits_container_shipping_class', '' );
		return (string) apply_filters( 'ubac_kits_container_shipping_class_slug', $slug );
	}

	/**
	 * ID of the shipping class of the kit containers. 0 when no class is named
	 * by the setting, and 0 when the one it names does not exist.
	 *
	 * @return int
	 */
	public static function get_container_shipping_class_id() {
		$slug = self::get_container_shipping_class_slug();
		if ( '' === $slug ) {
			return 0;
		}
		$term = get_term_by( 'slug', $slug, 'product_shipping_class' );
		return $term ? (int) $term->term_id : 0;
	}
}
