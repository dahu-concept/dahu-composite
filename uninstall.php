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
 * `dahu_composite_remove_data_on_uninstall` option to a truthy value before
 * deleting the plugin.
 *
 * @package DahuComposite
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Plugin settings. Always removed.
 */
const DAHU_COMPOSITE_UNINSTALL_OPTIONS = array(
	'dahu_composite_container_shipping_class',
	'dahu_composite_container_shipping_class_slug',
	'dahu_composite_price_tiers',
	'dahu_composite_none_image_id',
	'dahu_composite_none_text',
	'dahu_composite_more_info_link',
	'dahu_composite_help_placeholder',
	'dahu_composite_guest_save_enabled',
	'dahu_composite_guest_save_fields',
	'dahu_composite_guest_save_limit',
	'dahu_composite_rewrite_version',
	'dahu_composite_migrations',
	'dahu_composite_remove_data_on_uninstall',
);

/**
 * Post meta holding merchant content. Removed only on an explicit wipe.
 */
const DAHU_COMPOSITE_UNINSTALL_POST_META = array(
	'_dahu_kit',
	'_dahu_kit_components',
	'_dahu_kit_scenarios',
	'_dahu_kit_icons',
	'_dahu_kit_card_style',
	'_dahu_kit_card_corners',
	'_dahu_kit_click',
	'_dahu_kit_info_desktop',
	'_dahu_kit_info_mobile',
	'_dahu_kit_customer_exceptions',
	'_dahu_supply',
	'_dahu_supply_data',
	'_dahu_composition',
	'_dahu_composition_id',
);

/**
 * User meta holding customer content. Removed only on an explicit wipe.
 */
const DAHU_COMPOSITE_UNINSTALL_USER_META = array(
	'_dahu_kit_saved_configs',
	'_dahu_kit_contact_consent',
);

/**
 * Clean one site.
 *
 * @return void
 */
function dahu_composite_uninstall_site() {

	global $wpdb;

	$wipe = (bool) get_option( 'dahu_composite_remove_data_on_uninstall', false );

	foreach ( DAHU_COMPOSITE_UNINSTALL_OPTIONS as $option ) {
		delete_option( $option );
	}

	// Transients: caches only, always safe to drop. Three families are built
	// with a dynamic suffix, so they are matched on their prefix.
	foreach ( array( 'dahu_composite_notice_', 'dahu_kit_', 'dahu_supply_search_' ) as $prefix ) {
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

	foreach ( DAHU_COMPOSITE_UNINSTALL_POST_META as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	foreach ( DAHU_COMPOSITE_UNINSTALL_USER_META as $meta_key ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- no core helper deletes a user meta key site-wide.
		$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $meta_key ) );
	}
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		dahu_composite_uninstall_site();
		restore_current_blog();
	}
} else {
	dahu_composite_uninstall_site();
}
