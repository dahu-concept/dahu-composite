<?php
/**
 * Saved configurations: “Save my configuration” in the Summary, the
 * “My account › My configurations” list, reopening at the Summary.
 *
 * STORAGE — one user meta (META), a list of LIGHT snapshots:
 *   { id, product_id, name, created, quantity, selections, summary }
 *  - selections: the selections as the engine returns them
 *    (Dahu_Composite_Engine::state() → selections): products, variants,
 *    quantities, “Any” attributes, bundle items, branch options. No prices:
 *    they are recomputed on reopening, at the rate of the day and of the role
 *    of the customer;
 *  - summary: “Step: choice” labels frozen at save time, for the account list
 *    only (never read back by the engine).
 *
 * REOPENING — link of the list: product page of the kit + ?dahu_kit_config=<id>.
 * The script of the configurator calls GET /saved/<id> (cookie + nonce: the page
 * may come from a cache, the response never does) and receives the state
 * computed by the engine, plus the differences from what had been saved
 * (restore()): option removed from the kit or deleted, out of stock, variant
 * gone, choice now incompatible (hidden by a scenario). A choice that no longer
 * holds is REMOVED and reported at its step, never forced: a reopened
 * configuration cannot be more permissive than an ordinary journey.
 *
 * A configuration always belongs to an ACCOUNT (the same constraint as the
 * customer exceptions):
 *  - logged-in customer: POST /saved, saved directly;
 *  - visitor: POST /saved-guest — form of the Summary (first name, last name,
 *    e-mail, phone, all required; no postal address), account created through
 *    WooCommerce's standard mechanism (wc_create_new_customer() and its
 *    filters, and therefore the registration rule of the site: “customer”
 *    role, password set through the link in the welcome e-mail), then the same
 *    save, in the same meta. See “Visitor” further down;
 *  - visitor whose e-mail already has an account: no duplicate, they are invited
 *    to log in; the script keeps their configuration for the duration of the
 *    login (sessionStorage) and saves it when they come back.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Saved_Configs {

	const META      = '_dahu_kit_saved_configs';
	const ENDPOINT  = 'mes-kits';
	const QUERY_ARG = 'dahu_kit_config';

	/** Maximum number of configurations per customer. */
	const MAX = 30;

	/** Maximum length of a configuration name. */
	const NAME_MAX = 80;

	/** Version of the rewrite rules (account endpoint). */
	const REWRITE_OPTION = 'dahu_composite_rewrite_version';

	/** Visitor: action of the nonce of the form. */
	const GUEST_NONCE = 'dahu_kit_guest_save';

	/** Visitor: honeypot field (anti-robot), empty for a human. */
	const GUEST_TRAP = 'dahu_kit_site';

	/** Visitor: user meta keeping a trace of the consent. */
	const CONSENT_META = '_dahu_kit_contact_consent';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// “My account › My configurations”: an endpoint declared to WooCommerce,
		// which adds it to its own rewrite rules. The label alone changed
		// along the way; the `mes-kits` address does not.
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_items' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'endpoint_title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_account' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_delete' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite' ), 99 );
	}

	/*
	|--------------------------------------------------------------------------
	| Storage
	|--------------------------------------------------------------------------
	*/

	/**
	 * Configurations of a customer, most recent first.
	 *
	 * @param int $user_id Customer.
	 * @return array[]
	 */
	public static function get_all( $user_id ) {
		$raw = get_user_meta( $user_id, self::META, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['id'] ) && ! empty( $entry['product_id'] ) && isset( $entry['selections'] ) && is_array( $entry['selections'] ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * @param int    $user_id Customer.
	 * @param string $id      Configuration.
	 * @return array|null
	 */
	public static function get( $user_id, $id ) {
		foreach ( self::get_all( $user_id ) as $entry ) {
			if ( (string) $entry['id'] === (string) $id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * @param int    $user_id Customer.
	 * @param string $id      Configuration.
	 * @return bool True when something was deleted.
	 */
	public static function delete( $user_id, $id ) {
		$all  = self::get_all( $user_id );
		$kept = array_values(
			array_filter(
				$all,
				static function ( $entry ) use ( $id ) {
					return (string) $entry['id'] !== (string) $id;
				}
			)
		);
		if ( count( $kept ) === count( $all ) ) {
			return false;
		}
		update_user_meta( $user_id, self::META, $kept );
		return true;
	}

	/**
	 * Saves the current configuration of a kit for a customer.
	 *
	 * @param int                 $user_id    Customer.
	 * @param WC_Product_Dahu_Kit $kit        Kit.
	 * @param mixed               $selections RAW selections (format of the front end).
	 * @param int                 $quantity   Number of kits.
	 * @param string              $name       Name given by the customer (empty: date).
	 * @return array|WP_Error Saved configuration.
	 */
	public static function save( $user_id, WC_Product_Dahu_Kit $kit, $selections, $quantity, $name ) {
		$all = self::get_all( $user_id );
		if ( count( $all ) >= self::MAX ) {
			/* translators: %d: maximum number */
			return new WP_Error( 'dahu_kit_saved_limit', sprintf( __( 'You already have %d saved configurations: delete one from My account › My configurations.', 'dahu-composite' ), self::MAX ), array( 'status' => 400 ) );
		}

		// Same path as a click: selections corrected by the engine. Only
		// a complete and valid configuration is saved — one that could be
		// added to the cart.
		$state = Dahu_Composite_Engine::state( $kit, $selections, $quantity );
		if ( empty( $state['valid'] ) ) {
			return new WP_Error( 'dahu_kit_saved_invalid', __( 'Incomplete configuration: finish it before saving.', 'dahu-composite' ), array( 'status' => 400 ) );
		}

		$name = self::clean_name( $name );
		if ( '' === $name ) {
			/* translators: %s: date */
			$name = sprintf( __( 'My configuration of %s', 'dahu-composite' ), date_i18n( get_option( 'date_format' ) ) );
		}

		$entry = array(
			'id'         => strtolower( wp_generate_password( 12, false, false ) ),
			'product_id' => (int) $kit->get_id(),
			'name'       => $name,
			'created'    => time(),
			'quantity'   => max( 1, (int) $state['quantity'] ),
			// Object → associative array (stored format, format read back).
			'selections' => json_decode( wp_json_encode( $state['selections'] ), true ),
			'summary'    => self::summary( $kit, $state ),
		);

		array_unshift( $all, $entry );
		update_user_meta( $user_id, self::META, $all );

		return $entry;
	}

	/**
	 * @param mixed $name Raw name.
	 * @return string
	 */
	private static function clean_name( $name ) {
		$name = trim( sanitize_text_field( (string) $name ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, self::NAME_MAX ) : substr( $name, 0, self::NAME_MAX );
	}

	/**
	 * “Step: choice” labels of a state (account list).
	 *
	 * @param WC_Product_Dahu_Kit $kit   Kit.
	 * @param array               $state Result of Dahu_Composite_Engine::state().
	 * @return string[]
	 */
	private static function summary( WC_Product_Dahu_Kit $kit, array $state ) {
		$selections = json_decode( wp_json_encode( $state['selections'] ), true );
		$out        = array();
		foreach ( $kit->get_kit_components() as $component ) {
			$cid = $component['id'];
			if ( Dahu_Composite_Engine::is_path( $component ) ) {
				$option = ! empty( $selections[ $cid ]['option_id'] ) ? Dahu_Composite_Engine::path_option( $component, $selections[ $cid ]['option_id'] ) : null;
				if ( $option ) {
					$out[] = $component['title'] . ' : ' . $option['label'];
				}
				continue;
			}
			foreach ( $state['line_items'] as $line ) {
				if ( $line['component_id'] === $cid ) {
					$out[] = $component['title'] . ' : ' . wp_strip_all_tags( $line['name'] ) . ( $line['quantity'] > 1 ? ' × ' . (int) $line['quantity'] : '' );
				}
			}
		}
		return $out;
	}

	/*
	|--------------------------------------------------------------------------
	| Reopening
	|--------------------------------------------------------------------------
	*/

	/**
	 * State of a saved configuration, recomputed today, and the differences from
	 * the snapshot.
	 *
	 * Two stages:
	 *  1. whatever no longer exists or can no longer be ordered is removed BEFORE
	 *     the engine (step gone, option removed from the kit or unpublished, out
	 *     of stock, variant gone or out of stock — the product stays, the variant
	 *     is to be chosen again);
	 *  2. the engine (state()) applies today's scenarios: whatever it removes on
	 *     top of that has “become incompatible with your other choices”.
	 *
	 * @param WC_Product_Dahu_Kit $kit   Kit.
	 * @param array               $entry Saved configuration.
	 * @return array { id, name, product_id, state, issues: {cid: string[]}, general: string[] }
	 */
	public static function restore( WC_Product_Dahu_Kit $kit, array $entry ) {
		$components = array();
		foreach ( $kit->get_kit_components() as $component ) {
			$components[ $component['id'] ] = $component;
		}

		$issues  = array();
		$general = array();
		$raw     = array();
		// Variants removed before the engine: [cid][pid] = rank of the message.
		// When the engine picks one automatically (the only possible variant), the
		// message says which one instead of “choose another”.
		$dropped = array();

		foreach ( $entry['selections'] as $cid => $selection ) {
			if ( ! isset( $components[ $cid ] ) || ! is_array( $selection ) ) {
				$general[] = __( 'A step of your configuration no longer exists in this product: its choice was removed.', 'dahu-composite' );
				continue;
			}
			$component = $components[ $cid ];

			if ( Dahu_Composite_Engine::is_path( $component ) ) {
				$option_id = isset( $selection['option_id'] ) ? (string) $selection['option_id'] : '';
				if ( ! Dahu_Composite_Engine::path_option( $component, $option_id ) ) {
					$issues[ $cid ][] = __( 'Your choice is no longer offered: please choose again.', 'dahu-composite' );
					continue;
				}
				$raw[ $cid ] = array( 'option_id' => $option_id );
				continue;
			}

			$multiple = Dahu_Composite_Engine::is_multiple( $component );
			$items    = Dahu_Composite_Engine::selection_items( $selection );
			$kept     = array();
			foreach ( $items as $item ) {
				$had  = ! empty( $item['variation_id'] );
				$item = self::check_item( $component, $item, $issues[ $cid ] );
				if ( $item ) {
					$kept[] = $item;
					if ( $had && empty( $item['variation_id'] ) ) {
						$dropped[ $cid ][ (int) $item['product_id'] ] = count( $issues[ $cid ] ) - 1;
					}
				}
			}
			if ( empty( $issues[ $cid ] ) ) {
				unset( $issues[ $cid ] );
			}
			if ( $kept ) {
				$raw[ $cid ] = $multiple ? $kept : $kept[0];
			}
		}

		$quantity = isset( $entry['quantity'] ) ? max( 1, (int) $entry['quantity'] ) : 1;
		// Everything is “decided”: the display projection sticks to the
		// real choices (we reopen at the Summary).
		$state = Dahu_Composite_Engine::state( $kit, $raw, $quantity, array_keys( $components ) );

		// What the engine removed or emptied on top of that: incompatibilities.
		$after = json_decode( wp_json_encode( $state['selections'] ), true );
		foreach ( $raw as $cid => $selection ) {
			if ( Dahu_Composite_Engine::is_path( $components[ $cid ] ) ) {
				if ( empty( $after[ $cid ] ) ) {
					$issues[ $cid ][] = __( 'Your choice is no longer compatible with your other choices: please choose again.', 'dahu-composite' );
				}
				continue;
			}
			$now = array();
			foreach ( Dahu_Composite_Engine::selection_items( isset( $after[ $cid ] ) ? $after[ $cid ] : null ) as $item ) {
				$now[ (int) $item['product_id'] ] = $item;
			}
			foreach ( Dahu_Composite_Engine::selection_items( $selection ) as $item ) {
				$pid  = (int) $item['product_id'];
				$name   = self::product_name( $pid );
				$chosen = isset( $now[ $pid ]['variation_id'] ) ? (int) $now[ $pid ]['variation_id'] : 0;
				if ( isset( $dropped[ $cid ][ $pid ] ) && $chosen ) {
					// Variant removed above, replaced automatically by the
					// engine (the only one possible): we say which one.
					/* translators: %s: variant */
					$issues[ $cid ][ $dropped[ $cid ][ $pid ] ] .= ' ' . sprintf( __( '“%s” was chosen instead, as the only variation still available: please check it.', 'dahu-composite' ), self::product_name( $chosen ) );
					continue;
				}
				if ( ! isset( $now[ $pid ] ) ) {
					/* translators: %s: product */
					$issues[ $cid ][] = sprintf( __( '“%s” is no longer compatible with your other choices: it was removed.', 'dahu-composite' ), $name );
				} elseif ( ! empty( $item['variation_id'] ) && $chosen && $chosen !== (int) $item['variation_id'] ) {
					/* translators: 1: product, 2: variant */
					$issues[ $cid ][] = sprintf( __( 'The variation chosen for “%1$s” is no longer compatible with your other choices; “%2$s” was chosen instead as the only one possible: please check it.', 'dahu-composite' ), $name, self::product_name( $chosen ) );
				} elseif ( ! empty( $item['variation_id'] ) && ! $chosen ) {
					/* translators: %s: product */
					$issues[ $cid ][] = sprintf( __( 'The variation chosen for “%s” is no longer compatible with your other choices: please pick another.', 'dahu-composite' ), $name );
				}
			}
		}

		return array(
			'id'         => (string) $entry['id'],
			'name'       => (string) $entry['name'],
			'product_id' => (int) $entry['product_id'],
			'state'      => $state,
			// A JSON object even when empty ({}), key = component.
			'issues'     => (object) $issues,
			'general'    => array_values( array_unique( $general ) ),
		);
	}

	/**
	 * Is a saved item still orderable? Returns the item (with the variant
	 * possibly removed), or null when it has to be removed. The messages go into
	 * $issues.
	 *
	 * @param array      $component Component.
	 * @param array      $item      Saved item.
	 * @param array|null $issues    Messages of the step (by reference).
	 * @return array|null
	 */
	private static function check_item( array $component, array $item, &$issues ) {
		$issues  = is_array( $issues ) ? $issues : array();
		$pid     = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
		$offered = false;
		foreach ( $component['options'] as $option ) {
			$offered = $offered || (int) $option['product_id'] === $pid;
		}
		$product = $pid ? wc_get_product( $pid ) : null;
		$name    = self::product_name( $pid );

		if ( ! $offered || ! $product || 'publish' !== $product->get_status() ) {
			/* translators: %s: product */
			$issues[] = sprintf( __( '“%s” is no longer offered: it was removed.', 'dahu-composite' ), $name );
			return null;
		}
		if ( ! $product->is_in_stock() ) {
			/* translators: %s: product */
			$issues[] = sprintf( __( '“%s” is out of stock: it was removed.', 'dahu-composite' ), $name );
			return null;
		}

		$vid = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
		if ( $vid ) {
			$variation = wc_get_product( $vid );
			if ( ! $variation || (int) $variation->get_parent_id() !== $pid || ! $variation->variation_is_visible() ) {
				/* translators: %s: product */
				$issues[] = sprintf( __( 'The variation chosen for “%s” no longer exists: please pick another.', 'dahu-composite' ), $name );
				unset( $item['variation_id'], $item['attributes'] );
			} elseif ( ! $variation->is_in_stock() ) {
				/* translators: %s: product */
				$issues[] = sprintf( __( 'The variation chosen for “%s” is out of stock: please pick another.', 'dahu-composite' ), $name );
				unset( $item['variation_id'], $item['attributes'] );
			}
		}

		return $item;
	}

	/**
	 * @param int $product_id Product.
	 * @return string
	 */
	private static function product_name( $product_id ) {
		$product = $product_id ? wc_get_product( $product_id ) : null;
		return $product ? wp_strip_all_tags( $product->get_name() ) : '#' . (int) $product_id;
	}

	/**
	 * Link that reopens a configuration.
	 *
	 * @param array $entry Configuration.
	 * @return string
	 */
	public static function reopen_url( array $entry ) {
		$permalink = get_permalink( (int) $entry['product_id'] );
		return $permalink ? add_query_arg( self::QUERY_ARG, rawurlencode( (string) $entry['id'] ), $permalink ) : '';
	}

	/** “My account › My configurations” page. */
	public static function account_url() {
		return function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( self::ENDPOINT ) : '';
	}

	/*
	|--------------------------------------------------------------------------
	| REST API
	|--------------------------------------------------------------------------
	*/

	public static function register_routes() {
		$logged_in = static function () {
			return is_user_logged_in();
		};

		register_rest_route(
			Dahu_Composite_Rest::NAMESPACE_V1,
			'/saved',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_save' ),
				'permission_callback' => $logged_in,
				'args'                => array(
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
					'name'       => array(
						'required' => false,
						'type'     => 'string',
						'default'  => '',
					),
				),
			)
		);

		// Visitor: account creation + save. A route public
		// by nature; nonce, honeypot field, per-IP quota and
		// validation of every field in rest_save_guest(). The fields of the
		// form are not declared here: their error messages are
		// those of the form, field by field, not those of the REST API.
		register_rest_route(
			Dahu_Composite_Rest::NAMESPACE_V1,
			'/saved-guest',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_save_guest' ),
				'permission_callback' => array( __CLASS__, 'permission_save_guest' ),
				'args'                => array(
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
				),
			)
		);

		register_rest_route(
			Dahu_Composite_Rest::NAMESPACE_V1,
			'/saved/(?P<id>[a-z0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => $logged_in,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'rest_delete' ),
					'permission_callback' => $logged_in,
				),
			)
		);
	}

	/**
	 * Kit visible, ready for a price computation, or WP_Error.
	 *
	 * @param int $product_id Product.
	 * @return WC_Product_Dahu_Kit|WP_Error
	 */
	private static function load_kit( $product_id ) {
		$kit = Dahu_Composite_Engine::get_kit( $product_id );
		if ( ! $kit || ( 'publish' !== $kit->get_status() && ! current_user_can( 'edit_post', $kit->get_id() ) ) ) {
			return new WP_Error( 'dahu_kit_not_found', __( 'This product is no longer available.', 'dahu-composite' ), array( 'status' => 404 ) );
		}
		Dahu_Composite_Pricing::ensure_tax_context();
		return $kit;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_save( WP_REST_Request $request ) {
		$kit = self::load_kit( $request['product_id'] );
		if ( is_wp_error( $kit ) ) {
			return $kit;
		}
		$entry = self::save( get_current_user_id(), $kit, $request['selections'], $request['quantity'], $request['name'] );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		return self::response(
			array(
				'id'          => $entry['id'],
				'name'        => $entry['name'],
				'url'         => self::reopen_url( $entry ),
				'account_url' => self::account_url(),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get( WP_REST_Request $request ) {
		// Meta of the logged-in customer: a configuration of another customer is
		// never found (the same response as an unknown identifier).
		$entry = self::get( get_current_user_id(), $request['id'] );
		if ( ! $entry ) {
			return new WP_Error( 'dahu_kit_saved_not_found', __( 'Configuration not found in your account.', 'dahu-composite' ), array( 'status' => 404 ) );
		}
		$kit = self::load_kit( $entry['product_id'] );
		if ( is_wp_error( $kit ) ) {
			return $kit;
		}
		return self::response( self::restore( $kit, $entry ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_delete( WP_REST_Request $request ) {
		if ( ! self::delete( get_current_user_id(), $request['id'] ) ) {
			return new WP_Error( 'dahu_kit_saved_not_found', __( 'Configuration not found in your account.', 'dahu-composite' ), array( 'status' => 404 ) );
		}
		return self::response( array( 'deleted' => true ) );
	}

	/**
	 * @param mixed $data Data.
	 * @return WP_REST_Response
	 */
	private static function response( $data ) {
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/*
	|--------------------------------------------------------------------------
	| Visitor: account creation, then save
	|--------------------------------------------------------------------------
	*/

	/**
	 * Fields of the visitor form, in display order. A single source: the script
	 * displays them (DahuCompositeConfig.guestFields), the server validates them
	 * (guest_values()).
	 *
	 * REQUIRED: first name, last name, e-mail and phone — the fields of the My
	 * account registration form. The postal address (address, postcode, town) is
	 * no longer asked for. The FORMAT of these fields (the phone in particular) is
	 * not decided here: it is the registration rule of the site, applied by
	 * wc_create_new_customer() (see create_customer()). The
	 * `dahu_composite_guest_save_fields` filter: `label`, `max` and `required` are
	 * adjustable, and a field removed is neither displayed nor saved:
	 *
	 *     add_filter( 'dahu_composite_guest_save_fields', function ( $fields ) {
	 *         $fields['phone']['required'] = false;
	 *         return $fields;
	 *     } );
	 *
	 * The e-mail stays required whatever happens: it is what creates the account.
	 * A field removed by the filter is neither displayed nor saved; an unknown
	 * field is ignored (each one has its own column on the WooCommerce customer
	 * side, see create_customer()).
	 *
	 * @return array[] key => { label, type, autocomplete, max, required }
	 */
	public static function guest_fields() {
		$fields = array(
			'first_name' => array(
				'label'        => __( 'First name', 'dahu-composite' ),
				'type'         => 'text',
				'autocomplete' => 'given-name',
				'max'          => 60,
				'required'     => true,
			),
			'last_name'  => array(
				'label'        => __( 'Last name', 'dahu-composite' ),
				'type'         => 'text',
				'autocomplete' => 'family-name',
				'max'          => 60,
				'required'     => true,
			),
			'email'      => array(
				'label'        => __( 'Email address', 'dahu-composite' ),
				'type'         => 'email',
				'autocomplete' => 'email',
				'max'          => 100,
				'required'     => true,
			),
			'phone'      => array(
				'label'        => __( 'Phone', 'dahu-composite' ),
				'type'         => 'tel',
				'autocomplete' => 'tel',
				'max'          => 30,
				'required'     => true,
			),
		);

		/**
		 * Fields of the visitor's “Save my configuration” form.
		 *
		 * @param array[] $fields key => { label, type, autocomplete, max, required }.
		 */
		$filtered = apply_filters( 'dahu_composite_guest_save_fields', $fields );
		$filtered = is_array( $filtered ) ? $filtered : array();

		$out = array();
		foreach ( $fields as $key => $default ) {
			$custom = isset( $filtered[ $key ] ) && is_array( $filtered[ $key ] ) ? $filtered[ $key ] : null;
			if ( null === $custom && 'email' !== $key ) {
				continue;
			}
			$field             = array_merge( $default, (array) $custom );
			$field['type']     = $default['type'];
			$field['max']      = max( 1, min( 200, (int) $field['max'] ) );
			$field['required'] = 'email' === $key || ! empty( $field['required'] );
			$field['label']    = wp_strip_all_tags( (string) $field['label'] );
			$out[ $key ]       = $field;
		}
		return $out;
	}

	/** Wording of the consent checkbox (recorded with the account created). */
	public static function consent_text() {
		return __( 'I agree to my details being stored so that I can be contacted about my configuration.', 'dahu-composite' );
	}

	/** Is the visitor form offered? */
	public static function guest_enabled() {
		/**
		 * Offer the visitor the chance to create their account from the Summary.
		 * When false, the block falls back to the invitation to log in alone and
		 * the /saved-guest route answers 403.
		 *
		 * @param bool $enabled Offered.
		 */
		return (bool) apply_filters( 'dahu_composite_guest_save_enabled', function_exists( 'wc_create_new_customer' ) );
	}

	/**
	 * Sanitised values of the form and errors per field.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array { 0: values (key => string), 1: errors (key => message) }
	 */
	private static function guest_values( WP_REST_Request $request ) {
		$values = array();
		$errors = array();
		foreach ( self::guest_fields() as $key => $field ) {
			$raw = $request->get_param( $key );
			$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
			// E-mail: sanitize_email() removes what cannot appear in
			// an address; the rest is plain text on a single line.
			$value = 'email' === $key ? sanitize_email( $raw ) : trim( sanitize_text_field( $raw ) );
			$long  = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );

			if ( 'email' === $key && ( $value !== $raw || ! is_email( $value ) ) && '' !== $raw ) {
				$errors[ $key ] = __( 'This email address is not valid.', 'dahu-composite' );
			} elseif ( '' === $value ) {
				if ( $field['required'] ) {
					$errors[ $key ] = __( 'This field is required.', 'dahu-composite' );
				}
			} elseif ( $long > $field['max'] ) {
				/* translators: %d: number of characters */
				$errors[ $key ] = sprintf( __( '%d characters at most.', 'dahu-composite' ), $field['max'] );
			}
			// Format of the phone: no check of our own here. It is a matter
			// for the registration rule of the site, the same as for My
			// account, applied when the account is created (create_customer()).
			$values[ $key ] = $value;
		}
		return array( $values, $errors );
	}

	/**
	 * Quota of requests per IP address (creating an account sends an e-mail to an
	 * unverified address: the route must not become a relay). The address is never
	 * stored: only its hash serves as a key.
	 *
	 * @return bool True when the quota is reached.
	 */
	private static function guest_throttled() {
		/**
		 * Account creation requests accepted per hour and per IP address from the
		 * Summary. 0: no quota.
		 *
		 * @param int $limit Quota.
		 */
		$limit = (int) apply_filters( 'dahu_composite_guest_save_limit', 10 );
		$ip    = class_exists( 'WC_Geolocation' ) ? (string) WC_Geolocation::get_ip_address() : '';
		if ( $limit < 1 || '' === $ip ) {
			return false;
		}
		$key   = 'dahu_kit_gs_' . md5( wp_salt( 'nonce' ) . $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return false;
	}

	/**
	 * Creates the customer account through WooCommerce's standard mechanism.
	 *
	 *  - role: “customer”, the one of wc_create_new_customer() — no Dahu role is
	 *    added;
	 *  - username: generated by WooCommerce from the e-mail; the customer logs in
	 *    with their e-mail address;
	 *  - password: generated by WooCommerce, never returned or displayed. The
	 *    welcome e-mail (“New account”) carries the link through which the customer
	 *    sets their own;
	 *  - the registration filters apply (`woocommerce_register_post`,
	 *    `woocommerce_registration_errors`: the anti-spam of dahu-security-connect,
	 *    refused domains…), and with them the REGISTRATION RULE OF THE SITE:
	 *    during the call, the `dahu_composite_registration_fields` filter returns
	 *    the entered values under the names of the My account registration form
	 *    (billing_first_name, billing_last_name, billing_phone), and null
	 *    everywhere else. The theme of the site uses that to validate and save this
	 *    form exactly as it does the My account one (as observed with a theme
	 *    declaring an endpoint of its own): ONE single rule, no copy here. Its
	 *    refusals carry, as data, the field concerned (`field`), returned under
	 *    that field (registration_field_errors()).
	 *
	 * @param array $values Sanitised values (guest_values()).
	 * @return int|WP_Error Identifier of the account.
	 */
	private static function create_customer( array $values ) {
		// WooCommerce < 9 only generated a username and a password when the
		// settings of the shop asked for it: we impose it for the duration of
		// the call, so as never to have to ask for a password here.
		$yes = static function () {
			return 'yes';
		};
		add_filter( 'pre_option_woocommerce_registration_generate_username', $yes );
		add_filter( 'pre_option_woocommerce_registration_generate_password', $yes );

		// Values for the registration rule of the site (see above), for the
		// duration of the call only.
		$fields = static function () use ( $values ) {
			return array(
				'billing_first_name' => isset( $values['first_name'] ) ? $values['first_name'] : '',
				'billing_last_name'  => isset( $values['last_name'] ) ? $values['last_name'] : '',
				'billing_phone'      => isset( $values['phone'] ) ? $values['phone'] : '',
			);
		};
		add_filter( 'dahu_composite_registration_fields', $fields );

		$user_id = wc_create_new_customer(
			$values['email'],
			wc_create_new_customer_username( $values['email'] ),
			'',
			array(
				'first_name' => isset( $values['first_name'] ) ? $values['first_name'] : '',
				'last_name'  => isset( $values['last_name'] ) ? $values['last_name'] : '',
				'source'     => 'dahu-composite',
			)
		);

		remove_filter( 'pre_option_woocommerce_registration_generate_username', $yes );
		remove_filter( 'pre_option_woocommerce_registration_generate_password', $yes );
		remove_filter( 'dahu_composite_registration_fields', $fields );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Contact details: standard billing fields, reused at checkout
		// (already written by the rule of the site when it is there: same values).
		$map      = array(
			'first_name' => 'billing_first_name',
			'last_name'  => 'billing_last_name',
			'email'      => 'billing_email',
			'phone'      => 'billing_phone',
		);
		$customer = new WC_Customer( $user_id );
		foreach ( $map as $key => $prop ) {
			if ( isset( $values[ $key ] ) && '' !== $values[ $key ] ) {
				$customer->{'set_' . $prop}( $values[ $key ] );
			}
		}
		$customer->save();

		return (int) $user_id;
	}

	/**
	 * Refusals of the registration rule, per field of the form: errors from
	 * wc_create_new_customer() that carry as data the registration field concerned
	 * (`field`: billing_first_name, billing_last_name, billing_phone, or the key of
	 * the form itself).
	 *
	 * @param WP_Error $error Refusal of the account creation.
	 * @return array key of the form => message.
	 */
	private static function registration_field_errors( WP_Error $error ) {
		$names = array(
			'billing_first_name' => 'first_name',
			'billing_last_name'  => 'last_name',
			'billing_phone'      => 'phone',
		);
		$known = self::guest_fields();
		$out   = array();
		foreach ( $error->get_error_codes() as $code ) {
			$data  = $error->get_error_data( $code );
			$field = is_array( $data ) && isset( $data['field'] ) ? (string) $data['field'] : '';
			$key   = isset( $names[ $field ] ) ? $names[ $field ] : $field;
			if ( '' !== $key && isset( $known[ $key ] ) && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = wp_strip_all_tags( $error->get_error_message( $code ) );
			}
		}
		return $out;
	}

	/** Is WooCommerce's welcome e-mail (“New account”) active? */
	private static function welcome_email_enabled() {
		$emails = WC()->mailer()->get_emails();
		return isset( $emails['WC_Email_Customer_New_Account'] ) && $emails['WC_Email_Customer_New_Account']->is_enabled();
	}

	/**
	 * Visitor: creates their account, then saves the configuration into it.
	 *
	 * Order of the checks: nonce, honeypot field, fields and consent,
	 * configuration (complete and valid — no account is created for a
	 * configuration that could not be saved), quota, e-mail already known, and
	 * only then the creation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	/**
	 * Permission for the guest save route.
	 *
	 * This is the only route that lets an anonymous visitor write something
	 * durable, so its gatekeeping lives here rather than in the handler:
	 * the feature must be switched on by the merchant, and the request must
	 * carry a valid dedicated nonce. A logged-in caller falls through to the
	 * ordinary save, which has its own capability check.
	 *
	 * The remaining defences — honeypot field, explicit consent, per-visitor
	 * quota and full server-side revalidation of the configuration — stay in
	 * the handler, where the request body is read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_save_guest( WP_REST_Request $request ) {

		if ( is_user_logged_in() ) {
			return true;
		}

		if ( ! self::guest_enabled() ) {
			return new WP_Error(
				'dahu_kit_guest_disabled',
				__( 'Log in to save your configuration.', 'dahu-composite' ),
				array( 'status' => 403 )
			);
		}

		$nonce = $request->get_param( 'nonce' );

		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::GUEST_NONCE ) ) {
			return new WP_Error(
				'dahu_kit_guest_nonce',
				__( 'Your session has expired: reload the page and try again.', 'dahu-composite' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public static function rest_save_guest( WP_REST_Request $request ) {
		// Already logged in (direct call): ordinary save, the form is
		// ignored. Activation and nonce are already checked by
		// permission_save_guest(), so they are not checked again here.
		if ( is_user_logged_in() ) {
			return self::rest_save( $request );
		}

		// Honeypot field filled in: a robot. A deliberately vague response.
		$trap = $request->get_param( self::GUEST_TRAP );
		if ( null !== $trap && '' !== $trap ) {
			return new WP_Error( 'dahu_kit_guest_refused', __( 'Your request could not be saved. Reload the page and try again.', 'dahu-composite' ), array( 'status' => 400 ) );
		}

		list( $values, $errors ) = self::guest_values( $request );
		// Consent: the box is ticked by the customer, never assumed.
		if ( true !== rest_sanitize_boolean( $request->get_param( 'consent' ) ) ) {
			$errors['consent'] = __( 'Tick this box so that we may store your details.', 'dahu-composite' );
		}
		if ( $errors ) {
			return new WP_Error(
				'dahu_kit_guest_invalid',
				isset( $errors['consent'] ) && 1 === count( $errors )
					? $errors['consent']
					: __( 'Check the fields flagged above.', 'dahu-composite' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		$kit = self::load_kit( $request['product_id'] );
		if ( is_wp_error( $kit ) ) {
			return $kit;
		}
		$state = Dahu_Composite_Engine::state( $kit, $request['selections'], $request['quantity'] );
		if ( empty( $state['valid'] ) ) {
			return new WP_Error( 'dahu_kit_saved_invalid', __( 'Incomplete configuration: finish it before saving.', 'dahu-composite' ), array( 'status' => 400 ) );
		}

		if ( self::guest_throttled() ) {
			return new WP_Error( 'dahu_kit_guest_throttled', __( 'Too many requests from your connection. Try again in an hour.', 'dahu-composite' ), array( 'status' => 429 ) );
		}

		// E-mail already known: never a second account. The script keeps the
		// configuration and saves it when they come back from logging in.
		$exists = new WP_Error( 'dahu_kit_account_exists', __( 'An account already exists with this email address. Log in and your configuration will be saved to your account when you come back.', 'dahu-composite' ), array( 'status' => 409 ) );
		if ( email_exists( $values['email'] ) ) {
			return $exists;
		}

		$user_id = self::create_customer( $values );
		if ( is_wp_error( $user_id ) ) {
			if ( 'registration-error-email-exists' === $user_id->get_error_code() ) {
				return $exists;
			}
			// Registration rule of the site: every message under its own
			// field, like an input error.
			$field_errors = self::registration_field_errors( $user_id );
			if ( $field_errors ) {
				return new WP_Error(
					'dahu_kit_guest_invalid',
					__( 'Check the fields flagged above.', 'dahu-composite' ),
					array(
						'status' => 400,
						'fields' => $field_errors,
					)
				);
			}
			// Refusal from a plugin (anti-spam…): its message, with no tag.
			return new WP_Error( 'dahu_kit_guest_refused', wp_strip_all_tags( $user_id->get_error_message() ), array( 'status' => 400 ) );
		}

		// Trace of the consent: date, wording accepted, kit concerned.
		update_user_meta(
			$user_id,
			self::CONSENT_META,
			array(
				'time'       => time(),
				'text'       => self::consent_text(),
				'product_id' => (int) $kit->get_id(),
			)
		);

		$entry = self::save( $user_id, $kit, $request['selections'], $request['quantity'], '' );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}

		// The visitor is NOT logged in automatically: their e-mail address is
		// not verified, and access to the account goes through the link received at
		// that address.
		return self::response(
			array(
				'id'          => $entry['id'],
				'name'        => $entry['name'],
				'account_url' => self::account_url(),
				'created'     => true,
				'email'       => $values['email'],
				'mail_sent'   => self::welcome_email_enabled(),
			)
		);
	}

	/*
	|--------------------------------------------------------------------------
	| My account › My configurations
	|--------------------------------------------------------------------------
	*/

	/**
	 * @param array $vars WooCommerce endpoints.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * “My configurations” just before “Log out”.
	 *
	 * @param array $items Entries of the menu.
	 * @return array
	 */
	public static function menu_items( $items ) {
		$logout = isset( $items['customer-logout'] ) ? $items['customer-logout'] : null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = __( 'My configurations', 'dahu-composite' );
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	/** @return string */
	public static function endpoint_title() {
		return __( 'My configurations', 'dahu-composite' );
	}

	/**
	 * New endpoint: rewrite rules regenerated once (when this version is
	 * installed), never on every load.
	 */
	public static function maybe_flush_rewrite() {
		if ( get_option( self::REWRITE_OPTION ) !== self::ENDPOINT ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_OPTION, self::ENDPOINT, true );
		}
	}

	/**
	 * Deletion from the account list (POST form + nonce, with no JavaScript),
	 * then back to the list.
	 */
	public static function handle_delete() {
		if ( empty( $_POST['dahu_kit_saved_delete'] ) || ! is_user_logged_in() ) {
			return;
		}
		$id = sanitize_key( wp_unslash( $_POST['dahu_kit_saved_delete'] ) );
		if ( ! isset( $_POST['_dahu_kit_saved_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_dahu_kit_saved_nonce'] ) ), 'dahu_kit_saved_delete_' . $id ) ) {
			return;
		}
		if ( self::delete( get_current_user_id(), $id ) && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Configuration deleted.', 'dahu-composite' ) );
		}
		wp_safe_redirect( self::account_url() );
		exit;
	}

	/**
	 * Contents of “My configurations”. A standard WooCommerce table
	 * (shop_table); the two actions carry a class of their own
	 * (.dahu-kit-saved__open, .dahu-kit-saved__remove) so that a theme can tell
	 * the main action from the deletion.
	 */
	public static function render_account() {
		$entries = self::get_all( get_current_user_id() );
		if ( ! $entries ) {
			echo '<p class="dahu-kit-saved__empty">' . esc_html__( 'No saved configuration yet. On the Summary step, “Save my configuration” files it here.', 'dahu-composite' ) . '</p>';
			return;
		}
		echo '<table class="shop_table shop_table_responsive dahu-kit-saved"><thead><tr>';
		echo '<th>' . esc_html__( 'Configuration', 'dahu-composite' ) . '</th>';
		echo '<th>' . esc_html__( 'Product', 'dahu-composite' ) . '</th>';
		echo '<th>' . esc_html__( 'Saved on', 'dahu-composite' ) . '</th>';
		echo '<th><span class="screen-reader-text">' . esc_html__( 'Actions', 'dahu-composite' ) . '</span></th>';
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$kit  = wc_get_product( (int) $entry['product_id'] );
			$live = $kit && $kit->is_type( DAHU_COMPOSITE_PRODUCT_TYPE ) && 'publish' === $kit->get_status();
			$url  = $live ? self::reopen_url( $entry ) : '';
			echo '<tr>';
			echo '<td data-title="' . esc_attr__( 'Configuration', 'dahu-composite' ) . '"><strong>' . esc_html( $entry['name'] ) . '</strong>';
			if ( ! empty( $entry['summary'] ) && is_array( $entry['summary'] ) ) {
				echo '<details class="dahu-kit-saved__details"><summary>' . esc_html__( 'Details', 'dahu-composite' ) . '</summary><ul>';
				foreach ( $entry['summary'] as $line ) {
					echo '<li>' . esc_html( $line ) . '</li>';
				}
				echo '</ul></details>';
			}
			echo '</td>';
			echo '<td data-title="' . esc_attr__( 'Product', 'dahu-composite' ) . '">' . ( $kit ? esc_html( $kit->get_name() ) : '—' ) . ( $live ? '' : ' <em>(' . esc_html__( 'no longer available', 'dahu-composite' ) . ')</em>' ) . '</td>';
			echo '<td data-title="' . esc_attr__( 'Saved on', 'dahu-composite' ) . '">' . esc_html( date_i18n( get_option( 'date_format' ), (int) $entry['created'] ) ) . '</td>';
			echo '<td class="dahu-kit-saved__actions">';
			if ( $url ) {
				echo '<a class="woocommerce-button button dahu-kit-saved__open" href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'dahu-composite' ) . '</a> ';
			}
			echo '<form method="post" class="dahu-kit-saved__delete">';
			echo '<input type="hidden" name="dahu_kit_saved_delete" value="' . esc_attr( $entry['id'] ) . '">';
			wp_nonce_field( 'dahu_kit_saved_delete_' . $entry['id'], '_dahu_kit_saved_nonce', false );
			echo '<button type="submit" class="woocommerce-button button dahu-kit-saved__remove" onclick="return window.confirm(this.getAttribute(\'data-confirm\'));" data-confirm="' . esc_attr__( 'Delete this configuration?', 'dahu-composite' ) . '">' . esc_html__( 'Delete', 'dahu-composite' ) . '</button>';
			echo '</form></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
