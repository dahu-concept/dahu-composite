<?php
/**
 * REST API — dahu-composite/v1 namespace.
 *
 *   GET  /schema/{product_id}  structure of the kit, options (price of the current role, weight)
 *   POST /state                {product_id, selections, quantity} → EVERYTHING in one pass
 *   POST /validate             {product_id, selections}           → validity + hiding
 *   POST /quote                {product_id, selections, quantity} → prices, weight, rows
 *   POST /add-to-cart          {product_id, selections, quantity} → revalidates, adds, fragments
 *   GET  /product-tabs/{product_id} detailed content of an option product (“More info” drawer)
 *
 * /state is the INTERACTIVE path: it is the only route the configurator calls
 * for a customer action, and the only one the administration simulator uses. It
 * corrects the selections made invalid by a hiding rule (internal cascade, with
 * no network round trip), then returns in one go what /validate and /quote
 * returned between them.
 *
 * /validate and /quote remain READ routes, unchanged, for external headless
 * use: they take the selections as they are, never correcting them. They are no
 * longer called by the front end.
 *
 * Authentication: WordPress cookie + X-WP-Nonce header (wp_rest nonce). Without
 * a nonce, a logged-in user is seen as a guest by the REST API: the price
 * returned would then be the public price. The bundled front end sends the
 * nonce; an external client must do the same.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Rest {

	const NAMESPACE_V1 = 'dahu-composite/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$selection_args = array(
			'product_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'selections' => array(
				'required' => false,
				'type'     => 'object',
				'default'  => array(),
			),
			'quantity'   => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			// Administration simulator only: configuration supplied
			// as it is being entered, before saving. Ignored for a
			// caller without the edit_products capability (403).
			'config'     => array(
				'required' => false,
				'type'     => 'object',
			),
			// Simulator only: the customer whose rendering is being
			// previewed (0 = visitor). Ignored outside “configure
			// online” mode, and therefore without edit_products.
			'as_customer' => array(
				'required'          => false,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/schema/(?P<product_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_schema' ),
				'permission_callback' => array( __CLASS__, 'permission_read_kit' ),
				'args'                => array(
					'product_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Detailed content of a product, for the “More info” drawer of the
		// configurator. Read only, no state: cached on the client side.
		register_rest_route(
			self::NAMESPACE_V1,
			'/product-tabs/(?P<product_id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_product_tabs' ),
				'permission_callback' => array( __CLASS__, 'permission_read_product' ),
				'args'                => array(
					'product_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/state',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'state' ),
				'permission_callback' => array( __CLASS__, 'permission_public' ),
				// serial: configurator opened by ?dahu_kit_serial=…
				// when the state could not be dropped into the page.
				'args'                => $selection_args + array(
					'serial' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/validate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'validate' ),
				'permission_callback' => array( __CLASS__, 'permission_public' ),
				'args'                => $selection_args,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/quote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'quote' ),
				'permission_callback' => array( __CLASS__, 'permission_public' ),
				'args'                => $selection_args,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/add-to-cart',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'add_to_cart' ),
				'permission_callback' => array( __CLASS__, 'permission_public' ),
				'args'                => $selection_args,
			)
		);
	}

	/**
	 * Kit visible to the current user, or WP_Error.
	 *
	 * @param int $product_id Product.
	 * @return WC_Product_Dahu_Kit|WP_Error
	 */
	/**
	 * Kit for a request, taking into account the “configure online” mode
	 * reserved for the administration.
	 *
	 * Normal mode (public front end and external clients): the configuration is
	 * read from the database using product_id, as before.
	 *
	 * Simulator mode: the request carries a `config` object (components and
	 * scenarios as they stand in the form, before saving). It is then normalised
	 * and set on the kit object; the engine sees no difference — evaluate(),
	 * validate() and quote() are exactly the same. Reserved for users who can
	 * edit products: the request must be authenticated by cookie + wp_rest nonce
	 * (without a valid nonce the REST API treats the caller as a visitor and the
	 * capability check fails), and this mode is only for previewing — it never
	 * takes part in /add-to-cart, where only the saved configuration counts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array {WC_Product_Dahu_Kit|WP_Error, string[] $warnings}
	 */
	private static function kit_for_request( WP_REST_Request $request ) {
		$config = $request['config'];

		if ( empty( $config ) || ! is_array( $config ) ) {
			return array( self::load_kit( $request['product_id'] ), array() );
		}

		if ( ! current_user_can( 'edit_products' ) ) {
			return array(
				new WP_Error(
					'dahu_kit_forbidden',
					__( 'Preview restricted to users who can manage products.', 'dahu-composite' ),
					array( 'status' => 403 )
				),
				array(),
			);
		}

		// The product may not be of the kit type yet (first creation):
		// a bare container is then enough for the preview.
		$kit = Dahu_Composite_Engine::get_kit( $request['product_id'] );
		if ( ! $kit ) {
			$kit = new WC_Product_Dahu_Kit();
		}

		$checked = Dahu_Composite_Validator::check(
			isset( $config['components'] ) && is_array( $config['components'] ) ? $config['components'] : array(),
			isset( $config['scenarios'] ) && is_array( $config['scenarios'] ) ? $config['scenarios'] : array(),
			// Customer exceptions being entered; key absent =
			// the saved ones.
			isset( $config['customer_exceptions'] ) && is_array( $config['customer_exceptions'] ) ? $config['customer_exceptions'] : null
		);

		$kit->set_kit_config( $checked['components'], $checked['scenarios'], $checked['exceptions'] );
		// Rendering as seen by a given customer. The capability is already
		// checked above: a customer can never pose as another.
		if ( null !== $request['as_customer'] ) {
			Dahu_Composite_Customer_Access::set_simulated_customer( (int) $request['as_customer'] );
		}
		Dahu_Composite_Pricing::ensure_tax_context();

		return array( $kit, $checked['warnings'] );
	}

	/**
	 * Permission for the public configurator routes.
	 *
	 * These routes are deliberately open to anonymous visitors, because the
	 * configurator has to work for a shopper who is not logged in — which is
	 * most shoppers. /state, /validate and /quote compute and return a result;
	 * they persist nothing. /add-to-cart writes to the visitor's own session
	 * cart, exactly like WooCommerce's own `?add-to-cart=` handler, which also
	 * requires no nonce.
	 *
	 * No nonce is required here on purpose: product pages are routinely served
	 * from a page cache, and a cached page carries a nonce that expires within
	 * a day. Requiring one would break adding to the cart on every cached page,
	 * to guard against an attacker putting an item in a visitor's own cart —
	 * a risk WooCommerce core itself accepts.
	 *
	 * Every route still revalidates the whole configuration server side before
	 * acting on it; nothing a client sends is trusted.
	 *
	 * @return true
	 */
	public static function permission_public() {
		return true;
	}

	/**
	 * Permission for reading a kit's schema.
	 *
	 * A published kit is readable by anyone, since its page is public. An
	 * unpublished one is readable only by a user who could edit it, so a draft
	 * can be previewed. The error is identical in both cases, so the response
	 * never reveals whether an unpublished kit exists.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_read_kit( WP_REST_Request $request ) {
		return self::permission_read_product( $request );
	}

	/**
	 * Permission for reading a product's detail tabs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_read_product( WP_REST_Request $request ) {

		$product_id = absint( $request['product_id'] );
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( $product && ( 'publish' === $product->get_status() || current_user_can( 'edit_post', $product_id ) ) ) {
			return true;
		}

		return new WP_Error(
			'dahu_product_not_found',
			__( 'Product not found.', 'dahu-composite' ),
			array( 'status' => 404 )
		);
	}

	private static function load_kit( $product_id ) {
		$kit = Dahu_Composite_Engine::get_kit( $product_id );
		if ( ! $kit ) {
			return new WP_Error( 'dahu_kit_not_found', __( 'Product not found.', 'dahu-composite' ), array( 'status' => 404 ) );
		}
		$visible = 'publish' === $kit->get_status() || current_user_can( 'edit_post', $kit->get_id() );
		if ( ! $visible ) {
			return new WP_Error( 'dahu_kit_not_found', __( 'Product not found.', 'dahu-composite' ), array( 'status' => 404 ) );
		}

		// Every route goes through here: taxation context initialised
		// before any display price is computed (otherwise incl. = excl. over REST).
		Dahu_Composite_Pricing::ensure_tax_context();

		return $kit;
	}

	/**
	 * Non-cacheable response (the prices depend on the role of the user).
	 *
	 * @param mixed $data   Data.
	 * @param int   $status Status.
	 * @return WP_REST_Response
	 */
	private static function response( $data, $status = 200 ) {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_schema( WP_REST_Request $request ) {
		$kit = self::load_kit( $request['product_id'] );
		if ( is_wp_error( $kit ) ) {
			return $kit;
		}
		return self::response( Dahu_Composite_Engine::schema( $kit ) );
	}

	/**
	 * Detail tabs of an option product. The product does not have to be a kit:
	 * it is a component option. We only require it to be visible.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_product_tabs( WP_REST_Request $request ) {
		$product = wc_get_product( absint( $request['product_id'] ) );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'dahu_product_not_found', __( 'Product not found.', 'dahu-composite' ), array( 'status' => 404 ) );
		}

		return self::response( Dahu_Composite_Product_Tabs::get( $product ) );
	}

	/**
	 * Complete state in one call: corrected selections, validity, hiding,
	 * prices, weight, rows. Replaces the /validate → /quote chain on the front
	 * end and in the administration simulator.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function state( WP_REST_Request $request ) {
		list( $kit, $warnings ) = self::kit_for_request( $request );
		if ( is_wp_error( $kit ) ) {
			return $kit;
		}

		// Serial: its choices replace the selections sent
		// (defaults of the schema); when unreadable, those stay.
		$raw     = $request['selections'];
		$restore = null;
		if ( is_string( $request['serial'] ) && '' !== $request['serial'] ) {
			$parsed  = Dahu_Composite_Composition::parse( $kit, $request['serial'] );
			$restore = Dahu_Composite_Composition::restore_info( $request['serial'], $parsed );
			if ( $parsed ) {
				$raw = $parsed['selections'];
			}
		}

		// decided: components already decided on the client side (steps passed,
		// “None”), for the display projection of the journey.
		$state = Dahu_Composite_Engine::state( $kit, $raw, $request['quantity'], $request['decided'] );
		if ( $restore ) {
			$state['serial_restore'] = $restore;
		}

		if ( $kit->has_kit_config_override() ) {
			$state['config_warnings'] = $warnings;
			$state['components']      = self::preview_components( $kit );
			// Rules generated by the public visibility and the exceptions,
			// shown by the “Customer exceptions” screen.
			$state['generated_scenarios'] = $kit->get_kit_generated_scenarios();
			$state['simulated_customer']  = Dahu_Composite_Customer_Access::current_customer_id();
		}

		return self::response( $state );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function validate( WP_REST_Request $request ) {
		list( $kit, $warnings ) = self::kit_for_request( $request );
		if ( is_wp_error( $kit ) ) {
			return $kit;
		}
		$selections = Dahu_Composite_Engine::normalize_selections( $kit, $request['selections'] );
		$validation = Dahu_Composite_Engine::validate( $kit, $selections, max( 1, (int) $request['quantity'] ) );

		if ( $kit->has_kit_config_override() ) {
			$validation['config_warnings'] = $warnings;
			$validation['components']      = self::preview_components( $kit );
		}

		return self::response( $validation );
	}

	/**
	 * Minimal structure of the components, so that the admin simulator knows
	 * what to display without copying a single rule to the client side.
	 *
	 * @param WC_Product_Dahu_Kit $kit Kit.
	 * @return array[]
	 */
	private static function preview_components( WC_Product_Dahu_Kit $kit ) {
		$out = array();
		foreach ( $kit->get_kit_components() as $component ) {
			$out[] = array(
				'id'             => $component['id'],
				'title'          => $component['title'],
				// Description of the component, displayed by the simulator as by
				// the front end (already filtered: wp_kses_post on normalisation).
				'description'    => isset( $component['description'] ) ? $component['description'] : '',
				'component_type' => $component['component_type'],
				'display_group'  => $component['display_group'],
				'title_role'     => isset( $component['title_role'] ) ? $component['title_role'] : 'primary',
				// Anchor of an addition: the simulator places it like
				// the front end.
				'anchor_component_id' => isset( $component['anchor_component_id'] ) ? $component['anchor_component_id'] : null,
				'anchor_product_id'   => isset( $component['anchor_product_id'] ) ? (int) $component['anchor_product_id'] : null,
				'optional'       => $component['optional'],
				'selection_mode' => $component['selection_mode'],
			);
		}
		return $out;
	}

	/**
	 * Quote. Also returns the validation state, so that a client can make do
	 * with a single call if it wants to.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function quote( WP_REST_Request $request ) {
		list( $kit, $warnings ) = self::kit_for_request( $request );
		if ( is_wp_error( $kit ) ) {
			return $kit;
		}
		unset( $warnings );
		$quantity   = max( 1, (int) $request['quantity'] );
		$selections = Dahu_Composite_Engine::normalize_selections( $kit, $request['selections'] );
		$quote      = Dahu_Composite_Engine::quote( $kit, $selections, $quantity );
		$validation = Dahu_Composite_Engine::validate( $kit, $selections, $quantity );

		$quote['valid']   = $validation['valid'];
		$quote['errors']  = $validation['errors'];
		$quote['missing'] = $validation['missing'];

		return self::response( $quote );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function add_to_cart( WP_REST_Request $request ) {
		// Deliberately load_kit() and not kit_for_request(): an addition to the
		// cart is NEVER made on a configuration supplied by the caller. Only
		// the saved configuration counts.
		$kit = self::load_kit( $request['product_id'] );
		if ( is_wp_error( $kit ) ) {
			return $kit;
		}

		$result = Dahu_Composite_Cart::add_kit_to_cart( $kit, $request['selections'], max( 1, (int) $request['quantity'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::response( array_merge( $result, Dahu_Composite_Cart::get_fragments() ) );
	}
}
