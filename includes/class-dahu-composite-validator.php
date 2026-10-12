<?php
/**
 * Configuration safety nets: warnings shown when saving and in the simulator of
 * the administration screen.
 *
 * Nothing is blocking. An inconsistent configuration stays saveable: the
 * normalisation (Dahu_Composite_Kit_Config) already discards whatever would be
 * dangerous at runtime, and these messages only say where the input does not
 * mean what one thinks.
 *
 * Five families of checks:
 *  1. dead references — a scenario points at a component or an option that no
 *     longer exists;
 *  2. cascade — a condition bears on a component placed AFTER its target;
 *  3. dead ends — a mask_options (or show_options) scenario that, for a
 *     combination of selections actually reachable, would hide ALL the options
 *     of its target component;
 *  4. categories (ranges) — an option attached to a category that does not
 *     exist, a category with no option, two categories with the same label;
 *  5. anchor of an add-on — anchor component or option not found, not on the
 *     same screen, anchor set on a component that is not an add-on. The front
 *     end then falls back to the usual place.
 *
 * Points 1 and 2 are already detected by the normalisation, which discards them
 * silently: we collect its messages rather than doing the work twice. Point 3
 * existed nowhere before.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Validator {

	/**
	 * Maximum number of combinations explored per target component. Beyond that,
	 * the dead-end check is declared inconclusive rather than silently truncated.
	 */
	const MAX_COMBINATIONS = 5000;

	/**
	 * Warnings for a RAW configuration (as it was entered).
	 *
	 * @param array $raw_components Raw components.
	 * @param array $raw_scenarios  Raw scenarios.
	 * @return array {
	 *   @type string[] $warnings   Messages.
	 *   @type array    $components Normalised components.
	 *   @type array    $scenarios  Normalised scenarios.
	 * }
	 */
	public static function check( array $raw_components, array $raw_scenarios, $raw_exceptions = null ) {
		$warnings = array();

		// 1 and 2: the normalisation already reports unknown targets, conditions
		// outside the cascade and scenarios emptied of their meaning.
		$components = Dahu_Composite_Kit_Config::sanitize_components( $raw_components, $warnings );
		$scenarios  = Dahu_Composite_Kit_Config::sanitize_scenarios( $raw_scenarios, $components, $warnings );
		// Customer exceptions: null = not supplied.
		$exceptions = is_array( $raw_exceptions ) ? Dahu_Composite_Customer_Access::sanitize_exceptions( $raw_exceptions, $components, $warnings ) : null;

		$warnings = array_merge(
			$warnings,
			self::dangling_values( $components, $scenarios ),
			self::tier_warnings( $components ),
			self::anchor_warnings( $components ),
			self::dead_ends( $components, $scenarios ),
			null === $exceptions ? array() : self::exception_warnings( $components, $exceptions )
		);

		return array(
			'warnings'   => array_values( array_unique( $warnings ) ),
			'components' => $components,
			'scenarios'  => $scenarios,
			'exceptions' => $exceptions,
		);
	}

	/**
	 * Customer exceptions with no effect: unknown customer, component with
	 * nothing reserved, unlocked value that is not reserved. Not blocking.
	 *
	 * @param array $components Normalised components.
	 * @param array $exceptions Normalised exceptions.
	 * @return string[]
	 */
	private static function exception_warnings( array $components, array $exceptions ) {
		$warnings = array();
		$reserved = array();
		$titles   = array();
		foreach ( $components as $component ) {
			$reserved[ $component['id'] ] = Dahu_Composite_Customer_Access::reserved_values( $component );
			$titles[ $component['id'] ]   = '' !== $component['title'] ? $component['title'] : $component['id'];
		}
		foreach ( $exceptions as $exception ) {
			$cid   = $exception['component_id'];
			$label = Dahu_Composite_Customer_Access::customer_label( $exception['customer_id'] );
			if ( function_exists( 'get_userdata' ) && ! get_userdata( $exception['customer_id'] ) ) {
				/* translators: 1: customer, 2: component */
				$warnings[] = sprintf( __( 'Exception “%1$s” on “%2$s”: this customer does not exist on this site.', 'dahu-composite' ), $label, $titles[ $cid ] );
			}
			if ( empty( $reserved[ $cid ] ) ) {
				/* translators: 1: customer, 2: component */
				$warnings[] = sprintf( __( 'Exception “%1$s” on “%2$s”: nothing is restricted on this component, so it has no effect.', 'dahu-composite' ), $label, $titles[ $cid ] );
				continue;
			}
			foreach ( $exception['values'] as $value ) {
				$expanded = in_array( $value, $reserved[ $cid ], true );
				if ( ! $expanded && false === strpos( $value, ':' ) ) {
					foreach ( $reserved[ $cid ] as $r ) {
						$expanded = $expanded || 0 === strpos( $r, $value . ':' );
					}
				}
				if ( ! $expanded ) {
					/* translators: 1: customer, 2: value, 3: component */
					$warnings[] = sprintf( __( 'Exception “%1$s”: “%2$s” is not restricted on “%3$s” (already public, or absent) — no effect.', 'dahu-composite' ), $label, $value, $titles[ $cid ] );
				}
			}
		}
		return $warnings;
	}

	/**
	 * Inconsistent categories (ranges). Not blocking: an option attached to a
	 * category that does not exist simply shows with no category.
	 *
	 * @param array $components Normalised components.
	 * @return string[]
	 */
	private static function tier_warnings( array $components ) {
		$warnings = array();
		foreach ( $components as $component ) {
			$title  = '' !== $component['title'] ? $component['title'] : $component['id'];
			$tiers  = isset( $component['tiers'] ) ? $component['tiers'] : array();
			$used   = array();
			$labels = array();
			$known  = array();
			foreach ( $tiers as $tier ) {
				$known[ $tier['id'] ] = true;
			}
			foreach ( $component['options'] as $option ) {
				if ( empty( $option['tier'] ) ) {
					continue;
				}
				if ( isset( $known[ $option['tier'] ] ) ) {
					$used[ $option['tier'] ] = true;
					continue;
				}
				$name       = isset( $option['label'] ) ? $option['label'] : '#' . $option['product_id'];
				$warnings[] = sprintf(
					/* translators: 1: component, 2: option, 3: category identifier */
					__( '“%1$s”: option “%2$s” is attached to category “%3$s”, which does not exist. It is shown without a category.', 'dahu-composite' ),
					$title,
					$name,
					$option['tier']
				);
			}
			foreach ( $tiers as $tier ) {
				if ( ! isset( $used[ $tier['id'] ] ) ) {
					$warnings[] = sprintf(
						/* translators: 1: component, 2: category */
						__( '“%1$s”: category “%2$s” has no options.', 'dahu-composite' ),
						$title,
						$tier['label']
					);
				}
				$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tier['label'] ) : strtolower( $tier['label'] );
				if ( isset( $labels[ $key ] ) ) {
					$warnings[] = sprintf(
						/* translators: 1: component, 2: label */
						__( '“%1$s”: two categories are called “%2$s”.', 'dahu-composite' ),
						$title,
						$tier['label']
					);
				}
				$labels[ $key ] = true;
			}
		}
		return $warnings;
	}

	/**
	 * “Add-on” component: secondary, optional, a single option, not a branch.
	 * Same definition as the front end (DahuCompositeTiers.isAddon(),
	 * dahu-composite-tiers.js).
	 *
	 * @param array $component Normalised component.
	 * @return bool
	 */
	private static function is_addon( array $component ) {
		return 'path' !== $component['component_type']
			&& ( ! isset( $component['selection_mode'] ) || 'multiple' !== $component['selection_mode'] )
			&& isset( $component['title_role'] ) && 'secondary' === $component['title_role']
			&& ! empty( $component['optional'] )
			&& 1 === count( $component['options'] );
	}

	/**
	 * Add-on anchors (anchor_component_id / anchor_product_id) that lead to no
	 * card. Not blocking: the add-on simply shows in its usual place (the front
	 * end says so in the console too).
	 *
	 * @param array $components Normalised components.
	 * @return string[]
	 */
	private static function anchor_warnings( array $components ) {
		$warnings = array();
		$by_id    = array();
		foreach ( $components as $component ) {
			$by_id[ $component['id'] ] = $component;
		}
		foreach ( $components as $component ) {
			// “question” presentation: reserved for an add-on.
			if ( isset( $component['presentation'] ) && 'question' === $component['presentation'] && ! self::is_addon( $component ) ) {
				$warnings[] = sprintf(
					/* translators: %s: component */
					__( '“%s”: the “Yes / no question” presentation only applies to an “add-on” component (secondary, optional, a single option). It is shown as cards.', 'dahu-composite' ),
					'' !== $component['title'] ? $component['title'] : $component['id']
				);
			}
			// With no anchor declared, an add-on follows the chosen card of the first
			// product component of its screen (front end): nothing to check.
			if ( empty( $component['anchor_component_id'] ) ) {
				continue;
			}
			$title   = '' !== $component['title'] ? $component['title'] : $component['id'];
			$host_id = $component['anchor_component_id'];
			$product = ! empty( $component['anchor_product_id'] ) ? (int) $component['anchor_product_id'] : 0;

			if ( ! self::is_addon( $component ) ) {
				$warnings[] = sprintf(
					/* translators: %s: component */
					__( '“%s”: the “Anchor next to” setting only applies to an “add-on” component (secondary, optional, a single option). Ignored.', 'dahu-composite' ),
					$title
				);
				continue;
			}
			if ( $host_id === $component['id'] || ! isset( $by_id[ $host_id ] ) ) {
				$warnings[] = sprintf(
					/* translators: 1: component, 2: identifier of the anchor component */
					__( '“%1$s”: anchored next to component “%2$s”, which does not exist. It is shown in its usual place.', 'dahu-composite' ),
					$title,
					$host_id
				);
				continue;
			}
			$host       = $by_id[ $host_id ];
			$host_title = '' !== $host['title'] ? $host['title'] : $host['id'];
			if ( 'path' === $host['component_type'] || self::is_addon( $host ) ) {
				$warnings[] = sprintf(
					/* translators: 1: component, 2: anchor component */
					__( '“%1$s”: anchored to “%2$s”, which shows no product cards (a branching or add-on component). It is shown in its usual place.', 'dahu-composite' ),
					$title,
					$host_title
				);
				continue;
			}
			// Component alone: next to the chosen card, whichever it is.
			$found = ! $product;
			foreach ( $host['options'] as $option ) {
				if ( isset( $option['product_id'] ) && (int) $option['product_id'] === $product ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$warnings[] = sprintf(
					/* translators: 1: component, 2: product, 3: anchor component */
					__( '“%1$s”: anchored next to product #%2$d, which is not among the options of “%3$s”. It is shown in its usual place.', 'dahu-composite' ),
					$title,
					$product,
					$host_title
				);
				continue;
			}
			if ( '' === $component['display_group'] || $component['display_group'] !== $host['display_group'] ) {
				$warnings[] = sprintf(
					/* translators: 1: component, 2: anchor component */
					__( '“%1$s”: anchored to “%2$s”, which is not on the same screen (a different display group). It is shown in its usual place.', 'dahu-composite' ),
					$title,
					$host_title
				);
			}
		}
		return $warnings;
	}

	/**
	 * Condition or masked-option values that match no option of the component
	 * concerned. Typical case: an option removed from a component while scenarios
	 * still cited it.
	 *
	 * @param array $components Normalised components.
	 * @param array $scenarios  Normalised scenarios.
	 * @return string[]
	 */
	private static function dangling_values( array $components, array $scenarios ) {
		$known    = array();
		$titles   = array();
		foreach ( $components as $component ) {
			$known[ $component['id'] ]  = self::known_values( $component );
			$titles[ $component['id'] ] = '' !== $component['title'] ? $component['title'] : $component['id'];
		}

		$warnings = array();

		foreach ( $scenarios as $scenario ) {
			foreach ( $scenario['conditions'] as $source => $condition ) {
				foreach ( $condition['values'] as $value ) {
					if ( 'none' === $value || isset( $known[ $source ][ $value ] ) ) {
						continue;
					}
					$warnings[] = sprintf(
						/* translators: 1: scenario, 2: value, 3: component */
						__( 'Scenario “%1$s”: the condition refers to “%2$s”, which is no longer among the options of “%3$s”.', 'dahu-composite' ),
						$scenario['id'],
						$value,
						isset( $titles[ $source ] ) ? $titles[ $source ] : $source
					);
				}
			}

			$target = $scenario['target_component'];
			// “Show only”: masked_options is a computed
			// complement, always valid; it is the list that was entered
			// that we check.
			foreach ( isset( $scenario['shown_options'] ) ? $scenario['shown_options'] : array() as $value ) {
				if ( isset( $known[ $target ][ $value ] ) ) {
					continue;
				}
				$warnings[] = sprintf(
					/* translators: 1: scenario, 2: value, 3: component */
					__( 'Scenario “%1$s”: shows only “%2$s”, which is no longer among the options of “%3$s”.', 'dahu-composite' ),
					$scenario['id'],
					$value,
					isset( $titles[ $target ] ) ? $titles[ $target ] : $target
				);
			}
			foreach ( $scenario['masked_options'] as $value ) {
				if ( isset( $known[ $target ][ $value ] ) ) {
					continue;
				}
				$warnings[] = sprintf(
					/* translators: 1: scenario, 2: value, 3: component */
					__( 'Scenario “%1$s”: hides “%2$s”, which is no longer among the options of “%3$s”.', 'dahu-composite' ),
					$scenario['id'],
					$value,
					isset( $titles[ $target ] ) ? $titles[ $target ] : $target
				);
			}
		}

		return $warnings;
	}

	/**
	 * Acceptable values for a component: option identifiers (path), or products
	 * and variations (product), including the intermediate state “variable product
	 * chosen, variation not chosen yet” (`123:0`).
	 *
	 * @param array $component Normalised component.
	 * @return array Values as keys.
	 */
	private static function known_values( array $component ) {
		$values = array();

		if ( Dahu_Composite_Engine::is_path( $component ) ) {
			foreach ( $component['options'] as $option ) {
				$values[ (string) $option['id'] ] = true;
			}
			return $values;
		}

		foreach ( $component['options'] as $option ) {
			$pid            = (int) $option['product_id'];
			$values[ (string) $pid ] = true;
			$values[ $pid . ':0' ]   = true;

			$product = wc_get_product( $pid );
			if ( $product && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$values[ $pid . ':' . (int) $variation_id ] = true;
				}
			}
		}

		return $values;
	}

	/**
	 * Components ALL of whose options would be hidden for at least one reachable
	 * combination of selections.
	 *
	 * The sweep does not explore every possible configuration — that would be
	 * exponential. Two restrictions keep it small without losing anything:
	 *  - only the components targeted by at least one mask_options are examined
	 *    (nothing else can empty a component of its options);
	 *  - for such a component, only the selections of the components cited as a
	 *    condition by those mask_options count, plus those that condition the
	 *    display of the latter (a hidden component counts as “no selection”).
	 * On the kit tree used in production this gives 792 combinations for the worst
	 * target and 2,628 in all, instead of about 23,000 for the worst target alone
	 * without those restrictions.
	 *
	 * @param array $components Normalised components.
	 * @param array $scenarios  Normalised scenarios.
	 * @return string[]
	 */
	private static function dead_ends( array $components, array $scenarios ) {
		$by_id = array();
		foreach ( $components as $component ) {
			$by_id[ $component['id'] ] = $component;
		}

		$warnings = array();

		foreach ( $components as $component ) {
			$target = $component['id'];
			if ( empty( $component['options'] ) ) {
				continue;
			}

			// Only a mask_options (or show_options) can empty a component. With
			// no scenario of that kind targeting it, there is nothing to explore —
			// and that is what keeps the sweep small: on the kit tree we go
			// from about 23,000 combinations for the worst target to 792.
			$sources = self::relevant_sources( $target, $scenarios, $by_id );
			if ( ! $sources ) {
				continue;
			}

			$axes  = array();
			$total = 1;
			foreach ( $sources as $source_id ) {
				$axis  = self::axis_values( $by_id[ $source_id ] );
				$total = $total * count( $axis );
				if ( $total > self::MAX_COMBINATIONS ) {
					/* translators: %s: component */
					$warnings[] = sprintf( __( '“%s”: too many combinations to explore, so the dead-end check could not run. Check it by hand.', 'dahu-composite' ), $component['title'] );
					continue 2;
				}
				$axes[ $source_id ] = $axis;
			}

			$dead = self::first_dead_combination( $component, $axes, $components, $scenarios, $by_id );
			if ( null !== $dead ) {
				$warnings[] = sprintf(
					/* translators: 1: component, 2: description of the combination */
					__( '“%1$s” has no option left available when %2$s. Check the scenarios targeting it.', 'dahu-composite' ),
					$component['title'],
					$dead
				);
			}
		}

		return $warnings;
	}

	/**
	 * Components cited as a condition by the scenarios targeting the target, plus,
	 * step by step, those that condition their own display (a hidden component
	 * counts as “no selection”).
	 *
	 * @param string $target    Target component.
	 * @param array  $scenarios Scenarios.
	 * @param array  $by_id     Components by identifier.
	 * @return string[]
	 */
	private static function relevant_sources( $target, array $scenarios, array $by_id ) {
		$sources = array();
		$wanted  = array();

		// Seed: only the conditions of the mask_options targeting the target.
		// Returns an empty array when none targets it — the sweep is then
		// pointless.
		foreach ( $scenarios as $scenario ) {
			// “Show only” hides options as well.
			if ( ! in_array( $scenario['action'], array( 'mask_options', 'show_options' ), true ) || $scenario['target_component'] !== $target ) {
				continue;
			}
			foreach ( array_keys( $scenario['conditions'] ) as $source ) {
				if ( isset( $by_id[ $source ] ) ) {
					$sources[ $source ] = true;
					$wanted[ $source ]  = true;
				}
			}
		}

		if ( ! $sources ) {
			return array();
		}

		// Then, step by step, the components that condition the
		// display of those sources: a hidden component counts as “no
		// selection”, which changes the result. A hard bound so as never to
		// run indefinitely.
		for ( $pass = 0; $pass < 10; $pass++ ) {
			$before = count( $sources );
			foreach ( $scenarios as $scenario ) {
				if ( 'block' === $scenario['action'] || ! isset( $wanted[ $scenario['target_component'] ] ) ) {
					continue;
				}
				foreach ( array_keys( $scenario['conditions'] ) as $source ) {
					if ( isset( $by_id[ $source ] ) ) {
						$sources[ $source ] = true;
						$wanted[ $source ]  = true;
					}
				}
			}
			if ( count( $sources ) === $before ) {
				break;
			}
		}

		unset( $sources[ $target ] );
		return array_keys( $sources );
	}

	/**
	 * Values tested for a source component: no selection, every option, and every
	 * variation of a variable option.
	 *
	 * @param array $component Component.
	 * @return array[] [ ['selection' => array|null, 'label' => string] ]
	 */
	private static function axis_values( array $component ) {
		$values = array( array( 'selection' => null, 'label' => __( 'no selection', 'dahu-composite' ) ) );

		if ( Dahu_Composite_Engine::is_path( $component ) ) {
			foreach ( $component['options'] as $option ) {
				$values[] = array(
					'selection' => array( 'option_id' => $option['id'] ),
					'label'     => $option['label'],
				);
			}
			return $values;
		}

		foreach ( $component['options'] as $option ) {
			$pid     = (int) $option['product_id'];
			$product = wc_get_product( $pid );
			$name    = $product ? $product->get_name() : '#' . $pid;

			$values[] = array(
				'selection' => array( 'product_id' => $pid ),
				'label'     => $name,
			);

			if ( $product && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					$values[]  = array(
						'selection' => array(
							'product_id'   => $pid,
							'variation_id' => (int) $variation_id,
						),
						'label'     => $name . ' — ' . ( $variation ? wp_strip_all_tags( wc_get_formatted_variation( $variation, true, false, false ) ) : '#' . $variation_id ),
					);
				}
			}
		}

		return $values;
	}

	/**
	 * First reachable combination that hides every option of the target,
	 * described in plain words — or null.
	 *
	 * @param array $component  Target component.
	 * @param array $axes       [component_id => values tested].
	 * @param array $components Components.
	 * @param array $scenarios  Scenarios.
	 * @param array $by_id      Components by identifier.
	 * @return string|null
	 */
	private static function first_dead_combination( array $component, array $axes, array $components, array $scenarios, array $by_id ) {
		$ids   = array_keys( $axes );
		$sizes = array();
		$total = 1;
		foreach ( $ids as $id ) {
			$sizes[ $id ] = count( $axes[ $id ] );
			$total       *= $sizes[ $id ];
		}

		$option_count = count( $component['options'] );

		for ( $n = 0; $n < $total; $n++ ) {
			$rest       = $n;
			$selections = array();
			$labels     = array();

			foreach ( $ids as $id ) {
				$index = $rest % $sizes[ $id ];
				$rest  = (int) ( $rest / $sizes[ $id ] );
				$value = $axes[ $id ][ $index ];
				if ( null !== $value['selection'] ) {
					$selections[ $id ] = $value['selection'];
				}
				$labels[ $id ] = $value['label'];
			}

			$evaluation = Dahu_Composite_Scenarios::evaluate( $components, $scenarios, $selections );
			$hidden     = array_flip( $evaluation['hidden_components'] );

			// Target already hidden entirely: no dead end, the step simply does
			// not exist in that branch.
			if ( isset( $hidden[ $component['id'] ] ) ) {
				continue;
			}

			// Unreachable combination: it gives a selection to a
			// component which, in that same combination, is hidden.
			$reachable = true;
			foreach ( $selections as $id => $unused ) {
				if ( isset( $hidden[ $id ] ) ) {
					$reachable = false;
					break;
				}
			}
			if ( ! $reachable ) {
				continue;
			}

			$masked  = isset( $evaluation['hidden_options'][ $component['id'] ] ) ? $evaluation['hidden_options'][ $component['id'] ] : array();
			$visible = 0;
			foreach ( $component['options'] as $option ) {
				if ( ! self::option_is_masked( $component, $option, $masked ) ) {
					$visible++;
				}
			}

			if ( 0 === $visible && $option_count > 0 ) {
				$parts = array();
				foreach ( $ids as $id ) {
					$title   = '' !== $by_id[ $id ]['title'] ? $by_id[ $id ]['title'] : $id;
					$parts[] = sprintf( '%s = %s', $title, $labels[ $id ] );
				}
				return implode( __( ' and ', 'dahu-composite' ), $parts );
			}
		}

		return null;
	}

	/**
	 * An option is hidden when it is hidden as a whole. For a variable product,
	 * every one of its variations must be hidden too: a single remaining variation
	 * is enough to keep the option available.
	 *
	 * @param array $component Component.
	 * @param array $option    Option.
	 * @param array $masked    Hidden values.
	 * @return bool
	 */
	private static function option_is_masked( array $component, array $option, array $masked ) {
		if ( Dahu_Composite_Engine::is_path( $component ) ) {
			return in_array( (string) $option['id'], $masked, true );
		}

		$pid = (int) $option['product_id'];
		if ( in_array( (string) $pid, $masked, true ) ) {
			return true;
		}

		$product = wc_get_product( $pid );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return false;
		}

		$children = $product->get_children();
		if ( ! $children ) {
			return false;
		}
		foreach ( $children as $variation_id ) {
			if ( ! in_array( $pid . ':' . (int) $variation_id, $masked, true ) ) {
				return false;
			}
		}
		return true;
	}
}
