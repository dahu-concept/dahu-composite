<?php
/**
 * Plugin Name: Ubac Composite Products
 * Description: Server-driven configurable product kits for WooCommerce. Structure, scenarios, validation, pricing and weight are computed server side and exposed through a REST API, so the configurator stays fast and the cart always matches what was validated.
 * Version:     0.78.1
 * Author:      Hugo Vial-Jaime
 * Author URI:  https://dahu-concept.fr
 * License:     MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: ubac-kits
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   11.2
 *
 * @package DahuComposite
 */

defined( 'ABSPATH' ) || exit;

define( 'DAHU_COMPOSITE_VERSION', '0.78.1' );
define( 'DAHU_COMPOSITE_FILE', __FILE__ );
define( 'DAHU_COMPOSITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'DAHU_COMPOSITE_URL', plugin_dir_url( __FILE__ ) );
define( 'DAHU_COMPOSITE_BASENAME', plugin_basename( __FILE__ ) );

/** WooCommerce product type of the engine. */
define( 'DAHU_COMPOSITE_PRODUCT_TYPE', 'dahu_kit' );

/** Slug of the settings screen (WooCommerce submenu). */
define( 'DAHU_COMPOSITE_SETTINGS_SLUG', 'dahu-composite-settings' );

/* -------------------------------------------------------------------------
 * Translations
 *
 * There is deliberately no load_plugin_textdomain() call here. Since
 * WordPress 4.6 the translations of a plugin hosted on WordPress.org are
 * loaded on demand, and the directory's review asks that the call be removed
 * rather than kept: this plugin requires 6.0, so there is no older WordPress
 * to cover. Translations come from translate.wordpress.org.
 * ---------------------------------------------------------------------- */

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
						. esc_html__( 'Dahu Composite requires WooCommerce.', 'ubac-kits' )
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
			. esc_html__( 'Settings', 'ubac-kits' ) . '</a>';

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
				sprintf( /* translators: %s: plugin version number. */ esc_html__( 'Version %s', 'ubac-kits' ), esc_html( $header['Version'] ) ),
				sprintf(
					'<a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( 'https://dahu-concept.fr' ),
					esc_html__( 'By Hugo Vial-Jaime — Dahu-Concept', 'ubac-kits' )
				),
				sprintf(
					'<a href="%s" target="_blank" rel="noopener">%s</a>',
					esc_url( 'https://dahu-concept.fr' ),
					esc_html__( 'Plugin website', 'ubac-kits' )
				),
			);
		}
		return $links;
	},
	10,
	2
);

/*
 * The welcome screen, shown once just after activation.
 *
 * Registered here rather than inside the admin include, because the
 * activation flag has to be set whatever else is loaded.
 */
require_once DAHU_COMPOSITE_DIR . 'includes/dahu-welcome.php';

/*
 * The flag, not a redirect: an activation hook that redirects breaks bulk
 * activation and WP-CLI, which activate several plugins in one request. The
 * welcome screen reads the flag on the next page load, where a redirect is
 * safe, and clears it when several plugins were activated together.
 */
register_activation_hook(
	__FILE__,
	static function () {
		Dahu_Welcome::mark_activated( 'dahu-composite' );
	}
);

add_action(
	'init',
	static function () {
		Dahu_Welcome::register(
			array(
				'slug'       => 'dahu-composite',
				'name'       => __( 'Composite products', 'ubac-kits' ),
				'tagline'    => __( 'Products built from parts the customer chooses.', 'ubac-kits' ),
				'file'       => DAHU_COMPOSITE_FILE,
				'version'    => DAHU_COMPOSITE_VERSION,
				'textdomain' => 'dahu-composite',
				'mark'       => array(
					'view'  => '0 0 112 112',
					'paths' => array(
						'M12.0 28.0 H46.0 A6.0 6.0 0 0 1 52.0 34.0 V68.0 A6.0 6.0 0 0 1 46.0 74.0 H12.0 A6.0 6.0 0 0 1 6.0 68.0 V34.0 A6.0 6.0 0 0 1 12.0 28.0 Z',
						'M60 74 L60 92 Q60 98 66 98 L100 98 Q106 98 106 92 L106 58 Q106 52 100 52 L84 52',
						'M30 28 L30 74 M6 51 L52 51',
						'M76.0 10.0 H102.0 A6.0 6.0 0 0 1 108.0 16.0 V42.0 A6.0 6.0 0 0 1 102.0 48.0 H76.0 A6.0 6.0 0 0 1 70.0 42.0 V16.0 A6.0 6.0 0 0 1 76.0 10.0 Z',
					),
				),
				/*
				 * The welcome screen's words come from here: the shared file is
				 * copied byte for byte across the whole family, so it can carry
				 * no text domain of its own.
				 */
				'strings'    => array(
					'next'   => __( 'Next', 'ubac-kits' ),
					'done'   => __( 'Done', 'ubac-kits' ),
					'skip'   => __( 'Skip', 'ubac-kits' ),
					'of'     => __( 'of', 'ubac-kits' ),
					'denied' => __( 'You are not allowed to view this page.', 'ubac-kits' ),
				),
				/*
				 * The screen's own capability, not the shared file's default of
				 * manage_options: the settings screen asks for manage_woocommerce,
				 * so a shop manager would see the screen and never its tour.
				 */
				'capability'  => 'manage_woocommerce',
				/*
				 * Written out in full, never assembled from the slug. A submenu's
				 * hook is `<parent>_page_<slug>`, and an assembled one is accepted
				 * as a non-empty string while matching no screen at all: the tour
				 * would then play to nobody and mark itself as seen. The product
				 * editor cannot serve as the tour's screen either — its hook is
				 * `post.php`, which also fires on a blog post.
				 */
				'tour_screen' => 'woocommerce_page_dahu-composite-settings',
				/*
				 * Three steps, and the number is the family's rule. They point at
				 * controls that really exist on this screen: a step whose target
				 * is absent is skipped, so a guessed selector produces an empty,
				 * silent tour.
				 */
				'tour'        => array(
					array(
						'selector' => '#dahu-composite-shipping-class',
						'title'    => __( 'Where the parcel comes from', 'ubac-kits' ),
						'text'     => __( 'A composite product ships as one parcel. This shipping class is what carries it, and every composite product uses it.', 'ubac-kits' ),
					),
					array(
						'selector' => '.dahu-kit-settings__table',
						'title'    => __( 'Trade prices', 'ubac-kits' ),
						'text'     => __( 'A tier gives one or more roles their own price on every option. Without a tier, everybody sees the public price.', 'ubac-kits' ),
					),
					array(
						'selector' => '.dahu-ui-table',
						'title'    => __( 'Your composite products', 'ubac-kits' ),
						'text'     => __( 'Each one shows how many components and scenarios it has, and the address a shop front can read its schema from.', 'ubac-kits' ),
					),
				),
				'points'     => array(
				array(
					'title' => __( 'Components', 'ubac-kits' ),
					'text'  => __( 'Group the parts, set what each one costs.', 'ubac-kits' ),
				),
				array(
					'title' => __( 'Scenarios', 'ubac-kits' ),
					'text'  => __( 'Offer ready-made combinations alongside the free choice.', 'ubac-kits' ),
				),
				array(
					'title' => __( 'One price', 'ubac-kits' ),
					'text'  => __( 'The total follows the choices, in the cart and on the order.', 'ubac-kits' ),
				),
				),
			)
		);
	}
);