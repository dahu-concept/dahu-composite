<?php
/**
 * Appearance of a kit, set ONCE per product in the back office. A single table
 * (SETTINGS): every setting has its meta, its allowed values (the first one is
 * the default) and its attribute on the container of the configurator. Adding
 * a setting = one line here; box, saving, attributes and simulator follow.
 *
 *  - card_style   : vignette | card
 *      vignette = cards with a photo, a subtitle and “More info”, with no
 *                 right-hand column; card = fixed-size cards, with a preview
 *                 in the right-hand column.
 *  - card_corners : square | rounded
 *      square = summary and right-hand column with no rounding.
 *  - icons        : on | off (formerly the theme's “Icons” toggle)
 *  - info_desktop : card | lateral | extended (formerly the theme's
 *                   “Side / Card / Extended” group);
 *  - info_mobile  : lateral | card | extended — below the layout threshold of
 *                   the configurator (64em), chosen by the script: the page is
 *                   cached, so no wp_is_mobile();
 *  - click        : on | off (formerly the theme's “Click” toggle).
 *
 * Default values = the combination validated in production: vignette, square,
 * icons on, card (desktop), side (mobile), click on. A product with no saved
 * setting therefore keeps its rendering.
 *
 * STORAGE — SEPARATE product metas, deliberately outside the kit configuration
 * (_dahu_kit_components / _dahu_kit_scenarios) and therefore outside the JSON
 * import / export. Reimporting a fixture must never overwrite them: that is the
 * lesson of an option's `optional` flag, lost on every reimport because it
 * lived in the importable JSON. See RESUME_TECHNIQUE.md.
 *
 * Rendering: Dahu_Composite_Frontend sets the values as attributes on the
 * container of the configurator (data-dahu-kit-*), read by the script and by
 * the theme.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Appearance {

	const META_CARD_STYLE   = '_dahu_kit_card_style';
	const META_CARD_CORNERS = '_dahu_kit_card_corners';

	/** Allowed values; the first one is the default. */
	const CARD_STYLES  = array( 'vignette', 'card' );
	const CARD_CORNERS = array( 'square', 'rounded' );

	/**
	 * Settings: key => meta, allowed values (first = default), attribute of the
	 * container.
	 */
	const SETTINGS = array(
		'card_style'   => array( 'meta' => '_dahu_kit_card_style', 'values' => array( 'vignette', 'card' ), 'attribute' => 'data-dahu-kit-card-style' ),
		'card_corners' => array( 'meta' => '_dahu_kit_card_corners', 'values' => array( 'square', 'rounded' ), 'attribute' => 'data-dahu-kit-card-corners' ),
		'icons'        => array( 'meta' => '_dahu_kit_icons', 'values' => array( 'on', 'off' ), 'attribute' => 'data-dahu-kit-icons' ),
		'info_desktop' => array( 'meta' => '_dahu_kit_info_desktop', 'values' => array( 'card', 'lateral', 'extended' ), 'attribute' => 'data-dahu-kit-info-desktop' ),
		'info_mobile'  => array( 'meta' => '_dahu_kit_info_mobile', 'values' => array( 'lateral', 'card', 'extended' ), 'attribute' => 'data-dahu-kit-info-mobile' ),
		'click'        => array( 'meta' => '_dahu_kit_click', 'values' => array( 'on', 'off' ), 'attribute' => 'data-dahu-kit-click' ),
	);

	/**
	 * Settings of a product, default values included.
	 *
	 * @param int $product_id Kit product.
	 * @return array<string, string> SETTINGS key => value.
	 */
	public static function get( $product_id ) {
		$out = array();
		foreach ( self::SETTINGS as $key => $setting ) {
			$out[ $key ] = self::pick( get_post_meta( $product_id, $setting['meta'], true ), $setting['values'] );
		}
		return $out;
	}

	/** Default values of every setting. */
	public static function defaults() {
		$out = array();
		foreach ( self::SETTINGS as $key => $setting ) {
			$out[ $key ] = $setting['values'][0];
		}
		return $out;
	}

	/**
	 * Saves the settings present in $values. An absent key is left alone; an
	 * unknown value falls back to the default value.
	 *
	 * @param int   $product_id Kit product.
	 * @param array $values     SETTINGS key => value.
	 */
	public static function save( $product_id, array $values ) {
		foreach ( self::SETTINGS as $key => $setting ) {
			if ( isset( $values[ $key ] ) ) {
				update_post_meta( $product_id, $setting['meta'], self::pick( $values[ $key ], $setting['values'] ) );
			}
		}
	}

	/**
	 * HTML attributes of the configurator container (leading space included).
	 *
	 * @param int $product_id Kit product.
	 * @return string
	 */
	public static function data_attributes( $product_id ) {
		$appearance = self::get( $product_id );
		$html       = '';
		foreach ( self::SETTINGS as $key => $setting ) {
			$html .= sprintf( ' %s="%s"', $setting['attribute'], esc_attr( $appearance[ $key ] ) );
		}
		return $html;
	}

	/**
	 * @param mixed    $value   Raw value.
	 * @param string[] $allowed Allowed values (the first one is the default).
	 * @return string
	 */
	private static function pick( $value, array $allowed ) {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, $allowed, true ) ? $value : $allowed[0];
	}
}
