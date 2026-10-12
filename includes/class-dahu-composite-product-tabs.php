<?php
/**
 * Detailed content of a product for the configurator's “More info” drawer:
 * Description and Specifications. “Installation & uses” and “Technology” stay
 * on the full product page, not in the drawer.
 *
 * Two sources, in this order:
 *
 *  1. the active theme, when it declares a function through the
 *     dahu_composite_product_tabs_callback( WC_Product, string[] ) filter.
 *     That function reads metas of its own and, for the description, calls
 *     the_content(): it therefore needs the global loop. We set $post /
 *     $product temporarily, then restore them — ordinary WordPress practice,
 *     with no side effect as long as the restore is done;
 *  2. otherwise, a generic fallback built on the native WooCommerce data
 *     (attribute table). The drawer is therefore never empty or broken when
 *     the theme is not active: dahu-composite stays distributable on its own.
 *
 * Exception: the Description tab comes from neither of the two sources. It is
 * always the SHORT description of the product (see short_description_tab()),
 * the long one staying reserved for the full product page.
 *
 * In both cases, the content comes from the REAL WooCommerce product. The
 * display_title / display_description fields are only display overlays of the
 * option card: they have no place here.
 *
 * Reviews are not carried over (not used on the site).
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Product_Tabs {

	/** Tabs requested, in display order. */
	const SLUGS = array( 'description', 'caracteristiques' );

	/**
	 * Name of the theme function rendering the detail tabs, when there is one.
	 *
	 * No theme function is assumed by default: the plugin renders the tabs
	 * itself. A theme that wants to render them returns its own function name
	 * from the filter below.
	 *
	 *     add_filter(
	 *         'dahu_composite_product_tabs_callback',
	 *         static function () { return 'my_theme_product_tabs'; }
	 *     );
	 *
	 * @return string|null
	 */
	public static function theme_callback() {
		/**
		 * Filters the function rendering the detail tabs.
		 *
		 * It must accept ( WC_Product $product, string[] $slugs ) and return
		 * slug => array( 'label', 'html' ). An unknown function name is
		 * ignored and the plugin's own rendering is used.
		 *
		 * @param string $callback Function name, empty for the built-in rendering.
		 */
		$callback = (string) apply_filters( 'dahu_composite_product_tabs_callback', '' );

		return ( '' !== $callback && function_exists( $callback ) ) ? $callback : null;
	}

	/**
	 * Tabs of a product.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array {
	 *   @type string  $source Source of the rendering: 'theme' or 'fallback'.
	 *   @type array[] $tabs   [ ['slug', 'label', 'html'] ], empty tabs excluded.
	 * }
	 */
	public static function get( WC_Product $product ) {
		// A variation has neither a long description nor metas of its own: we go
		// up to the parent product, which carries the page.
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$product = $parent;
			}
		}

		$callback = self::theme_callback();
		$tabs     = $callback ? self::from_theme( $callback, $product ) : array();
		$source   = 'theme';

		if ( ! $tabs ) {
			$tabs   = self::fallback( $product );
			$source = 'fallback';
		}

		// The Description tab of the drawer is ALWAYS the short description,
		// whatever the source: the long one belongs to the full product page,
		// too long for a choice drawer. The theme is therefore never asked
		// about this tab (see from_theme()).
		$short = self::short_description_tab( $product );
		if ( $short ) {
			array_unshift( $tabs, $short );
		}

		return array(
			'product_id' => (int) $product->get_id(),
			'name'       => $product->get_name(),
			'source'     => $source,
			'tabs'       => $tabs,
		);
	}

	/**
	 * Does the product have specifications to show? Same source as the drawer
	 * tab (theme, else WooCommerce attributes), without the short description.
	 * Called for every option of /schema: the result is kept for the request,
	 * and the `dahu_composite_option_has_specs` filter lets the theme answer
	 * faster when it can do so without producing the HTML.
	 *
	 * @param WC_Product $product Option product.
	 * @return bool
	 */
	public static function has_characteristics( WC_Product $product ) {
		static $cache = array();
		$id = (int) $product->get_id();
		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ];
		}

		/**
		 * Short circuit: a boolean returned here avoids producing the HTML of
		 * the specifications. null = default computation.
		 *
		 * @param bool|null  $has     Answer, or null.
		 * @param WC_Product $product Product.
		 */
		$has = apply_filters( 'dahu_composite_option_has_specs', null, $product );
		if ( null === $has ) {
			$callback = self::theme_callback();
			$tabs     = $callback ? self::from_theme( $callback, $product ) : array();
			if ( ! $tabs ) {
				$tabs = self::fallback( $product );
			}
			$has = false;
			foreach ( $tabs as $tab ) {
				if ( 'caracteristiques' === $tab['slug'] ) {
					$has = true;
				}
			}
		}

		$cache[ $id ] = (bool) $has;
		return $cache[ $id ];
	}

	/**
	 * Calls the theme function with the loop set on the requested product, then
	 * restores the global state.
	 *
	 * @param string     $callback Theme function.
	 * @param WC_Product $product  Product.
	 * @return array[]
	 */
	private static function from_theme( $callback, WC_Product $product ) {
		$post_object = get_post( $product->get_id() );
		if ( ! $post_object ) {
			return array();
		}

		$previous_post    = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$previous_product = isset( $GLOBALS['product'] ) ? $GLOBALS['product'] : null;

		$GLOBALS['post']    = $post_object; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['product'] = $product;     // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post_object );

		$raw = array();
		try {
			$raw = (array) call_user_func( $callback, $product, array_values( array_diff( self::SLUGS, array( 'description' ) ) ) );
		} catch ( Exception $e ) {
			$raw = array();
		}

		wp_reset_postdata();
		$GLOBALS['post']    = $previous_post;    // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['product'] = $previous_product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$tabs = array();
		foreach ( $raw as $slug => $tab ) {
			// The theme may return more than was asked for: only the tabs
			// kept for the drawer get through.
			if ( ! in_array( sanitize_title( (string) $slug ), self::SLUGS, true ) ) {
				continue;
			}
			if ( ! is_array( $tab ) || empty( $tab['html'] ) || '' === trim( wp_strip_all_tags( (string) $tab['html'] ) ) ) {
				continue;
			}
			$tabs[] = array(
				'slug'  => sanitize_title( (string) $slug ),
				'label' => isset( $tab['label'] ) ? (string) $tab['label'] : '',
				'html'  => (string) $tab['html'],
			);
		}

		return $tabs;
	}

	/**
	 * Description tab: SHORT description of the product, rendered the way
	 * WooCommerce renders it on a product page (woocommerce_short_description
	 * filter).
	 *
	 * @param WC_Product $product Product.
	 * @return array|null Tab, or null when the short description is empty.
	 */
	private static function short_description_tab( WC_Product $product ) {
		$short = (string) $product->get_short_description();
		if ( '' === trim( wp_strip_all_tags( $short ) ) ) {
			return null;
		}

		return array(
			'slug'  => 'description',
			'label' => __( 'Description', 'dahu-composite' ),
			'html'  => wp_kses_post( apply_filters( 'woocommerce_short_description', $short ) ),
		);
	}

	/**
	 * Fallback without a theme: WooCommerce attribute table. The description is
	 * added by get() (short description); the two other tabs have no native
	 * equivalent and are simply absent.
	 *
	 * @param WC_Product $product Product.
	 * @return array[]
	 */
	private static function fallback( WC_Product $product ) {
		$tabs = array();

		$attributes = self::attributes_html( $product );
		if ( '' !== $attributes ) {
			$tabs[] = array(
				'slug'  => 'caracteristiques',
				'label' => __( 'Specifications', 'dahu-composite' ),
				'html'  => $attributes,
			);
		}

		return $tabs;
	}

	/**
	 * Attribute table, through the native WooCommerce template when it is
	 * available.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private static function attributes_html( WC_Product $product ) {
		$attributes = array_filter(
			$product->get_attributes(),
			static function ( $attribute ) {
				return $attribute->get_visible();
			}
		);

		if ( ! $attributes || ! function_exists( 'wc_get_template_html' ) ) {
			return '';
		}

		return wc_get_template_html(
			'single-product/product-attributes.php',
			array(
				'product'          => $product,
				'display_dimensions' => $product->has_dimensions() || $product->has_weight(),
			)
		);
	}
}
