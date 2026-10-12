<?php
/**
 * Settings screen (WooCommerce > Dahu Kits): shipping class of the containers,
 * price tiers, “None” card of the configurator and state of the existing kits.
 *
 * @package Dahu_Composite
 */

defined( 'ABSPATH' ) || exit;

class Dahu_Composite_Settings {

	const OPTION_SHIPPING_CLASS = 'dahu_composite_container_shipping_class';
	const OPTION_PRICE_TIERS    = 'dahu_composite_price_tiers';
	const OPTION_NONE_IMAGE     = 'dahu_composite_none_image_id';
	const OPTION_NONE_TEXT      = 'dahu_composite_none_text';

	/**
	 * “Show more info”: a link to the product page after its description, on the
	 * cards of the configurator. 'yes' = ticked; option absent (the screen has
	 * never been saved since the update) = unticked.
	 */
	const OPTION_MORE_INFO = 'dahu_composite_more_info_link';

	/**
	 * Screen suffix returned by add_submenu_page() (in practice
	 * “woocommerce_page_dahu-composite-settings”): the media library and the
	 * script of the screen are loaded there only.
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
			'option_page_capability_dahu_composite',
			static function () {
				return 'manage_woocommerce';
			}
		);
	}

	public static function menu() {
		self::$hook = add_submenu_page(
			'woocommerce',
			__( 'Configurable kits', 'dahu-composite' ),
			__( 'Configurable kits', 'dahu-composite' ),
			'manage_woocommerce',
			DAHU_COMPOSITE_SETTINGS_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function register() {
		register_setting(
			'dahu_composite',
			self::OPTION_SHIPPING_CLASS,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_title',
				'default'           => 'choisir-son-kit',
			)
		);

		// “None” card: one image and one text for the WHOLE
		// configurator — a global setting, neither per product nor per scenario.
		register_setting(
			'dahu_composite',
			self::OPTION_NONE_IMAGE,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
		register_setting(
			'dahu_composite',
			self::OPTION_NONE_TEXT,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		// General appearance. Nothing is written until the screen has been
		// saved: the absent option means “unticked”.
		register_setting(
			'dahu_composite',
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
			'dahu_composite',
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
	 * general appearance.
	 *
	 * @return bool
	 */
	public static function more_info_enabled() {
		return 'yes' === get_option( self::OPTION_MORE_INFO, '' );
	}

	/**
	 * “None” card of the optional components, as the configurator receives it in
	 * the schema (none_choice): text and image set in WooCommerce > Dahu Kits,
	 * shared by every kit. null when nothing is set: the “None” card then stays
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
	 * kit editor, “Customise the display”), with the shared text. null when the
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
	 * Media library and picker of the “None” image, on the settings screen only.
	 * The script depends on “media-editor” and goes out in the page footer, after
	 * wp.media: the earlier <script> block written into the page ran before it
	 * and never wired the button up.
	 *
	 * @param string $hook Current screen.
	 */
	public static function enqueue( $hook ) {
		if ( ! self::$hook || self::$hook !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'dahu-composite-settings', DAHU_COMPOSITE_URL . 'assets/css/dahu-composite-settings.css', array(), DAHU_COMPOSITE_VERSION );
		wp_enqueue_script( 'dahu-composite-settings', DAHU_COMPOSITE_URL . 'assets/js/dahu-composite-settings.js', array( 'jquery', 'media-editor' ), DAHU_COMPOSITE_VERSION, true );
	}

