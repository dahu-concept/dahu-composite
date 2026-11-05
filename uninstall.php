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
 * `ubac_kits_remove_data_on_uninstall` option to a truthy value before
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
function ubac_kits_uninstall_site() {

	// The welcome screen's own three flags.
	//
	// They are interface state, not the shop's data, which is why they sit
	// BEFORE any “remove data on uninstall” guard: whatever a shop chose to
	// keep, a reinstall has to greet it again. `includes/ubac-welcome.php`
	// writes them with update_option(), so they outlive the plugin unless
	// they are deleted here — and the welcome screen and the guided tour
	// would then stay silent for good, with nothing on screen to explain why.
	delete_option( 'ubac_welcome_seen_ubac-kits' );
	delete_option( 'ubac_welcome_pending_ubac-kits' );
	delete_option( 'ubac_welcome_tour_ubac-kits' );

	global $wpdb;

	$wipe = (bool) get_option( 'ubac_kits_remove_data_on_uninstall', false );

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

	foreach ( UBAC_KITS_UNINSTALL_USER_META as $meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no core helper deletes a user meta key site-wide.
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $meta_key ) );
	}
}

if ( is_multisite() ) {
	$ubac_kits_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ubac_kits_site_ids as $ubac_kits_site_id ) {
		switch_to_blog( $ubac_kits_site_id );
		ubac_kits_uninstall_site();
		restore_current_blog();
	}
} else {
	ubac_kits_uninstall_site();
}
