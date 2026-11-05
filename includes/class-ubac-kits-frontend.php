<?php
/**
 * Front end: mount point of the step-by-step configurator on the product page
 * of a kit. The rendering is done entirely in JS from the REST API; no rule
 * (hiding, prices, weight) is computed on the client side.
 *
 * @package Ubac_Kits
 */

defined( 'ABSPATH' ) || exit;

class Ubac_Kits_Frontend {

	public static function init() {
		// Called by woocommerce_template_single_add_to_cart() for the ubac_kit type.
		add_action( 'woocommerce_' . UBAC_KITS_PRODUCT_TYPE . '_add_to_cart', array( __CLASS__, 'render_configurator' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Does the active theme style the configurator itself?
	 *
	 * THE HANDOVER POINT BETWEEN PLUGIN AND THEME. The plugin always enqueues
	 * its functional stylesheet — drawer position, animation, scroll locking,
	 * column grid — because the configurator does not work without it. On top
	 * of that it enqueues a default skin.
	 *
	 * A theme that wants to style the configurator itself returns true from
	 * this filter; the plugin then drops its own skin, since two competing
	 * skins produce duplicate and conflicting rules.
	 *
	 *     add_filter( 'ubac_kits_theme_provides_styles', '__return_true' );
	 *
	 * @return bool
	 */
	public static function theme_provides_styles() {
		/**
		 * Filters whether the active theme provides its own configurator skin.
		 *
		 * @param bool $provides True when the theme takes the styling over.
		 */
		return (bool) apply_filters( 'ubac_kits_theme_provides_styles', false );
	}

	public static function register_assets() {
		// Functional stylesheet: always needed (position of the drawer,
		// animation, scroll locking, column grid).
		wp_register_style( 'ubac-kits-core', UBAC_KITS_URL . 'assets/css/ubac-kits-core.css', array(), UBAC_KITS_VERSION );
		// Fallback skin: only without a compatible theme.
		wp_register_style( 'ubac-kits-front', UBAC_KITS_URL . 'assets/css/ubac-kits-front.css', array( 'ubac-kits-core' ), UBAC_KITS_VERSION );
		// Layouts of a multiple-choice component (display_style):
		// a separate stylesheet.
		wp_register_style( 'ubac-kits-display-style', UBAC_KITS_URL . 'assets/css/ubac-kits-display-style.css', array( 'ubac-kits-core' ), UBAC_KITS_VERSION );
		// Category sections: module shared with the admin simulator.
		wp_register_script( 'ubac-kits-tiers', UBAC_KITS_URL . 'assets/js/ubac-kits-tiers.js', array(), UBAC_KITS_VERSION, true );
		wp_register_script( 'ubac-kits-front', UBAC_KITS_URL . 'assets/js/ubac-kits-front.js', array( 'ubac-kits-tiers' ), UBAC_KITS_VERSION, true );

		// Indentation of the components in the cart / the order.
		if ( is_cart() || is_checkout() || is_account_page() ) {
			self::enqueue_styles();
		}
	}

	/**
	 * Enqueues the functional stylesheet and, when the theme does not take it
	 * over, the fallback skin.
	 */
	public static function enqueue_styles() {
		wp_enqueue_style( 'ubac-kits-core' );
		wp_enqueue_style( 'ubac-kits-display-style' );
		if ( ! self::theme_provides_styles() ) {
			wp_enqueue_style( 'ubac-kits-front' );
		}
	}

	/**
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product instanceof WC_Product_Ubac_Kit ) {
				$classes[] = 'ubac-kit-page';
			}
		}
		return $classes;
	}

	/**
	 * Mount point + JS configuration.
	 */
	public static function render_configurator() {
		global $product;
		if ( ! $product instanceof WC_Product_Ubac_Kit ) {
			return;
		}

		self::enqueue_styles();
		wp_enqueue_script( 'ubac-kits-front' );
		$guest_save = ! is_user_logged_in() && Ubac_Kits_Saved_Configs::guest_enabled();
		$config = array(
				'restUrl' => esc_url_raw( rest_url( Ubac_Kits_Rest::NAMESPACE_V1 . '/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'cartUrl' => wc_get_cart_url(),
				// Saved configurations. loggedIn comes from the
				// page: a visitor's page may be cached, but a
				// logged-in customer is never served from that cache (see
				// print_boot_state()). The server checks again on every call.
				'savedEnabled'   => (bool) apply_filters( 'ubac_kits_saved_configs_enabled', true, $product ),
				'savedParam'     => Ubac_Kits_Saved_Configs::QUERY_ARG,
				// Configuration serial: ?ubac_kit_serial=…
				'serialParam'    => Ubac_Kits_Composition::QUERY_ARG,
				'loggedIn'       => is_user_logged_in(),
				'loginUrl'       => wp_login_url( get_permalink( $product->get_id() ) ),
				'accountKitsUrl' => Ubac_Kits_Saved_Configs::account_url(),
				// Visitor: account creation form in the
				// save block of the Summary. Fields and whether they are required
				// come from the server (Ubac_Kits_Saved_Configs::guest_fields()),
				// which revalidates everything on submission. None of this for a
				// logged-in customer.
				'guestSave'       => $guest_save,
				'guestFields'     => $guest_save ? self::guest_fields_for_script() : array(),
				'guestNonce'      => $guest_save ? wp_create_nonce( Ubac_Kits_Saved_Configs::GUEST_NONCE ) : '',
				'guestTrap'       => Ubac_Kits_Saved_Configs::GUEST_TRAP,
				'lostPasswordUrl' => $guest_save ? wc_lostpassword_url() : '',
				// Block to the right of the “Add to cart” button of the Summary,
				// supplied by the theme (e.g. reassurance: delivery,
				// warranty). Filtered HTML, inline SVG icons included; empty by
				// default = nothing.
				'summaryAside'    => self::summary_aside_html( $product ),
				// Keyword table of the “Category icon” layout (see category_icons()).
				'categoryIcons'   => self::category_icons(),
				'i18n'    => array(
					'loading'        => __( 'Loading the configurator…', 'ubac-kits' ),
					'loadError'      => __( 'The configurator could not be loaded. Reload the page.', 'ubac-kits' ),
					'step'           => __( 'Step', 'ubac-kits' ),
					'summary'        => __( 'Summary', 'ubac-kits' ),
					'chooseVariant'  => __( 'Choose a variation', 'ubac-kits' ),
					'quantity'       => __( 'Quantity', 'ubac-kits' ),
					'outOfStock'     => __( 'Out of stock', 'ubac-kits' ),
					'total'          => __( 'Total', 'ubac-kits' ),
					// “Estimated weight”: total of the summary and of the Summary,
					// computed with the weight setting of every step.
					'weight'         => __( 'Estimated weight', 'ubac-kits' ),
					// Free-text mention to the right of the weight, under the total of
					// the summary (plain text, empty by default = nothing):
					// supplied by the theme (e.g. “Free delivery”).
					'recapNote'      => wp_strip_all_tags( (string) apply_filters( 'ubac_kits_recap_note', '' ) ),
					'component'      => __( 'Component', 'ubac-kits' ),
					'product'        => __( 'Product', 'ubac-kits' ),
					'price'          => __( 'Price', 'ubac-kits' ),
					'none'           => __( 'None', 'ubac-kits' ),
					'addToCart'      => __( 'Add to cart', 'ubac-kits' ),
					'adding'         => __( 'Adding…', 'ubac-kits' ),
					'added'          => __( 'The product was added to your cart.', 'ubac-kits' ),
					'addedRedirect'  => __( 'The product was added to your cart. Taking you to the cart…', 'ubac-kits' ),
					'viewCart'       => __( 'View cart', 'ubac-kits' ),
					'incomplete'     => __( 'Complete the following steps:', 'ubac-kits' ),
					'bundleOptional' => __( 'Optional items', 'ubac-kits' ),
					'restart'        => __( 'Back to step', 'ubac-kits' ),
					'comingSoon'     => __( 'This option cannot be ordered yet. Check back soon.', 'ubac-kits' ),
					'comingSoonBadge' => __( 'Coming soon', 'ubac-kits' ),
					// Button of a branch “link” card, in
					// button mode: the card points to a page.
					'linkOpen'       => __( 'Go to', 'ubac-kits' ),
					'recap'          => __( 'Your configuration', 'ubac-kits' ),
					'toChoose'       => __( 'To choose', 'ubac-kits' ),
					// “Select” button of the cards in button mode (no longer in
					// the “More info” drawer).
					'select'         => __( 'Select', 'ubac-kits' ),
					// “Add-on” component (optional, a single option): the label alone
					// says “Select”, but the gesture stays a cumulative addition, which
					// a second click removes. The cards of a multiple choice use the
					// addItem labels below instead.
					'addOn'          => __( 'Select', 'ubac-kits' ),
					'addOnDone'      => __( 'Selected', 'ubac-kits' ),
					// Cards of a multiple choice (Accessories): “Add / Added”, the
					// step's own advance button saying “Select”
					// (the “next” key).
					'addItem'        => __( 'Add', 'ubac-kits' ),
					'addItemDone'    => __( 'Added', 'ubac-kits' ),
					// Expandable row (“Inline” layout).
					'rowExpand'      => __( 'Show details', 'ubac-kits' ),
					'rowCollapse'    => __( 'Hide details', 'ubac-kits' ),
					// Add-on presented as a yes / no question.
					'yes'            => __( 'Yes', 'ubac-kits' ),
					'no'             => __( 'No', 'ubac-kits' ),
					// Advance button of a multiple-choice step (Accessories,
					// renderStepNav()): “Select”. The cards of that step say
					// “Add”.
					'next'           => __( 'Select', 'ubac-kits' ),
					// Waiting text of the “?” popover of a category with neither
					// text nor media. Empty by default: no “?” is
					// shown when there is nothing to show. A site that wants
					// a generic text supplies it through the filter.
					'helpPlaceholder' => wp_strip_all_tags( (string) apply_filters( 'ubac_kits_help_placeholder', '' ) ),
					// Vignette mode (data-ubac-kit-card-style="vignette"): drawer.
					'moreInfo'       => __( 'More info', 'ubac-kits' ),
					// Link to the product page (“Show more info”).
					'moreInfoLink'   => __( 'more info…', 'ubac-kits' ),
					'close'          => __( 'Close', 'ubac-kits' ),
					'detailsLoading' => __( 'Loading…', 'ubac-kits' ),
					'noDetails'      => __( 'No further information for this product.', 'ubac-kits' ),
					'detailsError'   => __( 'This product’s information could not be loaded.', 'ubac-kits' ),
					'infoDescription'       => __( 'Description', 'ubac-kits' ),
					'infoCharacteristics'   => __( 'Specifications', 'ubac-kits' ),
					'infoNoCharacteristics' => __( 'No specifications entered', 'ubac-kits' ),
					'infoIncluded'          => __( 'Included products (soon)', 'ubac-kits' ),
					'infoLoading'           => __( 'Loading…', 'ubac-kits' ),
					// “More info” as a flipped / extended card
					// (data-ubac-kit-info="card|extended").
					'infoBack'              => __( 'Back', 'ubac-kits' ),
					/* translators: %s: title of the step or of the range */
					'hintAbout'             => __( 'Information about: %s', 'ubac-kits' ),
					'infoChoose'            => __( 'Select', 'ubac-kits' ),
					'infoChosen'            => __( 'Selected', 'ubac-kits' ),
					'infoChooseVariantFirst' => __( 'choose a variation on the card first', 'ubac-kits' ),
					// Option retained with no variant: message under the
					// list of variants, as long as the step cannot move on.
					'variantRequired'       => __( 'Choose a variation before continuing.', 'ubac-kits' ),
					// “Included products”: the label alone, the data stays
					// `bundled`.
					'infoBundled'           => __( 'Included products', 'ubac-kits' ),
					// Key shown with no included product (data-ubac-kit-info="vignette").
					'infoNoBundled'         => __( 'No included products', 'ubac-kits' ),
					// Variable option on which no variant is chosen yet: what it
					// would list is the content of its preselected variation, which
					// the choice may change. Greyed control, and this reason in
					// place of the list.
					'infoBundledPending'    => __( 'Choose an option to see what is included', 'ubac-kits' ),
					'networkError'   => __( 'Could not reach the server.', 'ubac-kits' ),
					// Saved configurations.
					'saveTitle'           => __( 'Save my configuration', 'ubac-kits' ),
					'saveButton'          => __( 'Save my configuration', 'ubac-kits' ),
					'saving'              => __( 'Saving…', 'ubac-kits' ),
					'saveName'            => __( 'Configuration name', 'ubac-kits' ),
					'saveNamePlaceholder' => __( 'Name (optional)', 'ubac-kits' ),
					'saveIncomplete'      => __( 'Finish the configuration before saving it.', 'ubac-kits' ),
					'saveLoginPrompt'     => __( 'Log in to find it again in your account, under “My configurations”.', 'ubac-kits' ),
					'saveLogin'           => __( 'Log in', 'ubac-kits' ),
					/* translators: %s: name of the configuration */
					'saved'               => __( 'Configuration “%s” saved.', 'ubac-kits' ),
					'savedSee'            => __( 'View my configurations', 'ubac-kits' ),
					/* translators: %s: name of the configuration */
					'savedOpened'         => __( 'Configuration “%s” reopened, at today’s prices.', 'ubac-kits' ),
					'savedChanged'        => __( 'Some choices are no longer available and were removed:', 'ubac-kits' ),
					'savedLoginToOpen'    => __( 'Log in to reopen this saved configuration.', 'ubac-kits' ),
					// ?ubac_kit_serial=… link that no longer matches the kit.
					'serialInvalid'       => __( 'This configuration link no longer matches this product: build your configuration below.', 'ubac-kits' ),
					// Visitor: account creation from the Summary.
					'guestIntro'           => __( 'Find it later in your account, under “My configurations”. No account yet? One is created at the same time, with no password to choose right now.', 'ubac-kits' ),
					'guestRequired'        => __( 'required', 'ubac-kits' ),
					'guestRequiredNote'    => __( '* Required fields', 'ubac-kits' ),
					'guestConsent'         => Ubac_Kits_Saved_Configs::consent_text(),
					'guestSubmit'          => __( 'Create my account and save', 'ubac-kits' ),
					// Button that unfolds the form: what the form
					// does.
					'guestOpen'            => __( 'Create my account and save', 'ubac-kits' ),
					'guestCancel'          => __( 'Cancel', 'ubac-kits' ),
					'guestFieldRequired'   => __( 'This field is required.', 'ubac-kits' ),
					'guestEmailInvalid'    => __( 'This email address is not valid.', 'ubac-kits' ),
					'guestConsentRequired' => __( 'Tick this box so that we may store your details.', 'ubac-kits' ),
					'guestCheckFields'     => __( 'Check the fields flagged above.', 'ubac-kits' ),
					/* translators: 1: name of the configuration, 2: e-mail address */
					'guestCreated'         => __( 'Configuration “%1$s” saved and account created. An email has just gone out to %2$s: the link in it lets you set your password. Your configuration is then waiting in your account, under “My configurations”.', 'ubac-kits' ),
					/* translators: 1: name of the configuration, 2: e-mail address */
					'guestCreatedNoMail'   => __( 'Configuration “%1$s” saved and account created for %2$s. To reach it, set your password from the login page.', 'ubac-kits' ),
					'guestSetPassword'     => __( 'Set my password', 'ubac-kits' ),
				),
		);

		// Appearance set on the product (Ubac_Kits_Appearance): fixed
		// when the page is rendered, read by the script (card style) and by the
		// theme (shape of the corners). No toggle on the visitor's side.
		printf(
			'<div class="ubac-kit is-loading" id="ubac-kit-%1$d" data-product-id="%1$d"%4$s><p class="ubac-kit__loading"><span class="ubac-kit__spinner" aria-hidden="true"></span>%2$s</p><noscript>%3$s</noscript></div>',
			(int) $product->get_id(),
			esc_html__( 'Loading the configurator…', 'ubac-kits' ),
			esc_html__( 'The configurator requires JavaScript.', 'ubac-kits' ),
			Ubac_Kits_Appearance::data_attributes( $product->get_id() ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by data_attributes().
		);

		// The configuration is printed inline, next to the boot state, rather than
		// handed to wp_localize_script(). Under a block theme (Twenty Twenty-Five)
		// this runs during content rendering, after the footer scripts' data has
		// been decided, so the "-js-extra" block is never written and every label
		// came out as "undefined". An inline node reaches the page whatever the theme.
		// Same JSON_HEX_* flags as the boot state: translations carry markup and quotes.
		$config_json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		printf(
			'<script type="application/json" id="ubac-kits-config">%s</script>',
			$config_json // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON escaped above (JSON_HEX_*).
		);

		self::print_boot_state( $product );
	}

	/**
	 * Keyword table of the “Category icon” layout.
	 *
	 * That layout draws a pictogram per option. The configuration schema carries
	 * no category, so the pictogram is derived from the NAME of the product: the
	 * first pattern that matches wins, and an option that matches nothing gets
	 * the default box. The script holds the shapes and nothing else — the
	 * keywords are here, because a keyword belongs to the language of the
	 * catalogue, not to the code.
	 *
	 * Each entry is `array( 'pattern' => <regular expression>, 'icon' => <shape> )`.
	 * The pattern is compiled by the browser, case-insensitively, so it is written
	 * in JavaScript syntax (which for this kind of alternation is the same as
	 * PCRE) and WITHOUT delimiters or flags. The shapes the script knows are:
	 * `box` (the default), `wheel`, `drivetrain`, `tool`, `mount`, `battery`,
	 * `light` and `lock`; an entry naming anything else is ignored.
	 *
	 * The defaults are English. A shop whose product names are in another
	 * language replaces the table — here, the original French keywords:
	 *
	 *     add_filter(
	 *         'ubac_kits_category_icons',
	 *         function () {
	 *             return array(
	 *                 array( 'pattern' => 'pneu|chambre|jante|roue', 'icon' => 'wheel' ),
	 *                 array( 'pattern' => 'cassette|pignon|plateau|cha[iî]ne', 'icon' => 'drivetrain' ),
	 *                 array( 'pattern' => 'outil|r[ée]paration|cl[ée]f?\\b', 'icon' => 'tool' ),
	 *                 array( 'pattern' => 'adaptateur|support|porte|[ée]trier|fixation', 'icon' => 'mount' ),
	 *                 array( 'pattern' => 'batterie|chargeur', 'icon' => 'battery' ),
	 *                 array( 'pattern' => '[ée]clairage|lampe|feu', 'icon' => 'light' ),
	 *                 array( 'pattern' => 'antivol|cadenas', 'icon' => 'lock' ),
	 *             );
	 *         }
	 *     );
	 *
	 * Returning an empty array switches the derivation off: every option then
	 * shows the box.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function category_icons() {

		$icons = array(
			array( 'pattern' => 'tyre|tire|tube|rim|wheel', 'icon' => 'wheel' ),
			array( 'pattern' => 'cassette|sprocket|chainring|chain', 'icon' => 'drivetrain' ),
			array( 'pattern' => 'tool|repair|wrench|spanner', 'icon' => 'tool' ),
			array( 'pattern' => 'adapter|adaptor|bracket|mount|caliper', 'icon' => 'mount' ),
			array( 'pattern' => 'battery|charger', 'icon' => 'battery' ),
			array( 'pattern' => 'light|lamp|headlight', 'icon' => 'light' ),
			array( 'pattern' => 'lock|padlock', 'icon' => 'lock' ),
		);

		/**
		 * Filters the keyword table of the “Category icon” layout.
		 *
		 * @param array<int,array<string,string>> $icons Ordered entries, each
		 *                                               array( 'pattern', 'icon' ).
		 */
		$icons = apply_filters( 'ubac_kits_category_icons', $icons );

		$clean = array();

		foreach ( (array) $icons as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['pattern'] ) || empty( $entry['icon'] ) ) {
				continue;
			}
			$clean[] = array(
				'pattern' => (string) $entry['pattern'],
				'icon'    => (string) $entry['icon'],
			);
		}

		return $clean;
	}

	/**
	 * Block to the right of the “Add to cart” button of the Summary: the
	 * `ubac_kits_summary_aside_html` filter ( string $html,
	 * WC_Product_Ubac_Kit $product ), empty by default. Content HTML
	 * (wp_kses_post) plus inline SVG icons, with no event attribute.
	 *
	 * @param WC_Product_Ubac_Kit $product Kit.
	 * @return string
	 */
	private static function summary_aside_html( $product ) {
		$html = (string) apply_filters( 'ubac_kits_summary_aside_html', '', $product );
		if ( '' === trim( $html ) ) {
			return '';
		}
		$svg     = array(
			'class'       => true,
			'width'       => true,
			'height'      => true,
			'viewbox'     => true,
			'aria-hidden' => true,
			'focusable'   => true,
			'role'        => true,
			'fill'        => true,
		);
		$shape   = array(
			'd'               => true,
			'cx'              => true,
			'cy'              => true,
			'r'               => true,
			'x'               => true,
			'y'               => true,
			'points'          => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linejoin' => true,
			'stroke-linecap'  => true,
		);
		$allowed = array_merge(
			wp_kses_allowed_html( 'post' ),
			array(
				'svg'      => $svg,
				'path'     => $shape,
				'circle'   => $shape,
				'rect'     => $shape,
				'polyline' => $shape,
				'g'        => $shape,
			)
		);
		return wp_kses( $html, $allowed );
	}

	/**
	 * Fields of the visitor form, reduced to what the script displays.
	 *
	 * @return array[] Ordered list of { key, label, type, autocomplete, max, required }.
	 */
	private static function guest_fields_for_script() {
		$out = array();
		foreach ( Ubac_Kits_Saved_Configs::guest_fields() as $key => $field ) {
			$out[] = array(
				'key'          => $key,
				'label'        => $field['label'],
				'type'         => $field['type'],
				'autocomplete' => (string) $field['autocomplete'],
				'max'          => (int) $field['max'],
				'required'     => (bool) $field['required'],
			);
		}
		return $out;
	}

	/**
	 * Starting state computed when the page is rendered and dropped into the HTML.
	 *
	 * Without it, the configurator chains GET /schema then POST /state before
	 * showing anything: two REST round trips, each with its full WordPress +
	 * WooCommerce bootstrap, during which the buying area stays empty. Here,
	 * everything is already in the page.
	 *
	 * ------------------------------------------------------------------
	 * CACHING — read this before touching this method.
	 *
	 * What is dropped here depends on the ROLE: price of the tier (Individual /
	 * Pro) and display including or excluding tax. It must therefore never end up
	 * in a cached page served to somebody else.
	 *
	 * Two cases:
	 *  - anonymous visitor: the content is that of the public tier, identical for
	 *    every anonymous visitor. The page stays cacheable, otherwise we would
	 *    lose the page cache for the vast majority of the traffic — which would
	 *    work against the goal;
	 *  - logged-in user: the page is declared NOT cacheable (DONOTCACHEPAGE,
	 *    honoured by WP Rocket, WP Super Cache, W3TC, LiteSpeed). A personalised
	 *    page is therefore never stored.
	 *
	 * What remains is the assumption that the cache does not serve an anonymous
	 * page to a logged-in user. That is WP Rocket's default behaviour (it excludes
	 * logged-in users at the advanced-cache.php level, before PHP), and a site
	 * usually depends on it elsewhere already — the header shows the state of the
	 * account and the mini-cart. Should the “cache for logged-in users” option ever
	 * be turned on, the injection would have to be disabled:
	 *
	 *     add_filter( 'ubac_kits_inline_boot_state', '__return_false' );
	 *
	 * The configurator then falls back on its REST calls: slower, never wrong.
	 * ------------------------------------------------------------------
	 *
	 * @param WC_Product_Ubac_Kit $product Kit.
	 */
	private static function print_boot_state( WC_Product_Ubac_Kit $product ) {
		/**
		 * Inject the starting state into the page.
		 *
		 * @param bool                $enabled Enabled.
		 * @param WC_Product_Ubac_Kit $product Kit.
		 */
		if ( ! apply_filters( 'ubac_kits_inline_boot_state', true, $product ) ) {
			return;
		}

		if ( is_user_logged_in() && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		Ubac_Kits_Pricing::ensure_tax_context();

		$schema = Ubac_Kits_Engine::schema( $product );
		// Starting selections = default values of the schema, passed through the
		// same path as a click: strictly the same state as a first
		// POST /state, in the same format.
		// The array cast is mandatory: schema() returns `defaults` as an object
		// (so that the JSON carries {} and not [] when it is empty), and
		// normalize_selections() silently ignores anything that is not
		// an array — the starting state would have gone out empty.
		$raw = (array) $schema['defaults'];

		// Opened by ?ubac_kit_serial=… (button on a product page,
		// link from the simulator): the starting state is the one of the serial. A
		// page with a parameter is not served from the page cache (WP Rocket does
		// not cache an address with an unknown parameter); were it cached,
		// the state dropped in would not have `serial` and the front end would read
		// the serial again through /state. Unreadable serial: ordinary start, reported to the front end.
		$restore = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only, no action.
		$serial = isset( $_GET[ Ubac_Kits_Composition::QUERY_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ Ubac_Kits_Composition::QUERY_ARG ] ) ) : '';
		if ( '' !== $serial ) {
			// Never cached: a cache that ignored the parameter would serve
			// this starting state to every visitor of the kit.
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			$parsed  = Ubac_Kits_Composition::parse( $product, $serial );
			$restore = Ubac_Kits_Composition::restore_info( $serial, $parsed );
			if ( $parsed ) {
				$raw = $parsed['selections'];
			}
		}

		$state = Ubac_Kits_Engine::state( $product, $raw, 1 );

		$boot = array(
			'schema' => $schema,
			'state'  => $state,
		);
		if ( $restore ) {
			$boot['serial'] = $restore;
		}

		// JSON_HEX_TAG: product descriptions contain HTML, and a
		// “</script>” in one of them would close the tag.
		$json = wp_json_encode( $boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		if ( false === $json ) {
			return; // The front end will go through the REST calls instead.
		}

		printf(
			'<script type="application/json" id="ubac-kit-boot-%d">%s</script>',
			(int) $product->get_id(),
			$json // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON escaped above (JSON_HEX_*).
		);
	}
}