	/**
	 * @param mixed $input Posted values.
	 * @return array
	 */
	public static function sanitize_tiers( $input ) {
		$output = array();

		foreach ( Dahu_Composite_Pricing::get_tiers() as $key => $tier ) {
			$posted = ( is_array( $input ) && isset( $input[ $key ] ) && is_array( $input[ $key ] ) ) ? $input[ $key ] : array();

			$roles = isset( $posted['roles'] ) ? (string) $posted['roles'] : implode( ', ', $tier['roles'] );
			$roles = array_values( array_filter( array_map( 'trim', explode( ',', $roles ) ) ) );

			$output[ $key ] = array(
				'roles'               => array_map( 'sanitize_text_field', $roles ),
				'label'               => isset( $posted['label'] ) ? sanitize_text_field( (string) $posted['label'] ) : $tier['label'],
				'public_label'        => isset( $posted['public_label'] ) ? sanitize_text_field( (string) $posted['public_label'] ) : $tier['public_label'],
				'public_label_short'  => isset( $posted['public_label_short'] ) ? sanitize_text_field( (string) $posted['public_label_short'] ) : ( isset( $tier['public_label_short'] ) ? $tier['public_label_short'] : '' ),
				'price_excluding_tax' => ! empty( $posted['price_excluding_tax'] ),
			);
		}

		return $output;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$current = (string) get_option( self::OPTION_SHIPPING_CLASS, 'choisir-son-kit' );
		$classes = get_terms(
			array(
				'taxonomy'   => 'product_shipping_class',
				'hide_empty' => false,
			)
		);
		$kits    = wc_get_products(
			array(
				'type'   => DAHU_COMPOSITE_PRODUCT_TYPE,
				'limit'  => 100,
				'status' => array( 'publish', 'draft', 'private', 'pending' ),
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Configurable kits', 'dahu-composite' ); ?></h1>

			<p class="dahu-composite-settings__intro">
				<?php esc_html_e( 'Dahu Composite builds a product a customer assembles themselves, step by step, from choices you define. These settings apply to every kit.', 'dahu-composite' ); ?>
			</p>
			<hr class="wp-header-end">

			<form method="post" action="options.php">
				<?php settings_fields( 'dahu_composite' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dahu-composite-shipping-class"><?php esc_html_e( 'Shipping class for kits', 'dahu-composite' ); ?></label></th>
						<td>
							<select id="dahu-composite-shipping-class" name="<?php echo esc_attr( self::OPTION_SHIPPING_CLASS ); ?>">
								<option value=""><?php esc_html_e( '— None —', 'dahu-composite' ); ?></option>
								<?php foreach ( is_array( $classes ) ? $classes : array() as $term ) : ?>
									<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $current, $term->slug ); ?>><?php echo esc_html( $term->name . ' (' . $term->slug . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Applied to the kit line in the cart.', 'dahu-composite' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'General appearance', 'dahu-composite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show more info', 'dahu-composite' ); ?></th>
						<td>
							<label for="dahu-composite-more-info">
								<input type="checkbox" id="dahu-composite-more-info" name="<?php echo esc_attr( self::OPTION_MORE_INFO ); ?>" value="yes" <?php checked( self::more_info_enabled() ); ?>>
								<?php esc_html_e( 'A “more info…” link after the product description on cards', 'dahu-composite' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Opens the product page in a new window. Never on the “None” card, nor for a product with no public page.', 'dahu-composite' ); ?></p>
						</td>
					</tr>
				</table>

				<?php
				$none_image_id = absint( get_option( self::OPTION_NONE_IMAGE, 0 ) );
				$none_preview  = $none_image_id ? wp_get_attachment_image_url( $none_image_id, 'thumbnail' ) : '';
				?>
				<h2 class="title"><?php esc_html_e( 'The configurator’s “None” card', 'dahu-composite' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Used on every optional step; an image set on a component overrides it.', 'dahu-composite' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Image', 'dahu-composite' ); ?></th>
						<td>
							<input type="hidden" id="dahu-composite-none-image" name="<?php echo esc_attr( self::OPTION_NONE_IMAGE ); ?>" value="<?php echo esc_attr( $none_image_id ? (string) $none_image_id : '' ); ?>">
							<img id="dahu-composite-none-preview" class="dahu-kit-settings__preview" src="<?php echo esc_url( $none_preview ); ?>" alt="" <?php echo $none_preview ? '' : 'hidden'; ?>>
							<button type="button" class="button" id="dahu-composite-none-pick" data-frame-title="<?php esc_attr_e( 'Image for the “None” card', 'dahu-composite' ); ?>" data-frame-button="<?php esc_attr_e( 'Use this image', 'dahu-composite' ); ?>"><?php esc_html_e( 'Choose an image', 'dahu-composite' ); ?></button>
							<button type="button" class="button-link delete" id="dahu-composite-none-clear" <?php echo $none_image_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove the image', 'dahu-composite' ); ?></button>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dahu-composite-none-text"><?php esc_html_e( 'Text', 'dahu-composite' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="dahu-composite-none-text" name="<?php echo esc_attr( self::OPTION_NONE_TEXT ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPTION_NONE_TEXT, '' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. I do not need one', 'dahu-composite' ); ?>">
							<p class="description"><?php esc_html_e( 'Shown under “None”, on the card.', 'dahu-composite' ); ?></p>
						</td>
					</tr>
				</table>
				<?php // Picker: assets/js/dahu-composite-settings.js (enqueue()). ?>

				<h2 class="title"><?php esc_html_e( 'Trade price tiers', 'dahu-composite' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Public price and tier price, for these roles. “Short label” is used in the summary only.', 'dahu-composite' ); ?>
				</p>
				<table class="widefat striped dahu-kit-settings__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tier', 'dahu-composite' ); ?></th>
							<th><?php esc_html_e( 'Roles (comma separated)', 'dahu-composite' ); ?></th>
							<th><?php esc_html_e( 'Label for the tier price', 'dahu-composite' ); ?></th>
							<th><?php esc_html_e( 'Label for the public price', 'dahu-composite' ); ?></th>
							<th><?php esc_html_e( 'Short label (summary)', 'dahu-composite' ); ?></th>
							<th><?php esc_html_e( 'Tier price excluding tax', 'dahu-composite' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php $shared_any = false; ?>
					<?php foreach ( Dahu_Composite_Pricing::get_tiers() as $key => $tier ) : ?>
						<?php
						$name = esc_attr( self::OPTION_PRICE_TIERS . '[' . $key . ']' );
						// Labels driven by dahu-pricing: shown, not editable here. The
						// hidden fields keep the value in reserve for the day
						// dahu-pricing is deactivated.
						$shared     = null !== Dahu_Composite_Pricing::get_shared_labels( $key );
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
					</tbody>
				</table>
				<?php if ( $shared_any ) : ?>
					<p class="description">
						<sup>*</sup>
						<?php esc_html_e( 'Label taken from Dahu Pricing; change it there.', 'dahu-composite' ); ?>
						<?php if ( is_callable( array( 'Dahu_Pricing_Settings', 'get_settings_url' ) ) ) : ?>
							<a href="<?php echo esc_url( Dahu_Pricing_Settings::get_settings_url() ); ?>"><?php esc_html_e( 'Open the Dahu Pricing settings', 'dahu-composite' ); ?></a>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Kits', 'dahu-composite' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Kit', 'dahu-composite' ); ?></th>
						<th><?php esc_html_e( 'Status', 'dahu-composite' ); ?></th>
						<th><?php esc_html_e( 'Components', 'dahu-composite' ); ?></th>
						<th><?php esc_html_e( 'Scenarios', 'dahu-composite' ); ?></th>
						<th><?php esc_html_e( 'Shipping class', 'dahu-composite' ); ?></th>
						<th><?php esc_html_e( 'REST schema', 'dahu-composite' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $kits ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No kits yet. Create a product of type “Configurable kit (Dahu)”.', 'dahu-composite' ); ?></td></tr>
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
						<td><?php echo $class_term && ! is_wp_error( $class_term ) ? esc_html( $class_term->slug ) : '<strong class="dahu-kit-settings__missing">' . esc_html__( 'none', 'dahu-composite' ) . '</strong>'; ?></td>
						<td><code><?php echo esc_html( rest_url( Dahu_Composite_Rest::NAMESPACE_V1 . '/schema/' . $kit->get_id() ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
