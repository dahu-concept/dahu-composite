<?php
/**
 * Block cart, mini-cart and checkout: loads the script that sets the row
 * classes of the supply variations and of their accessories
 * (assets/js/dahu-composite-supply-blocks.js). Same mechanism as Product
 * Bundles (WC_PB_Checkout_Blocks_Integration).
 *
 * Loaded only when the interface exists (Dahu_Composite_Included::
 * register_blocks_integration()).
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Blocks_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {

	const NAME = 'dahu-composite-supply';

	const HANDLE = 'dahu-composite-supply-blocks';

	/**
	 * @return string
	 */
	public function get_name() {
		return self::NAME;
	}

	public function initialize() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}
		wp_register_script(
			self::HANDLE,
			DAHU_COMPOSITE_URL . 'assets/js/dahu-composite-supply-blocks.js',
			array( 'wc-blocks-checkout' ),
			DAHU_COMPOSITE_VERSION,
			true
		);
	}

	/**
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( self::HANDLE );
	}

	/**
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * @return array
	 */
	public function get_script_data() {
		return array();
	}
}
