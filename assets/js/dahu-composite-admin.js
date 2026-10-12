/**
 * DAHU COMPOSITE — kit configuration metabox.
 *
 * Local state { components, scenarios } rendered as repeaters, serialised into
 * the hidden field #dahu-kit-config-json on every change. The final
 * normalisation is done on the server when the product is saved.
 */
(function ($) {
	'use strict';

	var cfg = window.DahuCompositeAdmin || {};
	// Localised labels, from wp_localize_script (Dahu_Composite_Admin::script_i18n()).
	// Not named `t`: that name is already a tier in tiersHtml() and below.
	var i18n = cfg.i18n || {};
	var state = {
		components: (cfg.config && cfg.config.components) || [],
		scenarios: (cfg.config && cfg.config.scenarios) || [],
		// Customer exceptions, the same JSON as the rest.
		customer_exceptions: (cfg.config && cfg.config.customer_exceptions) || []
	};
	// Labels of the customers (“Name — e-mail (#id)”), completed by the search.
	var customerLabels = cfg.customers || {};
	var productInfo = cfg.productInfo || {};

	var $components, $scenarios, $json, $warnings, $simulator, $exceptions, $generated;
	// Index of the component being dragged (null outside a drag and drop).
	var dragIndex = null;
	// Category being dragged: { component, index } (null otherwise).
	var dragTier = null;
	// “Categories / ranges” panels that are open, per component id.
	var tiersOpen = {};
	// Previews of the images chosen in the media library, per media ID.
	var mediaUrls = cfg.mediaUrls || {};
	// File name of a media with no thumbnail (the video of a range).
	var mediaNames = cfg.mediaNames || {};

	/* ---------------- Collapsing the blocks ----------------
	 *
	 * A block (component or scenario) is folded or unfolded. The state is kept
	 * BY IDENTIFIER, never by index: reordering or deleting must not open the
	 * wrong block.
	 *
	 * Folding only hides for display. The body stays in the DOM (the `hidden`
	 * attribute), and in any case saving reads no input field: it serialises
	 * `state` into the single hidden field (see serialize() and
	 * Dahu_Composite_Admin::render_save_field).
	 */
	var FOLD_KEY = 'dahu-kit-admin-open-' + (cfg.productId || 0);
	// { component: { id: bool }, scenario: { id: bool } }
	var openState = { component: {}, scenario: {} };
	// Block to open and focus on the next render: { kind, id }.
	var pendingFocus = null;
	// Beyond this number of items, a section arrives folded.
	var FOLD_THRESHOLD = 3;

	function loadOpenState() {
		try {
			var raw = window.localStorage.getItem(FOLD_KEY);
			var saved = raw ? JSON.parse(raw) : null;
			if (saved && typeof saved === 'object') {
				openState.component = saved.component || {};
				openState.scenario = saved.scenario || {};
			}
		} catch (e) {
			// Private browsing, storage full, storage refused: we do without
			// the memory from one reload to the next, nothing more.
		}
	}

	function saveOpenState() {
		try {
			window.localStorage.setItem(FOLD_KEY, JSON.stringify(openState));
		} catch (e) {}
	}

	/**
	 * Is a block unfolded? With no value remembered, the section decides: open
	 * when it fits on screen (few items), folded otherwise.
	 */
	function isOpen(kind, id, total) {
		var known = openState[kind];
		if (known && Object.prototype.hasOwnProperty.call(known, id)) return !!known[id];
		return total <= FOLD_THRESHOLD;
	}

	function setOpen(kind, id, open) {
		openState[kind][id] = !!open;
		saveOpenState();
	}

	function foldAll(kind, open) {
		var list = kind === 'component' ? state.components : state.scenarios;
		list.forEach(function (item) { openState[kind][item.id] = !!open; });
		saveOpenState();
		if (kind === 'component') renderComponents(); else renderScenarios();
	}

	/**
	 * Header of a collapsible block.
	 *
	 * The collapse button and the actions are SIBLING buttons: nesting “Delete”
	 * inside the button that folds would be invalid and unusable from the
	 * keyboard.
	 *
	 * @param {Object} o kind, id, bodyId, open, title, badges, actions, tooltip, grip.
	 */
	function boxHeadHtml(o) {
		return '<div class="dahu-kit-admin__box-head"' + (o.grip ? ' draggable="true"' : '') + '>' +
			(o.grip ? '<span class="dahu-kit-admin__grip" aria-hidden="true" title="' + escAttr(L('dragToReorder', 'Drag to reorder')) + '">⠿</span> ' : '') +
			'<button type="button" class="dahu-kit-admin__toggle" data-fold="' + escAttr(o.kind) + '"' +
				' aria-expanded="' + (o.open ? 'true' : 'false') + '" aria-controls="' + escAttr(o.bodyId) + '">' +
				'<span class="dahu-kit-admin__caret" aria-hidden="true">▸</span> ' +
				'<span class="dahu-kit-admin__box-title"' + (o.tooltip ? ' title="' + escAttr(o.tooltip) + '"' : '') + '>' + o.title + '</span>' +
				(o.badges || '') +
			'</button>' +
			'<span class="dahu-kit-admin__actions">' + o.actions + '</span>' +
		'</div>';
	}

	/* ---------------- Focus and scrolling during a re-render ----------------
	 *
	 * renderComponents() / renderScenarios() rebuild the whole list. Without
	 * care, the field being edited loses focus and the page jumps. We find the
	 * field again by its POSITION inside its block — approximate by nature, since
	 * a structural re-render is exactly what changes the shape of the block, but
	 * good enough in the ordinary cases (checkbox, list).
	 */
	function captureFocus($container) {
		var el = document.activeElement;
		if (!el || !$container.length || !$.contains($container[0], el)) return null;
		var $box = $(el).closest('.dahu-kit-admin__box');
		if (!$box.length) return null;
		return {
			boxIndex: $box.data('index'),
			fieldIndex: $box.find('input, select, textarea, button').index(el),
			start: 'selectionStart' in el ? el.selectionStart : null,
			end: 'selectionEnd' in el ? el.selectionEnd : null,
			scrollY: window.scrollY
		};
	}

	function restoreFocus($container, snap) {
		if (!snap) return;
		if (typeof snap.scrollY === 'number') window.scrollTo(window.scrollX, snap.scrollY);
		if (snap.fieldIndex < 0) return;
		var $box = $container.children('.dahu-kit-admin__box').eq(snap.boxIndex);
		var el = $box.find('input, select, textarea, button').get(snap.fieldIndex);
		if (!el) return;
		try {
			el.focus();
			if (snap.start !== null && 'setSelectionRange' in el) el.setSelectionRange(snap.start, snap.end);
		} catch (e) {}
	}

	/* ------------------------------------------------------------------ */

	function esc(text) {
		return $('<div>').text(text === undefined || text === null ? '' : String(text)).html();
	}

	/**
	 * esc() (textContent -> innerHTML) only escapes & < >: not enough for an
	 * attribute value. Everything interpolated into value="", placeholder="" or
	 * src="" goes through escAttr().
	 */
	function escAttr(text) {
		return esc(text).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	/**
	 * Localised label. `fallback` is the English wording, kept so the editor
	 * stays readable when the localisation did not load (same approach as
	 * dahu-composite-front.js).
	 *
	 * Returns the RAW string: a caller that injects it into the markup built
	 * here wraps it in esc() (or escAttr() for an attribute value), because a
	 * translation may contain &, < or a quote.
	 *
	 * @param {string} key      Key of Dahu_Composite_Admin::script_i18n().
	 * @param {string} fallback English wording.
	 * @return {string}
	 */
	function L(key, fallback) {
		var value = i18n[key];
		return typeof value === 'string' && value !== '' ? value : fallback;
	}

	/**
	 * Substitutes %s (in order) and %1$s … %9$s (positional) in a localised
	 * string. Sentences are localised whole and substituted here rather than
	 * concatenated from pieces in the script: a translator cannot work with a
	 * fragment, and word order changes from one language to the next.
	 *
	 * @param {string} template Localised string.
	 * @return {string}
	 */
	function fmt(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;
		return String(template).replace(/%(?:([1-9])\$)?s/g, function (match, pos) {
			var value = pos ? args[pos - 1] : args[i++];
			return value === undefined || value === null ? '' : String(value);
		});
	}

	/**
	 * Picks the form of a count string. PHP sends the two forms _n() registered
	 * for this locale, as [ singular, plural ]; the script picks on n === 1.
	 *
	 * Returns the string UNSUBSTITUTED: the caller passes it to fmt() with the
	 * count and whatever else the sentence needs, so a form carrying several
	 * placeholders works the same as one carrying only the count.
	 *
	 * Limit, stated plainly: this covers the languages with two plural forms.
	 * A language with three or more (Polish, Russian, Arabic) would need
	 * wp.i18n._n() in the script, which means wp_set_script_translations() and
	 * JSON translation files. Everywhere a count could be phrased without
	 * inflection (“Restricted: 3”) it is, and no plural is involved at all.
	 *
	 * @param {string} key  Key holding [ singular, plural ].
	 * @param {number} n    Count.
	 * @param {string} one  English singular.
	 * @param {string} many English plural.
	 * @return {string}
	 */
	function plural(key, n, one, many) {
		var forms = i18n[key];
		var usable = Object.prototype.toString.call(forms) === '[object Array]'
			&& forms.length === 2
			&& typeof forms[0] === 'string'
			&& typeof forms[1] === 'string';
		return usable ? forms[n === 1 ? 0 : 1] : (n === 1 ? one : many);
	}

	var simTimer = null;

	function serialize() {
		$json.val(JSON.stringify(state));
		// The simulator follows what is typed, with a delay so as not to call the
		// server on every keystroke.
		if (simTimer) window.clearTimeout(simTimer);
		simTimer = window.setTimeout(simRefresh, 400);
	}

	function slugify(text) {
		return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
			.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
	}

	function uniqueComponentId(base) {
		var id = base || 'composant';
		var n = 1;
		var exists = function (candidate) { return state.components.some(function (c) { return c.id === candidate; }); };
		var candidate = id;
		while (exists(candidate)) { candidate = id + '-' + (++n); }
		return candidate;
	}

	/* Categories (ranges) ------------------------------------------------ */

	/** Free category id within a component (apart from the `except` category). */
	function uniqueTierId(component, base, except) {
		var root = base || 'categorie';
		var taken = function (candidate) {
			return (component.tiers || []).some(function (t) { return t !== except && t.id === candidate; });
		};
		var candidate = root;
		var n = 1;
		while (taken(candidate)) { candidate = root + '-' + (++n); }
		return candidate;
	}

	/** Number of options attached to a category. */
	function tierUsage(component, id) {
		return component.options.filter(function (o) { return o.tier === id; }).length;
	}

	/**
	 * The same migration as Dahu_Composite_Kit_Config::normalize_tiers(), for a
	 * JSON imported in the older format (free labels in “tier”, no “tiers”): the
	 * configuration loaded from the product is already normalised by the server.
	 * Categories created in order of first appearance, with “tier” replaced by
	 * the id.
	 */
	/**
	 * Layouts of a multiple-choice component (display_style). The same values as
	 * Dahu_Composite_Kit_Config::DISPLAY_STYLES, plus '': the general appearance
	 * of the kit (the “Appearance” box).
	 */
	var DISPLAY_STYLES = [
		['', L('styleDefault', 'Default (the kit’s general appearance)')],
		['image', L('styleImage', 'With image — cards in a grid')],
		['row', L('styleRow', 'Inline — one expandable row per option')],
		['small', L('styleSmall', 'Small — compact grid')],
		['icon', L('styleIcon', 'Category icon')],
		['chips', L('styleChips', 'Tickable chips')]
	];

	/**
	 * Configuration saved with the older test setting (test_display_style): taken
	 * over into display_style, then forgotten — otherwise the server would read it
	 * back and “Default” could no longer be chosen.
	 */
	function migrateDisplayStyle(component) {
		if (!component || typeof component !== 'object') return;
		if (component.display_style === undefined && component.test_display_style) component.display_style = component.test_display_style;
		delete component.test_display_style;
	}

	function migrateTiers(component) {
		component.options = component.options || [];
		if (!Array.isArray(component.tiers)) {
			component.tiers = [];
			var byLabel = {};
			component.options.forEach(function (o) {
				if (!o.tier) return;
				if (!byLabel[o.tier]) {
					var tier = { id: uniqueTierId(component, slugify(o.tier)), label: o.tier, description: null };
					component.tiers.push(tier);
					byLabel[o.tier] = tier.id;
				}
				o.tier = byLabel[o.tier];
			});
			return;
		}
		// Recent format: a value equal to the LABEL of a category (JSON
		// written by hand) falls back to its id.
		component.options.forEach(function (o) {
			if (!o.tier || component.tiers.some(function (t) { return t.id === o.tier; })) return;
			component.tiers.forEach(function (t) { if (t.label === o.tier) o.tier = t.id; });
		});
	}

	/**
	 * “Range” field of an option: a dropdown of the categories of the component
	 * (no free text any more). With no category: no list, and a link to create
	 * one.
	 *
	 * @param {Object} component Component.
	 * @param {Object} option    Option.
	 * @param {string} attr      data-opt-field or data-popt-field.
	 */
	function tierFieldHtml(component, option, attr) {
		var tiers = component.tiers || [];
		if (!tiers.length) {
			return '<p class="dahu-kit-admin__tier-none">' + esc(L('rangeNoCategory', 'Range: no category.')) + ' ' +
				'<button type="button" class="button-link" data-tier-add>' + esc(L('rangeCreate', 'Create a category')) + '</button></p>';
		}
		var known = tiers.some(function (t) { return t.id === option.tier; });
		return '<label>' + esc(L('rangeField', 'Range')) + ' <select ' + attr + '="tier">' +
			'<option value="">' + esc(L('optionNone', '— None —')) + '</option>' +
			tiers.map(function (t) {
				return '<option value="' + escAttr(t.id) + '"' + (option.tier === t.id ? ' selected' : '') + '>' + esc(t.label || t.id) + '</option>';
			}).join('') +
			// Attached to a category that has gone: shown, so that the
			// validator is not the only thing to say so.
			(option.tier && !known ? '<option value="' + escAttr(option.tier) + '" selected>' + esc(fmt(L('notFound', '%s (not found)'), option.tier)) + '</option>' : '') +
			'</select></label>';
	}

	/**
	 * “Anchor beside”: an “add-on” component shown right after a card of another
	 * component of the same screen. Two lists, like the target of a scenario: the
	 * component, then the option (the base product — the card, not a variant). The
	 * option may be left empty (“Chosen card”) and so may the component
	 * (“Automatic”: the chosen card of the first product component of the screen).
	 * A reference that no longer matches anything stays visible, marked, like a
	 * range that cannot be found.
	 */
	function anchorFieldHtml(component) {
		if (isPath(component)) return '';
		var hosts = state.components.filter(function (h) { return h !== component && !isPath(h); });
		var hostId = component.anchor_component_id || '';
		var host = null;
		hosts.forEach(function (h) { if (h.id === hostId) host = h; });
		var html = '<label>' + esc(L('anchorNextTo', 'Anchor next to')) + ' <select data-field="anchor_component_id">' +
			'<option value="">' + esc(L('anchorAutomatic', '— Automatic (the chosen card of the first component on the screen) —')) + '</option>' +
			hosts.map(function (h) {
				var elsewhere = !component.display_group || h.display_group !== component.display_group;
				return '<option value="' + escAttr(h.id) + '"' + (h.id === hostId ? ' selected' : '') + '>' +
					esc(elsewhere ? fmt(L('anchorOtherScreen', '%s (other screen)'), h.title || h.id) : (h.title || h.id)) + '</option>';
			}).join('') +
			(hostId && !host ? '<option value="' + escAttr(hostId) + '" selected>' + esc(fmt(L('notFound', '%s (not found)'), hostId)) + '</option>' : '') +
			'</select></label>';
		if (!hostId) return html;
		var productId = parseInt(component.anchor_product_id, 10) || 0;
		var options = host ? host.options : [];
		var known = options.some(function (o) { return o.product_id === productId; });
		return html + '<label>' + esc(L('anchorCard', 'Card')) + ' <select data-field="anchor_product_id">' +
			'<option value="">' + esc(L('anchorChosenCard', '— Chosen card —')) + '</option>' +
			options.map(function (o) {
				return '<option value="' + o.product_id + '"' + (o.product_id === productId ? ' selected' : '') + '>' + esc(productLabel(o.product_id)) + '</option>';
			}).join('') +
			(productId && !known ? '<option value="' + productId + '" selected>' + esc(fmt(L('anchorAbsent', '%s (absent from the options)'), productLabel(productId))) + '</option>' : '') +
			'</select></label>';
	}

	/**
	 * “Categories / ranges” repeater of a component: label, optional description,
	 * order (▲▼ or drag and drop by the handle), duplication, deletion. The order
	 * of the list is the order of the sections of the configurator.
	 */
	function tiersHtml(component) {
		var tiers = component.tiers || [];
		var rows = tiers.map(function (t, tierIndex) {
			var used = tierUsage(component, t.id);
			return '<div class="dahu-kit-admin__tier" data-tier="' + tierIndex + '">' +
				'<p class="dahu-kit-admin__tier-head" draggable="true">' +
					'<span class="dahu-kit-admin__grip" aria-hidden="true" title="' + escAttr(L('dragToReorder', 'Drag to reorder')) + '">⠿</span> ' +
					'<strong>' + esc(t.label || L('noLabel', '(no label)')) + '</strong> <code>' + esc(t.id) + '</code> ' +
					'<span class="description">' + esc(fmt(L('optionCount', 'Options: %s'), used)) + '</span>' +
					'<span class="dahu-kit-admin__actions">' +
						'<button type="button" class="button-link" data-tier-move="-1" title="' + escAttr(L('moveUp', 'Move up')) + '">▲</button> ' +
						'<button type="button" class="button-link" data-tier-move="1" title="' + escAttr(L('moveDown', 'Move down')) + '">▼</button> ' +
						'<button type="button" class="button-link" data-tier-duplicate>' + esc(L('duplicate', 'Duplicate')) + '</button> ' +
						'<button type="button" class="button-link delete" data-tier-remove>' + esc(L('delete', 'Delete')) + '</button>' +
					'</span>' +
				'</p>' +
				'<div class="dahu-kit-admin__grid">' +
					'<label>' + esc(L('labelField', 'Label')) + ' <input type="text" data-tier-field="label" value="' + escAttr(t.label || '') + '"></label>' +
					tierColorHtml(t) +
				'</div>' +
				'<label class="dahu-kit-admin__full">' + esc(L('infoBubbleText', 'Info bubble text (optional)')) + ' <textarea rows="2" data-tier-field="description">' + esc(t.description || '') + '</textarea></label>' +
				tierMediaHtml(t) +
			'</div>';
		}).join('');

		// Open / closed kept from one render to the next (tiersOpen).
		return '<details class="dahu-kit-admin__display dahu-kit-admin__tiers" data-tiers-panel' + (tiersOpen[component.id] ? ' open' : '') + '>' +
			'<summary>' + esc(fmt(L('rangesPanel', 'Categories / ranges (%s)'), tiers.length)) + ' ▾</summary>' +
			'<p class="description">' + esc(L('rangesHelp', 'One section per category, in this order.')) +
				(tiers.length ? '' : ' ' + esc(L('rangesHelpEmpty', '“Range display” appears above as soon as there is one.'))) + '</p>' +
			rows +
			'<p><button type="button" class="button" data-tier-add>' + esc(L('rangeAdd', 'Add a category')) + '</button></p></details>';
	}

	/**
	 * Display of the ranges of a component, three independent settings: “New row”
	 * (every range starts a new row of cards), “Badge” (name of the range to the
	 * right of the name of every card, on the colour of the range), “Tinted
	 * background” (background of the cards tinted with the colour of their range).
	 *
	 * Visible in the body of the component, just above the “Categories / ranges”
	 * panel (it used to be INSIDE that panel, folded by default, and went
	 * unnoticed). Nothing with no range: the server then sets all three settings
	 * back to false (kit-config), so a checkbox would have no effect.
	 */
	function tierDisplayChecks(component) {
		if (!(component.tiers || []).length) return '';
		return '<p class="dahu-kit-admin__tier-display"><strong>' + esc(L('rangeDisplay', 'Display of the ranges:')) + '</strong> ' +
			'<label class="dahu-kit-admin__check"><input type="checkbox" data-field="tier_newline"' + (component.tier_newline ? ' checked' : '') + '> ' + esc(L('rangeNewline', 'On its own line')) + '</label> ' +
			'<label class="dahu-kit-admin__check"><input type="checkbox" data-field="tier_badge"' + (component.tier_badge ? ' checked' : '') + '> ' + esc(L('rangeBadge', 'Badge')) + '</label> ' +
			'<label class="dahu-kit-admin__check"><input type="checkbox" data-field="tier_tint"' + (component.tier_tint ? ' checked' : '') + '> ' + esc(L('rangeTint', 'Coloured background')) + '</label>' +
		'</p>';
	}

	/**
	 * Colour of a range: badge and tinted background of the cards. One of the
	 * eight colours of the palette (DahuCompositeTiers.palette: shade of the
	 * badge, light background supplied with it). “Default”: the colour assigned in
	 * the order of the ranges of the kit (blue, purple, orange…), shown in
	 * brackets. A code outside the palette is still offered as it is.
	 */
	function tierColorHtml(t) {
		var tiers = window.DahuCompositeTiers;
		var color = /^#[0-9a-f]{6}$/i.test(t.color || '') ? t.color.toLowerCase() : '';
		// Default colour: that of the range with no choice (the same computation as the front end).
		var fallback = tiers.tierColor(state.components, { id: t.id, label: t.label });
		var effective = color || fallback;
		var nameOf = function (value) {
			var name = value;
			tiers.palette.forEach(function (p) { if (p.color === value) name = p.name; });
			return name;
		};
		var known = tiers.palette.some(function (p) { return p.color === color; });
		return '<label class="dahu-kit-admin__tier-color">' + esc(L('colourField', 'Colour')) + ' <span class="dahu-kit-admin__swatch" style="background-color:' + escAttr(effective) + '" aria-hidden="true"></span>' +
			'<select data-tier-field="color">' +
				'<option value=""' + (color ? '' : ' selected') + '>' + esc(fmt(L('colourDefault', 'Default (%s)'), nameOf(fallback))) + '</option>' +
				tiers.palette.map(function (p) {
					return '<option value="' + p.color + '"' + (p.color === color ? ' selected' : '') + '>' + esc(p.name) + '</option>';
				}).join('') +
				(color && !known ? '<option value="' + escAttr(color) + '" selected>' + esc(color) + '</option>' : '') +
			'</select></label>';
	}

	/**
	 * Image or video of the “?” popover of a range, on top of the text: an
	 * attachment from the media library (`media_id`, image or video) or the link
	 * of a video (`media_url`: YouTube, Vimeo… or a .mp4 file). The attachment
	 * comes before the link when both are set.
	 */
	function tierMediaHtml(t) {
		var id = t.media_id || '';
		var preview = '';
		if (id && mediaUrls[id]) preview = '<img class="dahu-kit-admin__thumb" src="' + escAttr(mediaUrls[id]) + '" alt=""> ';
		else if (id) preview = '<span class="dahu-kit-admin__media-name">' + esc(mediaNames[id] || L('media', 'Media')) + '</span> ';
		return '<div class="dahu-kit-admin__tier-media">' +
			'<p class="dahu-kit-admin__media">' + esc(L('tierMedia', 'Tooltip image or video (optional)')) + ' ' +
				preview +
				'<button type="button" class="button" data-tier-media>' + esc(id ? L('change', 'Change') : L('chooseFromLibrary', 'Choose from the media library')) + '</button> ' +
				(id ? '<button type="button" class="button-link delete" data-tier-media-clear>' + esc(L('remove', 'Remove')) + '</button> <code>#' + id + '</code>' : '') +
			'</p>' +
			'<label class="dahu-kit-admin__full">' + esc(L('tierMediaUrl', 'or a video link (YouTube, Vimeo, .mp4 file)')) + ' <input type="url" data-tier-field="media_url" value="' + escAttr(t.media_url || '') + '" placeholder="https://"></label>' +
		'</div>';
	}

	function componentIndex(id) {
		for (var i = 0; i < state.components.length; i++) {
			if (state.components[i].id === id) return i;
		}
		return -1;
	}

	function productLabel(id) {
		var info = productInfo[id];
		if (!info) return '#' + id;
		return info.name + ' (#' + id + (info.status && info.status !== 'publish' ? ', ' + info.status : '') + ')';
	}

	function isPath(component) {
		return !!component && component.component_type === 'path';
	}

	/** Possible values for a component: products + variations. */
	function valueChoices(component, withNone) {
		var choices = [];
		if (withNone) choices.push({ value: 'none', label: L('valueNoSelection', '— No selection —') });
		// Branch: the values are the option identifiers, not
		// products.
		if (isPath(component)) {
			component.options.forEach(function (o) {
				choices.push({ value: String(o.id), label: (o.label || o.id) + ' (' + o.id + ')' });
			});
			return choices;
		}
		(component ? component.options : []).forEach(function (o) {
			var info = productInfo[o.product_id];
			choices.push({ value: String(o.product_id), label: info && info.variations.length ? fmt(L('valueEveryVariant', '%s — every variant'), productLabel(o.product_id)) : productLabel(o.product_id) });
			if (info && withNone && info.variations.length) {
				// Conditions only: product chosen, variant not chosen yet.
				choices.push({ value: o.product_id + ':0', label: '    ↳ ' + fmt(L('valueVariantPending', '%s — variant not chosen yet'), info.name) });
			}
			if (info) {
				info.variations.forEach(function (v) {
					choices.push({ value: o.product_id + ':' + v.id, label: '    ↳ ' + info.name + ' — ' + v.name + ' (#' + v.id + ')' });
				});
			}
		});
		return choices;
	}

	function fetchMissingInfo() {
		var missing = [];
		state.components.forEach(function (c) {
			if (isPath(c)) return;
			c.options.forEach(function (o) { if (!productInfo[o.product_id]) missing.push(o.product_id); });
		});
		if (!missing.length) return $.Deferred().resolve().promise();
		return $.post(cfg.ajaxUrl, { action: 'dahu_composite_product_info', nonce: cfg.infoNonce, ids: missing }).then(function (response) {
			if (response && response.success) {
				Object.keys(response.data).forEach(function (id) { productInfo[id] = response.data[id]; });
			}
		});
	}

	function initSelects($context) {
		$(document.body).trigger('wc-enhanced-select-init');
		$context.find('select.dahu-kit-enhanced').each(function () {
			if (!$(this).data('select2') && $.fn.selectWoo) {
				$(this).selectWoo({ width: '100%' });
			}
		});
	}

	/* ------------------------------------------------------------------ */
	/* Components                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Display content specific to the kit, per option: title, description, image
	 * and badge. All optional — left empty, the configurator shows the real data
	 * of the product. Folded by default.
	 */
	/**
	 * Image picker of an option (wp.media): preview, “Choose / Change the image”,
	 * “Remove”. ONE single picker for both kinds of component — the same
	 * `display_image_id` field (attachment ID), the same handlers
	 * [data-opt-media] / [data-opt-media-clear], which find the option again by
	 * its index whatever the kind.
	 */
	function optionMediaHtml(o) {
		var imageId = o.display_image_id || '';
		var preview = imageId && mediaUrls[imageId]
			? '<img class="dahu-kit-admin__thumb" src="' + escAttr(mediaUrls[imageId]) + '" alt="">'
			: '';
		return '<p class="dahu-kit-admin__media">' +
			preview +
			'<button type="button" class="button" data-opt-media>' + esc(imageId ? L('changeImage', 'Change the image') : L('chooseImage', 'Choose an image')) + '</button> ' +
			(imageId ? '<button type="button" class="button-link delete" data-opt-media-clear>' + esc(L('remove', 'Remove')) + '</button> <code>#' + imageId + '</code>' : '') +
		'</p>';
	}

	/**
	 * “Help image” of a product option (`help_image_id`): a complementary image,
	 * shown on the BACK of the card only (Description section, at the top). The
	 * image above (the front) does not depend on it. Not for a branch (its cards
	 * have no back).
	 */
	function optionHelpMediaHtml(o, variation, hasVariations) {
		var map = variationHelpImages(o);
		var helpId = variation ? (map[variation.id] || '') : (o.help_image_id || '');
		var attr = variation ? ' data-help-var="' + variation.id + '"' : '';
		var preview = helpId && mediaUrls[helpId]
			? '<img class="dahu-kit-admin__thumb" src="' + escAttr(mediaUrls[helpId]) + '" alt="">'
			: '';
		return '<p class="dahu-kit-admin__media dahu-kit-admin__media--help">' +
			'<span class="dahu-kit-admin__help-target">' + (variation ? esc(variation.name) + ' <code>#' + variation.id + '</code>' : esc(hasVariations ? L('everyVariant', 'Every variant') : L('image', 'Image'))) + '</span> ' +
			preview +
			'<button type="button" class="button" data-opt-help-media' + attr + '>' + esc(helpId ? L('change', 'Change') : L('choose', 'Choose')) + '</button> ' +
			(helpId ? '<button type="button" class="button-link delete" data-opt-help-media-clear' + attr + '>' + esc(L('remove', 'Remove')) + '</button> <code>#' + helpId + '</code>' : '') +
		'</p>';
	}

	/**
	 * Help images per variation of an option: { variation_id: attachment_id }. An
	 * empty array (the JSON of an empty table on the PHP side) reads as {}.
	 */
	function variationHelpImages(o) {
		var map = o.variation_help_images;
		return map && typeof map === 'object' && !Array.isArray(map) ? map : {};
	}

	/**
	 * “Help image” block of a product component (grouped outside “Customise the
	 * display”): an image shown on the BACK of the card, at the top of the
	 * description (e.g. a measuring diagram). Per option: one image for all of its
	 * variants (help_image_id), then, for a variable product, one per variant
	 * (variation_help_images), which replaces it when that variant is chosen.
	 */
	function helpFieldsHtml(component) {
		if (isPath(component) || !component.options.length) return '';
		var count = 0;
		var rows = component.options.map(function (o, optIndex) {
			var info = productInfo[o.product_id];
			var vars = info && info.variations.length ? info.variations : [];
			if (o.help_image_id) count++;
			count += Object.keys(variationHelpImages(o)).length;
			return '<div class="dahu-kit-admin__option dahu-kit-admin__help" data-option="' + optIndex + '">' +
				'<p class="dahu-kit-admin__option-title"><strong>' + esc(productLabel(o.product_id)) + '</strong></p>' +
				optionHelpMediaHtml(o, null, vars.length > 0) +
				vars.map(function (v) { return optionHelpMediaHtml(o, v); }).join('') +
			'</div>';
		}).join('');
		return '<details class="dahu-kit-admin__display" data-panel="help"' + (panelOpen[component.id + ':help'] ? ' open' : '') + '>' +
			'<summary>' + esc(L('helpImagePanel', 'Help image')) + ' ▾' + (count ? ' — ' + count : '') + '</summary>' +
			'<p class="description">' + esc(L('helpImageHelp', 'On the back of the card, at the top of the description.')) + '</p>' +
			rows + '</details>';
	}

	/**
	 * “Proposed variants” block of a product component: per option with variants,
	 * the variants offered (allowed_variations; empty = all of them). A variant
	 * that is unticked is offered to nobody (a generated scenario
	 * dahu-variations-*, on the server). The last ticked variant cannot be
	 * unticked: removing the option is done in “Options (products)”.
	 */
	function variantsFieldsHtml(component) {
		if (isPath(component)) return '';
		var restricted = 0;
		var rows = component.options.map(function (o, optIndex) {
			var info = productInfo[o.product_id];
			if (!info || !info.variations.length) return '';
			var allowed = (o.allowed_variations || []).map(Number);
			if (allowed.length) restricted++;
			return '<div class="dahu-kit-admin__option dahu-kit-admin__variants" data-option="' + optIndex + '">' +
				'<p class="dahu-kit-admin__option-title"><strong>' + esc(productLabel(o.product_id)) + '</strong> — ' + (allowed.length ? allowed.length + ' / ' + info.variations.length : esc(L('variantsAll', 'all'))) + '</p>' +
				'<div class="dahu-kit-admin__vis-vars">' + info.variations.map(function (v) {
					var on = !allowed.length || allowed.indexOf(v.id) !== -1;
					return '<label class="dahu-kit-admin__check"><input type="checkbox" data-allow-var="' + v.id + '"' + (on ? ' checked' : '') + '> ' + esc(v.name) + ' <code>#' + v.id + '</code></label>';
				}).join('') + '</div>' +
			'</div>';
		}).join('');
		if (!rows) return '';
		return '<details class="dahu-kit-admin__display" data-panel="variants"' + (panelOpen[component.id + ':variants'] ? ' open' : '') + '>' +
			'<summary>' + esc(L('variantsPanel', 'Variations offered')) + ' ▾ — ' + esc(restricted ? fmt(L('variantsRestrictedCount', 'restricted on %s'), restricted) : L('variantsAll', 'all')) + '</summary>' +
			'<p class="description">' + esc(L('variantsHelp', 'Unticked: the variation is no longer offered.')) + '</p>' +
			rows + '</details>';
	}

	/** Open / closed of the [data-panel] blocks of a component, from one render to the next. */
	var panelOpen = {};

	/**
	 * New product option: the display checkboxes are written EXPLICITLY with the
	 * defaults of a new option (image and description yes, specifications and
	 * supplied products no). An absent key reads as “yes” (an option saved before
	 * those checkboxes existed): without these values, a new option would fall
	 * into that case.
	 */
	function newProductOption(id) {
		return { product_id: id, show_image: true, show_description: true, show_specs: false, show_bundled: false, is_public: true, private_variations: [], allowed_variations: [], variation_help_images: {} };
	}

	/**
	 * Fields MERGED by a JSON import instead of being replaced: the image, titles,
	 * descriptions and badges, and the info bubble text of a component (`info`).
	 * The ranges have their own merge (IMPORT_KEEP_TIER, importedTierFallbacks()).
	 * Every other field comes from the JSON as it is.
	 */
	var IMPORT_KEEP_COMPONENT = ['title', 'description', 'display_style', 'info', 'none_image_id', 'tier_newline', 'tier_badge', 'tier_tint'];
	var IMPORT_KEEP_TIER = ['description', 'media_id', 'media_url', 'color'];
	var IMPORT_KEEP_OPTION = ['display_image_id', 'help_image_id', 'display_title', 'display_description', 'badge', 'label', 'link', 'allowed_variations', 'variation_help_images'];

	/** “Nothing” value of an imported field: key absent, null, 0, an empty or blank string. */
	function importBlank(value) {
		return value === undefined || value === null || value === 0 || String(value).trim() === '';
	}

	/**
	 * JSON import: a merged field (IMPORT_KEEP_*) left empty in the JSON keeps the
	 * value already shown for the same component (id) or the same option (same
	 * component, same product or same branch option). A value present in the JSON
	 * wins. Modifies `imported` in place; returns the number of values kept per
	 * field ({ display_image_id: 3, … }).
	 */
	function importedFieldFallbacks(current, imported) {
		var comps = {};
		var opts = {};
		var optionKey = function (c, o) { return c.id + '|' + (o.product_id ? String(o.product_id) : String(o.id || '')); };
		(current || []).forEach(function (c) {
			comps[c.id] = c;
			(c.options || []).forEach(function (o) {
				if (o && typeof o === 'object') opts[optionKey(c, o)] = o;
			});
		});
		var kept = {};
		var keep = function (target, source, fields) {
			if (!source) return;
			fields.forEach(function (field) {
				if (importBlank(target[field]) && !importBlank(source[field])) {
					target[field] = source[field];
					kept[field] = (kept[field] || 0) + 1;
				}
			});
		};
		(imported || []).forEach(function (c) {
			if (!c || typeof c !== 'object') return;
			keep(c, comps[c.id], IMPORT_KEEP_COMPONENT);
			(Array.isArray(c.options) ? c.options : []).forEach(function (o) {
				if (o && typeof o === 'object') keep(o, opts[optionKey(c, o)], IMPORT_KEEP_OPTION);
			});
		});
		return kept;
	}

	/**
	 * JSON import, ranges: the bubble text and the media of a range left empty in
	 * the JSON keep the value already set on the SAME range of the same component
	 * — same id, failing that same label. Before, the list of ranges from the JSON
	 * replaced everything: reimporting a fixture (ranges with no text) wiped the
	 * texts entered in the admin. To be called AFTER migrateTiers() (older format:
	 * ranges created from the labels). Completes `kept` (tier_* keys).
	 */
	function importedTierFallbacks(current, imported, kept) {
		var comps = {};
		(current || []).forEach(function (c) { if (c && c.id) comps[c.id] = c; });
		(imported || []).forEach(function (c) {
			var previous = c && comps[c.id];
			if (!previous || !Array.isArray(previous.tiers) || !Array.isArray(c.tiers)) return;
			c.tiers.forEach(function (tier) {
				if (!tier || typeof tier !== 'object') return;
				var source = null;
				previous.tiers.forEach(function (p) { if (!source && p && p.id === tier.id) source = p; });
				previous.tiers.forEach(function (p) { if (!source && p && p.label && p.label === tier.label) source = p; });
				if (!source) return;
				IMPORT_KEEP_TIER.forEach(function (field) {
					if (importBlank(tier[field]) && !importBlank(source[field])) {
						tier[field] = source[field];
						kept['tier_' + field] = (kept['tier_' + field] || 0) + 1;
					}
				});
			});
		});
		return kept;
	}

	/** Sentence of the import alert: what has been kept, per field. */
	function importKeptSentence(kept) {
		var labels = {
			display_image_id: L('keptImages', '%s image(s)'),
			help_image_id: L('keptHelpImages', '%s help image(s)'),
			display_title: L('keptOptionTitles', '%s option title(s)'),
			label: L('keptOptionLabels', '%s option label(s)'),
			display_description: L('keptOptionDescriptions', '%s option description(s)'),
			badge: L('keptBadges', '%s badge(s)'),
			title: L('keptComponentTitles', '%s component title(s)'),
			display_style: L('keptComponentLayouts', '%s component layout(s)'),
			description: L('keptComponentDescriptions', '%s component description(s)'),
			info: L('keptComponentInfo', '%s component info bubble text(s)'),
			none_image_id: L('keptNoneImages', '%s “None” card image(s)'),
			tier_description: L('keptRangeInfo', '%s range info bubble text(s)'),
			tier_media_id: L('keptRangeMedia', '%s range media'),
			tier_media_url: L('keptRangeVideoLinks', '%s range video link(s)'),
			link: L('keptCardLinks', '%s card link(s)'),
			allowed_variations: L('keptOfferedVariations', '%s offered variation choices'),
			variation_help_images: L('keptVariantHelpImages', '%s variant help images'),
			tier_color: L('keptRangeColours', '%s range colour(s)'),
			tier_newline: L('keptRangeNewline', '%s “On its own line” setting(s)'),
			tier_badge: L('keptRangeBadge', '%s “Badge” setting(s)'),
			tier_tint: L('keptRangeTint', '%s “Coloured background” setting(s)')
		};
		var parts = Object.keys(kept).map(function (field) { return fmt(labels[field], kept[field]); });
		return parts.length ? '\n\n' + fmt(L('importKept', 'Already set in the admin and absent from the imported JSON, so kept: %s.'), parts.join(', ')) : '';
	}

	/** Checkbox of an option (data-opt-field, a boolean value). Key absent = ticked. */
	function optionCheck(field, label, checked) {
		return '<label class="dahu-kit-admin__check"><input type="checkbox" data-opt-field="' + field + '"' + (checked ? ' checked' : '') + '> ' + esc(label) + '</label>';
	}

	/**
	 * Image of the “None” card of the component (`none_image_id`): an optional
	 * single-choice component only — neither a multiple choice nor an add-on (a
	 * “Select” card or a question) has a “None” card. Empty: the shared image from
	 * WooCommerce › Dahu Kits. The same picker as the image of an option.
	 */
	function noneImageHtml(component) {
		if (!component.optional || component.selection_mode === 'multiple') return '';
		if (window.DahuCompositeTiers && window.DahuCompositeTiers.isAddon(component)) return '';
		var imageId = component.none_image_id || '';
		var preview = imageId && mediaUrls[imageId]
			? '<img class="dahu-kit-admin__thumb" src="' + escAttr(mediaUrls[imageId]) + '" alt="">'
			: '';
		return '<div class="dahu-kit-admin__none-image">' +
			'<p class="dahu-kit-admin__option-title"><strong>' + esc(L('noneCard', '“None” card')) + '</strong></p>' +
			'<p class="description">' + esc(L('noneCardHelp', 'Empty: the shared image (WooCommerce › Dahu Kits).')) + '</p>' +
			'<p class="dahu-kit-admin__media">' +
				preview +
				'<button type="button" class="button" data-none-media>' + esc(imageId ? L('changeImage', 'Change the image') : L('chooseImage', 'Choose an image')) + '</button> ' +
				(imageId ? '<button type="button" class="button-link delete" data-none-media-clear>' + esc(L('remove', 'Remove')) + '</button> <code>#' + imageId + '</code>' : '') +
			'</p>' +
		'</div>';
	}

	function displayFieldsHtml(component) {
		if (isPath(component) || !component.options.length) return '';

		var rows = component.options.map(function (o, optIndex) {
			var info = productInfo[o.product_id];
			return '<div class="dahu-kit-admin__option" data-option="' + optIndex + '">' +
				'<p class="dahu-kit-admin__option-title"><strong>' + esc(productLabel(o.product_id)) + '</strong> ' +
					'<button type="button" class="button-link" data-opt-move="-1" title="' + escAttr(L('moveUp', 'Move up')) + '">▲</button> ' +
					'<button type="button" class="button-link" data-opt-move="1" title="' + escAttr(L('moveDown', 'Move down')) + '">▼</button>' +
				'</p>' +
				'<div class="dahu-kit-admin__grid">' +
					'<label>' + esc(L('displayedTitle', 'Displayed title')) + ' <input type="text" data-opt-field="display_title" value="' + escAttr(o.display_title || '') + '" placeholder="' + escAttr(info ? info.name : '') + '"></label>' +
					'<label>' + esc(L('badgeField', 'Badge')) + ' <input type="text" data-opt-field="badge" value="' + escAttr(o.badge || '') + '" placeholder="' + escAttr(L('badgePlaceholder', 'e.g. Best seller')) + '"></label>' +
					tierFieldHtml(component, o, 'data-opt-field') +
				'</div>' +
				'<label class="dahu-kit-admin__full">' + esc(L('displayedDescription', 'Displayed description (simple HTML)')) + ' <textarea rows="2" data-opt-field="display_description">' + esc(o.display_description || '') + '</textarea></label>' +
				// Card: image, and the info sections offered
				// (icons, tabs, drawer). Key absent: image and
				// description ticked, the two others not.
				'<div class="dahu-kit-admin__grid dahu-kit-admin__option-show">' +
					optionCheck('show_image', L('showImage', 'Show the image'), o.show_image !== false) +
					optionCheck('show_description', L('showDescription', 'Description'), o.show_description !== false) +
					optionCheck('show_specs', L('showSpecs', 'Specifications'), o.show_specs !== false) +
					optionCheck('show_bundled', L('showBundled', 'Included products'), o.show_bundled !== false) +
				'</div>' +
				optionMediaHtml(o) +
			'</div>';
		}).join('');

		return '<details class="dahu-kit-admin__display"><summary>' + esc(L('customiseDisplay', 'Customise the display')) + ' ▾</summary>' +
			'<p class="description">' + esc(L('customiseDisplayHelp', 'Empty: the product’s own data.')) + '</p>' +
			noneImageHtml(component) +
			rows + '</details>';
	}

	/* ---------------- Public visibility ----------------
	 *
	 * Per option: “public” (the whole option, is_public) and, for a variable
	 * product, one “public” checkbox per variant (unticked = in
	 * private_variations). Key absent = public. An option or a variant that is
	 * restricted is only offered to the customers who have an exception (the
	 * “Customer exceptions” box); the server generates the hiding rules.
	 */
	var visibilityOpen = {};

	/** Number of restricted values of a component (options + variants). */
	function reservedCount(component) {
		var n = 0;
		(component.options || []).forEach(function (o) {
			var info = productInfo[o.product_id];
			if (o.is_public === false) { n++; return; }
			if (info && info.variations.length) n += (o.private_variations || []).length;
		});
		return n;
	}

	function visibilityFieldsHtml(component) {
		if (isPath(component) || !component.options.length) return '';
		var count = reservedCount(component);
		var rows = component.options.map(function (o, optIndex) {
			var info = productInfo[o.product_id];
			var isPublic = o.is_public !== false;
			var reserved = (o.private_variations || []).map(Number);
			var vars = info && info.variations.length ? info.variations.map(function (v) {
				var pub = isPublic && reserved.indexOf(v.id) === -1;
				return '<label class="dahu-kit-admin__check"><input type="checkbox" data-vis-var="' + v.id + '"' + (pub ? ' checked' : '') + (isPublic ? '' : ' disabled') + '> ' +
					esc(v.name) + ' <code>#' + v.id + '</code></label>';
			}).join('') : '';
			return '<div class="dahu-kit-admin__option dahu-kit-admin__visibility" data-option="' + optIndex + '">' +
				'<label class="dahu-kit-admin__check"><input type="checkbox" data-vis-public' + (isPublic ? ' checked' : '') + '> <strong>' + esc(productLabel(o.product_id)) + '</strong> — ' + esc(L('publicLabel', 'public')) +
					(isPublic ? '' : ' <span class="dahu-kit-admin__badge dahu-kit-admin__badge--reserved">' + esc(vars ? L('restrictedEveryVariant', 'restricted (every variant)') : L('restricted', 'restricted')) + '</span>') + '</label>' +
				(vars ? '<div class="dahu-kit-admin__vis-vars">' + vars + '</div>' : '') +
			'</div>';
		}).join('');
		return '<details class="dahu-kit-admin__display" data-visibility' + (visibilityOpen[component.id] ? ' open' : '') + '>' +
			'<summary>' + esc(L('visibilityPanel', 'Public visibility')) + ' ▾ — ' + esc(count ? fmt(L('restrictedCount', 'Restricted: %s'), count) : L('everythingPublic', 'everything is public')) + '</summary>' +
			'<p class="description">' + esc(L('visibilityHelp', 'Unticked: restricted to customers with an exception.')) + '</p>' +
			rows + '</details>';
	}

	/**
	 * Option repeater of a “path” component: pure branches, with no product, no
	 * price and no weight.
	 */
	function pathOptionsHtml(component) {
		var rows = component.options.map(function (o, optIndex) {
			return '<div class="dahu-kit-admin__option" data-option="' + optIndex + '">' +
				'<div class="dahu-kit-admin__grid">' +
					'<label>' + esc(L('labelField', 'Label')) + ' <input type="text" data-popt-field="label" value="' + escAttr(o.label || '') + '"></label>' +
					// Read-only identifier (a badge): generated from the
					// label the first time it is entered, never editable afterwards —
					// the scenarios refer to it.
					'<span class="dahu-kit-admin__id">' + esc(L('identifier', 'Identifier')) + ' ' + (o.id
						? '<code>' + esc(o.id) + '</code>'
						: '<em>' + esc(L('identifierFromLabel', 'generated from the label')) + '</em>') + '</span>' +
						tierFieldHtml(component, o, 'data-popt-field') +
					'<label>' + esc(L('badgeField', 'Badge')) + ' <input type="text" data-popt-field="badge" value="' + escAttr(o.badge || '') + '" placeholder="' + escAttr(L('badgePathPlaceholder', 'e.g. New')) + '"></label>' +
					'<label class="dahu-kit-admin__check"><input type="checkbox" data-popt-field="coming_soon"' + (o.coming_soon ? ' checked' : '') + '> ' + esc(L('comingSoon', 'Coming soon')) + '</label>' +
					// “link” card: the card points to a page instead of
					// being a choice — the slug or path of a page of the site (resolved
					// on the current site, staging included), or a complete address.
					'<label>' + esc(L('linkField', 'Link (optional)')) + ' <input type="text" data-popt-field="link" value="' + escAttr(o.link || '') + '" placeholder="' + escAttr(L('linkPlaceholder', 'page slug (contact) or https://…')) + '" title="' + escAttr(L('linkTitle', 'Filled in: the card is no longer a choice, it opens this page.')) + '"></label>' +
				'</div>' +
				'<label class="dahu-kit-admin__full">' + esc(L('displayedDescription', 'Displayed description (simple HTML)')) + ' <textarea rows="2" data-popt-field="display_description">' + esc(o.display_description || '') + '</textarea></label>' +
				// The same picker as the product options: with no product
				// behind it, it is the only source of a photo for the card.
				optionMediaHtml(o) +
				'<p>' +
					'<button type="button" class="button-link" data-popt-move="-1" title="' + escAttr(L('moveUp', 'Move up')) + '">▲</button> ' +
					'<button type="button" class="button-link" data-popt-move="1" title="' + escAttr(L('moveDown', 'Move down')) + '">▼</button> ' +
					'<button type="button" class="button-link delete" data-popt-remove>' + esc(L('deleteThisOption', 'Delete this option')) + '</button>' +
				'</p>' +
			'</div>';
		}).join('');

		return '<div class="dahu-kit-admin__display"><strong>' + esc(L('branchOptions', 'Options of the branch')) + '</strong>' +

			rows +
			'<p><button type="button" class="button" data-popt-add>' + esc(L('addOption', 'Add an option')) + '</button></p></div>';
	}

	function renderComponents() {
		var snap = captureFocus($components);
		$components.empty();
		state.components.forEach(migrateTiers);
		state.components.forEach(migrateDisplayStyle);
		var total = state.components.length;
		state.components.forEach(function (c, index) {
			var bodyId = 'dahu-kit-body-c-' + index;
			var open = isOpen('component', c.id, total);
			var productOptions = isPath(c) ? [] : c.options;
			var optionsHtml = productOptions.map(function (o) {
				return '<option value="' + o.product_id + '" selected>' + esc(productLabel(o.product_id)) + '</option>';
			}).join('');
			var defaultHtml = '<option value="">' + esc(L('optionNone', '— None —')) + '</option>' + productOptions.map(function (o) {
				return '<option value="' + o.product_id + '"' + (c.default_product_id === o.product_id ? ' selected' : '') + '>' + esc(productLabel(o.product_id)) + '</option>';
			}).join('');

			// Everything needed to recognise a component without unfolding it:
			// type, number of options, categories, grouped screen, whether it is
			// optional.
			var badges =
				' <span class="dahu-kit-admin__badge dahu-kit-admin__badge--' + (isPath(c) ? 'path' : 'product') + '">' +
					esc(isPath(c) ? L('badgeBranch', 'Branch') : L('badgeProducts', 'Products')) + '</span>' +
				' <span class="dahu-kit-admin__badge dahu-kit-admin__badge--count">' +
					esc(fmt(L('optionCount', 'Options: %s'), (c.options || []).length)) + '</span>' +
				((c.tiers || []).length ? ' <span class="dahu-kit-admin__badge dahu-kit-admin__badge--tiers">' + esc(fmt(L('rangeCount', 'Ranges: %s'), c.tiers.length)) + '</span>' : '') +
				(c.selection_mode === 'multiple' ? ' <span class="dahu-kit-admin__badge dahu-kit-admin__badge--multiple">' + esc(L('badgeMultiple', 'multiple choice')) + '</span>' : '') +
				(c.optional ? ' <span class="dahu-kit-admin__badge dahu-kit-admin__badge--optional">' + esc(L('badgeOptional', 'optional')) + '</span>' : '') +
				(!isPath(c) && reservedCount(c) ? ' <span class="dahu-kit-admin__badge dahu-kit-admin__badge--reserved">' + esc(fmt(L('restrictedCount', 'Restricted: %s'), reservedCount(c))) + '</span>' : '') +
				(c.display_group ? ' <span class="dahu-kit-admin__badge dahu-kit-admin__badge--group">' + esc(fmt(L('badgeScreen', 'screen: %s'), c.display_group)) + '</span>' : '') +
				' <code>' + esc(c.id) + '</code>';

			var $box = $(
				'<div class="dahu-kit-admin__box" data-index="' + index + '" data-component-box' +
					' data-id="' + escAttr(c.id) + '"' + (open ? '' : ' data-folded') + '>' +
					boxHeadHtml({
						kind: 'component',
						bodyId: bodyId,
						open: open,
						grip: true,
						title: (index + 1) + '. ' + esc(c.title || L('untitled', '(untitled)')),
						badges: badges,
						actions:
							'<button type="button" class="button-link" data-move="-1" title="' + escAttr(L('moveUp', 'Move up')) + '">▲</button> ' +
							'<button type="button" class="button-link" data-move="1" title="' + escAttr(L('moveDown', 'Move down')) + '">▼</button> ' +
							'<button type="button" class="button-link" data-duplicate>' + esc(L('duplicate', 'Duplicate')) + '</button> ' +
							'<button type="button" class="button-link delete" data-remove>' + esc(L('delete', 'Delete')) + '</button>'
					}) +
					'<div class="dahu-kit-admin__box-body" id="' + bodyId + '"' + (open ? '' : ' hidden') + '>' +
					'<div class="dahu-kit-admin__grid">' +
						'<label>' + esc(L('titleField', 'Title')) + ' <input type="text" data-field="title" value="' + escAttr(c.title) + '"></label>' +
						'<label>' + esc(L('componentType', 'Component type')) + ' <select data-field="component_type">' +
							'<option value="product"' + (!isPath(c) ? ' selected' : '') + '>' + esc(L('typeProducts', 'Products')) + '</option>' +
							'<option value="path"' + (isPath(c) ? ' selected' : '') + '>' + esc(L('typeBranch', 'Branch (no product)')) + '</option>' +
						'</select></label>' +
						// Single or multiple choice: products only.
						(isPath(c) ? '' :
							'<label>' + esc(L('choiceField', 'Choice')) + ' <select data-field="selection_mode">' +
								'<option value="radio"' + (c.selection_mode !== 'multiple' ? ' selected' : '') + '>' + esc(L('choiceSingle', 'Single (one option)')) + '</option>' +
								'<option value="multiple"' + (c.selection_mode === 'multiple' ? ' selected' : '') + '>' + esc(L('choiceMultiple', 'Multiple (several options, one row each)')) + '</option>' +
							'</select></label>') +
						// Layout of a multiple-choice component
						// (display_style): set component by component; “Default”
						// follows the general appearance of the kit.
						(isPath(c) || c.selection_mode !== 'multiple' ? '' :
							'<label>' + esc(L('layoutField', 'Layout')) + ' <select data-field="display_style">' +
								DISPLAY_STYLES.map(function (entry) {
									return '<option value="' + entry[0] + '"' + ((c.display_style || '') === entry[0] ? ' selected' : '') + '>' + esc(entry[1]) + '</option>';
								}).join('') +
							'</select></label>') +
						'<label>' + esc(L('displayGroup', 'Display group')) + ' <input type="text" data-field="display_group" value="' + escAttr(c.display_group || '') + '" placeholder="' + escAttr(L('displayGroupPlaceholder', 'same value = same screen')) + '"></label>' +
						// Role of the title in a grouped screen (title_role).
						'<label>' + esc(L('titleRoleField', 'Title on the screen')) + ' <select data-field="title_role">' +
							'<option value="primary"' + (c.title_role !== 'secondary' ? ' selected' : '') + '>' + esc(L('titleRolePrimary', 'Main (in the screen title)')) + '</option>' +
							'<option value="secondary"' + (c.title_role === 'secondary' ? ' selected' : '') + '>' + esc(L('titleRoleSecondary', 'Secondary (add-on, no title)')) + '</option>' +
						'</select></label>' +
						anchorFieldHtml(c) +
						// Presentation of an add-on: a “Select” card, or
						// a yes / no question inside the chosen card.
						(isPath(c) || c.selection_mode === 'multiple' || (c.title_role !== 'secondary' && c.presentation !== 'question') ? '' :
							'<label>' + esc(L('presentationField', 'Add-on presentation')) + ' <select data-field="presentation">' +
								'<option value=""' + (c.presentation !== 'question' ? ' selected' : '') + '>' + esc(L('presentationCard', '“Select” card')) + '</option>' +
								'<option value="question"' + (c.presentation === 'question' ? ' selected' : '') + '>' + esc(L('presentationQuestion', 'Yes / no question')) + '</option>' +
							'</select></label>' +
							(c.presentation === 'question'
								? '<label>' + esc(L('questionField', 'Text of the question')) + ' <input type="text" data-field="question" value="' + escAttr(c.question || '') + '" placeholder="' + escAttr(L('questionPlaceholder', 'Empty: the title of the component')) + '"></label>'
								: '')) +
						// Adjustable quantity: unticked, the customer chooses
						// no quantity (one unit) and the two fields
						// have no reason to be there.
						// Multiple choice: one unit per option, no quantity.
						(isPath(c) || c.selection_mode === 'multiple' ? '' :
							'<label class="dahu-kit-admin__check"><input type="checkbox" data-field="quantity_editable"' + (c.quantity_editable ? ' checked' : '') + '> ' + esc(L('quantityEditable', 'Adjustable quantity')) + '</label>' +
							(c.quantity_editable
								? '<label>' + esc(L('quantityMin', 'Min quantity')) + ' <input type="number" min="0" data-field="quantity_min" value="' + esc(c.quantity_min) + '"></label>' +
									'<label>' + esc(L('quantityMax', 'Max quantity')) + ' <input type="number" min="0" data-field="quantity_max" value="' + esc(c.quantity_max) + '"></label>'
								: '')) +
						'<label class="dahu-kit-admin__check"><input type="checkbox" data-field="optional"' + (c.optional ? ' checked' : '') + '> ' + esc(L('optionalField', 'Optional')) + '</label>' +
						(isPath(c) ? '' :
							'<label class="dahu-kit-admin__check"><input type="checkbox" data-field="shipped_individually"' + (c.shipped_individually ? ' checked' : '') + '> ' + esc(L('shippedIndividually', 'Shipped on its own (otherwise its weight is added to the kit parcel)')) + '</label>') +
						// Displayed weight of the step: the configurator only,
						// never the weight of the cart or of the order.
						(isPath(c) ? '' :
							'<label>' + esc(L('weightModeField', 'Displayed weight')) + ' <select data-field="weight_mode">' +
								[['', L('weightModeProducts', 'Weight of the products')], ['fixed', L('weightModeFixed', 'Fixed value')], ['subtract', L('weightModeSubtract', 'Weight of the products minus…')]].map(function (pair) {
									return '<option value="' + pair[0] + '"' + ((c.weight_mode || '') === pair[0] ? ' selected' : '') + '>' + esc(pair[1]) + '</option>';
								}).join('') +
							'</select></label>' +
							(c.weight_mode
								? '<label>' + esc(L('weightGrams', 'Grams')) + ' <input type="number" min="0" step="1" data-field="weight_grams" value="' + esc(c.weight_grams || 0) + '"></label>'
								: '')) +
					'</div>' +
					(!isPath(c) && (c.title_role === 'secondary' || c.anchor_component_id)
						? '<p class="description">' + esc(L('anchorNote', 'The add-on appears after the anchor card (same screen).')) + '</p>'
						: '') +
					'<label class="dahu-kit-admin__full">' + esc(L('descriptionField', 'Description (HTML allowed)')) + ' <textarea rows="3" data-field="description">' + esc(c.description) + '</textarea></label>' +
					// Info bubble of the screen: an “i” icon next to the
					// step number, and an expandable panel under the header.
					'<label class="dahu-kit-admin__full">' + esc(L('infoBubbleText', 'Info bubble text (optional)')) + ' <textarea rows="3" data-field="info">' + esc(c.info || '') + '</textarea></label>' +
					tierDisplayChecks(c) +
					tiersHtml(c) +
					(isPath(c) ?
						pathOptionsHtml(c) +
						'<label class="dahu-kit-admin__full">' + esc(L('defaultOptionField', 'Default option')) + ' <select data-field="default_option_id"><option value="">' + esc(L('optionNone', '— None —')) + '</option>' +
							c.options.map(function (o) {
								return '<option value="' + escAttr(o.id) + '"' + (c.default_option_id === o.id ? ' selected' : '') + '>' + esc(o.label || o.id) + '</option>';
							}).join('') + '</select></label>'
						:
						'<label class="dahu-kit-admin__full">' + esc(L('optionsProductsField', 'Options (products)')) + ' <select multiple class="wc-product-search" data-field="options" data-action="woocommerce_json_search_products" data-placeholder="' + escAttr(L('searchProduct', 'Search for a product…')) + '" style="width:100%">' + optionsHtml + '</select></label>' +
						'<label class="dahu-kit-admin__full">' + esc(L('defaultSelectionField', 'Default selection')) + ' <select data-field="default_product_id">' + defaultHtml + '</select></label>' +
						displayFieldsHtml(c) +
						variantsFieldsHtml(c) +
						helpFieldsHtml(c) +
						visibilityFieldsHtml(c)) +
					'</div>' +
				'</div>'
			);
			$components.append($box);
		});
		initSelects($components);
		updateBoxCount('dahu-kit-components-box', L('componentsBox', 'Components'), state.components.length);
		restoreFocus($components, snap);
		applyPendingFocus('component');
		serialize();
	}

	/**
	 * Counter in the native title of the box: “Components (8)”.
	 */
	function updateBoxCount(boxId, label, count) {
		var $title = $('#' + boxId).find('.hndle, .postbox-header .hndle').first();
		if (!$title.length) return;
		$title.text(label + ' (' + count + ')');
	}

	/**
	 * Block that has just been added or duplicated: unfolded and focused.
	 */
	function applyPendingFocus(kind) {
		if (!pendingFocus || pendingFocus.kind !== kind) return;
		var $container = kind === 'component' ? $components : $scenarios;
		var $box = $container.children('[data-id="' + pendingFocus.id.replace(/"/g, '\\"') + '"]');
		pendingFocus = null;
		if (!$box.length) return;
		// First input field of the body, failing that the collapse button.
		var el = $box.find('.dahu-kit-admin__box-body').find('input, select, textarea').get(0)
			|| $box.find('.dahu-kit-admin__toggle').get(0);
		if (!el) return;
		try {
			el.focus();
			if (el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
		} catch (e) {}
	}

	/** Opens a box and asks for the focus on it at the next render. */
	function openAndFocus(kind, id) {
		openState[kind][id] = true;
		saveOpenState();
		pendingFocus = { kind: kind, id: id };
	}

	function bindComponents() {
		$components.on('input change', '[data-field]', function (event) {
			var $input = $(this);
			var index = parseInt($input.closest('.dahu-kit-admin__box').data('index'), 10);
			var c = state.components[index];
			var field = $input.data('field');
			if (!c) return;

			if (field === 'options') {
				if (event.type !== 'change') return;
				var ids = ($input.val() || []).map(function (v) { return parseInt(v, 10); });
				// Keeps the display content already entered for the options
				// that stay selected.
				var previous = {};
				c.options.forEach(function (o) { previous[o.product_id] = o; });
				c.options = ids.map(function (id) { return previous[id] || newProductOption(id); });
				if (c.default_product_id && ids.indexOf(c.default_product_id) === -1) c.default_product_id = null;
				fetchMissingInfo().then(function () { renderComponents(); renderScenarios(); });
				return;
			}
			if (field === 'selection_mode') {
				if (event.type !== 'change') return;
				// Multiple: one unit per option chosen, no adjustable
				// quantity; the minimum only says whether it is optional.
				c.selection_mode = $input.val() === 'multiple' ? 'multiple' : 'radio';
				if (c.selection_mode === 'multiple') {
					c.quantity_editable = false;
					c.quantity_min = c.optional ? 0 : 1;
					c.quantity_max = 1;
				}
				renderComponents();
				return;
			}
			if (field === 'component_type') {
				if (event.type !== 'change') return;
				// Changing type empties the options: products and
				// branches have nothing in common.
				c.component_type = $input.val() === 'path' ? 'path' : 'product';
				if (isPath(c)) c.selection_mode = 'radio';
				c.options = [];
				c.default_product_id = null;
				c.default_option_id = null;
				// Anchoring: a branch is not anchored, and the add-ons
				// anchored to this component lose their card (options emptied).
				if (isPath(c)) {
					c.anchor_component_id = null;
					c.anchor_product_id = null;
				}
				state.components.forEach(function (a) {
					if (a.anchor_component_id === c.id) a.anchor_product_id = null;
				});
				state.scenarios.forEach(function (s) {
					if (s.target_component === c.id) s.masked_options = [];
					if (s.conditions && s.conditions[c.id]) s.conditions[c.id].values = [];
				});
				renderComponents();
				renderScenarios();
				return;
			}
			if (field === 'quantity_editable') {
				c.quantity_editable = $input.is(':checked');
				// Unticked: we go back to one unit, and the min/max fields
				// disappear. Ticked: we start again from usable bounds.
				if (!c.quantity_editable) {
					c.quantity_min = c.optional ? 0 : 1;
					c.quantity_max = 1;
				} else if (c.quantity_max <= c.quantity_min) {
					c.quantity_max = c.quantity_min + 1;
				}
				renderComponents();
				return;
			}
			if (field === 'anchor_component_id') {
				if (event.type !== 'change') return;
				// Another anchor component: the option is to be chosen again.
				c.anchor_component_id = $input.val() || null;
				c.anchor_product_id = null;
				renderComponents();
				return;
			}
			if (field === 'anchor_product_id') {
				if (event.type !== 'change') return;
				c.anchor_product_id = parseInt($input.val(), 10) || null;
				serialize();
				return;
			}
			if (field === 'optional' || field === 'shipped_individually' || field === 'tier_newline' || field === 'tier_badge' || field === 'tier_tint') {
				c[field] = $input.is(':checked');
			} else if (field === 'quantity_min' || field === 'quantity_max') {
				c[field] = parseInt($input.val(), 10) || 0;
			} else if (field === 'weight_grams') {
				c.weight_grams = Math.max(0, parseInt($input.val(), 10) || 0);
			} else if (field === 'default_product_id') {
				c.default_product_id = parseInt($input.val(), 10) || null;
			} else if (field === 'default_option_id') {
				c.default_option_id = $input.val() || null;
			} else if (field === 'display_group') {
				c.display_group = slugify($input.val());
			} else {
				c[field] = $input.val();
			}
			serialize();
			if (field === 'title' && event.type === 'change') renderScenarios();
			// Secondary: the help for “Anchor beside” appears.
			if (field === 'title_role' && event.type === 'change') renderComponents();
			// Yes / no question: the text field appears.
			if (field === 'presentation' && event.type === 'change') renderComponents();
			// Displayed weight: the grams field appears.
			if (field === 'weight_mode' && event.type === 'change') renderComponents();
		});

		// Display content per option.
		$components.on('input change', '[data-opt-field]', function () {
			var $input = $(this);
			var index = parseInt($input.closest('.dahu-kit-admin__box').data('index'), 10);
			var optIndex = parseInt($input.closest('.dahu-kit-admin__option').data('option'), 10);
			var option = state.components[index] && state.components[index].options[optIndex];
			if (!option) return;
			if ($input.is(':checkbox')) {
				option[$input.data('opt-field')] = $input.is(':checked');
				serialize();
				return;
			}
			var value = $input.val();
			option[$input.data('opt-field')] = '' === value ? null : value;
			serialize();
		});

		// Public visibility.
		// “toggle” does not bubble: we listen in the capture phase.
		$components[0].addEventListener('toggle', function (event) {
			var details = event.target;
			if (!details.matches || !details.matches('details[data-visibility]')) return;
			var index = parseInt($(details).closest('.dahu-kit-admin__box').data('index'), 10);
			var c = state.components[index];
			if (c) visibilityOpen[c.id] = details.open;
		}, true);
		$components.on('change', '[data-vis-public], [data-vis-var]', function () {
			var $input = $(this);
			var index = parseInt($input.closest('.dahu-kit-admin__box').data('index'), 10);
			var optIndex = parseInt($input.closest('.dahu-kit-admin__option').data('option'), 10);
			var c = state.components[index];
			var option = c && c.options[optIndex];
			if (!option) return;
			visibilityOpen[c.id] = true;
			if ($input.is('[data-vis-public]')) {
				option.is_public = $input.is(':checked');
			} else {
				var vid = parseInt($input.data('vis-var'), 10);
				var list = (option.private_variations || []).map(Number).filter(function (id) { return id !== vid; });
				if (!$input.is(':checked')) list.push(vid);
				option.private_variations = list;
			}
			renderComponents();
			renderExceptions();
		});

		// [data-panel] blocks: open / closed remembered.
		$components[0].addEventListener('toggle', function (event) {
			var details = event.target;
			if (!details.matches || !details.matches('details[data-panel]')) return;
			var c = state.components[parseInt($(details).closest('.dahu-kit-admin__box').data('index'), 10)];
			if (c) panelOpen[c.id + ':' + details.getAttribute('data-panel')] = details.open;
		}, true);

		// Proposed variants: all ticked = [] (all of them,
		// including those created later); the last one cannot be unticked.
		$components.on('change', '[data-allow-var]', function () {
			var $input = $(this);
			var c = state.components[parseInt($input.closest('.dahu-kit-admin__box').data('index'), 10)];
			var $row = $input.closest('.dahu-kit-admin__option');
			var option = c && c.options[parseInt($row.data('option'), 10)];
			if (!option) return;
			var $all = $row.find('[data-allow-var]');
			var checked = $all.filter(':checked').map(function () { return parseInt($(this).data('allow-var'), 10); }).get();
			if (!checked.length) {
				$input.prop('checked', true);
				return;
			}
			option.allowed_variations = checked.length === $all.length ? [] : checked;
			panelOpen[c.id + ':variants'] = true;
			renderComponents();
			renderScenarios();
		});

		// Options of a “path” component.
		$components.on('input change', '[data-popt-field]', function (event) {
			var $input = $(this);
			var index = parseInt($input.closest('.dahu-kit-admin__box').data('index'), 10);
			var optIndex = parseInt($input.closest('.dahu-kit-admin__option').data('option'), 10);
			var c = state.components[index];
			var option = c && c.options[optIndex];
			if (!option) return;
			var field = $input.data('popt-field');

			if (field === 'coming_soon') {
				option.coming_soon = $input.is(':checked');
			} else if (field === 'label') {
				option.label = $input.val();
				if (event.type === 'change' && !option.id) {
					option.id = uniquePathOptionId(c, slugify(option.label) || 'option');
					renderComponents();
					renderScenarios();
					return;
				}
			} else {
				option[field] = '' === $input.val() ? null : $input.val();
			}
			serialize();
		});

		$components.on('click', '[data-popt-add]', function () {
			var index = parseInt($(this).closest('.dahu-kit-admin__box').data('index'), 10);
			var c = state.components[index];
			if (!c) return;
			// Identifier assigned the first time the label is entered (read
			// only afterwards), not an arbitrary “option-N”.
			c.options.push({ id: '', label: '', display_description: null, badge: null, tier: null, coming_soon: false, link: '' });
			renderComponents();
			renderScenarios();
		});

		$components.on('click', '[data-popt-remove]', function () {
			var index = parseInt($(this).closest('.dahu-kit-admin__box').data('index'), 10);
			var optIndex = parseInt($(this).closest('.dahu-kit-admin__option').data('option'), 10);
			var c = state.components[index];
			if (!c || !c.options[optIndex]) return;
			var removed = c.options[optIndex].id;
			c.options.splice(optIndex, 1);
			if (c.default_option_id === removed) c.default_option_id = null;
			renderComponents();
			renderScenarios();
		});

		$components.on('click', '[data-opt-media]', function () {
			var $button = $(this);
			var index = parseInt($button.closest('.dahu-kit-admin__box').data('index'), 10);
			var optIndex = parseInt($button.closest('.dahu-kit-admin__option').data('option'), 10);
			var option = state.components[index] && state.components[index].options[optIndex];
			if (!option || !window.wp || !window.wp.media) return;

			var frame = window.wp.media({
				title: L('mediaOptionImage', 'Image of the option'),
				button: { text: L('mediaUseThisVisual', 'Use this visual') },
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				option.display_image_id = attachment.id;
				mediaUrls[attachment.id] = (attachment.sizes && attachment.sizes.thumbnail)
					? attachment.sizes.thumbnail.url : attachment.url;
				renderComponents();
			});
			frame.open();
		});

		// “Help image” of a product option.
		$components.on('click', '[data-opt-help-media]', function () {
			var $button = $(this);
			var index = parseInt($button.closest('.dahu-kit-admin__box').data('index'), 10);
			var optIndex = parseInt($button.closest('.dahu-kit-admin__option').data('option'), 10);
			var option = state.components[index] && state.components[index].options[optIndex];
			if (!option || !window.wp || !window.wp.media) return;
			var vid = parseInt($button.data('help-var'), 10) || 0;
			panelOpen[state.components[index].id + ':help'] = true;
			var frame = window.wp.media({
				title: L('mediaHelpImage', 'Help image (back of the card)'),
				button: { text: L('mediaUseThisVisual', 'Use this visual') },
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				if (vid) {
					var map = variationHelpImages(option);
					map[vid] = attachment.id;
					option.variation_help_images = map;
				} else {
					option.help_image_id = attachment.id;
				}
				mediaUrls[attachment.id] = (attachment.sizes && attachment.sizes.thumbnail)
					? attachment.sizes.thumbnail.url : attachment.url;
				renderComponents();
			});
			frame.open();
		});

		$components.on('click', '[data-opt-help-media-clear]', function () {
			var $button = $(this);
			var index = parseInt($button.closest('.dahu-kit-admin__box').data('index'), 10);
			var optIndex = parseInt($button.closest('.dahu-kit-admin__option').data('option'), 10);
			var option = state.components[index] && state.components[index].options[optIndex];
			if (!option) return;
			var vid = parseInt($button.data('help-var'), 10) || 0;
			panelOpen[state.components[index].id + ':help'] = true;
			if (vid) {
				var map = variationHelpImages(option);
				delete map[vid];
				option.variation_help_images = map;
			} else {
				option.help_image_id = null;
			}
			renderComponents();
		});

		// Image of the “None” card of the component.
		$components.on('click', '[data-none-media]', function () {
			var c = state.components[parseInt($(this).closest('.dahu-kit-admin__box').data('index'), 10)];
			if (!c || !window.wp || !window.wp.media) return;
			var frame = window.wp.media({
				title: fmt(L('mediaNoneImage', 'Image of the “None” card — %s'), c.title || c.id),
				button: { text: L('mediaUseThisImage', 'Use this image') },
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				c.none_image_id = attachment.id;
				mediaUrls[attachment.id] = (attachment.sizes && attachment.sizes.thumbnail)
					? attachment.sizes.thumbnail.url : attachment.url;
				renderComponents();
			});
			frame.open();
		});

		$components.on('click', '[data-none-media-clear]', function () {
			var c = state.components[parseInt($(this).closest('.dahu-kit-admin__box').data('index'), 10)];
			if (!c) return;
			c.none_image_id = null;
			renderComponents();
		});

		$components.on('click', '[data-opt-media-clear]', function () {
			var $button = $(this);
			var index = parseInt($button.closest('.dahu-kit-admin__box').data('index'), 10);
			var optIndex = parseInt($button.closest('.dahu-kit-admin__option').data('option'), 10);
			var option = state.components[index] && state.components[index].options[optIndex];
			if (!option) return;
			option.display_image_id = null;
			renderComponents();
		});

		// Moving an option within a component (products and
		// branches). The arrows stay usable from the keyboard, which
		// drag and drop alone would not allow.
		$components.on('click', '[data-opt-move], [data-popt-move]', function () {
			var $button = $(this);
			var index = parseInt($button.closest('.dahu-kit-admin__box').data('index'), 10);
			var optIndex = parseInt($button.closest('.dahu-kit-admin__option').data('option'), 10);
			var delta = parseInt($button.data('opt-move') || $button.data('popt-move'), 10);
			var c = state.components[index];
			if (!c || !c.options[optIndex]) return;
			var target = optIndex + delta;
			if (target < 0 || target >= c.options.length) return;
			var moved = c.options.splice(optIndex, 1)[0];
			c.options.splice(target, 0, moved);
			renderComponents();
			renderScenarios();
		});

		$components.on('click', '[data-duplicate]', function () {
			var index = parseInt($(this).closest('.dahu-kit-admin__box').data('index'), 10);
			var source = state.components[index];
			if (!source) return;
			// Deep copy: options and display content included. The
			// scenarios are NOT duplicated — they target the original, and the
			// copying them onto the copy would hide two components instead of one.
			var copy = JSON.parse(JSON.stringify(source));
			copy.id = uniqueComponentId(source.id);
			copy.title = source.title ? fmt(L('copySuffix', '%s (copy)'), source.title) : '';
			state.components.splice(index + 1, 0, copy);
			openAndFocus('component', copy.id);
			renderComponents();
			renderScenarios();
		});

		// Drag and drop of the components: the handle is the header of the block.
		$components.on('dragstart', '.dahu-kit-admin__box-head', function (event) {
			dragIndex = parseInt($(this).closest('.dahu-kit-admin__box').data('index'), 10);
			// During the gesture every body is visually folded: an
			// unfolded block can be several screens tall, and the drop
			// target then becomes impossible to find. Purely CSS, each one's
			// folded state is left untouched.
			$components.addClass('is-reordering');
			var transfer = event.originalEvent.dataTransfer;
			if (transfer) {
				transfer.effectAllowed = 'move';
				// Firefox does not start the drag without data.
				transfer.setData('text/plain', String(dragIndex));
			}
			$(this).closest('.dahu-kit-admin__box').addClass('is-dragging');
		});

		$components.on('dragend', '.dahu-kit-admin__box-head', function () {
			dragIndex = null;
			$components.removeClass('is-reordering');
			$components.find('.is-dragging, .is-drop-target').removeClass('is-dragging is-drop-target');
		});

		$components.on('dragover', '[data-component-box]', function (event) {
			if (dragIndex === null) return;
			event.preventDefault();
			if (event.originalEvent.dataTransfer) event.originalEvent.dataTransfer.dropEffect = 'move';
			$components.find('.is-drop-target').removeClass('is-drop-target');
			$(this).addClass('is-drop-target');
		});

		$components.on('drop', '[data-component-box]', function (event) {
			if (dragIndex === null) return;
			event.preventDefault();
			var target = parseInt($(this).data('index'), 10);
			if (isNaN(target) || target === dragIndex) return;
			var moved = state.components.splice(dragIndex, 1)[0];
			state.components.splice(target, 0, moved);
			dragIndex = null;
			// dragend does not always fire after a drop followed by a
			// re-render: we remove the class here too.
			$components.removeClass('is-reordering');
			renderComponents();
			renderScenarios();
		});

		$components.on('click', '[data-move]', function () {
			var index = parseInt($(this).closest('.dahu-kit-admin__box').data('index'), 10);
			var target = index + parseInt($(this).data('move'), 10);
			if (target < 0 || target >= state.components.length) return;
			var item = state.components.splice(index, 1)[0];
			state.components.splice(target, 0, item);
			renderComponents();
			renderScenarios();
		});

		/* Categories (ranges) -------------------------------------------- */

		var tierContext = function ($el) {
			var c = state.components[parseInt($el.closest('.dahu-kit-admin__box').data('index'), 10)];
			var tierIndex = parseInt($el.closest('.dahu-kit-admin__tier').data('tier'), 10);
			return { component: c, index: tierIndex, tier: c && c.tiers ? c.tiers[tierIndex] : null };
		};

		// “toggle” does not bubble: we listen in the capture phase.
		if ($components[0]) {
			$components[0].addEventListener('toggle', function (event) {
				var panel = event.target;
				if (!panel.matches || !panel.matches('[data-tiers-panel]')) return;
				var c = state.components[parseInt($(panel).closest('.dahu-kit-admin__box').data('index'), 10)];
				if (c) tiersOpen[c.id] = panel.open;
			}, true);
		}

		$components.on('input change', '[data-tier-field]', function (event) {
			var ctx = tierContext($(this));
			if (!ctx.tier) return;
			var field = $(this).data('tier-field');
			var value = $(this).val();
			if (field === 'description') {
				ctx.tier.description = '' === value ? null : value;
				serialize();
				return;
			}
			if (field === 'media_url') {
				ctx.tier.media_url = String(value || '').trim();
				serialize();
				return;
			}
			// Colour of the range: the swatch and the default colours
			// of the other ranges redrawn.
			if (field === 'color') {
				if (event.type !== 'change') return;
				ctx.tier.color = /^#[0-9a-f]{6}$/i.test(value || '') ? String(value).toLowerCase() : '';
				renderComponents();
				return;
			}
			ctx.tier.label = value;
			if (event.type !== 'change') {
				serialize();
				return;
			}
			// Stable id: it follows the label only as long as no option
			// is attached (a brand new category). After that, renaming only
			// changes the label.
			if (!tierUsage(ctx.component, ctx.tier.id) && value) {
				ctx.tier.id = uniqueTierId(ctx.component, slugify(value), ctx.tier);
			}
			renderComponents();
		});

		// Media of the popover of a range: an image or a video.
		$components.on('click', '[data-tier-media]', function () {
			var ctx = tierContext($(this));
			if (!ctx.tier || !window.wp || !window.wp.media) return;
			var frame = window.wp.media({
				title: fmt(L('mediaTierTooltip', 'Tooltip image or video “%s”'), ctx.tier.label || ctx.tier.id),
				button: { text: L('mediaUseThisMedia', 'Use this media') },
				library: { type: ['image', 'video'] },
				multiple: false
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				ctx.tier.media_id = attachment.id;
				if (attachment.type === 'image') {
					mediaUrls[attachment.id] = (attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url;
				} else {
					mediaNames[attachment.id] = attachment.filename || attachment.title || ('#' + attachment.id);
				}
				renderComponents();
			});
			frame.open();
		});

		$components.on('click', '[data-tier-media-clear]', function () {
			var ctx = tierContext($(this));
			if (!ctx.tier) return;
			ctx.tier.media_id = null;
			renderComponents();
		});


		$components.on('click', '[data-tier-add]', function () {
			var c = state.components[parseInt($(this).closest('.dahu-kit-admin__box').data('index'), 10)];
			if (!c) return;
			c.tiers = c.tiers || [];
			// First range: “Badge” ticked automatically.
			if (!c.tiers.length) c.tier_badge = true;
			c.tiers.push({ id: uniqueTierId(c, 'categorie'), label: '', description: null, media_id: null, media_url: '', color: '' });
			tiersOpen[c.id] = true;
			renderComponents();
			var $label = $components.find('.dahu-kit-admin__box[data-index="' + componentIndex(c.id) + '"] [data-tier="' + (c.tiers.length - 1) + '"] [data-tier-field="label"]');
			$label.trigger('focus');
		});

		$components.on('click', '[data-tier-move]', function () {
			var ctx = tierContext($(this));
			if (!ctx.tier) return;
			var target = ctx.index + parseInt($(this).data('tier-move'), 10);
			if (target < 0 || target >= ctx.component.tiers.length) return;
			ctx.component.tiers.splice(target, 0, ctx.component.tiers.splice(ctx.index, 1)[0]);
			renderComponents();
		});

		$components.on('click', '[data-tier-duplicate]', function () {
			var ctx = tierContext($(this));
			if (!ctx.tier) return;
			// Copy of the label, the description, the media and the
			// colour; no option is attached to it.
			var copy = {
				id: uniqueTierId(ctx.component, ctx.tier.id),
				label: ctx.tier.label ? fmt(L('copySuffix', '%s (copy)'), ctx.tier.label) : '',
				description: ctx.tier.description || null,
				media_id: ctx.tier.media_id || null,
				media_url: ctx.tier.media_url || '',
				color: ctx.tier.color || ''
			};
			ctx.component.tiers.splice(ctx.index + 1, 0, copy);
			renderComponents();
		});

		$components.on('click', '[data-tier-remove]', function () {
			var ctx = tierContext($(this));
			if (!ctx.tier) return;
			var used = tierUsage(ctx.component, ctx.tier.id);
			var message = fmt(L('tierDeleteConfirm', 'Delete the category “%s”?'), ctx.tier.label || ctx.tier.id);
			message += used
				? '\n\n' + fmt(plural('tierDeleteAttached', used, '%s option is attached to it: it will go back to having no category.', '%s options are attached to it: they will go back to having no category.'), used)
				: '\n\n' + L('tierDeleteNoOption', 'No option is attached to it.');
			if (!window.confirm(message)) return;
			var removedId = ctx.tier.id;
			ctx.component.tiers.splice(ctx.index, 1);
			ctx.component.options.forEach(function (o) { if (o.tier === removedId) o.tier = null; });
			renderComponents();
		});

		// Drag and drop of the categories: the same mechanism as the components
		// (handle = header), limited to the component it came from.
		$components.on('dragstart', '.dahu-kit-admin__tier-head', function (event) {
			event.stopPropagation();
			var ctx = tierContext($(this));
			if (!ctx.tier) return;
			dragTier = { component: ctx.component, index: ctx.index };
			var transfer = event.originalEvent.dataTransfer;
			if (transfer) {
				transfer.effectAllowed = 'move';
				transfer.setData('text/plain', String(ctx.index));
			}
			$(this).closest('.dahu-kit-admin__tier').addClass('is-dragging');
		});

		$components.on('dragend', '.dahu-kit-admin__tier-head', function () {
			dragTier = null;
			$components.find('.is-dragging, .is-drop-target').removeClass('is-dragging is-drop-target');
		});

		$components.on('dragover', '[data-tier]', function (event) {
			if (!dragTier || tierContext($(this)).component !== dragTier.component) return;
			event.preventDefault();
			event.stopPropagation();
			if (event.originalEvent.dataTransfer) event.originalEvent.dataTransfer.dropEffect = 'move';
			$components.find('.is-drop-target').removeClass('is-drop-target');
			$(this).addClass('is-drop-target');
		});

		$components.on('drop', '[data-tier]', function (event) {
			if (!dragTier) return;
			var ctx = tierContext($(this));
			if (ctx.component !== dragTier.component) return;
			event.preventDefault();
			event.stopPropagation();
			var from = dragTier.index;
			dragTier = null;
			if (isNaN(ctx.index) || ctx.index === from) return;
			ctx.component.tiers.splice(ctx.index, 0, ctx.component.tiers.splice(from, 1)[0]);
			renderComponents();
		});

		$components.on('click', '[data-remove]', function () {
			var index = parseInt($(this).closest('.dahu-kit-admin__box').data('index'), 10);
			var removed = state.components[index];
			if (!removed) return;
			var linked = state.scenarios.filter(function (s) {
				return s.target_component === removed.id || (s.conditions && s.conditions[removed.id]);
			}).length;
			var message = fmt(L('componentDeleteConfirm', 'Delete the component “%s”?'), removed.title || removed.id);
			if (linked) {
				message += '\n\n' + fmt(plural('componentDeleteLinked', linked, '%s scenario refers to it and will be deleted, or have this condition removed.', '%s scenarios refer to it and will be deleted, or have this condition removed.'), linked);
			}
			message += '\n\n' + L('cannotBeUndone', 'This cannot be undone without reloading the page.');
			if (!window.confirm(message)) return;
			state.components.splice(index, 1);
			state.scenarios = state.scenarios.filter(function (s) { return s.target_component !== removed.id; });
			state.scenarios.forEach(function (s) { delete s.conditions[removed.id]; });
			// Add-ons anchored to the deleted component: back to the usual place.
			state.components.forEach(function (a) {
				if (a.anchor_component_id !== removed.id) return;
				a.anchor_component_id = null;
				a.anchor_product_id = null;
			});
			renderComponents();
			renderScenarios();
		});
	}

	/**
	 * Free identifier for a branch option of the component. The server DISCARDS
	 * an option whose identifier is already taken: uniqueness therefore has to
	 * start here.
	 */
	function uniquePathOptionId(component, base) {
		var taken = {};
		component.options.forEach(function (o) { if (o.id) taken[o.id] = true; });
		var id = base;
		var n = 2;
		while (taken[id]) id = base + '-' + n++;
		return id;
	}

	/* ------------------------------------------------------------------ */
	/* Scenarios                                                           */
	/* ------------------------------------------------------------------ */

	function multiSelect(field, choices, selected, extra) {
		return '<select multiple class="dahu-kit-enhanced" data-field="' + field + '"' + (extra || '') + '>' +
			choices.map(function (choice) {
				return '<option value="' + escAttr(choice.value) + '"' + (selected.indexOf(choice.value) !== -1 ? ' selected' : '') + '>' + esc(choice.label) + '</option>';
			}).join('') + '</select>';
	}

	/** Readable label of a condition value (product, variant, option). */
	function valueLabel(component, value) {
		var found = null;
		valueChoices(component, true).forEach(function (choice) {
			if (!found && choice.value === String(value)) found = choice.label;
		});
		// The variant labels are indented for the dropdown list.
		return (found || String(value)).replace(/^\s*↳\s*/, '').trim();
	}

	/**
	 * Header sentence of a scenario, built from the data with the real labels —
	 * never the identifiers, which say nothing when read. It also serves as the
	 * search text.
	 */
	function scenarioSentence(s) {
		var isBlock = s.action === 'block';
		var target = componentIndex(s.target_component) !== -1 ? state.components[componentIndex(s.target_component)] : null;
		var targetName = target ? (target.title || target.id) : (s.target_component || L('targetMissing', '(target missing)'));

		var head;
		if (isBlock) head = L('scenDeadEnd', 'Dead end');
		else if (s.action === 'mask_component') head = fmt(L('scenHidesStep', 'Hides the step %s'), targetName);
		else if (s.action === 'show_options') {
			// “Show only”: the list that is kept, not its complement.
			var kept = (s.shown_options || []).map(function (v) { return valueLabel(target, v); });
			head = fmt(L('scenShowsOnly', 'Shows in %1$s only %2$s'), targetName, kept.length ? '{' + kept.join(', ') + '}' : L('scenNoOptionChosen', '(no option chosen)'));
		} else head = fmt(L('scenHidesOptions', 'Hides options of %s'), targetName);

		var conditions = Object.keys(s.conditions || {}).map(function (sourceId) {
			var idx = componentIndex(sourceId);
			var source = idx !== -1 ? state.components[idx] : null;
			var name = source ? (source.title || source.id) : sourceId;
			var cond = s.conditions[sourceId] || {};
			var values = (cond.values || []).map(function (v) { return valueLabel(source, v); });
			if (!values.length) return fmt(L('scenNoValue', '%s (no value)'), name);
			if (values.length === 1) return name + (cond.mode === 'not_in' ? ' ≠ ' : ' = ') + values[0];
			return name + (cond.mode === 'not_in' ? ' ∉ ' : ' ∈ ') + '{' + values.join(', ') + '}';
		});

		// Customer condition.
		var customer = customerSentence(s.customer);
		if (customer) conditions.push(customer);

		return conditions.length
			? fmt(L('scenIf', '%1$s if %2$s'), head, conditions.join(L('scenAnd', ' and ')))
			: fmt(L('scenAlwaysActive', '%s (always active)'), head);
	}

	function customerName(id) {
		return customerLabels[id] || fmt(L('customerNumber', 'customer #%s'), id);
	}

	/** “customer ∈ {…}” / “customer ∉ {…}” — '' with no customer condition. */
	function customerSentence(customer) {
		if (!customer || !(customer.ids || []).length) return '';
		var names = customer.ids.map(customerName);
		return customer.mode === 'not_in'
			? fmt(L('customerNotIn', 'customer ∉ {%s}'), names.join(', '))
			: fmt(L('customerIn', 'customer ∈ {%s}'), names.join(', '));
	}

	/** Target of the filter: identifier of the component concerned, '' for a dead end. */
	function scenarioTarget(s) {
		return s.action === 'block' ? '' : (s.target_component || '');
	}

	function renderScenarios() {
		var snap = captureFocus($scenarios);
		$scenarios.empty();
		var total = state.scenarios.length;
		state.scenarios.forEach(function (s, index) {
			var bodyId = 'dahu-kit-body-s-' + index;
			var open = isOpen('scenario', s.id, total);
			var sentence = scenarioSentence(s);
			var isBlock = s.action === 'block';
			// A block has no target: every step may serve
			// as a condition.
			var targetIndex = isBlock ? state.components.length : componentIndex(s.target_component);
			var targetOptions = state.components.map(function (c) {
				return '<option value="' + escAttr(c.id) + '"' + (c.id === s.target_component ? ' selected' : '') + '>' + esc(c.title || c.id) + '</option>';
			}).join('');

			var conditionsHtml = state.components.slice(0, Math.max(0, targetIndex)).map(function (source) {
				var cond = s.conditions[source.id];
				return '<div class="dahu-kit-admin__condition" data-source="' + escAttr(source.id) + '">' +
					'<label class="dahu-kit-admin__check"><input type="checkbox" data-field="cond_enabled"' + (cond ? ' checked' : '') + '> ' + esc(L('condOn', 'Condition on')) + ' <strong>' + esc(source.title || source.id) + '</strong></label>' +
					(cond ?
						'<select data-field="cond_mode"><option value="in"' + (cond.mode === 'in' ? ' selected' : '') + '>' + esc(L('condIsOneOf', 'is one of')) + '</option><option value="not_in"' + (cond.mode === 'not_in' ? ' selected' : '') + '>' + esc(L('condIsNoneOf', 'is none of')) + '</option></select>' +
						multiSelect('cond_values', valueChoices(source, true), cond.values || [])
						: '') +
					'</div>';
			}).join('');

			var blockHtml = isBlock
				? '<label class="dahu-kit-admin__full">' + esc(L('blockMessageField', 'Message shown (dead end)')) + ' <textarea rows="3" data-field="message">' + esc(s.message || '') + '</textarea></label>' +
					'<label class="dahu-kit-admin__full">' + esc(L('restartField', 'Resume at step')) + ' <select data-field="restart_component"><option value="">' + esc(L('optionNone', '— None —')) + '</option>' +
						state.components.map(function (c) {
							return '<option value="' + escAttr(c.id) + '"' + (c.id === s.restart_component ? ' selected' : '') + '>' + esc(c.title || c.id) + '</option>';
						}).join('') + '</select></label>'
				: '';

			var maskedHtml = s.action === 'mask_options' && !isBlock && targetIndex !== -1
				? '<label class="dahu-kit-admin__full">' + esc(fmt(L('scenMaskedField', 'Options to hide in “%s”'), state.components[targetIndex].title)) + ' ' +
					multiSelect('masked_options', valueChoices(state.components[targetIndex], false), s.masked_options || []) + '</label>'
				: '';
			// “Show only”: we tick what STAYS visible; the
			// server hides all the rest of the component (variations included),
			// recomputed on every read — an option added later is hidden.
			if (s.action === 'show_options' && targetIndex !== -1) {
				maskedHtml = '<label class="dahu-kit-admin__full">' + esc(fmt(L('scenShownField', 'Options to keep visible in “%s”'), state.components[targetIndex].title)) + ' ' +
					multiSelect('shown_options', valueChoices(state.components[targetIndex], false), s.shown_options || []) + '</label>' +
					'<p class="description">' + esc(L('scenShownHelp', 'The other options are hidden when the conditions are true.')) + '</p>';
			}

			var $box = $(
				'<div class="dahu-kit-admin__box" data-index="' + index + '"' +
					' data-id="' + escAttr(s.id) + '" data-target="' + escAttr(scenarioTarget(s)) + '"' +
					' data-search="' + escAttr(sentence.toLowerCase()) + '"' + (open ? '' : ' data-folded') + '>' +
					boxHeadHtml({
						kind: 'scenario',
						bodyId: bodyId,
						open: open,
						grip: false,
						// Truncated in CSS; the full text stays in the title attribute.
						title: esc(sentence),
						tooltip: sentence,
						badges:
							' <span class="dahu-kit-admin__badge dahu-kit-admin__badge--' + (isBlock ? 'block' : 'mask') + '">' +
								esc(isBlock ? L('badgeDeadEnd', 'Dead end') : (s.action === 'mask_component' ? L('badgeHidesStep', 'Hides the step') : (s.action === 'show_options' ? L('badgeShowsOnly', 'Shows only') : L('badgeHidesOptions', 'Hides options')))) + '</span>' +
							' <code>' + esc(s.id) + '</code>',
						actions:
							'<button type="button" class="button-link" data-duplicate>' + esc(L('duplicate', 'Duplicate')) + '</button> ' +
							'<button type="button" class="button-link delete" data-remove>' + esc(L('delete', 'Delete')) + '</button>'
					}) +
					'<div class="dahu-kit-admin__box-body" id="' + bodyId + '"' + (open ? '' : ' hidden') + '>' +
					'<div class="dahu-kit-admin__grid">' +
						(isBlock ? '' : '<label>' + esc(L('targetComponentField', 'Target component')) + ' <select data-field="target_component"><option value="">—</option>' + targetOptions + '</select></label>') +
						'<label>' + esc(L('actionField', 'Action')) + ' <select data-field="action">' +
							'<option value="mask_options"' + (s.action === 'mask_options' ? ' selected' : '') + '>' + esc(L('actionHideOptions', 'Hide options')) + '</option>' +
								'<option value="show_options"' + (s.action === 'show_options' ? ' selected' : '') + '>' + esc(L('actionShowOnly', 'Show only these options')) + '</option>' +
							'<option value="mask_component"' + (s.action === 'mask_component' ? ' selected' : '') + '>' + esc(L('actionHideStep', 'Hide the whole step')) + '</option>' +
							'<option value="block"' + (isBlock ? ' selected' : '') + '>' + esc(L('actionDeadEnd', 'Dead end (stop the configurator with a message)')) + '</option>' +
						'</select></label>' +
					'</div>' +
					'<div class="dahu-kit-admin__conditions"><p class="description">' + esc(L('condHelp', 'Active when every condition is true.')) + '</p>' +
						(conditionsHtml
							? '<div class="dahu-kit-admin__conditions-list">' + conditionsHtml + '</div>'
							: '<p class="description">' + esc(L('condNone', 'No condition possible: always active.')) + '</p>') + '</div>' +
					(s.customer ? '<p class="description">' + esc(fmt(L('condCustomerLine', 'Customer condition: %s (import JSON).'), customerSentence(s.customer))) + '</p>' : '') +
					blockHtml +
					maskedHtml +
					'</div>' +
				'</div>'
			);
			$scenarios.append($box);
		});
		initSelects($scenarios);
		updateBoxCount('dahu-kit-scenarios-box', L('scenariosBox', 'Scenarios'), state.scenarios.length);
		renderScenarioTargets();
		applyScenarioFilter();
		restoreFocus($scenarios, snap);
		applyPendingFocus('scenario');
		serialize();
	}

	/* ---------------- Searching and filtering the scenarios ----------------
	 *
	 * Purely visual: the filtered blocks stay in the DOM (`hidden`) and `state`
	 * is never touched. The indexes of the blocks therefore stay those of the
	 * full list, and saving sees no difference.
	 */
	var scenarioFilter = { text: '', target: '' };

	function renderScenarioTargets() {
		var $select = $('#dahu-kit-scenario-target');
		if (!$select.length) return;
		var used = {};
		state.scenarios.forEach(function (s) {
			var t = scenarioTarget(s);
			if (t) used[t] = true;
		});
		var html = '<option value="">' + esc(L('filterEveryTarget', 'Every target')) + '</option>';
		state.components.forEach(function (c) {
			if (!used[c.id]) return;
			html += '<option value="' + escAttr(c.id) + '"' + (scenarioFilter.target === c.id ? ' selected' : '') + '>' + esc(c.title || c.id) + '</option>';
		});
		$select.html(html);
	}

	function applyScenarioFilter() {
		var text = scenarioFilter.text;
		var target = scenarioFilter.target;
		var shown = 0;
		$scenarios.children('.dahu-kit-admin__box').each(function () {
			var $box = $(this);
			var ok = (!text || String($box.data('search') || '').indexOf(text) !== -1)
				&& (!target || String($box.data('target') || '') === target);
			$box.prop('hidden', !ok);
			if (ok) shown++;
		});
		var $count = $('#dahu-kit-scenario-count');
		if ($count.length) {
			$count.text(text || target ? shown + ' / ' + state.scenarios.length : '');
		}
	}

	function bindScenarios() {
		$scenarios.on('change', '[data-field]', function () {
			var $input = $(this);
			var index = parseInt($input.closest('.dahu-kit-admin__box').data('index'), 10);
			var s = state.scenarios[index];
			var field = $input.data('field');
			var source = $input.closest('.dahu-kit-admin__condition').data('source');
			if (!s) return;

			switch (field) {
				case 'target_component':
					s.target_component = $input.val();
					var targetIndex = componentIndex(s.target_component);
					Object.keys(s.conditions).forEach(function (cid) {
						if (componentIndex(cid) >= targetIndex) delete s.conditions[cid];
					});
					s.masked_options = [];
					if (s.action === 'show_options') s.shown_options = [];
					renderScenarios();
					return;
				case 'action':
					s.action = $input.val();
					if (s.action !== 'mask_options') s.masked_options = [];
					if (s.action === 'show_options') s.shown_options = s.shown_options || [];
					else delete s.shown_options;
					if (s.action === 'block') s.target_component = '';
					renderScenarios();
					return;
				case 'message':
					s.message = $input.val();
					break;
				case 'restart_component':
					s.restart_component = $input.val();
					break;
				case 'cond_enabled':
					if ($input.is(':checked')) s.conditions[source] = { mode: 'in', values: [] };
					else delete s.conditions[source];
					renderScenarios();
					return;
				case 'cond_mode':
					s.conditions[source].mode = $input.val();
					break;
				case 'cond_values':
					s.conditions[source].values = $input.val() || [];
					break;
				case 'masked_options':
					s.masked_options = $input.val() || [];
					break;
				case 'shown_options':
					s.shown_options = $input.val() || [];
					// The sentence quotes the list that is kept: title, tooltip and
					// search updated in place (a new render
					// would close the list being typed in).
					var sentence = scenarioSentence(s);
					var $box = $input.closest('.dahu-kit-admin__box');
					$box.find('.dahu-kit-admin__box-title').first().text(sentence).attr('title', sentence);
					$box.attr('data-search', sentence.toLowerCase());
					break;
			}
			serialize();
		});

		$scenarios.on('click', '[data-remove]', function () {
			var index = parseInt($(this).closest('.dahu-kit-admin__box').data('index'), 10);
			var s = state.scenarios[index];
			if (!s) return;
			if (!window.confirm(fmt(L('scenarioDeleteConfirm', 'Delete the scenario “%s”?'), s.id) + '\n\n' + L('cannotBeUndone', 'This cannot be undone without reloading the page.'))) return;
			state.scenarios.splice(index, 1);
			renderScenarios();
		});

		$scenarios.on('click', '[data-duplicate]', function () {
			var index = parseInt($(this).closest('.dahu-kit-admin__box').data('index'), 10);
			var source = state.scenarios[index];
			if (!source) return;
			var copy = JSON.parse(JSON.stringify(source));
			copy.id = source.id + '-copy-' + (state.scenarios.length + 1);
			state.scenarios.splice(index + 1, 0, copy);
			openAndFocus('scenario', copy.id);
			renderScenarios();
		});
	}

	/* ------------------------------------------------------------------ */
	/* Simulateur                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Tests the configuration being shown, saved or not.
	 *
	 * NO rule is replayed here: hiding, validity, prices and weight come from
	 * /state, called in “configure online” mode (the config of the form is sent as
	 * it is and the server normalises it before running the usual engine).
	 *
	 * It is EXACTLY the route the public front end calls, including for the
	 * clearing cascade — so the simulator cannot diverge from what the customer
	 * will see. There is still only one implementation of evaluate(), and only
	 * one of the clearing that follows it.
	 */
	var sim = {
		// Simulated customer: 0 = visitor / customer with no exception.
		asCustomer: 0,
		selections: {},
		validation: null,
		quote: null,
		busy: false,
		requestId: 0,
		error: null
	};

	function simApi(path, body) {
		return window.fetch(cfg.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.restNonce },
			body: JSON.stringify(body)
		}).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (data) {
				if (!response.ok) throw new Error((data && data.message) || fmt(L('httpError', 'Error %s'), response.status));
				return data;
			});
		});
	}

	function simPayload() {
		return {
			product_id: cfg.productId || 0,
			selections: sim.selections,
			quantity: 1,
			config: { components: state.components, scenarios: state.scenarios, customer_exceptions: state.customer_exceptions },
			// Always sent: without it the server would take the user
			// logged in (the administrator), who may have an exception.
			as_customer: sim.asCustomer || 0
		};
	}

	/**
	 * A single call. /state returns the corrected selections, the validity, the
	 * hiding, the price and the weight all at once: sim.validation and sim.quote
	 * therefore point at the same response.
	 *
	 * The clearing of the hidden selections (simPrune) has gone from here: it is
	 * done by the PHP engine, as for the front end.
	 */
	/** Is the box of the simulator folded (or hidden)? */
	function simulatorClosed() {
		var $box = $('#dahu-kit-simulator-box');
		return $box.length ? $box.hasClass('closed') : false;
	}

	// A change came in while the simulator was folded:
	// the computation is deferred until it is opened.
	var simDirty = false;

	function simRefresh() {
		if (!$simulator || !$simulator.length) return;
		// Folded: no call to the server. This is the costliest part of
		// the screen (one POST /state per change, after 400 ms), and it has
		// no point as long as nobody is looking at the result.
		if (simulatorClosed()) {
			simDirty = true;
			return;
		}
		simDirty = false;
		var id = ++sim.requestId;
		sim.busy = true;
		sim.error = null;
		renderSimulator();

		simApi('state', simPayload()).then(function (result) {
			if (id !== sim.requestId) return;
			sim.selections = result.selections || {};
			sim.validation = result;
			sim.quote = result;
			sim.busy = false;
			renderSimulator();
			renderGenerated(result.generated_scenarios);
		}).catch(function (error) {
			if (id !== sim.requestId) return;
			sim.busy = false;
			sim.error = error.message;
			renderSimulator();
		});
	}

	/** Local component matching a component returned by the server. */
	function simLocalComponent(id, index) {
		var byId = componentIndex(id);
		if (byId !== -1) return state.components[byId];
		return state.components[index] || null;
	}

	function simOptionLabel(component, option) {
		return isPath(component) ? (option.label || option.id) : productLabel(option.product_id);
	}

	function simOptionKey(component, option) {
		return isPath(component) ? String(option.id) : String(option.product_id);
	}

	function renderWarnings(list) {
		if (!$warnings || !$warnings.length) return;
		$warnings.empty();
		var count = (list || []).length;
		// The whole box disappears when there is nothing to report: an
		// empty box would take up room to say “nothing”.
		var $box = $('#dahu-kit-warnings-box');
		$box.toggle(count > 0).removeClass('closed');
		updateBoxCount('dahu-kit-warnings-box', L('warningsBox', 'Warnings'), count);
		if (!count) {
			$warnings.attr('hidden', 'hidden');
			return;
		}
		$warnings.removeAttr('hidden');
		var $box = $('<div class="notice notice-warning inline"><p><strong>' + esc(L('warningsTitle', 'Worth checking')) + '</strong></p><ul></ul></div>');
		list.forEach(function (message) {
			$box.find('ul').append($('<li>').text(message));
		});
		$warnings.append($box);
	}

	/**
	 * Appearance of the product being edited: the buttons of the Appearance box
	 * (even unsaved), else the saved value.
	 */
	/**
	 * Settings of the Appearance box (Dahu_Composite_Appearance::SETTINGS): key →
	 * attribute of the container, default value. The same table as the server; the
	 * simulator carries the same attributes as the front end.
	 */
	var SIM_APPEARANCE = {
		card_style: { attribute: 'data-dahu-kit-card-style', fallback: 'vignette' },
		card_corners: { attribute: 'data-dahu-kit-card-corners', fallback: 'square' },
		icons: { attribute: 'data-dahu-kit-icons', fallback: 'on' },
		info_desktop: { attribute: 'data-dahu-kit-info-desktop', fallback: 'card' },
		info_mobile: { attribute: 'data-dahu-kit-info-mobile', fallback: 'lateral' },
		click: { attribute: 'data-dahu-kit-click', fallback: 'on' }
	};

	function simAppearance() {
		var saved = cfg.appearance || {};
		var out = {};
		Object.keys(SIM_APPEARANCE).forEach(function (key) {
			out[key] = $('input[name="dahu_kit_' + key + '"]:checked').val() || saved[key] || SIM_APPEARANCE[key].fallback;
		});
		return out;
	}

	var SIM_INFO_LABELS = {
		lateral: L('appearanceInfoSide', 'side'),
		card: L('appearanceInfoCard', 'card'),
		extended: L('appearanceInfoExtended', 'extended')
	};

	/** Readable sentence of the appearance that is set. */
	function simAppearanceText(a) {
		return fmt(
			L('appearanceSentence', 'Appearance on the product page: %1$s, corners %2$s, icons %3$s, info %4$s (desktop) / %5$s (mobile), click %6$s.'),
			a.card_style === 'card' ? L('appearanceCard', 'Card') : L('appearanceThumbnail', 'Thumbnail'),
			a.card_corners === 'rounded' ? L('appearanceRounded', 'rounded') : L('appearanceSquare', 'square'),
			a.icons === 'on' ? L('yes', 'yes') : L('no', 'no'),
			SIM_INFO_LABELS[a.info_desktop],
			SIM_INFO_LABELS[a.info_mobile],
			a.click === 'on' ? L('yes', 'yes') : L('no', 'no')
		);
	}

	function renderSimulator() {
		if (!$simulator || !$simulator.length) return;
		$simulator.empty().toggleClass('is-busy', sim.busy);

		// The simulator tests the logic (hiding, prices, weight), not the
		// rendering: it shows neither cards nor columns. It carries and states
		// the appearance that is set, the same attributes as the container of the front end.
		var appearance = simAppearance();
		Object.keys(SIM_APPEARANCE).forEach(function (key) {
			$simulator.attr(SIM_APPEARANCE[key].attribute, appearance[key]);
		});
		$simulator.append($('<p class="dahu-kit-admin__sim-appearance description">').text(simAppearanceText(appearance)));
		$simulator.append(simCustomerPicker());

		if (sim.error) {
			$simulator.append($('<p class="dahu-kit-admin__sim-error">').text(sim.error));
			return;
		}
		if (!sim.validation) {
			$simulator.append($('<p class="description">').text(L('simComputing', 'Computing…')));
			return;
		}

		renderWarnings(sim.validation.config_warnings);

		var serverComponents = sim.validation.components || [];
		var hidden = sim.validation.hidden_components || [];
		var hiddenOptions = sim.validation.hidden_options || {};
		var missing = sim.validation.missing || [];
		var errors = sim.validation.errors || [];

		// Dead ends (a “block” scenario): the journey stops, as on the front end.
		(sim.validation.blocked || []).forEach(function (block) {
			var $block = $('<div class="dahu-kit-admin__sim-block notice notice-error inline">');
			$block.append($('<div>').html(block.message || esc(L('simDeadEnd', 'Dead end.'))));
			if (block.restart_component) {
				$block.append($('<p class="description">').text(fmt(L('simRestartAt', 'Resume offered at step: %s'), block.restart_component)));
			}
			$simulator.append($block);
		});

		// Grouping per screen, exactly like the front end: consecutive
		// components sharing the same display_group.
		var screens = [];
		var last = null;
		serverComponents.forEach(function (component, index) {
			if (hidden.indexOf(component.id) !== -1) return;
			var group = component.display_group || '';
			if (group && last && last.group === group) {
				last.items.push({ component: component, index: index });
				return;
			}
			last = { group: group, items: [{ component: component, index: index }] };
			screens.push(last);
		});

		if (!screens.length) {
			$simulator.append($('<p class="description">').text(L('simNoStepVisible', 'No step is visible with these choices.')));
		}

		// Journey shown to the customer (summary + “Step X / Y”), the same
		// logic as the front end (displaySteps()): real screens joined
		// with the projection of the server (display_hidden_components), where every
		// component not chosen takes its representative value. The simulator
		// has no current step: only the selections count as
		// decided.
		var projected = sim.validation.display_hidden_components;
		if (projected) {
			var shown = [];
			var lastShown = null;
			serverComponents.forEach(function (component) {
				if (hidden.indexOf(component.id) !== -1 && projected.indexOf(component.id) !== -1) return;
				var group = component.display_group || '';
				if (group && lastShown && lastShown.group === group) {
					lastShown.items.push(component);
					return;
				}
				lastShown = { group: group, items: [component] };
				shown.push(lastShown);
			});
			var names = shown.map(function (screen) {
				return screen.items.filter(function (c) { return c.title_role !== 'secondary'; })
					.map(function (c) { return c.title || c.id; }).join(' + ') || screen.items[0].title;
			}).concat([L('simSummaryStep', 'Summary')]);
			$simulator.append($('<p class="dahu-kit-admin__sim-path description">').text(
				fmt(plural('simPath', names.length, 'Journey shown to the customer (%1$s step): %2$s', 'Journey shown to the customer (%1$s steps): %2$s'), names.length, names.join(' › '))
			));
		}

		screens.forEach(function (screen, screenIndex) {
			var $screen = $('<div class="dahu-kit-admin__sim-screen">');
			$screen.append($('<h4>').text(fmt(
				L('simScreenTitle', 'Screen %1$s — %2$s'),
				screenIndex + 1,
				// The same rule as the front end (stepTitle()): “primary” titles
				// only.
				screen.items.filter(function (i) { return i.component.title_role !== 'secondary'; })
					.map(function (i) { return i.component.title || i.component.id; }).join(' + ')
			)));
			// Anchored add-ons: rendered right after their anchor card, in
			// the list of the anchor component, like the front end.
			var anchoring = simAnchors(screen, hiddenOptions);
			screen.items.forEach(function (item) {
				if (anchoring.placed[item.component.id]) return;
				$screen.append(renderSimComponent(item.component, item.index, hiddenOptions, errors, missing, anchoring.byHost[item.component.id]));
			});
			$simulator.append($screen);
		});

		var $footer = $('<div class="dahu-kit-admin__sim-footer">');
		$footer.append(simSerial(sim.validation.composition_id || '', !!sim.validation.valid));
		if (sim.quote) {
			$footer.append($('<span class="dahu-kit-admin__sim-total">').html(esc(L('simTotal', 'Total:')) + ' ' + (sim.quote.price.html || '—')));
			if (sim.quote.weight && sim.quote.weight.unit) {
				$footer.append($('<span>').text(fmt(L('simWeight', 'Estimated weight: %1$s %2$s'), sim.quote.weight.total, sim.quote.weight.unit)));
			}
			$footer.append($('<span class="dahu-kit-admin__sim-state">')
				.addClass(sim.validation.valid ? 'is-valid' : 'is-invalid')
				.text(sim.validation.valid ? L('simValid', 'Configuration complete and valid') : L('simIncomplete', 'Configuration incomplete')));
		}
		$footer.append($('<button type="button" class="button">').text(L('simReset', 'Reset')).on('click', function () {
			sim.selections = {};
			simRefresh();
		}));
		$simulator.append($footer);
	}

	/**
	 * Configuration serial of the choices in force: computed by the server on
	 * every /state (composition_id), even for an incomplete configuration —
	 * whatever is not chosen yet reads as “0” there. Identifier: a read-only
	 * <code> badge, a “Copy” button, and an “Open on the site” link (published kit)
	 * which reopens the configurator on those choices. The link is read against
	 * the SAVED configuration of the kit: with components added, removed or moved
	 * without saving, it does not match yet.
	 *
	 * @param {string}  serial Serial, '' with no choice at all.
	 * @param {boolean} valid  Configuration complete and valid.
	 * @return {jQuery}
	 */
	function simSerial(serial, valid) {
		var $row = $('<div class="dahu-kit-admin__sim-serial">');
		$row.append($('<span class="dahu-kit-admin__sim-serial-label">').text(
			serial && !valid
				? L('simSerialPartialLabel', 'Configuration serial (partial configuration):')
				: L('simSerialLabel', 'Configuration serial:')
		));
		if (!serial) {
			$row.append($('<span class="description">').text(L('simSerialNone', 'no serial as long as no choice is made.')));
			return $row;
		}
		$row.append($('<code class="dahu-kit-admin__sim-serial-code">').text(serial));
		var $copy = $('<button type="button" class="button button-small">').text(L('copy', 'Copy'));
		$copy.on('click', function () {
			copyText(serial).then(function () {
				$copy.text(L('copied', 'Copied'));
			}, function () {
				$copy.text(L('copyFailed', 'Copy failed'));
			}).then(function () {
				window.setTimeout(function () { $copy.text(L('copy', 'Copy')); }, 1500);
			});
		});
		$row.append($copy);
		if (cfg.kitUrl) {
			var url = cfg.kitUrl + (cfg.kitUrl.indexOf('?') === -1 ? '?' : '&')
				+ encodeURIComponent(cfg.serialParam || 'dahu_kit_serial') + '=' + encodeURIComponent(serial);
			$row.append($('<a class="button button-small" target="_blank" rel="noopener noreferrer">')
				.attr('href', url)
				.attr('title', L('simOpenTitle', 'Reopens the configurator on these choices (the kit’s saved configuration)'))
				.text(L('simOpenLink', 'Open on the site')));
		} else {
			$row.append($('<span class="description">').text(L('simOpenHelp', 'An “Open on the site” link once the kit is published.')));
		}
		return $row;
	}

	/**
	 * Copy to the clipboard: the Clipboard API, failing that (an admin page
	 * outside HTTPS, API refused) selecting a temporary field.
	 *
	 * @param {string} text Text.
	 * @return {Promise}
	 */
	function copyText(text) {
		if (window.navigator.clipboard && window.isSecureContext) {
			return window.navigator.clipboard.writeText(text).catch(function () {
				return copyBySelection(text);
			});
		}
		return copyBySelection(text);
	}

	function copyBySelection(text) {
		return new Promise(function (resolve, reject) {
			var field = document.createElement('textarea');
			field.value = text;
			field.setAttribute('readonly', '');
			field.style.position = 'fixed';
			field.style.opacity = '0';
			document.body.appendChild(field);
			field.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
			document.body.removeChild(field);
			if (ok) resolve(); else reject(new Error('copy'));
		});
	}

	/**
	 * “View as”: a visitor, or one of the customers of the exceptions. The
	 * rendering (hiding) is that of the customer; the PRICES stay those of the
	 * logged-in account (the price tier is not simulated).
	 */
	function simCustomerPicker() {
		var ids = [];
		state.customer_exceptions.forEach(function (e) {
			if (e.customer_id && ids.indexOf(Number(e.customer_id)) === -1) ids.push(Number(e.customer_id));
		});
		var $p = $('<p class="dahu-kit-admin__sim-customer">');
		var $select = $('<select>').append($('<option value="0">').text(L('simViewVisitor', 'Visitor / customer with no exception')));
		ids.forEach(function (id) {
			$select.append($('<option>').attr('value', id).prop('selected', Number(sim.asCustomer) === id).text(customerName(id)));
		});
		$select.on('change', function () {
			sim.asCustomer = parseInt($(this).val(), 10) || 0;
			simRefresh();
		});
		$p.append($('<label>').text(L('simViewAs', 'View as:') + ' ').append($select));
		if (sim.asCustomer) $p.append($('<span class="description">').text(' ' + L('simViewAsHelp', '(this customer’s hidden options; prices of the logged-in account)')));
		return $p;
	}

	/* ------------------------------------------------------------------ */
	/* Customer exceptions                                                 */
	/* ------------------------------------------------------------------ */

	/** Restricted values of a component, unlockable through an exception. */
	function reservedChoices(component) {
		var choices = [];
		if (!component || isPath(component)) return choices;
		component.options.forEach(function (o) {
			var info = productInfo[o.product_id];
			var vars = info ? info.variations : [];
			var isPublic = o.is_public !== false;
			var reserved = (o.private_variations || []).map(Number);
			if (!isPublic) {
				choices.push({ value: String(o.product_id), label: productLabel(o.product_id) + (vars.length ? ' — ' + L('everyVariantOfIt', 'every one of its variants') : '') });
			}
			vars.forEach(function (v) {
				if (!isPublic || reserved.indexOf(v.id) !== -1) {
					choices.push({ value: o.product_id + ':' + v.id, label: (info ? info.name : '#' + o.product_id) + ' — ' + v.name + ' (#' + v.id + ')' });
				}
			});
		});
		return choices;
	}

	function exceptionSentence(e) {
		var idx = componentIndex(e.component_id);
		var component = idx !== -1 ? state.components[idx] : null;
		var name = component ? (component.title || component.id) : (e.component_id || L('unknownComponent', '(component?)'));
		var who = e.customer_id ? customerName(e.customer_id) : L('customerToChoose', '(customer to be chosen)');
		if (e.access === 'list') {
			var n = (e.values || []).length;
			return fmt(
				plural(
					'excSentenceList',
					n,
					'%1$s — %2$s: %3$s reserved value on top of the public ones',
					'%1$s — %2$s: %3$s reserved values on top of the public ones'
				),
				who, name, n
			);
		}
		return fmt(L('excSentenceAll', '%1$s — %2$s: every restricted option and variation'), who, name);
	}

	function renderExceptions() {
		if (!$exceptions || !$exceptions.length) return;
		var snap = captureFocus($exceptions);
		$exceptions.empty();
		var productComponents = state.components.filter(function (c) { return !isPath(c); });
		state.customer_exceptions.forEach(function (e, index) {
			var idx = componentIndex(e.component_id);
			var component = idx !== -1 ? state.components[idx] : null;
			var reserved = reservedChoices(component);
			var componentOptions = '<option value="">—</option>' + productComponents.map(function (c) {
				var n = reservedCount(c);
				return '<option value="' + escAttr(c.id) + '"' + (c.id === e.component_id ? ' selected' : '') + '>' +
					esc((c.title || c.id) + ' (' + c.id + ')' + ' — ' + (n
					? fmt(plural('reservedValues', n, '%s reserved value', '%s reserved values'), n)
					: L('nothingRestricted', 'nothing restricted'))) + '</option>';
			}).join('');
			var $box = $(
				'<div class="dahu-kit-admin__box" data-index="' + index + '">' +
					'<div class="dahu-kit-admin__box-head"><span class="dahu-kit-admin__box-title">' + esc(exceptionSentence(e)) + '</span>' +
						'<span class="dahu-kit-admin__actions"><button type="button" class="button-link delete" data-exc-remove>' + esc(L('delete', 'Delete')) + '</button></span></div>' +
					'<div class="dahu-kit-admin__box-body">' +
					'<div class="dahu-kit-admin__grid">' +
						'<label>' + esc(L('customerField', 'Customer')) + ' <select class="wc-customer-search" data-exc-field="customer_id" data-placeholder="' + escAttr(L('searchCustomer', 'Search for a customer (name, e-mail)…')) + '" data-allow_clear="false" style="width:100%">' +
							(e.customer_id ? '<option value="' + escAttr(e.customer_id) + '" selected>' + esc(customerName(e.customer_id)) + '</option>' : '') +
						'</select></label>' +
						'<label>' + esc(L('componentField', 'Component')) + ' <select data-exc-field="component_id">' + componentOptions + '</select></label>' +
						'<label>' + esc(L('noteField', 'Note (internal)')) + ' <input type="text" data-exc-field="note" value="' + escAttr(e.note || '') + '" placeholder="' + escAttr(L('notePlaceholder', 'e.g. loyal customer, agreement of 12/09')) + '"></label>' +
					'</div>' +
					'<fieldset class="dahu-kit-admin__exc-access">' +
						'<label class="dahu-kit-admin__check"><input type="radio" name="dahu-exc-access-' + index + '" data-exc-field="access" value="all"' + (e.access !== 'list' ? ' checked' : '') + '> ' + esc(L('excAccessAll', 'Access to every restricted option and variation of this component')) + '</label>' +
						'<label class="dahu-kit-admin__check"><input type="radio" name="dahu-exc-access-' + index + '" data-exc-field="access" value="list"' + (e.access === 'list' ? ' checked' : '') + '> ' + esc(L('excAccessList', 'Only these, on top of the public ones:')) + '</label>' +
					'</fieldset>' +
					(e.access === 'list'
						? (reserved.length
							? '<label class="dahu-kit-admin__full">' + multiSelect('exc_values', reserved, (e.values || []).map(String)) + '</label>'
							: '<p class="description">' + esc(L('excNothingRestricted', 'Nothing is restricted on this component.')) + '</p>')
						: '') +
					'</div>' +
				'</div>'
			);
			$exceptions.append($box);
		});
		if (!state.customer_exceptions.length) {
			$exceptions.append($('<p class="description">').text(L('excNone', 'No exception.')));
		}
		initSelects($exceptions);
		updateBoxCount('dahu-kit-exceptions-box', L('exceptionsBox', 'Customer exceptions'), state.customer_exceptions.length);
		restoreFocus($exceptions, snap);
		serialize();
	}

	/**
	 * Rules generated by the server (public visibility + exceptions), read again
	 * on every computation of the simulator: that is what the engine evaluates.
	 */
	function renderGenerated(list) {
		if (!$generated || !$generated.length || !list) return;
		$generated.empty();
		if (!list.length) {
			$generated.append($('<p class="description">').text(L('generatedNone', 'Generated rules: none (nothing is restricted).')));
			return;
		}
		var $ul = $('<ul>');
		list.forEach(function (g) {
			var idx = componentIndex(g.target_component);
			var component = idx !== -1 ? state.components[idx] : null;
			var values = (g.masked_options || []).map(function (v) { return valueLabel(component, v); });
			var who = g.customer
				? (g.customer.mode === 'not_in'
					? fmt(L('generatedExceptFor', 'for everyone, except %s'), g.customer.ids.map(customerName).join(', '))
					: fmt(L('generatedOnlyFor', 'for %s only'), g.customer.ids.map(customerName).join(', ')))
				: L('generatedForEveryone', 'for everyone');
			$ul.append($('<li>').append($('<code>').text(g.id)).append(document.createTextNode(fmt(
				plural(
					'generatedLine',
					values.length,
					' — hides %1$s value of “%2$s” %3$s: %4$s',
					' — hides %1$s values of “%2$s” %3$s: %4$s'
				),
				values.length,
				component ? component.title || component.id : g.target_component,
				who,
				values.join(', ')
			))));
		});
		$generated.append($('<p>').append($('<strong>').text(L('generatedTitle', 'Generated rules (read only)')))).append($ul);
	}

	/**
	 * Anchors of a screen of the simulator: the same rule as the front end
	 * (DahuCompositeTiers.anchors() and isAddon(), dahu-composite-tiers.js), on
	 * the components as the server has normalised them, with their options. An
	 * add-on is placed when its anchor card is shown (a component of the screen,
	 * an option that is not hidden); otherwise it keeps its place, as on the front
	 * end.
	 *
	 * @return {{byHost: Object, placed: Object}} byHost[hostId][productId] =
	 *         screen items; placed[id] = add-on placed.
	 */
	function simAnchors(screen, hiddenOptions) {
		var shaped = screen.items.map(function (item) {
			var local = simLocalComponent(item.component.id, item.index);
			return $.extend({}, item.component, { options: local ? local.options : [], item: item });
		});
		var byHost = {};
		var placed = {};
		window.DahuCompositeTiers.anchors(shaped).forEach(function (entry) {
			var host = null;
			shaped.forEach(function (s) { if (s.id === entry.hostId) host = s; });
			if (!host || host === entry.component || isPath(host) || window.DahuCompositeTiers.isAddon(host)) return;
			// Chosen card: according to the choices of the simulator.
			var productId = entry.productId || window.DahuCompositeTiers.selectedProductId(sim.selections, host.id);
			if (!productId) return;
			var hidden = hiddenOptions[host.id] || [];
			var shown = host.options.some(function (o) {
				return parseInt(o.product_id, 10) === productId && hidden.indexOf(simOptionKey(host, o)) === -1;
			});
			if (!shown) return;
			byHost[host.id] = byHost[host.id] || {};
			(byHost[host.id][productId] = byHost[host.id][productId] || []).push(entry.component.item);
			placed[entry.component.id] = true;
		});
		return { byHost: byHost, placed: placed };
	}

	/**
	 * @param {Object} anchored Add-ons anchored to this component, per product
	 *                          (simAnchors()): rendered right after the choice
	 *                          of that product.
	 */
	function renderSimComponent(component, index, hiddenOptions, errors, missing, anchored) {
		var local = simLocalComponent(component.id, index);
		var $block = $('<div class="dahu-kit-admin__sim-component">');
		$block.append($('<p class="dahu-kit-admin__sim-label">')
			.text(component.title || component.id)
			.append($('<span class="dahu-kit-admin__badge dahu-kit-admin__badge--' + (component.component_type === 'path' ? 'path' : 'product') + '">')
				.text(component.component_type === 'path' ? L('badgeBranch', 'Branch') : L('badgeProducts', 'Products'))));
		// Description of the component, like the front end (renderComponentBlock()):
		// under its label, above its choices. HTML returned by the
		// server, already filtered (wp_kses_post). Empty: nothing.
		if (component.description) {
			$block.append($('<div class="dahu-kit-admin__sim-description dahu-kit__step-description">').html(component.description));
		}

		if (!local) {
			$block.append($('<p class="description">').text(L('simOptionsUnavailable', 'Options unavailable (component renamed?).')));
			return $block;
		}

		var list = hiddenOptions[component.id] || [];
		var options = local.options.filter(function (o) {
			return list.indexOf(simOptionKey(local, o)) === -1;
		});
		var selection = sim.selections[component.id];
		// Multiple choice: a list of items, with checkboxes.
		var multiple = !isPath(local) && local.selection_mode === 'multiple';
		var chosenItems = multiple ? (Array.isArray(selection) ? selection : []) : [];
		var chosenItem = function (productId) {
			var found = null;
			chosenItems.forEach(function (item) { if (item.product_id === productId) found = item; });
			return found;
		};

		if (!options.length) {
			$block.append($('<p class="dahu-kit-admin__sim-empty">').text(L('simNoOptionAvailable', 'No option available with these choices.')));
		}

		var $list = $('<div class="dahu-kit-admin__sim-options">');
		var name = 'dahu-sim-' + component.id;

		if (component.optional) {
			$list.append(simChoice(name, L('none', 'None'), multiple ? !chosenItems.length : !selection, function () {
				delete sim.selections[component.id];
				simRefresh();
			}, null, multiple ? 'radio-none' : null));
		}

		// Sections per category: the same implementation as the front end
		// (dahu-composite-tiers.js), on the options that are actually visible.
		window.DahuCompositeTiers.sections(local, options).forEach(function (section) {
			if (section.tier) {
				$list.append($('<p class="dahu-kit-admin__sim-tier">').append($('<strong>').text(section.tier.label || section.tier.id)));
				if (section.tier.description) {
					$list.append($('<div class="dahu-kit-admin__sim-tier-description description">').html(section.tier.description));
				}
			}
			section.options.forEach(function (option) {
				var checked = multiple ? !!chosenItem(option.product_id) : !!selection && (isPath(local)
					? selection.option_id === option.id
					: selection.product_id === option.product_id);
				// Image specific to the kit (display_image_id), the same field for
				// both kinds: a branch shows the photo of its card.
				var thumb = option.display_image_id ? mediaUrls[option.display_image_id] : null;
				$list.append(simChoice(name, simOptionLabel(local, option), checked, function () {
					if (multiple) {
						var rest = chosenItems.filter(function (item) { return item.product_id !== option.product_id; });
						if (!checked) rest.push({ product_id: option.product_id, quantity: 1 });
						if (rest.length) sim.selections[component.id] = rest;
						else delete sim.selections[component.id];
					} else {
						sim.selections[component.id] = isPath(local)
							? { option_id: option.id }
							: { product_id: option.product_id, quantity: local.quantity_min || 1 };
					}
					simRefresh();
				}, thumb, multiple ? 'checkbox' : null));
				((anchored || {})[option.product_id] || []).forEach(function (item) {
					$list.append(renderSimComponent(item.component, item.index, hiddenOptions, errors, missing)
						.addClass('dahu-kit-admin__sim-component--anchored'));
				});
			});
		});
		$block.append($list);

		// Multiple choice: one variant list per option chosen.
		chosenItems.forEach(function (item) {
			var itemInfo = productInfo[item.product_id];
			if (!itemInfo || !itemInfo.variations.length) return;
			var $itemSelect = $('<select>').append($('<option value="">').text(fmt(L('simVariantOf', '— Variant of %s —'), productLabel(item.product_id))));
			itemInfo.variations.forEach(function (v) {
				if (list.indexOf(item.product_id + ':' + v.id) !== -1) return;
				$itemSelect.append($('<option>').attr('value', v.id).prop('selected', item.variation_id === v.id).text(v.name + ' (#' + v.id + ')'));
			});
			$itemSelect.on('change', function () {
				var value = parseInt($(this).val(), 10);
				if (value) item.variation_id = value;
				else delete item.variation_id;
				simRefresh();
			});
			$block.append($('<p>').append($itemSelect));
		});

		// Variations: the same list as the front end, filtered by the hiding.
		if (!multiple && selection && !isPath(local) && selection.product_id) {
			var info = productInfo[selection.product_id];
			if (info && info.variations.length) {
				var $select = $('<select>').append($('<option value="">').text(L('simChooseVariant', '— Choose a variant —')));
				info.variations.forEach(function (v) {
					if (list.indexOf(selection.product_id + ':' + v.id) !== -1) return;
					$select.append($('<option>').attr('value', v.id).prop('selected', selection.variation_id === v.id).text(v.name + ' (#' + v.id + ')'));
				});
				$select.on('change', function () {
					var value = parseInt($(this).val(), 10);
					if (value) sim.selections[component.id].variation_id = value;
					else delete sim.selections[component.id].variation_id;
					simRefresh();
				});
				$block.append($('<p>').append($select));
			}
		}

		var own = errors.filter(function (e) { return e.component === component.id; });
		if (own.length) {
			var $errors = $('<ul class="dahu-kit-admin__sim-errors">');
			own.forEach(function (e) { $errors.append($('<li>').text(e.message)); });
			$block.append($errors);
		}
		if (missing.indexOf(component.id) !== -1) {
			$block.append($('<p class="dahu-kit-admin__sim-missing">').text(L('simStepToFill', 'Step to fill in.')));
		}

		return $block;
	}

	/**
	 * @param {string|null} [thumb] Thumbnail of the image specific to the kit,
	 *                              when there is one (product or branch options).
	 */
	/**
	 * @param {string|null} [kind] 'checkbox' (option of a multiple choice),
	 *                             'radio-none' (the “None” of a multiple
	 *                             choice: a standalone button), else radio.
	 */
	function simChoice(name, label, checked, onChange, thumb, kind) {
		var $input = kind === 'checkbox'
			? $('<input type="checkbox">').prop('checked', checked).on('change', onChange)
			: $('<input type="radio">').attr('name', kind === 'radio-none' ? name + '-none' : name).prop('checked', checked).on('change', onChange);
		var $choice = $('<label class="dahu-kit-admin__sim-choice">').append($input);
		if (thumb) $choice.append($('<img class="dahu-kit-admin__sim-thumb" alt="">').attr('src', thumb));
		return $choice.append($('<span>').text(label));
	}

	/* ------------------------------------------------------------------ */

	function toggleVisibility() {
		var isKit = $('#product-type').val() === cfg.productType;
		// All five boxes carry the same class (postbox_classes_product_*).
		$('.dahu-kit-postbox').toggle(isKit);
		// The warnings box only shows when there are any.
		if (isKit) renderWarnings((sim.validation && sim.validation.config_warnings) || []);
		// No price of its own for a kit: it is computed from the components.
		if (isKit) $('.options_group.pricing').hide();
	}

	$(function () {
		$components = $('#dahu-kit-components');
		$scenarios = $('#dahu-kit-scenarios');
		$json = $('#dahu-kit-config-json');
		$warnings = $('#dahu-kit-warnings');
		$simulator = $('#dahu-kit-simulator');
		$exceptions = $('#dahu-kit-exceptions');
		$generated = $('#dahu-kit-generated');
		if (!$components.length) return;

		loadOpenState();

		// A box folded by the user: WordPress fires this
		// event after setting or removing the “closed” class. We use it
		// to catch up on the computation of the simulator that was set aside.
		$(document).on('postbox-toggled', function () {
			if (simDirty && !simulatorClosed()) simRefresh();
		});

		// Appearance: no server computation, the simulator just redraws itself.
		$(document).on('change', 'input[name^="dahu_kit_"][type="radio"]', function () {
			if (sim.validation) renderSimulator();
		});

		state.scenarios.forEach(function (s) {
			if (!s.conditions || Array.isArray(s.conditions)) s.conditions = {};
			if (!s.masked_options) s.masked_options = [];
		});

		bindComponents();
		bindScenarios();
		renderComponents();
		renderScenarios();
		renderExceptions();

		/* ---------------- Exceptions clients (0.36.0) ---------------- */

		$('#dahu-kit-add-exception').on('click', function () {
			var first = state.components.filter(function (c) { return !isPath(c) && reservedCount(c); })[0];
			state.customer_exceptions.push({ customer_id: 0, component_id: first ? first.id : '', access: 'all', values: [], note: '' });
			renderExceptions();
		});
		$exceptions.on('click', '[data-exc-remove]', function () {
			var index = parseInt($(this).closest('.dahu-kit-admin__box').data('index'), 10);
			state.customer_exceptions.splice(index, 1);
			renderExceptions();
		});
		$exceptions.on('input change', '[data-exc-field], [data-field="exc_values"]', function (event) {
			var $input = $(this);
			var index = parseInt($input.closest('.dahu-kit-admin__box').data('index'), 10);
			var e = state.customer_exceptions[index];
			if (!e) return;
			if ($input.is('[data-field="exc_values"]')) {
				e.values = $input.val() || [];
				serialize();
				return;
			}
			var field = $input.data('exc-field');
			if (field === 'customer_id') {
				e.customer_id = parseInt($input.val(), 10) || 0;
				var label = $input.find('option:selected').text();
				if (e.customer_id && label) customerLabels[e.customer_id] = label;
				renderExceptions();
				return;
			}
			if (field === 'note') {
				e.note = $input.val();
				serialize();
				return;
			}
			if (event.type !== 'change') return;
			if (field === 'component_id') { e.component_id = $input.val(); e.values = []; }
			if (field === 'access') { e.access = $input.val(); if (e.access !== 'list') e.values = []; }
			renderExceptions();
		});

		$('#dahu-kit-add-component').on('click', function () {
			state.components.push({
				id: uniqueComponentId('composant'),
				component_type: 'product',
				display_group: '',
				title_role: 'primary',
				title: '',
				description: '',
				info: '',
				selection_mode: 'radio',
				quantity_min: 1,
				quantity_max: 1,
				quantity_editable: false,
				default_product_id: null,
				default_option_id: null,
				tiers: [],
				// Ranges: “Badge” ticked by default (with no effect as long
				// as there is no range).
				tier_badge: true,
				options: [],
				shipped_individually: false,
				optional: false
			});
			openAndFocus('component', state.components[state.components.length - 1].id);
			renderComponents();
			renderScenarios();
		});

		$('#dahu-kit-add-scenario').on('click', function () {
			state.scenarios.push({
				id: 'scenario-' + Date.now(),
				action: 'mask_options',
				target_component: state.components.length ? state.components[state.components.length - 1].id : '',
				conditions: {},
				masked_options: []
			});
			openAndFocus('scenario', state.scenarios[state.scenarios.length - 1].id);
			renderScenarios();
		});

		/* ---------------- Folding ---------------- */

		// A click on the header folds or unfolds, wherever one clicks — except on the
		// actions and the handle, which are beside the button, not inside it.
		$('#dahu-kit-components, #dahu-kit-scenarios').on('click', '.dahu-kit-admin__box-head', function (event) {
			if ($(event.target).closest('.dahu-kit-admin__actions, .dahu-kit-admin__grip').length) return;
			var $box = $(this).closest('.dahu-kit-admin__box');
			var $toggle = $(this).find('.dahu-kit-admin__toggle');
			var kind = $toggle.data('fold');
			var id = String($box.data('id'));
			var open = $toggle.attr('aria-expanded') !== 'true';
			setOpen(kind, id, open);
			$toggle.attr('aria-expanded', open ? 'true' : 'false');
			$box.attr('data-folded', open ? null : '');
			$('#' + $toggle.attr('aria-controls')).prop('hidden', !open);
		});

		$('.dahu-kit-admin').on('click', '[data-fold-all], [data-unfold-all]', function () {
			var $button = $(this);
			var open = $button.is('[data-unfold-all]');
			var section = $button.attr(open ? 'data-unfold-all' : 'data-fold-all');
			foldAll(section === 'scenarios' ? 'scenario' : 'component', open);
		});

		/* ---------------- Searching and filtering the scenarios ---------------- */

		$('#dahu-kit-scenario-search').on('input', function () {
			scenarioFilter.text = String($(this).val() || '').toLowerCase().trim();
			applyScenarioFilter();
		});

		$(document).on('change', '#dahu-kit-scenario-target', function () {
			scenarioFilter.target = String($(this).val() || '');
			applyScenarioFilter();
		});

		$('#dahu-kit-import-button').on('click', function () {
			var data;
			try {
				data = JSON.parse($('#dahu-kit-import').val());
			} catch (e) {
				window.alert(fmt(L('importInvalidJson', 'Invalid JSON: %s'), e.message));
				return;
			}
			if (!data || !Array.isArray(data.components)) {
				window.alert(L('importNoComponents', 'Invalid JSON: the “components” key is missing.'));
				return;
			}
			// Visuals, titles, descriptions and badges: an
			// import MERGES these fields instead of replacing them. A field empty
			// in the JSON (key absent, null or empty — what the export of an
			// unfilled field writes, and therefore any fixture) keeps the value already
			// set on the same component or the same option. Without this,
			// reimporting a fixture wiped what had been entered in the
			// admin (the images of a branch step). A value that is present
			// in the JSON wins; to empty a field: in the admin.
			var previous = state.components;
			var kept = importedFieldFallbacks(previous, data.components);
			state.components = data.components;
			// Older format (free labels in “tier”): categories
			// created as the server would.
			state.components.forEach(migrateTiers);
			// Ranges: bubble text and media kept.
			importedTierFallbacks(previous, state.components, kept);
			state.components.forEach(migrateDisplayStyle);
			state.scenarios = Array.isArray(data.scenarios) ? data.scenarios : [];
			state.scenarios.forEach(function (s) {
				if (!s.conditions || Array.isArray(s.conditions)) s.conditions = {};
				if (!s.masked_options) s.masked_options = [];
			});
			// Customer exceptions: a JSON that does not carry them (an export
			// from before they existed, a configuration from another site) leaves
			// the ones already set in place.
			if (Array.isArray(data.customer_exceptions)) state.customer_exceptions = data.customer_exceptions;
			fetchMissingInfo().always(function () {
				renderComponents();
				renderScenarios();
				renderExceptions();
				window.alert(L('importDone', 'Configuration imported: save the product to apply it.') + importKeptSentence(kept));
			});
		});

		/** Export JSON: the format “Import” expects. */
		function exportData() {
			return { version: 1, components: state.components, scenarios: state.scenarios, customer_exceptions: state.customer_exceptions };
		}

		$('#dahu-kit-export-button').on('click', function () {
			$('#dahu-kit-import').val(JSON.stringify(exportData(), null, 2)).trigger('focus').trigger('select');
		});

		// “Export”: the same JSON, downloaded as a file
		// (kit-<id>-<title>-<date>.json), to carry it over into a fixture.
		// The configuration being SHOWN, saved or not. Nothing goes through the
		// serveur.
		$('#dahu-kit-download-button').on('click', function () {
			var json = JSON.stringify(exportData(), null, 2) + '\n';
			var postId = $('#post_ID').val() || 'nouveau';
			var title = slugify($('#title').val() || '');
			var now = new Date();
			var pad = function (n) { return (n < 10 ? '0' : '') + n; };
			var date = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
			var name = ['kit', postId, title, date].filter(Boolean).join('-') + '.json';
			var url = window.URL.createObjectURL(new window.Blob([json], { type: 'application/json' }));
			var link = document.createElement('a');
			link.href = url;
			link.download = name;
			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);
			window.setTimeout(function () { window.URL.revokeObjectURL(url); }, 1000);
		});

		$('#product-type').on('change', toggleVisibility);
		toggleVisibility();

		// First computation: state of the configuration as it is loaded.
		simRefresh();
	});
})(jQuery);
