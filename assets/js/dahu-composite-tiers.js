/**
 * DAHU COMPOSITE — organisation of the option lists: sections per category
 * (range) and anchoring of the “add-on” components (chosen card and implicit
 * anchor).
 *
 * The ONLY implementation of these rules: the configurator
 * (dahu-composite-front.js, in every one of its display modes) and the
 * administration simulator (dahu-composite-admin.js) both call it.
 *
 * Sections:
 *
 * The sections come from the ordered `component.tiers` list
 * ([{ id, label, description }]): order of the sections = order of the list,
 * heading = label. The `tier` field of an option is only the id it is attached
 * by; an unknown id reads as “no category”.
 *
 * Behaviour kept from before the categories existed:
 *  - no option attached to a category → a single section, with no heading (a
 *    plain list);
 *  - options with no category mixed in with others → one section with no
 *    heading, placed at the rank where its first option appears (which used to
 *    be the rank of every section: order of first appearance).
 *
 * Only the options received count: a category all of whose options are hidden
 * by a scenario produces no section (no empty heading).
 */
(function (root) {
	'use strict';

	/**
	 * @param {Object} component Component ({ tiers: [...] }).
	 * @param {Array}  options   Options to display (already filtered).
	 * @return {Array} [{ tier: {id, label, description}|null, options: [] }]
	 */
	function sections(component, options) {
		var tiers = (component && component.tiers) || [];
		var known = {};
		tiers.forEach(function (tier) { known[tier.id] = tier; });

		var keyOf = function (o) { return o.tier && known[o.tier] ? o.tier : ''; };
		var byKey = {};
		var firstSeen = [];
		options.forEach(function (o) {
			var key = keyOf(o);
			if (!byKey[key]) {
				byKey[key] = [];
				firstSeen.push(key);
			}
			byKey[key].push(o);
		});

		if (!firstSeen.length || (firstSeen.length === 1 && firstSeen[0] === '')) {
			return [{ tier: null, options: options }];
		}

		var result = [];
		tiers.forEach(function (tier) {
			if (byKey[tier.id]) result.push({ tier: tier, options: byKey[tier.id] });
		});
		var untiered = firstSeen.indexOf('');
		if (untiered !== -1) {
			result.splice(Math.min(untiered, result.length), 0, { tier: null, options: byKey[''] });
		}
		return result;
	}

	/**
	 * “Add-on” component: “secondary” (title_role), optional and with ONE single
	 * option — the cassette next to the motor. Not a choice between several
	 * possibilities but “with / without”. Never a multiple-choice component,
	 * which has its own rendering (one “add-on” card per option).
	 *
	 * @param {Object} component Component (options included).
	 * @return {boolean}
	 */
	function isAddon(component) {
		return !!component && component.component_type !== 'path' && component.selection_mode !== 'multiple'
			&& component.title_role === 'secondary'
			&& !!component.optional && (component.options || []).length === 1;
	}

	/**
	 * Add-on presented as a yes / no QUESTION (the `presentation` setting of the
	 * component): “Yes” chooses the single option, “No” chooses nothing. Still an
	 * add-on (isAddon()): same anchoring, title and selection rules; only the
	 * rendering changes. The setting, placed on a component that is not an
	 * add-on, is ignored.
	 *
	 * @param {Object} component Component (options included).
	 * @return {boolean}
	 */
	function isQuestion(component) {
		return isAddon(component) && component.presentation === 'question';
	}

	/**
	 * Implicit anchor component of an add-on: the first component of the screen
	 * that shows single-choice product cards (neither a branch, nor an add-on,
	 * nor a multiple choice). null when there is none.
	 */
	function defaultHost(components, addon) {
		var found = null;
		(components || []).forEach(function (c) {
			if (found || c === addon || c.component_type === 'path' || c.selection_mode === 'multiple' || isAddon(c)) return;
			found = c;
		});
		return found;
	}

	/**
	 * Anchors of a screen: EVERY add-on (isAddon()) is shown next to a card of
	 * another component of the screen, inside its option list, instead of being a
	 * direct child of the screen.
	 *  - anchor_component_id + anchor_product_id: next to THAT card (the base
	 *    product of the option, not a variant);
	 *  - anchor_component_id alone: next to the CHOSEN card of that component;
	 *  - nothing declared: next to the chosen card of the first product component
	 *    of the screen (defaultHost()) — the cassette next to the chosen motor,
	 *    with nothing to set.
	 * productId = 0: “the chosen card”, resolved by the caller from its own
	 * selections (selectedProductId()).
	 *
	 * Nothing is inferred from the scenarios. The presence of the card is NOT
	 * checked here — it depends on the rendering and on the selections (option
	 * hidden, nothing chosen, anchor component absent from the screen): the caller
	 * then falls back to the usual place.
	 *
	 * @param {Array} components Components of the screen, in order.
	 * @return {Array} [{ component, hostId, productId }], in the order of the
	 *                 screen.
	 */
	function anchors(components) {
		var out = [];
		(components || []).forEach(function (component) {
			if (!isAddon(component)) return;
			var hostId = component.anchor_component_id || '';
			var productId = parseInt(component.anchor_product_id, 10) || 0;
			if (!hostId) {
				var host = defaultHost(components, component);
				if (!host) return;
				hostId = host.id;
				productId = 0;
			}
			out.push({ component: component, hostId: hostId, productId: productId });
		});
		return out;
	}

	/**
	 * Base product chosen for a single-choice component, from selections in the
	 * format of the engine ({ product_id, … }); 0 otherwise (nothing chosen,
	 * branch, multiple choice).
	 */
	function selectedProductId(selections, hostId) {
		var sel = selections && selections[hostId];
		if (!sel || Array.isArray(sel)) return 0;
		return parseInt(sel.product_id, 10) || 0;
	}

	/**
	 * Palette of the ranges: the eight colours of the kit editor's picker, in the
	 * order of the default colours. Each one has two shades: `color` (the badge)
	 * and `tint` (the light background of the cards, the “Tinted background”
	 * setting).
	 */
	var TIER_PALETTE = [
		{ color: '#3b5ba9', tint: '#dedfea', name: 'Bleu' },
		{ color: '#6b4fa0', tint: '#e6e0f0', name: 'Violet' },
		{ color: '#c97a3d', tint: '#f3e3d3', name: 'Orange' },
		{ color: '#4c7a5e', tint: '#dce8e0', name: 'Vert' },
		{ color: '#a3453f', tint: '#f0dcda', name: 'Bordeaux' },
		{ color: '#b99233', tint: '#f0e8d3', name: 'Moutarde' },
		{ color: '#a15c7a', tint: '#efdde5', name: 'Mauve' },
		{ color: '#4a7a8c', tint: '#d9e6ea', name: 'Gris-bleu' }
	];

	/**
	 * Light background of a range colour: the one from the palette; '' for a code
	 * outside the palette (JSON written by hand) — the background is then a blend
	 * computed by the functional stylesheet.
	 */
	function tierTint(color) {
		var found = '';
		TIER_PALETTE.forEach(function (p) { if (p.color === String(color || '').toLowerCase()) found = p.tint; });
		return found;
	}

	/**
	 * Colour of a range: the one chosen in the editor (“#rrggbb”), else the one
	 * from the palette, in the order in which the range LABELS appear in the kit
	 * — blue, purple, orange… A given label therefore has the same colour in
	 * every component (the same axle type at the rear as at the front). Same
	 * computation on the front end and in the editor.
	 *
	 * @param {Array}  components Components of the kit (with their ranges).
	 * @param {Object} tier       Range.
	 * @return {string} “#rrggbb”, '' with no range.
	 */
	function tierColor(components, tier) {
		if (!tier) return '';
		if (/^#[0-9a-f]{6}$/i.test(tier.color || '')) return tier.color.toLowerCase();
		var norm = function (t) { return String(t.label || t.id || '').trim().toLowerCase(); };
		var labels = [];
		(components || []).forEach(function (c) {
			(c.tiers || []).forEach(function (t) {
				if (labels.indexOf(norm(t)) === -1) labels.push(norm(t));
			});
		});
		var index = Math.max(0, labels.indexOf(norm(tier)));
		return TIER_PALETTE[index % TIER_PALETTE.length].color;
	}

	root.DahuCompositeTiers = { sections: sections, isAddon: isAddon, isQuestion: isQuestion, anchors: anchors, selectedProductId: selectedProductId, palette: TIER_PALETTE, tierColor: tierColor, tierTint: tierTint };
}(typeof window !== 'undefined' ? window : this));
