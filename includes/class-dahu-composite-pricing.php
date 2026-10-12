<?php
/**
 * Resolution of the price of an option, server side, according to the current
 * role.
 *
 * Every “raw” amount is expressed in the WooCommerce price storage base
 * (excluding tax on a shop that stores prices excluding tax), exactly like
 * get_price(). The display conversion (including or excluding tax, following
 * the “Display prices in the shop” setting) is done by to_display().
 *
 * Role (Pro / Individual):
 *  - when Addify Role Based Pricing is active (AF_C_S_P_Price class) and the
 *    user is logged in, we apply the same rule as Addify's
 *    rbp_get_role_based_price(), already reproduced by dahu-pricing
 *    (class-dahu-pricing-admin-order-tax-fix.php, class-dahu-pricing-bundle-compat.php):
 *    the first role of the user that returns a price;
 *  - otherwise get_price(), filtered as usual.
 *
 * Why get_price() alone is not enough: Addify ignores the "bundle" product type
 * (a plain allow list of simple/variation/variable/grouped, see the header of
 * class-dahu-pricing-bundle-compat.php). get_price() of a battery sold as a
 * bundle would therefore return the public price even for a Pro. Going through
 * get_price_of_product() covers the fixed-price bundles.
 *
 * Extension point: the dahu_composite_option_unit_price filter.
 *
 * Imposed customer (set_customer()): in the order screen, the logged-in user is
 * the operator, not the customer. The price (and the tier) is then the one of
 * the customer of the order — same rule as above.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Pricing {

	/**
	 * Customer whose role is to be applied: null = logged-in user, false =
	 * visitor (no role), WP_User = that customer.
	 *
	 * @var WP_User|false|null
	 */
	private static $customer = null;

	/**
	 * Imposes the customer whose role sets the prices, for the current request
	 * (configuration of a kit in the order screen).
	 *
	 * @param WP_User|false|null $user Customer, false = visitor, null = restores the logged-in user.
	 */
	public static function set_customer( $user ) {
		self::$customer = ( $user instanceof WP_User || false === $user ) ? $user : null;
	}

	/**
	 * User whose role sets the prices, or null (visitor).
	 *
	 * @return WP_User|null
	 */
	private static function pricing_user() {
		if ( null !== self::$customer ) {
			return self::$customer ? self::$customer : null;
		}
		return is_user_logged_in() ? wp_get_current_user() : null;
	}

	/**
	 * Unit price (storage base), role aware.
	 *
	 * @param WC_Product $product  Product or variation.
	 * @param int        $quantity Quantity (Addify quantity price rules).
	 * @return float
	 */
	public static function get_raw_price( WC_Product $product, $quantity = 1 ) {
		$price = self::get_addify_role_price( $product, $quantity );

		if ( null === $price ) {
			$price = (float) $product->get_price();
		}

		return (float) apply_filters( 'dahu_composite_option_unit_price', $price, $product, $quantity );
	}

	/**
	 * Unit price of a CART row (storage base), role aware.
	 *
	 * The product of a cart item is not a “fresh” product: its price may have
	 * been rewritten by set_price() — by Addify (role price), or by this engine
	 * during an earlier computation in the same request. And a percentage Addify
	 * rule (percentage_decrease) is computed on the price the object carries:
	 * resolving it on the cart product applies the discount a second time (a bug:
	 * −40% on a Pro price already at −40%).
	 *
	 * The price is therefore resolved on a freshly read instance
	 * (wc_get_product()), never on the cart object: the same starting product as
	 * /quote and as the product page, and an identical result whatever the number
	 * of passes through calculate_totals().
	 *
	 * @param WC_Product $cart_product Product carried by the cart item.
	 * @param int        $quantity     Quantity of the row.
	 * @return float|null null when the product cannot be read again (price left as it is).
	 */
	public static function get_cart_item_price( WC_Product $cart_product, $quantity = 1 ) {
		$product = wc_get_product( $cart_product->get_id() );

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		return self::get_raw_price( $product, $quantity );
	}

	/**
	 * Public (regular) unit price, storage base. Used to show the public price
	 * next to the Pro price.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return float
	 */
	public static function get_raw_regular_price( WC_Product $product ) {
		$regular = $product->get_regular_price();
		if ( '' === $regular || null === $regular ) {
			$regular = $product->get_price();
		}
		return (float) apply_filters( 'dahu_composite_option_unit_regular_price', (float) $regular, $product );
	}

	/**
	 * DISPLAY amount, following the actual setting of the shop:
	 *  - taxes active and “Display prices in the shop” = including tax
	 *    (woocommerce_tax_display_shop = incl): conversion with the tax class of
	 *    the product (wc_get_price_including_tax);
	 *  - otherwise: amount excluding tax (wc_get_price_excluding_tax, which takes
	 *    the tax off when prices are entered including tax).
	 *
	 * DISPLAY ONLY. The cart always receives the storage-base price
	 * (get_raw_price(), excluding tax on this shop) and WooCommerce computes the
	 * tax itself: never use this amount to feed the cart.
	 *
	 * Computing the tax-inclusive amount requires a taxation context
	 * (WC()->customer): see ensure_tax_context(), called by every REST route.
	 *
	 * @param WC_Product $product  Product (for its tax class).
	 * @param float      $raw      Unit amount, storage base.
	 * @param int        $quantity Quantity.
	 * @return float
	 */
	public static function to_display( WC_Product $product, $raw, $quantity = 1 ) {
		$args = array(
			'price' => (float) $raw,
			'qty'   => max( 1, (int) $quantity ),
		);

		if ( wc_tax_enabled() && 'incl' === get_option( 'woocommerce_tax_display_shop' ) ) {
			return (float) wc_get_price_including_tax( $product, $args );
		}

		return (float) wc_get_price_excluding_tax( $product, $args );
	}

	/**
	 * Guarantees a taxation context for the display computations.
	 *
	 * In a REST request, WooCommerce initialises neither the session nor the
	 * customer (WC()->customer). Without a customer, WC_Tax::get_tax_location()
	 * returns no address — unless the tax is computed on the shop address — so no
	 * rate applies and a computed “tax-inclusive” amount equals the
	 * tax-exclusive one. That was the cause of the tax-exclusive prices returned
	 * by /schema and /quote. We therefore load session + customer + cart as on a
	 * shop page (the same taxation address as the rest of the site).
	 */
	public static function ensure_tax_context() {
		if ( WC()->customer instanceof WC_Customer ) {
			return;
		}
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
	}

	/**
	 * @param WC_Product $product Product.
	 * @param float      $raw     Amount, storage base.
	 * @param int        $qty     Quantity.
	 * @return array ['excl' => float, 'incl' => float]
	 */
	public static function tax_split( WC_Product $product, $raw, $qty = 1 ) {
		$args = array(
			'price' => (float) $raw,
			'qty'   => max( 1, (int) $qty ),
		);
		return array(
			'excl' => (float) wc_get_price_excluding_tax( $product, $args ),
			'incl' => (float) wc_get_price_including_tax( $product, $args ),
		);
	}

	/**
	 * Display amount of the TIER price (pro price).
	 *
	 * A tier may show its prices excluding tax even when the shop displays prices
	 * including tax: that is the case of the Gold tier (French pros), for whom the
	 * site shows “Gold Pro price (excl. tax)”. See get_tiers().
	 *
	 * @param WC_Product $product  Product.
	 * @param float      $raw      Amount, storage base.
	 * @param int        $quantity Quantity.
	 * @return float
	 */
	public static function display_tier_price( WC_Product $product, $raw, $quantity = 1 ) {
		$tier = self::get_current_tier();

		if ( $tier && ! empty( $tier['price_excluding_tax'] ) ) {
			return (float) wc_get_price_excluding_tax(
				$product,
				array(
					'price' => (float) $raw,
					'qty'   => max( 1, (int) $quantity ),
				)
			);
		}

		return self::to_display( $product, $raw, $quantity );
	}

	/**
	 * Price HTML.
	 *
	 * - Visitor / Individual: a single amount (or WooCommerce's native sale
	 *   format when the price is below the public price).
	 * - Label of the public price: two texts when the tier has a short label
	 *   (public_label_short), .dahu-kit-price__label-full and
	 *   .dahu-kit-price__label-short. The functional stylesheet shows the full one
	 *   everywhere and the short one in the summary (.dahu-kit__recap), where
	 *   space is short. Short label empty or identical: a single text, the earlier
	 *   markup.
	 * - Logged-in professional tier: public price and tier price, each preceded by
	 *   its label, rendered here server side — no recomputation script takes part
	 *   on those pages any more. Two <span>, not <del> / <ins>: the public price is
	 *   a recommended resale price, not a former price — <del> had the browser
	 *   strike it through and screen readers announce it as “deleted”. The classes
	 *   are unchanged.
	 *
	 * @param float $tier_display   Amount of the tier (or of the visitor).
	 * @param float $public_display Public amount.
	 * @return string
	 */
	public static function price_html( $tier_display, $public_display ) {
		$tier      = self::get_current_tier();
		$has_offer = $public_display > $tier_display + 0.005;

		if ( ! $tier ) {
			return $has_offer ? wc_format_sale_price( $public_display, $tier_display ) : wc_price( $tier_display );
		}

		$tier_amount = '<span class="dahu-kit-price__amount">' . wc_price( $tier_display ) . '</span>';
		$tier_label  = '' !== $tier['label'] ? '<span class="dahu-kit-price__label">' . esc_html( $tier['label'] ) . '</span> ' : '';
		$html        = '';

		if ( $has_offer ) {
			$short        = isset( $tier['public_label_short'] ) ? (string) $tier['public_label_short'] : '';
			$public_label = '';
			if ( '' !== $tier['public_label'] && '' !== $short && $short !== $tier['public_label'] ) {
				$public_label = '<span class="dahu-kit-price__label"><span class="dahu-kit-price__label-full">' . esc_html( $tier['public_label'] ) . '</span>'
					. '<span class="dahu-kit-price__label-short">' . esc_html( $short ) . '</span></span> ';
			} elseif ( '' !== $tier['public_label'] ) {
				$public_label = '<span class="dahu-kit-price__label">' . esc_html( $tier['public_label'] ) . '</span> ';
			}
			$html        .= '<span class="dahu-kit-price__public">' . $public_label
				. '<span class="dahu-kit-price__amount">' . wc_price( $public_display ) . '</span></span> ';
		}

		$html .= '<span class="dahu-kit-price__tier">' . $tier_label . $tier_amount . '</span>';

		return $html;
	}

	/**
	 * Price tiers: roles concerned, labels, and whether the tier price is shown
	 * excluding tax.
	 *
	 * Default values taken from the behaviour of the site (see
	 * RESUME_TECHNIQUE.md §2.6): the Gold tier groups French professionals, to
	 * whom the site shows the pro price excluding tax next to a recommended resale
	 * price including tax. The Silver tier groups professionals outside France,
	 * for whom WooCommerce already computes no French VAT: no conversion to
	 * apply.
	 *
	 * @return array[] Keyed by tier key.
	 */
	public static function get_tiers() {
		$defaults = array(
			'or'     => array(
				'roles'               => array( 'Professionnel_Or' ),
				'label'               => __( 'Gold trade price', 'dahu-composite' ),
				'public_label'        => __( 'Suggested resale price, incl. tax', 'dahu-composite' ),
				// Short label: summary only.
				'public_label_short'  => __( 'Resale price, incl. tax', 'dahu-composite' ),
				'price_excluding_tax' => true,
			),
			'argent' => array(
				'roles'               => array( 'Professionnel_Argent' ),
				'label'               => __( 'Silver trade price', 'dahu-composite' ),
				'public_label'        => __( 'Suggested resale price', 'dahu-composite' ),
				'public_label_short'  => __( 'Resale price', 'dahu-composite' ),
				'price_excluding_tax' => false,
			),
		);

		// The constant is not referenced here: the settings class is only loaded
		// in the admin, while the prices are computed on the front end.
		$saved = get_option( 'dahu_composite_price_tiers', array() );
		$tiers = array();

		// The tiers added by the filter are kept from one load to the next:
		// their saved values must be read back as well.
		foreach ( array_keys( (array) $saved ) as $saved_key ) {
			if ( ! isset( $defaults[ $saved_key ] ) ) {
				$defaults[ $saved_key ] = array(
					'roles'               => array(),
					'label'               => '',
					'public_label'        => '',
					'public_label_short'  => '',
					'price_excluding_tax' => false,
				);
			}
		}

		foreach ( $defaults as $key => $tier ) {
			$stored = isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array();

			$label        = isset( $stored['label'] ) ? sanitize_text_field( (string) $stored['label'] ) : $tier['label'];
			$public_label = isset( $stored['public_label'] ) ? sanitize_text_field( (string) $stored['public_label'] ) : $tier['public_label'];

			// Labels driven by dahu-pricing when it is active:
			// a single source for both plugins.
			$shared = self::get_shared_labels( $key );
			if ( $shared ) {
				$label        = $shared['label'];
				$public_label = $shared['public_label'];
			}

			// Short label. Setting saved before the key existed: that of the
			// default tier, but only when the label of the public price
			// has stayed the default one — a custom label has no short
			// version until one is entered.
			$default_short = isset( $tier['public_label_short'] ) ? $tier['public_label_short'] : '';
			if ( $public_label !== $tier['public_label'] ) {
				$default_short = '';
			}

			$tiers[ $key ] = array(
				'key'                 => $key,
				'roles'               => isset( $stored['roles'] ) ? array_filter( array_map( 'sanitize_text_field', (array) $stored['roles'] ) ) : $tier['roles'],
				'label'               => $label,
				'public_label'        => $public_label,
				'public_label_short'  => isset( $stored['public_label_short'] ) ? sanitize_text_field( (string) $stored['public_label_short'] ) : $default_short,
				'price_excluding_tax' => isset( $stored['price_excluding_tax'] ) ? (bool) $stored['price_excluding_tax'] : $tier['price_excluding_tax'],
			);
		}

		return apply_filters( 'dahu_composite_price_tiers', $tiers );
	}

	/**
	 * Labels of a tier supplied by dahu-pricing, or null.
	 *
	 * Source: the “Labels shown in front of the prices” setting of dahu-pricing
	 * (WooCommerce › Pricing), read through its public function
	 * dahu_pricing_get_price_labels( $tier ) — never by reading its option
	 * directly. The key of the tier is the same on both sides (gold, silver).
	 *
	 * null when dahu-pricing is absent, too old or does not know that tier: the
	 * labels of dahu-composite then apply.
	 *
	 * The trailing separator entered in dahu-pricing (“Gold Pro price (excl.
	 * tax):”) is stripped: here the label and the amount are two separate
	 * elements, held apart by the markup.
	 *
	 * @param string $key Key of the tier.
	 * @return array|null ['label' => string, 'public_label' => string]
	 */
	public static function get_shared_labels( $key ) {
		if ( ! function_exists( 'dahu_pricing_get_price_labels' ) ) {
			return null;
		}

		$labels = dahu_pricing_get_price_labels( (string) $key );
		if ( ! is_array( $labels ) ) {
			return null;
		}

		$clean = static function ( $text ) {
			// Trailing colon, spaces and non-breaking spaces.
			return (string) preg_replace( '/[\s:\x{00A0}\x{202F}]+$/u', '', sanitize_text_field( (string) $text ) );
		};

		return array(
			'label'        => $clean( isset( $labels['pro'] ) ? $labels['pro'] : '' ),
			'public_label' => $clean( isset( $labels['client'] ) ? $labels['client'] : '' ),
		);
	}

	/**
	 * Tier of the current user, or null (visitor / individual).
	 *
	 * @return array|null
	 */
	public static function get_current_tier() {
		$user = self::pricing_user();
		if ( ! $user ) {
			return null;
		}

		$roles = (array) $user->roles;

		foreach ( self::get_tiers() as $tier ) {
			if ( array_intersect( $roles, $tier['roles'] ) ) {
				return $tier;
			}
		}

		return null;
	}

	/**
	 * Price by role through Addify, or null when it does not apply.
	 *
	 * @param WC_Product $product  Product.
	 * @param int        $quantity Quantity.
	 * @return float|null
	 */
	private static function get_addify_role_price( WC_Product $product, $quantity ) {
		$user = self::pricing_user();
		if ( ! class_exists( 'AF_C_S_P_Price' ) || ! $user ) {
			return null;
		}

		$af_price = new AF_C_S_P_Price();
		if ( ! method_exists( $af_price, 'get_price_of_product' ) ) {
			return null;
		}

		foreach ( (array) $user->roles as $role ) {
			$candidate = $af_price->get_price_of_product( $product, $user, $role, max( 1, (int) $quantity ) );
			$candidate = ! is_bool( $candidate ) ? floatval( $candidate ) : 0;
			if ( ! empty( $candidate ) ) {
				return $candidate;
			}
		}

		return null;
	}
}
