<?php
/**
 * Core of the engine: normalisation of the selections, validation, schema and
 * quote (prices + weight). All the business logic goes through here; the REST
 * API, the cart and the front end only call these methods.
 *
 * Format of a selection (API input):
 *   selections[component_id] = [
 *     'product_id'   => int,
 *     'variation_id' => int|null,          // mandatory for a variable product
 *     'quantity'     => int,               // default: quantity_min of the component
 *     'attributes'   => [ 'attribute_x' => 'value' ],  // only when the variation has “Any” attributes
 *     'bundle'       => [ bundled_item_id => ['selected' => bool, 'quantity' => int, 'variation_id' => int] ],
 *   ]
 *
 * Multiple-choice component (selection_mode “multiple”): the selection is a
 * LIST of those items, one per chosen option (a product at most once), each with
 * its variant, its attributes and its bundle items:
 *   selections[component_id] = [ [ 'product_id' => …, … ], [ … ] ]
 * Every item produces its own row (price, cart, order). A radio component keeps
 * exactly the format and the behaviour it had before.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Engine {

	/**
	 * Fetches a kit by ID, or null.
	 *
	 * @param int $product_id Product.
	 * @return WC_Product_Dahu_Kit|null
	 */
	public static function get_kit( $product_id ) {
		$product = wc_get_product( absint( $product_id ) );
		return ( $product instanceof WC_Product_Dahu_Kit ) ? $product : null;
	}

	/**
	 * Is a component a plain branch (with no product)?
	 *
	 * @param array $component Normalised component.
	 * @return bool
	 */
	public static function is_path( array $component ) {
		return isset( $component['component_type'] ) && 'path' === $component['component_type'];
	}

	/**
	 * Multiple-choice component: several options selectable together. Never a
	 * branch.
	 *
	 * @param array $component Normalised component.
	 * @return bool
	 */
	public static function is_multiple( array $component ) {
		return ! self::is_path( $component ) && isset( $component['selection_mode'] ) && 'multiple' === $component['selection_mode'];
	}

	/**
	 * Items of a selection (see Dahu_Composite_Scenarios::selection_items()).
	 *
	 * @param mixed $selection Selection of a component, or null.
	 * @return array[]
	 */
	public static function selection_items( $selection ) {
		return Dahu_Composite_Scenarios::selection_items( $selection );
	}

	/**
	 * Option of a “path” component by identifier.
	 *
	 * @param array  $component Component.
	 * @param string $option_id Identifier.
	 * @return array|null
	 */
	public static function path_option( array $component, $option_id ) {
		foreach ( $component['options'] as $option ) {
			if ( isset( $option['id'] ) && (string) $option['id'] === (string) $option_id ) {
				return $option;
			}
		}
		return null;
	}

	/*
	|--------------------------------------------------------------------------
	| Selections
	|--------------------------------------------------------------------------
	*/

	/**
	 * Normalises raw selections: keeps only the known components, converts the
	 * types. No business rule here (see validate()).
	 *
	 * @param WC_Product_Dahu_Kit $kit Kit.
	 * @param mixed               $raw Raw selections.
	 * @return array
	 */
	public static function normalize_selections( WC_Product_Dahu_Kit $kit, $raw ) {
		$raw        = is_array( $raw ) ? $raw : array();
		$selections = array();

		foreach ( $kit->get_kit_components() as $component ) {
			$cid = $component['id'];
			if ( empty( $raw[ $cid ] ) || ! is_array( $raw[ $cid ] ) ) {
				continue;
			}
			$item = $raw[ $cid ];

			// “path” component: a plain branch, the selection is an
			// option identifier, with no product and no quantity.
			if ( self::is_path( $component ) ) {
				$oid = isset( $item['option_id'] ) ? sanitize_title( (string) $item['option_id'] ) : '';
				if ( '' === $oid ) {
					continue;
				}
				$selections[ $cid ] = array(
					'option_id'    => $oid,
					'product_id'   => 0,
					'variation_id' => null,
					'quantity'     => 0,
					'attributes'   => array(),
					'bundle'       => array(),
				);
				continue;
			}

			// Multiple choice: a list of items (a single item is accepted),
			// a product at most once (the last one wins).
			if ( self::is_multiple( $component ) ) {
				$raw_items = ( isset( $item['product_id'] ) || isset( $item['option_id'] ) ) ? array( $item ) : $item;
				$items     = array();
				foreach ( $raw_items as $raw_item ) {
					$normalized = is_array( $raw_item ) ? self::normalize_product_item( $component, $raw_item, true ) : null;
					if ( $normalized ) {
						$items[ $normalized['product_id'] ] = $normalized;
					}
				}
				if ( $items ) {
					$selections[ $cid ] = array_values( $items );
				}
				continue;
			}

			$normalized = self::normalize_product_item( $component, $item, false );
			if ( $normalized ) {
				$selections[ $cid ] = $normalized;
			}
		}

		return $selections;
	}

	/**
	 * Normalised product item (a radio component, or one item of a multiple
	 * component).
	 *
	 * @param array $component Component.
	 * @param array $item      Raw item.
	 * @param bool  $multiple  Item of a multiple component: quantity 1 by
	 *                         default (a multiple component is generally
	 *                         optional, quantity_min = 0).
	 * @return array|null
	 */
	private static function normalize_product_item( array $component, array $item, $multiple ) {
		$pid = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
		if ( ! $pid ) {
			return null;
		}

		$attributes = array();
		if ( ! empty( $item['attributes'] ) && is_array( $item['attributes'] ) ) {
			foreach ( $item['attributes'] as $key => $value ) {
				$key = sanitize_title( (string) $key );
				if ( 0 !== strpos( $key, 'attribute_' ) ) {
					$key = 'attribute_' . $key;
				}
				$attributes[ $key ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}

		$bundle = array();
		if ( ! empty( $item['bundle'] ) && is_array( $item['bundle'] ) ) {
			foreach ( $item['bundle'] as $bundled_item_id => $conf ) {
				if ( ! is_array( $conf ) ) {
					continue;
				}
				$bundle[ absint( $bundled_item_id ) ] = array(
					'selected'     => ! empty( $conf['selected'] ),
					'quantity'     => isset( $conf['quantity'] ) ? absint( $conf['quantity'] ) : null,
					'variation_id' => ! empty( $conf['variation_id'] ) ? absint( $conf['variation_id'] ) : 0,
				);
			}
		}

		$default_quantity = $multiple ? max( 1, (int) $component['quantity_min'] ) : (int) $component['quantity_min'];
		return array(
			'product_id'   => $pid,
			'variation_id' => ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : null,
			'quantity'     => isset( $item['quantity'] ) && '' !== $item['quantity'] ? (int) $item['quantity'] : $default_quantity,
			'attributes'   => $attributes,
			'bundle'       => $bundle,
		);
	}

	/**
	 * Is an option hidden? Grammar of the scenario values: “123” (the whole
	 * product) or “123:456” (one precise variant).
	 *
	 * @param int      $product_id     Product.
	 * @param int|null $variation_id   Variant, or null to test the product.
	 * @param string[] $hidden_options Hidden values of the component.
	 * @return bool
	 */
	private static function option_is_hidden( $product_id, $variation_id, array $hidden_options ) {
		if ( in_array( (string) $product_id, $hidden_options, true ) ) {
			return true;
		}
		return $variation_id && in_array( $product_id . ':' . $variation_id, $hidden_options, true );
	}

	/**
	 * Removes the selections made invalid by a hiding rule: hidden component,
	 * hidden product, hidden variant.
	 *
	 * @param array $selections Normalised selections, MODIFIED in place.
	 * @param array $evaluation Result of evaluate_scenarios().
	 * @return bool True when something was removed.
	 */
	private static function prune_selections( array &$selections, array $evaluation ) {
		$changed           = false;
		$hidden_components = array_flip( $evaluation['hidden_components'] );

		foreach ( $selections as $cid => $selection ) {
			if ( isset( $hidden_components[ $cid ] ) ) {
				unset( $selections[ $cid ] );
				$changed = true;
				continue;
			}

			$hidden = isset( $evaluation['hidden_options'][ $cid ] ) ? $evaluation['hidden_options'][ $cid ] : array();

			// Multiple choice: every item is handled like a radio
			// selection; an emptied list disappears.
			if ( ! isset( $selection['product_id'] ) && ! isset( $selection['option_id'] ) ) {
				$kept = array();
				foreach ( self::selection_items( $selection ) as $item ) {
					if ( self::option_is_hidden( $item['product_id'], null, $hidden ) ) {
						$changed = true;
						continue;
					}
					if ( self::option_is_hidden( $item['product_id'], $item['variation_id'], $hidden ) ) {
						$item['variation_id'] = null;
						$changed              = true;
					}
					$kept[] = $item;
				}
				if ( $kept ) {
					$selections[ $cid ] = $kept;
				} else {
					unset( $selections[ $cid ] );
					$changed = true;
				}
				continue;
			}

			// Branch: the selection is an option identifier.
			if ( ! empty( $selection['option_id'] ) ) {
				if ( in_array( (string) $selection['option_id'], $hidden, true ) ) {
					unset( $selections[ $cid ] );
					$changed = true;
				}
				continue;
			}

			if ( self::option_is_hidden( $selection['product_id'], null, $hidden ) ) {
				unset( $selections[ $cid ] );
				$changed = true;
			} elseif ( self::option_is_hidden( $selection['product_id'], $selection['variation_id'], $hidden ) ) {
				// The product stays selectable, only the variant falls away.
				$selections[ $cid ]['variation_id'] = null;
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * Variable product with only one POSSIBLE variant — visible (not hidden by a
	 * scenario) AND in stock: it is chosen automatically, the customer has nothing
	 * to settle. The same rule as the list on the front end
	 * (renderVariationSelect()), which shows neither the hidden variants nor the
	 * ones out of stock.
	 *
	 * @param WC_Product_Dahu_Kit $kit        Kit.
	 * @param array               $selections Normalised selections, MODIFIED in place.
	 * @param array               $evaluation Result of evaluate_scenarios().
	 * @return bool True when a selection was completed.
	 */
	private static function autoselect_variations( WC_Product_Dahu_Kit $kit, array &$selections, array $evaluation ) {
		$changed = false;

		foreach ( $kit->get_kit_components() as $component ) {
			$cid = $component['id'];
			if ( self::is_path( $component ) || empty( $selections[ $cid ] ) ) {
				continue;
			}
			$hidden = isset( $evaluation['hidden_options'][ $cid ] ) ? $evaluation['hidden_options'][ $cid ] : array();

			// Multiple choice: the same rule, item by item.
			if ( ! isset( $selections[ $cid ]['product_id'] ) ) {
				foreach ( $selections[ $cid ] as $index => $item ) {
					$variation_id = is_array( $item ) ? self::single_possible_variation( $component, $item, $hidden ) : 0;
					if ( $variation_id ) {
						$selections[ $cid ][ $index ]['variation_id'] = $variation_id;
						$changed = true;
					}
				}
				continue;
			}

			$variation_id = self::single_possible_variation( $component, $selections[ $cid ], $hidden );
			if ( $variation_id ) {
				$selections[ $cid ]['variation_id'] = $variation_id;
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * Variant to choose automatically for an item (autoselect_variations()): its
	 * single possible variant, or 0.
	 *
	 * @param array $component Component.
	 * @param array $selection Item (product).
	 * @param array $hidden    Hidden options of the component.
	 * @return int
	 */
	private static function single_possible_variation( array $component, array $selection, array $hidden ) {
		if ( empty( $selection['product_id'] ) || ! empty( $selection['variation_id'] ) ) {
			return 0;
		}

		// The selection must bear on an option actually offered.
		$offered = false;
		foreach ( $component['options'] as $option ) {
			if ( (int) $option['product_id'] === (int) $selection['product_id'] ) {
				$offered = true;
			}
		}
		if ( ! $offered ) {
			return 0;
		}

		$product = wc_get_product( $selection['product_id'] );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return 0;
		}

		$visible = array();
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->variation_is_visible() ) {
				continue;
			}
			if ( self::option_is_hidden( $selection['product_id'], $variation_id, $hidden ) ) {
				continue;
			}
			if ( ! $variation->is_in_stock() ) {
				continue;
			}
			$visible[] = $variation;
		}

		return 1 === count( $visible ) ? (int) $visible[0]->get_id() : 0;
	}

	/**
	 * Cleanup cascade. The hiding rules cascade: removing a selection may
	 * deactivate a scenario, and therefore unhide a component, and therefore hide
	 * another one. It has to be re-evaluated up to the fixed point.
	 *
	 * This loop used to live on the JavaScript side, at the cost of a full REST
	 * round trip per turn — and of a second reading of the hiding rules outside
	 * the engine. Here, one turn only costs the time of evaluate(), a pure
	 * function with no database access.
	 *
	 * @param WC_Product_Dahu_Kit $kit        Kit.
	 * @param array               $selections Normalised selections.
	 * @return array {array $selections, bool $corrected}
	 */
	public static function resolve_selections( WC_Product_Dahu_Kit $kit, array $selections ) {
		$corrected = false;

		// The same safety net as the old client-side loop: a
		// pathological configuration must not run indefinitely.
		for ( $pass = 0; $pass < 5; $pass++ ) {
			$evaluation = $kit->evaluate_scenarios( $selections );

			// Both passes always run: a hidden variant that was
			// removed may leave a single variant to choose automatically.
			$pruned    = self::prune_selections( $selections, $evaluation );
			$completed = self::autoselect_variations( $kit, $selections, $evaluation );

			if ( ! $pruned && ! $completed ) {
				break;
			}
			$corrected = true;
		}

		return array( $selections, $corrected );
	}

	/**
	 * Selections in the format a client expects: the internal normalised format
	 * carries keys that make no sense for a branch (product_id at 0, quantity at
	 * 0).
	 *
	 * @param array $selections Normalised selections.
	 * @return object
	 */
	private static function export_selections( array $selections ) {
		$out = array();

		foreach ( $selections as $cid => $selection ) {
			if ( ! empty( $selection['option_id'] ) ) {
				$out[ $cid ] = array( 'option_id' => $selection['option_id'] );
				continue;
			}
			// Multiple choice: a list (JSON array), one item per option.
			if ( ! isset( $selection['product_id'] ) ) {
				$out[ $cid ] = array_map( array( __CLASS__, 'export_item' ), self::selection_items( $selection ) );
				continue;
			}
			$out[ $cid ] = self::export_item( $selection );
		}

		// Cast to an object: an empty array would serialise as [] and not as {}.
		return (object) $out;
	}

	/**
	 * @param array $selection Normalised product item.
	 * @return array
	 */
	private static function export_item( array $selection ) {
		$item = array(
			'product_id' => (int) $selection['product_id'],
			'quantity'   => (int) $selection['quantity'],
		);
		if ( ! empty( $selection['variation_id'] ) ) {
			$item['variation_id'] = (int) $selection['variation_id'];
		}
		if ( ! empty( $selection['attributes'] ) ) {
			$item['attributes'] = $selection['attributes'];
		}
		if ( ! empty( $selection['bundle'] ) ) {
			$item['bundle'] = $selection['bundle'];
		}
		return $item;
	}

	/**
	 * Complete state of a configuration in ONE pass: corrected selections,
	 * validity, hiding, prices, weight and rows.
	 *
	 * This is the path the configurator takes for every interaction. validate()
	 * and quote() remain available separately for external use, but are no longer
	 * called by the front end.
	 *
	 * @param WC_Product_Dahu_Kit $kit          Kit.
	 * @param mixed               $raw          RAW selections (not normalised).
	 * @param int                 $kit_quantity Number of kits.
	 * @param mixed               $decided      Components already decided on the
	 *                                          client side (list of identifiers),
	 *                                          for the display projection.
	 * @return array
	 */
	public static function state( WC_Product_Dahu_Kit $kit, $raw, $kit_quantity = 1, $decided = array() ) {
		$kit_quantity = max( 1, (int) $kit_quantity );

		$selections = self::normalize_selections( $kit, $raw );
		list( $selections, $corrected ) = self::resolve_selections( $kit, $selections );

		$validation = self::validate( $kit, $selections, $kit_quantity );
		$quote      = self::quote( $kit, $selections, $kit_quantity );
		// The same cleaning as the component identifiers (Kit_Config).
		$decided = is_array( $decided ) ? array_map(
			static function ( $id ) {
				return sanitize_title( (string) $id );
			},
			array_filter( $decided, 'is_scalar' )
		) : array();

		// No key in common between the two: validate() returns validity,
		// errors and hiding; quote() returns prices, weight and rows.
		return array_merge(
			$validation,
			$quote,
			array(
				'selections'                => self::export_selections( $selections ),
				'selections_corrected'      => $corrected,
				// The journey “generally” expected, for the summary and
				// the step counter only: real selections
				// + representative value of every component not decided.
				'display_hidden_components' => self::display_hidden_components( $kit, $selections, $decided ),
				// Configuration serial of the choices under way, even
				// incomplete ones; '' with no choice at all. Displayed by the
				// administration simulator.
				'composition_id'            => Dahu_Composite_Composition::serial( $kit, $selections ),
			)
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Validation
	|--------------------------------------------------------------------------
	*/

	/**
	 * Validates a configuration.
	 *
	 * @param WC_Product_Dahu_Kit $kit          Kit.
	 * @param array               $selections   NORMALISED selections.
	 * @param int                 $kit_quantity Number of kits (stock check).
	 * @return array {
	 *   @type bool     $valid             Configuration complete and free of errors.
	 *   @type array[]  $errors            [ ['code', 'component', 'message'] ].
	 *   @type string[] $missing           Visible mandatory components left unfilled.
	 *   @type string[] $hidden_components
	 *   @type array    $hidden_options
	 * }
	 */
	public static function validate( WC_Product_Dahu_Kit $kit, array $selections, $kit_quantity = 1 ) {
		$kit_quantity = max( 1, (int) $kit_quantity );
		$evaluation   = $kit->evaluate_scenarios( $selections );
		$hidden_comps = array_flip( $evaluation['hidden_components'] );
		$errors       = array();
		$missing      = array();

		foreach ( $kit->get_kit_components() as $component ) {
			$cid       = $component['id'];
			$selection = isset( $selections[ $cid ] ) ? $selections[ $cid ] : null;

			if ( isset( $hidden_comps[ $cid ] ) ) {
				if ( $selection ) {
					$errors[] = self::error( 'component_hidden', $cid, sprintf( /* translators: %s: component */ __( '“%s” is not available with the configuration you chose.', 'dahu-composite' ), $component['title'] ) );
				}
				continue;
			}

			if ( ! $selection ) {
				if ( ! $component['optional'] ) {
					$missing[] = $cid;
				}
				continue;
			}

			$hidden_options = isset( $evaluation['hidden_options'][ $cid ] ) ? $evaluation['hidden_options'][ $cid ] : array();

			// “path” component: no row, no price — we only check
			// that the option exists, is not hidden and is not
			// a “coming soon” dead end.
			if ( self::is_path( $component ) ) {
				$option = self::path_option( $component, $selection['option_id'] );
				if ( ! $option ) {
					$errors[] = self::error( 'invalid_option', $cid, sprintf( /* translators: %s: component */ __( 'Invalid option for “%s”.', 'dahu-composite' ), $component['title'] ) );
				} elseif ( in_array( (string) $selection['option_id'], $hidden_options, true ) ) {
					$errors[] = self::error( 'option_hidden', $cid, sprintf( /* translators: %s: component */ __( 'The option chosen for “%s” is not compatible with your earlier choices.', 'dahu-composite' ), $component['title'] ) );
				} elseif ( ! empty( $option['coming_soon'] ) ) {
					$errors[] = self::error( 'coming_soon', $cid, sprintf( /* translators: %s: option */ __( '“%s” cannot be ordered yet.', 'dahu-composite' ), $option['label'] ) );
				} elseif ( ! empty( $option['link'] ) ) {
					// “link” card: a pointer elsewhere, never a choice.
					$errors[] = self::error( 'link_only', $cid, sprintf( /* translators: %s: option */ __( '“%s” links to another page: it is not a kit choice.', 'dahu-composite' ), $option['label'] ) );
				}
				continue;
			}

			// Multiple choice: every item is validated like a radio
			// selection. An empty list = no selection (already handled above, the
			// normalisation keeps no empty list).
			if ( ! isset( $selection['product_id'] ) ) {
				$incomplete = false;
				foreach ( self::selection_items( $selection ) as $item ) {
					$item_errors = self::validate_selection( $component, $item, $hidden_options, $kit_quantity );
					if ( 'variation_required' === $item_errors ) {
						$incomplete = true;
						continue;
					}
					$errors = array_merge( $errors, $item_errors );
				}
				if ( $incomplete ) {
					$missing[] = $cid;
				}
				continue;
			}

			$line_errors    = self::validate_selection( $component, $selection, $hidden_options, $kit_quantity );

			if ( 'variation_required' === $line_errors ) {
				$missing[] = $cid;
				continue;
			}
			$errors = array_merge( $errors, $line_errors );
		}

		$blocked = isset( $evaluation['blocked'] ) ? $evaluation['blocked'] : array();

		return array(
			// A dead end (a “block” scenario) invalidates the configuration:
			// it cannot be added to the cart even when the front end is bypassed.
			'valid'             => empty( $errors ) && empty( $missing ) && empty( $blocked ),
			'errors'            => $errors,
			'missing'           => $missing,
			'blocked'           => $blocked,
			'hidden_components' => $evaluation['hidden_components'],
			'hidden_options'    => (object) $evaluation['hidden_options'],
		);
	}

	/**
	 * Result of validate() without the stock errors: a kit assembled in the order
	 * screen (after-sales, phone order) accepts a component out of stock, like the
	 * manual addition of an item by WooCommerce. The other rules (option offered,
	 * hiding, variant, quantity, dead end) stay those of the front end.
	 *
	 * @param array $validation Result of validate().
	 * @return array The same shape, with `valid` recomputed.
	 */
	public static function without_stock_errors( array $validation ) {
		$validation['errors'] = array_values(
			array_filter(
				$validation['errors'],
				static function ( $error ) {
					return ! in_array( $error['code'], array( 'out_of_stock', 'insufficient_stock' ), true );
				}
			)
		);
		$validation['valid']  = empty( $validation['errors'] ) && empty( $validation['missing'] ) && empty( $validation['blocked'] );
		return $validation;
	}

	/**
	 * @param array $component      Component.
	 * @param array $selection      Normalised selection.
	 * @param array $hidden_options Hidden options of the component.
	 * @param int   $kit_quantity   Number of kits.
	 * @return array[]|string Errors, or 'variation_required' (incomplete selection).
	 */
	private static function validate_selection( array $component, array $selection, array $hidden_options, $kit_quantity ) {
		$cid    = $component['id'];
		$title  = $component['title'];
		$pid    = $selection['product_id'];
		$vid    = $selection['variation_id'];
		$errors = array();

		$allowed = wp_list_pluck( $component['options'], 'product_id' );
		if ( ! in_array( $pid, array_map( 'absint', $allowed ), true ) ) {
			return array( self::error( 'invalid_option', $cid, sprintf( /* translators: %s: component */ __( 'Invalid option for “%s”.', 'dahu-composite' ), $title ) ) );
		}

		$product = wc_get_product( $pid );
		if ( ! $product || ! self::is_product_available( $product ) ) {
			return array( self::error( 'unavailable_option', $cid, sprintf( /* translators: %s: component */ __( 'The option chosen for “%s” is no longer available.', 'dahu-composite' ), $title ) ) );
		}

		if ( $product->is_type( 'variable' ) ) {
			if ( ! $vid ) {
				return 'variation_required';
			}
			$variation = wc_get_product( $vid );
			if ( ! $variation || ! $variation->is_type( 'variation' ) || (int) $variation->get_parent_id() !== (int) $pid || ! $variation->variation_is_visible() ) {
				return array( self::error( 'invalid_variation', $cid, sprintf( /* translators: %s: component */ __( 'Invalid variation for “%s”.', 'dahu-composite' ), $title ) ) );
			}
			$missing_attributes = self::missing_variation_attributes( $variation, $selection['attributes'] );
			if ( $missing_attributes ) {
				return array( self::error( 'variation_attributes_required', $cid, sprintf( /* translators: %s: component */ __( 'Specify the variation chosen for “%s”.', 'dahu-composite' ), $title ) ) );
			}
			$line_product = $variation;
		} else {
			if ( $vid ) {
				return array( self::error( 'invalid_variation', $cid, sprintf( /* translators: %s: component */ __( 'Invalid variation for “%s”.', 'dahu-composite' ), $title ) ) );
			}
			$line_product = $product;
		}

		if ( Dahu_Composite_Scenarios::is_option_hidden( $pid, (int) $vid, $hidden_options ) ) {
			$errors[] = self::error( 'option_hidden', $cid, sprintf( /* translators: %s: component */ __( 'The option chosen for “%s” is not compatible with your earlier choices.', 'dahu-composite' ), $title ) );
		}

		$qty = (int) $selection['quantity'];
		if ( $qty < (int) $component['quantity_min'] || $qty > (int) $component['quantity_max'] ) {
			$errors[] = self::error(
				'invalid_quantity',
				$cid,
				sprintf( /* translators: 1: component, 2: min, 3: max */ __( 'Invalid quantity for “%1$s” (between %2$d and %3$d).', 'dahu-composite' ), $title, $component['quantity_min'], $component['quantity_max'] )
			);
		}

		if ( $line_product->is_sold_individually() && max( 1, $qty ) * $kit_quantity > 1 ) {
			$errors[] = self::error( 'sold_individually', $cid, sprintf( /* translators: 1: product */ __( '“%s” can only be ordered one at a time.', 'dahu-composite' ), $line_product->get_name() ) );
		}

		if ( ! $line_product->is_purchasable() || ! $line_product->is_in_stock() ) {
			$errors[] = self::error( 'out_of_stock', $cid, sprintf( /* translators: 1: product */ __( '“%s” is out of stock.', 'dahu-composite' ), $line_product->get_name() ) );
		} elseif ( $line_product->managing_stock() && ! $line_product->has_enough_stock( max( 1, $qty ) * $kit_quantity ) ) {
			$errors[] = self::error( 'insufficient_stock', $cid, sprintf( /* translators: 1: product */ __( 'Not enough stock for “%s”.', 'dahu-composite' ), $line_product->get_name() ) );
		}

		if ( $product->is_type( 'bundle' ) ) {
			$errors = array_merge( $errors, self::validate_bundle_selection( $component, $product, $selection ) );
		}

		return $errors;
	}

	/**
	 * Light validation of the configuration of a bundle (the full validation is
	 * done by Product Bundles when it is added to the cart).
	 *
	 * @param array      $component Component.
	 * @param WC_Product $bundle    Bundle.
	 * @param array      $selection Selection.
	 * @return array[]
	 */
	private static function validate_bundle_selection( array $component, WC_Product $bundle, array $selection ) {
		$errors = array();
		if ( ! method_exists( $bundle, 'get_bundled_items' ) ) {
			return $errors;
		}
		$items = $bundle->get_bundled_items();

		foreach ( $selection['bundle'] as $bundled_item_id => $conf ) {
			if ( ! isset( $items[ $bundled_item_id ] ) ) {
				$errors[] = self::error( 'invalid_bundle_item', $component['id'], __( 'Invalid bundle item.', 'dahu-composite' ) );
			}
		}

		foreach ( $items as $bundled_item_id => $bundled_item ) {
			$conf     = isset( $selection['bundle'][ $bundled_item_id ] ) ? $selection['bundle'][ $bundled_item_id ] : null;
			$included = ! $bundled_item->is_optional() || ( $conf && $conf['selected'] );
			if ( ! $included ) {
				continue;
			}
			$item_product = $bundled_item->get_product();
			if ( $item_product && $item_product->is_type( 'variable' ) && ! self::resolve_bundled_variation( $bundled_item, $conf ) ) {
				/* translators: %s: item of the bundle */
				$errors[] = self::error( 'bundle_variation_required', $component['id'], sprintf( __( 'Choose a variation for “%s”.', 'dahu-composite' ), $bundled_item->get_raw_title( true ) ) );
			}
		}

		return $errors;
	}

	/**
	 * Representative values of every component, for the display projection
	 * (Dahu_Composite_Scenarios::project()) — never used as a real selection.
	 *  - the configured default (default_option_id / default_product_id) first;
	 *  - mandatory component: then its other options, in order (excluding “coming
	 *    soon” and unavailable products), so that a component with no default, or
	 *    whose default is hidden, still gets one;
	 *  - optional component with no default: none (it stays “None”).
	 *
	 * @param WC_Product_Dahu_Kit $kit Kit.
	 * @return array [component_id => normalised selection[]]
	 */
	private static function projection_candidates( WC_Product_Dahu_Kit $kit ) {
		$candidates = array();

		foreach ( $kit->get_kit_components() as $component ) {
			$list = array();

			if ( self::is_path( $component ) ) {
				$default = (string) $component['default_option_id'];
				foreach ( $component['options'] as $option ) {
					if ( ! empty( $option['coming_soon'] ) || ! empty( $option['link'] ) ) {
						continue;
					}
					$pick = array( 'option_id' => (string) $option['id'] );
					if ( '' !== $default && (string) $option['id'] === $default ) {
						array_unshift( $list, $pick );
					} elseif ( ! $component['optional'] ) {
						$list[] = $pick;
					}
				}
			} else {
				$default = (int) $component['default_product_id'];
				foreach ( $component['options'] as $option ) {
					$product_id = (int) $option['product_id'];
					$product    = wc_get_product( $product_id );
					if ( ! $product || ! self::is_product_available( $product ) ) {
						continue;
					}
					$pick = array(
						'product_id'   => $product_id,
						'variation_id' => null,
						'quantity'     => max( 1, (int) $component['quantity_min'] ),
					);
					// Multiple choice: a selection is a list.
					if ( self::is_multiple( $component ) ) {
						$pick = array( $pick );
					}
					if ( $default && $product_id === $default ) {
						array_unshift( $list, $pick );
					} elseif ( ! $component['optional'] ) {
						$list[] = $pick;
					}
				}
			}

			$candidates[ $component['id'] ] = $list;
		}

		return $candidates;
	}

	/**
	 * Components hidden in the display projection of the journey (see
	 * Dahu_Composite_Scenarios::project()).
	 *
	 * @param WC_Product_Dahu_Kit $kit        Kit.
	 * @param array               $selections REAL NORMALISED selections.
	 * @param array               $decided    Components already decided on the client side.
	 * @return string[]
	 */
	public static function display_hidden_components( WC_Product_Dahu_Kit $kit, array $selections, array $decided = array() ) {
		return Dahu_Composite_Scenarios::project(
			$kit->get_kit_components(),
			$kit->get_kit_scenarios(),
			$selections,
			$decided,
			self::projection_candidates( $kit ),
			$kit->scenario_context()
		);
	}

	/**
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private static function is_product_available( WC_Product $product ) {
		$status = $product->get_status();
		return 'publish' === $status || ( 'private' === $status && current_user_can( 'read_private_products' ) );
	}

	/**
	 * “Any” variation attributes (empty value) not supplied by the client.
	 *
	 * @param WC_Product $variation  Variation.
	 * @param array      $attributes Attributes supplied.
	 * @return string[]
	 */
	private static function missing_variation_attributes( WC_Product $variation, array $attributes ) {
		$missing = array();
		foreach ( wc_get_product_variation_attributes( $variation->get_id() ) as $key => $value ) {
			if ( '' === $value && empty( $attributes[ $key ] ) ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	/**
	 * Full attributes of a variation (fixed values + the “Any” ones supplied).
	 *
	 * @param WC_Product $variation  Variation.
	 * @param array      $attributes Attributes supplied.
	 * @return array
	 */
	public static function variation_attributes( WC_Product $variation, array $attributes ) {
		$out = array();
		foreach ( wc_get_product_variation_attributes( $variation->get_id() ) as $key => $value ) {
			$out[ $key ] = '' === $value && isset( $attributes[ $key ] ) ? $attributes[ $key ] : $value;
		}
		return $out;
	}

	/**
	 * Variation to use for a variable bundle item: the one chosen by the customer
	 * (when it is allowed), else the single allowed variation (the case of an item
	 * locked to one value, such as a charger locked to a single voltage).
	 *
	 * @param object     $bundled_item Bundle item (WC_Bundled_Item).
	 * @param array|null $conf         Customer configuration.
	 * @return int 0 when undetermined.
	 */
	public static function resolve_bundled_variation( $bundled_item, $conf ) {
		$product = $bundled_item->get_product();
		if ( ! $product ) {
			return 0;
		}
		$allowed = method_exists( $bundled_item, 'get_filtered_variations' ) ? array_map( 'absint', (array) $bundled_item->get_filtered_variations() ) : array();
		if ( empty( $allowed ) ) {
			$allowed = array_map( 'absint', $product->get_children() );
		}

		if ( $conf && ! empty( $conf['variation_id'] ) && in_array( (int) $conf['variation_id'], $allowed, true ) ) {
			return (int) $conf['variation_id'];
		}
		return 1 === count( $allowed ) ? (int) reset( $allowed ) : 0;
	}

	/*
	|--------------------------------------------------------------------------
	| Quote (prices + weight)
	|--------------------------------------------------------------------------
	*/

	/**
	 * Rows (one per visible selected component — one per chosen OPTION for a
	 * multiple component) with prices and weight.
	 *
	 * Prices: sum of (unit price × quantity) — no base price, no discount
	 * (original kits: base_price = 0, everything priced_individually).
	 * Weight: the components with shipped_individually = false are aggregated into
	 * the parcel of the container; the others stay on their own row.
	 *
	 * @param WC_Product_Dahu_Kit $kit        Kit.
	 * @param array               $selections Normalised selections.
	 * @return array
	 */
	public static function build_lines( WC_Product_Dahu_Kit $kit, array $selections, $kit_quantity = 1 ) {
		$evaluation = $kit->evaluate_scenarios( $selections );
		$hidden     = array_flip( $evaluation['hidden_components'] );
		$lines      = array();

		foreach ( $kit->get_kit_components() as $component ) {
			$cid = $component['id'];
			// A branch (“path”) never produces a row: no price,
			// no weight, no cart row and no order row.
			if ( self::is_path( $component ) || isset( $hidden[ $cid ] ) || empty( $selections[ $cid ] ) ) {
				continue;
			}
			foreach ( self::selection_items( $selections[ $cid ] ) as $selection ) {
				$line = self::build_line( $component, $selection, $kit_quantity );
				if ( $line ) {
					$lines[] = $line;
				}
			}
		}

		return $lines;
	}

	/**
	 * Row of a chosen item, or null (product not found, variant missing, quantity
	 * zero). Carries the item itself (the “selection” key, internal: the cart needs
	 * it for the attributes and the bundle items; quote() does not publish it).
	 *
	 * @param array $component    Component.
	 * @param array $selection    Normalised item.
	 * @param int   $kit_quantity Number of kits.
	 * @return array|null
	 */
	private static function build_line( array $component, array $selection, $kit_quantity ) {
		$cid     = $component['id'];
		$product = wc_get_product( $selection['product_id'] );
		if ( ! $product ) {
			return null;
		}
		$line_product = $product;
		if ( $product->is_type( 'variable' ) ) {
			$variation = $selection['variation_id'] ? wc_get_product( $selection['variation_id'] ) : null;
			if ( ! $variation ) {
				return null;
			}
			$line_product = $variation;
		}

		$qty = max( 0, (int) $selection['quantity'] );
		if ( $qty < 1 ) {
			return null; // Optional component at quantity 0: no row.
		}
		$unit_raw    = Dahu_Composite_Pricing::get_raw_price( $line_product, max( 1, $qty * max( 1, (int) $kit_quantity ) ) );
		$unit_regular = max( $unit_raw, Dahu_Composite_Pricing::get_raw_regular_price( $line_product ) );
		$bundle_extra = array(
			'raw'     => 0.0,
			'regular' => 0.0,
			'weight'  => 0.0,
			'items'   => array(),
		);

		if ( $product->is_type( 'bundle' ) ) {
			$bundle_extra = self::bundle_selection_extras( $product, $selection );
		}

		$unit_raw_total     = $unit_raw + $bundle_extra['raw'];
		$unit_regular_total = $unit_regular + $bundle_extra['regular'];
		$unit_weight        = self::option_weight( $line_product ) + $bundle_extra['weight'];

		return array(
			'component_id'         => $cid,
			'component_title'      => $component['title'],
			'product_id'           => (int) $product->get_id(),
			'variation_id'         => $line_product->is_type( 'variation' ) ? (int) $line_product->get_id() : null,
			'name'                 => self::option_display_name( $component, $product, $line_product, (array) $selection['attributes'] ),
			'type'                 => $product->get_type(),
			'quantity'             => $qty,
			'shipped_individually' => (bool) $component['shipped_individually'],
			'unit_raw'             => $unit_raw_total,
			'unit_regular_raw'     => $unit_regular_total,
			'unit_display'         => Dahu_Composite_Pricing::display_tier_price( $line_product, $unit_raw_total ),
			'unit_regular_display' => Dahu_Composite_Pricing::to_display( $line_product, $unit_regular_total ),
			'line_display'         => Dahu_Composite_Pricing::display_tier_price( $line_product, $unit_raw_total, $qty ),
			'line_regular_display' => Dahu_Composite_Pricing::to_display( $line_product, $unit_regular_total, $qty ),
			'line_tax'             => Dahu_Composite_Pricing::tax_split( $line_product, $unit_raw_total, $qty ),
			'unit_weight'          => $unit_weight,
			'line_weight'          => $unit_weight * $qty,
			'bundle_items'         => $bundle_extra['items'],
			'selection'            => $selection,
		);
	}

	/**
	 * DISPLAYED weight of a step, the “Displayed weight” setting of the component
	 * in the kit editor:
	 *  - '' (default): the weight of the chosen products, unchanged;
	 *  - 'fixed'     : weight_grams, whatever the product;
	 *  - 'subtract'  : the weight of the products minus weight_grams, floor 0.
	 * Multiple-choice step: applied once to the total of the step. Grams converted
	 * into the weight unit of the shop.
	 *
	 * DISPLAY ONLY (quote: summary, Summary, /quote, /state): build_lines(), the
	 * cart (Dahu_Composite_Cart::shipping_packages(), the real weights of the
	 * products) and the order (_composite_weight) never take it into account.
	 *
	 * @param array $component Normalised component.
	 * @param float $weight    Weight of the products of the step, per kit (unit of the shop).
	 * @return float
	 */
	public static function display_step_weight( array $component, $weight ) {
		$mode = isset( $component['weight_mode'] ) ? $component['weight_mode'] : '';
		if ( 'fixed' !== $mode && 'subtract' !== $mode ) {
			return (float) $weight;
		}
		$grams = (float) wc_get_weight( max( 0, (int) $component['weight_grams'] ), (string) get_option( 'woocommerce_weight_unit', 'kg' ), 'g' );
		return 'fixed' === $mode ? $grams : max( 0.0, (float) $weight - $grams );
	}

	/**
	 * Complete quote of a configuration.
	 *
	 * @param WC_Product_Dahu_Kit $kit          Kit.
	 * @param array               $selections   Normalised selections.
	 * @param int                 $kit_quantity Number of kits.
	 * @return array
	 */
	public static function quote( WC_Product_Dahu_Kit $kit, array $selections, $kit_quantity = 1 ) {
		$kit_quantity = max( 1, (int) $kit_quantity );
		$lines        = self::build_lines( $kit, $selections, $kit_quantity );

		$total           = 0.0;
		$total_regular   = 0.0;
		$total_excl      = 0.0;
		$total_incl      = 0.0;
		$packaged_weight = 0.0;
		$separate_weight = 0.0;
		// Steps with a weight setting: the weight of the products of the step,
		// accumulated apart then adjusted once (display_step_weight()). The
		// other steps add up row by row, exactly as before.
		$adjusted = array();

		foreach ( $lines as $line ) {
			$total         += $line['line_display'];
			$total_regular += $line['line_regular_display'];
			$total_excl    += $line['line_tax']['excl'];
			$total_incl    += $line['line_tax']['incl'];
			$component      = $kit->get_kit_component( $line['component_id'] );
			if ( $component && ! empty( $component['weight_mode'] ) ) {
				if ( ! isset( $adjusted[ $line['component_id'] ] ) ) {
					$adjusted[ $line['component_id'] ] = array(
						'component' => $component,
						'weight'    => 0.0,
						'separate'  => (bool) $line['shipped_individually'],
					);
				}
				$adjusted[ $line['component_id'] ]['weight'] += $line['line_weight'];
				continue;
			}
			if ( $line['shipped_individually'] ) {
				$separate_weight += $line['line_weight'];
			} else {
				$packaged_weight += $line['line_weight'];
			}
		}
		foreach ( $adjusted as $step ) {
			$weight = self::display_step_weight( $step['component'], $step['weight'] );
			if ( $step['separate'] ) {
				$separate_weight += $weight;
			} else {
				$packaged_weight += $weight;
			}
		}

		$own_weight       = (float) $kit->get_weight();
		$container_weight = $own_weight + $packaged_weight;
		$decimals         = wc_get_price_decimals();

		$price = array(
			'total'          => round( $total * $kit_quantity, $decimals ),
			'total_regular'  => round( $total_regular * $kit_quantity, $decimals ),
			'total_excl_tax' => round( $total_excl * $kit_quantity, $decimals ),
			'total_incl_tax' => round( $total_incl * $kit_quantity, $decimals ),
			'unit'           => round( $total, $decimals ),
			'unit_regular'   => round( $total_regular, $decimals ),
			'tax_display'    => get_option( 'woocommerce_tax_display_shop' ),
			'currency'       => get_woocommerce_currency(),
			'tier'           => self::current_tier_summary(),
		);
		$price['html']      = Dahu_Composite_Pricing::price_html( $price['total'], $price['total_regular'] );
		$price['unit_html'] = Dahu_Composite_Pricing::price_html( $price['unit'], $price['unit_regular'] );

		$public_lines = array();
		foreach ( $lines as $line ) {
			$public_lines[] = array(
				'component_id'         => $line['component_id'],
				'component_title'      => $line['component_title'],
				'product_id'           => $line['product_id'],
				'variation_id'         => $line['variation_id'],
				'name'                 => $line['name'],
				'quantity'             => $line['quantity'],
				'unit_price'           => round( $line['unit_display'], $decimals ),
				'unit_regular_price'   => round( $line['unit_regular_display'], $decimals ),
				'line_total'           => round( $line['line_display'], $decimals ),
				'line_regular_total'   => round( $line['line_regular_display'], $decimals ),
				'price_html'           => Dahu_Composite_Pricing::price_html( $line['line_display'], $line['line_regular_display'] ),
				'unit_weight'          => $line['unit_weight'],
				'line_weight'          => $line['line_weight'],
				'shipped_individually' => $line['shipped_individually'],
				'bundle_items'         => $line['bundle_items'],
			);
		}

		// Price per SCREEN, for the summary: sum of the rows of the
		// components of one screen — the same display_group, else the component
		// alone (the same split as the front end, groupSteps()). The summary
		// shows one price per screen, plus one per component (Motor + cassette).
		// The sum is done here and not on the client side: rendering a price
		// (tier, excl. / incl. tax, labels) is price_html()'s business.
		$screen_of = array();
		foreach ( $kit->get_kit_components() as $component ) {
			$group                         = isset( $component['display_group'] ) ? (string) $component['display_group'] : '';
			$screen_of[ $component['id'] ] = '' !== $group ? $group : $component['id'];
		}
		$screens = array();
		foreach ( $lines as $line ) {
			$key = isset( $screen_of[ $line['component_id'] ] ) ? $screen_of[ $line['component_id'] ] : $line['component_id'];
			if ( ! isset( $screens[ $key ] ) ) {
				$screens[ $key ] = array( 0.0, 0.0 );
			}
			$screens[ $key ][0] += $line['line_display'];
			$screens[ $key ][1] += $line['line_regular_display'];
		}
		$screen_prices = array();
		foreach ( $screens as $key => $sums ) {
			$screen_prices[ $key ] = array(
				'total'         => round( $sums[0], $decimals ),
				'total_regular' => round( $sums[1], $decimals ),
				'html'          => Dahu_Composite_Pricing::price_html( $sums[0], $sums[1] ),
			);
		}

		return array(
			'price'      => $price,
			// An object even when empty ({} and not [] in JSON).
			'screen_prices' => (object) $screen_prices,
			'weight'     => array(
				'unit'                  => get_option( 'woocommerce_weight_unit' ),
				'container_own'         => $own_weight,
				'container_package'     => $container_weight,
				'shipped_separately'    => $separate_weight,
				'per_kit'               => $container_weight + $separate_weight,
				'total'                 => ( $container_weight + $separate_weight ) * $kit_quantity,
				'has_packaged_components' => self::has_packaged_lines( $lines ),
			),
			'quantity'   => $kit_quantity,
			'line_items' => $public_lines,
		);
	}

	/**
	 * @param array $lines Rows.
	 * @return bool
	 */
	public static function has_packaged_lines( array $lines ) {
		foreach ( $lines as $line ) {
			if ( ! $line['shipped_individually'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * “Help image” of an option: “large” size (never cropped), dimensions and
	 * alternative text of the attachment.
	 *
	 * @param array $option Normalised option.
	 * @return array|null { url, width, height, alt }
	 */
	public static function help_image( array $option ) {
		$id = ! empty( $option['help_image_id'] ) ? (int) $option['help_image_id'] : 0;
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $id, 'large' );
		if ( ! $src ) {
			return null;
		}
		return array(
			'url'    => $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'alt'    => trim( wp_strip_all_tags( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ),
		);
	}

	/**
	 * Address of a “link” card (see
	 * Dahu_Composite_Kit_Config::path_link()): a complete address as it is; a path
	 * or slug of a page resolved on the current site (home_url(), a trailing slash
	 * added to a plain slug).
	 *
	 * @param string $link Saved link.
	 * @return string Address, '' with no link.
	 */
	public static function path_link_url( $link ) {
		$link = trim( (string) $link );
		if ( '' === $link ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $link ) ) {
			return esc_url_raw( $link, array( 'http', 'https' ) );
		}
		$path = ltrim( $link, '/' );
		// A plain slug (“contact”): the same shape as the permalinks of the pages.
		if ( false === strpbrk( $path, '/?#.' ) ) {
			$path .= '/';
		}
		return esc_url_raw( home_url( '/' . $path ) );
	}

	/**
	 * Media of the “?” popover of a range, rendered server side.
	 *
	 * Order: the attachment (media_id) first, then the link (media_url).
	 *  - image from the media library: wp_get_attachment_image() at the “large”
	 *    size (srcset included), lazy loaded;
	 *  - video from the media library, or a link to a .mp4 / .webm / .ogv / .mov
	 *    file: a <video> tag with controls, without autoplay;
	 *  - any other link (YouTube, Vimeo…): WordPress oEmbed integration (WP_Embed,
	 *    cached by WordPress after the first call), retained only when it produces
	 *    an <iframe>.
	 * Nothing usable (attachment deleted, link not recognised): null, and the
	 * popover shows the text alone.
	 *
	 * The text of the range stays filtered by wp_kses_post(); this HTML here is
	 * produced by WordPress (attachment, recognised oEmbed provider), not entered.
	 *
	 * @param array $tier Normalised range.
	 * @return array|null ['type' => 'image'|'video'|'embed', 'html' => string]
	 */
	public static function tier_media_html( array $tier ) {
		$id = ! empty( $tier['media_id'] ) ? (int) $tier['media_id'] : 0;
		if ( $id ) {
			if ( wp_attachment_is_image( $id ) ) {
				$html = wp_get_attachment_image(
					$id,
					'large',
					false,
					array(
						'class'    => 'dahu-kit__popover-image',
						'loading'  => 'lazy',
						'decoding' => 'async',
					)
				);
				if ( $html ) {
					return array(
						'type' => 'image',
						'html' => $html,
					);
				}
			} elseif ( wp_attachment_is( 'video', $id ) ) {
				$file = wp_get_attachment_url( $id );
				if ( $file ) {
					return array(
						'type' => 'video',
						'html' => self::popover_video( $file ),
					);
				}
			}
		}

		$url = ! empty( $tier['media_url'] ) ? (string) $tier['media_url'] : '';
		if ( '' === $url ) {
			return null;
		}
		if ( preg_match( '/\.(mp4|webm|ogv|mov)(\?.*)?$/i', $url ) ) {
			return array(
				'type' => 'video',
				'html' => self::popover_video( $url ),
			);
		}
		global $wp_embed;
		$html = $wp_embed instanceof WP_Embed ? (string) $wp_embed->shortcode( array(), $url ) : '';
		if ( false === stripos( $html, '<iframe' ) ) {
			return null;
		}
		return array(
			'type' => 'embed',
			'html' => $html,
		);
	}

	/**
	 * @param string $url Video file.
	 * @return string
	 */
	private static function popover_video( $url ) {
		return '<video class="dahu-kit__popover-video" src="' . esc_url( $url ) . '" controls preload="metadata" playsinline></video>';
	}

	/**
	 * Summary of the current price tier, for the customer's information.
	 *
	 * @return array|null
	 */
	public static function current_tier_summary() {
		$tier = Dahu_Composite_Pricing::get_current_tier();
		if ( ! $tier ) {
			return null;
		}
		return array(
			'key'                 => $tier['key'],
			'label'               => $tier['label'],
			'public_label'        => $tier['public_label'],
			'price_excluding_tax' => (bool) $tier['price_excluding_tax'],
		);
	}

	/**
	 * Display label of an option: the custom title of the kit when it is filled
	 * in, else the name of the product. Option with variants: always followed by
	 * the variant retained (“Rim, rim brake — 700x 17C, 32”). Display only — the
	 * cart and the order keep the real name of the WooCommerce product.
	 *
	 * Earlier, with no custom title, the label was the name of the variation as
	 * WooCommerce composes it — which only carries its attributes for a variation
	 * with one or two attributes none of whose names contains a hyphen: a rim
	 * (“rim-size”, “spoke-count”) therefore showed without its size in the
	 * Summary. The label no longer depends on that rule.
	 *
	 * @param array      $component    Component.
	 * @param WC_Product $product      Option product (parent).
	 * @param WC_Product $line_product Product or variation retained.
	 * @param array      $attributes   “Any” attributes supplied by the client.
	 * @return string
	 */
	public static function option_display_name( array $component, WC_Product $product, WC_Product $line_product, array $attributes = array() ) {
		$title = null;
		foreach ( $component['options'] as $option ) {
			if ( (int) $option['product_id'] === (int) $product->get_id() && ! empty( $option['display_title'] ) ) {
				$title = $option['display_title'];
			}
		}

		if ( ! $line_product->is_type( 'variation' ) ) {
			return null === $title ? $line_product->get_name() : $title;
		}

		$name      = null === $title ? $product->get_name() : $title;
		$variation = self::variation_label( $line_product, $attributes );

		return '' !== $variation ? $name . ' — ' . $variation : $name;
	}

	/**
	 * Label of a variant: the values of its attributes, in the order of the
	 * product (“700x 17C, 32”) — the same text as in the variant list of the
	 * configurator (schema_option()), completed with the values chosen by the
	 * client for the “Any” attributes.
	 *
	 * @param WC_Product $variation  Variation.
	 * @param array      $attributes “Any” attributes supplied by the client.
	 * @return string
	 */
	public static function variation_label( WC_Product $variation, array $attributes = array() ) {
		return wc_get_formatted_variation( self::variation_attributes( $variation, $attributes ), true, false, false );
	}

	/**
	 * Unit weight of an option (excluding the bundle items: see
	 * bundle_selection_extras()).
	 *
	 * @param WC_Product $product Product or variation.
	 * @return float
	 */
	public static function option_weight( WC_Product $product ) {
		return (float) apply_filters( 'dahu_composite_option_weight', (float) $product->get_weight(), $product );
	}

	/**
	 * Extra cost and weight brought by the items of a bundle (Product Bundles).
	 *
	 * - Prices: only the items “priced individually” and included (mandatory, or
	 *   optional and ticked) add to the base price of the bundle, PB item discount
	 *   included.
	 * - Weight: items not shipped individually, when the bundle aggregates the
	 *   weights (the PB “aggregated weight” setting).
	 *
	 * @param WC_Product $bundle    Bundle.
	 * @param array      $selection Selection.
	 * @return array ['raw' => float, 'regular' => float, 'weight' => float, 'items' => array]
	 */
	public static function bundle_selection_extras( WC_Product $bundle, array $selection ) {
		$out = array(
			'raw'     => 0.0,
			'regular' => 0.0,
			'weight'  => 0.0,
			'items'   => array(),
		);
		if ( ! method_exists( $bundle, 'get_bundled_items' ) ) {
			return $out;
		}
		$aggregate = method_exists( $bundle, 'get_aggregate_weight' ) ? (bool) $bundle->get_aggregate_weight() : false;

		foreach ( $bundle->get_bundled_items() as $bundled_item_id => $bundled_item ) {
			$conf     = isset( $selection['bundle'][ $bundled_item_id ] ) ? $selection['bundle'][ $bundled_item_id ] : null;
			$included = ! $bundled_item->is_optional() || ( $conf && $conf['selected'] );
			if ( ! $included ) {
				continue;
			}
			$item_product = $bundled_item->get_product();
			if ( ! $item_product ) {
				continue;
			}
			if ( $item_product->is_type( 'variable' ) ) {
				$vid          = self::resolve_bundled_variation( $bundled_item, $conf );
				$item_product = $vid ? wc_get_product( $vid ) : null;
				if ( ! $item_product ) {
					continue;
				}
			}
			$qty = ( $conf && null !== $conf['quantity'] && $conf['quantity'] > 0 ) ? (int) $conf['quantity'] : (int) $bundled_item->get_quantity( 'default' );
			$qty = max( 1, $qty );

			$item_raw     = 0.0;
			$item_regular = 0.0;
			if ( $bundled_item->is_priced_individually() ) {
				$discount     = (float) $bundled_item->get_discount();
				$regular      = Dahu_Composite_Pricing::get_raw_regular_price( $item_product );
				$role_price   = Dahu_Composite_Pricing::get_raw_price( $item_product, $qty );
				$item_raw     = $discount > 0 ? min( $role_price, $regular * ( 100 - $discount ) / 100 ) : $role_price;
				$item_regular = $regular;
				$out['raw']     += $item_raw * $qty;
				$out['regular'] += max( $item_raw, $item_regular ) * $qty;
			}
			if ( $aggregate && ! $bundled_item->is_shipped_individually() ) {
				$out['weight'] += (float) $item_product->get_weight() * $qty;
			}

			$out['items'][] = array(
				'bundled_item_id' => (int) $bundled_item_id,
				'product_id'      => (int) $item_product->get_id(),
				'name'            => $item_product->get_name(),
				'quantity'        => $qty,
			);
		}

		return $out;
	}

	/*
	|--------------------------------------------------------------------------
	| Schema
	|--------------------------------------------------------------------------
	*/

	/**
	 * Schema of the kit for a headless client: components, options (price
	 * according to the current role, weight, stock, variations), configurable
	 * bundle items, and initial hiding state (default selections).
	 *
	 * @param WC_Product_Dahu_Kit $kit Kit.
	 * @return array
	 */
	public static function schema( WC_Product_Dahu_Kit $kit ) {
		$components = array();
		$defaults   = array();

		// Options reserved to the current customer: what the GENERATED
		// scenarios (public visibility, exceptions) hide from them. They
		// do not go out in the schema: a visitor sees in the page neither
		// the name nor the price of a reserved variant. The hiding (and the
		// refusal on validation) stays ensured by those same scenarios, which the
		// engine evaluates along with the others.
		$reserved = array();
		if ( $kit->get_kit_generated_scenarios() ) {
			$reserved = Dahu_Composite_Scenarios::evaluate( $kit->get_kit_components(), $kit->get_kit_generated_scenarios(), array(), $kit->scenario_context() )['hidden_options'];
		}

		foreach ( $kit->get_kit_components() as $component ) {
			$is_path = self::is_path( $component );
			$options = array();

			if ( $is_path ) {
				// Branch: the options are returned as they are,
				// with no price and no weight (no product behind them).
				foreach ( $component['options'] as $option ) {
					$image_id  = ! empty( $option['display_image_id'] ) ? (int) $option['display_image_id'] : 0;
					$options[] = array(
						'id'          => $option['id'],
						'name'        => $option['label'],
						'description' => ! empty( $option['display_description'] ) ? wp_kses_post( $option['display_description'] ) : '',
						'image'       => $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : null,
						// Large format: back of the flipped summary.
						'image_large' => $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_single' ) : null,
						'badge'       => ! empty( $option['badge'] ) ? $option['badge'] : null,
						'tier'        => ! empty( $option['tier'] ) ? $option['tier'] : null,
						'coming_soon' => ! empty( $option['coming_soon'] ),
						// “link” card: address resolved, '' otherwise.
						'link'        => self::path_link_url( isset( $option['link'] ) ? $option['link'] : '' ),
						'in_stock'    => true,
					);
				}
				// A “link” card is never chosen automatically.
				$default_option = $component['default_option_id'] ? self::path_option( $component, $component['default_option_id'] ) : null;
				if ( $default_option && empty( $default_option['link'] ) ) {
					$defaults[ $component['id'] ] = array( 'option_id' => $component['default_option_id'] );
				}
			} else {
				$component_reserved = isset( $reserved[ $component['id'] ] ) ? $reserved[ $component['id'] ] : array();
				foreach ( $component['options'] as $option ) {
					$product = wc_get_product( $option['product_id'] );
					if ( ! $product || ! self::is_product_available( $product ) ) {
						continue;
					}
					if ( in_array( (string) $option['product_id'], $component_reserved, true ) ) {
						continue;
					}
					$data = self::schema_option( $product, $option );
					if ( $component_reserved && ! empty( $data['variations'] ) ) {
						$pid       = (int) $option['product_id'];
						$remaining = array_values(
							array_filter(
								$data['variations'],
								function ( $variation ) use ( $pid, $component_reserved ) {
									return ! in_array( $pid . ':' . (int) $variation['variation_id'], $component_reserved, true );
								}
							)
						);
						if ( count( $remaining ) !== count( $data['variations'] ) ) {
							$data['variations'] = $remaining;
							// “From” recomputed on the visible
							// variants: otherwise it would give away the price of a
							// reserved variant.
							$min         = null;
							$min_regular = null;
							foreach ( $remaining as $variation ) {
								if ( null === $min || $variation['price'] < $min ) {
									$min         = $variation['price'];
									$min_regular = $variation['regular_price'];
								}
							}
							$data['price']         = $min;
							$data['regular_price'] = $min_regular;
							$data['price_html']    = null === $min
								? ''
								: wc_get_price_html_from_text() . Dahu_Composite_Pricing::price_html( $min, $min_regular );
						}
					}
					$options[] = $data;
				}

				if ( $component['default_product_id'] ) {
					$default_product = wc_get_product( $component['default_product_id'] );
					// Variable product by default: preselected with no variation,
					// the customer chooses the variant (as in WCCP).
					if ( $default_product ) {
						$defaults[ $component['id'] ] = array(
							'product_id' => (int) $component['default_product_id'],
							'quantity'   => (int) $component['quantity_min'],
						);
						// Multiple choice: a list of one item, one unit.
						if ( self::is_multiple( $component ) ) {
							$defaults[ $component['id'] ] = array(
								array(
									'product_id' => (int) $component['default_product_id'],
									'quantity'   => max( 1, (int) $component['quantity_min'] ),
								),
							);
						}
					}
				}
			}

			$components[] = array(
				'id'                   => $component['id'],
				'component_type'       => $component['component_type'],
				// Components with the same display_group = the same screen on the front end.
				'display_group'        => $component['display_group'],
				// An older configuration without the key: “primary”.
				'title_role'           => isset( $component['title_role'] ) ? $component['title_role'] : 'primary',
				// Anchor of an add-on next to a card;
				// a configuration saved before the keys: null.
				'anchor_component_id'  => isset( $component['anchor_component_id'] ) ? $component['anchor_component_id'] : null,
				'anchor_product_id'    => isset( $component['anchor_product_id'] ) ? (int) $component['anchor_product_id'] : null,
				// “question” presentation of an add-on; a configuration
				// saved before the keys: empty.
				'presentation'         => isset( $component['presentation'] ) ? $component['presentation'] : '',
				'question'             => isset( $component['question'] ) ? $component['question'] : '',
				'title'                => $component['title'],
				'description'          => $component['description'],
				// Info bubble of the screen; a configuration saved
				// before the key: empty.
				'info'                 => isset( $component['info'] ) ? $component['info'] : '',
				'selection_mode'       => $component['selection_mode'],
				// Layout of a multiple-choice component;
				// a configuration saved with the older test setting
				// (test_display_style): read back.
				'display_style'        => Dahu_Composite_Kit_Config::display_style( $component ),
				'quantity_min'         => $component['quantity_min'],
				'quantity_max'         => $component['quantity_max'],
				'quantity_editable'    => $component['quantity_editable'],
				'default_product_id'   => $component['default_product_id'],
				'default_option_id'    => $component['default_option_id'],
				'optional'             => $component['optional'],
				// “None” card specific to the component: image set
				// in the kit editor. null = the shared card of the schema
				// (none_choice, further down).
				'none_choice'          => $component['optional'] ? Dahu_Composite_Settings::component_none_choice( $component ) : null,
				'shipped_individually' => $component['shipped_individually'],
				// Categories (ranges), in the order of the sections: the front end
				// builds its sections from this list, the “tier” of the
				// options being only the id it is attached by.
				// Display of the ranges: a new row per range, a range
				// badge on the cards, a tinted card background. A configuration
				// saved before the keys: disabled.
				'tier_newline'         => ! empty( $component['tier_newline'] ),
				'tier_badge'           => ! empty( $component['tier_badge'] ),
				'tier_tint'            => ! empty( $component['tier_tint'] ),
				'tiers'                => array_map(
					function ( $tier ) {
						return array(
							'id'          => $tier['id'],
							'label'       => $tier['label'],
							'description' => ! empty( $tier['description'] ) ? wp_kses_post( $tier['description'] ) : '',
							// Image or video of the “?” popover of the range:
							// null = text alone.
							'media'       => self::tier_media_html( $tier ),
							// Colour: “#rrggbb”, or '' = the default
							// colour, assigned by the front end (tierColor()).
							'color'       => isset( $tier['color'] ) ? (string) $tier['color'] : '',
						);
					},
					isset( $component['tiers'] ) ? $component['tiers'] : array()
				),
				'options'              => $options,
			);
		}

		$initial = $kit->evaluate_scenarios( array() );

		return array(
			'product_id'      => $kit->get_id(),
			'name'            => $kit->get_name(),
			'currency'        => get_woocommerce_currency(),
			'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
			'tax_display'     => get_option( 'woocommerce_tax_display_shop' ),
			// Price tier of the current user (null = visitor /
			// individual). Informative: the rendered prices already take the
			// tier into account, the client has no recomputation to do.
			'price_tier'      => self::current_tier_summary(),
			'weight_unit'     => get_option( 'woocommerce_weight_unit' ),
			'components'      => $components,
			// “None” card of the optional components: image and
			// text shared by the whole configurator (WooCommerce > Dahu Kits).
			// null = nothing set, the “None” card unchanged.
			'none_choice'     => Dahu_Composite_Settings::none_choice(),
			'defaults'        => (object) $defaults,
			// The hiding depends on the selection state: the client calls
			// /validate again on every change. We supply the initial state here.
			'visibility'      => array(
				'hidden_components' => $initial['hidden_components'],
				// Display projection with no selection at all.
				'display_hidden_components' => self::display_hidden_components( $kit, array() ),
				'hidden_options'    => (object) $initial['hidden_options'],
				'blocked'           => isset( $initial['blocked'] ) ? $initial['blocked'] : array(),
			),
		);
	}

	/**
	 * @param WC_Product $product Option product.
	 * @return array
	 */
	private static function schema_option( WC_Product $product, array $option = array() ) {
		// RESOLVED display content: the content specific to the kit when it is
		// filled in, else the real data of the product. The client never has
		// to make that choice itself. Display only: the real product
		// stays what is billed and traced.
		$image_id = ! empty( $option['display_image_id'] ) ? (int) $option['display_image_id'] : $product->get_image_id();

		$data = array(
			'product_id'  => (int) $product->get_id(),
			'name'        => ! empty( $option['display_title'] ) ? $option['display_title'] : $product->get_name(),
			'type'        => $product->get_type(),
			'description' => ! empty( $option['display_description'] )
				? wp_kses_post( $option['display_description'] )
				: wp_kses_post( $product->get_short_description() ),
			'image'       => $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : null,
			// Large format: back of the flipped summary.
			'image_large' => $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_single' ) : null,
			'badge'       => ! empty( $option['badge'] ) ? $option['badge'] : null,
			// “Help image”: image of the back, Description section,
			// WordPress “large” size (never cropped: a diagram keeps
			// its proportions). null: none.
			'help_image'  => self::help_image( $option ),
			// Subtitle and generation badge of the product, shown on the
			// vignettes. The plugin does not know this data: it
			// comes from the theme (or from another plugin) through a filter. Plain
			// text, empty by default — nothing is then shown.
			'subtitle'    => sanitize_text_field( (string) apply_filters( 'dahu_composite_option_subtitle', '', $product ) ),
			'generation'  => sanitize_text_field( (string) apply_filters( 'dahu_composite_option_generation', '', $product ) ),
			// Category (range): id of an entry of the component's “tiers”,
			// null with no category.
			'tier'        => ! empty( $option['tier'] ) ? $option['tier'] : null,
			// Image and info sections shown. Key absent
			// (an option saved before these checkboxes existed) = true, key present =
			// honoured: Dahu_Composite_Kit_Config::display_flag().
			'show_image'       => Dahu_Composite_Kit_Config::display_flag( $option, 'show_image' ),
			'show_description' => Dahu_Composite_Kit_Config::display_flag( $option, 'show_description' ),
			'show_specs'       => Dahu_Composite_Kit_Config::display_flag( $option, 'show_specs' ),
			'show_bundled'     => Dahu_Composite_Kit_Config::display_flag( $option, 'show_bundled' ),
			'in_stock'    => $product->is_in_stock(),
			'weight'      => self::option_weight( $product ),
			'variations'  => array(),
		);

		if ( $product->is_type( 'variable' ) ) {
			$min         = null;
			$min_regular = null;
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation || ! $variation->variation_is_visible() ) {
					continue;
				}
				$pricing                = self::schema_price( $variation );
				// Image OF THE VARIATION itself ('edit': no fallback to the
				// parent); null = the image of the option applies.
				$variation_image_id     = (int) $variation->get_image_id( 'edit' );
				$data['variations'][] = array_merge(
					array(
						'variation_id'   => (int) $variation_id,
						'name'           => wc_get_formatted_variation( $variation, true, false, false ),
						'attributes'     => wc_get_product_variation_attributes( $variation_id ),
						// “Any” attributes: the client must choose a value.
						'any_attributes' => self::any_attributes( $product, $variation_id ),
						'in_stock'       => $variation->is_in_stock(),
						'weight'         => self::option_weight( $variation ),
						'image_large'    => $variation_image_id ? wp_get_attachment_image_url( $variation_image_id, 'woocommerce_single' ) : null,
						// Help image of the variation itself, null =
						// that of the option (help_image).
						'help_image'     => self::help_image(
							array(
								'help_image_id' => isset( $option['variation_help_images'][ $variation_id ] ) ? $option['variation_help_images'][ $variation_id ] : 0,
							)
						),
					),
					$pricing
				);
				if ( null === $min || $pricing['price'] < $min ) {
					$min         = $pricing['price'];
					$min_regular = $pricing['regular_price'];
				}
			}
			// The same rendering as the simple options (public price + labelled
			// tier price), preceded by WooCommerce's “from”.
			$data['price']         = $min;
			$data['regular_price'] = $min_regular;
			$data['price_html']    = null === $min
				? ''
				: wc_get_price_html_from_text() . Dahu_Composite_Pricing::price_html( $min, $min_regular );
		} else {
			$data = array_merge( $data, self::schema_price( $product ) );
		}

		if ( $product->is_type( 'bundle' ) && method_exists( $product, 'get_bundled_items' ) ) {
			$data['bundle_items'] = self::schema_bundle_items( $product );
		}

		// “More info” as a flipped / extended card. Always the
		// data of the REAL product: `description` above may be replaced
		// by the content specific to the kit, this one may not. Sent only when
		// it differs from it: with no content specific to the kit, it is the same
		// string, and the front end then reads `description` (a lighter inline state).
		$short = wp_kses_post( $product->get_short_description() );
		if ( $short !== $data['description'] ) {
			$data['short_description'] = $short;
		}
		// Specifications to show? The content itself is loaded on
		// demand (GET /product-tabs/{id}): it does not go into the inline state.
		$data['has_specs'] = Dahu_Composite_Product_Tabs::has_characteristics( $product );
		$data['bundled']   = self::schema_bundled( $product );

		// “more info…” link, the “Show more info” checkbox of
		// WooCommerce > Dahu Kits. Key present only when the box is
		// ticked AND the product page exists publicly: schema unchanged otherwise.
		if ( Dahu_Composite_Settings::more_info_enabled() ) {
			$url = self::public_product_url( $product );
			if ( '' !== $url ) {
				$data['more_info_url'] = $url;
			}
		}

		return $data;
	}

	/**
	 * Address of the page of an option product, or '' when it does not exist for a
	 * visitor (draft, private, pending, password protected, not found): never a
	 * link to a 404 page. A variation points to its parent product.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	private static function public_product_url( WC_Product $product ) {
		if ( $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}
		if ( ! $product || 'publish' !== $product->get_status() || '' !== (string) $product->get_post_password() ) {
			return '';
		}
		$url = get_permalink( $product->get_id() );
		return $url ? (string) $url : '';
	}

	/**
	 * Products supplied with a bundle: name, quantity, thumbnail. Every item,
	 * including those hidden on the product page (the charger shipped with a
	 * battery is still a product that is supplied). No prices. Empty list for a
	 * product that is not a bundle.
	 *
	 * A variable option one of whose variations has a supply stays at an empty
	 * list. Temporary limit: the kit path does not ship the supply yet (see the
	 * header of Dahu_Composite_Supply), and the configurator only announces what
	 * the order will deliver.
	 *
	 * List produced by Dahu_Composite_Included::bundle_items() (the only producer,
	 * shared with dahu_composite_get_bundled_items()), reduced here to the five
	 * keys of the schema, in the same order.
	 *
	 * @param WC_Product $product Option product.
	 * @return array[] [ ['product_id', 'name', 'quantity', 'image', 'optional'] ]
	 */
	private static function schema_bundled( WC_Product $product ) {
		if ( ! $product->is_type( 'bundle' ) || ! method_exists( $product, 'get_bundled_items' ) ) {
			return array();
		}
		$keys = array_flip( array( 'product_id', 'name', 'quantity', 'image', 'optional' ) );
		return array_map(
			static function ( $row ) use ( $keys ) {
				return array_intersect_key( $row, $keys );
			},
			Dahu_Composite_Included::bundle_items( $product )
		);
	}

	/**
	 * “Any” attributes of a variation and their possible values.
	 *
	 * @param WC_Product $product      Variable product.
	 * @param int        $variation_id Variation.
	 * @return array [ ['key' => 'attribute_x', 'label' => string, 'values' => [ ['value', 'label'] ]] ]
	 */
	private static function any_attributes( WC_Product $product, $variation_id ) {
		$out       = array();
		$available = method_exists( $product, 'get_variation_attributes' ) ? $product->get_variation_attributes() : array();

		foreach ( wc_get_product_variation_attributes( $variation_id ) as $key => $value ) {
			if ( '' !== $value ) {
				continue;
			}
			$name   = substr( $key, strlen( 'attribute_' ) );
			$values = array();
			foreach ( $available as $attribute_name => $options ) {
				if ( sanitize_title( $attribute_name ) !== $name ) {
					continue;
				}
				foreach ( (array) $options as $option ) {
					$label = $option;
					if ( taxonomy_exists( $attribute_name ) ) {
						$term  = get_term_by( 'slug', $option, $attribute_name );
						$label = $term ? $term->name : $option;
					}
					$values[] = array(
						'value' => $option,
						'label' => $label,
					);
				}
				$out[] = array(
					'key'    => $key,
					'label'  => wc_attribute_label( $attribute_name, $product ),
					'values' => $values,
				);
			}
		}
		return $out;
	}

	/**
	 * @param WC_Product $product Product or variation.
	 * @return array
	 */
	private static function schema_price( WC_Product $product ) {
		$raw     = Dahu_Composite_Pricing::get_raw_price( $product );
		$regular = max( $raw, Dahu_Composite_Pricing::get_raw_regular_price( $product ) );
		$display = Dahu_Composite_Pricing::display_tier_price( $product, $raw );
		$reg_d   = Dahu_Composite_Pricing::to_display( $product, $regular );
		return array(
			'price'         => round( $display, wc_get_price_decimals() ),
			'regular_price' => round( $reg_d, wc_get_price_decimals() ),
			'price_html'    => Dahu_Composite_Pricing::price_html( $display, $reg_d ),
		);
	}

	/**
	 * @param WC_Product $bundle Bundle.
	 * @return array
	 */
	private static function schema_bundle_items( WC_Product $bundle ) {
		$items = array();
		foreach ( $bundle->get_bundled_items() as $bundled_item_id => $bundled_item ) {
			$product = $bundled_item->get_product();
			if ( ! $product ) {
				continue;
			}
			$variations = array();
			if ( $product->is_type( 'variable' ) ) {
				$allowed = method_exists( $bundled_item, 'get_filtered_variations' ) ? (array) $bundled_item->get_filtered_variations() : array();
				if ( empty( $allowed ) ) {
					$allowed = $product->get_children();
				}
				foreach ( $allowed as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( $variation ) {
						$variations[] = array_merge(
							array(
								'variation_id' => (int) $variation_id,
								'name'         => wc_get_formatted_variation( $variation, true, false, false ),
								'in_stock'     => $variation->is_in_stock(),
							),
							self::schema_price( $variation )
						);
					}
				}
			}
			$item = array(
				'bundled_item_id'     => (int) $bundled_item_id,
				'product_id'          => (int) $product->get_id(),
				'title'               => $bundled_item->get_raw_title( true ),
				'optional'            => (bool) $bundled_item->is_optional(),
				'priced_individually' => (bool) $bundled_item->is_priced_individually(),
				'quantity_default'    => (int) $bundled_item->get_quantity( 'default' ),
				'quantity_min'        => (int) $bundled_item->get_quantity( 'min' ),
				'quantity_max'        => $bundled_item->get_quantity( 'max' ),
				'variations'          => $variations,
			);
			if ( ! $product->is_type( 'variable' ) && $bundled_item->is_priced_individually() ) {
				$item = array_merge( $item, self::schema_price( $product ) );
			}
			$items[] = $item;
		}
		return $items;
	}

	/**
	 * @param string $code      Code.
	 * @param string $component Component.
	 * @param string $message   Message.
	 * @return array
	 */
	private static function error( $code, $component, $message ) {
		return array(
			'code'      => $code,
			'component' => $component,
			'message'   => $message,
		);
	}
}
