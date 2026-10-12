/**
 * DAHU COMPOSITE — step-by-step configurator (a client of the REST API).
 *
 * This script computes NOTHING: structure (GET /schema), hiding and validity
 * (POST /validate), prices and weight (POST /quote) and adding to the cart
 * (POST /add-to-cart) all come from the server. It only displays the state it
 * is given and clears the selections that have become hidden.
 *
 * No dependency. When jQuery is present, the standard WooCommerce
 * "added_to_cart" event is fired with the fragments that came back.
 */
(function () {
	'use strict';

	// The server prints the configuration as an inline JSON node because block
	// themes never write the wp_localize_script() block, which left every label
	// "undefined". A global set by someone else still wins.
	if (!window.DahuCompositeConfig) {
		try {
			var cfgNode = document.getElementById('dahu-composite-config');
			if (cfgNode) window.DahuCompositeConfig = JSON.parse(cfgNode.textContent);
		} catch (e) {}
	}
	var cfg = window.DahuCompositeConfig || {};
	var t = cfg.i18n || {};

	/* ------------------------------------------------------------------ */
	/* Utilitaires                                                         */
	/* ------------------------------------------------------------------ */

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (key) {
				var value = attrs[key];
				if (value === null || value === undefined || value === false) return;
				if (key === 'className') node.className = value;
				else if (key === 'text') node.textContent = value;
				else if (key === 'html') node.innerHTML = value; // HTML produced by the server only.
				else if (key.indexOf('on') === 0) node.addEventListener(key.slice(2), value);
				else node.setAttribute(key, value === true ? '' : value);
			});
		}
		(children || []).forEach(function (child) {
			if (child) node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});
		return node;
	}

	function api(method, path, body) {
		return fetch(cfg.restUrl + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (data) {
				if (!response.ok) {
					var error = new Error((data && data.message) || t.networkError);
					error.data = data;
					throw error;
				}
				return data;
			});
		});
	}

	/**
	 * Detailed content of a product (GET /product-tabs/{id}), cached per product
	 * for the whole page: the right-hand column (Specifications tab) and the
	 * “More info” drawer share the same response. A failure is not kept: the
	 * next call tries again.
	 */
	var productTabsCache = {};
	function productTabs(productId) {
		if (!productTabsCache[productId]) {
			productTabsCache[productId] = api('GET', 'product-tabs/' + productId).catch(function (error) {
				delete productTabsCache[productId];
				throw error;
			});
		}
		return productTabsCache[productId];
	}

	/** Tab of a productTabs() response, or null. */
	function findTab(data, slug) {
		var found = null;
		((data && data.tabs) || []).forEach(function (tab) { if (tab.slug === slug) found = tab; });
		return found;
	}

	/**
	 * Icons of the bar of the right-hand column. Static SVG (no data injected),
	 * stroke in currentColor: the colour follows the button.
	 */
	var INFO_ICON_OPEN = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
	var INFO_ICONS = {
		// Feuille de texte.
		description: INFO_ICON_OPEN + '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/></svg>',
		// Setting sliders.
		caracteristiques: INFO_ICON_OPEN + '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg>',
		// Spanner.
		fournis: INFO_ICON_OPEN + '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>',
		// Circled “i”: “More info” in “vignette” display.
		info: INFO_ICON_OPEN + '<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 7.5h.01"/></svg>'
	};

	/**
	 * Info bubble (step, range): a circled “i”, and a close cross. Stroke and
	 * fill in currentColor, no colour; size 1em (the theme changes it in CSS).
	 */
	var HINT_ICON = '<svg class="dahu-kit__hint-icon" viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true" focusable="false">'
		+ '<circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/>'
		+ '<circle cx="12" cy="7.5" r="1.5" fill="currentColor"/>'
		+ '<path d="M12 11v6.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>';
	/**
	 * “?” of a range: a circle and a question mark, the same stroke family as
	 * the “i” (currentColor, no colour).
	 */
	var HELP_ICON = '<svg class="dahu-kit__tier-help-icon" viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true" focusable="false">'
		+ '<circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/>'
		+ '<path d="M9.2 9.3a2.9 2.9 0 0 1 5.6 1c0 1.9-2.8 2.4-2.8 4.2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
		+ '<circle cx="12" cy="17.6" r="1.25" fill="currentColor"/></svg>';
	var HINT_CLOSE_ICON = '<svg class="dahu-kit__hint-close-icon" viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true" focusable="false">'
		+ '<path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

	/** Non-empty HTML text (tags and spaces stripped)? An image alone counts. */
	function hasText(html) {
		var value = String(html || '');
		if (/<img\b/i.test(value)) return true;
		return value.replace(/<[^>]*>/g, '').replace(/&nbsp;|&#160;|\s/g, '') !== '';
	}

	/** Accessible name of an “i”: “Information about: <title>”. */
	function hintLabel(title) {
		return (t.hintAbout || 'Information about: %s').replace('%s', title || '');
	}

	function isHidden(productId, variationId, hiddenList) {
		if (!hiddenList) return false;
		if (hiddenList.indexOf(String(productId)) !== -1) return true;
		return !!variationId && hiddenList.indexOf(productId + ':' + variationId) !== -1;
	}

	function formatWeight(value, unit) {
		return (Math.round(value * 1000) / 1000).toString().replace('.', ',') + ' ' + unit;
	}

	/**
	 * “Customer” mode of the Pro / Customer switch of dahu-pricing (a class set
	 * on <body> by dahu-pricing-toggle.js), for a customer who has a
	 * professional tier. The price HTML bends to it through CSS; the text of an
	 * <option> cannot, so it is computed here.
	 *
	 * @param {Object} schema Schema of the kit (price_tier: the current tier, or null).
	 * @return {boolean}
	 */
	function clientPriceMode(schema) {
		return !!(schema && schema.price_tier) && document.body.classList.contains('dahu-price-mode-client');
	}

	/**
	 * Label of a variant in a dropdown list: “name — price”.
	 *
	 * The tier price (price) in Pro mode; in “Customer” mode, the price the card
	 * then shows: the public price (regular_price) when it is above the tier
	 * price, else the tier price — the same rule as price_html() on the server.
	 * Earlier, the list always showed the Pro price, even with the switch
	 * disabled.
	 *
	 * @param {Object} v      Variant of the schema ({ name, price, regular_price }).
	 * @param {Object} schema Schema of the kit.
	 * @return {string}
	 */
	function variationOptionText(v, schema) {
		var price = v.price;
		if (clientPriceMode(schema) && Number(v.regular_price) > Number(v.price) + 0.005) price = v.regular_price;
		return v.name + ' — ' + String(price).replace('.', ',') + ' ' + schema.currency_symbol;
	}

	/* ------------------------------------------------------------------ */
	/* Configurateur                                                       */
	/* ------------------------------------------------------------------ */

	function Configurator(root) {
		this.root = root;
		this.productId = parseInt(root.getAttribute('data-product-id'), 10);
		this.schema = null;
		this.selections = {};
		this.validation = { hidden_components: [], hidden_options: {}, errors: [], missing: [], valid: false };
		this.quote = null;
		this.step = 0;
		// Furthest screen reached in this configuration. It bounds
		// navigation through the summary: going back and then forward again
		// must not lose access to what had already been passed,
		// but nothing must allow jumping over a screen
		// never reached.
		this.maxStep = 0;
		// DISPLAY projection of the journey (server:
		// display_hidden_components): components hidden if every component
		// not decided yet took its representative value. Used by the
		// summary and the step counter only (displaySteps());
		// null = no projection, we fall back on the real journey.
		this.displayHidden = null;
		// Components left at “None” by a gesture of the customer: decided,
		// so the projection lends them no value (decidedComponents()).
		this.declined = {};
		// Yes / no questions where the customer CLICKED “Yes”: that gesture
		// alone marks “Yes” as an answer given when the option has
		// variants (choosing a variant in the list does not do it).
		this.answeredYes = {};
		this.quantity = 1;
		this.requestId = 0;
		this.busy = false;
		this.message = null;
		// Mobile summary banner: collapsed by default.
		this.recapOpen = false;
		// Has the customer made a choice yet? A safety net for the automatic
		// advance: it must never fire on load, for instance
		// on a configuration whose default values would be enough.
		this.touched = false;
		this.autoAdvanceTimer = null;
		// Component whose product the right-hand column shows: the last
		// clicked (see setFocus() and focusedOption()).
		this.focusComponent = null;
		// Right-hand column: content shown under the name of the product,
		// 'description' (short description) or 'caracteristiques'. Falls back to
		// the description when the product being shown changes (infoProduct).
		this.infoTab = 'description';
		this.infoProduct = null;
		// Specifications per product: { status: 'loading' | 'ok' | 'error', tab }.
		this.infoTabsState = {};
		// Persistent nodes, set on the first render (see render()).
		this.layoutNode = null;
		this.headingNode = null;
		// Title of the step when it sits in the product title row
		// (see render()); null otherwise, when it stays at the top of the panel.
		this.stepHeadingNode = null;
		this.recapNode = null;
		this.infoNode = null;
		this.panelNode = null;
		// “Vignette” display mode (see readMode()), read again on every render.
		this.vignettes = false;
		// Selection through a “Select” button (see readSelectMode()), read again
		// on every render.
		this.selectButton = false;
		// Button mode: option whose card the customer clicked WITHOUT
		// selecting it, shown in the right-hand column (a preview).
		// { cid, key } ou null.
		this.preview = null;
		// Product screen being shown: its key (see stepKey()). The same key on the
		// next render = the component slots are updated IN PLACE
		// (patchComponentStep()), with no rebuilding of the panel.
		this.panelKey = null;
		// Persistent “add-on” blocks, per component (see addonBlock()).
		this.addonNodes = {};
		// Display of “More info” (see readInfoMode()), read again on every
		// rendu.
		this.infoMode = 'lateral';
		// “Icons” toggle (see readIconsMode()), read again on every render.
		this.icons = false;
		// Cards whose info is open (a LIST, in the order
		// of opening — MAX_INFO_OPEN flipped cards at most, a single
		// extended column):
		// { key, face: description | specs | bundled | question, display: card |
		//   extended, anchor: right | left | shift, width, shift }. Survives the
		// renders. Read per card: infoFor(key).
		this.infoOpen = [];
		// Questions already presented (automatic opening of the back, once
		// par pose — autoOpenQuestions()).
		this.questionAsked = {};
		// Mobile: question component shown in the drawer, or null
		// (openQuestionDrawer(), syncQuestionDrawer()).
		this.questionDrawer = null;
		// Expanded rows (“Inline” layout), per card.
		this.rowOpen = {};
		// Info bubble (step, range): a single persistent panel under
		// the header (see createHint()). hint = { key, step } when it is
		// open: key “step” or “tier:<component>:<range>”, step = the screen
		// that opened it (hintStepId()). Survives the renders of the same screen.
		this.hint = null;
		this.hintNode = null;
		this.hintBody = null;
		this.hintStep = null;
		this.hintComponents = [];
		this.hintStepName = '';
		this.hintStepButton = null;
		this.hintFadeTimer = null;
		// The customer's last gesture was a choice (a click on a card, “None”) →
		// advance as soon as the screen is complete. Consumed by maybeAutoAdvance().
		this.advanceRequested = false;
		// Saved configuration reopened: differences reported by the
		// server, per component ({ cid: [messages] }), cleared as soon as the
		// customer touches that component again; notice = { name, general } at the
		// top of the Summary.
		this.restoreIssues = {};
		this.restoreNotice = null;
		// Saving from the Summary: name entered, submission under way, result.
		this.saveName = '';
		this.saving = false;
		this.saveMessage = null;
		// Visitor: account creation form of the save
		// block — unfolded or not, values entered (per field key),
		// consent checkbox, honeypot field, errors per field, and outcome:
		// { type: 'created', … } or { type: 'exists', text }. Kept here: the
		// Summary is rebuilt on every render.
		this.guestOpen = false;
		this.guestValues = {};
		this.guestConsent = false;
		this.guestTrap = '';
		this.guestErrors = {};
		this.guestResult = null;
	}

	/**
	 * Card style set on the product (the “Appearance” box of the back office,
	 * Dahu_Composite_Appearance): the attribute
	 * data-dahu-kit-card-style="vignette | card", set by the server on the
	 * container of the configurator. Fixed when the page is rendered: no button
	 * and no storage on the visitor's side.
	 *
	 * By default: three columns; every option is a square card of fixed size
	 * (name, price, variant), clickable as a whole.
	 *
	 * Vignettes: no right-hand column; every option is a vignette (photo,
	 * subtitle, “More info”), clickable as a whole.
	 *
	 * In both modes there is no “Previous” and no “Next” (except for a multiple
	 * choice, see pendingMultiple()): clicking a card moves things forward, and
	 * the summary on the left serves as navigation.
	 */
	function readMode(root) {
		return root.getAttribute('data-dahu-kit-card-style') === 'vignette';
	}

	/**
	 * Display settings of the product (the “Appearance” box): set by the server
	 * on the container, like the card style. No theme toggle any more, and no
	 * reading of <body> or of the visitor's storage.
	 *
	 *  - data-dahu-kit-click        = on | off  → readSelectMode()
	 *  - data-dahu-kit-icons        = on | off  → readIconsMode()
	 *  - data-dahu-kit-info-desktop = lateral | card | extended
	 *  - data-dahu-kit-info-mobile  = lateral | card | extended → readInfoMode()
	 *
	 * Container with no attribute (a page rendered by an earlier version): the
	 * default values of the Appearance box.
	 */
	var APPEARANCE_DEFAULTS = { click: 'on', icons: 'on', 'info-desktop': 'card', 'info-mobile': 'lateral' };

	function readSetting(root, name) {
		var value = root.getAttribute('data-dahu-kit-' + name);
		return value === null ? APPEARANCE_DEFAULTS[name] : value;
	}

	/**
	 * Desktop / mobile threshold: the one of the whole layout of the
	 * configurator (below 64em the summary becomes a banner — functional
	 * stylesheet and theme). On the client side, not wp_is_mobile(): the page is
	 * cached.
	 */
	var MOBILE_QUERY = '(max-width: 63.999em)';

	function isMobile() {
		return !!(window.matchMedia && window.matchMedia(MOBILE_QUERY).matches);
	}

	/**
	 * Choice gesture on the exclusive-choice cards: the “Click” setting of the
	 * product, data-dahu-kit-click on the container (on by default).
	 *
	 * “off”: the whole card selects.
	 *
	 * “on”: every card has its own “Select” button (.dahu-kit__choose); a click
	 * elsewhere on a square card shows it in the right-hand column
	 * (showPreview()) without selecting it. Vignette card and “None”: no
	 * preview.
	 *
	 * The “add-on” component (addonBlock()) is affected in neither case: it
	 * keeps its “Add” / “Added” button.
	 */
	function readSelectMode(root) {
		return readSetting(root, 'click') === 'on';
	}

	/**
	 * Display of “More info” (on the cards that have one: vignettes, product
	 * options and “add-on” components). Two settings of the product, desktop and
	 * mobile (data-dahu-kit-info-desktop / -mobile on the container); which one
	 * applies depends on the width (MOBILE_QUERY), watched by watchMode().
	 *
	 *  - “lateral” (including an unknown value): the side drawer (Drawer),
	 *    unchanged;
	 *  - “card”: the card flips over, and the back shows the short description,
	 *    the specifications or the products supplied;
	 *  - “extended”: the card widens to three cards, in overlay, with an info
	 *    column on the right; falls back to “card” when space runs short
	 *    (extendedFit()).
	 *
	 * “vignette” is no longer a display: it has become the INDEPENDENT “Icons”
	 * toggle (readIconsMode()). An older value “vignette” is read as “card”.
	 *
	 * @return {string} lateral | card | extended
	 */
	function readInfoMode(root) {
		var value = readSetting(root, isMobile() ? 'info-mobile' : 'info-desktop');
		if (value === 'vignette') return 'card';
		return value === 'card' || value === 'extended' ? value : 'lateral';
	}

	/**
	 * “Icons” setting of the product, independent of the display (side, card,
	 * extended) and of “Click”: data-dahu-kit-icons="on" on the container. See
	 * renderInfoControls().
	 *
	 *  - on: the footer of the cards has ONLY three icons — description (“i”),
	 *    specifications, products supplied — and no “More info”. In side mode,
	 *    every icon opens the drawer on its own tab. Back of a card: “Back”
	 *    instead of “Select”;
	 *  - off: “More info” alone, no icon. The open panel (back of a card,
	 *    extended column, drawer) offers the other sections as tabs, at the level
	 *    of the title (fillInfo()).
	 *
	 * @return {boolean}
	 */
	function readIconsMode(root) {
		return readSetting(root, 'icons') === 'on';
	}

	/**
	 * A new render when the effective mode changes AFTER the load: crossing the
	 * desktop / mobile threshold (the “More info” display changes setting), or
	 * an attribute of the container modified by a script. Nothing else:
	 * selections, step and quote are kept. The card style stays fixed as of when
	 * the page was rendered (readMode()).
	 */
	Configurator.prototype.watchMode = function () {
		var self = this;
		var root = this.root;
		function check() {
			if (readSelectMode(root) === self.selectButton && readInfoMode(root) === self.infoMode && readIconsMode(root) === self.icons) return;
			Drawer.close(false);
			self.preview = null;
			self.infoOpen = [];
			self.render();
		}
		if (window.matchMedia) {
			var query = window.matchMedia(MOBILE_QUERY);
			if (query.addEventListener) query.addEventListener('change', check);
			else if (query.addListener) query.addListener(check);
		}
		if (window.MutationObserver) {
			new MutationObserver(check).observe(root, { attributes: true, attributeFilter: ['data-dahu-kit-click', 'data-dahu-kit-icons', 'data-dahu-kit-info-desktop', 'data-dahu-kit-info-mobile'] });
		}
	};

	/**
	 * Starting state dropped into the page by the server (see
	 * Dahu_Composite_Frontend::print_boot_state). Absent when the injection has
	 * been disabled through a filter, or when the JSON is unreadable: we then go
	 * through the REST calls, slower but still correct.
	 */
	Configurator.prototype.readBoot = function () {
		var node = document.getElementById('dahu-kit-boot-' + this.productId);
		if (!node) return null;
		try {
			var data = JSON.parse(node.textContent);
			return data && data.schema && data.state ? data : null;
		} catch (e) {
			return null;
		}
	};

	/**
	 * Adopts a response from /state. The server returns the selections ALREADY
	 * corrected (hidden component removed, single variant chosen automatically):
	 * the client takes them as they are. That is the whole point — no hiding rule
	 * is replayed here any more.
	 */
	Configurator.prototype.applyState = function (state) {
		this.selections = state.selections || {};
		this.validation = {
			valid: !!state.valid,
			errors: state.errors || [],
			missing: state.missing || [],
			blocked: state.blocked || [],
			hidden_components: state.hidden_components || [],
			hidden_options: state.hidden_options || {}
		};
		this.displayHidden = state.display_hidden_components || null;
		this.quote = {
			price: state.price || {},
			weight: state.weight || {},
			line_items: state.line_items || [],
			// Price per screen; absent from a state dropped in by an earlier
			// version: the summary then keeps one price per row.
			screen_prices: state.screen_prices || null,
			quantity: state.quantity
		};
	};

	Configurator.prototype.start = function () {
		var self = this;
		this.watchMode();

		// Pro / Customer switch of dahu-pricing: the variant lists
		// already shown are relabelled in place, with no new render.
		document.addEventListener('dahu:price-mode-changed', function () {
			Array.prototype.forEach.call(self.root.querySelectorAll('option'), function (node) {
				if (node.dahuVariation) node.textContent = variationOptionText(node.dahuVariation, self.schema);
			});
		});

		// “More info” as a card / extended: Esc closes it, focus given back to
		// “More info”. Resize: the extended one is re-evaluated (falling back
		// to a flipped card when it no longer fits).
		// Info bubble: Esc closes it too (focus given back to its “i”).
		// Focus inside the panel or on an “i”: that one first; otherwise the
		// open card first. Drawer open: that is what Esc closes.
		document.addEventListener('keydown', function (event) {
			if (event.key !== 'Escape') return;
			// “?” popover of a range: always the first one closed.
			if (self.tierHelp) {
				self.closeTierHelp(true);
				return;
			}
			var active = document.activeElement;
			var onHint = !!self.hint && !!active && (self.hintNode.contains(active) || !!(active.hasAttribute && active.hasAttribute('data-hint-key')));
			if (onHint) {
				self.closeHint(true);
			} else if (self.infoOpen.length) {
				// The card that has focus; failing that, the last one opened.
				var focused = active && active.closest ? active.closest('[data-info-key]') : null;
				var focusedKey = focused ? focused.getAttribute('data-info-key') : null;
				self.closeInfo(true, focusedKey && self.infoFor(focusedKey) ? focusedKey : undefined);
			} else if (self.hint && !document.body.classList.contains('dahu-kit-drawer-is-open')) {
				self.closeHint(true);
			}
		});
		// Popup « ? » : clic ailleurs = fermeture (0.52.0) ; redimensionnement :
		// proportion of the media read again (the popover is centred).
		document.addEventListener('pointerdown', function (event) {
			if (!self.tierHelp) return;
			var target = event.target;
			if (self.tierHelpNode.contains(target)) return;
			if (target.closest && target.closest('[data-tier-help-key]')) return;
			self.closeTierHelp(false);
		});
		window.addEventListener('resize', function () {
			if (self.tierHelp) self.placeTierHelp();
		});
		var resizeFrame = null;
		window.addEventListener('resize', function () {
			if (resizeFrame || !self.infoOpen.length || self.infoMode !== 'extended') return;
			resizeFrame = window.requestAnimationFrame(function () {
				resizeFrame = null;
				self.refitInfo();
			});
		});

		// Fast path: everything is already in the page, no round trip.
		var boot = this.readBoot();
		if (boot) {
			this.schema = boot.schema;
			this.applyState(boot.state);
			this.root.classList.remove('is-loading');
			this.render();
			// Serial already applied by the server: boot.serial.
			this.resumeSaved(boot.serial || null);
			return;
		}

		api('GET', 'schema/' + this.productId).then(function (schema) {
			self.schema = schema;
			Object.keys(schema.defaults || {}).forEach(function (cid) {
				var d = schema.defaults[cid];
				// Multiple choice: a list.
				self.selections[cid] = Array.isArray(d)
					? d.map(function (item) { return { product_id: item.product_id, quantity: item.quantity }; })
					: (d.option_id ? { option_id: d.option_id } : { product_id: d.product_id, quantity: d.quantity });
			});
			self.validation.hidden_components = schema.visibility.hidden_components || [];
			self.validation.hidden_options = schema.visibility.hidden_options || {};
			self.displayHidden = schema.visibility.display_hidden_components || null;
			return self.refresh().then(function () { self.resumeSaved(); });
		}).catch(function (error) {
			self.root.classList.remove('is-loading');
			self.root.innerHTML = '';
			self.root.appendChild(el('p', { className: 'dahu-kit__error', text: t.loadError + ' (' + error.message + ')' }));
		});
	};

	/**
	 * At start-up: the “Open” link of My account › My configurations
	 * (?dahu_kit_config=<id>), or a configuration kept for the duration of a
	 * login asked for from the Summary (sessionStorage). Nothing else: the
	 * ordinary start-up stays what it was.
	 *
	 * Login asked for because the e-mail entered already had an account
	 * (pending.autosave): the configuration is saved automatically on the way
	 * back, with nothing for the customer to redo.
	 *
	 * Configuration serial (?dahu_kit_serial=…, a button on a product page or a
	 * link from the simulator): the configurator is reopened on those choices, at
	 * the screen following the last choice of the serial (landFromSerial()). The
	 * server has usually already applied it to the state dropped into the page
	 * (bootSerial); otherwise it is read again by /state.
	 *
	 * @param {Object|null} bootSerial Reopening already done by the server.
	 */
	Configurator.prototype.resumeSaved = function (bootSerial) {
		var id = null;
		var serial = null;
		try {
			var params = new window.URLSearchParams(window.location.search);
			id = params.get(cfg.savedParam || 'dahu_kit_config');
			serial = params.get(cfg.serialParam || 'dahu_kit_serial');
		} catch (e) {
			id = null;
			serial = null;
		}
		if (id) {
			if (cfg.loggedIn) this.openSaved(id);
			else this.startNotice(t.savedLoginToOpen, t.saveLogin, cfg.loginUrl);
			return;
		}
		serial = serial ? serial.trim().toLowerCase() : '';
		if (serial) {
			if (bootSerial && bootSerial.serial === serial) this.landFromSerial(bootSerial);
			else this.openSerial(serial);
			return;
		}
		var pending = cfg.loggedIn ? takePending(this.productId) : null;
		if (!pending) return;
		var self = this;
		this.selections = pending.selections || {};
		this.quantity = Math.max(1, parseInt(pending.quantity, 10) || 1);
		this.refresh().then(function () {
			self.goToSummary();
			if (pending.autosave && self.validation.valid) self.saveConfiguration();
		});
	};

	/**
	 * Reopens a saved configuration: GET /saved/<id> returns the state recomputed
	 * TODAY (prices, stock, scenarios) and what has changed. A choice that no
	 * longer holds has already been removed by the server: it is reported at its
	 * step (renderErrors()) and at the top of the Summary, never forced.
	 */
	Configurator.prototype.openSaved = function (id) {
		var self = this;
		var requestId = ++this.requestId;
		this.busy = true;
		this.render();
		return api('GET', 'saved/' + encodeURIComponent(id)).then(function (data) {
			if (requestId !== self.requestId) return;
			self.applyState(data.state || {});
			self.quantity = Math.max(1, parseInt((data.state || {}).quantity, 10) || 1);
			self.restoreIssues = data.issues || {};
			self.restoreNotice = { name: data.name || '', general: data.general || [] };
			self.busy = false;
			self.goToSummary();
		}).catch(function (error) {
			if (requestId !== self.requestId) return;
			self.busy = false;
			self.render();
			self.startNotice(error.message, cfg.accountKitsUrl ? t.savedSee : '', cfg.accountKitsUrl);
		});
	};

	/**
	 * Serial read again by the server: POST /state with `serial`. Its choices
	 * replace the defaults in force; when it is unreadable, the state stays that
	 * of the defaults.
	 *
	 * @param {string} serial Serial from the address.
	 */
	Configurator.prototype.openSerial = function (serial) {
		var self = this;
		var requestId = ++this.requestId;
		this.busy = true;
		this.render();
		return api('POST', 'state', Object.assign(this.payload(), { serial: serial })).then(function (state) {
			if (requestId !== self.requestId) return;
			self.applyState(state);
			self.busy = false;
			self.landFromSerial(state.serial_restore || { invalid: true });
		}).catch(function (error) {
			if (requestId !== self.requestId) return;
			self.busy = false;
			self.message = { type: 'error', text: error.message };
			self.render();
		});
	};

	/**
	 * Landing screen of a serial — the selections are already in place. We land
	 * on the screen FOLLOWING the last screen the serial filled in (e.g. Motor
	 * chosen → Battery, even an optional one), unless a screen before it is still
	 * to be done — we then stop on the first of those:
	 *  - screen not finished (variant to choose, choice removed because it no
	 *    longer holds, mandatory one empty);
	 *  - screen with a mandatory component the serial does not fill in: its
	 *    selection is only a default, never seen by the customer (the page of a
	 *    battery → the Motor screen, with the default motor preselected).
	 * The last screen filled in also keeps the customer when it still asks for a
	 * gesture on the spot (needsMoreInput(): a question asked with no answer, an
	 * add-on next to the choice), outside a multiple choice.
	 * Screens passed: reachable through the summary (maxStep). A yes / no
	 * question on a screen that was passed, with no selection: “No”.
	 * Everything filled in: the Summary.
	 *
	 * @param {Object} restore { serial, components: string[] } or { invalid: true }.
	 */
	Configurator.prototype.landFromSerial = function (restore) {
		if (!restore || restore.invalid || !restore.components) {
			this.render();
			this.startNotice(t.serialInvalid || '', '', '');
			return;
		}
		var self = this;
		var restored = restore.components;
		var fromSerial = function (c) { return restored.indexOf(c.id) !== -1; };
		var steps = this.steps();
		var last = -1;
		steps.forEach(function (step, index) {
			var filled = step.components.some(function (c) { return fromSerial(c) && self.hasSelection(c.id); });
			if (filled) last = index;
		});
		var target = last + 1;
		for (var i = 0; i < target && i < steps.length; i++) {
			var step = steps[i];
			var unseen = !step.components.some(fromSerial) && step.components.some(function (c) { return !c.optional; });
			var stay = i === last && this.needsMoreInput(step) && !step.components.some(isMultiple);
			if (!this.isStepDone(step) || unseen || stay) {
				target = i;
				break;
			}
		}
		steps.forEach(function (step, index) {
			if (index >= target) return;
			step.components.forEach(function (c) {
				if (isQuestion(c) && !self.hasSelection(c.id)) self.declined[c.id] = true;
			});
		});
		this.touched = false;
		this.step = this.maxStep = Math.min(Math.max(target, 0), steps.length);
		this.render();
	};

	/**
	 * Banner at the top of the configurator, outside the step panel: reopening
	 * impossible (not logged in, configuration not found). The configurator stays
	 * usable as normal below it.
	 */
	Configurator.prototype.startNotice = function (text, linkText, href) {
		var node = el('div', { className: 'dahu-kit__message dahu-kit__start-notice is-error', role: 'status' }, [
			el('span', { text: text }),
			linkText && href ? ' ' : null,
			linkText && href ? el('a', { href: href, text: linkText }) : null
		]);
		var old = this.root.querySelector(':scope > .dahu-kit__start-notice');
		if (old) old.parentNode.removeChild(old);
		this.root.insertBefore(node, this.root.firstChild);
	};

	/**
	 * Straight to the Summary, with every screen counting as passed through (each
	 * can be returned to through the summary). No automatic advance.
	 */
	Configurator.prototype.goToSummary = function () {
		this.touched = false;
		this.step = this.maxStep = this.steps().length;
		this.render();
	};

	/** The customer touches a component again: its reopening differences drop. */
	Configurator.prototype.clearRestoreIssue = function (cid) {
		if (this.restoreIssues && this.restoreIssues[cid]) delete this.restoreIssues[cid];
		this.saveMessage = null;
		this.guestResult = null;
	};

	/**
	 * Configuration kept for the duration of a login (sessionStorage: this tab
	 * only, two hours at most). Read once only.
	 */
	var PENDING_PREFIX = 'dahu-kit-pending-';
	function storePending(productId, data) {
		try {
			window.sessionStorage.setItem(PENDING_PREFIX + productId, JSON.stringify(Object.assign({ at: Date.now() }, data)));
		} catch (e) { /* storage unavailable: we log in without keeping anything */ }
	}
	function takePending(productId) {
		try {
			var raw = window.sessionStorage.getItem(PENDING_PREFIX + productId);
			window.sessionStorage.removeItem(PENDING_PREFIX + productId);
			var data = raw ? JSON.parse(raw) : null;
			return data && Date.now() - data.at < 2 * 3600 * 1000 ? data : null;
		} catch (e) {
			return null;
		}
	}

	/** Does the component have at least one option chosen? */
	Configurator.prototype.hasSelection = function (cid) {
		return selectionItems(this.selections[cid]).length > 0;
	};

	Configurator.prototype.component = function (cid) {
		var found = null;
		(this.schema.components || []).forEach(function (c) { if (c.id === cid) found = c; });
		return found;
	};

	Configurator.prototype.visibleComponents = function () {
		var hidden = this.validation.hidden_components || [];
		return this.schema.components.filter(function (c) { return hidden.indexOf(c.id) === -1; });
	};

	function isPath(component) {
		return component.component_type === 'path';
	}

	/**
	 * Screens of the configurator: the visible components sharing the same
	 * display_group are rendered together. The grouping is purely visual — every
	 * component stays validated and priced separately on the server.
	 */
	/**
	 * Heading of a screen. A screen may carry several components
	 * (display_group): its title is the title of all of them. A single
	 * definition, used by the right-hand panel, the mobile banner and the
	 * summary — all three have to name the screen the same way.
	 */
	function stepTitle(step) {
		// “secondary” component (title_role): an add-on next to the main
		// choice (a cassette next to the motor), outside the title of the screen.
		// Screen made only of “secondary” ones: we keep them all, since a
		// screen always has a title.
		var primary = step.components.filter(function (c) { return c.title_role !== 'secondary'; });
		return (primary.length ? primary : step.components).map(function (c) { return c.title; }).join(' + ');
	}

	/**
	 * “Add-on” component: “secondary” (title_role), optional and with ONE single
	 * option — the cassette next to the motor. Not a choice between several
	 * possibilities but “with / without”: an “Add” / “Added” button
	 * (addonBlock()), with no “None” card.
	 *
	 * Tied to “secondary” on purpose: an add-on sits BESIDE a main choice, and it
	 * is that choice which moves things forward. An optional component with one
	 * option, alone on its screen, stays a list of cards (“None” + its option):
	 * otherwise nothing on the screen would allow moving on.
	 *
	 * Definition shared with the administration simulator
	 * (dahu-composite-tiers.js), like the anchoring (anchors()).
	 */
	function isAddon(component) {
		return window.DahuCompositeTiers.isAddon(component);
	}

	/**
	 * Add-on presented as a yes / no QUESTION (`presentation` = “question”):
	 * “Do you have a freehub-standard cassette?”. It is still an add-on
	 * (isAddon() stays true: anchoring, title, place of “Next”); what changes is
	 * only its rendering (questionBlock(), inside the chosen card of the anchor
	 * component), when it appears (questionWaiting()) and the fact that “No” is
	 * an answer (this.declined) which moves things forward. The engine knows
	 * nothing of it: “Yes” = the option chosen, “No” = nothing.
	 */
	function isQuestion(component) {
		return window.DahuCompositeTiers.isQuestion(component);
	}

	/**
	 * Multiple-choice component (selection_mode “multiple”): several options
	 * together, each on an “add-on” card (Add / Added, addonCard() — “Select /
	 * Selected” in the general appearance); “Next” moves things forward
	 * (renderStepNav()). No “None” card: adding nothing is enough. Its selection
	 * is a LIST of items ({ product_id, variation_id, … }, one per option),
	 * instead of a single item.
	 */
	function isMultiple(component) {
		return !!component && component.component_type !== 'path' && component.selection_mode === 'multiple';
	}

	/* ===== Layout of a multiple-choice component =====
	 * The “Layout” setting of the component (display_style, kit editor),
	 * component by component:
	 *   ''      = general appearance of the kit (the usual “add-on” cards);
	 *   'image' = product card (photo, name, price, button) in a grid;
	 *   'row'   = one row per option, EXPANDABLE (photo and short description,
	 *             rowDetail() / toggleRow());
	 *   'small' = compact grid; 'icon' = a pictogram in place of the photo;
	 *             'chips' = clickable pills.
	 * Rendering only: selection, hiding and validation unchanged.
	 * Stylesheet: assets/css/dahu-composite-display-style.css.
	 * History: it started as a test setting (test_display_style, still read back
	 * by the server), then preview buttons and preview styles, and became a real
	 * setting. The preview buttons on the product page
	 * (.dahu-kit__style-preview) have been removed: the layout is set in the back
	 * office only (kit editor). */
	var DISPLAY_STYLES = ['image', 'row', 'small', 'icon', 'chips'];

	/* Yes / no question on the BACK of the chosen card: the card of the anchor
	 * component flips over by itself onto the question, and its front carries a
	 * control to come back (questionToggle()). It was disabled for a while (the
	 * question right after the card, with no flip), then RESTORED: outside the
	 * back, the question fell into the grid, to the right of the card.
	 * The code is the one from before that detour (renderCards() > inside(),
	 * questionToggle(), autoOpenQuestions(), questionShown()). Square cards (with
	 * no back): always after the card. */
	var QUESTION_ON_CARD_BACK = true;
	function displayStyle(component) {
		if (!isMultiple(component)) return '';
		var style = component.display_style;
		return DISPLAY_STYLES.indexOf(style) !== -1 ? style : '';
	}

	/* Pictograms of the “Category icon” layout. The schema carries no category
	 * per option: the pictogram is derived from the NAME of the product. This
	 * file holds the SHAPES only — not one keyword. The keywords come from the
	 * server (cfg.categoryIcons, filter dahu_composite_category_icons), so a
	 * catalogue in another language is served by filtering that table instead of
	 * patching this script. Nothing matched, or no table at all: the box. */
	var CATEGORY_SHAPES = {
		box: '<rect x="4" y="7" width="16" height="11" rx="2"/><path d="M8 7V5.5A2.5 2.5 0 0 1 10.5 3h3A2.5 2.5 0 0 1 16 5.5V7"/>',
		wheel: '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="3"/>',
		drivetrain: '<circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>',
		tool: '<path d="M14.5 6.5a4 4 0 0 0-5.3 5.3L4 17l3 3 5.2-5.2a4 4 0 0 0 5.3-5.3l-2.6 2.6-2.4-.6-.6-2.4z"/>',
		mount: '<path d="M4 8h10a4 4 0 0 1 0 8H4z"/><circle cx="8" cy="12" r="1.5"/>',
		battery: '<rect x="3" y="8" width="16" height="8" rx="2"/><path d="M21 11v2"/>',
		light: '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.9V16h7v-2.1A6 6 0 0 0 12 3z"/>',
		lock: '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>'
	};

	/* The server table compiled once into [ RegExp, shape ] pairs, in the order
	 * it was sent: the first keyword that matches wins. An entry naming an
	 * unknown shape, or a pattern this browser refuses, is skipped rather than
	 * taking the whole list down. */
	var CATEGORY_ICONS = (function () {
		var out = [];
		var table = cfg.categoryIcons;
		if (!Array.isArray(table)) return out;
		table.forEach(function (entry) {
			var shape = entry && CATEGORY_SHAPES[entry.icon];
			if (!shape || !entry.pattern) return;
			try {
				out.push([new RegExp(entry.pattern, 'i'), shape]);
			} catch (error) {
				// A pattern this engine cannot compile: ignored.
			}
		});
		return out;
	})();
	function categoryIcon(option) {
		var shape = CATEGORY_SHAPES.box;
		for (var i = 0; i < CATEGORY_ICONS.length; i++) {
			if (CATEGORY_ICONS[i][0].test(option.name || '')) { shape = CATEGORY_ICONS[i][1]; break; }
		}
		return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + shape + '</svg>';
	}

	/**
	 * Detail of an expandable row (“Inline” layout): the photo of the product (the
	 * image of the variant added, when there is one) and its short description —
	 * each one when its checkbox is ticked on the option (show_image,
	 * show_description). Nothing to show: null, and the row is not expandable (no
	 * arrow, no click).
	 */
	function rowDetail(option, sel) {
		var photo = option.show_image !== false ? optionImage(option, sel) : null;
		var text = option.show_description !== false ? shortDescription(option) : '';
		return photo || text ? { photo: photo, text: text } : null;
	}

	/** Arrow of an expandable row: pointing down, flipped when the row is open (stylesheet). */
	var ROW_CHEVRON = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9l6 6 6-6"/></svg>';
	/* ===== end of the per-component layout ===== */

	/**
	 * Items of a selection: [] with no selection, [selection] for a radio
	 * component or a branch, the list itself for a multiple-choice component. The
	 * same reading as the engine
	 * (Dahu_Composite_Scenarios::selection_items()).
	 */
	function selectionItems(sel) {
		if (!sel) return [];
		return Array.isArray(sel) ? sel : [sel];
	}

	/** Screens of a list of components: consecutive ones with the same display_group = one screen. */
	function groupSteps(components) {
		var steps = [];
		var last = null;
		components.forEach(function (c) {
			var group = c.display_group || '';
			if (group && last && last.group === group) {
				last.components.push(c);
				return;
			}
			last = { group: group, components: [c] };
			steps.push(last);
		});
		return steps;
	}

	/** Identity of a screen from one list to the next (real / projected journey). */
	function stepId(step) {
		return step.group || step.components[0].id;
	}

	Configurator.prototype.steps = function () {
		return groupSteps(this.visibleComponents());
	};

	/**
	 * Journey DISPLAYED by the summary and the step counter. With no choice made,
	 * the real journey stops at the first branch (“Step 1 / 2”): the following
	 * steps only exist once the branch has been chosen. So we display the journey
	 * projected by the server (real selections + representative value of every
	 * component not decided), joined with the real journey — every real screen is
	 * always in it, and navigation (steps(), this.step, canGoToStep()) stays on
	 * the real one. “Coming soon” option chosen: no sequel, the real journey.
	 */
	Configurator.prototype.displaySteps = function () {
		if (!this.displayHidden || this.comingSoon()) return this.steps();
		var real = this.validation.hidden_components || [];
		var projected = this.displayHidden;
		return groupSteps(this.schema.components.filter(function (c) {
			return real.indexOf(c.id) === -1 || projected.indexOf(c.id) === -1;
		}));
	};

	/**
	 * Components already decided, sent to /state for the projection: those of the
	 * screens that were passed (an optional one left empty is a choice there, not
	 * an oversight) and those set to “None”. The components that are selected are
	 * taken as decided on the server side.
	 */
	Configurator.prototype.decidedComponents = function () {
		var reached = Math.max(this.step, this.maxStep);
		var ids = [];
		this.steps().forEach(function (step, index) {
			if (index < reached) step.components.forEach(function (c) { ids.push(c.id); });
		});
		Object.keys(this.declined).forEach(function (cid) {
			if (ids.indexOf(cid) === -1) ids.push(cid);
		});
		return ids;
	};

	/** Selected “path” option whose sequel does not exist yet. */
	Configurator.prototype.comingSoon = function () {
		var self = this;
		var found = null;
		this.visibleComponents().forEach(function (c) {
			if (!isPath(c) || found) return;
			var sel = self.selections[c.id];
			if (!sel || !sel.option_id) return;
			c.options.forEach(function (o) {
				if (o.id === sel.option_id && o.coming_soon) found = { component: c, option: o };
			});
		});
		return found;
	};

	// The clearing of the selections made invalid by a hiding rule
	// (pruneSelections / autoSelectVariations) has been moved
	// into the PHP engine — Dahu_Composite_Engine::resolve_selections(). It
	// replayed the hiding rules outside the engine, and each of its turns
	// cost a full REST round trip. See refresh().

	Configurator.prototype.payload = function () {
		return { product_id: this.productId, selections: this.selections, quantity: this.quantity };
	};

	/**
	 * ONE single round trip per action of the customer.
	 *
	 * It used to be: POST /validate, clearing on the client side, re-POST
	 * /validate as long as the clearing changed something (up to five times),
	 * then POST /quote — that is two to six requests in series, each with its own
	 * WordPress + WooCommerce bootstrap.
	 *
	 * Now: POST /state. The clearing cascade runs in memory on the server and the
	 * response carries the corrected selections, the validity, the hiding, the
	 * price and the weight all at once.
	 */
	Configurator.prototype.refresh = function () {
		var self = this;
		var id = ++this.requestId;
		this.busy = true;
		this.render();

		// decided: for the display projection of the journey only.
		return api('POST', 'state', Object.assign(this.payload(), { decided: this.decidedComponents() })).then(function (state) {
			if (id !== self.requestId) return;
			self.applyState(state);
			self.busy = false;
			self.clampStep();
			// Before render(): the automatic advance timer has to be set
			// before the render that follows.
			self.maybeAutoAdvance();
			self.render();
		}).catch(function (error) {
			if (id !== self.requestId) return;
			self.busy = false;
			self.message = { type: 'error', text: error.message };
			self.render();
		});
	};

	Configurator.prototype.clampStep = function () {
		var max = this.steps().length; // index of the Summary
		if (this.step > max) this.step = max;
		// The number of screens varies with the hiding (changing the kit type
		// replaces the whole sequel): the bound must never point beyond it.
		if (this.maxStep > max) this.maxStep = max;
		if (this.maxStep < this.step) this.maxStep = this.step;
	};

	/**
	 * Is a screen reachable through the summary? Only the current screen and the
	 * ones already passed are. The summary (index = number of screens) follows
	 * the same rule: it is only reached after going through the screens.
	 */
	Configurator.prototype.canGoToStep = function (index) {
		return index <= Math.max(this.step, this.maxStep);
	};

	/** Navigation through the summary. Refuses any jump forward. */
	Configurator.prototype.goToStep = function (index) {
		if (!this.canGoToStep(index)) return;
		this.step = index;
		if (this.maxStep < index) this.maxStep = index;
		this.recapOpen = false;
		this.render();
	};

	/**
	 * Selection of a branch option. A “coming soon” option has no sequel: we call
	 * neither /validate nor /quote.
	 */
	Configurator.prototype.selectPath = function (cid, option) {
		this.selections[cid] = { option_id: option.id };
		this.clearRestoreIssue(cid);
		this.message = null;
		this.touched = true;
		if (option.coming_soon) {
			this.busy = false;
			this.render();
			return;
		}
		this.refresh();
	};

	/**
	 * Right-hand column: the component whose product it shows. Called on a click
	 * on an option card, BEFORE the selection: the render that follows therefore
	 * already shows that product.
	 */
	Configurator.prototype.setFocus = function (cid) {
		this.focusComponent = cid;
		this.preview = null;
	};

	/** Key of an option: branch id, or id of the product. */
	function optionKey(component, option) {
		return isPath(component) ? option.id : option.product_id;
	}

	/**
	 * Button mode (readSelectMode()): a click on a card outside its controls → a
	 * preview of the product in the right-hand column, without selecting it or
	 * moving on.
	 */
	Configurator.prototype.showPreview = function (component, option) {
		this.preview = { cid: component.id, key: optionKey(component, option) };
		this.render();
	};

	/**
	 * Option to show in the right-hand column, among the components of the
	 * current screen: the preview (button mode, card clicked) when it is on this
	 * screen; else the one of the last component selected when it is on this
	 * screen and still filled in; else the first option chosen on the screen.
	 * null = nothing chosen on this screen.
	 *
	 * @param {Array} components Components of the current screen.
	 * @return {{component: Object, option: Object, sel: Object}|null}
	 */
	Configurator.prototype.focusedOption = function (components) {
		var self = this;
		var preview = this.preview;
		var previewed = null;
		if (preview) {
			components.forEach(function (component) {
				if (component.id !== preview.cid) return;
				component.options.forEach(function (o) {
					if (optionKey(component, o) !== preview.key) return;
					// Item chosen for this option (multiple choice: among
					// plusieurs), sinon null.
					var chosen = null;
					selectionItems(self.selections[component.id]).forEach(function (sel) {
						if ((isPath(component) ? sel.option_id : sel.product_id) === preview.key) chosen = sel;
					});
					previewed = { component: component, option: o, sel: chosen };
				});
			});
		}
		if (previewed) return previewed;

		var ordered = components.slice();
		ordered.sort(function (a, b) {
			return (b.id === self.focusComponent ? 1 : 0) - (a.id === self.focusComponent ? 1 : 0);
		});

		var found = null;
		ordered.some(function (component) {
			// Multiple choice: the last item chosen.
			selectionItems(self.selections[component.id]).forEach(function (sel) {
				component.options.forEach(function (o) {
					if (isPath(component) ? o.id === sel.option_id : o.product_id === sel.product_id) {
						found = { component: component, option: o, sel: sel };
					}
				});
			});
			return !!found;
		});
		return found;
	};

	/**
	 * Photo of a chosen option: large format, replaced by the variant's own image
	 * when it has one. null = no image.
	 */
	function optionImage(option, sel) {
		var src = option.image_large || option.image || null;
		if (sel && sel.variation_id) {
			(option.variations || []).forEach(function (v) {
				if (v.variation_id === sel.variation_id && v.image_large) src = v.image_large;
			});
		}
		return src;
	}

	/** Starts again from a step: the selection of that step and of the following ones is cleared. */
	Configurator.prototype.restartAt = function (cid) {
		var ids = this.schema.components.map(function (c) { return c.id; });
		var from = ids.indexOf(cid);
		var self = this;
		if (from === -1) return;
		ids.slice(from).forEach(function (id) {
			delete self.selections[id];
			delete self.declined[id];
			delete self.answeredYes[id];
		});
		var steps = this.steps();
		for (var i = 0; i < steps.length; i++) {
			if (steps[i].components.some(function (c) { return c.id === cid; })) { this.step = i; break; }
		}
		// The following selections have just been cleared: those screens no
		// are no longer “reached”, and have to be gone through again.
		this.maxStep = this.step;
		this.message = null;
		this.refresh();
	};

	/**
	 * @param {string} cid    Component.
	 * @param {Object} patch  Change (product, variant, attributes, bundle, quantity).
	 * @param {number} [item] Multiple choice: product of the item targeted
	 *                        (selectItem()). Ignored for a radio component.
	 */
	Configurator.prototype.select = function (cid, patch, item) {
		var component = this.component(cid);
		if (isMultiple(component)) {
			this.selectItem(component, patch, item || patch.product_id);
			return;
		}
		var current = this.selections[cid] || {};
		var next = Object.assign({}, current, patch);
		if (patch.product_id && patch.product_id !== current.product_id) {
			this.resetQuestions(component);
			// An optional component has quantity_min = 0: choosing an option
			// means “one unit”, otherwise no row would be created.
			next = {
				product_id: patch.product_id,
				quantity: patch.quantity || current.quantity || component.quantity_min || 1
			};
			// Variant chosen inside the card of an option not yet
			// selected: product and variant are taken together.
			if (patch.variation_id) next.variation_id = patch.variation_id;
		} else if ('variation_id' in patch && patch.variation_id !== current.variation_id) {
			delete next.attributes; // “Any” attributes specific to the former variant
		}
		this.selections[cid] = next;
		this.clearRestoreIssue(cid);
		this.message = null;
		this.touched = true;
		this.refresh();
	};

	/**
	 * Another card chosen in `host`: the questions asked beside its cards become
	 * unanswered again — a “No” given for one motor does not hold for the next.
	 * A “Yes” is a selection: the server keeps it or removes it according to the
	 * scenarios.
	 */
	Configurator.prototype.resetQuestions = function (host) {
		var self = this;
		this.schema.components.forEach(function (c) {
			if (c === host || !isQuestion(c)) return;
			var hostId = c.anchor_component_id || '';
			var linked = hostId ? hostId === host.id : !!c.display_group && c.display_group === host.display_group;
			if (linked) {
				delete self.declined[c.id];
				delete self.answeredYes[c.id];
			}
		});
	};

	/**
	 * Multiple choice: adds the option `productId` to the list, or sets the item
	 * already chosen (variant, “Any” attributes, bundle items) — the same rules
	 * as select() for a radio component, item by item. Never moves things
	 * forward: several options are chosen on the spot; “Next” or “None” move on.
	 */
	Configurator.prototype.selectItem = function (component, patch, productId) {
		var items = selectionItems(this.selections[component.id]).slice();
		var index = -1;
		items.forEach(function (item, i) { if (item.product_id === productId) index = i; });
		var current = index === -1 ? null : items[index];
		// Add-on: a new option, through its button. Only an add-on can
		// move on, and only when ALL the options offered are
		// added (needsMoreInput()) — unless the option added is still to be set
		// still to be set on the spot (items of a bundle). A variant chosen in the
		// list is never an addition that moves things on.
		var adding = index === -1 && !patch.variation_id;
		if (adding && !optionNeedsInput(optionOf(component, productId))) this.advanceRequested = true;
		var next = Object.assign({}, current || { quantity: 1 }, patch, { product_id: productId });
		if (current && 'variation_id' in patch && patch.variation_id !== current.variation_id) {
			delete next.attributes; // “Any” attributes specific to the former variant
		}
		if (index === -1) items.push(next);
		else items[index] = next;
		this.selections[component.id] = items;
		this.clearRestoreIssue(component.id);
		this.message = null;
		this.touched = true;
		this.refresh();
	};

	/** Multiple choice: removes an option from the list (empty: no selection left). */
	Configurator.prototype.removeItem = function (cid, productId) {
		var items = selectionItems(this.selections[cid]).filter(function (item) { return item.product_id !== productId; });
		if (items.length) this.selections[cid] = items;
		else delete this.selections[cid];
		this.clearRestoreIssue(cid);
		this.message = null;
		this.touched = true;
		this.refresh();
	};

	/** Optional component: removing the selection. */
	Configurator.prototype.clear = function (cid) {
		delete this.selections[cid];
		this.clearRestoreIssue(cid);
		this.message = null;
		this.touched = true;
		this.refresh();
	};

	/**
	 * Choosing an option (a click on the card or on “Select” in button mode): the
	 * same path as select() / clear(), plus a request to move on as soon as the
	 * screen is complete (the “Next” button only exists for a multiple choice,
	 * pendingMultiple()). The settings (variant changed inside a card, quantity,
	 * items of a bundle) go through select() alone and do not move on (the
	 * variant included).
	 */
	Configurator.prototype.choose = function (cid, patch) {
		this.advanceRequested = true;
		this.setFocus(cid);
		this.select(cid, patch);
		if (patch && patch.product_id) this.focusVariation(cid, patch.product_id);
	};

	/**
	 * Option with variants retained without a variant: focus moves to its list,
	 * under which the message “Choose a variant before continuing” has just
	 * appeared (renderVariationSelect()). Nothing to do when the variant is known
	 * or chosen automatically (list absent, or without .is-required).
	 */
	Configurator.prototype.focusVariation = function (cid, productId) {
		if (!this.panelNode) return;
		var node = this.panelNode.querySelector('[data-focus-key="' + variationKey(cid, productId) + '"]');
		if (node && node.classList.contains('is-required')) node.focus({ preventScroll: true });
	};

	/** Focus key of the variant list of an option. */
	function variationKey(cid, productId) {
		return cid + ':' + productId + ':variation';
	}

	Configurator.prototype.chooseNone = function (cid) {
		this.advanceRequested = true;
		this.setFocus(cid);
		// “None” is a choice: the projection of the journey does not lend it
		// its default (decidedComponents()).
		this.declined[cid] = true;
		delete this.answeredYes[cid];
		this.clear(cid);
	};

	/**
	 * A click on the option ALREADY chosen: it means “continue”. When a response
	 * from the server is pending (a variant just changed), the request is handed
	 * to maybeAutoAdvance(), which will decide on the up-to-date state.
	 */
	Configurator.prototype.continueFrom = function () {
		var step = this.steps()[this.step];
		if (!step) return;
		if (this.busy) {
			this.advanceRequested = true;
			return;
		}
		if (this.isStepDone(step)) this.goNext();
	};

	/**
	 * Moves to the next screen. This is the ONLY way to move forward: the
	 * automatic advance and a click on a card both call this method.
	 */
	Configurator.prototype.goNext = function () {
		if (this.step >= this.steps().length) return;
		this.step++;
		// The only place that pushes the bound further: a new screen is only
		// reached by actually moving forward, never through the summary.
		if (this.maxStep < this.step) this.maxStep = this.step;
		this.render();
		// Tall cards (vignettes): the top of the new screen may be
		// above the window. We bring it back, without ever scrolling down.
		if (this.panelNode && this.panelNode.getBoundingClientRect().top < 0) {
			var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			this.panelNode.scrollIntoView({ block: 'start', behavior: reduced ? 'auto' : 'smooth' });
		}
	};

	/**
	 * The screen still asks for a gesture on the spot: items of a bundle or the
	 * adjustable quantity of the chosen option, or an “add-on” component shown
	 * beside the choice and NOT ADDED YET (a cassette next to the motor — moving
	 * on straight away would make it unreachable). No automatic advance: the
	 * customer answers the question, clicks the chosen option again
	 * (continueFrom()) or goes through the summary — there is no “Next” here any
	 * more. Add-on done: the screen becomes an ordinary screen again.
	 */
	Configurator.prototype.needsMoreInput = function (step) {
		var self = this;
		return step.components.some(function (c) {
			if (isPath(c)) return false;
			// Question: pending as long as it is asked and unanswered.
			// “No” is an answer; a question not yet asked
			// (questionWaiting()) remembers nothing.
			if (isQuestion(c)) return !self.selections[c.id] && !self.declined[c.id] && !self.questionWaiting(c, step);
			if (isAddon(c)) return !self.selections[c.id];
			// Multiple choice: some options chosen, maybe more to
			// others on the spot (“Next”); none (“None”): we move on.
			// All the options offered added: nothing left to
			// choisir, on avance aussi.
			if (isMultiple(c)) return self.hasSelection(c.id) && !self.allOptionsAdded(c);
			var sel = self.selections[c.id];
			if (!sel) return false;
			if (c.quantity_editable && c.quantity_max > c.quantity_min) return true;
			return c.options.some(function (o) {
				return o.product_id === sel.product_id && (o.bundle_items || []).some(function (item) {
					return item.optional || item.variations.length > 1;
				});
			});
		});
	};

	/**
	 * Multiple choice: all the options OFFERED are added — those the customer can
	 * add: neither hidden by a scenario nor out of stock (the same rule as the
	 * “Add” button, addonCard()). “None” is not an option. Nothing offered:
	 * false (nothing has been added).
	 */
	Configurator.prototype.allOptionsAdded = function (component) {
		var hiddenList = (this.validation.hidden_options || {})[component.id] || [];
		var items = selectionItems(this.selections[component.id]);
		var offered = component.options.filter(function (o) {
			return !!o.in_stock && !isHidden(o.product_id, null, hiddenList);
		});
		return offered.length > 0 && offered.every(function (o) {
			return items.some(function (item) { return item.product_id === o.product_id; });
		});
	};

	/** Option of a component by product, or null. */
	function optionOf(component, productId) {
		var found = null;
		(component.options || []).forEach(function (o) { if (o.product_id === productId) found = o; });
		return found;
	}

	/**
	 * The option is still to be set on its card: a bundle with optional items or
	 * items with variants (the same rule as needsMoreInput() for a radio
	 * component). Adding it therefore does not move things on.
	 */
	function optionNeedsInput(option) {
		return !!option && (option.bundle_items || []).some(function (item) {
			return item.optional || item.variations.length > 1;
		});
	}

	/**
	 * Automatic advance — the ordinary way of moving forward; a “Next” button only
	 * appears for a multiple choice (pendingMultiple()).
	 *
	 * - Screen made up ONLY of “path” components (kit type + axle + battery
	 *   format): every choice reveals the next one on the same screen; when there
	 *   is nothing left to choose, we move on.
	 * - Product screen: only following a choice (choose(), continueFrom()), and
	 *   when nothing is left to settle on the spot (needsMoreInput()).
	 *
	 * A hidden component does not appear in step.components (steps() starts from
	 * the visible components): “everything is chosen or hidden” therefore comes
	 * down to “every component of the screen is filled in”, which isStepDone()
	 * already knows how to say.
	 */
	Configurator.prototype.maybeAutoAdvance = function () {
		var self = this;

		if (this.autoAdvanceTimer) {
			window.clearTimeout(this.autoAdvanceTimer);
			this.autoAdvanceTimer = null;
		}

		// Request for a choice: valid for THIS response only.
		var requested = this.advanceRequested;
		this.advanceRequested = false;

		// Never on load: only following a choice by the customer.
		if (!this.touched) return;

		// Dead end or “coming soon” option: render() already shows the
		// panel matching the place of the steps, there is nowhere to
		// avancer.
		if ((this.validation.blocked || []).length || this.comingSoon()) return;

		var steps = this.steps();
		var step = steps[this.step];
		if (!step || this.step >= steps.length) return;
		// “path” screen: always. Product screen: only following
		// a choice, and when nothing is left to settle on the spot.
		if (!step.components.every(isPath) && !(requested && !this.needsMoreInput(step))) return;
		if (!this.isStepDone(step)) return;

		// A short delay: the last click stays visible for a moment before the
		// transition.
		var id = this.requestId;
		this.autoAdvanceTimer = window.setTimeout(function () {
			self.autoAdvanceTimer = null;
			// Another change came through in the meantime: we drop this one.
			if (id !== self.requestId) return;
			self.goNext();
		}, 300);
	};

	Configurator.prototype.isComponentReady = function (cid) {
		var component = this.component(cid);
		if ((this.validation.missing || []).indexOf(cid) !== -1) return false;
		var hasError = (this.validation.errors || []).some(function (e) { return e.component === cid; });
		if (hasError) return false;
		return this.hasSelection(cid) || component.optional;
	};

	/* ------------------------------------------------------------------ */
	/* Rendu                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Three columns: persistent summary on the left, current step in the middle,
	 * photo + short description on the right (two columns in vignette mode,
	 * without the right-hand one). Below 64em the summary becomes a collapsible
	 * banner above the picker, and the right-hand column moves under the picker.
	 */
	Configurator.prototype.render = function () {
		if (!this.schema) return;
		var steps = this.steps();
		var root = this.root;

		// Skeleton set once only; panel, summary and right-hand
		// column are rebuilt on every render inside these persistent nodes
		// (the product title is moved once only).
		if (!this.layoutNode) {
			// Product title: a theme may mark its title with
			// [data-dahu-kit-title] to hand it over to the configurator, which
			// place above the picker, to the right of the summary. Opt-in: without that attribute the title stays where the theme
			// put it. The node is MOVED, not copied (a single <h1>).
			var title = document.querySelector('[data-dahu-kit-title]');
			this.headingNode = title ? el('div', { className: 'dahu-kit__heading' }, [title]) : null;
			// Title of the step in the SAME row as the title of the product: the
			// theme lays them out (product title, step number, step
			// title). Updated on every render (updateStepHeading()).
			if (this.headingNode) {
				this.stepHeadingNode = el('h2', { className: 'dahu-kit__step-title', 'aria-live': 'polite' });
				this.headingNode.appendChild(this.stepHeadingNode);
			}
			this.recapNode = el('aside', { 'aria-label': t.recap });
			// Same classes as the buying area of a product page of the theme:
			// the picker takes the styling of the buybox (title, price, subtitle,
			// button area), and falls back on the plugin's styles without it.
			var kit = this;
			this.panelNode = el('div', {
				className: 'dahu-kit__panel summary entry-summary',
				// Click inside the panel, outside the cards: the cards
				// flipped (or extended) go back to the front.
				onclick: function (event) {
					if (kit.infoOpen.length && !event.target.closest('.dahu-kit__option')) kit.closeAllInfo();
				}
			});
			this.infoNode = el('aside', { className: 'dahu-kit__info' });
			// Info bubble: the first child of the panel (just under the header),
			// never recreated or removed (see createHint()).
			this.createHint();
			this.layoutNode = el('div', {
				className: 'dahu-kit__layout' + (this.headingNode ? ' has-heading' : '')
			}, [this.headingNode, this.recapNode, this.panelNode, this.infoNode]);
			root.innerHTML = '';
			root.appendChild(this.layoutNode);
		}
		root.classList.toggle('is-busy', this.busy);
		// Screen change pending: the server's answer to a
		// gesture of the customer, then the short delay of the automatic advance
		// (maybeAutoAdvance()). Shows the small circle of the step title
		// (.dahu-kit__step-loading); removed when the next screen renders, or
		// on the answer when the screen does not change.
		root.classList.toggle('is-advancing', (this.busy && !!this.touched) || !!this.autoAdvanceTimer);

		// Vignettes: the same grid, without the right-hand column (see the
		// fonctionnelle).
		this.vignettes = readMode(this.root);
		this.selectButton = readSelectMode(this.root);
		this.infoMode = readInfoMode(this.root);
		this.icons = readIconsMode(this.root);
		this.layoutNode.className = 'dahu-kit__layout' + (this.headingNode ? ' has-heading' : '') + (this.vignettes ? ' is-vignettes' : '') + (this.selectButton ? ' is-select-button' : '')
			+ ' is-info-' + this.infoMode + (this.icons ? ' is-icons' : '');
		this.infoNode.hidden = this.vignettes;

		// Dead end declared by the server (a “block” scenario) or a
		// “coming soon” option: the journey stops here.
		var blocked = (this.validation.blocked || [])[0];
		var soon = this.comingSoon();
		var panel;
		// Keyboard focus is on a card or a button of the panel, which is about
		// be recreated (panel rebuilt, or content of a slot
		// replaced): we will give it back to its equivalent in the new render
		// (same data-focus-key), otherwise it would fall back to <body>.
		var active = document.activeElement;
		var focusKey = active && this.panelNode.contains(active) ? active.getAttribute('data-focus-key') : null;
		this.infoComponents = [];
		// Info bubble: changing screen closes it, with no animation (the whole
		// panel changes); a render of the same screen leaves it as it is.
		var hintStep = !blocked && !soon && this.step < steps.length ? this.hintStepId(steps[this.step]) : null;
		if (this.hint && this.hint.step !== hintStep) this.closeHint(false, true);
		if (this.tierHelp && this.tierHelp.step !== hintStep) this.closeTierHelp(false);
		this.hintStep = hintStep;
		this.hintComponents = hintStep ? steps[this.step].components : [];
		this.hintStepName = hintStep ? stepTitle(steps[this.step]) : '';
		if (blocked) {
			panel = this.renderBlocked(blocked);
		} else if (soon) {
			panel = this.renderComingSoon(soon);
		} else if (this.step < steps.length) {
			var key = this.stepKey(steps[this.step]);
			// Same screen as on the previous render: the component slots
			// stay in the DOM and are updated in place. That is what
			// lets the theme animate the appearance of a component (the
			// cassette next to the motor): an element recreated on every render
			// cannot have a CSS transition.
			if (key === this.panelKey && this.stepSection()) {
				this.patchComponentStep(steps[this.step], steps);
				panel = null;
			} else {
				panel = this.renderComponentStep(steps[this.step], steps);
			}
			this.panelKey = key;
			this.infoComponents = steps[this.step].components;
		} else {
			panel = this.renderSummary(steps);
		}
		if (blocked || soon || this.step >= steps.length) this.panelKey = null;

		if (panel) {
			// All the content is replaced, except the info bubble (the first
			// enfant, persistante).
			var hintNode = this.hintNode;
			Array.prototype.slice.call(this.panelNode.childNodes).forEach(function (child) {
				if (child !== hintNode) child.parentNode.removeChild(child);
			});
			if (hintNode.parentNode !== this.panelNode) this.panelNode.insertBefore(hintNode, this.panelNode.firstChild);
			this.panelNode.appendChild(panel);
		}
		if (focusKey && document.activeElement !== active) {
			var again = this.panelNode.querySelector('[data-focus-key="' + focusKey.replace(/"/g, '') + '"]');
			if (again) again.focus({ preventScroll: true });
		}
		this.updateStepHeading(blocked || soon ? null : (this.step < steps.length
			? { index: this.stepIndexText(steps), name: stepTitle(steps[this.step]) }
			: { index: null, name: t.summary }));
		this.refreshHint();
		this.refreshTierHelp();
		this.renderRecap(steps);
		if (!this.vignettes) this.renderInfo(this.infoComponents);
		// The open card is no longer on screen (another step, option hidden):
		// we forget its state.
		this.pruneInfo();
		if (this.step < steps.length && !blocked && !soon) this.autoOpenQuestions(steps[this.step]);
		this.syncQuestionDrawer();
		// “Next” depends on the open cards, known once the render is done.
		this.syncStepNav();
	};

	/**
	 * Left-hand column: one row per visible SCREEN, with its state, the resolved
	 * choices and the total in the footer. No extra request: everything comes from
	 * the /state already received.
	 *
	 * One row = one screen, not one component. The split comes from steps(), the
	 * same function as the right-hand panel: both columns therefore name and split
	 * the steps identically by construction, with no second grouping logic. A
	 * screen carrying several components (display_group) thus shows a single
	 * heading and as many values.
	 */
	Configurator.prototype.renderRecap = function (steps) {
		var self = this;
		var quote = this.quote || { line_items: [], price: {}, weight: {} };
		// Quote rows per component: several for a multiple choice.
		var byComponent = {};
		(quote.line_items || []).forEach(function (line) {
			(byComponent[line.component_id] = byComponent[line.component_id] || []).push(line);
		});

		// Rows: the journey as DISPLAYED (displaySteps(), the projection).
		// A screen of the real journey keeps its real index (navigation
		// unchanged); a screen that is only projected is not reached yet:
		// “To be chosen”, not clickable (an index out of reach of canGoToStep()).
		var realIndex = {};
		steps.forEach(function (step, index) { realIndex[stepId(step)] = index; });
		var rows = [];
		this.displaySteps().forEach(function (shown) {
			var index = realIndex[stepId(shown)];
			if (index === undefined) {
				rows.push(self.renderRecapRow(shown, byComponent, Infinity, 'is-todo'));
				return;
			}
			var step = steps[index];
			var state = index === self.step ? 'is-current' : (self.isStepDone(step) ? 'is-done' : 'is-todo');
			rows.push(self.renderRecapRow(step, byComponent, index, state));
		});

		// No “Next” to reach the summary (and adding to the cart):
		// it is the last row of the summary.
		var onSummary = this.step >= steps.length;
		// The same rule as the other rows: the summary is only reachable
		// once the screens have been gone through.
		var summaryReachable = this.canGoToStep(steps.length);
		var summaryInner = [
			el('span', { className: 'dahu-kit__recap-bullet', 'aria-hidden': true }),
			el('span', { className: 'dahu-kit__recap-text' }, [
				el('span', { className: 'dahu-kit__recap-label', text: t.summary })
			])
		];
		rows.push(el('li', {
			className: 'dahu-kit__recap-step dahu-kit__recap-step--summary'
				+ (onSummary ? 'is-current' : (this.validation.valid ? 'is-done' : 'is-todo'))
				+ (summaryReachable ? '' : ' is-locked')
		}, [
			summaryReachable
				? el('button', {
					type: 'button',
					className: 'dahu-kit__recap-link',
					'aria-current': onSummary ? 'step' : null,
					onclick: function () { self.goToStep(steps.length); }
				}, summaryInner)
				: el('span', { className: 'dahu-kit__recap-link dahu-kit__recap-link--locked' }, summaryInner)
		]));

		var total = el('div', { className: 'dahu-kit__recap-total' }, [
			el('span', { className: 'dahu-kit__recap-total-label', text: t.total }),
			el('span', { className: 'dahu-kit__recap-total-price price', html: quote.price && quote.price.html ? quote.price.html : '' }),
			quote.weight && quote.weight.unit
				? el('span', { className: 'dahu-kit__recap-total-weight', text: t.weight + ' : ' + formatWeight(quote.weight.total, quote.weight.unit) })
				: null,
			// Mention from the theme (the dahu_composite_recap_note filter), after the
			// weight; absent when empty.
			t.recapNote ? el('span', { className: 'dahu-kit__recap-total-note', text: t.recapNote }) : null
		]);

		var currentStep = steps[this.step];
		var currentLabel = currentStep ? stepTitle(currentStep) : t.summary;

		var aside = this.recapNode;
		aside.className = 'dahu-kit__recap' + (this.recapOpen ? ' is-expanded' : '');
		aside.innerHTML = '';
		// Bandeau compact : visible sous 64em seulement (CSS).
		aside.appendChild(el('button', {
			type: 'button',
			className: 'dahu-kit__recap-toggle',
			'aria-expanded': this.recapOpen ? 'true' : 'false',
			onclick: function () { self.recapOpen = !self.recapOpen; self.render(); }
		}, [
			el('span', { className: 'dahu-kit__recap-toggle-step', text: currentLabel }),
			el('span', { className: 'dahu-kit__recap-toggle-price price', html: quote.price && quote.price.html ? quote.price.html : '' }),
			el('span', { className: 'dahu-kit__recap-toggle-icon', 'aria-hidden': true })
		]));
		aside.appendChild(el('div', { className: 'dahu-kit__recap-body' }, [
			el('p', { className: 'dahu-kit__recap-title', text: t.recap }),
			el('ol', { className: 'dahu-kit__recap-steps' }, rows),
			total
		]));

		return aside;
	};

	/**
	 * Third column, always shown: photo of the product at the top, then its short
	 * description (the `description` field of the schema: the content specific to
	 * the kit when it is filled in, else the short description of the product).
	 *
	 * Product shown: focusedOption() — the card that was clicked (preview), else
	 * the last one selected, else the first one chosen on the screen. Nothing
	 * chosen: an empty column (the description of the components lives in the
	 * screen itself, see renderComponentBlock()).
	 *
	 * @param {Array} components Components of the current screen (empty on the
	 *                           summary, a dead end or “coming soon”).
	 */
	Configurator.prototype.renderInfo = function (components) {
		var node = this.infoNode;
		var focused = this.focusedOption(components);
		var children = [];

		if (focused) {
			var src = optionImage(focused.option, focused.sel);
			if (src) {
				children.push(el('figure', { className: 'dahu-kit__info-media' }, [
					el('img', { className: 'dahu-kit__info-photo', src: src, alt: focused.option.name || '' })
				]));
			}
			children.push(el('p', { className: 'dahu-kit__info-name', text: focused.option.name }));
			// Product option: short description or specifications, as
			// chosen on the icon bar. A branch has no
			// product, and therefore only its description.
			if (focused.option.product_id) {
				children = children.concat(this.renderInfoTabs(focused.option));
			} else if (focused.option.description) {
				children.push(el('div', { className: 'dahu-kit__info-text dahu-kit__description', html: focused.option.description }));
			}
		}
		// Nothing chosen: an empty column. The description of the components of
		// of the screen used to be there; it has since moved into
		// the screen itself (renderComponentBlock()), in every style —
		// keeping it here would show it twice in “card” style.

		node.innerHTML = '';
		// Content in a container of its own: the column takes the whole
		// height of the grid, and its content stays sticky (functional
		// fonctionnelle).
		node.appendChild(el('div', { className: 'dahu-kit__info-inner' }, children));
		// The column stays in place even when empty (summary, dead end): the grid
		// does not jump from one screen to the next.
		node.className = 'dahu-kit__info' + (children.length ? '' : ' is-empty');
	};

	/**
	 * Right-hand column, product option: the text (short description or
	 * specifications, in the same container) then the icon bar — Description,
	 * Specifications, and on the right the “products supplied with” key,
	 * decorative for now.
	 *
	 * The specifications come from GET /product-tabs/{id} (the
	 * “caracteristiques” tab, shared with the drawer through productTabs()). The
	 * request goes out as soon as the product is shown, so that the click is
	 * immediate; when it answers, only the column is redrawn.
	 *
	 * @param {Object} option Product option being shown.
	 * @return {Array} Nodes to add under the name.
	 */
	Configurator.prototype.renderInfoTabs = function (option) {
		var self = this;
		var productId = option.product_id;
		if (this.infoProduct !== productId) {
			this.infoProduct = productId;
			this.infoTab = 'description';
		}

		// The same loading as the cards (loadSpecs()): after a failure, no
		// new request on every render — the error response redrew
		// the column, which fired the request again, indefinitely. A new
		// attempt happens only on a click on the icon.
		var current = this.loadSpecs(productId);
		var characteristics = current.status === 'ok' ? current.tab : null;
		var noCharacteristics = current.status === 'ok' && !characteristics;
		if (noCharacteristics && this.infoTab === 'caracteristiques') this.infoTab = 'description';

		var nodes = [];
		if (this.infoTab === 'caracteristiques') {
			var content;
			if (characteristics) content = { html: characteristics.html };
			else if (current.status === 'error') content = { text: t.detailsError };
			else content = { text: t.infoLoading };
			nodes.push(el('div', Object.assign({
				className: 'dahu-kit__info-text dahu-kit__description dahu-kit__info-text--caracteristiques'
			}, content)));
		} else if (option.description) {
			var described = el('div', { className: 'dahu-kit__info-text dahu-kit__description', html: option.description });
			// “more info…” following the description.
			var more = moreInfoLink(option);
			if (more) described.appendChild(el('p', { className: 'dahu-kit__more-info' }, [more]));
			nodes.push(described);
		}

		function tool(tab, label, disabled) {
			var active = self.infoTab === tab;
			return el('button', {
				type: 'button',
				className: 'dahu-kit__info-tool dahu-kit__info-tool--' + tab + (active ? ' is-active' : ''),
				'aria-pressed': active ? 'true' : 'false',
				'aria-label': label,
				title: label,
				disabled: !!disabled,
				html: INFO_ICONS[tab],
				onclick: function () {
					var retry = tab === 'caracteristiques' && self.infoTabsState[productId] && self.infoTabsState[productId].status === 'error';
					if (retry) self.loadSpecs(productId, true);
					else if (self.infoTab === tab) return;
					self.infoTab = tab;
					self.renderInfo(self.infoComponents);
				}
			});
		}

		nodes.push(el('div', { className: 'dahu-kit__info-tools', role: 'toolbar', 'aria-label': option.name || '' }, [
			tool('description', t.infoDescription, !option.description),
			tool('caracteristiques', noCharacteristics ? t.infoNoCharacteristics : t.infoCharacteristics, noCharacteristics),
			// Products supplied with: to come. Purely decorative — not a
			// button, outside the tab order and out of reach of screen readers.
			// Only when the option has included products.
			(option.bundled || []).length ? el('span', {
				className: 'dahu-kit__info-tool dahu-kit__info-tool--fournis is-soon',
				title: t.infoIncluded,
				'aria-hidden': 'true',
				html: INFO_ICONS.fournis
			}) : null
		]));
		return nodes;
	};

	/**
	 * Value chosen for ONE component, with its price: the content of a summary row
	 * when the screen has a single component, one row among others when it carries
	 * several. Multiple choice: one value (.dahu-kit__recap-head) per option
	 * chosen; nothing chosen: the same empty value as a radio component.
	 *
	 * @param {Object} component Component.
	 * @param {Array}  lines     Quote rows of the component (may be missing).
	 * @return {Node|Node[]}
	 */
	Configurator.prototype.renderRecapValue = function (component, lines, perLine) {
		var self = this;
		lines = lines || [];
		if (isMultiple(component)) {
			var items = selectionItems(this.selections[component.id]);
			if (items.length) {
				return items.map(function (item) {
					var line = null;
					lines.forEach(function (l) { if (l.product_id === item.product_id) line = l; });
					return self.renderRecapItem(component, item, line, perLine);
				});
			}
		}
		return this.renderRecapItem(component, this.selections[component.id], lines.length ? lines[lines.length - 1] : null, perLine);
	};

	/** One value of the summary: a chosen item (or nothing) and its quote row. */
	Configurator.prototype.renderRecapItem = function (component, selection, line, perLine) {
		var value = null;
		var priceHtml = '';

		if (isPath(component)) {
			component.options.forEach(function (o) {
				if (selection && o.id === selection.option_id) value = o.name;
			});
		} else if (selection) {
			// Label already resolved by the server (the title specific to the kit
			// when it is filled in, else that of the product).
			value = line ? line.name + (line.quantity > 1 ? ' × ' + line.quantity : '') : null;
			priceHtml = line ? line.price_html : '';

			// No row yet: the choice has just been made and /state has not
			// answered. The name of the option is already in the schema loaded
			// in memory — no reason to wait for the network to show it,
			// nor make the customer believe they have chosen nothing. Only the price
			// reste en attente.
			if (!value) {
				component.options.forEach(function (o) {
					if (o.product_id === selection.product_id) value = o.name;
				});
			}

			// Variant chosen: its label ALONE (“142mm”), without
			// the name of the parent product — the row is already under the title of
			// the step. Label from the schema (variations[].name); not found:
			// the full label, as before.
			if (selection.variation_id) {
				var variant = null;
				component.options.forEach(function (o) {
					if (o.product_id !== selection.product_id) return;
					(o.variations || []).forEach(function (v) {
						if (v.variation_id === selection.variation_id) variant = v;
					});
				});
				if (variant && variant.name) {
					var quantity = line ? line.quantity : selection.quantity;
					value = variant.name + (quantity > 1 ? ' × ' + quantity : '');
				}
			}
		}

		return el('span', { className: 'dahu-kit__recap-head' }, [
			value
				? el('span', { className: 'dahu-kit__recap-value', text: value })
				: el('span', { className: 'dahu-kit__recap-value dahu-kit__recap-value--empty', text: component.optional ? t.none : t.toChoose }),
			// Price per row: only when there is no price per screen (renderRecapRow()).
			perLine && priceHtml ? el('span', { className: 'dahu-kit__recap-price price', html: priceHtml }) : null
		]);
	};

	/**
	 * One summary row = one SCREEN: a heading, then one value per component of
	 * that screen. “Screen + Button” therefore fits on a single row, carrying both
	 * choices.
	 *
	 * @param {Object} step        Screen, as steps() returns it.
	 * @param {Object} byComponent Quote rows keyed by component.
	 * @param {number} stepIndex   Position of the screen.
	 * @param {string} state       is-current / is-done / is-todo.
	 */
	Configurator.prototype.renderRecapRow = function (step, byComponent, stepIndex, state) {
		var self = this;

		var body = [el('span', { className: 'dahu-kit__recap-label', text: stepTitle(step) })];
		// A single price per screen: the sum of the items chosen on
		// the screen (screen_prices, computed and rendered by the server), instead
		// of one price per component — “Motor” + cassette took four
		// price rows for a professional account. A state with no screen_prices
		// (dropped in by an earlier version): one price per row, as before.
		var prices = (this.quote || {}).screen_prices;
		var perLine = !prices;
		var values = [];
		var primaries = step.components.filter(function (c) { return c.title_role !== 'secondary'; });
		step.components.forEach(function (c) {
			// Supporting component (add-on, question) with nothing chosen, beside
			// of a main choice: no extra “None” row.
			if (!perLine && c.title_role === 'secondary' && primaries.length && !self.hasSelection(c.id)) return;
			values = values.concat(self.renderRecapValue(c, byComponent[c.id], perLine));
		});
		var sum = prices ? prices[stepId(step)] : null;
		if (sum && sum.html) {
			var price = el('span', { className: 'dahu-kit__recap-price price', html: sum.html });
			// A single value: the price to the right of its name, as before.
			// Several: the names, then the price of the screen on its own row.
			if (values.length === 1) values[0].appendChild(price);
			else values.push(el('span', { className: 'dahu-kit__recap-head dahu-kit__recap-head--sum' }, [price]));
		}
		body = body.concat(values);

		// Strictly sequential navigation: one may go back to a screen
		// already reached, never jump to one that has not been. A
		// screen out of reach stays listed, with its state, but is not a
		// button — no cursor, no handler, no keyboard target.
		var reachable = this.canGoToStep(stepIndex);

		var inner = [
			// State bullet only: the photo of the choice is in the
			// column, not as a vignette in the list.
			el('span', { className: 'dahu-kit__recap-bullet', 'aria-hidden': true }),
			el('span', { className: 'dahu-kit__recap-text' }, body)
		];

		var link = reachable
			? el('button', {
				type: 'button',
				className: 'dahu-kit__recap-link',
				'aria-current': state === 'is-current' ? 'step' : null,
				onclick: function () {
					// Pure navigation: the selection state does not change, so
					// there is nothing to revalidate. Any change made once on
					// place repasse par select() → /state.
					self.goToStep(stepIndex);
				}
			}, inner)
			: el('span', { className: 'dahu-kit__recap-link dahu-kit__recap-link--locked' }, inner);

		return el('li', { className: 'dahu-kit__recap-step' + state + (reachable ? '' : ' is-locked') }, [link]);
	};

	/** A step is done when all of its components are valid and filled in. */
	Configurator.prototype.isStepDone = function (step) {
		var self = this;
		return step.components.every(function (c) {
			return self.isComponentReady(c.id) && (self.hasSelection(c.id) || c.optional);
		});
	};

	/** Dead end: message from the server + resume at the step it names. */
	Configurator.prototype.renderBlocked = function (blocked) {
		var self = this;
		var children = [el('div', { className: 'dahu-kit__blocked-text', html: blocked.message || '' })];
		var target = blocked.restart_component && this.component(blocked.restart_component);
		if (target) {
			children.push(el('div', { className: 'dahu-kit__buttons' }, [el('button', {
				type: 'button',
				className: 'button alt',
				text: t.restart + ' : ' + target.title,
				onclick: function () { self.restartAt(target.id); }
			})]));
		}
		return el('section', { className: 'dahu-kit__step dahu-kit__step--blocked' }, children);
	};

	/** Branch option announced but not open for sale yet. */
	Configurator.prototype.renderComingSoon = function (soon) {
		var self = this;
		return el('section', { className: 'dahu-kit__step dahu-kit__step--coming-soon' }, [
			el('h2', { className: 'dahu-kit__step-title', text: soon.option.name }),
			el('p', { className: 'dahu-kit__coming-soon', text: t.comingSoon }),
			soon.option.description ? el('div', { className: 'dahu-kit__option-description', html: soon.option.description }) : null,
			el('div', { className: 'dahu-kit__buttons' }, [el('button', {
				type: 'button',
				className: 'button',
				text: t.restart + ' : ' + soon.component.title,
				onclick: function () { self.restartAt(soon.component.id); }
			})])
		]);
	};

	/**
	 * One screen = one or several components (display_group). Every component
	 * keeps its own block, its own errors and its own validation.
	 */
	/**
	 * “Step 2 / 7” (the summary counts as a step). Total and position on the
	 * journey as DISPLAYED (displaySteps()), like the summary: on arrival the
	 * counter announces the full journey that is expected, not “1 / 2”.
	 *
	 * @param {Array} steps Real journey (steps()).
	 */
	Configurator.prototype.stepIndexText = function (steps) {
		var shown = this.displaySteps();
		var total = shown.length + 1;
		if (this.step >= steps.length) return t.step + ' ' + total + ' / ' + total;
		var id = stepId(steps[this.step]);
		var position = this.step;
		for (var i = 0; i < shown.length; i++) {
			if (stepId(shown[i]) === id) { position = i; break; }
		}
		return t.step + ' ' + (position + 1) + ' / ' + total;
	};


	/**
	 * Title of the step in the product title row (for a theme that hands over its
	 * title, [data-dahu-kit-title]). null = nothing to show (dead end, “coming
	 * soon”: their panel carries its own title). With no title row, the screens
	 * keep their title at the top of the panel.
	 *
	 * @param {{index: string|null, name: string}|null} heading
	 */
	Configurator.prototype.updateStepHeading = function (heading) {
		var node = this.stepHeadingNode;
		if (!node) return;
		node.hidden = !heading;
		if (heading) this.fillStepTitle(node, heading.index, heading.name);
	};

	/**
	 * Fills a step title (the title row, or the h2 at the top of the panel) IN
	 * PLACE: number (.dahu-kit__step-index), the “i” of the info bubble of the
	 * screen (hintButton('step'), only when a component of the screen has an
	 * `info` text), name (.dahu-kit__step-name). The nodes are kept from one
	 * render to the next: the “i” does not lose focus.
	 */
	Configurator.prototype.fillStepTitle = function (node, index, name) {
		var indexNode = node.querySelector(':scope > .dahu-kit__step-index') || el('span', { className: 'dahu-kit__step-index' });
		var nameNode = node.querySelector(':scope > .dahu-kit__step-name') || el('span', { className: 'dahu-kit__step-name' });
		if (indexNode.textContent !== (index || '')) indexNode.textContent = index || '';
		if (nameNode.textContent !== name) nameNode.textContent = name;
		var button = null;
		if (this.hintComponents.some(function (c) { return hasText(c.info); })) {
			if (!this.hintStepButton) this.hintStepButton = this.hintButton('step', '');
			this.hintStepButton.setAttribute('aria-label', hintLabel(this.hintStepName));
			button = this.hintStepButton;
		}
		// Small loading circle, after the name: a single node,
		// visible seulement sous .is-advancing (feuille fonctionnelle).
		if (!this.stepLoadingNode) {
			this.stepLoadingNode = el('span', { className: 'dahu-kit__step-loading', 'aria-hidden': 'true' }, [
				el('span', { className: 'dahu-kit__spinner' })
			]);
		}
		syncChildren(node, [index ? indexNode : null, button, nameNode, this.stepLoadingNode].filter(Boolean));
	};

	/** Section of the screen being shown (last child of the panel, after the bubble), or null. */
	Configurator.prototype.stepSection = function () {
		var last = this.panelNode.lastElementChild;
		return last && last !== this.hintNode ? last : null;
	};

	/**
	 * Identity of a screen for the rendering: same key = same screen, updated in
	 * place. Index, group, display mode and choice gesture (a change of mode
	 * rebuilds everything).
	 */
	Configurator.prototype.stepKey = function (step) {
		return [this.step, step.group || step.components[0].id, this.vignettes ? 'v' : 'd', this.selectButton ? 'b' : 'c'].join('|');
	};

	/**
	 * Components for which the screen reserves a slot: ALL those of the display
	 * group, hidden ones included (steps() keeps only the visible ones). A
	 * component hidden by a scenario therefore keeps its place in the DOM, in
	 * .is-hidden, and can appear with a transition. Screen with no group: its own
	 * components only.
	 */
	Configurator.prototype.slotComponents = function (step) {
		if (!step.group) return step.components;
		return this.schema.components.filter(function (c) { return (c.display_group || '') === step.group; });
	};

	/**
	 * One screen = one or several components (display_group). Every component has
	 * its own stable slot (.dahu-kit__slot), filled by fillSlot(); it keeps its
	 * own block, its own errors and its own validation.
	 */
	Configurator.prototype.renderComponentStep = function (step, steps) {
		var multiple = step.components.length > 1;

		// Title of the step at the top of the panel only when it is not already
		// in the product title row (updateStepHeading()).
		var title = null;
		if (!this.stepHeadingNode) {
			title = el('h2', { className: 'dahu-kit__step-title' });
			this.fillStepTitle(title, this.stepIndexText(steps), stepTitle(step));
		}
		var body = [title];

		var slots = {};
		this.slotComponents(step).forEach(function (component) {
			slots[component.id] = el('div', { 'data-component': component.id });
		});
		// “Next” row: created before the slots, renderCards()
		// places it at the end of the option grid (placeStepNav()).
		var nav = this.stepNavNode = el('div', {});
		this.renderStepNav(nav, step);
		this.prepareStepNav(step);
		// An anchored add-on is already in the option list of its anchor
		// component: it is not a direct child of the screen.
		var anchored = this.fillSlots(slots, step);
		this.slotComponents(step).forEach(function (component) {
			if (!anchored[component.id]) body.push(slots[component.id]);
		});

		// No grid to hold it: the earlier place, under the screen.
		if (!this.stepNavPlaced) body.push(nav);

		return el('section', {
			className: 'dahu-kit__step' + (multiple ? ' dahu-kit__step--grouped' : ''),
			'data-component': step.components[0].id
		}, body);
	};

	/** Same screen as on the previous render: every slot is updated in place. */
	Configurator.prototype.patchComponentStep = function (step, steps) {
		var section = this.stepSection();
		var multiple = step.components.length > 1;
		section.className = 'dahu-kit__step' + (multiple ? ' dahu-kit__step--grouped' : '');
		// Title at the top of the panel (with no title row): the “i” follows the
		// visible components of the screen.
		var title = section.querySelector(':scope > .dahu-kit__step-title');
		if (title && !this.stepHeadingNode) this.fillStepTitle(title, this.stepIndexText(steps), stepTitle(step));
		// The slot is looked for across the whole screen, not only among its
		// direct children: an anchored add-on lives in the option list of its
		// anchor component.
		var slots = {};
		this.slotComponents(step).forEach(function (component) {
			var slot = section.querySelector('.dahu-kit__slot[data-component="' + component.id + '"]');
			if (slot) slots[component.id] = slot;
		});
		var nav = this.stepNavNode || section.querySelector('.dahu-kit__step-nav');
		if (nav) this.renderStepNav(nav, step);
		this.prepareStepNav(step);
		var anchored = this.fillSlots(slots, step);
		// Direct children of the screen: title, unanchored slots, the
		// “Next” row. An add-on that loses its anchor card comes back here, to its
		// usual place; nothing else moves.
		var top = [title];
		this.slotComponents(step).forEach(function (component) {
			if (slots[component.id] && !anchored[component.id]) top.push(slots[component.id]);
		});
		if (!this.stepNavPlaced) top.push(nav);
		reconcileChildren(section, top.filter(Boolean));
	};

	/**
	 * Fills the slots of a screen (fillSlot()) and places the anchored add-ons
	 * (DahuCompositeTiers.anchors()): every component receives the slots of the
	 * add-ons anchored to one of its cards, which renderCards() inserts right
	 * after that card. Recomputed on every render, from the state: nothing is
	 * moved afterwards.
	 *
	 * An add-on is placed even when hidden (.is-hidden): its disappearance and
	 * reappearance animate beside the card. Card not found (option hidden, anchor
	 * component absent from the screen or hidden, configuration in error): the
	 * add-on stays in its usual place, a direct child of the screen — with a
	 * console warning when it is visible.
	 *
	 * @param {Object} slots Slots keyed by component id.
	 * @param {Object} step  Screen.
	 * @return {Object} Ids of the add-ons placed in an option list.
	 */
	Configurator.prototype.fillSlots = function (slots, step) {
		var self = this;
		var components = this.slotComponents(step);
		var byHost = {};
		var entries = window.DahuCompositeTiers.anchors(components).filter(function (entry) {
			if (!slots[entry.component.id]) return false;
			// CHOSEN card (anchoring to the component alone, or implicit):
			// resolved from the selections. Nothing chosen: the usual place,
			// with no warning.
			if (!entry.productId) {
				entry.productId = window.DahuCompositeTiers.selectedProductId(self.selections, entry.hostId);
				if (!entry.productId) return false;
			}
			entry.slot = slots[entry.component.id];
			entry.placed = false;
			entry.inside = false;
			(byHost[entry.hostId] = byHost[entry.hostId] || []).push(entry);
			return true;
		});
		components.forEach(function (component) {
			if (slots[component.id]) self.fillSlot(slots[component.id], component, step, byHost[component.id] || []);
		});
		var placed = {};
		entries.forEach(function (entry) {
			// Class set after fillSlot(), which rewrites className.
			entry.slot.classList.toggle('dahu-kit__component--anchored', entry.placed);
			// Question asked INSIDE the card (renderCards()).
			entry.slot.classList.toggle('dahu-kit__component--in-card', entry.placed && entry.inside);
			if (entry.placed) {
				placed[entry.component.id] = true;
			} else if (step.components.indexOf(entry.component) !== -1) {
				self.warnAnchor(entry);
			}
		});
		return placed;
	};

	/**
	 * Visible add-on whose anchor card cannot be found: once per add-on and per
	 * page load, so that a configuration in error shows up without flooding the
	 * console on every render.
	 */
	Configurator.prototype.warnAnchor = function (entry) {
		this.anchorWarned = this.anchorWarned || {};
		if (this.anchorWarned[entry.component.id]) return;
		this.anchorWarned[entry.component.id] = true;
		if (window.console && window.console.warn) {
			window.console.warn('[dahu-composite] Add-on “' + entry.component.id + '” anchored to “' + entry.hostId
				+ '” / product #' + entry.productId + ' : card not found on this screen. Shown in its usual place.');
		}
	};

	/**
	 * Complete screen carrying a multiple-choice component: the options are added
	 * without moving things on, so “Next” is always offered — the only way to move
	 * on, since there is no “None” card.
	 */
	Configurator.prototype.pendingMultiple = function (step) {
		return step.components.some(isMultiple) && this.isStepDone(step);
	};

	/**
	 * Place of the “Next” row: at the end of the option grid of the LAST visible
	 * product component of the screen (neither a branch nor an add-on), right
	 * next to the last card — no longer at the edge of the screen. renderCards()
	 * puts it there (stepNavPlaced); with no host component it stays under the
	 * screen, as before. Always present, .is-visible / .is-hidden: a node already
	 * in place is never moved.
	 */
	Configurator.prototype.prepareStepNav = function (step) {
		var host = null;
		step.components.forEach(function (c) {
			if (!isPath(c) && !isAddon(c) && c.options.length) host = c.id;
		});
		this.stepNavHost = host;
		this.stepNavPlaced = false;
	};

	/**
	 * “Next” row: the same button as the former manual navigation
	 * (.dahu-kit__buttons) and the same path as “continue” (continueFrom() →
	 * goNext()). Always present in the screen, .is-visible / .is-hidden like the
	 * component slots; the button is never recreated.
	 */
	Configurator.prototype.renderStepNav = function (nav, step) {
		var self = this;
		// Only where “Next” is the only way to move on —
		// a multiple choice (Accessories). Elsewhere, the chosen card
		// (“Select”) or the answer given already move things on; earlier,
		// pendingAddon() also showed it on coming back to a screen whose
		// question had been answered “No”, or whose card flipped onto the
		// question had been closed again.
		var show = this.pendingMultiple(step);
		nav.className = 'dahu-kit__buttons dahu-kit__step-nav ' + (show ? 'is-visible' : 'is-hidden');
		if (show) {
			nav.removeAttribute('aria-hidden');
			nav.inert = false;
		} else {
			nav.setAttribute('aria-hidden', 'true');
			nav.inert = true;
		}
		if (!nav.firstChild) {
			nav.appendChild(el('button', {
				type: 'button',
				className: 'button alt dahu-kit__next',
				'data-focus-key': 'step:next',
				text: t.next,
				onclick: function () { self.continueFrom(); }
			}));
		}
	};

	/**
	 * Slot of a component: ALWAYS present for the screen being shown. It is the
	 * .dahu-kit__component[data-component] element itself — the structure the
	 * themes expect (section > .dahu-kit__component) does not change, the element
	 * is simply kept from one render to the next and only its content is replaced.
	 * Exception: an anchored add-on (.dahu-kit__component--anchored) lives in the
	 * option list of its anchor component, right after the card it targets
	 * (fillSlots()).
	 *
	 * .is-visible or .is-hidden according to the scenarios. Hidden, it keeps its
	 * last content (an animated disappearance still has something to show), drops
	 * out of the accessibility tree and out of keyboard navigation (aria-hidden +
	 * inert). The visual effect is the theme's business; the functional stylesheet
	 * only does display: none.
	 *
	 * “Add-on” component: persistent content (addonBlock()), never recreated — so
	 * its button can animate its own change of state too.
	 *
	 * anchored: add-ons anchored to a card of this component (fillSlots()).
	 * The content is reconciled, not replaced: a list that already contains an
	 * anchored add-on is kept (renderCards()), so the add-on is never removed and
	 * put back — it would otherwise keep its transitions and replay its appearance
	 * (@starting-style) on every render.
	 */
	Configurator.prototype.fillSlot = function (slot, component, step, anchored) {
		var visible = step.components.indexOf(component) !== -1;
		var secondary = component.title_role === 'secondary';
		// Yes / no question: an add-on, but neither its card nor its class
		// (.dahu-kit__component--addon: a card animated by the themes). Not
		// asked yet (questionWaiting()): hidden as if by a scenario.
		var question = isQuestion(component);
		var addon = isAddon(component) && !question;
		if (visible && question && this.questionWaiting(component, step)) visible = false;
		slot.className = 'dahu-kit__component dahu-kit__slot'
			+ (isPath(component) ? ' dahu-kit__component--path' : '')
			+ (addon ? ' dahu-kit__component--addon' : '')
			+ (question ? ' dahu-kit__component--question' : '')
			+ (secondary ? ' dahu-kit__component--secondary' : '')
			+ (displayStyle(component) ? ' dahu-kit__component--style-' + displayStyle(component) : '')
			+ (visible ? ' is-visible' : ' is-hidden');
		if (visible) {
			slot.removeAttribute('aria-hidden');
			slot.inert = false;
		} else {
			slot.setAttribute('aria-hidden', 'true');
			slot.inert = true;
			return;
		}

		if (addon) {
			var parts = this.addonBlock(component);
			// Already in place: nothing to move (a node removed and put back
			// would lose its transition).
			if (parts[0].parentNode !== slot) slot.replaceChildren.apply(slot, parts);
			return;
		}
		if (question) {
			slot.replaceChildren.apply(slot, this.questionBlock(component));
			return;
		}
		// Component title only when there are several components
		// “primary” on the screen (Screen + Button); never for a
		// “secondary” one, which its context is enough to place.
		var primaries = step.components.filter(function (c) { return c.title_role !== 'secondary'; });
		var withHeading = !secondary && primaries.length > 1;
		reconcileChildren(slot, this.renderComponentBlock(component, withHeading, anchored || []));
	};

	Configurator.prototype.renderComponentBlock = function (component, withHeading, anchored) {
		var cid = component.id;
		var sel = this.selections[cid] || null;
		var hiddenList = (this.validation.hidden_options || {})[cid] || [];
		var body = [];

		if (withHeading) {
			body.push(el('h3', { className: 'dahu-kit__component-title', text: component.title }));
		}
		// Description of the COMPONENT (the admin “Description (HTML allowed)”
		// field — not to be confused with option.description): under its
		// title, above ITS cards. In a merged screen
		// (display_group) every component carries its own. HTML already filtered
		// on saving (wp_kses_post, sanitize_components()), injected
		// like the other admin content. Empty: no container.
		// It used to be shown in the right-hand column only
		// (“card” style), as long as nothing was chosen — and therefore
		// never in “vignette” style. “Add-on” component: its own block
		// (addonBlock()), which is not concerned.
		if (component.description) {
			body.push(el('div', { className: 'dahu-kit__step-description dahu-kit__description', html: component.description }));
		}

		// Branch: no price, no weight, no variant — only a choice.
		if (isPath(component)) {
			var pathOptions = component.options.filter(function (o) { return hiddenList.indexOf(o.id) === -1; });
			body.push(this.renderCards(component, pathOptions, sel, hiddenList));
			body.push(this.renderErrors(cid));
			return body.filter(Boolean);
		}

		var options = component.options.filter(function (o) { return !isHidden(o.product_id, null, hiddenList); });
		var selectedOption = null;
		if (sel && !isMultiple(component)) options.forEach(function (o) { if (o.product_id === sel.product_id) selectedOption = o; });

		body.push(this.renderCards(component, options, sel, hiddenList, anchored));

		// Multiple choice: items of a bundle, for every option chosen.
		if (isMultiple(component)) {
			var self = this;
			selectionItems(sel).forEach(function (item) {
				options.forEach(function (o) {
					if (o.product_id !== item.product_id || !o.bundle_items) return;
					var block = self.renderBundleItems(component, o, item);
					if (block) body.push(block);
				});
			});
		}

		if (selectedOption && selectedOption.bundle_items) {
			var bundleBlock = this.renderBundleItems(component, selectedOption, sel);
			if (bundleBlock) body.push(bundleBlock);
		}
		// Quantity field only when the administrator has explicitly made it
		// adjustable.
		if (sel && component.quantity_editable && component.quantity_max > component.quantity_min) {
			body.push(this.renderQuantity(component, sel));
		}

		body.push(this.renderErrors(cid));

		// Content only: the .dahu-kit__component wrapper is
		// the persistent slot (fillSlot()).
		return body.filter(Boolean);
	};

	/**
	 * Sections of a component per category (range): a single implementation,
	 * shared with the administration simulator (dahu-composite-tiers.js). Order
	 * and headings = component.tiers.
	 */
	function groupByTier(component, options) {
		return window.DahuCompositeTiers.sections(component, options);
	}

	/**
	 * Heading of a section: h4.dahu-kit__tier > span.dahu-kit__tier-label. A
	 * category with a bubble text or a media: followed, inside the title, by its
	 * “?” (.has-hint), which opens the popover of the range (it used to be an
	 * “i” and a panel under the header). The description is not rendered in plain
	 * sight under the title.
	 */
	Configurator.prototype.tierHeading = function (component, tier) {
		var help = tierHasHelp(tier);
		return [
			el('h4', { className: 'dahu-kit__tier' + (help ? ' has-hint' : '') }, [
				el('span', { className: 'dahu-kit__tier-label', text: tier.label }),
				help ? this.tierHelpButton(component, tier) : null
			])
		];
	};

	/**
	 * Range of an option and its colour, for the badge and the tinted background
	 * of the cards (the tier_badge / tier_tint settings of the component):
	 * { tier, color, tint, ink } or null (option with no range, “None”).
	 * tint: light background from the palette ('' for a code outside it).
	 */
	Configurator.prototype.optionTier = function (component, option) {
		if (!option || !option.tier || !(component.tier_badge || component.tier_tint)) return null;
		var found = null;
		(component.tiers || []).forEach(function (tier) { if (tier.id === option.tier) found = tier; });
		if (!found) return null;
		var color = window.DahuCompositeTiers.tierColor(this.schema.components, found);
		return { tier: found, color: color, tint: window.DahuCompositeTiers.tierTint(color), ink: tierInk(color) };
	};

	/** Text readable on a #rrggbb background: black on a light one, white otherwise (WCAG luminance). */
	function tierInk(hex) {
		var channel = function (i) {
			var c = parseInt(hex.substr(i, 2), 16) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
		};
		var luminance = 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);
		// Contrast with white ≥ contrast with black ⇔ luminance < 0.179.
		return luminance < 0.179 ? '#ffffff' : '#000000';
	}

	/**
	 * Range with a “?” popover: a bubble text or a media. Now, any range as long
	 * as a waiting text exists (helpPlaceholder, empty by default): the popover of
	 * an empty range shows that instead of nothing.
	 */
	function tierHasHelp(tier) {
		return !!tier && (hasText(tier.description) || !!(tier.media && tier.media.html) || !!t.helpPlaceholder);
	}

	/**
	 * Empty row above the following vignettes of a range (the same height as the
	 * title). When a range of the component has an “i”, the row carries a ghost
	 * one (invisible, out of accessibility): the same height whatever the drawing
	 * of the button.
	 */
	function tierSpacer(withHint) {
		if (!withHint) return el('span', { className: 'dahu-kit__tier dahu-kit__tier--spacer', 'aria-hidden': 'true', text: ' ' });
		return el('span', { className: 'dahu-kit__tier dahu-kit__tier--spacer' + (withHint ? ' has-hint' : ''), 'aria-hidden': 'true' }, [
			el('span', { className: 'dahu-kit__tier-label', text: ' ' }),
			withHint ? el('span', { className: 'dahu-kit__tier-help dahu-kit__tier-help--placeholder', html: HELP_ICON }) : null
		]);
	}


	/** Maximum length of the text of a vignette. */
	var EXCERPT_MAX = 250;

	/**
	 * Plain text of an HTML description, cut at `max` characters (on a word when
	 * possible, with “…”). DOMParser: the HTML is parsed without being executed or
	 * loaded (no image, no handler).
	 */
	function excerpt(html, max) {
		var doc = new DOMParser().parseFromString(html, 'text/html');
		var text = (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
		if (text.length <= max) return text;
		var cut = text.slice(0, max - 1);
		var space = cut.lastIndexOf(' ');
		if (space > max * 0.6) cut = cut.slice(0, space);
		return cut.replace(/[\s,;:.–-]+$/, '') + '…';
	}

	/**
	 * Option cards, for the “path” components as well as for the product
	 * components with an EXCLUSIVE CHOICE; “None” closes the list of an optional
	 * component (outside an “add-on” component). (“Add-on” component:
	 * addonBlock().)
	 *
	 * The whole card selects: no “Select” button. A click anywhere on the card →
	 * choose() (or chooseNone(), selectPath()), which moves on as soon as the
	 * screen is complete; on the option already chosen → continueFrom(). Only the
	 * card's own controls (variant list, “More info”) keep their own action.
	 *
	 * Button mode (readSelectMode()): the same action carried by a “Select” button
	 * (.dahu-kit__choose, aria-pressed) in .dahu-kit__option-actions; a click
	 * elsewhere on a square card → showPreview() (.is-previewed), without
	 * choosing. The .dahu-kit__option-hit area is then no longer a button: the
	 * keyboard goes through “Select”.
	 *
	 * Keyboard (default mode): the main area of the card (.dahu-kit__option-hit:
	 * photo, name, price, text) is a button — role="button", tabindex="0",
	 * aria-pressed, activated on Enter and Space. The controls stay BESIDE that
	 * area, not inside it: a button role makes its descendants unreachable for
	 * screen readers, and a variant list inside would have become unusable.
	 *
	 * Two presentations (readMode()):
	 *  - by default, a SQUARE card of fixed size: name, price, variant row (empty
	 *    but kept when there is none);
	 *  - vignettes: the photo of the product at the top, then the same content,
	 *    plus the subtitle of the product (the `subtitle` field of the schema,
	 *    supplied by the theme; for a branch, its description), EXCERPT_MAX
	 *    characters at most, and “More info” (the detail drawer, product options
	 *    only).
	 *
	 * The variant is chosen inside the card, even before selecting the option:
	 * product and variant are then taken together. Changing variant does not move
	 * things on.
	 *
	 * Anchored add-ons (`anchored`, see fillSlots()): the slot of the add-on
	 * (.dahu-kit__component--addon) becomes an item of the list, right AFTER the
	 * item of the anchored card (its .dahu-kit__option-cell in a list with ranges,
	 * the card otherwise) — to its right in the flow, wrapping when space runs
	 * short. A list that already contains it is reused as it is (optionList()).
	 */
	Configurator.prototype.renderCards = function (component, options, sel, hiddenList, anchored) {
		var self = this;
		anchored = anchored || [];

		/** Slots of the add-ons anchored to the option `o` (marked as placed). */
		function after(o) {
			if (!o || !o.product_id) return [];
			return anchored.filter(function (entry) {
				if (entry.placed || entry.productId !== parseInt(o.product_id, 10)) return false;
				entry.placed = true;
				return true;
			}).map(function (entry) { return entry.slot; });
		}

		/**
		 * Questions anchored to the option `o`, carried by ITS card — on the BACK
		 * (infoFrame()): vignettes only (a square card does not flip — the
		 * question stays an item of the list there, after()). Returns the entries
		 * (slot + component).
		 *
		 * Disabled (QUESTION_ON_CARD_BACK): no entry, and the question stays an
		 * item of the list right after the card (after()), as with the square
		 * cards.
		 */
		function inside(o) {
			if (!QUESTION_ON_CARD_BACK) return [];
			if (!vignettes || !o || !o.product_id) return [];
			return anchored.filter(function (entry) {
				if (entry.placed || !isQuestion(entry.component) || entry.productId !== parseInt(o.product_id, 10)) return false;
				entry.placed = true;
				entry.inside = true;
				return true;
			});
		}
		var path = isPath(component);
		var multiple = isMultiple(component);
		var vignettes = this.vignettes;
		var selectButton = this.selectButton;
		var preview = this.preview;
		// “None” card: the image specific to the component (kit editor),
		// else the image and text shared by the whole configurator
		// (WooCommerce > Dahu Kits), when they are set.
		var noneChoice = component.none_choice || this.schema.none_choice || null;
		var listModifier = multiple ? ' dahu-kit__options--multiple' : '';

		/**
		 * “Next” row of the screen, when this component hosts it
		 * (prepareStepNav()): the last item of the grid, after “None”.
		 */
		function navItem() {
			if (self.stepNavHost !== component.id || !self.stepNavNode) return [];
			self.stepNavPlaced = true;
			return [self.stepNavNode];
		}

		function card(o) {
			// Multiple choice: every option is an “add-on” card
			// persistent (Select / Selected); no “None” card
			// (0.46.0).
			if (multiple && o) return self.addonCard(component, o).card;
			var none = o === null;
			// “link” card of a branch (the `link` field of the schema):
			// not a choice, a pointer to a page (contact, dealer
			// dealers…). Never ticked; the click opens the page.
			var link = path && !none && o.link ? o.link : '';
			var checked = !link && (none ? !selectionItems(sel).length : !!sel && (path ? sel.option_id === o.id : sel.product_id === o.product_id));
			var available = none || path || !!o.in_stock;

			function activate() {
				if (!available) return;
				if (link) {
					window.location.href = link;
					return;
				}
				if (checked) {
					// A choice already made that carries a question being asked:
					// “Select” flips the card onto it again,
					// to change the answer — the front shows nothing of it any more.
					if (frameKey && questions.some(function (entry) { return self.questionPosed(entry.component); })) {
						self.openQuestion(frameKey);
						return;
					}
					self.continueFrom();
				} else if (none) {
					self.chooseNone(component.id);
				} else if (path) {
					self.setFocus(component.id);
					self.selectPath(component.id, o);
				} else {
					self.choose(component.id, { product_id: o.product_id });
				}
			}

			var variation = !none && !path && o.type === 'variable'
				? self.renderVariationSelect(component, o, checked ? sel : null, hiddenList)
				: null;
			// Vignette: subtitle of the product (not its short description);
			// a branch has no product, so we keep its description.
			var text = '';
			if (vignettes && !none) {
				if (path) text = o.description ? excerpt(o.description, EXCERPT_MAX) : '';
				else text = o.subtitle ? excerpt(o.subtitle, EXCERPT_MAX) : '';
			} else if (vignettes && noneChoice && noneChoice.text) {
				text = excerpt(noneChoice.text, EXCERPT_MAX);
			}
			// Image disabled on the option (show_image): no
			// image at all, the card tightens up. Variant chosen with an
			// image of its own: that image replaces the one of the product.
			var photo = vignettes && !none && o.show_image !== false ? optionImage(o, checked ? sel : null) : null;
			if (vignettes && none && noneChoice && noneChoice.image) photo = noneChoice.image_large || noneChoice.image;
			// “More info”: vignettes, product options only. Outside
			// side display, the card also carries a back and an extended
			// column (infoFrame()), found through infoKey.
			var infoKey = vignettes && !none && !path && self.infoMode !== 'lateral' && o.product_id
				? component.id + ':' + optionKey(component, o) : null;
			var focusKey = component.id + ':' + (none ? '' : optionKey(component, o));
			var variantMissing = !!infoKey && o.type === 'variable' && !(checked && sel.variation_id)
				&& (o.variations || []).filter(function (v) {
					return v.in_stock && !isHidden(o.product_id, v.variation_id, hiddenList);
				}).length > 1;

			/**
			 * Footer of a face (one per face, so it turns with the card).
			 * side: 'front' | 'back'; null = side display (a single footer, unchanged).
			 *  - icons and “More info” (renderInfoControls());
			 *  - the “Select” of the open info (.dahu-kit__option-tool--choose),
			 *    FRONT only (the extended column, which only has a front) — the body
			 *    does not choose, this does: the same activate(), after closing the
			 *    info again. Hidden when the info is closed (applyInfo()).
			 *    Back: “Back”. Option with variants and no variant retained:
			 *    disabled, with the reason in the accessible name;
			 *  - button mode: the “Select” of the card (.dahu-kit__choose), front
			 *    only (hidden when the info is open, applyInfo()).
			 */
			function footer(side) {
				var more = vignettes && !none && !path ? self.renderInfoControls(o, infoKey, side) : null;
				var pick = null;
				if (infoKey && side === 'back') {
					// Back (flipped card): one reads, one does not choose — its
					// action button is “Back”, never “Select”
					// (earlier: only with the icons). Without icons,
					// “More info” itself becomes “Back” once the
					// card is flipped (applyInfo()): no second button.
					var hasMore = !self.icons && (more || []).filter(Boolean).length > 0;
					pick = hasMore ? null : el('button', {
						type: 'button',
						className: 'button dahu-kit__info-back',
						'data-focus-key': infoKey + ':back:' + side,
						text: t.infoBack,
						onclick: function () { self.closeInfo(true, infoKey); }
					});
				} else if (infoKey) {
					var pickLabel = checked ? t.infoChosen : t.infoChoose;
					pick = el('button', {
						type: 'button',
						className: 'button alt dahu-kit__option-tool--choose',
						'aria-pressed': checked ? 'true' : 'false',
						'aria-label': !available ? pickLabel + ' — ' + t.outOfStock
							: variantMissing ? pickLabel + ' — ' + t.infoChooseVariantFirst : null,
						title: variantMissing && available ? pickLabel + ' — ' + t.infoChooseVariantFirst : null,
						disabled: !available || variantMissing,
						hidden: true,
						'data-focus-key': infoKey + ':choose:' + side,
						text: pickLabel,
						onclick: function (event) {
							var owner = event.currentTarget.closest('.dahu-kit__option');
							self.closeInfo(false, infoKey);
							// Focus moves to the choice control of the front (the same
							// data-focus-key): the render that follows will give it back there.
							var target = owner && owner.querySelector(selectButton ? '.dahu-kit__choose' : '.dahu-kit__option-hit[role="button"]');
							if (target) target.focus({ preventScroll: true });
							activate();
						}
					});
				}
				// Button mode: “Select” carries the choice (and the focus
				// keyboard); the area of the card is no longer a button.
				// “link” card: “Go”, the same button as
				// “Select” (an <a class="button alt"> would take the style
				// of WooCommerce's link-buttons, not that of the cards),
				// announced as a link; the click opens the page.
				var choose = selectButton && side !== 'back' && link ? el('button', {
					type: 'button',
					role: 'link',
					className: 'button alt dahu-kit__choose dahu-kit__choose--link',
					'data-focus-key': focusKey,
					'data-href': link,
					text: t.linkOpen,
					onclick: activate
				}) : selectButton && side !== 'back' ? el('button', {
					type: 'button',
					className: 'button alt dahu-kit__choose',
					'aria-pressed': checked ? 'true' : 'false',
					disabled: !available,
					'data-focus-key': focusKey,
					// “None” card: the button says “None”, the
					// name of the card is then not repeated above.
					text: none ? t.none : t.select,
					onclick: activate
				}) : null;
				return more || choose
					? el('div', { className: 'dahu-kit__option-actions' }, (more || []).concat([pick, choose]))
					: null;
			}
			var previewed = selectButton && !none && !!preview && preview.cid === component.id && preview.key === optionKey(component, o);
			// Questions carried by this card: it then has a back
			// even in side display, where the info goes through the drawer.
			var questions = none || path ? [] : inside(o);
			var frameKey = infoKey || (questions.length ? component.id + ':' + optionKey(component, o) : null);

			// Range of the option: a badge to the right of the name (tier_badge),
			// tinted background of the card (tier_tint), in the colour of the range.
			var tierInfo = none || path ? null : self.optionTier(component, o);
			var nameNode = el('span', { className: 'dahu-kit__option-name', text: none ? t.none : o.name });
			if (tierInfo && component.tier_badge) {
				nameNode = el('span', { className: 'dahu-kit__option-heading' }, [
					nameNode,
					el('span', { className: 'dahu-kit__option-tier', text: tierInfo.tier.label })
				]);
			}

			var hit = el('div', selectButton ? { className: 'dahu-kit__option-hit' } : {
				className: 'dahu-kit__option-hit',
				// “link” card: a link role, no pressed state.
				role: link ? 'link' : 'button',
				tabindex: available ? '0' : '-1',
				'aria-pressed': link ? null : checked ? 'true' : 'false',
				'aria-disabled': available ? null : 'true',
				'data-focus-key': focusKey,
				onkeydown: function (event) {
					if (event.key === 'Enter') {
						event.preventDefault();
						activate();
					} else if (event.key === ' ' || event.key === 'Spacebar') {
						// Space: we avoid scrolling the page; the action
						// goes out on release, like a real button.
						event.preventDefault();
					}
				},
				onkeyup: function (event) {
					if (event.key === ' ' || event.key === 'Spacebar') {
						event.preventDefault();
						activate();
					}
				}
			}, [
				el('div', { className: 'dahu-kit__option-body' }, [
					// “None” in button mode: carried by the button alone.
					none && selectButton ? null : nameNode,
					!none && o.badge ? el('span', { className: 'dahu-kit__option-badge', text: o.badge }) : null,
					path && o.coming_soon ? el('span', { className: 'dahu-kit__option-badge', text: t.comingSoonBadge }) : null,
					// Always present (empty for a branch or “None”):
					// the square cards keep the same grid of rows.
					el('span', { className: 'dahu-kit__option-price price', html: !none && !path ? o.price_html || '' : '' }),
					available ? null : el('span', { className: 'dahu-kit__option-stock', text: t.outOfStock })
				]),
				text ? el('p', { className: 'dahu-kit__option-text', text: text }) : null
			]);

			var front = [
				// Photo: a DIRECT child of the card (the themes place it edge
				// to edge with `.dahu-kit__option--vignette > .dahu-kit__option-media`),
				// outside the keyboard area — decorative, the name follows. A click
				// on it opens the Description (renderOptionMedia()), without
				// select; branch: it is part of the card. In
				// card / extended display it moves into the front
				// (.dahu-kit__option-face--front), voir infoFrame().
				photo ? self.renderOptionMedia(photo, path ? null : o, infoKey) : null,
				hit,
				el('div', { className: 'dahu-kit__option-variation' }, [variation])
			// Question about this choice: on the back only. The front shows
			// nothing of it any more (no questionToggle()):
			// the card flips over by itself (autoOpenQuestions()), and
			// clicking the choice again flips it over once more (activate()).
			];
			var backFooter = infoKey ? footer('back') : null;
			if (frameKey && !infoKey) {
				// Side display: no info back, only that of the
				// question — its footer comes down to “Back”.
				backFooter = el('div', { className: 'dahu-kit__option-actions' }, [el('button', {
					type: 'button',
					className: 'button dahu-kit__info-back',
					'data-focus-key': frameKey + ':back:back',
					text: t.infoBack,
					onclick: function () { self.closeInfo(true, frameKey); }
				})]);
			}
			// Back of the question: the name of the chosen product at the top
			// the name of the chosen product above “Do you have a … cassette?”).
			var questionZone = questions.length
				? [el('p', { className: 'dahu-kit__option-question-product', text: o.name })].concat(questions.map(function (entry) { return entry.slot; }))
				: [];
			var children = frameKey
				? self.infoFrame(frameKey, front.concat([footer(infoKey ? 'front' : null)]), backFooter, questionZone)
				: front.concat([footer(null)]);

			var node = el('div', {
				className: 'dahu-kit__option'
					+ (vignettes ? ' dahu-kit__option--vignette' : ' dahu-kit__option--square')
					+ (vignettes && !photo ? ' dahu-kit__option--no-media' : '')
					+ (path ? ' dahu-kit__option--path' : '')
					+ (none ? ' dahu-kit__option--none' : '')
					+ (link ? ' dahu-kit__option--link' : '')
					+ (checked ? ' is-selected' : '')
					+ (previewed ? ' is-previewed' : '')
					+ (available ? '' : ' is-out-of-stock')
					+ (tierInfo && component.tier_tint ? ' has-tier-tint' : ''),
				// Colour of the range: badge and tinted background.
				style: tierInfo ? '--dahu-kit-tier-color: ' + tierInfo.color + '; --dahu-kit-tier-ink: ' + tierInfo.ink + (tierInfo.tint ? '; --dahu-kit-tier-bg: ' + tierInfo.tint : '') : null,
				// Mouse: the WHOLE card, inner padding included. The
				// card's own controls keep their own action. Button
				// mode: preview (square card only), no choice.
				// Back or extended column: one reads, one does not choose.
				// “link” card: the whole card opens the page, in
				// both modes.
				onclick: selectButton && (vignettes || none) && !link ? null : function (event) {
					if (event.target.closest('select, input, a, button, label')) return;
					if (event.currentTarget.classList.contains('is-flipped') || event.target.closest('.dahu-kit__option-extension')) return;
					if (selectButton && !link) self.showPreview(component, o);
					else activate();
				}
			}, children);
			self.bindInfoCard(node, frameKey, o, component.id);
			return node;
		}

		/**
		 * Option list. When it already contains an anchored add-on (from the
		 * previous render) it is reused — classes and attributes reset, children
		 * reconciled — instead of being recreated: the add-on never leaves the
		 * page, it keeps its transitions and does not replay its appearance.
		 */
		function optionList(attrs, items) {
			var kept = null;
			items.forEach(function (item) {
				var parent = item.parentNode;
				// Anchored add-on, or the persistent card of a multiple choice.
				var persistent = item.classList.contains('dahu-kit__slot') || item.classList.contains('dahu-kit__option--multiple')
					|| item.classList.contains('dahu-kit__step-nav');
				if (!kept && persistent && parent && parent.classList && parent.classList.contains('dahu-kit__options')) kept = parent;
			});
			if (!kept) return el('div', attrs, items);
			kept.className = attrs.className;
			kept.setAttribute('role', attrs.role);
			if (attrs['aria-label']) kept.setAttribute('aria-label', attrs['aria-label']);
			else kept.removeAttribute('aria-label');
			reconcileChildren(kept, items);
			return kept;
		}

		var blocks = [];
		var groups = groupByTier(component, options);

		// Vignettes with ranges: ONE single list, the ranges following one another on
		// the same row as long as there is room. Every card is placed in
		// a cell that reserves the range title row: the title above
		// the first card of a range, an empty row (the same height) above
		// the following ones — so every card stays aligned.
		if (vignettes && groups.length > 1) {
			var cells = [];
			var anyHint = groups.some(function (group) { return tierHasHelp(group.tier); });
			groups.forEach(function (group, groupIndex) {
				var items = group.options.slice();
				// “New row” (tier_newline): every range starts
				// a new row of cards — a break element across the whole
				// largeur de la liste (feuille fonctionnelle).
				if (groupIndex > 0 && component.tier_newline) cells.push(el('div', { className: 'dahu-kit__tier-break', 'aria-hidden': 'true' }));
				if (!path && !multiple && component.optional && groupIndex === groups.length - 1) items.push(null);
				items.forEach(function (o, index) {
					var label = index === 0 && group.tier
						? self.tierHeading(component, group.tier)
						: [tierSpacer(anyHint)];
					cells.push(el('div', { className: 'dahu-kit__option-cell' }, label.concat([card(o)]).filter(Boolean)));
					cells = cells.concat(after(o));
				});
			});
			cells = cells.concat(navItem());
			return optionList({
				className: 'dahu-kit__options dahu-kit__options--vignettes dahu-kit__options--tiered' + listModifier,
				role: 'group',
				'aria-label': component.title
			}, cells);
		}

		groups.forEach(function (group, groupIndex) {
			if (group.tier) blocks = blocks.concat(self.tierHeading(component, group.tier));
			var list = [];
			group.options.forEach(function (o) {
				list.push(card(o));
				list = list.concat(after(o));
			});
			// “None” only appears once, at the end of the last group. Never
			// for a multiple choice: optional and cumulative, and adding
			// adding there already says “none” — “Next” moves things on.
			if (!path && !multiple && component.optional && groupIndex === groups.length - 1) list.push(card(null));
			if (groupIndex === groups.length - 1) list = list.concat(navItem());
			blocks.push(optionList({
				className: 'dahu-kit__options' + (vignettes ? ' dahu-kit__options--vignettes' : '') + listModifier,
				role: 'group',
				'aria-label': component.title
			}, list));
		});

		if (blocks.length === 1) return blocks[0];
		// Several ranges outside vignettes: the wrapper holding the list
		// one that is reused is reused as well (same reason).
		var wrap = null;
		blocks.forEach(function (block) {
			var parent = block.parentNode;
			if (!wrap && parent && parent.classList && parent.classList.contains('dahu-kit__option-groups')) wrap = parent;
		});
		if (!wrap) return el('div', { className: 'dahu-kit__option-groups' }, blocks);
		reconcileChildren(wrap, blocks);
		return wrap;
	};

	/**
	 * Question not asked yet: it bears on the CHOSEN card of the anchor
	 * component, and only appears once that choice is complete — nothing chosen,
	 * a card other than the anchored one, or a product with variants and no
	 * variant retained: no question. With no anchor component on the screen it is
	 * always asked, in its usual place.
	 *
	 * @param {Object} component Question component.
	 * @param {Object} step      Screen.
	 * @return {boolean}
	 */
	Configurator.prototype.questionWaiting = function (component, step) {
		var entry = null;
		window.DahuCompositeTiers.anchors(this.slotComponents(step)).forEach(function (e) {
			if (e.component === component) entry = e;
		});
		if (!entry) return false;
		var host = this.component(entry.hostId);
		var sel = this.selections[entry.hostId];
		if (!host || !sel || Array.isArray(sel) || !sel.product_id) return true;
		if (entry.productId && entry.productId !== parseInt(sel.product_id, 10)) return true;
		var option = optionOf(host, sel.product_id);
		return !!option && option.type === 'variable' && !sel.variation_id;
	};

	/** The question is asked on the screen being shown (visible and not pending). */
	Configurator.prototype.questionPosed = function (component) {
		var step = this.steps()[this.step];
		return !!step && step.components.indexOf(component) !== -1 && !this.questionWaiting(component, step);
	};

	/**
	 * The question is before the customer's eyes: asked, and — when it is on the
	 * back of a card — that card flipped onto it. Outside a card (square cards,
	 * the usual place), asked is enough.
	 */
	Configurator.prototype.questionShown = function (component) {
		if (!this.questionPosed(component)) return false;
		// Mobile: the question is in the drawer.
		if (this.questionDrawer === component.id) return true;
		var slot = this.panelNode ? this.panelNode.querySelector('.dahu-kit__slot[data-component="' + component.id + '"]') : null;
		var card = slot && slot.closest('.dahu-kit__option-question') ? slot.closest('.dahu-kit__option[data-info-key]') : null;
		if (!card) return true;
		var open = this.infoFor(card.getAttribute('data-info-key'));
		return !!open && open.face === 'question';
	};

	/**
	 * Flips the card `key` onto its question — “Select” (or a click) on the choice
	 * ALREADY made, for instance on coming back to the screen through the
	 * summary: the answer can be changed. It replaces the former front-side
	 * control (questionToggle(), .dahu-kit__question-toggle), which has been
	 * removed: the front no longer hints at the question. Focus on the answer
	 * given, failing that the first one.
	 */
	Configurator.prototype.openQuestion = function (key) {
		var card = this.infoCard(key);
		if (!card) return;
		// Mobile: in the drawer, not on the back.
		if (isMobile() && this.openQuestionDrawer(card)) return;
		var open = this.infoFor(key);
		if (!open || open.face !== 'question') {
			if (open) this.closeInfo(false, key);
			this.openInfo(key, 'question', card, null);
		}
		var answer = card.querySelector('.dahu-kit__option-question .dahu-kit__answer.is-selected:not([disabled])')
			|| card.querySelector('.dahu-kit__option-question .dahu-kit__answer:not([disabled])');
		if (answer) answer.focus({ preventScroll: true });
	};

	/**
	 * The question has just been asked (the variant of the motor only just
	 * chosen) and has no answer: the card flips onto it by itself, once per
	 * asking — closed again by the customer, it does not reopen on its own; asked
	 * again (another motor then back), it reopens. After the render, on the next
	 * frame: the card first appears front side up, and the flip animates. Never
	 * on load (this.touched).
	 */
	Configurator.prototype.autoOpenQuestions = function (step) {
		var self = this;
		this.slotComponents(step).forEach(function (component) {
			if (!isQuestion(component)) return;
			if (!self.questionPosed(component)) {
				delete self.questionAsked[component.id];
				return;
			}
			if (self.questionAsked[component.id]) return;
			self.questionAsked[component.id] = true;
			if (!self.touched || self.selections[component.id] || self.declined[component.id]) return;
			var frame = window.requestAnimationFrame || function (fn) { return window.setTimeout(fn, 16); };
			frame(function () {
				var slot = self.panelNode.querySelector('.dahu-kit__slot[data-component="' + component.id + '"]');
				var card = slot && slot.closest('.dahu-kit__option-question') ? slot.closest('.dahu-kit__option[data-info-key]') : null;
				if (!card || !self.questionPosed(component)) return;
				var key = card.getAttribute('data-info-key');
				var open = self.infoFor(key);
				if (open && open.face === 'question') return;
				// Mobile: the question opens in the drawer.
				if (isMobile() && self.openQuestionDrawer(card, component)) return;
				// Focus was inside the card (variant list): it follows to the back.
				var within = card.contains(document.activeElement);
				if (open) self.closeInfo(false, key);
				self.openInfo(key, 'question', card, null);
				if (within) {
					var first = card.querySelector('.dahu-kit__option-question .dahu-kit__answer:not([disabled])');
					if (first) first.focus({ preventScroll: true });
				}
			});
		});
	};

	/**
	 * Mobile: the question of an add-on carried by the chosen card is shown in the
	 * “Description” drawer (Drawer.openContent()) instead of flipping the card —
	 * same animation, same veil, same scroll locking, same closing (cross, veil,
	 * Esc), focus on the first answer then given back to the card. Desktop: the
	 * back of the card, unchanged.
	 *
	 * @param {Element} card        Chosen card (the back carrying the question).
	 * @param {Object}  [component] Question component (failing that, the first of the back).
	 * @return {boolean} False when the card carries no question.
	 */
	Configurator.prototype.openQuestionDrawer = function (card, component) {
		var self = this;
		if (!component) {
			var slot = card.querySelector('.dahu-kit__option-question .dahu-kit__slot[data-component]');
			component = slot ? this.component(slot.getAttribute('data-component')) : null;
		}
		if (!component || !isQuestion(component)) return false;
		var key = card.getAttribute('data-info-key');
		if (key && this.infoFor(key)) this.closeInfo(false, key);
		var cid = component.id;
		var name = card.querySelector('.dahu-kit__option-name');
		// Focus given back on closing: the choice control of the card, read again
		// at that moment (the card is redrawn on every render).
		var trigger = function () {
			var current = key ? self.infoCard(key) : null;
			if (!current) return null;
			return current.querySelector('.dahu-kit__choose:not([disabled])')
				|| current.querySelector('.dahu-kit__option-hit[role="button"]')
				|| current.querySelector('select.dahu-kit__variation');
		};
		this.questionDrawer = cid;
		Drawer.openContent(name ? name.textContent : component.title, function () {
			var current = self.component(cid);
			return current ? self.questionBlock(current) : [];
		}, trigger, function () {
			if (self.questionDrawer === cid) self.questionDrawer = null;
		});
		return true;
	};

	/**
	 * After every render: the drawer of the question kept up to date (the variant
	 * of an option with variants, the answer), closed again once the question has
	 * been answered or is no longer asked (next screen, another motor).
	 */
	Configurator.prototype.syncQuestionDrawer = function () {
		var cid = this.questionDrawer;
		if (!cid) return;
		var component = this.component(cid);
		var sel = component ? this.selections[cid] : null;
		var option = component ? component.options[0] : null;
		var answered = !!component && (!!this.declined[cid]
			|| (!!sel && !Array.isArray(sel) && !!option && sel.product_id === option.product_id && (option.type !== 'variable' || !!sel.variation_id)));
		if (!component || !this.questionPosed(component) || answered) {
			this.questionDrawer = null;
			Drawer.close(true);
			return;
		}
		Drawer.refreshContent();
	};

	/**
	 * Add-on presented as a yes / no question (isQuestion()): the text of the
	 * question (the `question` setting, else the title of the component) and two
	 * answers.
	 *  - “Yes” (+ price of the option) → chooses the option (choose());
	 *  - “No” → chooses nothing, and remembers it (chooseNone(), this.declined):
	 *    an answer, not an absence of answer.
	 * Both move things on as soon as the screen is complete; clicking the answer
	 * already given means “continue”, like a card already chosen. Neither a photo
	 * nor a product card: this is not one more item in the grid, it is a question
	 * about the choice that has just been made.
	 *
	 * Asked inside the chosen card (vignettes): the click does not bubble up to
	 * the card, which would mean “continue”.
	 *
	 * @param {Object} component Question component.
	 * @return {Node[]}
	 */
	Configurator.prototype.questionBlock = function (component) {
		var self = this;
		var cid = component.id;
		var option = component.options[0];
		var sel = this.selections[cid];
		var yes = !!sel && !Array.isArray(sel) && sel.product_id === option.product_id;
		var no = !yes && !!this.declined[cid];
		// Appearance of “Yes”: an option with variants, only after
		// a click on “Yes”. Choosing the variant in the list already selects
		// the option (yes) without “Yes” changing appearance: the list carries
		// that choice. Simple option: unchanged (yes comes from a click).
		var yesShown = yes && (option.type !== 'variable' || !!this.answeredYes[cid]);
		var hiddenList = (this.validation.hidden_options || {})[cid] || [];
		var available = !!option.in_stock && !isHidden(option.product_id, null, hiddenList);
		var labelId = 'dahu-kit-question-' + cid;

		function answer(kind, pressed, label, extra, disabled, onclick) {
			return el('button', {
				type: 'button',
				className: 'button dahu-kit__answer dahu-kit__answer--' + kind + (pressed ? ' is-selected' : ''),
				'aria-pressed': pressed ? 'true' : 'false',
				disabled: disabled,
				'data-focus-key': cid + ':' + kind,
				onclick: onclick
			}, [el('span', { className: 'dahu-kit__answer-label', text: label })].concat(extra || []));
		}

		var block = el('div', {
			className: 'dahu-kit__question',
			role: 'group',
			'aria-labelledby': labelId,
			onclick: function (event) { event.stopPropagation(); }
		}, [
			el('p', { className: 'dahu-kit__question-text', id: labelId, text: component.question || component.title }),
			el('div', { className: 'dahu-kit__question-answers' }, [
				answer('yes', yesShown, t.yes, [
					// The “+” in front of the price is set by the functional stylesheet.
					option.price_html ? el('span', { className: 'dahu-kit__answer-price price', html: option.price_html }) : null,
					available ? null : el('span', { className: 'dahu-kit__option-stock', text: t.outOfStock })
				], !available && !yes, function () {
					self.answeredYes[cid] = true;
					if (yes) self.continueFrom();
					else self.choose(cid, { product_id: option.product_id });
				}),
				answer('no', no, t.no, null, false, function () {
					if (no) self.continueFrom();
					else self.chooseNone(cid);
				})
			]),
			// Option with variants: the variant is chosen here (“Yes” alone is not
			// enough, and the screen stays incomplete until it is there). That is what
			// choice that moves things on, and the list says what it expects
			// (renderVariationSelect() — the safety net of the
			// extended to every option with variants).
			option.type === 'variable' ? this.renderVariationSelect(component, option, yes ? sel : null, hiddenList) : null,
			// Detail of the product: the “?” popover of the ranges (it used to be a
			// side drawer), for reading only — questionHelp().
			this.questionHelp(cid) ? el('button', {
				type: 'button',
				className: 'dahu-kit__question-more' + (this.tierHelp && this.tierHelp.key === 'question:' + cid ? ' is-active' : ''),
				'data-focus-key': cid + ':more',
				'data-tier-help-key': 'question:' + cid,
				'aria-haspopup': 'dialog',
				'aria-expanded': this.tierHelp && this.tierHelp.key === 'question:' + cid ? 'true' : 'false',
				'aria-controls': this.tierHelpId(),
				text: t.moreInfo,
				onclick: function (event) {
					event.stopPropagation();
					self.toggleTierHelp('question:' + cid);
				}
			}) : null
		]);
		return [block, this.renderErrors(cid)].filter(Boolean);
	};

	/**
	 * “Add-on” component (isAddon(): optional, a single option — the cassette next
	 * to the motor): not a choice but an addition. One card, no “None” card, and
	 * one button:
	 *  - “Add” → selects the option (choose(): moves on when the main choice of
	 *    the screen has already been made, otherwise waits for that choice);
	 *  - “Added” (.is-added, aria-pressed="true") → a second click: removes it.
	 *
	 * The content is PERSISTENT (this.addonNodes[cid]): created once, then updated
	 * in place — the button in particular is never recreated, so the theme can
	 * animate its switch to .is-added. Anchor point for an icon:
	 * span.dahu-kit__add-icon (empty, aria-hidden). No style here: state and
	 * classes only.
	 */
	Configurator.prototype.addonBlock = function (component) {
		var nodes = this.addonCard(component, component.options[0]);
		var errors = this.renderErrors(component.id);
		nodes.errors.replaceChildren.apply(nodes.errors, errors ? [errors] : []);
		return [nodes.card, nodes.errors];
	};

	/**
	 * “Add-on” card of ONE option: that of an “add-on” component (addonBlock()),
	 * or one of the cards of a multiple-choice component (renderCards()) — the
	 * same styling, the same persistent nodes (this.addonNodes, keyed by
	 * component, and by product for a multiple choice).
	 * Multiple choice: the button adds the option to the list (select(…, item))
	 * without moving things on, and a second click removes it (removeItem());
	 * the card is marked .dahu-kit__option--multiple.
	 *
	 * Label of the button: “Select” / “Selected” — the label alone, the gesture
	 * stays a cumulative addition; the classes (.dahu-kit__add, .is-added) are
	 * unchanged. Back of the card: “Back”. “Inline” layout: an expandable row
	 * (rowDetail(), toggleRow()).
	 *
	 * @return {Object} Nodes (card, errors, …).
	 */
	Configurator.prototype.addonCard = function (component, option) {
		var self = this;
		var cid = component.id;
		var multiple = isMultiple(component);
		var nodeKey = multiple ? cid + ':' + option.product_id : cid;
		var addKey = nodeKey + ':add';
		var nodes = this.addonNodes[nodeKey];

		if (!nodes) {
			var toggleAdd = function () {
				if (multiple) {
					var has = selectionItems(self.selections[cid]).some(function (item) { return item.product_id === option.product_id; });
					if (has) {
						self.removeItem(cid, option.product_id);
					} else {
						self.select(cid, { product_id: option.product_id }, option.product_id);
						self.focusVariation(cid, option.product_id);
					}
					return;
				}
				var current = self.selections[cid];
				if (current && current.product_id === option.product_id) {
					self.clear(cid);
				} else {
					// An add-on is a choice: it moves on when the rest of
					// the screen is already complete (maybeAutoAdvance()).
					self.choose(cid, { product_id: option.product_id });
				}
			};
			nodes = { label: el('span', { className: 'dahu-kit__add-label' }) };
			// “Select / Selected” button (“Add / Added” in a multiple
			// choice), created once and updated in place: never recreated
			// or moved, so the theme's transition animates.
			nodes.button = el('button', {
				type: 'button',
				className: 'button dahu-kit__add',
				'data-focus-key': addKey,
				onclick: toggleAdd
			}, [el('span', { className: 'dahu-kit__add-icon', 'aria-hidden': 'true' }), nodes.label]);
			// Back (card display): “Back”, and no second
			// add button — one reads on the back, one chooses on the front.
			nodes.back = el('button', {
				type: 'button',
				className: 'button dahu-kit__info-back',
				'data-focus-key': nodeKey + ':info-back',
				text: t.infoBack,
				onclick: function () { if (nodes.infoKey) self.closeInfo(true, nodes.infoKey); }
			});
			nodes.head = el('div', { className: 'dahu-kit__addon-head' });
			nodes.variation = el('div', { className: 'dahu-kit__option-variation' });
			nodes.more = el('span', { className: 'dahu-kit__addon-more' });
			nodes.moreBack = el('span', { className: 'dahu-kit__addon-more' });
			nodes.actions = el('div', { className: 'dahu-kit__option-actions' }, [nodes.more, nodes.button]);
			nodes.actionsBack = el('div', { className: 'dahu-kit__option-actions' }, [nodes.moreBack]);
			// Expandable row (“Inline” layout): an arrow to the
			// right of the row (a real button, aria-expanded) and the detail under the
			// row. Put in the card only when it is expandable.
			nodes.detailId = infoDomId(nodeKey, 'detail');
			nodes.toggle = el('button', {
				type: 'button',
				className: 'dahu-kit__row-toggle',
				'aria-controls': nodes.detailId,
				'data-focus-key': nodeKey + ':row-toggle',
				html: ROW_CHEVRON,
				onclick: function () { self.toggleRow(nodeKey); }
			});
			nodes.detailBody = el('div', { className: 'dahu-kit__row-detail-body' });
			nodes.detail = el('div', { className: 'dahu-kit__row-detail', id: nodes.detailId }, [
				el('div', { className: 'dahu-kit__row-detail-inner' }, [nodes.detailBody])
			]);
			nodes.card = el('div', {
				// Expandable row: a click on the row itself expands or
				// closes it. Its controls (button, variant list, icons)
				// keep their own action; the open detail can be read without closing it.
				onclick: function (event) {
					if (!nodes.expandable) return;
					if (event.target.closest('select, input, a, button, label, .dahu-kit__row-detail')) return;
					self.toggleRow(nodeKey);
				}
			}, [
				nodes.head,
				nodes.variation,
				nodes.actions
			]);
			nodes.errors = el('div', { className: 'dahu-kit__addon-errors' });
			this.addonNodes[nodeKey] = nodes;
		}

		// Item chosen for THIS option (multiple choice: among the list).
		var sel = null;
		selectionItems(this.selections[cid]).forEach(function (item) {
			if (item.product_id === option.product_id) sel = item;
		});
		var added = !!sel;
		var hiddenList = (this.validation.hidden_options || {})[cid] || [];
		var available = !!option.in_stock && !isHidden(option.product_id, null, hiddenList);
		var vignettes = this.vignettes;
		// Layout of the component (displayStyle(), multiple choice only).
		var style = displayStyle(component);
		var look = vignettes || style === 'image';
		// Expandable row: photo and short description under the row.
		var detail = style === 'row' ? rowDetail(option, sel) : null;
		nodes.expandable = !!detail;

		nodes.card.className = 'dahu-kit__option dahu-kit__option--addon'
			+ (multiple ? ' dahu-kit__option--multiple' : '')
			+ (look ? ' dahu-kit__option--vignette' : ' dahu-kit__option--square')
			+ (style ? ' dahu-kit__option--style-' + style : '')
			+ (detail ? ' dahu-kit__option--expandable' : '')
			+ (detail && this.rowOpen[nodeKey] ? ' is-open' : '')
			+ (added ? ' is-added' : '')
			+ (available ? '' : ' is-out-of-stock');

		// The variant's own image for the one that was added, when it exists. Layouts
		// layout with no photo on the card: everything except “With image” (the row
		// shows it in its detail).
		var photo = style ? (style === 'image' ? optionImage(option, sel) : null)
			: vignettes && option.show_image !== false ? optionImage(option, sel) : null;
		nodes.card.classList.toggle('dahu-kit__option--no-media', look && !photo);
		var text = !style && vignettes && option.subtitle ? excerpt(option.subtitle, EXCERPT_MAX) : '';
		// Photo: a direct child of the card, as on the other cards (in
		// the front in card / extended display).

		// “More info” outside side display: front / back / extended
		// column, like the option cards (infoFrame()). The nodes are not
		// move only when the structure changes (a change of display): the
		// button keeps its transitions.
		// A row and the compact layouts do not flip over: the
		// row expands, the others go through the side drawer.
		var infoKey = vignettes && (!style || style === 'image') && this.infoMode !== 'lateral' && option.product_id
			? cid + ':' + optionKey(component, option) : null;
		nodes.infoKey = infoKey;
		// “With image”, same photo and same info → the same node, no
		// reloading (and no blank) on every selection.
		var mediaKey = photo + '|' + infoKey;
		var keepMedia = style === 'image' && !!nodes.media && nodes.mediaKey === mediaKey;
		nodes.mediaKey = mediaKey;
		if (nodes.media && !keepMedia) nodes.media.remove();
		// Click on the image: Description (renderOptionMedia()).
		if (!keepMedia) nodes.media = photo ? this.renderOptionMedia(photo, option, infoKey) : null;
		var front = [nodes.media, nodes.head, nodes.variation].filter(Boolean);
		// Arrow of the expandable row: the last element of the footer, on the right.
		syncChildren(nodes.actions, [nodes.more, nodes.button].concat(detail ? [nodes.toggle] : []));
		if (infoKey) {
			// Footer inside every face: it turns with the card.
			if (!nodes.info || nodes.info.key !== infoKey) nodes.info = { key: infoKey, parts: this.infoFrame(infoKey, [], nodes.actionsBack) };
			syncChildren(nodes.info.parts[0].firstChild, front.concat([nodes.actions]));
			syncChildren(nodes.card, nodes.info.parts);
		} else {
			syncChildren(nodes.card, front.concat([nodes.actions]).concat(detail ? [nodes.detail] : []));
		}
		// Focus key: the shared logical key + the face, outside side display.
		nodes.button.setAttribute('data-focus-key', addKey + (infoKey ? ':front' : ''));
		nodes.head.replaceChildren.apply(nodes.head, [
			// Pictogram of the “Category icon” layout.
			style === 'icon' ? el('span', { className: 'dahu-kit__category-icon', 'aria-hidden': 'true', html: categoryIcon(option) }) : null,
			el('div', { className: 'dahu-kit__option-body' }, [
				el('span', { className: 'dahu-kit__option-name', text: option.name }),
				option.badge ? el('span', { className: 'dahu-kit__option-badge', text: option.badge }) : null,
				el('span', { className: 'dahu-kit__option-price price', html: option.price_html || '' }),
				available ? null : el('span', { className: 'dahu-kit__option-stock', text: t.outOfStock })
			]),
			text ? el('p', { className: 'dahu-kit__option-text', text: text }) : null
		].filter(Boolean));

		nodes.variation.replaceChildren.apply(nodes.variation, option.type === 'variable'
			? [this.renderVariationSelect(component, option, added ? sel : null, hiddenList)]
			: []);
		nodes.more.replaceChildren.apply(nodes.more, detail ? this.rowInfoControls(option, nodeKey, nodes.detailId)
			: vignettes ? this.renderInfoControls(option, infoKey, 'front').filter(Boolean) : []);
		var backControls = infoKey ? this.renderInfoControls(option, infoKey, 'back').filter(Boolean) : [];
		nodes.moreBack.replaceChildren.apply(nodes.moreBack, backControls);
		// Back: “Back”. Without icons, “More info” having become
		// “Back” (applyInfo()) stands in for it: no second button.
		syncChildren(nodes.actionsBack, [nodes.moreBack].concat(infoKey && (this.icons || !backControls.length) ? [nodes.back] : []));
		this.bindInfoCard(nodes.card, infoKey, option, cid);

		nodes.button.classList.toggle('is-added', added);
		nodes.button.setAttribute('aria-pressed', added ? 'true' : 'false');
		nodes.button.disabled = !available && !added;
		// Multiple choice (Accessories): “Add / Added” — the
		// “Next” of the screen now reads “Select”. An add-on alone
		// beside a choice: “Select / Selected”.
		nodes.label.textContent = multiple ? (added ? t.addItemDone : t.addItem) : (added ? t.addOnDone : t.addOn);

		// Detail of the row: rebuilt only when it changes (photo of
		// the variant), so the image is not reloaded on every render.
		if (detail) {
			var detailKey = detail.photo + '|' + detail.text;
			if (nodes.detailKey !== detailKey) {
				nodes.detailKey = detailKey;
				nodes.detailBody.replaceChildren.apply(nodes.detailBody, [
					detail.photo ? el('figure', { className: 'dahu-kit__row-media' }, [
						el('img', { className: 'dahu-kit__row-photo', src: detail.photo, alt: '', loading: 'lazy' })
					]) : null,
					detail.text ? el('div', { className: 'dahu-kit__row-text dahu-kit__description', html: detail.text }, [
						// “more info…” following the description.
						moreInfoLink(option) ? el('p', { className: 'dahu-kit__more-info' }, [moreInfoLink(option)]) : null
					]) : null
				].filter(Boolean));
			}
			nodes.detail.setAttribute('aria-label', option.name || '');
			this.applyRow(nodeKey);
		}

		return nodes;
	};

	/**
	 * Info controls of an expandable row: the DESCRIPTION control — the “i” icon,
	 * or “More info” without the icons — no longer goes through the side drawer:
	 * it expands the row, like a click on the row or on its arrow. The other
	 * sections that are enabled (specifications, products supplied) keep their
	 * icon and the drawer. Description disabled on the option: no “i”, the arrow
	 * is enough.
	 */
	Configurator.prototype.rowInfoControls = function (option, nodeKey, detailId) {
		var self = this;
		var enabled = enabledFaces(option);
		var open = !!this.rowOpen[nodeKey];
		var attrs = {
			type: 'button',
			'aria-expanded': open ? 'true' : 'false',
			'aria-controls': detailId,
			'data-focus-key': nodeKey + ':row-info',
			onclick: function () { self.toggleRow(nodeKey); }
		};
		if (!this.icons) {
			return [el('button', Object.assign(attrs, {
				className: 'button dahu-kit__more dahu-kit__row-info',
				text: t.moreInfo
			}))];
		}
		var rest = this.renderInfoControls(option, null, 'front').filter(function (button) {
			return button && !button.classList.contains('dahu-kit__option-tool--description');
		});
		if (enabled.indexOf('description') === -1) return rest;
		return [el('button', Object.assign(attrs, {
			className: 'dahu-kit__info-tool dahu-kit__option-tool dahu-kit__option-tool--description dahu-kit__row-info',
			'aria-label': t.infoDescription,
			title: t.infoDescription,
			html: INFO_ICONS.info
		}))].concat(rest);
	};

	/**
	 * Expands or closes a row (“Inline” layout): a click on the row, on its arrow
	 * or on its “i”. With no new render — the nodes of the card are persistent and
	 * the stylesheet animates the opening. Several rows may stay open. The state
	 * (this.rowOpen) survives the renders: a row that is open stays open when the
	 * option is selected.
	 */
	Configurator.prototype.toggleRow = function (nodeKey) {
		if (this.rowOpen[nodeKey]) delete this.rowOpen[nodeKey];
		else this.rowOpen[nodeKey] = true;
		this.applyRow(nodeKey);
	};

	/** Applies its open / closed state to a row: class, ARIA, inert detail. */
	Configurator.prototype.applyRow = function (nodeKey) {
		var nodes = this.addonNodes[nodeKey];
		if (!nodes) return;
		var open = !!this.rowOpen[nodeKey] && !!nodes.expandable;
		nodes.card.classList.toggle('is-open', open);
		nodes.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		nodes.toggle.setAttribute('aria-label', open ? t.rowCollapse : t.rowExpand);
		nodes.toggle.setAttribute('title', open ? t.rowCollapse : t.rowExpand);
		var info = nodes.more.querySelector('.dahu-kit__row-info');
		if (info) info.setAttribute('aria-expanded', open ? 'true' : 'false');
		setInert(nodes.detail, !open);
	};

	/**
	 * Image area (.dahu-kit__option-media) of a product option, on the option
	 * cards and the “add-on” component. A click on the image opens the info on
	 * the Description, by the SAME path as the Description icon or “More info”:
	 *  - card / extended / vignette (key): toggleInfo(key, face) — unless the card
	 *    is already open on that section (the image “shows”, it does not close);
	 *    when extended and open on another section, it goes back to the
	 *    description;
	 *  - side: the drawer, on that tab.
	 * Description disabled on the option (show_description): the first section
	 * still enabled, like “More info” (enabledFaces()[0]). No section enabled: no
	 * interception, and the image stays a part of the card as before.
	 *
	 * The click stops at the image (stopPropagation): with the whole card
	 * clickable (“Click” disabled), it does NOT select the option as well. Front
	 * face only: the back has no image, and a flipped card has its front inert.
	 * Decorative (empty alt) and out of the keyboard path: the same sections stay
	 * reachable through the footer controls.
	 *
	 * @param {string}      photo  URL of the image.
	 * @param {Object}      option Product option (null: branch, “None”).
	 * @param {string|null} key    Info key of the card (null = side).
	 * @return {Node}
	 */
	Configurator.prototype.renderOptionMedia = function (photo, option, key) {
		var self = this;
		var faces = option && option.product_id ? enabledFaces(option) : [];
		var face = faces.indexOf('description') !== -1 ? 'description' : faces[0];
		return el('figure', {
			className: 'dahu-kit__option-media' + (face ? ' is-info-trigger' : ''),
			onclick: face ? function (event) {
				event.stopPropagation();
				var figure = event.currentTarget;
				var card = figure.closest('.dahu-kit__option');
				if (key) {
					var open = self.infoFor(key);
					if (open && open.face === face) return;
					self.toggleInfo(key, face, card, null);
					return;
				}
				// Focus given back when the drawer closes: to the info control of the
				// card (the image is not focusable).
				var trigger = (card && card.querySelector('.dahu-kit__more, .dahu-kit__option-tool--' + face)) || figure;
				Drawer.open(option.product_id, option.name, trigger, self.icons
					? { tab: face, bundled: option.bundled || [], all: true, faces: faces, helpImage: self.helpImageFor(option, figure), moreInfo: option.more_info_url || '' }
					: { bundled: option.bundled || [], faces: faces, tab: face, helpImage: self.helpImageFor(option, figure), moreInfo: option.more_info_url || '' });
			} : null
		}, [
			el('img', { className: 'dahu-kit__option-photo', src: photo, alt: '', loading: 'lazy' })
		]);
	};

		/**
	 * “More info” button (vignettes): opens the detail drawer — content of the
	 * real WooCommerce product (GET /product-tabs/{id}), Description tab = short
	 * description. For reading only: no “Select” in the drawer (it was removed),
	 * the choice is made on the card.
	 *
	 * @param {Object} option Product option.
	 */
	Configurator.prototype.renderMoreInfo = function (option) {
		var self = this;
		if (!option || !option.product_id) return null;
		return el('button', {
			type: 'button',
			className: 'button dahu-kit__more',
			text: t.moreInfo,
			onclick: function (event) {
				// Products supplied: a tab of the drawer, when there are any;
				// sections disabled on the option: no tab.
				var faces = enabledFaces(option);
				Drawer.open(option.product_id, option.name, event.currentTarget, { bundled: option.bundled || [], faces: faces, tab: faces[0], helpImage: self.helpImageFor(option, event.currentTarget), moreInfo: option.more_info_url || '' });
			}
		});
	};

	/* ------------------------------------------------------------------ */
	/* “More info”: flipped / extended card (readInfoMode())              */
	/* ------------------------------------------------------------------ */

	/**
	 * Puts the children of `parent` back in the order of `list`, moving only what
	 * is out of place (a node that is moved loses its transitions).
	 */
	function syncChildren(parent, list) {
		list.forEach(function (child, index) {
			if (parent.children[index] !== child) parent.insertBefore(child, parent.children[index] || null);
		});
		while (parent.children.length > list.length) parent.removeChild(parent.lastElementChild);
	}

	/**
	 * syncChildren(), removing FIRST whatever is no longer in `list`: a node that
	 * is kept then never moves as long as the order of the kept nodes does not
	 * change. syncChildren() alone would move an anchored add-on as soon as new
	 * cards came before it.
	 */
	function reconcileChildren(parent, list) {
		Array.prototype.slice.call(parent.children).forEach(function (child) {
			if (list.indexOf(child) === -1) parent.removeChild(child);
		});
		syncChildren(parent, list);
	}

	/** DOM id derived from a card key (“component:option”). */
	function infoDomId(key, suffix) {
		return 'dahu-kit-info-' + String(key).replace(/[^a-zA-Z0-9_-]/g, '-') + '-' + suffix;
	}

	/** Labels of the info sections (icons, tabs, titles). */
	function infoLabel(face) {
		return face === 'specs' ? t.infoCharacteristics : face === 'bundled' ? t.infoBundled : t.infoDescription;
	}

	/**
	 * Controls of the card footer, according to the “Icons” toggle
	 * (readIconsMode()) and whatever the display:
	 *
	 *  - icons: [description “i”] [specifications] [products supplied], ALWAYS
	 *    all three (empty section: “none…”), and no “More info”. Card / extended:
	 *    every icon opens its section, and the active icon closes it again
	 *    (toggleInfo()). Side: every icon opens the drawer on its own tab;
	 *  - without icons: “More info” alone. Card / extended: opens on the
	 *    description (“Back” once open), and the other sections are tabs of the
	 *    panel (fillInfo()). Side: the drawer, with Description / Specifications /
	 *    Products supplied tabs.
	 *
	 * Real buttons: aria-expanded, aria-pressed, aria-controls set by
	 * applyInfo(); data-focus-key to find the focus again after a render. Outside
	 * the main area of the card: they never select.
	 *
	 * @param {Object}      option Product option.
	 * @param {string|null} key    Key of the card (null = side).
	 * @param {string}      side   'front' | 'back' (null in side display).
	 * @return {Array} Nodes (null possible).
	 */
	Configurator.prototype.renderInfoControls = function (option, key, side) {
		var self = this;
		// One series per face: data-focus-key = the shared logical key
		// + face (“:front” / “:back”), so that the focus finds its
		// control after a render and moves to its equivalent on the flip.
		var suffix = ':' + (side || 'front');
		// Sections disabled on the option: neither icon nor button.
		var enabled = enabledFaces(option);
		var FACES = [['description', 'info'], ['specs', 'caracteristiques'], ['bundled', 'fournis']].filter(function (pair) {
			return enabled.indexOf(pair[0]) !== -1;
		});
		if (!enabled.length) return [];

		if (!key) {
			if (!this.icons) return [this.renderMoreInfo(option)];
			// Side + icons: the drawer, opened on the tab of the icon.
			return FACES.map(function (pair) {
				var label = infoLabel(pair[0]);
				return el('button', {
					type: 'button',
					className: 'dahu-kit__info-tool dahu-kit__option-tool dahu-kit__option-tool--' + pair[0],
					'aria-label': label,
					title: label,
					html: INFO_ICONS[pair[1]],
					onclick: function (event) {
						Drawer.open(option.product_id, option.name, event.currentTarget, { tab: pair[0], bundled: option.bundled || [], all: true, faces: enabled, helpImage: self.helpImageFor(option, event.currentTarget), moreInfo: option.more_info_url || '' });
					}
				});
			});
		}

		if (this.icons) {
			return FACES.map(function (pair) {
				var face = pair[0];
				var label = infoLabel(face);
				return el('button', {
					type: 'button',
					// The same class and the same icon as the bar of the
					// droite (renderInfoTabs()).
					className: 'dahu-kit__info-tool dahu-kit__option-tool dahu-kit__option-tool--' + face,
					'data-info-action': face,
					'data-focus-key': key + ':' + face + suffix,
					'aria-label': label,
					title: label,
					html: INFO_ICONS[pair[1]],
					onclick: function (event) { self.toggleInfo(key, face, event.currentTarget.closest('.dahu-kit__option'), event.currentTarget); }
				});
			});
		}

		// “More info” opens the first section that is enabled (the description
		// by default): that is also its data-info-action.
		return [el('button', {
			type: 'button',
			className: 'button dahu-kit__more',
			'data-info-action': enabled[0],
			'data-focus-key': key + ':more' + suffix,
			text: t.moreInfo,
			onclick: function (event) { self.toggleInfo(key, 'more', event.currentTarget.closest('.dahu-kit__option'), event.currentTarget); }
		})];
	};

	/**
	 * Structure of a card with info (card / extended): it is the card ITSELF
	 * (div.dahu-kit__option[data-info-key]) that pivots, with its surface
	 * (background, rule, radius, shadow set by the theme); every face carries its
	 * own footer.
	 *
	 *   div.dahu-kit__option[data-info-key]            (flip pivot)
	 *     div.dahu-kit__option-flip                    (stacks the faces)
	 *       div.dahu-kit__option-face--front           (front: front + footer)
	 *         …, div.dahu-kit__option-actions
	 *       div.dahu-kit__option-face--back            (back, id …-back)
	 *         div.dahu-kit__option-info                (scrolls)
	 *         div.dahu-kit__option-question            (question of an add-on)
	 *         div.dahu-kit__option-actions             (footer of the back)
	 *     div.dahu-kit__option-extension               (extended column, id …-ext)
	 *       div.dahu-kit__option-info
	 *
	 * Both presentations exist in the DOM whatever the display: the extended one
	 * falls back to a flipped card when space runs short (it only uses the front
	 * and its footer). Side display (key null): `front` as it is, with the footer
	 * added by the caller.
	 *
	 * @param {string|null} key        Key of the card.
	 * @param {Array}       front      Content of the front, footer included.
	 * @param {Node|null}   backFooter Footer of the back.
	 * @return {Array}
	 */
	Configurator.prototype.infoFrame = function (key, front, backFooter, questions) {
		if (!key) return front;
		return [
			el('div', { className: 'dahu-kit__option-flip' }, [
				el('div', { className: 'dahu-kit__option-face dahu-kit__option-face--front' }, front),
				el('div', { className: 'dahu-kit__option-face dahu-kit__option-face--back', id: infoDomId(key, 'back'), role: 'region' }, [
					el('div', { className: 'dahu-kit__option-info' }),
					// Question(s) of an add-on: the “question” section of the
					// back, shown in place of .dahu-kit__option-info when
					// data-info-face vaut « question » (feuille fonctionnelle).
					questions && questions.length ? el('div', { className: 'dahu-kit__option-question' }, questions) : null,
					backFooter || null
				])
			]),
			el('div', { className: 'dahu-kit__option-extension', id: infoDomId(key, 'ext'), role: 'region' }, [
				el('div', { className: 'dahu-kit__option-info' })
			])
		];
	};

	/**
	 * Ties a card (new or persistent) to its option, to its component and to the
	 * open state. The component is carried by the card (_dahuInfoComponent): a new
	 * card is filled here BEFORE being inserted into its slot — see
	 * helpImageFor().
	 */
	Configurator.prototype.bindInfoCard = function (card, key, option, cid) {
		card._dahuInfoComponent = cid || null;
		if (!key) {
			if (card.hasAttribute('data-info-key')) {
				card.removeAttribute('data-info-key');
				card.classList.remove('is-flipped', 'is-extended');
				card.removeAttribute('data-info-face');
				card.removeAttribute('data-info-anchor');
				card.style.removeProperty('--dahu-kit-extend-width');
				card.style.removeProperty('--dahu-kit-extend-shift');
			}
			return;
		}
		card.setAttribute('data-info-key', key);
		card._dahuInfoOption = option;
		var name = option.name || '';
		card.querySelectorAll('.dahu-kit__option-face--back, .dahu-kit__option-extension').forEach(function (region) {
			region.setAttribute('aria-label', name);
		});
		this.applyInfo(card);
	};

	/** Card shown for a key, or null. */
	Configurator.prototype.infoCard = function (key) {
		var found = null;
		this.root.querySelectorAll('[data-info-key]').forEach(function (card) {
			if (card.getAttribute('data-info-key') === key) found = card;
		});
		return found;
	};

	/**
	 * Short description of the real product. The schema only sends
	 * `short_description` when it differs from `description` (content specific to
	 * the kit); absent, it is `description`. Present but empty: the product has
	 * none, and the text of the kit does not stand in for it.
	 */
	function shortDescription(option) {
		return Object.prototype.hasOwnProperty.call(option, 'short_description')
			? option.short_description || ''
			: option.description || '';
	}

	/**
	 * “more info…” link, following the description of a product: its page, in a
	 * new window. Only when the server has supplied more_info_url — the “Show
	 * more info” box ticked (WooCommerce > Dahu Kits) and the page public; never
	 * on the “None” card nor on a branch (no product). A click, Enter or Space on
	 * the link does not choose the card.
	 *
	 * @param {Object} option Product option.
	 * @return {Element|null}
	 */
	function moreInfoLink(option) {
		if (!option || !option.more_info_url) return null;
		function stop(event) { event.stopPropagation(); }
		return el('a', {
			className: 'dahu-kit__more-info-link',
			href: option.more_info_url,
			target: '_blank',
			rel: 'noopener noreferrer',
			text: t.moreInfoLink,
			onclick: stop,
			onkeydown: stop,
			onkeyup: stop
		});
	}

	function setInert(node, inert) {
		if (!node) return;
		if (inert) {
			node.setAttribute('inert', '');
			node.setAttribute('aria-hidden', 'true');
		} else {
			node.removeAttribute('inert');
			node.removeAttribute('aria-hidden');
		}
	}

	/**
	 * Applies the state of this.infoOpen to a card: classes (.is-flipped,
	 * .is-extended), data-info-face, data-info-anchor, width of the column
	 * (--dahu-kit-extend-width), faces made inert, ARIA attributes of the
	 * controls, label of “More info” (“Back” once open), content of the face or of
	 * the column being shown.
	 */
	Configurator.prototype.applyInfo = function (card) {
		var key = card.getAttribute('data-info-key');
		if (!key) return;
		var option = card._dahuInfoOption;
		var open = this.infoFor(key);
		var display = open ? open.display : null;
		var face = open ? open.face : null;

		card.classList.toggle('is-flipped', display === 'card');
		card.classList.toggle('is-extended', display === 'extended');
		if (face) card.setAttribute('data-info-face', face);
		else card.removeAttribute('data-info-face');
		if (display === 'extended') {
			card.setAttribute('data-info-anchor', open.anchor);
			card.style.setProperty('--dahu-kit-extend-width', open.width + 'px');
			card.style.setProperty('--dahu-kit-extend-shift', (open.shift || 0) + 'px');
		} else {
			card.removeAttribute('data-info-anchor');
			card.style.removeProperty('--dahu-kit-extend-width');
			card.style.removeProperty('--dahu-kit-extend-shift');
		}

		var frontFace = card.querySelector('.dahu-kit__option-face--front');
		var back = card.querySelector('.dahu-kit__option-face--back');
		var ext = card.querySelector('.dahu-kit__option-extension');
		setInert(frontFace, display === 'card');
		setInert(back, display !== 'card');
		setInert(ext, display !== 'extended');
		// Question: its area is already on the back (.dahu-kit__option-question),
		// shown by the functional stylesheet according to data-info-face.
		// Panel by its class, not firstChild: the “Help image”
		// comes before it in the back and in the extended column.
		if (display === 'card' && face !== 'question') this.fillInfo(back.querySelector(':scope > .dahu-kit__option-info'), option, face, card);
		this.fitQuestion(card);
		if (display === 'extended') this.fillInfo(ext.querySelector(':scope > .dahu-kit__option-info'), option, face, card);

		var controls = display === 'extended' ? ext.id : back.id;
		card.querySelectorAll('[data-info-action]').forEach(function (button) {
			var action = button.getAttribute('data-info-action');
			button.setAttribute('aria-expanded', open ? 'true' : 'false');
			button.setAttribute('aria-controls', controls);
			button.setAttribute('aria-pressed', face === action ? 'true' : 'false');
			button.classList.toggle('is-active', face === action);
			if (button.classList.contains('dahu-kit__more')) button.textContent = open ? t.infoBack : t.moreInfo;
		});
		// “Select” (info): only when the info is open, on the front (the
		// back carries “Back”). Button mode: it then replaces the “Select” of the front
		// (a single choice control at a time).
		var picks = card.querySelectorAll('.dahu-kit__option-tool--choose');
		picks.forEach(function (pick) { pick.hidden = !open; });
		if (picks.length) {
			var select = card.querySelector('.dahu-kit__choose');
			if (select) select.hidden = !!open;
		}
	};

	/**
	 * Control of a face equivalent to `action` (data-info-action:
	 * description = “More info” / “Back”, specs, bundled).
	 */
	function infoControl(card, side, action) {
		return card.querySelector('.dahu-kit__option-face--' + side + ' [data-info-action="' + action + '"]');
	}

	/**
	 * “Help image” of an option (the `help_image` field of the schema):
	 * figure.dahu-kit__option-help-visual[data-src] > img. The address is set as
	 * an attribute, never as HTML; real dimensions (no layout shift). Alternative
	 * text from the media library.
	 */
	function helpVisual(help) {
		return el('figure', { className: 'dahu-kit__option-help-visual', 'data-src': help.url }, [
			el('img', { src: help.url, alt: help.alt || '', width: help.width || null, height: help.height || null, loading: 'lazy', decoding: 'async' })
		]);
	}

	/** List of the products supplied (name, quantity, thumbnail), with no prices. */
	function bundledList(items) {
		return el('ul', { className: 'dahu-kit__bundled' }, (items || []).map(function (item) {
			return el('li', { className: 'dahu-kit__bundled-item' + (item.optional ? ' is-optional' : '') }, [
				item.image ? el('img', { className: 'dahu-kit__bundled-photo', src: item.image, alt: '', loading: 'lazy' }) : null,
				el('span', { className: 'dahu-kit__bundled-name', text: item.name }),
				el('span', { className: 'dahu-kit__bundled-qty', text: '× ' + item.quantity })
			]);
		}));
	}

	/**
	 * Sections offered as tabs for an option (without icons): the description
	 * always, the specifications when the product has any (has_specs), the
	 * products supplied when there are any.
	 */
	function infoFaces(option) {
		var enabled = enabledFaces(option);
		return ['description']
			.concat(option.has_specs ? ['specs'] : [])
			.concat((option.bundled || []).length ? ['bundled'] : [])
			.filter(function (face) { return enabled.indexOf(face) !== -1; });
	}

	/**
	 * Sections ENABLED for an option (the admin checkboxes): show_description,
	 * show_specs, show_bundled. Only an explicit false disables one: key absent =
	 * section shown, as it was before those checkboxes existed. The defaults of a
	 * new option (specifications and supplied products off) are written explicitly
	 * by the admin. A disabled section appears nowhere — no icon, no tab, no
	 * drawer tab. Enabled but empty, it keeps its fallback message (“No
	 * specifications”…) where there was one — except the included products: with
	 * no included product the section is not offered at all (no
	 * .dahu-kit__option-tool--bundled icon and no “No included products” tab),
	 * even when ticked.
	 */
	function enabledFaces(option) {
		if (!option) return [];
		return ['description', 'specs', 'bundled'].filter(function (face) {
			if (option['show_' + face] === false) return false;
			return face !== 'bundled' || (option.bundled || []).length > 0;
		});
	}

	/** Info section → tab of the drawer (slug from /product-tabs). */
	var DRAWER_SLUGS = { description: 'description', specs: 'caracteristiques', bundled: 'bundled' };

	/**
	 * Content of a section in the back or in the extended column.
	 *  - description: short description of the product (shortDescription());
	 *  - specs: the “caracteristiques” tab of GET /product-tabs/{id}, loaded on
	 *    the first click and kept per product (the same cache as the right-hand
	 *    column and the drawer) — a loading state in the meantime;
	 *  - bundled: products supplied (name, quantity, thumbnail), with no prices.
	 *
	 * Header: with the “Icons” toggle, the title of the section alone (the icons
	 * of the footer change section). Without icons (“More info” alone), the
	 * available sections (infoFaces()) are TABS at the level of the title — same
	 * classes, .dahu-kit__option-info-title, plus .dahu-kit__option-info-tab; the
	 * active one carries aria-selected. A single section: the title alone, as
	 * before.
	 *
	 * @param {Node}   node   .dahu-kit__option-info to fill.
	 * @param {Object} option Option.
	 * @param {string} face   Section being shown.
	 * @param {Node}   card   Card (tabs: changing section).
	 */
	/**
	 * Help image to show for an option: that of the variant CHOSEN in the
	 * component of the card (variations[].help_image), else that of the option
	 * (help_image), else null.
	 *
	 * Component: the one the card carries (_dahuInfoComponent, set by
	 * bindInfoCard()), failing that the [data-component] slot containing `node`.
	 * Not the slot alone: a flipped card is filled on every render
	 * (bindInfoCard() → applyInfo() → fillInfo()) while it is still OUTSIDE the
	 * document, closest() found no slot there, and the back lost the image of the
	 * variant on the /state answer following the choice of that variant.
	 *
	 * @param {Object} option Product option.
	 * @param {Element} node  Element of the card (or the card).
	 * @return {Object|null}
	 */
	Configurator.prototype.helpImageFor = function (option, node) {
		var cid = null;
		for (var n = node; n && n.nodeType === 1 && !cid; n = n.parentNode) {
			cid = n._dahuInfoComponent || n.getAttribute('data-component') || null;
		}
		var sel = cid ? this.selections[cid] : null;
		var items = !sel ? [] : (Array.isArray(sel) ? sel : [sel]);
		var vid = null;
		items.forEach(function (item) { if (item && item.product_id === option.product_id && item.variation_id) vid = item.variation_id; });
		var found = null;
		if (vid) (option.variations || []).forEach(function (v) { if (v.variation_id === vid && v.help_image) found = v.help_image; });
		return found || option.help_image || null;
	};

	Configurator.prototype.fillInfo = function (node, option, face, card) {
		var self = this;
		var body;
		if (face === 'specs') {
			var state = this.loadSpecs(option.product_id);
			if (state.status === 'ok') body = state.tab ? el('div', { className: 'dahu-kit__option-info-body', html: state.tab.html }) : el('p', { className: 'dahu-kit__option-info-empty', text: t.infoNoCharacteristics });
			else if (state.status === 'error') body = el('p', { className: 'dahu-kit__option-info-error', text: t.detailsError });
			else body = el('p', { className: 'dahu-kit__option-info-loading', 'aria-busy': 'true', text: t.infoLoading });
		} else if (face === 'bundled' && !(option.bundled || []).length) {
			// “products supplied” icon with no supplied product (the
			// “Icons” toggle: the three icons are always there).
			body = el('p', { className: 'dahu-kit__option-info-empty', text: t.infoNoBundled || 'No included products' });
		} else if (face === 'bundled') {
			body = bundledList(option.bundled);
		} else {
			var short = shortDescription(option);
			body = short
				? el('div', { className: 'dahu-kit__option-info-body', html: short })
				: el('p', { className: 'dahu-kit__option-info-empty', text: t.noDetails });
			// “more info…” following the description.
			var more = moreInfoLink(option);
			if (more) body.appendChild(el('p', { className: 'dahu-kit__more-info' }, [more]));
		}
		var current = face || 'description';
		var faces = this.icons ? [current] : infoFaces(option);
		var head;
		if (faces.length < 2) {
			head = el('p', { className: 'dahu-kit__option-info-title', text: infoLabel(current) });
		} else {
			var key = card ? card.getAttribute('data-info-key') : null;
			head = el('div', { className: 'dahu-kit__option-info-tabs', role: 'tablist' }, faces.map(function (tab) {
				var active = tab === current;
				return el('button', {
					type: 'button',
					className: 'dahu-kit__option-info-title dahu-kit__option-info-tab' + (active ? ' is-active' : ''),
					role: 'tab',
					'aria-selected': active ? 'true' : 'false',
					'data-info-tab': tab,
					'data-focus-key': key ? key + ':tab:' + tab : null,
					text: infoLabel(tab),
					onclick: function () { if (key && !active) self.showInfoFace(key, tab, card); }
				});
			}));
		}
		node.className = 'dahu-kit__option-info dahu-kit__option-info--' + current;
		node.setAttribute('data-info-face', current);
		// “Help image”: the Description section only, at the top
		// of the back (or of the extended column), BEFORE the panel that scrolls —
		// edge to edge with the card, like the photo of the front (functional
		// stylesheet). Removed for the other sections.
		var holder = node.parentNode;
		var visual = holder ? holder.querySelector(':scope > .dahu-kit__option-help-visual') : null;
		var help = current === 'description' ? this.helpImageFor(option, card || node) : null;
		if (!help && visual) holder.removeChild(visual);
		if (help && holder && (!visual || visual.getAttribute('data-src') !== help.url)) {
			if (visual) holder.removeChild(visual);
			holder.insertBefore(helpVisual(help), node);
		}
		// Tab that had focus: given back to its rebuilt equivalent
		// (the specifications answer arriving after the click, for instance).
		var focused = document.activeElement && node.contains(document.activeElement) ? document.activeElement.getAttribute('data-info-tab') : null;
		node.replaceChildren(head, body);
		if (focused) {
			var again = node.querySelector('[data-info-tab="' + focused + '"]');
			if (again) again.focus({ preventScroll: true });
		}
	};

	/**
	 * Tab of the open panel (without icons): changes section on the spot — content
	 * replaced, with no new flip — and keeps the focus on the chosen tab (the
	 * panel is rebuilt).
	 */
	Configurator.prototype.showInfoFace = function (key, face, card) {
		var open = this.infoFor(key);
		if (!open || open.face === face) return;
		if (face === 'specs' && card._dahuInfoOption) this.loadSpecs(card._dahuInfoOption.product_id, true);
		this.switchInfoFace(card, face, function (panel) {
			var tab = panel ? panel.querySelector('[data-info-tab="' + face + '"]') : null;
			if (tab) tab.focus({ preventScroll: true });
		});
	};

	/**
	 * Changing section on a panel that is ALREADY open (back or extended column),
	 * through an icon or a tab: no new flip — the card stays on its back, and only
	 * the content of .dahu-kit__option-info fades out then comes back
	 * (.is-changing, duration --dahu-kit-info-fade, the same fade as the info
	 * bubble). State of the controls (aria-pressed, .is-active) straight away;
	 * content replaced once it has faded. Reduced motion: replaced directly.
	 *
	 * @param {Node}     card  Open card.
	 * @param {string}   face  Section to show.
	 * @param {Function} after Called with the panel once the content is replaced.
	 */
	Configurator.prototype.switchInfoFace = function (card, face, after) {
		var self = this;
		var open = this.infoFor(card.getAttribute('data-info-key'));
		if (!open) return;
		open.face = face;
		card.setAttribute('data-info-face', face);
		card.querySelectorAll('[data-info-action]').forEach(function (button) {
			var action = button.getAttribute('data-info-action');
			button.setAttribute('aria-pressed', face === action ? 'true' : 'false');
			button.classList.toggle('is-active', face === action);
		});
		var panel = card.querySelector(open.display === 'extended'
			? '.dahu-kit__option-extension > .dahu-kit__option-info'
			: '.dahu-kit__option-face--back > .dahu-kit__option-info');
		if (card._dahuInfoFade) window.clearTimeout(card._dahuInfoFade);
		card._dahuInfoFade = null;
		function swap() {
			card._dahuInfoFade = null;
			// Closed again, or another section asked for during the fade.
			if (self.infoOpen.indexOf(open) !== -1 && open.face === face) self.applyInfo(card);
			// fillInfo() rewrites className: is-changing has already gone, and the
			// content comes back through the same transition.
			if (panel) panel.classList.remove('is-changing');
			if (after && self.infoOpen.indexOf(open) !== -1) after(panel);
		}
		var duration = panel && !reducedMotion() ? (panel.classList.add('is-changing'), transitionMs(panel)) : 0;
		if (duration) card._dahuInfoFade = window.setTimeout(swap, duration);
		else swap();
	};

	/**
	 * Specifications of a product: the same state as the right-hand column
	 * (this.infoTabsState, the productTabs() cache). Fires the request when
	 * needed; on the answer, the card open on that product is brought up to date.
	 */
	Configurator.prototype.loadSpecs = function (productId, retry) {
		var self = this;
		var state = this.infoTabsState;
		// After a failure we only try again on a gesture of the customer (retry):
		// otherwise the error response would redraw the card, which would fire
		// the request again, indefinitely.
		if (!state[productId] || (retry && state[productId].status === 'error')) {
			state[productId] = { status: 'loading' };
			productTabs(productId).then(function (data) {
				state[productId] = { status: 'ok', tab: findTab(data, 'caracteristiques') };
			}, function () {
				state[productId] = { status: 'error' };
			}).then(function () {
				// Right-hand column (square mode): the same shared state.
				if (self.infoProduct === productId && !self.vignettes) self.renderInfo(self.infoComponents);
				self.infoOpen.forEach(function (open) {
					if (open.face !== 'specs') return;
					var card = self.infoCard(open.key);
					if (card && card._dahuInfoOption && card._dahuInfoOption.product_id === productId) self.applyInfo(card);
				});
			});
		}
		return state[productId];
	};

	function reducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	/**
	 * Click on a footer control.
	 *  - “More info” (face 'more'): opens on the description, or closes again when
	 *    the card is open (it then serves as “Back”);
	 *  - icon: opens on its section; active icon: closes; another icon on a card
	 *    that is already open: changes section WITHOUT a new flip, through a fade
	 *    of the content (switchInfoFace() — earlier: the flip was replayed,
	 *    .is-reflipping), in card as well as extended display.
	 * Several cards may be open (openInfo()). None of this selects anything.
	 */
	Configurator.prototype.toggleInfo = function (key, face, card, button) {
		if (!card) return;
		// Specifications: the request goes out on the click, the card does not wait.
		if (face === 'specs' && card._dahuInfoOption) this.loadSpecs(card._dahuInfoOption.product_id, true);
		var open = this.infoFor(key);
		if (open) {
			if (face === 'more' || face === open.face) {
				// Flipped: the control that was clicked is on the back, which becomes
				// inert — focus goes back to the control that had opened it.
				this.closeInfo(open.display === 'card', key);
				return;
			}
			// The question only exists on the back: from an extended
			// extended, we close it again then reopen as a flipped card.
			if (face !== 'question' || open.display === 'card') {
				this.switchInfoFace(card, face);
				this.syncStepNav();
				return;
			}
			this.closeInfo(false, key);
		}
		this.openInfo(key, face, card, button);
	};

	/** Number of cards flipped at the same time. */
	var MAX_INFO_OPEN = 2;

	/** Open info state for a card, or null. */
	Configurator.prototype.infoFor = function (key) {
		var found = null;
		this.infoOpen.forEach(function (open) { if (open.key === key) found = open; });
		return found;
	};

	/**
	 * Opens the info of a card on a section (extracted from toggleInfo()).
	 * Several cards may be flipped at the same time, MAX_INFO_OPEN at most:
	 * beyond that, the one opened longest ago closes again. An extended column
	 * covers the neighbouring cards: it stays open on its own. The question of an
	 * add-on (face “question”) is always a flipped card, whatever the display.
	 */
	Configurator.prototype.openInfo = function (key, face, card, button) {
		var self = this;
		// opener: the control that opened it (data-info-action), to give it
		// the focus back on closing.
		var opener = face === 'more' ? (enabledFaces(card._dahuInfoOption)[0] || 'description') : face;
		var target = { key: key, face: opener, display: 'card', anchor: null, width: 0, opener: opener };
		if (this.infoMode === 'extended' && face !== 'question') {
			var fit = this.extendedFit(card);
			if (fit) {
				target.display = 'extended';
				target.anchor = fit.anchor;
				target.width = fit.width;
				target.shift = fit.shift;
			}
		}
		this.infoOpen.slice().forEach(function (other) {
			if (target.display === 'extended' || other.display === 'extended') self.closeInfo(false, other.key);
		});
		while (this.infoOpen.length >= MAX_INFO_OPEN) this.closeInfo(false, this.infoOpen[0].key);
		this.infoOpen.push(target);
		this.applyInfo(card);
		// Flipped card: focus moves to the equivalent control of the back
		// (“More info” → “Back”, icon → the same icon).
		if (target.display === 'card' && button && card.querySelector('.dahu-kit__option-face--front').contains(button)) {
			var twin = face === 'question'
				? card.querySelector('.dahu-kit__option-question .dahu-kit__answer:not([disabled])')
				: infoControl(card, 'back', button.getAttribute('data-info-action'));
			if (twin) twin.focus({ preventScroll: true });
		}
		this.syncStepNav();
	};

	/**
	 * Closes an open card again: the one for `key`, failing that the last one
	 * opened. restoreFocus: focus given back to the control of the front that had
	 * opened it (open.opener), “More info” failing that.
	 */
	Configurator.prototype.closeInfo = function (restoreFocus, key) {
		var open = key ? this.infoFor(key) : this.infoOpen[this.infoOpen.length - 1];
		if (!open) return;
		this.infoOpen.splice(this.infoOpen.indexOf(open), 1);
		var card = this.infoCard(open.key);
		if (card) {
			this.applyInfo(card);
			if (restoreFocus) {
				var target = infoControl(card, 'front', open.opener || 'description') || infoControl(card, 'front', 'description')
					// Question reopened by “Select” (openQuestion()):
					// with no info control on the front, focus goes back to the choice.
					|| card.querySelector('.dahu-kit__option-face--front .dahu-kit__choose, .dahu-kit__option-face--front .dahu-kit__option-hit[role="button"]');
				if (target) target.focus({ preventScroll: true });
			}
		}
		this.syncStepNav();
	};

	/** Closes every open card again (a click outside the cards). */
	Configurator.prototype.closeAllInfo = function () {
		while (this.infoOpen.length) this.closeInfo(false, this.infoOpen[0].key);
	};

	/**
	 * After a render: forgets the open cards that are no longer on screen (another
	 * step, option hidden), and closes again those whose question is no longer
	 * asked (variant removed, another motor).
	 */
	Configurator.prototype.pruneInfo = function () {
		var self = this;
		var stale = [];
		this.infoOpen = this.infoOpen.filter(function (open) {
			var card = self.infoCard(open.key);
			if (!card) return false;
			if (open.face === 'question' && !card.querySelector('.dahu-kit__option-question .dahu-kit__slot.is-visible')) {
				stale.push(card);
				return false;
			}
			return true;
		});
		stale.forEach(function (card) { self.applyInfo(card); });
		// Cards recreated by the render: measured once in the page.
		this.infoOpen.forEach(function (open) {
			var card = self.infoCard(open.key);
			if (card) self.fitQuestion(card);
		});
	};

	/**
	 * Question taller than its card — a card with no photo, a “Yes” answer with
	 * variants: the back is the size of the front (out of flow), and the area of
	 * the question would scroll. The card is therefore given the height that is
	 * missing (min-height) for as long as the question is shown; it is removed as
	 * soon as the card goes back to its front or changes section. Measured in the
	 * page only (a card that has just been created has no dimensions yet).
	 */
	Configurator.prototype.fitQuestion = function (card) {
		card.style.removeProperty('min-height');
		if (!card.isConnected || !card.classList.contains('is-flipped') || card.getAttribute('data-info-face') !== 'question') return;
		var zone = card.querySelector('.dahu-kit__option-face--back > .dahu-kit__option-question');
		if (!zone) return;
		var missing = zone.scrollHeight - zone.clientHeight;
		if (missing > 0) card.style.minHeight = Math.ceil(card.offsetHeight + missing) + 'px';
	};

	/**
	 * “Next” brought up to date on every opening / closing of a card, with no new
	 * render (it used to depend on the open cards; it now depends on the multiple
	 * choice alone — a call with no effect, kept).
	 */
	Configurator.prototype.syncStepNav = function () {
		var step = this.steps()[this.step];
		if (step && this.panelKey && this.stepNavNode) this.renderStepNav(this.stepNavNode, step);
	};

	/**
	 * Does the extended one fit? The card widens by two cards and two gutters
	 * (measured on the list), in overlay, and has to stay inside the panel of the
	 * configurator (.dahu-kit__panel, the picker column — including the two
	 * columns of a grouped screen).
	 *
	 * Anchor (data-info-anchor):
	 *  - “right”: the card stays in place and the column opens to its right;
	 *  - “left”: there is no room on the right; the whole thing aligns on the
	 *    right edge of the card (card shifted two cards to the left);
	 *  - “shift”: neither fits, but the panel is wide enough: the whole thing is
	 *    shifted just enough to stay inside it (the case of the cassette in the
	 *    right-hand column of a grouped screen).
	 * Shift of the card: --dahu-kit-extend-shift (px, ≤ 0).
	 *
	 * Panel narrower than three cards and two gutters (about 932 px for 300 px
	 * vignettes and a 1rem gutter, phones included) → null, falling back to a
	 * flipped card.
	 *
	 * @return {{anchor: string, width: number, shift: number}|null}
	 */
	Configurator.prototype.extendedFit = function (card) {
		if (!this.panelNode) return null;
		// Gutter of the card lists (an unanchored “add-on” card
		// is not in a list: the one of the screen counts; anchored, it
		// is in the one of its anchor component).
		var list = card.closest('.dahu-kit__options') || this.panelNode.querySelector('.dahu-kit__options');
		var style = list ? window.getComputedStyle(list) : null;
		var gap = style ? parseFloat(style.columnGap) || parseFloat(style.gap) || 0 : 0;
		var rect = card.getBoundingClientRect();
		var box = this.panelNode.getBoundingClientRect();
		if (!rect.width) return null;
		var width = 2 * (rect.width + gap);
		var total = rect.width + width;
		if (box.width + 0.5 < total) return null;
		if (rect.left + total <= box.right + 0.5) return { anchor: 'right', width: width, shift: 0 };
		if (rect.right - total >= box.left - 0.5) return { anchor: 'left', width: width, shift: -Math.round(width) };
		// Minimum shift: the whole thing hugs the right edge of the panel.
		return { anchor: 'shift', width: width, shift: Math.round(box.right - total - rect.left) };
	};

	/** Resize: the open extended one is re-evaluated (or falls back to a card). */
	Configurator.prototype.refitInfo = function () {
		var self = this;
		if (this.infoMode !== 'extended') return;
		this.infoOpen.slice().forEach(function (open) {
			// The question stays a flipped card.
			if (open.face === 'question') return;
			var card = self.infoCard(open.key);
			if (!card) return;
			// Focus is on a face that may become inert: it will move to its
			// equivalent on the other face (falling back to a card, or back to extended).
			var active = document.activeElement;
			var from = active && card.contains(active) && active.hasAttribute('data-info-action') ? active : null;
			// Measured at the normal position of the card (with no anchor shift).
			var index = self.infoOpen.indexOf(open);
			self.infoOpen.splice(index, 1);
			self.applyInfo(card);
			var fit = self.extendedFit(card);
			self.infoOpen.splice(index, 0, open);
			open.display = fit ? 'extended' : 'card';
			open.anchor = fit ? fit.anchor : null;
			open.width = fit ? fit.width : 0;
			open.shift = fit ? fit.shift : 0;
			self.applyInfo(card);
			if (from && from.closest('[inert]')) {
				var twin = infoControl(card, open.display === 'card' ? 'back' : 'front', from.getAttribute('data-info-action'));
				if (twin) twin.focus({ preventScroll: true });
			}
		});
		// An extended column stays open on its own: the last one wins.
		var extended = this.infoOpen.filter(function (open) { return open.display === 'extended'; });
		if (extended.length && this.infoOpen.length > 1) {
			var keep = extended[extended.length - 1];
			this.infoOpen.slice().forEach(function (open) { if (open !== keep) self.closeInfo(false, open.key); });
		}
	};

	/* ------------------------------------------------------------------ */
	/* Info bubble (step, range)                                           */
	/* ------------------------------------------------------------------ */

	/** CSS transition duration of a node (duration + delay, the longest), in ms. */
	function transitionMs(node) {
		var style = window.getComputedStyle(node);
		var durations = String(style.transitionDuration || '0s').split(',');
		var delays = String(style.transitionDelay || '0s').split(',');
		function ms(value) {
			value = String(value || '0s').trim();
			var number = parseFloat(value) || 0;
			return /ms$/.test(value) ? number : number * 1000;
		}
		var max = 0;
		durations.forEach(function (value, index) {
			max = Math.max(max, ms(value) + ms(delays[index] || delays[0]));
		});
		return max;
	}

	/**
	 * Panel of the info bubble, ONE per configurator, created once and never
	 * removed: the first child of .dahu-kit__panel, so just under
	 * .dahu-kit__heading (with no title row: at the top of the panel).
	 *
	 *   div.dahu-kit__hint#dahu-kit-hint-<product>   role=region, tabindex=-1,
	 *                                                .is-open, inert when closed
	 *     div.dahu-kit__hint-inner                   (collapse: overflow hidden)
	 *       div.dahu-kit__hint-box
	 *         div.dahu-kit__hint-body                (text; .is-changing = fade)
	 *         button.dahu-kit__hint-close            (cross, “Close”)
	 *
	 * Collapsing is done by the functional stylesheet (grid-template-rows 0fr →
	 * 1fr), with no height measured here.
	 */
	Configurator.prototype.createHint = function () {
		var self = this;
		this.hintBody = el('div', { className: 'dahu-kit__hint-body' });
		this.hintNode = el('div', {
			className: 'dahu-kit__hint',
			id: 'dahu-kit-hint-' + String(this.productId).replace(/[^a-zA-Z0-9_-]/g, ''),
			role: 'region',
			tabindex: '-1',
			'aria-hidden': 'true',
			inert: true
		}, [
			el('div', { className: 'dahu-kit__hint-inner' }, [
				el('div', { className: 'dahu-kit__hint-box' }, [
					this.hintBody,
					el('button', {
						type: 'button',
						className: 'dahu-kit__hint-close',
						'aria-label': t.close || 'Fermer',
						html: HINT_CLOSE_ICON,
						onclick: function () { self.closeHint(true); }
					})
				])
			])
		]);
	};

	/** Identity of a screen for the bubble: index and group (not the display mode). */
	Configurator.prototype.hintStepId = function (step) {
		return this.step + '|' + (step.group || step.components[0].id);
	};

	/**
	 * “i” of a bubble: a real button, button.dahu-kit__hint-btn [data-hint-key]
	 * (“step” or “tier:<component>:<range>”), data-focus-key “hint:<key>”,
	 * aria-expanded / aria-controls pointing at the panel, name “Information
	 * about: <title>”, .is-active when it is the one that opened the panel.
	 */
	Configurator.prototype.hintButton = function (key, title) {
		var self = this;
		var open = !!this.hint && this.hint.key === key;
		return el('button', {
			type: 'button',
			className: 'dahu-kit__hint-btn' + (open ? ' is-active' : ''),
			'data-hint-key': key,
			'data-focus-key': 'hint:' + key,
			'aria-expanded': open ? 'true' : 'false',
			'aria-controls': this.hintNode.id,
			'aria-label': hintLabel(title),
			html: HINT_ICON,
			onclick: function (event) {
				event.stopPropagation();
				self.toggleHint(key);
			}
		});
	};

	/** “i” shown (and active: not inside a hidden slot) for a key, or null. */
	Configurator.prototype.hintButtonFor = function (key) {
		var found = null;
		this.layoutNode.querySelectorAll('[data-hint-key]').forEach(function (button) {
			if (!found && button.getAttribute('data-hint-key') === key && !button.closest('[inert]')) found = button;
		});
		return found;
	};

	/**
	 * Content of the panel for a key: { nodes, label } or null (nothing left to
	 * show).
	 *  - “step”: the `info` texts of the VISIBLE components of the screen that
	 *    have one, in order; the title of the component in front of every text
	 *    only when several contribute (div.dahu-kit__hint-section >
	 *    p.dahu-kit__hint-title + div.dahu-kit__hint-text);
	 *  - “tier:…”: the description of the category (div.dahu-kit__hint-text).
	 *    No button opens it any more (the “?” popover of the range,
	 *    toggleTierHelp()).
	 */
	Configurator.prototype.hintContent = function (key) {
		if (key === 'step') {
			var list = this.hintComponents.filter(function (c) { return hasText(c.info); });
			if (!list.length) return null;
			var several = list.length > 1;
			return {
				label: hintLabel(this.hintStepName),
				nodes: list.map(function (c) {
					var text = el('div', { className: 'dahu-kit__hint-text', html: c.info });
					return several
						? el('div', { className: 'dahu-kit__hint-section' }, [el('p', { className: 'dahu-kit__hint-title', text: c.title }), text])
						: text;
				})
			};
		}
		var parts = String(key).split(':');
		if (parts[0] !== 'tier' || parts.length !== 3) return null;
		var component = this.component(parts[1]);
		var tier = null;
		((component && component.tiers) || []).forEach(function (candidate) { if (candidate.id === parts[2]) tier = candidate; });
		if (!tier || !hasText(tier.description)) return null;
		return {
			label: hintLabel(tier.label),
			nodes: [el('div', { className: 'dahu-kit__hint-text', html: tier.description })]
		};
	};

	/** aria-expanded and .is-active of every “i” shown. */
	Configurator.prototype.syncHintButtons = function () {
		var key = this.hint ? this.hint.key : null;
		this.layoutNode.querySelectorAll('[data-hint-key]').forEach(function (button) {
			var on = button.getAttribute('data-hint-key') === key;
			button.setAttribute('aria-expanded', on ? 'true' : 'false');
			button.classList.toggle('is-active', on);
		});
		if (this.hintStepButton && !this.hintStepButton.isConnected) {
			this.hintStepButton.setAttribute('aria-expanded', key === 'step' ? 'true' : 'false');
		}
	};

	/** Replaces the text of the panel; `fade`: a quick fade (.is-changing). */
	Configurator.prototype.setHintBody = function (nodes, fade) {
		var body = this.hintBody;
		var self = this;
		if (this.hintFadeTimer) window.clearTimeout(this.hintFadeTimer);
		this.hintFadeTimer = null;
		var duration = fade && !reducedMotion() ? (body.classList.add('is-changing'), transitionMs(body)) : 0;
		if (!duration) {
			body.classList.remove('is-changing');
			body.replaceChildren.apply(body, nodes);
			return;
		}
		this.hintFadeTimer = window.setTimeout(function () {
			self.hintFadeTimer = null;
			body.replaceChildren.apply(body, nodes);
			body.classList.remove('is-changing');
		}, duration);
	};

	/**
	 * Click on an “i”: opens the panel with its text; the same “i” (the panel
	 * having been opened by it) closes it again; another “i” with the panel open:
	 * the text is replaced with a fade and the panel stays open.
	 */
	Configurator.prototype.toggleHint = function (key) {
		if (this.hint && this.hint.key === key) {
			this.closeHint(true);
			return;
		}
		var content = this.hintContent(key);
		if (!content) return;
		var swap = !!this.hint;
		this.hint = { key: key, step: this.hintStep };
		this.hintNode.setAttribute('aria-label', content.label);
		// Source being shown (“step”, “tier:…”): for the theme, which may
		// style a step bubble and a range bubble differently.
		this.hintNode.setAttribute('data-hint-source', key === 'step' ? 'step' : 'tier');
		this.setHintBody(content.nodes, swap);
		this.hintNode.classList.add('is-open');
		this.hintNode.removeAttribute('inert');
		this.hintNode.removeAttribute('aria-hidden');
		this.syncHintButtons();
		if (!swap) this.revealHint();
	};

	/**
	 * Closes the panel again. restoreFocus: focus given back to the “i” that had
	 * opened it. instant: with no animation (screen change). The text stays in
	 * place during the collapse; the panel is made inert straight away, and
	 * invisible (visibility) at the end of the transition (functional stylesheet).
	 */
	Configurator.prototype.closeHint = function (restoreFocus, instant) {
		var hint = this.hint;
		if (!hint) return;
		this.hint = null;
		var node = this.hintNode;
		if (this.hintFadeTimer) {
			window.clearTimeout(this.hintFadeTimer);
			this.hintFadeTimer = null;
			this.hintBody.classList.remove('is-changing');
		}
		if (restoreFocus) {
			var button = this.hintButtonFor(hint.key);
			if (button) button.focus();
		}
		if (instant) node.classList.add('is-instant');
		node.classList.remove('is-open');
		node.setAttribute('inert', '');
		node.setAttribute('aria-hidden', 'true');
		if (instant) {
			void node.offsetWidth;
			node.classList.remove('is-instant');
		}
		this.syncHintButtons();
	};

	/**
	 * After every render: the open panel follows the screen. Its “i” has gone
	 * (component hidden by a scenario, range emptied) → collapsed; otherwise the
	 * text is updated with no animation when it has changed (a component of the
	 * screen appears with its `info`). The opening is not replayed.
	 */
	Configurator.prototype.refreshHint = function () {
		if (!this.hintNode) return;
		if (this.hint) {
			var content = this.hintButtonFor(this.hint.key) ? this.hintContent(this.hint.key) : null;
			if (!content) {
				this.closeHint(false);
			} else if (!this.hintFadeTimer) {
				var probe = el('div', {}, content.nodes);
				if (probe.innerHTML !== this.hintBody.innerHTML) this.hintBody.replaceChildren.apply(this.hintBody, Array.prototype.slice.call(probe.childNodes));
				this.hintNode.setAttribute('aria-label', content.label);
			}
		}
		this.syncHintButtons();
	};

	/**
	 * Panel open outside the window (a range “i” low in the page): a smooth scroll
	 * to it (immediate under prefers-reduced-motion), taking scroll-margin-top
	 * into account (--dahu-kit-hint-scroll-margin, see the functional stylesheet:
	 * the summary banner is sticky below 64em). Focus then moves to the panel
	 * (role=region), so that the keyboard and screen readers follow; Esc or the
	 * cross give it back to the “i”.
	 */
	Configurator.prototype.revealHint = function () {
		var node = this.hintNode;
		var rect = node.getBoundingClientRect();
		var margin = parseFloat(window.getComputedStyle(node).scrollMarginTop) || 0;
		var viewport = window.innerHeight || document.documentElement.clientHeight || 0;
		if (!viewport || (rect.top >= margin - 1 && rect.top <= viewport - 48)) return;
		if (node.scrollIntoView) node.scrollIntoView({ behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start' });
		node.focus({ preventScroll: true });
	};

	/* ------------------------------------------------------------------ */
	/* “?” popover of a range                                              */
	/* ------------------------------------------------------------------ */

	/*
	 * A system separate from the step info bubble (the panel under the header) and
	 * from the “i” of the cards: a small window that can show an image or a video
	 * on top of the text of the range.
	 *
	 *   div.dahu-kit-popover[role=dialog][aria-modal=false][hidden]
	 *       (.is-open, .has-media; --dahu-kit-popover-ratio: width / height of the
	 *       media)
	 *     div.dahu-kit-popover__box
	 *       div.dahu-kit-popover__media[data-media-type=image|video|embed]
	 *       div.dahu-kit-popover__content        (text column)
	 *         div.dahu-kit-popover__head
	 *           p.dahu-kit-popover__title        (label of the range)
	 *           button.dahu-kit-popover__close   (cross, “Close”)
	 *         div.dahu-kit-popover__text         (text of the bubble, HTML)
	 *
	 * FIXED, centred in the window, in landscape format — media on the left, edge
	 * to edge, at its own proportion (never cropped), text on the right; stacked
	 * on a phone (functional stylesheet). It used to be anchored under its “?”
	 * (above it when there was no room, .is-above), with a tip
	 * (.dahu-kit-popover__arrow, removed).
	 *
	 * ONE per configurator, a child of <body> (no container clips it). Its content
	 * is emptied when it closes: a video that is playing stops. It closes through
	 * the cross, the same “?”, Esc, a click elsewhere, a screen change or the
	 * disappearance of its “?”. Focus: on the popover when it opens, given back
	 * to the “?” when it is closed from the keyboard or through the cross.
	 */

	/**
	 * “?” of a range: button.dahu-kit__tier-help[data-tier-help-key]
	 * (“component:range”), aria-haspopup dialog, aria-expanded, name
	 * “Information about: <range>”.
	 */
	Configurator.prototype.tierHelpButton = function (component, tier) {
		var self = this;
		var key = component.id + ':' + tier.id;
		var open = !!this.tierHelp && this.tierHelp.key === key;
		return el('button', {
			type: 'button',
			className: 'dahu-kit__tier-help' + (open ? ' is-active' : ''),
			'data-tier-help-key': key,
			'data-focus-key': 'tierhelp:' + key,
			'aria-haspopup': 'dialog',
			'aria-expanded': open ? 'true' : 'false',
			'aria-controls': this.tierHelpId(),
			'aria-label': hintLabel(tier.label),
			html: HELP_ICON,
			onclick: function (event) {
				event.stopPropagation();
				self.toggleTierHelp(key);
			}
		});
	};

	Configurator.prototype.tierHelpId = function () {
		return 'dahu-kit-popover-' + String(this.productId).replace(/[^a-zA-Z0-9_-]/g, '');
	};

	Configurator.prototype.createTierHelp = function () {
		if (this.tierHelpNode) return;
		var self = this;
		this.tierHelpTitle = el('p', { className: 'dahu-kit-popover__title', id: this.tierHelpId() + '-title' });
		this.tierHelpMedia = el('div', { className: 'dahu-kit-popover__media' });
		this.tierHelpText = el('div', { className: 'dahu-kit-popover__text' });
		this.tierHelpNode = el('div', {
			className: 'dahu-kit-popover',
			id: this.tierHelpId(),
			role: 'dialog',
			'aria-modal': 'false',
			'aria-labelledby': this.tierHelpId() + '-title',
			tabindex: '-1',
			hidden: true
		}, [
			el('div', { className: 'dahu-kit-popover__box' }, [
				this.tierHelpMedia,
				el('div', { className: 'dahu-kit-popover__content' }, [
					el('div', { className: 'dahu-kit-popover__head' }, [
						this.tierHelpTitle,
						el('button', {
							type: 'button',
							className: 'dahu-kit-popover__close',
							'aria-label': t.close || 'Fermer',
							html: HINT_CLOSE_ICON,
							onclick: function () { self.closeTierHelp(true); }
						})
					]),
					this.tierHelpText
				])
			])
		]);
		document.body.appendChild(this.tierHelpNode);
	};

	/** Range of a key “component:range”, or null. */
	Configurator.prototype.tierForHelp = function (key) {
		var parts = String(key).split(':');
		var component = parts.length === 2 ? this.component(parts[0]) : null;
		var found = null;
		((component && component.tiers) || []).forEach(function (tier) { if (tier.id === parts[1]) found = tier; });
		return tierHasHelp(found) ? found : null;
	};

	/**
	 * Content of the popover for a key: { label, description, media } of a range
	 * (“component:range”), or { label, description, image } of the “More info” of
	 * an add-on question (“question:component”, questionHelp()). null: nothing to
	 * show.
	 */
	Configurator.prototype.helpContent = function (key) {
		key = String(key);
		return key.indexOf('question:') === 0 ? this.questionHelp(key.slice(9)) : this.tierForHelp(key);
	};

	/**
	 * “More info” of a yes / no question: the same popover as the “?” of a range,
	 * instead of the side drawer. Content of the single option of the add-on, as
	 * the kit already sets it:
	 *  - title: displayed name of the option (the title specific to the kit, else
	 *    the product);
	 *  - image: image of the option (specific to the kit, else the photo of the
	 *    product; the image of the chosen variant when it has one);
	 *  - text: displayed description of the option (specific to the kit, else the
	 *    short description of the product); empty: the info bubble text of the
	 *    component.
	 * Neither text nor image: no button.
	 */
	Configurator.prototype.questionHelp = function (cid) {
		var component = this.component(cid);
		var option = component && component.options ? component.options[0] : null;
		if (!option) return null;
		var sel = selectionItems(this.selections[cid])[0] || null;
		var image = option.show_image !== false ? optionImage(option, sel) : null;
		var text = hasText(option.description) ? option.description : (hasText(component.info) ? component.info : '');
		if (!image && !text) return null;
		return { label: option.name || component.title, description: text, image: image };
	};

	/** “?” shown (and active: not inside a hidden slot) for a key, or null. */
	Configurator.prototype.tierHelpButtonFor = function (key) {
		var found = null;
		this.layoutNode.querySelectorAll('[data-tier-help-key]').forEach(function (button) {
			if (!found && button.getAttribute('data-tier-help-key') === key && !button.closest('[inert]')) found = button;
		});
		return found;
	};

	Configurator.prototype.toggleTierHelp = function (key) {
		if (this.tierHelp && this.tierHelp.key === key) {
			this.closeTierHelp(true);
			return;
		}
		// Range, or question of an add-on (helpContent()).
		var tier = this.helpContent(key);
		if (!tier) return;
		this.createTierHelp();
		var self = this;
		var node = this.tierHelpNode;
		this.tierHelp = { key: key, step: this.hintStep };
		this.tierHelpTitle.textContent = tier.label;
		var media = tier.media && tier.media.html ? tier.media : null;
		this.tierHelpMedia.innerHTML = media ? media.html : ''; // HTML produced by the server only.
		// Question: photo of the product, address from the schema set as an attribute
		// (never as HTML), the same class as the image of a range.
		if (!media && tier.image) {
			this.tierHelpMedia.appendChild(el('img', { className: 'dahu-kit__popover-image', src: tier.image, alt: tier.label || '', loading: 'lazy', decoding: 'async' }));
			media = { type: 'image' };
		}
		this.tierHelpMedia.hidden = !media;
		if (media) this.tierHelpMedia.setAttribute('data-media-type', media.type);
		else this.tierHelpMedia.removeAttribute('data-media-type');
		this.tierHelpText.innerHTML = hasText(tier.description) ? tier.description : ''; // Idem.
		// Range still empty: the waiting text, as plain text.
		var placeholder = !hasText(tier.description) && !media && t.helpPlaceholder ? t.helpPlaceholder : '';
		if (placeholder) this.tierHelpText.appendChild(el('p', { className: 'dahu-kit-popover__placeholder', text: placeholder }));
		this.tierHelpText.hidden = !hasText(tier.description) && !placeholder;
		node.classList.toggle('has-media', !!media);
		// Proportion of the media known on load (image, video): the
		// width of the media column follows it.
		this.tierHelpMedia.querySelectorAll('img').forEach(function (img) {
			if (!img.complete) img.addEventListener('load', function () { if (self.tierHelp) self.placeTierHelp(); }, { once: true });
		});
		this.tierHelpMedia.querySelectorAll('video').forEach(function (video) {
			video.addEventListener('loadedmetadata', function () { if (self.tierHelp) self.placeTierHelp(); }, { once: true });
		});
		node.hidden = false;
		node.classList.remove('is-open');
		this.placeTierHelp();
		void node.offsetWidth; // The entrance (functional stylesheet) starts from the closed state.
		node.classList.add('is-open');
		this.syncTierHelpButtons();
		node.focus({ preventScroll: true });
	};

	/**
	 * Fixed and centred popover: the position comes from the functional
	 * stylesheet. What remains here is the proportion of the media
	 * (--dahu-kit-popover-ratio, width / height): the media column takes that
	 * width at the height of the popover, with no cropping. Image: real
	 * dimensions once loaded, else its attributes; video: dimensions once the
	 * metadata is read; embedded video: 16/9.
	 */
	Configurator.prototype.placeTierHelp = function () {
		var node = this.tierHelpNode;
		if (!node || !this.tierHelp) return;
		var ratio = 16 / 9;
		var img = this.tierHelpMedia.querySelector('img');
		var video = this.tierHelpMedia.querySelector('video');
		if (img) {
			var w = img.naturalWidth || parseInt(img.getAttribute('width'), 10) || 0;
			var h = img.naturalHeight || parseInt(img.getAttribute('height'), 10) || 0;
			ratio = w && h ? w / h : 1;
		} else if (video && video.videoWidth && video.videoHeight) {
			ratio = video.videoWidth / video.videoHeight;
		}
		node.style.setProperty('--dahu-kit-popover-ratio', String(Math.round(ratio * 10000) / 10000));
	};

	/** aria-expanded and .is-active of every “?” shown. */
	Configurator.prototype.syncTierHelpButtons = function () {
		var key = this.tierHelp ? this.tierHelp.key : null;
		this.layoutNode.querySelectorAll('[data-tier-help-key]').forEach(function (button) {
			var on = button.getAttribute('data-tier-help-key') === key;
			button.setAttribute('aria-expanded', on ? 'true' : 'false');
			button.classList.toggle('is-active', on);
		});
	};

	/**
	 * Closes the popover; restoreFocus: focus given back to its “?”. Content
	 * emptied (a video stops).
	 */
	Configurator.prototype.closeTierHelp = function (restoreFocus) {
		var help = this.tierHelp;
		if (!help) return;
		this.tierHelp = null;
		var node = this.tierHelpNode;
		node.classList.remove('is-open', 'has-media');
		node.hidden = true;
		this.tierHelpMedia.innerHTML = '';
		this.tierHelpMedia.removeAttribute('data-media-type');
		this.tierHelpText.innerHTML = '';
		this.syncTierHelpButtons();
		if (restoreFocus) {
			var button = this.tierHelpButtonFor(help.key);
			if (button) button.focus();
		}
	};

	/** After every render: the popover follows its “?”; no “?” left → closed. */
	Configurator.prototype.refreshTierHelp = function () {
		if (!this.tierHelp) return;
		if (!this.tierHelpButtonFor(this.tierHelp.key) || !this.helpContent(this.tierHelp.key)) {
			this.closeTierHelp(false);
			return;
		}
		this.syncTierHelpButtons();
		this.placeTierHelp();
	};

	/**
	 * Choice of the variant of a variable option.
	 *
	 * @param {Object|null} current Selection of the component when THIS option is
	 *                              the selected one, else null: choosing a variant
	 *                              then selects product and variant together.
	 *
	 * Variant required — one single rule for every option with variants (card of a
	 * single choice; card of a multiple choice; yes / no question):
	 *  - option retained WITHOUT a variant: the server holds it to be incomplete
	 *    (no price and no weight, and the screen does not move on). The
	 *    .dahu-kit__variation-required message says so under the list
	 *    (.is-required, aria-invalid), instead of a silent block;
	 *  - choosing a variant NEVER moves things on (for a while, the variant that
	 *    completed a choice did): an explicit gesture is needed — “Select” on the
	 *    card (an option already chosen = continue, continueFrom()), or “Yes”
	 *    clicked again for a question.
	 */
	Configurator.prototype.renderVariationSelect = function (component, option, current, hiddenList) {
		var self = this;
		var sel = current || {};
		// Possible variants only: neither hidden by a scenario nor out of
		// stock — absent from the list, not greyed out. The variant already
		// chosen stays listed even once out of stock, so that the customer
		// sees what is retained (the server reports the error).
		var variations = option.variations.filter(function (v) {
			if (isHidden(option.product_id, v.variation_id, hiddenList)) return false;
			return v.in_stock || v.variation_id === sel.variation_id;
		});
		var chosen = null;
		variations.forEach(function (v) { if (v.variation_id === sel.variation_id) chosen = v; });
		// Only one variant possible: the server chooses it automatically
		// (autoselect_variations(), the same rule) as soon as the product is
		// selected. No list to show — only, where applicable,
		// its “Any” attributes.
		var single = variations.length === 1;
		var required = !!current && !sel.variation_id && !single;
		var requiredId = 'dahu-kit-variation-required-' + component.id + '-' + option.product_id;
		var select = single ? null : el('select', {
			className: 'dahu-kit__select dahu-kit__variation' + (required ? ' is-required' : ''),
			'aria-label': t.chooseVariant,
			'aria-invalid': required ? 'true' : null,
			'aria-describedby': required ? requiredId : null,
			// The render following a choice recreates the list: focus is given back to it.
			'data-focus-key': variationKey(component.id, option.product_id),
			onchange: function (event) {
				var value = parseInt(event.target.value, 10);
				if (current) {
					self.select(component.id, { variation_id: value || null }, option.product_id);
				} else if (value) {
					self.setFocus(component.id);
					self.select(component.id, { product_id: option.product_id, variation_id: value }, option.product_id);
				}
			}
		}, [el('option', { value: '', text: t.chooseVariant })].concat(variations.map(function (v) {
			var node = el('option', {
				value: v.variation_id,
				selected: sel.variation_id === v.variation_id,
				text: variationOptionText(v, self.schema)
			});
			node.dahuVariation = v; // Relu au changement de mode (start()).
			return node;
		})));

		var children = select ? [select] : [];
		// .dahu-kit__error: the alert colour of the styling (theme or fallback
		// sheet); the functional stylesheet only handles the placement.
		if (required) {
			children.push(el('span', {
				className: 'dahu-kit__error dahu-kit__variation-required',
				id: requiredId,
				role: 'alert',
				text: t.variantRequired
			}));
		}

		// Variant with “Any” attribute(s): the customer states the value.
		if (chosen && chosen.any_attributes && chosen.any_attributes.length) {
			chosen.any_attributes.forEach(function (attr) {
				var attrValue = (sel.attributes || {})[attr.key] || '';
				// <span> and not <label>: this block is rendered INSIDE the card
				// of the option, itself a <label> (no valid nesting).
				children.push(el('span', { className: 'dahu-kit__any-attribute' }, [
					attr.label + ' ',
					el('select', {
						className: 'dahu-kit__select',
						'aria-label': attr.label,
						onchange: function (event) {
							var attributes = Object.assign({}, sel.attributes || {});
							attributes[attr.key] = event.target.value;
							self.select(component.id, { attributes: attributes }, option.product_id);
						}
					}, [el('option', { value: '', text: t.chooseVariant })].concat(attr.values.map(function (o) {
						return el('option', { value: o.value, selected: attrValue === o.value, text: o.label });
					})))
				]));
			});
		}
		if (!children.length) return null;
		return el('div', { className: 'dahu-kit__variation-wrap' }, children);
	};

	Configurator.prototype.renderBundleItems = function (component, option, sel) {
		var self = this;
		// Optional items (a checkbox) and mandatory items whose
		// variant is still to be chosen (several variants allowed).
		var configurable = option.bundle_items.filter(function (item) {
			return item.optional || item.variations.length > 1;
		});
		if (!configurable.length) return null;
		var bundleSel = sel.bundle || {};

		var rows = configurable.map(function (item) {
			var conf = bundleSel[item.bundled_item_id] || {};
			var included = !item.optional || !!conf.selected;
			var checkbox = item.optional ? el('input', {
				type: 'checkbox',
				checked: !!conf.selected,
				onchange: function (event) {
					var bundle = Object.assign({}, sel.bundle || {});
					bundle[item.bundled_item_id] = Object.assign({}, conf, { selected: event.target.checked });
					self.select(component.id, { bundle: bundle }, option.product_id);
				}
			}) : null;
			var children = [el('label', { className: 'dahu-kit__bundle-item-label' }, [
				checkbox,
				el('span', { text: item.title }),
				item.price_html ? el('span', { className: 'price', html: item.price_html }) : null
			])];
			if (included && item.variations.length) {
				children.push(el('select', {
					className: 'dahu-kit__select',
					onchange: function (event) {
						var bundle = Object.assign({}, sel.bundle || {});
						bundle[item.bundled_item_id] = Object.assign({}, conf, { selected: true, variation_id: parseInt(event.target.value, 10) || 0 });
						self.select(component.id, { bundle: bundle }, option.product_id);
					}
				}, [el('option', { value: '', text: t.chooseVariant })].concat(item.variations.map(function (v) {
					var node = el('option', {
						value: v.variation_id,
						selected: conf.variation_id === v.variation_id,
						text: variationOptionText(v, self.schema)
					});
					node.dahuVariation = v; // Relu au changement de mode (start()).
					return node;
				}))));
			}
			return el('li', { className: 'dahu-kit__bundle-item' }, children);
		});

		return el('div', { className: 'dahu-kit__bundle' }, [
			el('p', { className: 'dahu-kit__bundle-title', text: t.bundleOptional }),
			el('ul', { className: 'dahu-kit__bundle-items' }, rows)
		]);
	};

	Configurator.prototype.renderQuantity = function (component, sel) {
		var self = this;
		return el('label', { className: 'dahu-kit__quantity' }, [
			t.quantity + ' ',
			el('input', {
				type: 'number',
				min: component.quantity_min,
				max: component.quantity_max,
				step: 1,
				value: sel.quantity || component.quantity_min,
				onchange: function (event) {
					self.select(component.id, { quantity: parseInt(event.target.value, 10) || component.quantity_min });
				}
			})
		]);
	};

	Configurator.prototype.renderErrors = function (cid) {
		var errors = (this.validation.errors || []).filter(function (e) { return cid === null || e.component === cid; });
		// Reopening a saved configuration: what has
		// changed since, at the step concerned (the Summary groups them apart).
		var restored = cid !== null && this.restoreIssues && this.restoreIssues[cid] ? this.restoreIssues[cid] : [];
		if (!errors.length && !restored.length) return null;
		return el('ul', { className: 'dahu-kit__errors' }, restored.map(function (text) {
			return el('li', { className: 'dahu-kit__restore-issue', text: text });
		}).concat(errors.map(function (e) {
			return el('li', { text: e.message });
		})));
	};

	/** Row of the summary: component (back to its screen), value, price. */
	Configurator.prototype.renderSummaryRow = function (c, index, value, line) {
		var self = this;
		return el('tr', { className: isPath(c) ? 'dahu-kit__summary-path' : null }, [
			el('th', { scope: 'row' }, [el('button', {
				type: 'button',
				className: 'dahu-kit__summary-edit',
				text: c.title,
				// From the summary, going back by construction.
				// Still goes through goToStep(): a single point
				// of entry kept for every screen change.
				onclick: function () { self.goToStep(index); }
			})]),
			el('td', { text: value }),
			el('td', { className: 'price', html: line ? line.price_html : '' })
		]);
	};

	Configurator.prototype.renderSummary = function (steps) {
		var self = this;
		var quote = this.quote || { line_items: [], price: {}, weight: {} };
		// Quote rows per component: several for a multiple choice.
		var byComponent = {};
		quote.line_items.forEach(function (line) {
			(byComponent[line.component_id] = byComponent[line.component_id] || []).push(line);
		});

		var rows = [];
		steps.forEach(function (step, index) {
			step.components.forEach(function (c) {
				var lines = byComponent[c.id] || [];
				// Multiple choice: one row per option chosen.
				if (isMultiple(c) && lines.length > 1) {
					lines.slice(0, -1).forEach(function (extra) {
						rows.push(self.renderSummaryRow(c, index, extra.name + (extra.quantity > 1 ? ' × ' + extra.quantity : ''), extra));
					});
				}
				var line = lines.length ? lines[lines.length - 1] : null;
				var value = '—';
				if (line) {
					value = line.name + (line.quantity > 1 ? ' × ' + line.quantity : '');
				} else if (isPath(c)) {
					// Branch: no quote row, so we repeat the
					// chosen label.
					var sel = self.selections[c.id];
					c.options.forEach(function (o) { if (sel && o.id === sel.option_id) value = o.name; });
				}
				rows.push(self.renderSummaryRow(c, index, value, line));
			});
		});

		var missingTitles = (this.validation.missing || []).map(function (cid) {
			var c = self.component(cid);
			return c ? c.title : cid;
		});

		var children = [
			this.stepHeadingNode ? null : el('h2', { className: 'dahu-kit__step-title', text: t.summary }),
			this.renderRestoreNotice(),
			el('div', { className: 'dahu-kit__table-wrap' }, [
				el('table', { className: 'dahu-kit__summary shop_table' }, [
					el('thead', null, [el('tr', null, [
						el('th', { text: t.component }), el('th', { text: t.product }), el('th', { text: t.price })
					])]),
					el('tbody', null, rows),
					el('tfoot', null, [
						el('tr', null, [el('th', { colspan: 2, text: t.total }), el('td', { className: 'price dahu-kit__total', html: quote.price.html || '' })]),
						el('tr', null, [el('th', { colspan: 2, text: t.weight }), el('td', { text: quote.weight.unit ? formatWeight(quote.weight.total, quote.weight.unit) : '' })])
					])
				])
			])
		];

		if (missingTitles.length) {
			children.push(el('p', { className: 'dahu-kit__incomplete', text: t.incomplete + ' ' + missingTitles.join(', ') }));
		}
		children.push(this.renderErrors(null));

		children.push(el('div', { className: 'dahu-kit__cart' }, [
			el('label', { className: 'dahu-kit__quantity' }, [
				t.quantity + ' ',
				el('input', {
					type: 'number', min: 1, step: 1, value: this.quantity,
					onchange: function (event) {
						self.quantity = Math.max(1, parseInt(event.target.value, 10) || 1);
						self.refresh();
					}
				})
			]),
			el('button', {
				type: 'button',
				className: 'button alt single_add_to_cart_button dahu-kit__add',
				text: this.adding ? t.adding : t.addToCart,
				disabled: this.busy || this.adding || !this.validation.valid,
				onclick: function (event) { self.addToCart(event.currentTarget); }
			}),
			// Block supplied by the theme (the
			// dahu_composite_summary_aside_html filter: reassurance…), to the right of the
			// button; HTML already filtered on the server.
			cfg.summaryAside ? el('div', { className: 'dahu-kit__cart-aside', html: cfg.summaryAside }) : null
		]));
		children.push(this.renderSaveBlock());

		if (this.message) {
			var msg = el('div', { className: 'dahu-kit__message is-' + this.message.type }, [el('span', { text: this.message.text })]);
			if (this.message.type === 'success') {
				msg.appendChild(document.createTextNode(' '));
				msg.appendChild(el('a', { href: cfg.cartUrl, className: 'button wc-forward', text: t.viewCart }));
			}
			if (this.message.details) {
				msg.appendChild(el('ul', null, this.message.details.map(function (d) { return el('li', { text: d }); })));
			}
			children.push(msg);
		}

		return el('section', { className: 'dahu-kit__step dahu-kit__step--summary' }, children);
	};


	/**
	 * Head of the Summary of a reopened configuration: its name and everything
	 * that has changed since it was saved, step by step (every heading leads back
	 * to its screen). Nothing has changed: the name alone.
	 */
	Configurator.prototype.renderRestoreNotice = function () {
		var notice = this.restoreNotice;
		if (!notice) return null;
		var self = this;
		var steps = this.steps();
		var items = (notice.general || []).map(function (text) { return el('li', { text: text }); });
		Object.keys(this.restoreIssues || {}).forEach(function (cid) {
			var c = self.component(cid);
			var index = -1;
			steps.forEach(function (step, i) { if (step.components.some(function (x) { return x.id === cid; })) index = i; });
			(self.restoreIssues[cid] || []).forEach(function (text) {
				items.push(el('li', null, [
					index === -1 ? el('strong', { text: (c ? c.title : cid) + ' : ' }) : el('button', {
						type: 'button',
						className: 'dahu-kit__summary-edit',
						text: (c ? c.title : cid),
						onclick: function () { self.goToStep(index); }
					}),
					index === -1 ? null : ' : ',
					text
				]));
			});
		});
		var title = (t.savedOpened || '%s').replace('%s', notice.name);
		return el('div', { className: 'dahu-kit__restore' + (items.length ? ' has-issues' : '') }, [
			el('p', { className: 'dahu-kit__restore-title', text: title }),
			items.length ? el('p', { className: 'dahu-kit__restore-intro', text: t.savedChanged }) : null,
			items.length ? el('ul', { className: 'dahu-kit__restore-issues' }, items) : null
		]);
	};

	/**
	 * “Save my configuration”. Logged-in customer: an optional name (empty:
	 * today's date) then POST /saved; only a complete configuration is saved (the
	 * same condition as adding to the cart). Visitor: the account creation form
	 * (renderGuestSave()); without it (cfg.guestSave false), an invitation to log
	 * in, with the configuration kept in this tab and picked up again at the
	 * Summary on the way back (resumeSaved()).
	 */
	Configurator.prototype.renderSaveBlock = function () {
		if (!cfg.savedEnabled) return null;
		var self = this;
		var children = [el('p', { className: 'dahu-kit__save-title', text: t.saveTitle })];
		if (!cfg.loggedIn) {
			if (cfg.guestSave) return el('div', { className: 'dahu-kit__save' }, children.concat(this.renderGuestSave()));
			children.push(el('p', { className: 'dahu-kit__save-text', text: t.saveLoginPrompt }));
			children.push(el('div', { className: 'dahu-kit__buttons' }, [this.loginLink(false)]));
			return el('div', { className: 'dahu-kit__save' }, children);
		}
		children.push(el('div', { className: 'dahu-kit__buttons dahu-kit__save-form' }, [
			el('label', { className: 'dahu-kit__save-name' }, [
				el('span', { className: 'screen-reader-text', text: t.saveName }),
				el('input', {
					type: 'text',
					maxlength: '80',
					value: this.saveName,
					placeholder: t.saveNamePlaceholder,
					'data-focus-key': 'summary:save-name',
					oninput: function (event) { self.saveName = event.target.value; }
				})
			]),
			el('button', {
				type: 'button',
				className: 'button dahu-kit__save-button',
				'data-focus-key': 'summary:save',
				text: this.saving ? t.saving : t.saveButton,
				disabled: this.busy || this.saving || !this.validation.valid,
				onclick: function () { self.saveConfiguration(); }
			})
		]));
		if (!this.validation.valid) children.push(el('p', { className: 'dahu-kit__save-text', text: t.saveIncomplete }));
		var msg = this.saveMessage;
		if (msg) {
			children.push(el('p', { className: 'dahu-kit__message is-' + msg.type }, [
				el('span', { text: msg.text }),
				msg.url ? ' ' : null,
				msg.url ? el('a', { href: msg.url, text: t.savedSee }) : null
			]));
		}
		return el('div', { className: 'dahu-kit__save' }, children);
	};

	Configurator.prototype.saveConfiguration = function () {
		var self = this;
		this.saving = true;
		this.saveMessage = null;
		this.render();
		api('POST', 'saved', Object.assign(this.payload(), { name: this.saveName })).then(function (data) {
			self.saving = false;
			self.saveName = '';
			self.saveMessage = { type: 'success', text: (t.saved || '%s').replace('%s', data.name), url: data.account_url };
			self.render();
		}).catch(function (error) {
			self.saving = false;
			self.saveMessage = { type: 'error', text: error.message };
			self.render();
		});
	};

	/**
	 * “Log in” of the save block (visitor): the configuration is kept in this tab
	 * for the duration of the login. autosave: it will be saved automatically on
	 * the way back (e-mail already known); otherwise the customer comes back to
	 * the Summary and names it themselves. Only the selections are kept — never
	 * what was entered in the form.
	 */
	Configurator.prototype.loginLink = function (autosave) {
		var self = this;
		return el('a', {
			href: cfg.loginUrl,
			className: 'button dahu-kit__save-login',
			'data-focus-key': 'summary:save-login',
			text: t.saveLogin,
			onclick: function () {
				storePending(self.productId, { selections: self.selections, quantity: self.quantity, autosave: !!autosave });
			}
		});
	};

	/**
	 * Save block of the visitor. Three states:
	 *  - folded: an invitation and “Create my account and save” (unfolds the form;
	 *    there is no “Log in” beside it any more);
	 *  - form: the fields given by the server (cfg.guestFields — first name, last
	 *    name, e-mail, phone, all required), a consent checkbox never pre-ticked,
	 *    and an off-screen honeypot field;
	 *  - outcome: account created (a message, a link to the account), or e-mail
	 *    already known (a message and “Log in”, with the configuration following).
	 *
	 * Nothing is decided here: the server revalidates every field, the consent and
	 * the configuration (POST /saved-guest). The check made before submitting only
	 * saves a round trip for an empty field.
	 *
	 * @return {Array} Nodes to place under the title of the block.
	 */
	Configurator.prototype.renderGuestSave = function () {
		var self = this;
		var result = this.guestResult;
		var nodes = [];

		if (result && result.type === 'created') {
			var text = (result.mailSent ? t.guestCreated : t.guestCreatedNoMail).replace('%1$s', result.name).replace('%2$s', result.email);
			var link = result.mailSent
				? (result.url ? el('a', { href: result.url, text: t.savedSee }) : null)
				: (cfg.lostPasswordUrl ? el('a', { href: cfg.lostPasswordUrl, text: t.guestSetPassword }) : null);
			nodes.push(el('p', { className: 'dahu-kit__message is-success', role: 'status', tabindex: '-1', 'data-focus-key': 'summary:save-result' }, [
				el('span', { text: text }),
				link ? ' ' : null,
				link
			]));
			return nodes;
		}

		nodes.push(el('p', { className: 'dahu-kit__save-text', text: t.guestIntro }));

		if (!this.guestOpen) {
			// “Already a customer? Log in” removed: a duplicate. An
			// e-mail that already has an account is offered the login when the
			// form is submitted (the “exists” case below), with the configuration kept.
			nodes.push(el('div', { className: 'dahu-kit__buttons dahu-kit__save-form' }, [
				el('button', {
					type: 'button',
					className: 'button dahu-kit__save-button',
					'data-focus-key': 'summary:save',
					text: t.guestOpen || t.saveButton,
					disabled: this.busy || !this.validation.valid,
					onclick: function () {
						self.guestOpen = true;
						self.render();
						var first = self.panelNode.querySelector('.dahu-kit__save-guest input');
						if (first) first.focus();
					}
				})
			]));
			if (!this.validation.valid) nodes.push(el('p', { className: 'dahu-kit__save-text', text: t.saveIncomplete }));
			return nodes;
		}

		var prefix = 'dahu-kit-save-' + this.productId + '-';
		var errors = this.guestErrors || {};
		var fields = (cfg.guestFields || []).map(function (field) {
			var id = prefix + field.key;
			var error = errors[field.key];
			return el('div', { className: 'dahu-kit__save-field dahu-kit__save-field--' + field.key + (error ? ' has-error' : '') }, [
				el('label', { className: 'dahu-kit__save-label', 'for': id }, [
					field.label,
					field.required ? el('abbr', { className: 'dahu-kit__save-required', title: t.guestRequired, text: ' *' }) : null
				]),
				el('span', { className: 'dahu-kit__save-name' }, [el('input', {
					type: field.type || 'text',
					id: id,
					name: field.key,
					maxlength: String(field.max || 200),
					autocomplete: field.autocomplete || 'off',
					value: self.guestValues[field.key] || '',
					required: !!field.required,
					'aria-required': field.required ? 'true' : null,
					'aria-invalid': error ? 'true' : null,
					'aria-describedby': error ? id + '-error' : null,
					'data-focus-key': 'summary:guest:' + field.key,
					oninput: function (event) { self.guestValues[field.key] = event.target.value; }
				})]),
				error ? el('span', { className: 'dahu-kit__save-field-error', id: id + '-error', text: error }) : null
			]);
		});

		var trapId = prefix + 'site';
		var consentId = prefix + 'consent';
		var form = el('form', {
			className: 'dahu-kit__save-guest',
			novalidate: true,
			onsubmit: function (event) {
				event.preventDefault();
				self.saveAsGuest();
			}
		}, [
			el('div', { className: 'dahu-kit__save-fields' }, fields),
			// Honeypot field: off screen, out of the tab order, ignored by screen
			// readers and by autofill. Filled in = a robot.
			el('div', { className: 'dahu-kit__save-trap', 'aria-hidden': 'true' }, [
				el('label', { 'for': trapId, text: 'Site' }),
				el('input', {
					type: 'text',
					id: trapId,
					name: cfg.guestTrap || 'dahu_kit_site',
					tabindex: '-1',
					autocomplete: 'off',
					value: this.guestTrap,
					oninput: function (event) { self.guestTrap = event.target.value; }
				})
			]),
			el('div', { className: 'dahu-kit__save-consent' + (errors.consent ? ' has-error' : '') }, [
				el('input', {
					type: 'checkbox',
					id: consentId,
					name: 'consent',
					required: true,
					'aria-invalid': errors.consent ? 'true' : null,
					'aria-describedby': errors.consent ? consentId + '-error' : null,
					'data-focus-key': 'summary:guest:consent',
					onchange: function (event) { self.guestConsent = !!event.target.checked; }
				}),
				el('label', { 'for': consentId }, [
					t.guestConsent,
					el('abbr', { className: 'dahu-kit__save-required', title: t.guestRequired, text: ' *' })
				]),
				errors.consent ? el('span', { className: 'dahu-kit__save-field-error', id: consentId + '-error', text: errors.consent }) : null
			]),
			el('p', { className: 'dahu-kit__save-text dahu-kit__save-note', text: t.guestRequiredNote }),
			el('div', { className: 'dahu-kit__buttons dahu-kit__save-form' }, [
				el('button', {
					type: 'submit',
					className: 'button dahu-kit__save-button',
					'data-focus-key': 'summary:guest:submit',
					text: this.saving ? t.saving : t.guestSubmit,
					disabled: this.busy || this.saving || !this.validation.valid
				}),
				el('button', {
					type: 'button',
					className: 'dahu-kit__save-cancel',
					'data-focus-key': 'summary:guest:cancel',
					text: t.guestCancel,
					disabled: this.saving,
					onclick: function () {
						self.guestOpen = false;
						self.guestErrors = {};
						self.guestResult = null;
						self.saveMessage = null;
						self.render();
					}
				})
			])
		]);
		// Box ticked: the property (the “checked” attribute does not follow the state).
		form.querySelector('#' + consentId).checked = !!this.guestConsent;
		nodes.push(form);

		if (!this.validation.valid) nodes.push(el('p', { className: 'dahu-kit__save-text', text: t.saveIncomplete }));

		if (result && result.type === 'exists') {
			// E-mail already known: no second account. The login keeps the
			// configuration, which is saved automatically on the way back.
			nodes.push(el('div', { className: 'dahu-kit__save-exists' }, [
				el('p', { className: 'dahu-kit__message is-notice', role: 'alert' }, [el('span', { text: result.text })]),
				el('div', { className: 'dahu-kit__buttons' }, [this.loginLink(true)])
			]));
		} else if (this.saveMessage) {
			nodes.push(el('p', { className: 'dahu-kit__message is-' + this.saveMessage.type, role: 'alert' }, [
				el('span', { text: this.saveMessage.text })
			]));
		}
		return nodes;
	};

	/**
	 * Submitting the visitor form: account creation then save, in a single call
	 * (POST /saved-guest). A JSON body — nothing in the address.
	 */
	Configurator.prototype.saveAsGuest = function () {
		var self = this;
		if (this.saving) return;
		var values = {};
		var errors = {};
		(cfg.guestFields || []).forEach(function (field) {
			var value = String(self.guestValues[field.key] || '').trim();
			values[field.key] = value;
			if (field.required && !value) errors[field.key] = t.guestFieldRequired;
			else if (field.key === 'email' && value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) errors[field.key] = t.guestEmailInvalid;
		});
		if (!this.guestConsent) errors.consent = t.guestConsentRequired;

		this.guestResult = null;
		this.saveMessage = null;
		if (Object.keys(errors).length) {
			this.guestFail(errors, Object.keys(errors).length === 1 && errors.consent ? errors.consent : t.guestCheckFields);
			return;
		}

		this.guestErrors = {};
		this.saving = true;
		this.render();
		var body = Object.assign(this.payload(), values, { consent: true, nonce: cfg.guestNonce });
		body[cfg.guestTrap || 'dahu_kit_site'] = this.guestTrap;
		api('POST', 'saved-guest', body).then(function (data) {
			self.saving = false;
			// Nothing that was entered stays in memory.
			self.guestValues = {};
			self.guestConsent = false;
			self.guestOpen = false;
			self.guestResult = {
				type: 'created',
				name: data.name || '',
				email: data.email || values.email,
				url: data.account_url || cfg.accountKitsUrl,
				mailSent: data.mail_sent !== false
			};
			self.render();
			var done = self.panelNode.querySelector('[data-focus-key="summary:save-result"]');
			if (done) done.focus();
		}).catch(function (error) {
			self.saving = false;
			var code = error.data && error.data.code;
			if (code === 'dahu_kit_account_exists') {
				self.guestResult = { type: 'exists', text: error.message };
				self.render();
				var login = self.panelNode.querySelector('.dahu-kit__save-exists .dahu-kit__save-login');
				if (login) login.focus();
				return;
			}
			var fields = error.data && error.data.data && error.data.data.fields;
			self.guestFail(fields && typeof fields === 'object' ? fields : {}, error.message);
		});
	};

	/**
	 * Visitor form refused: errors per field, focus on the first one. Consent
	 * checkbox the only thing at fault: its message is already under the box, so
	 * there is no second identical message at the foot of the form.
	 */
	Configurator.prototype.guestFail = function (errors, message) {
		var keys = Object.keys(errors);
		this.guestErrors = errors;
		this.saveMessage = keys.length === 1 && errors.consent ? null : { type: 'error', text: message };
		this.render();
		var first = this.panelNode.querySelector('.dahu-kit__save-guest [aria-invalid="true"]');
		if (first) first.focus();
	};

	Configurator.prototype.addToCart = function (button) {
		var self = this;
		this.adding = true;
		this.message = null;
		this.render();

		api('POST', 'add-to-cart', this.payload()).then(function (data) {
			self.adding = false;
			self.message = { type: 'success', text: t.added };
			self.render();
			if (window.jQuery) {
				window.jQuery(document.body).trigger('added_to_cart', [data.fragments, data.cart_hash, window.jQuery(button)]);
			}
		}).catch(function (error) {
			self.adding = false;
			var details = [];
			var data = error.data && error.data.data;
			if (data && data.validation) {
				self.validation = data.validation;
				(data.validation.errors || []).forEach(function (e) { details.push(e.message); });
			}
			if (data && data.notices) details = details.concat(data.notices);
			self.message = { type: 'error', text: error.message, details: details.length ? details : null };
			self.render();
		});
	};

	/* ------------------------------------------------------------------ */
	/* “More info” drawer (vignettes)                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Right-hand side drawer showing the detailed page of a product option
	 * (Description / Installation & uses / Specifications / Technology).
	 *
	 * Behaviour modelled on the tab drawer of the theme's product pages: a slide
	 * in from the right edge in 300 ms ease-out, a clickable dark veil, page
	 * scrolling locked with compensation for the bar, a close cross, Esc, focus
	 * moved to the title then given back to the button it came from, and the panel
	 * removed from the render only at the end of the transition. This is not a
	 * modal: focus is not trapped.
	 *
	 * A standalone implementation: no file of the theme is required. The content
	 * comes from GET /product-tabs/{id}, which uses the theme's rendering when
	 * there is one and otherwise falls back on the native WooCommerce data.
	 */
	var Drawer = (function () {
		var panel, overlay, closeButton, titleNode, tabsNode, bodyNode;
		var trigger = null;
		var open = false;
		var scrollLocked = false;
		var requestId = 0;
		var cache = {};
		// Content supplied by the configurator (openContent()): the
		// question of an add-on on mobile. build() rebuilds the content, onClose()
		// is called on closing.
		var content = null;
		var reduced = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };

		function build() {
			if (panel) return;

			titleNode = el('h2', { className: 'dahu-kit-drawer__title', tabindex: '-1' });
			tabsNode = el('div', { className: 'dahu-kit-drawer__tabs' });
			bodyNode = el('div', { className: 'dahu-kit-drawer__body entry-content' });
			closeButton = el('button', {
				type: 'button',
				className: 'dahu-kit-drawer__close',
				'aria-label': t.close,
				onclick: function () { hide(true); }
			}, ['×']);

			panel = el('section', {
				className: 'dahu-kit-drawer',
				role: 'region',
				'aria-label': t.moreInfo,
				hidden: true
			}, [
				closeButton,
				el('div', { className: 'dahu-kit-drawer__inner' }, [titleNode, tabsNode, bodyNode])
			]);

			overlay = el('div', {
				className: 'dahu-kit-drawer__overlay',
				hidden: true,
				onclick: function () { hide(true); }
			});

			// End of the closing animation: the drawer is only removed from the
			// render at that moment.
			panel.addEventListener('transitionend', function (event) {
				if (event.target === panel && event.propertyName === 'transform') detach();
			});

			document.addEventListener('keydown', function (event) {
				if (event.key === 'Escape' && open) hide(true);
			});

			document.body.appendChild(overlay);
			document.body.appendChild(panel);
		}

		function lockScroll() {
			if (scrollLocked) return;
			var gap = window.innerWidth - document.documentElement.clientWidth;
			document.body.style.paddingRight = gap > 0 ? gap + 'px' : '';
			document.body.classList.add('dahu-kit-drawer-is-open');
			scrollLocked = true;
		}

		function unlockScroll() {
			if (!scrollLocked) return;
			document.body.classList.remove('dahu-kit-drawer-is-open');
			document.body.style.paddingRight = '';
			scrollLocked = false;
		}

		function detach() {
			if (open) return; // Rouvert entre-temps.
			panel.hidden = true;
			overlay.hidden = true;
		}

		function hide(restoreFocus) {
			if (!open) return;
			open = false;
			requestId++;
			panel.classList.remove('is-open');
			unlockScroll();
			if (reduced.matches) detach();
			// Element to give focus back to: supplied as it is, or found again on
			// closing (a function: the card may have been redrawn in the meantime).
			var target = typeof trigger === 'function' ? trigger() : trigger;
			if (restoreFocus && target && target.isConnected) target.focus({ preventScroll: true });
			trigger = null;
			endContent();
		}

		function endContent() {
			var ended = content;
			content = null;
			if (ended && ended.onClose) ended.onClose();
		}

		/**
		 * Opening the panel (description or supplied content): the same animation,
		 * the same veil, the same scroll locking.
		 */
		function reveal() {
			if (open) return;
			panel.hidden = false;
			overlay.hidden = false;
			lockScroll();
			void panel.offsetWidth; // Reflow : la transition part de translateX(100%).
			panel.classList.add('is-open');
			open = true;
		}

		/**
		 * Content supplied by the configurator: on mobile, the question of an
		 * add-on in place of flipping the card. The same drawer as
		 * “Description”: animation, veil, scroll locking, cross, click on the
		 * veil and Esc to close, focus inside the drawer then given back to the
		 * card. No tabs.
		 *
		 * @param {string}   name     Title (chosen product).
		 * @param {Function} make     Returns the nodes of the content (called on
		 *                            opening and on every refreshContent()).
		 * @param {Element|Function} source Element that receives the focus on
		 *                            closing, or a function returning it.
		 * @param {Function} onClose  Called on closing.
		 */
		function openContent(name, make, source, onClose) {
			build();
			endContent();
			content = { make: make, onClose: onClose || null };
			trigger = source || null;
			requestId++;
			panel.setAttribute('aria-label', name || t.moreInfo);
			titleNode.textContent = name || '';
			tabsNode.innerHTML = '';
			tabsNode.hidden = true;
			fill();
			reveal();
			panel.scrollTop = 0;
			var first = bodyNode.querySelector('button:not([disabled]), select, input');
			(first || titleNode).focus({ preventScroll: true });
		}

		/** Supplied content built again (the state of the configurator has changed), focus kept. */
		function refreshContent() {
			if (!open || !content) return;
			fill();
		}

		function fill() {
			var focused = document.activeElement && bodyNode.contains(document.activeElement)
				? document.activeElement.getAttribute('data-focus-key') : null;
			bodyNode.innerHTML = '';
			(content.make() || []).forEach(function (node) { if (node) bodyNode.appendChild(node); });
			if (focused) {
				var again = bodyNode.querySelector('[data-focus-key="' + focused + '"]');
				if (again) again.focus({ preventScroll: true });
			}
		}

		/**
		 * Description tab: a line break after every end of sentence. End of
		 * sentence = a full stop, space(s), then a CAPITAL: “2.5”, “approx. 3 km”
		 * or “etc. and” do not break. Works on the text nodes (the server's HTML
		 * is not rewritten), outside pre / code. A sentence may end in one node
		 * and the next begin in the following one (“end.</strong> Next”,
		 * “end. <strong>Next”): broken too, as long as both are in the same block
		 * (not between two paragraphs, which are separated already).
		 */
		function breakSentences(root) {
			var re = /\.\s+(?=[A-ZÀ-ÖØ-Þ])/g;
			var BLOCK = 'p, li, dd, dt, td, th, h1, h2, h3, h4, h5, h6, blockquote, div';
			var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
			var nodes = [];
			while (walker.nextNode()) {
				if (!walker.currentNode.parentNode.closest('pre, code')) nodes.push(walker.currentNode);
			}
			// Breaks between two nodes, spotted before any modification.
			var leading = nodes.map(function (node, index) {
				if (!index) return false;
				var prev = nodes[index - 1];
				var before = prev.nodeValue;
				var after = node.nodeValue;
				return /\.\s*$/.test(before)
					&& /^\s*[A-ZÀ-ÖØ-Þ]/.test(after)
					&& (/\s$/.test(before) || /^\s/.test(after))
					&& prev.parentNode.closest(BLOCK) === node.parentNode.closest(BLOCK);
			});
			// Spaces around those breaks: removed before the nodes are
			// replaced.
			nodes.forEach(function (node, index) {
				if (!leading[index]) return;
				nodes[index - 1].nodeValue = nodes[index - 1].nodeValue.replace(/\s+$/, '');
				node.nodeValue = node.nodeValue.replace(/^\s+/, '');
			});
			nodes.forEach(function (node, index) {
				var parts = node.nodeValue.split(re);
				if (parts.length < 2 && !leading[index]) return;
				var fragment = document.createDocumentFragment();
				if (leading[index]) fragment.appendChild(document.createElement('br'));
				parts.forEach(function (part, i) {
					if (i > 0) fragment.appendChild(document.createElement('br'));
					fragment.appendChild(document.createTextNode(i < parts.length - 1 ? part + '.' : part));
				});
				node.parentNode.replaceChild(fragment, node);
			});
		}

		/**
		 * Tabs of the drawer: those of the product (GET /product-tabs:
		 * description, specifications), then “Products supplied” when there are
		 * any.
		 *
		 * opts.all (side + the “Icons” toggle): all three sections are always
		 * there, like the three icons — an empty section says “none…”. opts.tab:
		 * the section opened first (description | specs | bundled), that of the
		 * icon that was clicked.
		 */
		function renderTabs(data, opts) {
			opts = opts || {};
			tabsNode.innerHTML = '';
			bodyNode.innerHTML = '';

			var tabs = ((data && data.tabs) || []).slice();
			var has = function (slug) { return tabs.some(function (tab) { return tab.slug === slug; }); };
			if (opts.all && !has('description')) tabs.unshift({ slug: 'description', label: t.infoDescription, empty: t.noDetails });
			if (opts.all && !has('caracteristiques')) tabs.push({ slug: 'caracteristiques', label: t.infoCharacteristics, empty: t.infoNoCharacteristics });
			if ((opts.bundled || []).length) tabs.push({ slug: 'bundled', label: t.infoBundled, node: bundledList(opts.bundled) });
			else if (opts.all) tabs.push({ slug: 'bundled', label: t.infoBundled, empty: t.infoNoBundled });
			// Sections enabled on the option: the other tabs
			// disparaissent.
			if (opts.faces) {
				var slugs = opts.faces.map(function (face) { return DRAWER_SLUGS[face]; });
				tabs = tabs.filter(function (tab) { return slugs.indexOf(tab.slug) !== -1; });
			}
			if (!tabs.length) {
				tabsNode.hidden = true;
				bodyNode.appendChild(el('p', { text: t.noDetails }));
				return;
			}

			var wanted = { description: 'description', specs: 'caracteristiques', bundled: 'bundled' }[opts.tab] || '';
			var first = Math.max(0, tabs.map(function (tab) { return tab.slug; }).indexOf(wanted));

			var panes = tabs.map(function (tab) {
				var pane = tab.node
					? el('div', { className: 'dahu-kit-drawer__pane' }, [tab.node])
					: tab.empty
						? el('div', { className: 'dahu-kit-drawer__pane' }, [el('p', { text: tab.empty })])
						: el('div', { className: 'dahu-kit-drawer__pane', html: tab.html });
				if (tab.slug === 'description' && tab.html) breakSentences(pane);
				// “Help image”: at the top of the description, as
				// on the back of the card (side display = no back).
				if (tab.slug === 'description' && opts.helpImage) pane.insertBefore(helpVisual(opts.helpImage), pane.firstChild);
				// “more info…” following the description, as
				// on the back of the card (side display = no back).
				var more = tab.slug === 'description' ? moreInfoLink({ more_info_url: opts.moreInfo }) : null;
				if (more) pane.appendChild(el('p', { className: 'dahu-kit__more-info' }, [more]));
				return pane;
			});

			var buttons = tabs.map(function (tab, index) {
				return el('button', {
					type: 'button',
					className: 'dahu-kit-drawer__tab' + (index === first ? ' is-active' : ''),
					'aria-selected': index === first ? 'true' : 'false',
					text: tab.label || tab.slug,
					onclick: function (event) {
						buttons.forEach(function (b, i) {
							var active = b === event.currentTarget;
							b.classList.toggle('is-active', active);
							b.setAttribute('aria-selected', active ? 'true' : 'false');
							panes[i].hidden = !active;
						});
					}
				});
			});

			buttons.forEach(function (b) { tabsNode.appendChild(b); });
			panes.forEach(function (p, i) {
				p.hidden = i !== first;
				bodyNode.appendChild(p);
			});
			// No “Select” in the drawer: the choice is made on
			// the card. The bar is hidden when there is only one tab.
			tabsNode.hidden = tabs.length < 2;
		}

		/**
		 * @param {Object} [opts] { tab, bundled, all } — voir renderTabs().
		 */
		function show(productId, name, source, opts) {
			build();
			// Supplied content (question) replaced by the description.
			endContent();
			panel.setAttribute('aria-label', t.moreInfo);
			trigger = source || null;

			titleNode.textContent = name || '';
			tabsNode.innerHTML = '';
			bodyNode.innerHTML = '';
			bodyNode.appendChild(el('p', { className: 'dahu-kit-drawer__loading', text: t.detailsLoading }));
			tabsNode.hidden = true;

			reveal();
			panel.scrollTop = 0;
			titleNode.focus({ preventScroll: true });

			var id = ++requestId;
			var cached = cache[productId];
			if (cached) {
				renderTabs(cached, opts);
				return;
			}

			productTabs(productId).then(function (data) {
				if (id !== requestId) return;
				cache[productId] = data;
				if (data && data.name) titleNode.textContent = data.name;
				renderTabs(data, opts);
			}).catch(function () {
				if (id !== requestId) return;
				bodyNode.innerHTML = '';
				bodyNode.appendChild(el('p', { className: 'dahu-kit__error', text: t.detailsError }));
			});
		}

		return {
			open: show,
			close: hide,
			openContent: openContent,
			refreshContent: refreshContent
		};
	})();

	/* ------------------------------------------------------------------ */

	function boot() {
		document.querySelectorAll('.dahu-kit[data-product-id]').forEach(function (root) {
			if (root.dataset.dahuKitReady) return;
			root.dataset.dahuKitReady = '1';
			new Configurator(root).start();
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
