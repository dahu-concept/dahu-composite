/**
 * Block cart, mini-cart and order summary: “what is included” row classes
 * (contract: RESUME_TECHNIQUE.md, §2 bis).
 *
 *  - Shared convention, the same for a Product Bundles bundle and for a supply
 *    variation, with no distinction: dahu-included-parent,
 *    dahu-included-child, dahu-included-last. Read from the “bundles”
 *    (Product Bundles) or “dahu_supply” (supply) namespace of the row.
 *  - Supply only: Product Bundles' presentation classes (is-bundle…,
 *    is-bundled…), derived from the same fields as its “bundles” namespace, so
 *    that its already loaded CSS rules apply. Product Bundles sets its own on
 *    its own rows. No CSS rule is copied here.
 *
 * Any error returns the list of classes that was received.
 */
(function () {
	'use strict';

	var checkout = window.wc && window.wc.blocksCheckout;
	if (!checkout || typeof checkout.registerCheckoutFilters !== 'function') {
		return;
	}

	function hasItems(list) {
		if (!list) return false;
		return Array.isArray(list) ? list.length > 0 : typeof list === 'object' && Object.keys(list).length > 0;
	}

	/** Shared convention, from the “bundles” or “dahu_supply” namespace. */
	function includedClasses(data) {
		if (!data) return [];
		if (data.bundled_by && data.bundled_item_data) {
			return data.bundled_item_data.is_last ? ['dahu-included-child', 'dahu-included-last'] : ['dahu-included-child'];
		}
		return hasItems(data.bundled_items) ? ['dahu-included-parent'] : [];
	}

	/** Product Bundles presentation of an accessory (same flags as its own script). */
	function bundledClasses(d, context) {
		var classes = ['is-bundled', 'is-bundled__cid_' + d.bundle_id];
		if (d.is_indented) classes.push('is-bundled__indented');
		if (d.is_last) classes.push('is-bundled__last');
		if (d.is_removable) classes.push('is-bundled__removable');
		if (d.is_subtotal_aggregated) classes.push('is-bundled__subtotal_aggregated');
		if (d.is_price_hidden) classes.push('is-bundled__price_hidden');
		if (d.is_subtotal_hidden) classes.push('is-bundled__subtotal_hidden');
		if (d.is_hidden_in_cart && context === 'cart') classes.push('is-bundled__hidden');
		if (d.is_hidden_in_summary && context === 'summary') classes.push('is-bundled__hidden');
		if (d.is_thumbnail_hidden) classes.push('is-bundled__thumbnail_hidden');
		if (d.is_parent_visible) classes.push('is-bundled__description_hidden');
		return classes;
	}

	/** Product Bundles presentation of a supply variation. */
	function bundleClasses(d, cartItem, context) {
		var classes = ['is-bundle', 'is-bundle__cid_' + cartItem.id];
		if (d.is_editable) classes.push('is-bundle__editable');
		if (d.is_hidden) classes.push('is-bundle__hidden');
		if (d.is_price_hidden) classes.push('is-bundle__price_hidden');
		if (d.is_subtotal_hidden) classes.push('is-bundle__subtotal_hidden');
		if (d.is_meta_hidden_in_cart && context === 'cart') classes.push('is-bundle__meta_hidden');
		if (d.is_meta_hidden_in_summary && context === 'summary') classes.push('is-bundle__meta_hidden');
		return classes;
	}

	checkout.registerCheckoutFilters('dahu-composite', {
		cartItemClass: function (classlist, extensions, args) {
			try {
				if (!extensions) return classlist;
				var context = args && args.context;
				var supply = extensions.dahu_supply;
				var classes = includedClasses(supply && (supply.bundled_by || supply.bundled_items) ? supply : extensions.bundles);
				if (supply && supply.bundled_by && supply.bundled_item_data) {
					classes = classes.concat(bundledClasses(supply.bundled_item_data, context));
				} else if (supply && supply.bundled_items && supply.bundle_data && args && args.cartItem) {
					classes = classes.concat(bundleClasses(supply.bundle_data, args.cartItem, context));
				}
				return classes.length ? classlist + ' ' + classes.join(' ') : classlist;
			} catch (e) {
				return classlist;
			}
		}
	});
})();
