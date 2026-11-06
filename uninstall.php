<?php
/**
 * Uninstall routine.
 *
 * Runs only when the plugin is deleted from the Plugins screen, never on
 * deactivation.
 *
 * Deliberate asymmetry between two kinds of data:
 *
 * - Plugin settings and caches are always removed. They are small, they are
 *   configuration rather than content, and they are trivially rebuilt.
 * - Kit definitions, saved customer configurations and order metadata are the
 *   merchant's own content. Months of work can sit in them, and an uninstall
 *   is often just a step in reinstalling. They are kept unless the merchant
 *   explicitly asked for a full wipe.
 *
 * A merchant who genuinely wants everything gone sets the
 * "Delete this plugin's data when the plugin is deleted" box on the Composite
 * products screen, which writes `ubac_kits_delete_data_on_uninstall`, before
 * deleting the plugin.
 *
 * @package UbacKits
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Plugin settings. Always removed.
 */
const UBAC_KITS_UNINSTALL_OPTIONS = array(
	'ubac_kits_container_shipping_class',
	'ubac_kits_container_shipping_class_slug',
	'ubac_kits_price_tiers',
	'ubac_kits_none_image_id',
	'ubac_kits_none_text',
	'ubac_kits_more_info_link',
	'ubac_kits_help_placeholder',
	'ubac_kits_guest_save_enabled',
	'ubac_kits_guest_save_fields',
	'ubac_kits_guest_save_limit',
	'ubac_kits_rewrite_version',
	'ubac_kits_migrations',
	'ubac_kits_delete_data_on_uninstall',
	// The old name. Nothing ever wrote it, but a shop that followed the previous
	// header and set it by hand would otherwise keep an orphan row.
	'ubac_kits_remove_data_on_uninstall',
);

/**
 * Post meta holding merchant content. Removed only on an explicit wipe.
 */
const UBAC_KITS_UNINSTALL_POST_META = array(
	'_ubac_kit',
	'_ubac_kit_components',
	'_ubac_kit_scenarios',
	'_ubac_kit_icons',
	'_ubac_kit_card_style',
	'_ubac_kit_card_corners',
	'_ubac_kit_click',
	'_ubac_kit_info_desktop',
	'_ubac_kit_info_mobile',
	'_ubac_kit_customer_exceptions',
	'_ubac_supply',
	'_ubac_supply_data',
	'_ubac_composition',
	'_ubac_composition_id',
);

/**
 * User meta holding customer content. Removed only on an explicit wipe.
 */
const UBAC_KITS_UNINSTALL_USER_META = array(
	'_ubac_kit_saved_configs',
	'_ubac_kit_contact_consent',
);

/**
 * Clean one site.
 *
 * @return void
 */
function ubac_composite_products_uninstall_site() {

	// The welcome screen's own three flags.
	//
	// They are interface state, not the shop's data, which is why they sit
	// BEFORE any “remove data on uninstall” guard: whatever a shop chose to
	// keep, a reinstall has to greet it again. `includes/ubac-welcome.php`
	// writes them with update_option(), so they outlive the plugin unless
	// they are deleted here — and the welcome screen and the guided tour
	// would then stay silent for good, with nothing on screen to explain why.
	delete_option( 'ubac_welcome_seen_ubac-composite-products' );
	delete_option( 'ubac_welcome_pending_ubac-composite-products' );
	delete_option( 'ubac_welcome_tour_ubac-composite-products' );

	// AND THE SAME THREE UNDER THE FORMER SLUG. This plugin was called
	// `ubac-kits` until 6 November 2026, and an installation from before the
	// rename wrote its welcome flags under that name. They are not reachable
	// by the current slug, so without these three lines an uninstall would
	// leave them behind for good.
	delete_option( 'ubac_welcome_seen_ubac-kits' );
	delete_option( 'ubac_welcome_pending_ubac-kits' );
	delete_option( 'ubac_welcome_tour_ubac-kits' );

	global $wpdb;

	/*
	 * Compared against 'yes', never cast to bool: the checkbox stores the empty
	 * string when unticked, and a future WooCommerce-style 'no' would be truthy.
	 *
	 * Until 06/11/2026 this option was named
	 * `ubac_kits_remove_data_on_uninstall` and NOTHING anywhere wrote it, so the
	 * branch it guards could never run: a shop that asked for its compositions
	 * to go kept them for ever, with no box to say otherwise.
	 */
	$wipe = ( 'yes' === get_option( 'ubac_kits_delete_data_on_uninstall', '' ) );

	foreach ( UBAC_KITS_UNINSTALL_OPTIONS as $option ) {
		delete_option( $option );
	}

	// Transients: caches only, always safe to drop. Three families are built
	// with a dynamic suffix, so they are matched on their prefix.
	foreach ( array( 'ubac_kits_notice_', 'ubac_kit_', 'ubac_supply_search_' ) as $prefix ) {
		$like = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall routine, no caching layer available.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		$like = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall routine, no caching layer available.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
	}

	if ( ! $wipe ) {
		return;
	}

	foreach ( UBAC_KITS_UNINSTALL_POST_META as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	/*
	 * The user meta is NOT touched here. `$wpdb->usermeta` is a NETWORK-GLOBAL
	 * table: switch_to_blog() does not change it, so deleting from it inside
	 * the per-site loop below would wipe the saved configurations and contact
	 * consents of every user of every site in the network because ONE site
	 * asked, and would repeat the work once per site. It is handled once,
	 * after the loop, by ubac_composite_products_uninstall_users().
	 *
	 * This was latent rather than harmless: until 06/11/2026 nothing wrote the
	 * option that gates this branch, so it never ran. Wiring the box made the
	 * branch reachable, which is what brought the per-site loop into contact
	 * with a network-global table.
	 */
}

/**
 * Cleans the user meta. Once per installation, not once per site.
 *
 * @param bool $wipe Whether the saved configurations and consents go too.
 * @return void
 */
function ubac_composite_products_uninstall_users( $wipe ) {
	global $wpdb;

	if ( ! $wipe ) {
		return;
	}

	foreach ( UBAC_KITS_UNINSTALL_USER_META as $meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no core helper deletes a user meta key site-wide.
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $meta_key ) );
	}
}

$ubac_composite_products_wipe_users = ( 'yes' === get_option( 'ubac_kits_delete_data_on_uninstall', '' ) );

if ( is_multisite() ) {
	$ubac_composite_products_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ubac_composite_products_site_ids as $ubac_composite_products_site_id ) {
		switch_to_blog( $ubac_composite_products_site_id );

		// Read per site, BEFORE the site function deletes the option: only a
		// site that asked gets its own data removed, and any one site asking
		// is enough for the user meta, which the whole network shares. The
		// box says so, because it is a choice that reaches other shops.
		if ( 'yes' === get_option( 'ubac_kits_delete_data_on_uninstall', '' ) ) {
			$ubac_composite_products_wipe_users = true;
		}

		ubac_composite_products_uninstall_site();
		restore_current_blog();
	}
} else {
	ubac_composite_products_uninstall_site();
}

ubac_composite_products_uninstall_users( $ubac_composite_products_wipe_users );
