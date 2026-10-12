<?php
/**
 * Scenario engine — pure functions (no database access, no state).
 *
 * Semantics reproduced from WCCP:
 *  - a scenario is ACTIVE when ALL of its conditions match the current
 *    selections;
 *  - a condition may only bear on a component placed before the target
 *    component (descending cascade);
 *  - a hidden component counts as “no selection” for the following scenarios
 *    (any selection it may have is ignored);
 *  - mask_component removes the whole step, mask_options removes options (a
 *    whole product “123” or a precise variation “123:456”).
 *
 * Multiple-choice component (selection_mode “multiple”): its selection is a
 * LIST of items (one per chosen option) instead of a single item. An “in”
 * condition is true when AT LEAST one chosen item appears in its values;
 * “not_in” when NONE appears; “none” = empty list. A radio component keeps
 * exactly the earlier semantics (its selection is read as a list of one item).
 * See selection_items().
 *
 * Customer condition (`customer` key of a scenario: { mode: in | not_in, ids:
 * int[] }): a dimension SEPARATE from the component conditions. The current
 * customer arrives through the context ($context['customer_id'], 0 = visitor
 * not logged in, never “in” a list); the engine stays pure. A scenario without
 * that key is evaluated exactly as before.
 *
 * The scenarios are evaluated in the order of the target components, which
 * guarantees that a hidden component is already hidden when a scenario further
 * down the cascade reads it as a condition.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Scenarios {

	/**
	 * @param array $components Normalised components (ordered).
	 * @param array $scenarios  Normalised scenarios.
	 * @param array $selections [component_id => ['product_id' => int, 'variation_id' => int|null]].
	 * @param array $context    ['customer_id' => int]; absent = visitor.
	 * @return array ['hidden_components' => string[], 'hidden_options' => [component_id => string[]]]
	 */
	public static function evaluate( array $components, array $scenarios, array $selections, array $context = array() ) {
		$positions = array();
		foreach ( array_values( $components ) as $index => $component ) {
			$positions[ $component['id'] ] = $index;
		}

		// Stable sort by position of the target component.
		$ordered = array();
		foreach ( array_values( $scenarios ) as $index => $scenario ) {
			if ( isset( $scenario['action'] ) && 'block' === $scenario['action'] ) {
				// With no target component: evaluated last, once every
				// hiding is known.
				$ordered[] = array( PHP_INT_MAX, $index, $scenario );
				continue;
			}
			if ( ! isset( $positions[ $scenario['target_component'] ] ) ) {
				continue;
			}
			$ordered[] = array( $positions[ $scenario['target_component'] ], $index, $scenario );
		}
		usort(
			$ordered,
			static function ( $a, $b ) {
				return ( $a[0] === $b[0] ) ? $a[1] - $b[1] : $a[0] - $b[0];
			}
		);

		$hidden_components = array();
		$hidden_options    = array();
		$blocked           = array();

		foreach ( $ordered as $entry ) {
			$scenario = $entry[2];

			if ( ! self::is_active( $scenario, $selections, $hidden_components, $context ) ) {
				continue;
			}

			$target = $scenario['target_component'];

			if ( 'block' === $scenario['action'] ) {
				$blocked[] = array(
					'id'                => $scenario['id'],
					'message'           => isset( $scenario['message'] ) ? $scenario['message'] : '',
					'restart_component' => isset( $scenario['restart_component'] ) ? $scenario['restart_component'] : '',
				);
			} elseif ( 'mask_component' === $scenario['action'] ) {
				$hidden_components[ $target ] = true;
			} else {
				foreach ( $scenario['masked_options'] as $value ) {
					$hidden_options[ $target ][ (string) $value ] = true;
				}
			}
		}

		$out_options = array();
		foreach ( $hidden_options as $component_id => $values ) {
			$out_options[ $component_id ] = array_map( 'strval', array_keys( $values ) );
		}

		return array(
			'hidden_components' => array_map( 'strval', array_keys( $hidden_components ) ),
			'hidden_options'    => $out_options,
			'blocked'           => $blocked,
		);
	}

	/**
	 * DISPLAY projection of the journey: which components would be visible if
	 * every component not yet decided took its “representative” value. Used only
	 * by the summary and the step counter of the configurator: the real
	 * selections, the prices, the validation and the cart never depend on it.
	 *
	 * Walked in component order (the cascade only goes downstream): for every
	 * component visible in the projection, neither selected nor decided, the
	 * first unmasked candidate is taken, then everything is re-evaluated — the
	 * visibility of the following ones depends on that choice. Same engine as
	 * everywhere else (evaluate()), no rule copied over.
	 *
	 * @param array $components Normalised components (ordered).
	 * @param array $scenarios  Normalised scenarios.
	 * @param array $selections REAL normalised selections (left unchanged).
	 * @param array $decided    Identifiers of the components already decided by
	 *                          the customer (step passed, “None” chosen): nothing
	 *                          is injected into them, even without a selection.
	 * @param array $candidates [component_id => selection[]]: representative
	 *                          values by order of preference (the configured
	 *                          default first). Absent or empty = the component
	 *                          stays without a selection in the projection.
	 * @param array $context    Customer context (see evaluate()).
	 * @return string[] Components hidden in the projection.
	 */
	public static function project( array $components, array $scenarios, array $selections, array $decided, array $candidates, array $context = array() ) {
		$decided   = array_flip( array_map( 'strval', $decided ) );
		$projected = $selections;

		foreach ( $components as $component ) {
			$cid = $component['id'];
			if ( isset( $decided[ $cid ] ) || self::selection_items( isset( $projected[ $cid ] ) ? $projected[ $cid ] : null ) ) {
				continue;
			}
			if ( empty( $candidates[ $cid ] ) ) {
				continue;
			}
			$evaluation = self::evaluate( $components, $scenarios, $projected, $context );
			if ( in_array( (string) $cid, $evaluation['hidden_components'], true ) ) {
				continue;
			}
			$hidden = isset( $evaluation['hidden_options'][ $cid ] ) ? $evaluation['hidden_options'][ $cid ] : array();
			foreach ( $candidates[ $cid ] as $candidate ) {
				// Candidate of a multiple component: a list of one element.
				$masked = false;
				foreach ( self::selection_items( $candidate ) as $item ) {
					$masked = $masked || ( ! empty( $item['option_id'] )
						? in_array( (string) $item['option_id'], $hidden, true )
						: self::is_option_hidden( $item['product_id'], 0, $hidden ) );
				}
				if ( ! $masked ) {
					$projected[ $cid ] = $candidate;
					break;
				}
			}
		}

		$evaluation = self::evaluate( $components, $scenarios, $projected, $context );
		return $evaluation['hidden_components'];
	}

	/**
	 * @param array $scenario          Scenario.
	 * @param array $selections        Current selections.
	 * @param array $hidden_components Components already hidden (keys).
	 * @param array $context           Customer context (see evaluate()).
	 * @return bool
	 */
	private static function is_active( array $scenario, array $selections, array $hidden_components, array $context = array() ) {
		if ( ! empty( $scenario['customer'] ) && ! self::customer_matches( $scenario['customer'], $context ) ) {
			return false;
		}
		foreach ( $scenario['conditions'] as $source => $condition ) {
			$selection = null;
			$has       = (bool) self::selection_items( isset( $selections[ $source ] ) ? $selections[ $source ] : null );
			if ( ! isset( $hidden_components[ $source ] ) && $has ) {
				$selection = $selections[ $source ];
			}

			$match = self::selection_matches( $selection, $condition['values'] );

			if ( 'not_in' === $condition['mode'] ) {
				$match = ! $match;
			}

			if ( ! $match ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Customer condition. The visitor (0) is never “in” a list: “in” is false,
	 * “not_in” true.
	 *
	 * @param array $condition { mode: in|not_in, ids: int[] }.
	 * @param array $context   ['customer_id' => int].
	 * @return bool
	 */
	private static function customer_matches( array $condition, array $context ) {
		$customer = isset( $context['customer_id'] ) ? (int) $context['customer_id'] : 0;
		$ids      = isset( $condition['ids'] ) ? array_map( 'intval', (array) $condition['ids'] ) : array();
		$in       = $customer > 0 && in_array( $customer, $ids, true );
		return ( isset( $condition['mode'] ) && 'not_in' === $condition['mode'] ) ? ! $in : $in;
	}

	/**
	 * Items of a component selection: [] without a selection, [ item ] for a
	 * radio component or a branch, the list itself for a multiple-choice
	 * component. An item with neither a product nor an option (degenerate shape)
	 * does not count: the same reading as before (“no selection”).
	 *
	 * @param mixed $selection Selection of a component, or null.
	 * @return array[]
	 */
	public static function selection_items( $selection ) {
		if ( empty( $selection ) || ! is_array( $selection ) ) {
			return array();
		}
		if ( array_key_exists( 'product_id', $selection ) || array_key_exists( 'option_id', $selection ) ) {
			return ( ! empty( $selection['product_id'] ) || ! empty( $selection['option_id'] ) ) ? array( $selection ) : array();
		}
		$items = array();
		foreach ( $selection as $item ) {
			if ( is_array( $item ) && ( ! empty( $item['product_id'] ) || ! empty( $item['option_id'] ) ) ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * A selection matches a list of values when:
	 *  - there is no selection (or an empty list) and "none" appears in the list;
	 *  - or a chosen item matches it (item_matches()) — for a radio component,
	 *    its single item.
	 *
	 * @param array|null $selection Selection or null.
	 * @param string[]   $values    Values.
	 * @return bool
	 */
	public static function selection_matches( $selection, array $values ) {
		$items = self::selection_items( $selection );
		if ( ! $items ) {
			return in_array( 'none', $values, true );
		}
		foreach ( $items as $item ) {
			if ( self::item_matches( $item, $values ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A chosen item matches a list of values when:
	 *  - the product appears in the list ("123");
	 *  - or the chosen variation appears in it ("123:456");
	 *  - or, for a variable product with no variation chosen yet, "123:0" appears
	 *    in it.
	 *
	 * @param array    $selection Item (product or branch option).
	 * @param string[] $values    Values.
	 * @return bool
	 */
	private static function item_matches( array $selection, array $values ) {
		// “path” component: the selection is an option identifier, not
		// a product.
		if ( ! empty( $selection['option_id'] ) ) {
			return in_array( (string) $selection['option_id'], $values, true );
		}

		$pid = (string) absint( $selection['product_id'] );
		if ( in_array( $pid, $values, true ) ) {
			return true;
		}

		// Variation chosen: "123:456". Variable product chosen WITHOUT a
		// variation (intermediate state of the configurator): "123:0" — the
		// equivalent of WCCP's "123_empty" key.
		$vid = ! empty( $selection['variation_id'] ) ? absint( $selection['variation_id'] ) : 0;
		return in_array( $pid . ':' . $vid, $values, true );
	}

	/**
	 * Is an option (product, possibly a variation) hidden?
	 *
	 * @param int      $product_id   Product.
	 * @param int      $variation_id Variation (0 when there is none).
	 * @param string[] $hidden       Hidden values of the component.
	 * @return bool
	 */
	public static function is_option_hidden( $product_id, $variation_id, array $hidden ) {
		if ( in_array( (string) absint( $product_id ), $hidden, true ) ) {
			return true;
		}
		return $variation_id && in_array( absint( $product_id ) . ':' . absint( $variation_id ), $hidden, true );
	}
}
