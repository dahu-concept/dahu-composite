<?php
/**
 * Settings screen (WooCommerce > Composite products): shipping class of the
 * containers, price tiers and state of the existing composite products. The
 * “Show more info” and “None” card settings are options of this class, but
 * their controls are in the Appearance panel of the product editor.
 *
 * @package Ubac_Kits
 */

defined( 'ABSPATH' ) || exit;

class Ubac_Kits_Settings {

	const OPTION_SHIPPING_CLASS = 'ubac_kits_container_shipping_class';
	const OPTION_PRICE_TIERS    = 'ubac_kits_price_tiers';
	const OPTION_NONE_IMAGE     = 'ubac_kits_none_image_id';
	const OPTION_NONE_TEXT      = 'ubac_kits_none_text';

	/**
	 * “Show more info”: a link to the product page after its description, on the
	 * cards of the configurator. 'yes' = ticked; option absent (never saved) =
	 * unticked.
	 */
	const OPTION_MORE_INFO = 'ubac_kits_more_info_link';

	/**
	 * Screen suffix returned by add_submenu_page() (in practice
	 * “woocommerce_page_ubac-kits-settings”): the stylesheet of the screen
	 * is loaded there only.
	 *
	 * @var string|false
	 */
	private static $hook = false;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		// options.php requires manage_options by default: align it with the menu
		// so that a shop manager can save.
		add_filter(
			'option_page_capability_ubac_kits',
			static function () {
				return 'manage_woocommerce';
			}
		);
	}

	public static function menu() {
		self::$hook = add_submenu_page(
			'woocommerce',
			__( 'Composite products', 'ubac-kits' ),
			__( 'Composite products', 'ubac-kits' ),
			'manage_woocommerce',
			UBAC_KITS_SETTINGS_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			'ubac_kits',
			self::OPTION_SHIPPING_CLASS,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
				'default'           => '',
			)
		);

		// “None” card: one image and one text for the WHOLE
		// configurator — a global setting, neither per product nor per scenario.
		// Edited in the Appearance panel of the product editor, so NOT in the
		// group of this screen: options.php would otherwise blank every option
		// of its group that the form does not post.
		register_setting(
			'ubac_kits_appearance',
			self::OPTION_NONE_IMAGE,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		register_setting(
			'ubac_kits_appearance',
			self::OPTION_NONE_TEXT,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		// Nothing is written until the panel has been saved: the absent option
		// means “unticked”.
		register_setting(
			'ubac_kits_appearance',
			self::OPTION_MORE_INFO,
			array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $value ) {
					return 'yes' === $value ? 'yes' : '';
				},
				'default'           => '',
			)
		);

		register_setting(
			'ubac_kits',
			self::OPTION_PRICE_TIERS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_tiers' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * “more info…” link on the cards: the “Show more info” checkbox of the
	 * Appearance panel.
	 *
	 * @return bool
	 */
	public static function more_info_enabled() {
		return 'yes' === get_option( self::OPTION_MORE_INFO, '' );
	}

	/**
	 * “None” card of the optional components, as the configurator receives it in
	 * the schema (none_choice): text and image set in the Appearance panel,
	 * shared by every composite product. null when nothing is set: the “None” card then stays
	 * the one from before (the label alone).
	 *
	 * @return array|null { text: string, image: string|null, image_large: string|null }
	 */
	public static function none_choice() {
		$image_id = absint( get_option( self::OPTION_NONE_IMAGE, 0 ) );
		$text     = trim( (string) get_option( self::OPTION_NONE_TEXT, '' ) );
		$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : false;
		if ( ! $image && '' === $text ) {
			return null;
		}
		return array(
			'text'        => $text,
			'image'       => $image ? $image : null,
			'image_large' => $image ? wp_get_attachment_image_url( $image_id, 'woocommerce_single' ) : null,
		);
	}

	/**
	 * “None” card of ONE component: its own image (`none_image_id`, set in the
	 * component editor, “Customise the display”), with the shared text. null when the
	 * component has no image of its own (or when it has disappeared from the
	 * media library): the configurator then takes the shared card
	 * (none_choice()).
	 *
	 * @param array $component Normalised component.
	 * @return array|null { text, image, image_large }, same shape as none_choice().
	 */
	public static function component_none_choice( array $component ) {
		$image_id = ! empty( $component['none_image_id'] ) ? absint( $component['none_image_id'] ) : 0;
		$image    = $image_id && wp_attachment_is_image( $image_id ) ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : false;
		if ( ! $image ) {
			return null;
		}
		$common = self::none_choice();
		return array(
			'text'        => $common ? $common['text'] : '',
			'image'       => $image,
			'image_large' => wp_get_attachment_image_url( $image_id, 'woocommerce_single' ),
		);
	}

	/**
	 * Stylesheet of the settings screen.
	 *
	 * @param string $hook Current screen.
	 */
	public static function enqueue( $hook ) {
		if ( ! self::$hook || self::$hook !== $hook ) {
			return;
		}
		/*
		 * The family sheet, queued by this screen and not by anything else.
		 *
		 * It used to arrive only with the guided tour, which queues it on this
		 * very hook — and the tour queues nothing once it has been played. The
		 * screen was therefore styled until somebody took the tour, and bare
		 * afterwards. Worse for a reviewer: a fresh install has the tour
		 * pending, so the screenshots that go to wordpress.org showed a screen
		 * that no established shop sees.
		 */
		wp_enqueue_style( 'ubac-ui', UBAC_KITS_URL . 'assets/css/ubac-ui.css', array(), UBAC_KITS_VERSION );
		wp_enqueue_style( 'ubac-kits-settings', UBAC_KITS_URL . 'assets/css/ubac-kits-settings.css', array( 'ubac-ui' ), UBAC_KITS_VERSION );
	}

	/**
	 * The roles of a tier, read either from the comma-separated list the form
	 * posts or from an array a previous sanitising pass already produced.
	 *
	 * WHY THIS IS NOT A ONE-LINE CAST. WordPress sanitises an option TWICE the
	 * first time it is written: `update_option()` sanitises, finds nothing
	 * stored, and hands the value to `add_option()`, which sanitises again. So
	 * this sanitiser is handed its OWN output on the second pass, and `roles`
	 * arrives as an array. Casting that array with `(string)` yielded the word
	 * “Array”, and a tier whose only role is “Array” matches nobody: every
	 * trade customer was shown the public price, with nothing on screen to say
	 * so.
	 *
	 * @param mixed $value Posted list, or an already sanitised array.
	 * @return string[]
	 */
	private static function normalize_roles( $value ) {
		$roles = is_array( $value ) ? array_map( 'strval', $value ) : explode( ',', (string) $value );
		$roles = array_map( 'trim', $roles );
		$roles = array_filter(
			$roles,
			static function ( $role ) {
				return '' !== $role;
			}
		);

		return array_values( array_map( 'sanitize_text_field', $roles ) );
	}

	/**
	 * @param mixed $input Posted values.
	 * @return array
	 */
	public static function sanitize_tiers( $input ) {
		$output = array();

		foreach ( Ubac_Kits_Pricing::get_tiers() as $key => $tier ) {
			$posted = ( is_array( $input ) && isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) ? $input[ $key ] : array();

			$roles = self::normalize_roles( isset( $posted['roles'] ) ? $posted['roles'] : $tier['roles'] );

			$output[ $key ] = array(
				'roles'               => $roles,
				'label'               => isset( $posted['label'] ) ? sanitize_text_field( (string) $posted['label'] ) : $tier['label'],
				'public_label'        => isset( $posted['public_label'] ) ? sanitize_text_field( (string) $posted['public_label'] ) : $tier['public_label'],
				'public_label_short'  => isset( $posted['public_label_short'] ) ? sanitize_text_field( (string) $posted['public_label_short'] ) : ( isset( $tier['public_label_short'] ) ? $tier['public_label_short'] : '' ),
				'price_excluding_tax' => ! empty( $posted['price_excluding_tax'] ),
			);
		}

		// Rows the loop above did not see, carried over as they are.
		//
		// WHY. On the very first write the option does not exist yet, so
		// `get_tiers()` — which builds itself from the saved option — returns
		// nothing and the loop above matches nothing. The second sanitising
		// pass therefore returned an empty array, and the first tier a shop
		// ever created was dropped without a word: the form came back blank
		// and the tier only stuck on a second attempt. Nothing is resurrected
		// here, because a tier is never removed by leaving it out of the
		// form — the screen has no delete control at all.
		foreach ( (array) $input as $posted_key => $row ) {
			$carried = sanitize_key( (string) $posted_key );
			if ( '' === $carried || 'new' === $carried || isset( $output[ $carried ] ) || ! is_array( $row ) ) {
				continue;
			}

			$output[ $carried ] = array(
				'roles'               => self::normalize_roles( isset( $row['roles'] ) ? $row['roles'] : array() ),
				'label'               => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
				'public_label'        => isset( $row['public_label'] ) ? sanitize_text_field( (string) $row['public_label'] ) : '',
				'public_label_short'  => isset( $row['public_label_short'] ) ? sanitize_text_field( (string) $row['public_label_short'] ) : '',
				'price_excluding_tax' => ! empty( $row['price_excluding_tax'] ),
			);
		}

		// A new row (key “new”): kept when it names at least one role.
		$new = ( is_array( $input ) && isset( $input['new'] ) && is_array( $input['new'] ) ) ? $input['new'] : array();
		if ( $new ) {
			$roles = self::normalize_roles( isset( $new['roles'] ) ? $new['roles'] : array() );
			if ( $roles ) {
				$label = isset( $new['label'] ) ? sanitize_text_field( (string) $new['label'] ) : '';
				$base  = sanitize_key( '' !== $label ? $label : $roles[0] );
				$base  = '' !== $base && 'new' !== $base ? $base : 'tier';
				$key   = $base;
				for ( $i = 2; isset( $output[ $key ] ); $i++ ) {
					$key = $base . '-' . $i;
				}
				$output[ $key ] = array(
					'roles'               => $roles,
					'label'               => $label,
					'public_label'        => isset( $new['public_label'] ) ? sanitize_text_field( (string) $new['public_label'] ) : '',
					'public_label_short'  => isset( $new['public_label_short'] ) ? sanitize_text_field( (string) $new['public_label_short'] ) : '',
					'price_excluding_tax' => ! empty( $new['price_excluding_tax'] ),
				);
			}
		}

		return $output;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$current = (string) get_option( self::OPTION_SHIPPING_CLASS, '' );
		$classes = get_terms(
			array(
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
			)
		);
		$kits    = wc_get_products(
			array(
				'type'   => UBAC_KITS_PRODUCT_TYPE,
				'limit'  => 100,
				'status' => array( 'publish', 'draft', 'private', 'pending' ),
			)
		);
		?>
		<div class="wrap ubac-ui">
			<div class="ubac-ui-head">
				<h1 class="ubac-ui-head__title"><?php esc_html_e( 'Composite products', 'ubac-kits' ); ?></h1>
				<p class="ubac-ui-head__sub"><?php esc_html_e( 'Products built from parts the customer chooses.', 'ubac-kits' ); ?></p>
			</div>

			<hr class="wp-header-end">

			<form method="post" action="options.php">
				<?php settings_fields( 'ubac_kits' ); ?>
				<div class="ubac-ui-card">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ubac-kits-shipping-class"><?php esc_html_e( 'Shipping class for composite products', 'ubac-kits' ); ?></label></th>
						<td>
							<select id="ubac-kits-shipping-class" name="<?php echo esc_attr( self::OPTION_SHIPPING_CLASS ); ?>">
								<option value=""><?php esc_html_e( '— None —', 'ubac-kits' ); ?></option>
								<?php foreach ( is_array( $classes ) ? $classes : array() as $term ) : ?>
									<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $current, $term->slug ); ?>><?php echo esc_html( $term->name . ' (' . $term->slug . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				</div>

				<div class="ubac-ui-card">
				<div class="ubac-ui-card__head">
					<h2 class="ubac-ui-card__title"><?php esc_html_e( 'Trade price tiers', 'ubac-kits' ); ?></h2>
				</div>
				<table class="widefat striped ubac-kit-settings__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tier', 'ubac-kits' ); ?></th>
							<th><?php esc_html_e( 'Roles (comma separated)', 'ubac-kits' ); ?></th>
							<th><?php esc_html_e( 'Label for the tier price', 'ubac-kits' ); ?></th>
							<th><?php esc_html_e( 'Label for the public price', 'ubac-kits' ); ?></th>
							<th><?php esc_html_e( 'Short label (summary)', 'ubac-kits' ); ?></th>
							<th><?php esc_html_e( 'Tier price excluding tax', 'ubac-kits' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php $shared_any = false; ?>
					<?php foreach ( Ubac_Kits_Pricing::get_tiers() as $key => $tier ) : ?>
						<?php
						$name = esc_attr( self::OPTION_PRICE_TIERS . '[' . $key . ']' );
						// Labels driven by ubac-pricing: shown, not editable here. The
						// hidden fields keep the value in reserve for the day
						// ubac-pricing is deactivated.
						$shared     = null !== Ubac_Kits_Pricing::get_shared_labels( $key );
						$shared_any = $shared_any || $shared;
						?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td><input type="text" class="regular-text" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[roles]" value="<?php echo esc_attr( implode( ', ', $tier['roles'] ) ); ?>"></td>
							<?php if ( $shared ) : ?>
								<td><?php echo esc_html( $tier['label'] ); ?> <sup>*</sup><input type="hidden" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[label]" value="<?php echo esc_attr( $tier['label'] ); ?>"></td>
								<td><?php echo esc_html( $tier['public_label'] ); ?> <sup>*</sup><input type="hidden" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[public_label]" value="<?php echo esc_attr( $tier['public_label'] ); ?>"></td>
							<?php else : ?>
								<td><input type="text" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[label]" value="<?php echo esc_attr( $tier['label'] ); ?>"></td>
								<td><input type="text" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[public_label]" value="<?php echo esc_attr( $tier['public_label'] ); ?>"></td>
							<?php endif; ?>
							<td><input type="text" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[public_label_short]" value="<?php echo esc_attr( isset( $tier['public_label_short'] ) ? $tier['public_label_short'] : '' ); ?>"></td>
							<td><input type="checkbox" value="1" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[price_excluding_tax]" <?php checked( $tier['price_excluding_tax'] ); ?>></td>
						</tr>
					<?php endforeach; ?>
						<?php $new_name = esc_attr( self::OPTION_PRICE_TIERS . '[new]' ); ?>
						<tr>
							<td>—</td>
							<td><input type="text" class="regular-text" name="<?php echo $new_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[roles]" value=""></td>
							<td><input type="text" name="<?php echo $new_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[label]" value=""></td>
							<td><input type="text" name="<?php echo $new_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[public_label]" value=""></td>
							<td><input type="text" name="<?php echo $new_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[public_label_short]" value=""></td>
							<td><input type="checkbox" value="1" name="<?php echo $new_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[price_excluding_tax]"></td>
						</tr>
					</tbody>
				</table>
				<?php if ( $shared_any ) : ?>
					<p class="description">
						<sup>*</sup>
						<?php esc_html_e( 'Label taken from Ubac Pricing; change it there.', 'ubac-kits' ); ?>
						<?php if ( is_callable( array( 'Ubac_Pricing_Settings', 'get_settings_url' ) ) ) : ?>
							<a href="<?php echo esc_url( Ubac_Pricing_Settings::get_settings_url() ); ?>"><?php esc_html_e( 'Open the Ubac Pricing settings', 'ubac-kits' ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>
				</div>

				<?php submit_button( null, 'ubac-ui-btn ubac-ui-btn--go' ); ?>
			</form>

			<div class="ubac-ui-card">
			<div class="ubac-ui-card__head">
				<h2 class="ubac-ui-card__title"><?php esc_html_e( 'Composite products', 'ubac-kits' ); ?></h2>
			</div>
			<table class="ubac-ui-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Composite product', 'ubac-kits' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ubac-kits' ); ?></th>
						<th><?php esc_html_e( 'Components', 'ubac-kits' ); ?></th>
						<th><?php esc_html_e( 'Scenarios', 'ubac-kits' ); ?></th>
						<th><?php esc_html_e( 'Shipping class', 'ubac-kits' ); ?></th>
						<th><?php esc_html_e( 'REST schema', 'ubac-kits' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $kits ) ) : ?>
					<tr>
						<td colspan="6">
							<p class="ubac-ui-hint"><?php esc_html_e( 'No composite products yet. Create a product of type “Composite product”.', 'ubac-kits' ); ?></p>
							<p>
								<a class="ubac-ui-btn" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=product' ) ); ?>">
									<?php esc_html_e( 'Add a product', 'ubac-kits' ); ?>
								</a>
							</p>
						</td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $kits as $kit ) : ?>
					<?php
					$class_term = $kit->get_shipping_class_id() ? get_term( $kit->get_shipping_class_id(), 'product_shipping_class' ) : null;
					?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $kit->get_id() ) ); ?>"><?php echo esc_html( $kit->get_name() ); ?></a> #<?php echo (int) $kit->get_id(); ?></td>
						<td><?php echo esc_html( $kit->get_status() ); ?></td>
						<td><?php echo (int) count( $kit->get_kit_components() ); ?></td>
						<td><?php echo (int) count( $kit->get_kit_scenarios() ); ?></td>
						<td><?php echo $class_term && ! is_wp_error( $class_term ) ? esc_html( $class_term->slug ) : '<strong class="ubac-kit-settings__missing">' . esc_html__( 'none', 'ubac-kits' ) . '</strong>'; ?></td>
						<td><code><?php echo esc_html( rest_url( Ubac_Kits_Rest::NAMESPACE_V1 . '/schema/' . $kit->get_id() ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
		<?php
	}
}
