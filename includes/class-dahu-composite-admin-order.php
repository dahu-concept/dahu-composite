<?php
/**
 * Order screen (admin): assembling a kit on an order row — phone order,
 * after-sales replacement.
 *
 * The same gesture as the composite products of WooCommerce Composite Products
 * (WCCP) on that screen: the kit product is added through “Add item(s)”, a
 * “Configure” button appears under its row (“Edit” once the kit is assembled),
 * it opens a “Configure” window with one block per component (choice list,
 * preview of the product retained: image, description, stock) and a “Done”
 * button. Same hooks as WCCP (class-wc-cp-admin-order.php): the
 * woocommerce_after_order_itemmeta action for the button, a $.WCBackboneModal
 * window, the item table returned by the server, then the
 * wc_order_items_reloaded event.
 *
 * DELIBERATE DEPARTURE from WCCP: its data model is not reused. WCCP keeps the
 * aggregated price on a single row; here, as for a kit that went through the
 * cart, the order receives a container row + one row per component, with the
 * same metas (Dahu_Composite_Order::add_kit_to_order()), read the same way by
 * dahu-post, dahu-sav, dahu-serials, dahu-avoir and the PDF invoices.
 *
 * One single engine: structure of the kit, scenarios, validation, rows and
 * composition identifier all come from Dahu_Composite_Engine and
 * Dahu_Composite_Cart::build_kit_lines(), as on the front end. The only
 * differences, specific to the order screen:
 *  - the customer is the one of the order (the “Customer” field of the screen,
 *    even before it is saved), not the logged-in operator: the price of their
 *    role (Dahu_Composite_Pricing::set_customer()) and the options reserved for
 *    them (Dahu_Composite_Customer_Access::set_simulated_customer());
 *  - no stock constraint (Dahu_Composite_Engine::without_stock_errors()), like
 *    the manual addition of an item by WooCommerce.
 *
 * Concerns only products of the Dahu kit type (WC_Product_Dahu_Kit, the same
 * test as the configurator of the product page): the WCCP composites keep their
 * own button and their own window.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Admin_Order {

	const NONCE = 'dahu_composite_admin_order';

	/** @var WC_Order|null Order being displayed (“Configure” button). */
	private static $order = null;

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_admin_order_item_headers', array( __CLASS__, 'set_order' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'display_configure_button' ), 10, 3 );
		add_action( 'admin_footer', array( __CLASS__, 'print_modal_template' ) );

		add_action( 'wp_ajax_dahu_composite_admin_get_kit_schema', array( __CLASS__, 'ajax_get_kit_schema' ) );
		add_action( 'wp_ajax_dahu_composite_admin_kit_state', array( __CLASS__, 'ajax_kit_state' ) );
		add_action( 'wp_ajax_dahu_composite_admin_configure_kit_line', array( __CLASS__, 'ajax_configure_kit_line' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Screen
	|--------------------------------------------------------------------------
	*/

	/**
	 * Order editing / creation screen, HPOS or not.
	 *
	 * @return bool
	 */
	private static function is_order_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		$order_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		return $screen->id === $order_screen;
	}

	/**
	 * Script and stylesheet, on the order screen only. Loaded even when the
	 * order holds no kit: a kit row may be added afterwards. Without a kit row
	 * they change nothing on the screen.
	 */
	public static function enqueue() {
		if ( ! self::is_order_screen() ) {
			return;
		}

		wp_enqueue_script(
			'dahu-composite-admin-order',
			DAHU_COMPOSITE_URL . 'assets/js/dahu-composite-admin-order.js',
			array( 'jquery', 'jquery-blockui', 'selectWoo', 'wc-backbone-modal', 'wc-admin-order-meta-boxes' ),
			DAHU_COMPOSITE_VERSION,
			true
		);
		wp_localize_script(
			'dahu-composite-admin-order',
			'dahuCompositeAdminOrder',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'i18n'    => array(
					'none'          => __( 'None', 'dahu-composite' ),
					'choose'        => __( 'Choose…', 'dahu-composite' ),
					'optional'      => __( '(optional)', 'dahu-composite' ),
					'quantity'      => __( 'Quantity', 'dahu-composite' ),
					'variation'     => __( 'Variation', 'dahu-composite' ),
					'comingSoon'    => __( 'coming soon', 'dahu-composite' ),
					// Same text as the configurator of the front end.
					'missingChoice' => __( 'Choose a variation before continuing.', 'dahu-composite' ),
					'blocked'       => __( 'This combination is not possible.', 'dahu-composite' ),
					'loadError'     => __( 'The kit could not be loaded.', 'dahu-composite' ),
					'saveError'     => __( 'The kit could not be added to the order.', 'dahu-composite' ),
					'product'       => __( 'Product', 'dahu-composite' ),
					'sku'           => __( 'SKU', 'dahu-composite' ),
				),
			)
		);

		wp_enqueue_style(
			'dahu-composite-admin-order',
			DAHU_COMPOSITE_URL . 'assets/css/dahu-composite-admin-order.css',
			array(),
			DAHU_COMPOSITE_VERSION
		);
	}

	/**
	 * Order being displayed, for display_configure_button() (same approach as
	 * WCCP: WC_Order_Item::get_order() is costly row by row).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function set_order( $order ) {
		self::$order = $order instanceof WC_Order ? $order : null;
	}

	/**
	 * “Configure” button (kit not assembled yet) or “Edit” (kit assembled) under
	 * the name of a kit row, on an editable order only — like the WCCP button.
	 * Rendered server side: it comes back on its own every time WooCommerce
	 * reloads the item table.
	 *
	 * @param int           $item_id Row.
	 * @param WC_Order_Item $item    Row.
	 * @param WC_Product    $product Product.
	 */
	public static function display_configure_button( $item_id, $item, $product ) {
		if ( ! self::$order || ! self::$order->is_editable() || ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		if ( ! $product instanceof WC_Product_Dahu_Kit || 'child' === Dahu_Composite_Order::kit_role( $item ) ) {
			return;
		}
		$configured = 'container' === Dahu_Composite_Order::kit_role( $item );
		$label      = $configured ? __( 'Edit', 'dahu-composite' ) : __( 'Configure', 'dahu-composite' );
		?>
		<div class="configure_order_item dahu-kit-configure-item">
			<button type="button" class="button js-dahu-kit-configure" data-title="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?></button>
		</div>
		<?php
	}

	/**
	 * Template of the window (wc-backbone-modal, like WCCP).
	 */
	public static function print_modal_template() {
		if ( ! wp_script_is( 'dahu-composite-admin-order' ) ) {
			return;
		}
		?>
		<script type="text/template" id="tmpl-dahu-kit-modal">
			<div class="wc-backbone-modal dahu-kit-modal">
				<div class="wc-backbone-modal-content">
					<section class="wc-backbone-modal-main" role="main">
						<header class="wc-backbone-modal-header">
							<h1>{{ data.title }}</h1>
							<button class="modal-close modal-close-link dashicons dashicons-no-alt">
								<span class="screen-reader-text"><?php esc_html_e( 'Close', 'dahu-composite' ); ?></span>
							</button>
						</header>
						<article>
							<form action="" method="post" class="dahu-kit-modal__form" onsubmit="return false;"></form>
						</article>
						<footer>
							<div class="inner dahu-kit-modal__footer">
								<button id="btn-ok" type="button" class="button button-primary button-large" disabled><?php esc_html_e( 'Done', 'dahu-composite' ); ?></button>
								<span class="dahu-kit-modal__status" role="status"></span>
							</div>
						</footer>
					</section>
				</div>
			</div>
			<div class="wc-backbone-modal-backdrop modal-close"></div>
		</script>
		<?php
	}

	/*
	|--------------------------------------------------------------------------
	| AJAX
	|--------------------------------------------------------------------------
	*/

	/**
	 * Opening the window: components of the kit (as seen by the customer of the
	 * order), starting selections — the current composition of the row, else the
	 * default choices of the kit — and state of the engine.
	 */
	public static function ajax_get_kit_schema() {
		$context = self::request_context();
		$kit     = $context['kit'];
		$item    = $context['item'];

		$schema     = Dahu_Composite_Engine::schema( $kit );
		$configured = 'container' === Dahu_Composite_Order::kit_role( $item );
		$selections = $configured ? self::current_selections( $kit, $item ) : json_decode( wp_json_encode( $schema['defaults'] ), true );

		wp_send_json_success(
			array(
				'kit_name'   => $kit->get_name(),
				'configured' => $configured,
				'components' => self::admin_components( $kit, $schema ),
				'state'      => self::state( $kit, $selections, $item->get_quantity() ),
			)
		);
	}

	/**
	 * Recomputation on every change in the window: corrected selections (cascade
	 * of the scenarios, single variant chosen automatically), hidden components
	 * and options, missing components, errors — the same state as /state for the
	 * configurator of the front end.
	 */
	public static function ajax_kit_state() {
		$context = self::request_context();
		wp_send_json_success( self::state( $context['kit'], self::posted_selections(), $context['item']->get_quantity() ) );
	}

	/**
	 * “Done”: the configuration is revalidated, the kit is added to the order
	 * (rows and metas of a kit that went through the cart), then the old row is
	 * removed — the kit product alone, or every row of the kit already assembled
	 * (reconfiguration). Taxes and totals recomputed by WooCommerce. Returns the
	 * item table and the notes, as WooCommerce does after an item is added.
	 */
	public static function ajax_configure_kit_line() {
		$context  = self::request_context();
		$order    = $context['order'];
		$kit      = $context['kit'];
		$item     = $context['item'];
		$quantity = max( 1, (int) $item->get_quantity() );

		if ( ! $order->is_editable() ) {
			wp_send_json_error( array( 'message' => __( 'This order can no longer be edited.', 'dahu-composite' ) ) );
		}

		$built = Dahu_Composite_Cart::build_kit_lines( $kit, self::posted_selections(), $quantity, array( 'ignore_stock' => true ) );
		if ( is_wp_error( $built ) ) {
			wp_send_json_error( array( 'message' => self::error_message( $built ) ) );
		}

		// Rows to replace, noted before the addition.
		$old_items = Dahu_Composite_Order::kit_items( $order, $item );

		$container_id = Dahu_Composite_Order::add_kit_to_order( $order, $kit, $built, $quantity );
		if ( is_wp_error( $container_id ) ) {
			wp_send_json_error( array( 'message' => $container_id->get_error_message() ) );
		}

		// Removal of the old configuration. Stock already reduced for a
		// removed row: given back, as when an item is deleted by
		// WooCommerce (WC_AJAX::remove_order_item()).
		if ( ! function_exists( 'wc_maybe_adjust_line_item_product_stock' ) ) {
			include_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
		}
		$removed = array();
		foreach ( $old_items as $old_id => $old_item ) {
			$changed = wc_maybe_adjust_line_item_product_stock( $old_item, 0 );
			if ( $changed && ! is_wp_error( $changed ) ) {
				/* translators: 1: item, 2: stock before → after */
				$removed[] = sprintf( __( '%1$s (stock adjusted: %2$s)', 'dahu-composite' ), $old_item->get_name(), $changed['from'] . '&rarr;' . $changed['to'] );
			}
			$order->remove_item( $old_id );
		}

		self::calculate_totals( $order );

		$added = array();
		foreach ( Dahu_Composite_Order::kit_items( $order, $order->get_item( $container_id, false ) ) as $new_item ) {
			if ( 'child' === Dahu_Composite_Order::kit_role( $new_item ) ) {
				$component = $kit->get_kit_component( (string) $new_item->get_meta( '_composite_item', true ) );
				$added[]   = ( $component ? $component['title'] . ' : ' : '' ) . $new_item->get_name();
			}
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: kit, 2: components */
				__( 'Kit “%1$s” configured from the order screen: %2$s.', 'dahu-composite' ),
				$kit->get_name(),
				implode( ', ', $added )
			) . ( $removed ? ' ' . sprintf( /* translators: %s: rows removed */ __( 'Lines removed: %s.', 'dahu-composite' ), implode( ', ', $removed ) ) : '' ),
			false,
			true
		);

		// Order read again: row metas up to date for the rendering.
		$order = wc_get_order( $order->get_id() );

		ob_start();
		include WC_ABSPATH . 'includes/admin/meta-boxes/views/html-order-items.php';
		$items_html = ob_get_clean();

		ob_start();
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		include WC_ABSPATH . 'includes/admin/meta-boxes/views/html-order-notes.php';
		$notes_html = ob_get_clean();

		wp_send_json_success(
			array(
				'composition_id' => (string) $order->get_item( $container_id )->get_meta( Dahu_Composite_Composition::META_ID, true ),
				'html'           => $items_html,
				'notes_html'     => $notes_html,
			)
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Request
	|--------------------------------------------------------------------------
	*/

	/**
	 * Shared checks (nonce, right, order, kit row) and the customer of the order
	 * applied to the engine. Answers with an error and stops otherwise.
	 *
	 * Right: edit_shop_orders, like the AJAX actions of WooCommerce's order
	 * screen (WC_AJAX::add_order_item(), remove_order_item()). The per-order
	 * capability (edit_shop_order + ID) does not resolve reliably with HPOS (no
	 * WordPress post behind the order).
	 *
	 * @return array { order: WC_Order, item: WC_Order_Item_Product, kit: WC_Product_Dahu_Kit }
	 */
	private static function request_context() {
		if ( ! check_ajax_referer( self::NONCE, 'security', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired: reload the page.', 'dahu-composite' ) ), 403 );
		}
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Action not allowed.', 'dahu-composite' ) ), 403 );
		}

		$order = wc_get_order( isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0 );
		$item  = $order ? $order->get_item( isset( $_POST['item_id'] ) ? absint( wp_unslash( $_POST['item_id'] ) ) : 0 ) : null;
		if ( ! $order instanceof WC_Order || ! $item instanceof WC_Order_Item_Product || (int) $item->get_order_id() !== (int) $order->get_id() ) {
			wp_send_json_error( array( 'message' => __( 'Order or line item not found.', 'dahu-composite' ) ), 404 );
		}

		$kit = $item->get_product();
		if ( ! $kit instanceof WC_Product_Dahu_Kit || 'child' === Dahu_Composite_Order::kit_role( $item ) ) {
			wp_send_json_error( array( 'message' => __( 'This line item is not a kit.', 'dahu-composite' ) ), 400 );
		}

		// Customer: the one chosen on the screen (“Customer” field, sent by the
		// script, even before it is saved), else the one of the order.
		// No customer = prices and options of a visitor.
		$customer_id = isset( $_POST['customer_user'] ) ? absint( wp_unslash( $_POST['customer_user'] ) ) : (int) $order->get_customer_id();
		$customer    = $customer_id ? get_user_by( 'id', $customer_id ) : false;
		Dahu_Composite_Pricing::set_customer( $customer ? $customer : false );
		Dahu_Composite_Customer_Access::set_simulated_customer( $customer ? (int) $customer->ID : 0 );

		return array(
			'order' => $order,
			'item'  => $item,
			'kit'   => $kit,
		);
	}

	/**
	 * Selections sent by the script (JSON: the booleans of the bundle items stay
	 * booleans there). Normalised afterwards by the engine.
	 *
	 * @return array
	 */
	private static function posted_selections() {
		$raw = isset( $_POST['selections'] ) ? json_decode( wp_unslash( (string) $_POST['selections'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised by Dahu_Composite_Engine::normalize_selections().
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * State of the engine for the window — Dahu_Composite_Engine::state(),
	 * without the stock errors.
	 *
	 * @param WC_Product_Dahu_Kit $kit          Kit.
	 * @param mixed               $raw          Raw selections.
	 * @param int                 $kit_quantity Number of kits.
	 * @return array
	 */
	private static function state( WC_Product_Dahu_Kit $kit, $raw, $kit_quantity ) {
		$state = Dahu_Composite_Engine::without_stock_errors( Dahu_Composite_Engine::state( $kit, $raw, max( 1, (int) $kit_quantity ) ) );
		return array(
			'selections'        => $state['selections'],
			'valid'             => $state['valid'],
			'errors'            => $state['errors'],
			'missing'           => $state['missing'],
			'blocked'           => $state['blocked'],
			'hidden_components' => $state['hidden_components'],
			'hidden_options'    => $state['hidden_options'],
		);
	}

	/**
	 * Message of a refused configuration, with the detail from the engine.
	 *
	 * @param WP_Error $error Error from build_kit_lines().
	 * @return string
	 */
	private static function error_message( WP_Error $error ) {
		$message = $error->get_error_message();
		$data    = $error->get_error_data();
		if ( ! empty( $data['validation']['errors'] ) ) {
			$message .= ' ' . implode( ' ', wp_list_pluck( $data['validation']['errors'], 'message' ) );
		}
		if ( ! empty( $data['validation']['blocked'] ) ) {
			$message .= ' ' . implode( ' ', array_filter( wp_list_pluck( $data['validation']['blocked'], 'message' ) ) );
		}
		return trim( $message );
	}

	/**
	 * Taxes (address entered on the screen, even when it is not saved yet) then
	 * totals, by WooCommerce — as after an item is deleted
	 * (WC_AJAX::remove_order_item()) or a WCCP composite is modified. Saves the
	 * order.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function calculate_totals( WC_Order $order ) {
		if ( isset( $_POST['country'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in request_context().
			$order->calculate_taxes(
				array(
					'country'  => isset( $_POST['country'] ) ? wc_strtoupper( wc_clean( wp_unslash( $_POST['country'] ) ) ) : '',
					'state'    => isset( $_POST['state'] ) ? wc_strtoupper( wc_clean( wp_unslash( $_POST['state'] ) ) ) : '',
					'postcode' => isset( $_POST['postcode'] ) ? wc_strtoupper( wc_clean( wp_unslash( $_POST['postcode'] ) ) ) : '',
					'city'     => isset( $_POST['city'] ) ? wc_strtoupper( wc_clean( wp_unslash( $_POST['city'] ) ) ) : '',
				)
			);
			$order->calculate_totals( false );
		} else {
			$order->calculate_totals();
		}
		$order->save();
	}

	/*
	|--------------------------------------------------------------------------
	| Data of the window
	|--------------------------------------------------------------------------
	*/

	/**
	 * Components of the kit for the window, from the schema of the front end
	 * (Dahu_Composite_Engine::schema(), reserved options already removed for the
	 * customer): what is needed for the choice lists and the preview.
	 *
	 * @param WC_Product_Dahu_Kit $kit    Kit.
	 * @param array               $schema Schema.
	 * @return array[]
	 */
	private static function admin_components( WC_Product_Dahu_Kit $kit, array $schema ) {
		$out = array();
		foreach ( $schema['components'] as $component ) {
			$is_path = 'path' === $component['component_type'];
			$options = array();

			foreach ( $component['options'] as $option ) {
				if ( $is_path ) {
					// “link” card: a pointer elsewhere, never a choice.
					if ( ! empty( $option['link'] ) ) {
						continue;
					}
					$options[] = array(
						'value'       => (string) $option['id'],
						'name'        => (string) $option['name'],
						'description' => (string) $option['description'],
						'image'       => $option['image'],
						'coming_soon' => ! empty( $option['coming_soon'] ),
					);
					continue;
				}

				$product = wc_get_product( $option['product_id'] );
				if ( ! $product ) {
					continue;
				}
				$entry = array_merge(
					array(
						'product_id'  => (int) $option['product_id'],
						'name'        => (string) $option['name'],
						'description' => (string) $option['description'],
						// With no image: the WooCommerce placeholder, like
						// the WCCP preview.
						'image'       => $option['image'] ? $option['image'] : wc_placeholder_img_src( 'woocommerce_thumbnail' ),
						'variations'  => array(),
						'bundle'      => array(),
					),
					self::product_facts( $product )
				);

				foreach ( $option['variations'] as $variation_data ) {
					$variation = wc_get_product( $variation_data['variation_id'] );
					if ( ! $variation ) {
						continue;
					}
					$image_id              = (int) $variation->get_image_id( 'edit' );
					$description           = wp_kses_post( wpautop( $variation->get_description() ) );
					$entry['variations'][] = array_merge(
						array(
							'variation_id' => (int) $variation_data['variation_id'],
							'name'         => (string) $variation_data['name'],
							'image'        => $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : null,
							'description'  => '' !== trim( wp_strip_all_tags( $description ) ) ? $description : '',
						),
						self::product_facts( $variation )
					);
				}

				// Bundle items to settle in the window: optional ones, or ones with
				// several possible variants (a locked charger: nothing to
				// choose, it does not appear).
				foreach ( isset( $option['bundle_items'] ) ? $option['bundle_items'] : array() as $bundled ) {
					if ( empty( $bundled['optional'] ) && count( $bundled['variations'] ) < 2 ) {
						continue;
					}
					$entry['bundle'][] = array(
						'bundled_item_id' => (int) $bundled['bundled_item_id'],
						'title'           => (string) $bundled['title'],
						'optional'        => ! empty( $bundled['optional'] ),
						'variations'      => array_values(
							array_filter(
								array_map(
									static function ( $variation ) {
										$product = wc_get_product( $variation['variation_id'] );
										return $product ? array_merge(
											array(
												'variation_id' => (int) $variation['variation_id'],
												'name'         => (string) $variation['name'],
											),
											self::product_facts( $product )
										) : null;
									},
									$bundled['variations']
								)
							)
						),
					);
				}

				$options[] = $entry;
			}

			$out[] = array(
				'id'                => $component['id'],
				'title'             => $component['title'],
				'type'              => $is_path ? 'path' : ( 'multiple' === $component['selection_mode'] ? 'multiple' : 'single' ),
				'optional'          => (bool) $component['optional'],
				'quantity_min'      => (int) $component['quantity_min'],
				'quantity_max'      => (int) $component['quantity_max'],
				'quantity_editable' => ! empty( $component['quantity_editable'] ),
				'options'           => $options,
			);
		}
		return $out;
	}

	/**
	 * Real name, SKU and stock of a product or a variation, for the preview.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private static function product_facts( WC_Product $product ) {
		$status   = $product->get_stock_status();
		$statuses = wc_get_product_stock_status_options();
		$label    = isset( $statuses[ $status ] ) ? $statuses[ $status ] : $status;
		if ( $product->managing_stock() ) {
			$label .= ' (' . wc_stock_amount( $product->get_stock_quantity() ) . ')';
		}
		return array(
			'real_name'    => wp_strip_all_tags( $product->get_name() ),
			'sku'          => (string) $product->get_sku(),
			'stock_status' => $status,
			'stock_label'  => $label,
		);
	}

	/**
	 * Selections of a kit row already assembled, read back from the structured
	 * shape of the composition identifier (_dahu_composition); falls back to
	 * _composite_data (older order: product components only, branch to be chosen
	 * again).
	 *
	 * @param WC_Product_Dahu_Kit   $kit  Kit.
	 * @param WC_Order_Item_Product $item Container row.
	 * @return array Raw selections (format of the API).
	 */
	private static function current_selections( WC_Product_Dahu_Kit $kit, WC_Order_Item_Product $item ) {
		$selections  = array();
		$composition = $item->get_meta( Dahu_Composite_Composition::META_DATA, true );

		if ( is_array( $composition ) && ! empty( $composition['components'] ) ) {
			foreach ( $composition['components'] as $entry ) {
				$cid = isset( $entry['component_id'] ) ? (string) $entry['component_id'] : '';
				if ( '' === $cid || ! empty( $entry['empty'] ) ) {
					continue;
				}
				if ( isset( $entry['option_id'] ) ) {
					$selections[ $cid ] = array( 'option_id' => (string) $entry['option_id'] );
					continue;
				}
				$items = array();
				foreach ( isset( $entry['items'] ) ? (array) $entry['items'] : array() as $element ) {
					$bundle = array();
					foreach ( isset( $element['bundle'] ) ? (array) $element['bundle'] : array() as $bundled ) {
						$bundle[ (int) $bundled['bundled_item_id'] ] = array(
							'selected'     => true,
							'quantity'     => (int) $bundled['quantity'],
							'variation_id' => (int) $bundled['variation_id'],
						);
					}
					$items[] = array(
						'product_id'   => (int) $element['product_id'],
						'variation_id' => (int) $element['variation_id'],
						'quantity'     => (int) $element['quantity'],
						'attributes'   => isset( $element['attributes'] ) ? (array) $element['attributes'] : array(),
						'bundle'       => $bundle,
					);
				}
				if ( $items ) {
					$component          = $kit->get_kit_component( $cid );
					$selections[ $cid ] = ( $component && Dahu_Composite_Engine::is_multiple( $component ) ) ? $items : $items[0];
				}
			}
			return $selections;
		}

		$data = $item->get_meta( '_composite_data', true );
		foreach ( is_array( $data ) ? $data : array() as $cid => $entry ) {
			$elements = ! empty( $entry['dahu_items'] ) ? (array) $entry['dahu_items'] : ( ! empty( $entry['product_id'] ) ? array( $entry ) : array() );
			$items    = array();
			foreach ( $elements as $element ) {
				$items[] = array(
					'product_id'   => (int) $element['product_id'],
					'variation_id' => (int) $element['variation_id'],
					'quantity'     => (int) $element['quantity'],
				);
			}
			if ( $items ) {
				$component          = $kit->get_kit_component( (string) $cid );
				$selections[ $cid ] = ( $component && Dahu_Composite_Engine::is_multiple( $component ) ) ? $items : $items[0];
			}
		}
		return $selections;
	}
}
