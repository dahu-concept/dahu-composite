<?php
/**
 * Composition identifier of a kit (format 2).
 *
 * Computed when the kit is added to the cart
 * (Dahu_Composite_Cart::add_kit_to_cart()), kept on the container item, then
 * written on the container row of the order
 * (Dahu_Composite_Order::add_order_item_meta()):
 *
 *   _dahu_composition_id  string  identifier (see build())
 *   _dahu_composition     array   the same data, structured and readable
 *
 * DETERMINISTIC: it depends on the composition alone — same products /
 * variations per component, same quantities per kit — never on the price, the
 * number of kits ordered, the date, the order, the customer, or the order in
 * which the customer made their choices. Two customers who assemble the same
 * kit get the same identifier: it identifies a TYPE of configuration, not an
 * order. It keeps the trace of the composition even when a product later leaves
 * the configurator.
 *
 * It is also the “configuration serial”: build() accepts a PARTIAL
 * configuration (components not chosen yet = “0”), parse() walks the other way
 * to reopen the configurator on those choices (?dahu_kit_serial=…), and
 * for_product() computes on the fly the serial of a configuration that starts
 * with a given product (button on a product page). The FORMAT is unchanged: the
 * identifiers already written on the orders stay identical. None of these three
 * functions stores anything in the database.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Composition {

	/** Row meta: identifier (string). */
	const META_ID = '_dahu_composition_id';

	/** Row meta: structured composition (array). */
	const META_DATA = '_dahu_composition';

	/**
	 * Version of the format (in the structured shape): 1 = empty component with
	 * no segment, 2 = fixed position, “0” sentinel, contents of the bundles.
	 */
	const FORMAT = 2;

	/** Separator between components. */
	const SEP_COMPONENT = '-';

	/** Separator between items of a component (multiple choice, contents of a bundle). */
	const SEP_ITEM = '.';

	/** Separator between an identifier and its quantity per kit. */
	const SEP_QUANTITY = 'x';

	/** Component defined but empty (or hidden): its place is kept. */
	const EMPTY_SEGMENT = '0';

	/** URL parameter that reopens the configurator on a serial. */
	const QUERY_ARG = 'dahu_kit_serial';

	/** Maximum length of a serial being read (safety net; a real kit makes about 60). */
	const MAX_LENGTH = 1000;

	/** Maximum number of branch combinations tried by for_product(). */
	const MAX_PATH_COMBINATIONS = 256;

	/**
	 * Identifier and structured shape of a VALIDATED configuration.
	 *
	 * Identifier = segments separated by “-”. Every component defined in the
	 * editor ALWAYS occupies the same position (order of definition,
	 * get_kit_components(), never the order in which choices were made), filled
	 * or not:
	 *  1. first segment, type of kit: the chosen option of every branch component
	 *     (`path`, e.g. “rear_wheel_kit”), several branches joined by “.” (“0” for
	 *     a branch with no choice); kit with no branch: “kit<ID of the kit
	 *     product>”;
	 *  2. then ONE segment per product component, in order: the ID of the chosen
	 *     variation, else that of the product; a quantity per kit above 1 after
	 *     “x” (“14005x2”); multiple choice: every item, sorted by ascending ID,
	 *     joined by “.”; component of the bundle type (Product Bundles): the ID of
	 *     the bundle, then, joined by “.” and sorted, the items whose content
	 *     depends on the customer (optional items ticked, items with several
	 *     possible variants: ID of the variant); component empty or hidden by a
	 *     scenario: “0”.
	 * E.g.: “rear_wheel_kit-51010-0-14011-17008-0-49984-29298.45138-0-0-0-0-0”.
	 * Intended consequence: adding, removing or reordering a component in the
	 * editor changes the identifier of the configurations of that kit.
	 *
	 * @param WC_Product_Dahu_Kit $kit        Kit.
	 * @param array               $selections Normalised and validated selections.
	 * @return array { id: string, data: array }
	 */
	public static function build( WC_Product_Dahu_Kit $kit, array $selections ) {
		$hidden     = array_flip( $kit->evaluate_scenarios( $selections )['hidden_components'] );
		$types      = array();
		$segments   = array();
		$components = array();

		foreach ( $kit->get_kit_components() as $position => $component ) {
			$cid      = $component['id'];
			$selected = isset( $selections[ $cid ] ) && ! isset( $hidden[ $cid ] );
			$is_path  = Dahu_Composite_Engine::is_path( $component );
			$entry    = array(
				'position'     => (int) $position,
				'component_id' => $cid,
				'title'        => (string) $component['title'],
				'type'         => $is_path ? 'path' : ( Dahu_Composite_Engine::is_multiple( $component ) ? 'multiple' : 'single' ),
				'empty'        => true,
			);

			if ( $is_path ) {
				$option_id = $selected ? (string) $selections[ $cid ]['option_id'] : '';
				if ( '' !== $option_id ) {
					$option             = Dahu_Composite_Engine::path_option( $component, $option_id );
					$entry['empty']     = false;
					$entry['option_id'] = $option_id;
					$entry['label']     = $option ? (string) $option['label'] : '';
				}
				$types[]      = '' !== $option_id ? $option_id : self::EMPTY_SEGMENT;
				$components[] = $entry;
				continue;
			}

			// Same items as the cart rows and as _composite_data:
			// a product, a positive quantity.
			$items = array();
			if ( $selected ) {
				foreach ( Dahu_Composite_Engine::selection_items( $selections[ $cid ] ) as $item ) {
					if ( empty( $item['product_id'] ) || (int) $item['quantity'] < 1 ) {
						continue;
					}
					$items[] = self::item( $item );
				}
			}

			// Click order has no effect: sorted by ID (then quantity, for a
			// total order).
			usort( $items, array( __CLASS__, 'compare' ) );

			$parts = array();
			foreach ( $items as $item ) {
				$part = self::token( $item['id'], $item['quantity'] );
				if ( ! empty( $item['bundle'] ) ) {
					$part .= self::SEP_ITEM . implode( self::SEP_ITEM, wp_list_pluck( $item['bundle'], 'token' ) );
				}
				$parts[] = $part;
			}
			$segments[] = $parts ? implode( self::SEP_ITEM, $parts ) : self::EMPTY_SEGMENT;

			if ( $items ) {
				$entry['empty'] = false;
				$entry['items'] = $items;
			}
			$components[] = $entry;
		}

		// Kit with no branch: the kit product stands in for the type.
		$type = $types ? implode( self::SEP_ITEM, $types ) : 'kit' . (int) $kit->get_id();
		$id   = implode( self::SEP_COMPONENT, array_merge( array( $type ), $segments ) );

		return array(
			'id'   => $id,
			'data' => array(
				'format'     => self::FORMAT,
				'id'         => $id,
				'kit_id'     => (int) $kit->get_id(),
				'kit_type'   => $type,
				'components' => $components,
			),
		);
	}

	/**
	 * Configuration serial: the identifier of build() for any selections,
	 * complete or PARTIAL (whatever is not chosen yet reads as “0”, exactly like
	 * an empty component). Administration simulator and /state.
	 *
	 * @param WC_Product_Dahu_Kit $kit        Kit.
	 * @param array               $selections Normalised selections (corrected by resolve_selections()).
	 * @return string '' with no choice at all: no serial to offer.
	 */
	public static function serial( WC_Product_Dahu_Kit $kit, array $selections ) {
		return $selections ? self::build( $kit, $selections )['id'] : '';
	}

	/**
	 * Reading a serial: the reverse path of build(), against the CURRENT
	 * definition of the kit. Returns RAW selections, in the format the front end
	 * sends to /state: the caller passes them through the usual engine
	 * (normalize_selections(), resolve_selections(), validate()), which removes
	 * everything that no longer holds (option hidden, withdrawn…).
	 *
	 * Splitting:
	 *  - the product segments (one per product component, editor order) are the
	 *    LAST N: they never contain a “-” (digits, “.”, “x”), whereas the
	 *    identifier of a branch option may (sanitize_title()); the rest, glued
	 *    back together with “-”, is the type segment;
	 *  - type segment: one value per branch, joined by “.” (“.” is impossible in
	 *    an option identifier), or “kit<ID of the kit>” for a kit with no branch;
	 *  - “0”: nothing chosen. A MANDATORY component at “0” that has a default
	 *    value: that default is taken, as when the configurator starts normally (a
	 *    mandatory component at “0” is never a refusal: it is not chosen yet, or it
	 *    is hidden — and the engine then removes the default);
	 *  - token of a product segment: “ID” or “IDxQ”; ID of a variation = parent
	 *    product + that variation; an ID that is not an option of the component
	 *    but follows a bundle (Product Bundles) = item of that bundle (see
	 *    bundle_contents()). A token that is BOTH an option of the component and an
	 *    item of the bundle preceding it is read as an option.
	 *
	 * Structure that does not match the kit (number of segments, of branches,
	 * another kit, unexpected character): null. Serial of a kit modified since
	 * (component added, removed, moved): usually null; otherwise the tokens that
	 * are no longer options of the right component are ignored (`ignored`).
	 *
	 * @param WC_Product_Dahu_Kit $kit    Kit.
	 * @param string              $serial Serial.
	 * @return array|null {
	 *   @type array    $selections Raw selections.
	 *   @type string[] $components Components filled in BY THE SERIAL (defaults taken aside).
	 *   @type int      $ignored    Tokens ignored.
	 * }
	 */
	public static function parse( WC_Product_Dahu_Kit $kit, $serial ) {
		$serial = strtolower( trim( (string) $serial ) );
		if ( '' === $serial || strlen( $serial ) > self::MAX_LENGTH || preg_match( '/[^a-z0-9._-]/', $serial ) ) {
			return null;
		}

		$paths    = array();
		$products = array();
		foreach ( $kit->get_kit_components() as $component ) {
			if ( Dahu_Composite_Engine::is_path( $component ) ) {
				$paths[] = $component;
			} else {
				$products[] = $component;
			}
		}

		$parts = explode( self::SEP_COMPONENT, $serial );
		$count = count( $products );
		if ( count( $parts ) < $count + 1 ) {
			return null;
		}
		$segments = $count ? array_slice( $parts, -$count ) : array();
		$type     = implode( self::SEP_COMPONENT, array_slice( $parts, 0, count( $parts ) - $count ) );
		foreach ( $segments as $segment ) {
			if ( ! preg_match( '/^[0-9x.]+$/', $segment ) ) {
				return null;
			}
		}

		$raw      = array();
		$restored = array();
		$ignored  = 0;

		if ( ! $paths ) {
			if ( 'kit' . (int) $kit->get_id() !== $type ) {
				return null;
			}
		} else {
			$values = explode( self::SEP_ITEM, $type );
			if ( count( $values ) !== count( $paths ) ) {
				return null;
			}
			foreach ( $paths as $index => $component ) {
				$value = $values[ $index ];
				if ( self::EMPTY_SEGMENT === $value || '' === $value ) {
					continue;
				}
				if ( ! Dahu_Composite_Engine::path_option( $component, $value ) ) {
					++$ignored;
					continue;
				}
				$raw[ $component['id'] ] = array( 'option_id' => $value );
				$restored[]              = $component['id'];
			}
		}

		foreach ( $products as $index => $component ) {
			$segment = $segments[ $index ];
			if ( self::EMPTY_SEGMENT === $segment ) {
				continue;
			}
			$items = self::parse_segment( $component, $segment, $ignored );
			if ( ! $items ) {
				continue;
			}
			if ( ! Dahu_Composite_Engine::is_multiple( $component ) ) {
				$ignored += count( $items ) - 1;
				$items    = $items[0];
			}
			$raw[ $component['id'] ] = $items;
			$restored[]              = $component['id'];
		}

		// Defaults of the mandatory components left at “0”: the same start
		// as the ordinary configurator (see above).
		foreach ( $kit->get_kit_components() as $component ) {
			$cid = $component['id'];
			if ( isset( $raw[ $cid ] ) || ! empty( $component['optional'] ) ) {
				continue;
			}
			if ( Dahu_Composite_Engine::is_path( $component ) ) {
				$default = (string) $component['default_option_id'];
				$option  = '' !== $default ? Dahu_Composite_Engine::path_option( $component, $default ) : null;
				if ( $option && empty( $option['link'] ) ) {
					$raw[ $cid ] = array( 'option_id' => $default );
				}
				continue;
			}
			$default = (int) $component['default_product_id'];
			if ( $default && wc_get_product( $default ) ) {
				$item        = array(
					'product_id' => $default,
					'quantity'   => max( 1, (int) $component['quantity_min'] ),
				);
				$raw[ $cid ] = Dahu_Composite_Engine::is_multiple( $component ) ? array( $item ) : $item;
			}
		}

		return array(
			'selections' => $raw,
			'components' => $restored,
			'ignored'    => $ignored,
		);
	}

	/**
	 * What the front end needs to know about a reopening by serial (/state, state
	 * dropped into the page): the serial read, the components it filled in (the
	 * landing screen depends on it), or `invalid`.
	 *
	 * @param string     $serial Serial received.
	 * @param array|null $parsed Result of parse().
	 * @return array
	 */
	public static function restore_info( $serial, $parsed ) {
		$info = array( 'serial' => strtolower( trim( (string) $serial ) ) );
		if ( ! $parsed || ! $parsed['components'] ) {
			$info['invalid'] = true;
			return $info;
		}
		$info['components'] = $parsed['components'];
		$info['ignored']    = (int) $parsed['ignored'];
		return $info;
	}

	/**
	 * Items of a product segment (parse()).
	 *
	 * @param array  $component Product component.
	 * @param string $segment   Segment (“14256”, “17023”, “29298.45138”, “14011x2”…).
	 * @param int    $ignored   Counter of ignored tokens, MODIFIED.
	 * @return array[] Raw items { product_id, variation_id?, quantity, bundle? }.
	 */
	private static function parse_segment( array $component, $segment, &$ignored ) {
		$offered = array_map( 'absint', wp_list_pluck( $component['options'], 'product_id' ) );
		$items   = array();
		$bundle  = null; // Index of the preceding “bundle” item, or null.

		foreach ( explode( self::SEP_ITEM, $segment ) as $token ) {
			if ( ! preg_match( '/^([1-9][0-9]*)(?:' . self::SEP_QUANTITY . '([1-9][0-9]*))?$/', $token, $match ) ) {
				++$ignored;
				continue;
			}
			$id       = (int) $match[1];
			$quantity = isset( $match[2] ) ? (int) $match[2] : 1;
			$product  = wc_get_product( $id );
			if ( ! $product ) {
				++$ignored;
				continue;
			}
			$variation_id = $product->is_type( 'variation' ) ? $id : 0;
			$product_id   = $variation_id ? (int) $product->get_parent_id() : $id;

			if ( in_array( $product_id, $offered, true ) ) {
				$item = array(
					'product_id' => $product_id,
					'quantity'   => $quantity,
				);
				if ( $variation_id ) {
					$item['variation_id'] = $variation_id;
				}
				$items[] = $item;
				$parent  = $variation_id ? wc_get_product( $product_id ) : $product;
				$bundle  = ( $parent && $parent->is_type( 'bundle' ) ) ? count( $items ) - 1 : null;
				continue;
			}

			$bundled_item_id = null !== $bundle ? self::bundled_item_id( $items[ $bundle ]['product_id'], $product_id ) : 0;
			if ( ! $bundled_item_id ) {
				++$ignored;
				continue;
			}
			$items[ $bundle ]['bundle'][ $bundled_item_id ] = array(
				'selected'     => true,
				'quantity'     => $quantity,
				'variation_id' => $variation_id,
			);
		}

		return $items;
	}

	/**
	 * Item of a bundle that carries a given product, or 0.
	 *
	 * @param int $bundle_id  Bundle.
	 * @param int $product_id Product of the item (parent, for a variant).
	 * @return int
	 */
	private static function bundled_item_id( $bundle_id, $product_id ) {
		$bundle = wc_get_product( $bundle_id );
		if ( ! $bundle || ! method_exists( $bundle, 'get_bundled_items' ) ) {
			return 0;
		}
		foreach ( $bundle->get_bundled_items() as $bundled_item_id => $bundled_item ) {
			if ( (int) $bundled_item->get_product_id() === (int) $product_id ) {
				return (int) $bundled_item_id;
			}
		}
		return 0;
	}

	/**
	 * Serial of a PARTIAL configuration that starts with a product: a “Configure
	 * my kit with this motor” button on a product page. Computed on the fly, never
	 * stored.
	 *
	 * The serial contains the product and the branch choices that make it
	 * selectable (e.g. “Rear wheel kit” for a rear wheel motor); nothing else — the
	 * following steps remain to be done.
	 *
	 * DETERMINISTIC rule when several places fit (a battery offered on the rear
	 * wheel AND on the front wheel):
	 *  1. the component highest in the editor order among those that offer the
	 *     product;
	 *  2. for that component, the branch combination that imposes the FEWEST
	 *     choices (no choice first: a component always visible imposes none);
	 *  3. at an equal count, the branch options in their editor order (“coming
	 *     soon” and “link” cards excluded).
	 * A place fits when, after the usual engine (cascading hiding, a single
	 * variant chosen automatically), every choice made holds, the product (and the
	 * requested variation) stays selected, with no error on those components
	 * (option hidden, reserved, unavailable, out of stock) and no dead end.
	 *
	 * Variation: its ID gives the serial of that variant (“17023”); variable
	 * product: serial of the product alone, the customer chooses their variant at
	 * the step (except for a single variant, chosen automatically by the engine).
	 *
	 * @param WC_Product_Dahu_Kit $kit        Kit.
	 * @param int                 $product_id Product or variation.
	 * @return string '': no place fits (product absent from the kit, unpublished,
	 *                out of stock, hidden…).
	 */
	public static function for_product( WC_Product_Dahu_Kit $kit, $product_id ) {
		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product || ! $product->is_in_stock() ) {
			return '';
		}
		$variation_id = $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;
		$parent_id    = $variation_id ? (int) $product->get_parent_id() : (int) $product->get_id();

		$paths      = array();
		$candidates = array();
		foreach ( $kit->get_kit_components() as $component ) {
			if ( Dahu_Composite_Engine::is_path( $component ) ) {
				$paths[] = $component;
				continue;
			}
			foreach ( $component['options'] as $option ) {
				if ( (int) $option['product_id'] === $parent_id ) {
					$candidates[] = $component;
					break;
				}
			}
		}
		if ( ! $candidates ) {
			return '';
		}

		$combinations = self::path_combinations( $paths );
		foreach ( $candidates as $component ) {
			foreach ( $combinations as $combination ) {
				$selections = self::try_place( $kit, $component, $parent_id, $variation_id, $combination );
				if ( null !== $selections ) {
					return self::build( $kit, $selections )['id'];
				}
			}
		}
		return '';
	}

	/**
	 * Branch combinations, from the least loaded to the most loaded (see
	 * for_product(), points 2 and 3).
	 *
	 * @param array[] $paths “path” components, editor order.
	 * @return array[] [ [component_id => option_id] ], the first one empty.
	 */
	private static function path_combinations( array $paths ) {
		$combinations = array( array() );
		foreach ( $paths as $component ) {
			$choices = array( null );
			foreach ( $component['options'] as $option ) {
				if ( empty( $option['coming_soon'] ) && empty( $option['link'] ) && isset( $option['id'] ) ) {
					$choices[] = (string) $option['id'];
				}
			}
			$next = array();
			foreach ( $combinations as $combination ) {
				foreach ( $choices as $choice ) {
					if ( count( $next ) >= self::MAX_PATH_COMBINATIONS ) {
						break 2;
					}
					$next[] = null === $choice ? $combination : $combination + array( $component['id'] => $choice );
				}
			}
			$combinations = $next;
		}

		// Stable sort: number of choices, then enumeration order (= order of the
		// options in the editor).
		$keyed = array();
		foreach ( $combinations as $index => $combination ) {
			$keyed[] = array( count( $combination ), $index, $combination );
		}
		usort(
			$keyed,
			static function ( $a, $b ) {
				return $a[0] === $b[0] ? $a[1] - $b[1] : $a[0] - $b[0];
			}
		);
		return wp_list_pluck( $keyed, 2 );
	}

	/**
	 * Does the product fit in this component with these branches?
	 *
	 * @param WC_Product_Dahu_Kit $kit          Kit.
	 * @param array               $component    Product component.
	 * @param int                 $product_id   Product (parent).
	 * @param int                 $variation_id Variation requested, 0 otherwise.
	 * @param array               $combination  [component_id => option_id].
	 * @return array|null Normalised and corrected selections, or null.
	 */
	private static function try_place( WC_Product_Dahu_Kit $kit, array $component, $product_id, $variation_id, array $combination ) {
		$raw = array();
		foreach ( $combination as $cid => $option_id ) {
			$raw[ $cid ] = array( 'option_id' => $option_id );
		}
		$item = array(
			'product_id' => $product_id,
			'quantity'   => max( 1, (int) $component['quantity_min'] ),
		);
		if ( $variation_id ) {
			$item['variation_id'] = $variation_id;
		}
		$raw[ $component['id'] ] = Dahu_Composite_Engine::is_multiple( $component ) ? array( $item ) : $item;

		$selections = Dahu_Composite_Engine::normalize_selections( $kit, $raw );
		list( $selections ) = Dahu_Composite_Engine::resolve_selections( $kit, $selections );

		foreach ( $combination as $cid => $option_id ) {
			if ( ! isset( $selections[ $cid ]['option_id'] ) || (string) $selections[ $cid ]['option_id'] !== $option_id ) {
				return null;
			}
		}
		$kept = null;
		foreach ( Dahu_Composite_Engine::selection_items( isset( $selections[ $component['id'] ] ) ? $selections[ $component['id'] ] : null ) as $selected ) {
			if ( (int) $selected['product_id'] === (int) $product_id ) {
				$kept = $selected;
			}
		}
		if ( ! $kept || ( $variation_id && (int) $kept['variation_id'] !== (int) $variation_id ) ) {
			return null;
		}

		$validation = Dahu_Composite_Engine::validate( $kit, $selections, 1 );
		if ( ! empty( $validation['blocked'] ) ) {
			return null;
		}
		$placed = array_merge( array_keys( $combination ), array( $component['id'] ) );
		foreach ( $validation['errors'] as $error ) {
			if ( in_array( $error['component'], $placed, true ) ) {
				return null;
			}
		}
		return $selections;
	}

	/**
	 * Address of the configurator opened on a serial.
	 *
	 * @param WC_Product_Dahu_Kit $kit    Kit.
	 * @param string              $serial Serial.
	 * @return string '' with no serial or no permalink.
	 */
	public static function url( WC_Product_Dahu_Kit $kit, $serial ) {
		$permalink = get_permalink( $kit->get_id() );
		return ( $permalink && '' !== (string) $serial ) ? add_query_arg( self::QUERY_ARG, rawurlencode( (string) $serial ), $permalink ) : '';
	}

	/**
	 * Item of a product component, structured shape.
	 *
	 * @param array $item Normalised selection item.
	 * @return array
	 */
	private static function item( array $item ) {
		$product_id   = (int) $item['product_id'];
		$variation_id = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
		$line_product = wc_get_product( $variation_id ? $variation_id : $product_id );
		$product      = $variation_id ? wc_get_product( $product_id ) : $line_product;
		$data         = array(
			'id'           => $variation_id ? $variation_id : $product_id,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'quantity'     => (int) $item['quantity'],
			'name'         => $line_product ? wp_strip_all_tags( $line_product->get_name() ) : '',
			// Values chosen for the “Any” attributes of a variation:
			// structured shape only (no current component has any).
			'attributes'   => ! empty( $item['attributes'] ) ? (array) $item['attributes'] : array(),
		);
		if ( $product && $product->is_type( 'bundle' ) ) {
			$data['bundle'] = self::bundle_contents( $product, $item );
		}
		return $data;
	}

	/**
	 * Contents of a bundle (Product Bundles) that depend on the customer — the
	 * same resolution as adding to the cart
	 * (Dahu_Composite_Cart::bundle_configuration()): an optional item ticked, or
	 * an item with several possible variants (ID of the variant retained). A
	 * mandatory item with fixed content changes nothing from one customer to
	 * another: it is not listed. Sorted by ID.
	 *
	 * @param WC_Product $bundle Bundle.
	 * @param array      $item   Selection item (`bundle` key).
	 * @return array[] { bundled_item_id, product_id, variation_id, id, quantity, optional, name, token }
	 */
	private static function bundle_contents( WC_Product $bundle, array $item ) {
		if ( ! method_exists( $bundle, 'get_bundled_items' ) ) {
			return array();
		}
		$out = array();
		foreach ( $bundle->get_bundled_items() as $bundled_item_id => $bundled_item ) {
			$conf     = isset( $item['bundle'][ $bundled_item_id ] ) ? $item['bundle'][ $bundled_item_id ] : null;
			$optional = $bundled_item->is_optional();
			if ( $optional && ! ( $conf && ! empty( $conf['selected'] ) ) ) {
				continue;
			}
			$product    = $bundled_item->get_product();
			$product_id = (int) $bundled_item->get_product_id();
			$vid        = 0;
			$choices    = 1;
			if ( $product && $product->is_type( 'variable' ) ) {
				$vid     = (int) Dahu_Composite_Engine::resolve_bundled_variation( $bundled_item, $conf );
				$allowed = method_exists( $bundled_item, 'get_filtered_variations' ) ? (array) $bundled_item->get_filtered_variations() : array();
				$choices = $allowed ? count( $allowed ) : count( $product->get_children() );
			}
			if ( ! $optional && $choices < 2 ) {
				continue;
			}
			$qty = ( $conf && ! empty( $conf['quantity'] ) ) ? (int) $conf['quantity'] : (int) $bundled_item->get_quantity( 'default' );
			$qty = max( (int) $bundled_item->get_quantity( 'min' ), max( 1, $qty ) );
			$id  = $vid ? $vid : $product_id;
			$line = $vid ? wc_get_product( $vid ) : $product;
			$out[] = array(
				'bundled_item_id' => (int) $bundled_item_id,
				'product_id'      => $product_id,
				'variation_id'    => $vid,
				'id'              => $id,
				'quantity'        => $qty,
				'optional'        => $optional,
				'name'            => $line ? wp_strip_all_tags( $line->get_name() ) : '',
				'token'           => self::token( $id, $qty ),
			);
		}
		usort( $out, array( __CLASS__, 'compare' ) );
		return $out;
	}

	/**
	 * “ID” or “IDxQ” (quantity per kit above 1).
	 *
	 * @param int $id       Product or variation.
	 * @param int $quantity Quantity.
	 * @return string
	 */
	private static function token( $id, $quantity ) {
		return (int) $id . ( (int) $quantity > 1 ? self::SEP_QUANTITY . (int) $quantity : '' );
	}

	/**
	 * Sort: ascending ID, then quantity.
	 *
	 * @param array $a Item.
	 * @param array $b Item.
	 * @return int
	 */
	private static function compare( $a, $b ) {
		return $a['id'] === $b['id'] ? $a['quantity'] - $b['quantity'] : $a['id'] - $b['id'];
	}
}
