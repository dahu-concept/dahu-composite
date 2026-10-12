<?php
/**
 * Plugin Name: Dahu Composite
 * Plugin URI:  https://dahu-concept.fr
 * Description: Server-driven configurable product kits for WooCommerce. Structure, scenarios, validation, pricing and weight are computed server side and exposed through a REST API, so the configurator stays fast and the cart always matches what was validated.
 * Version:     0.73.0
 * Author:      Hugo Vial-Jaime
 * Author URI:  mailto:contact@dahu-concept.fr
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: dahu-composite
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 *
 * @package DahuComposite
 */

defined( 'ABSPATH' ) || exit;

define( 'DAHU_COMPOSITE_VERSION', '0.73.0' );
define( 'DAHU_COMPOSITE_FILE', __FILE__ );
define( 'DAHU_COMPOSITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'DAHU_COMPOSITE_URL', plugin_dir_url( __FILE__ ) );
define( 'DAHU_COMPOSITE_BASENAME', plugin_basename( __FILE__ ) );

/** WooCommerce product type of the engine. */
define( 'DAHU_COMPOSITE_PRODUCT_TYPE', 'dahu_kit' );

/** Slug of the settings screen (WooCommerce submenu). */
define( 'DAHU_COMPOSITE_SETTINGS_SLUG', 'dahu-composite-settings' );

/* Translations. */
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'dahu-composite', false, dirname( DAHU_COMPOSITE_BASENAME ) . '/languages' );
	}
);

/*
 * HPOS compatibility (WooCommerce order tables): the engine only reaches
 * orders through the WC_Order / WC_Order_Item API, never in direct SQL.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>'
						. esc_html__( 'Dahu Composite requires WooCommerce.', 'dahu-composite' )
						. '</p></div>';
				}
			);
			return;
		}

		require_once DAHU_COMPOSITE_DIR . 'includes/class-wc-product-dahu-kit.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-kit-config.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-appearance.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-scenarios.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-customer-access.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-pricing.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-engine.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-product-type.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-composition.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-cart.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-order.php';
		// Supply of a variation: mandatory accessories of a variable product.
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-supply.php';
		// “What is included”: a Product Bundles bundle and a supply answer the
		// same calls (contract: RESUME_TECHNIQUE.md, §2 bis).
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-included.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/dahu-composite-functions.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-validator.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-product-tabs.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-rest.php';
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-frontend.php';
		// Saved configurations, My account › My configurations.
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-saved-configs.php';
		// Settings: read outside the admin too (“None” card of the schema).
		require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-settings.php';

		Dahu_Composite_Product_Type::init();
		Dahu_Composite_Cart::init();
		Dahu_Composite_Order::init();
		Dahu_Composite_Supply::init();
		Dahu_Composite_Included::init();
		Dahu_Composite_Rest::init();
		Dahu_Composite_Frontend::init();
		Dahu_Composite_Saved_Configs::init();
		// Data migrations, once per site.
		add_action( 'init', array( 'Dahu_Composite_Kit_Config', 'maybe_migrate' ), 20 );

		if ( is_admin() ) {
			require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-admin.php';
			Dahu_Composite_Admin::init();
			Dahu_Composite_Settings::init();
			// Assembled kit in the order screen.
			require_once DAHU_COMPOSITE_DIR . 'includes/class-dahu-composite-admin-order.php';
			Dahu_Composite_Admin_Order::init();
		}
	},
	20
);

/*
 * Plugins page — “Settings” first, before “Deactivate”. No version here: it
 * only appears in the meta row below.
 */
add_filter(
	'plugin_action_links_' . DAHU_COMPOSITE_BASENAME,
	static function ( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=' . DAHU_COMPOSITE_SETTINGS_SLUG ) ) . '">'
			. esc_html__( 'Settings', 'dahu-composite' ) . '</a>';

		array_unshift( $links, $settings );

		return $links;
	}
);

/*
 * Plugins page — meta row under the plugin name. The only place the version
 * appears, read from the file header (never hard-coded).
 */
add_filter(
	'plugin_row_meta',
	static function ( $links, $file ) {
		if ( DAHU_COMPOSITE_BASENAME === $file ) {
			$header = get_file_data( DAHU_COMPOSITE_FILE, array( 'Version' => 'Version' ) );
			$links  = array(
				sprintf( /* translators: %s: plugin version number. */ esc_html__( 'Version %s', 'dahu-composite' ), esc_html( $header['Version'] ) ),
				sprintf(
					'<a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( 'https://dahu-concept.fr' ),
					esc_html__( 'By Hugo Vial-Jaime — Dahu-Concept', 'dahu-composite' )
				),
				sprintf(
					'<a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( 'https://dahu-concept.fr' ),
					esc_html__( 'Plugin website', 'dahu-composite' )
				),
			);
		}
		return $links;
	},
	10,
	2
);
