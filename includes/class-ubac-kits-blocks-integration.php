<?php
/**
 * Block cart, mini-cart and checkout: loads the script that sets the row
 * classes of the supply variations and of their accessories
 * (assets/js/ubac-kits-supply-blocks.js). Same mechanism as Product
 * Bundles (WC_PB_Checkout_Blocks_Integration).
 *
 * Loaded only when the interface exists (Ubac_Kits_Included::
 * register_blocks_integration()).
 *
 * @package Ubac_Kits
 */

defined( 'ABSPATH' ) || exit;

class Ubac_Kits_Blocks_Integration implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {

	const NAME = 'ubac-kits-supply';

	const HANDLE = 'ubac-kits-supply-blocks';

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
			UBAC_KITS_URL . 'assets/js/ubac-kits-supply-blocks.js',
			array( 'wc-blocks-checkout' ),
			UBAC_KITS_VERSION,
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
