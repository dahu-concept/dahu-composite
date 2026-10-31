<?php
/**
 * Public visibility of the options, and per-customer exceptions.
 *
 * Three pieces, one single hiding mechanism (mask_options):
 *
 *  1. On every product option of a component: `is_public` (the whole option)
 *     and `private_variations` (identifiers of the variations that are NOT
 *     public — the equivalent of one `is_public` per variation). Key absent =
 *     public: an older saved configuration does not change.
 *
 *  2. Per kit, a list of exceptions (meta _dahu_kit_customer_exceptions): a
 *     WooCommerce customer (user ID), a component, and either access to
 *     EVERYTHING reserved on that component (`all`), or a precise list of
 *     reserved values unlocked on top of the public ones (`list`).
 *
 *  3. Scenarios GENERATED when reading, never saved (and therefore never out
 *     of step with the checkboxes or the exceptions):
 *       - dahu-public-{component}: mask_options of every reserved value, with
 *         a “not_in” customer condition = every customer holding an exception
 *         on that component (no condition when there is none);
 *       - dahu-exception-{component}-{customer}: for a “list” exception,
 *         mask_options of the reserved values MINUS the unlocked ones, with an
 *         “in” customer condition = that customer. An “all” exception has no
 *         rule of its own: it takes the customer out of the public rule.
 *     They are added to the scenarios of the kit (WC_Product_Dahu_Kit::
 *     get_kit_scenarios()) and evaluated by the same engine as the others. The
 *     other rules (motor compatibility and so on) still apply: an exception
 *     lifts the reservation, not an incompatibility.
 *
 * The customer condition (`customer` key of a scenario: mode in / not_in, ids)
 * is generic: a hand-written scenario can carry it too.
 *
 * Current customer: the logged-in user (get_current_user_id()) — page request
 * or REST request authenticated by nonce. The administration simulator may
 * impose another one (set_simulated_customer(), “configure online” mode,
 * restricted to product managers). A visitor who is not logged in is customer
 * 0: they appear in no exception.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Customer_Access {

	const META_EXCEPTIONS = '_dahu_kit_customer_exceptions';

	/** Prefixes of the generated scenarios (reserved: a manual scenario cannot carry them). */
	const PREFIX_PUBLIC    = 'dahu-public-';
	const PREFIX_EXCEPTION = 'dahu-exception-';

	/** @var int|null Customer imposed by the administration simulator. */
	private static $simulated = null;

	/**
	 * @param int|null $customer_id Customer to simulate (0 = visitor), null = none.
	 */
	public static function set_simulated_customer( $customer_id ) {
		self::$simulated = null === $customer_id ? null : absint( $customer_id );
	}

	/**
	 * @return int Current customer (0 = visitor not logged in).
	 */
	public static function current_customer_id() {
		if ( null !== self::$simulated ) {
			return self::$simulated;
		}
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Saved exceptions of a kit, normalised against its components.
	 *
	 * @param int   $product_id Kit.
	 * @param array $components Normalised components.
	 * @return array
	 */
	public static function get_exceptions( $product_id, array $components ) {
		$raw = get_post_meta( $product_id, self::META_EXCEPTIONS, true );
		return is_array( $raw ) ? self::sanitize_exceptions( $raw, $components ) : array();
	}

	/**
	 * Normalises the exceptions. One entry per (customer, component) pair: two
	 * entries for the same pair are merged (“all” wins, the lists add up).
	 *
	 * @param array    $raw        Raw exceptions.
	 * @param array    $components Normalised components.
	 * @param string[] $errors     Warnings (by reference).
	 * @return array[] [ { id, customer_id, component_id, access, values, note } ]
	 */
	public static function sanitize_exceptions( array $raw, array $components, array &$errors = array() ) {
		$product_components = array();
		foreach ( $components as $component ) {
			if ( 'path' !== $component['component_type'] ) {
				$product_components[ $component['id'] ] = $component;
			}
		}

		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$customer  = isset( $item['customer_id'] ) ? absint( $item['customer_id'] ) : 0;
			$component = isset( $item['component_id'] ) ? sanitize_title( (string) $item['component_id'] ) : '';
			if ( ! $customer ) {
				$errors[] = __( 'Customer exception with no customer: ignored.', 'dahu-composite' );
				continue;
			}
			if ( ! isset( $product_components[ $component ] ) ) {
				/* translators: 1: customer, 2: component */
				$errors[] = sprintf( __( 'Exception for customer #%1$d: unknown component “%2$s” (or a branching one); exception ignored.', 'dahu-composite' ), $customer, $component );
				continue;
			}
			$access = ( isset( $item['access'] ) && 'list' === $item['access'] ) ? 'list' : 'all';
			$values = array();
			if ( 'list' === $access ) {
				$values = Dahu_Composite_Kit_Config::sanitize_value_list( isset( $item['values'] ) ? $item['values'] : array() );
				if ( ! $values ) {
					/* translators: 1: customer, 2: component */
					$errors[] = sprintf( __( 'Exception for customer #%1$d on “%2$s”: no variation to unlock; exception ignored.', 'dahu-composite' ), $customer, $component );
					continue;
				}
			}
			$note = isset( $item['note'] ) ? sanitize_text_field( (string) $item['note'] ) : '';
			$key  = $customer . '|' . $component;

			if ( isset( $out[ $key ] ) ) {
				/* translators: 1: customer, 2: component */
				$errors[] = sprintf( __( 'Two exceptions for customer #%1$d on “%2$s”: merged.', 'dahu-composite' ), $customer, $component );
				if ( 'all' === $access || 'all' === $out[ $key ]['access'] ) {
					$out[ $key ]['access'] = 'all';
					$out[ $key ]['values'] = array();
				} else {
					$out[ $key ]['values'] = array_values( array_unique( array_merge( $out[ $key ]['values'], $values ) ) );
				}
				continue;
			}

			$out[ $key ] = array(
				'id'           => 'exc-' . $customer . '-' . $component,
				'customer_id'  => $customer,
				'component_id' => $component,
				'access'       => $access,
				'values'       => $values,
				'note'         => $note,
			);
		}
		return array_values( $out );
	}

	/**
	 * Variations of an option product (empty for a simple or missing product).
	 * Public, because it also serves the complement of a “show only” scenario
	 * (Dahu_Composite_Kit_Config).
	 *
	 * @param int $product_id Product.
	 * @return int[]
	 */
	public static function variation_ids( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return array();
		}
		return array_map( 'absint', $product->get_children() );
	}

	/**
	 * Reserved (non-public) values of a component, in the scenario format:
	 * "123" (simple product, or variable product ALL of whose variations are
	 * reserved) and "123:456" (variation). A variable product reserved as a whole
	 * is expanded into its variations: an exception can then unlock just one of
	 * them.
	 *
	 * @param array $component Normalised component.
	 * @return string[]
	 */
	public static function reserved_values( array $component ) {
		if ( 'path' === $component['component_type'] ) {
			return array();
		}
		$values = array();
		foreach ( $component['options'] as $option ) {
			$pid     = (int) $option['product_id'];
			$public  = ! isset( $option['is_public'] ) || false !== $option['is_public'];
			$private = isset( $option['private_variations'] ) ? array_map( 'absint', (array) $option['private_variations'] ) : array();
			if ( $public && ! $private ) {
				continue;
			}
			$children = self::variation_ids( $pid );
			if ( ! $children ) {
				// Simple product (or not found): only the whole option can
				// be reserved; a list of variations makes no sense.
				if ( ! $public ) {
					$values[] = (string) $pid;
				}
				continue;
			}
			$reserved = $public ? array_values( array_intersect( $children, $private ) ) : $children;
			foreach ( $reserved as $vid ) {
				$values[] = $pid . ':' . $vid;
			}
			if ( $reserved && count( $reserved ) === count( $children ) ) {
				$values[] = (string) $pid;
			}
		}
		return array_values( array_unique( $values ) );
	}

	/**
	 * Reserved values that stay hidden for a “list” exception: reserved minus
	 * unlocked. "123" unlocked = every variation of 123. The product "123" is
	 * hidden only when ALL of its variations stay hidden (otherwise its card must
	 * appear).
	 *
	 * @param string[] $reserved Reserved values of the component.
	 * @param string[] $unlocked Unlocked values.
	 * @return string[]
	 */
	public static function remaining_values( array $reserved, array $unlocked ) {
		$unlock = array();
		foreach ( $unlocked as $value ) {
			$unlock[ $value ] = true;
			if ( false === strpos( $value, ':' ) ) {
				foreach ( self::variation_ids( (int) $value ) as $vid ) {
					$unlock[ $value . ':' . $vid ] = true;
				}
			}
		}
		$remaining = array();
		foreach ( $reserved as $value ) {
			if ( ! isset( $unlock[ $value ] ) ) {
				$remaining[ $value ] = true;
			}
		}
		// Whole product: only when none of its variations is unlocked.
		foreach ( array_keys( $remaining ) as $value ) {
			if ( false !== strpos( $value, ':' ) ) {
				continue;
			}
			foreach ( self::variation_ids( (int) $value ) as $vid ) {
				if ( ! isset( $remaining[ $value . ':' . $vid ] ) ) {
					unset( $remaining[ $value ] );
					break;
				}
			}
		}
		return array_map( 'strval', array_keys( $remaining ) );
	}

	/**
	 * Generated scenarios (see the file header), already normalised.
	 *
	 * @param array $components Normalised components.
	 * @param array $exceptions Normalised exceptions.
	 * @return array[]
	 */
	public static function generated_scenarios( array $components, array $exceptions ) {
		$by_component = array();
		foreach ( $exceptions as $exception ) {
			$by_component[ $exception['component_id'] ][] = $exception;
		}

		$scenarios = array();
		foreach ( $components as $component ) {
			$reserved = self::reserved_values( $component );
			if ( ! $reserved ) {
				continue;
			}
			$cid       = $component['id'];
			$excluded  = array();
			$per_list  = array();
			foreach ( isset( $by_component[ $cid ] ) ? $by_component[ $cid ] : array() as $exception ) {
				$excluded[] = (int) $exception['customer_id'];
				if ( 'list' === $exception['access'] ) {
					$per_list[] = $exception;
				}
			}
			$excluded = array_values( array_unique( $excluded ) );
			sort( $excluded );

			$public = array(
				'id'                => self::PREFIX_PUBLIC . $cid,
				'action'            => 'mask_options',
				'target_component'  => $cid,
				'conditions'        => array(),
				'masked_options'    => $reserved,
				'message'           => '',
				'restart_component' => '',
				'generated'         => 'public',
			);
			if ( $excluded ) {
				$public['customer'] = array(
					'mode' => 'not_in',
					'ids'  => $excluded,
				);
			}
			$scenarios[] = $public;

			foreach ( $per_list as $exception ) {
				$remaining = self::remaining_values( $reserved, $exception['values'] );
				if ( ! $remaining ) {
					continue; // Everything is unlocked: no hiding for this customer.
				}
				$scenarios[] = array(
					'id'                => self::PREFIX_EXCEPTION . $cid . '-' . (int) $exception['customer_id'],
					'action'            => 'mask_options',
					'target_component'  => $cid,
					'conditions'        => array(),
					'masked_options'    => $remaining,
					'message'           => '',
					'restart_component' => '',
					'customer'          => array(
						'mode' => 'in',
						'ids'  => array( (int) $exception['customer_id'] ),
					),
					'generated'         => 'exception',
				);
			}
		}
		return $scenarios;
	}

	/**
	 * Label of a customer for the administration: “Name — e-mail (#id)”.
	 *
	 * @param int $customer_id User.
	 * @return string
	 */
	public static function customer_label( $customer_id ) {
		$user = get_userdata( (int) $customer_id );
		if ( ! $user ) {
			/* translators: %d: user identifier */
			return sprintf( __( 'Customer #%d (not found)', 'dahu-composite' ), (int) $customer_id );
		}
		$name = trim( $user->first_name . ' ' . $user->last_name );
		if ( '' === $name ) {
			$name = $user->display_name;
		}
		return sprintf( '%s — %s (#%d)', $name, $user->user_email, (int) $customer_id );
	}
}
