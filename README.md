# Dahu Composite

Server-driven configurable product kits for WooCommerce.

Developed and maintained by [Hugo Vial-Jaime — Dahu-Concept](https://dahu-concept.fr).

## What it does

Dahu Composite turns a WooCommerce product into a guided configurator. The
customer walks through steps, picks an option at each one, and ends on a summary
with a price and a weight that the **server** worked out.

That split is the point. Everything a shopper can change is revalidated server
side before it reaches the cart, so a configuration that passed the configurator
is a configuration the cart accepts. Nothing a client sends is trusted.

## Architecture

A kit is a list of **components**. Each component offers products as **options**,
and each option carries its own variation, quantity bounds, weight handling and
display settings. **Scenarios** describe what happens to one component depending
on what was chosen in another: hide options, keep only a subset, or stop the
journey with a message.

The front end is a thin client. It calls one REST route on every customer
action:

```
POST /wp-json/dahu-composite/v1/state
     { product_id, selections, quantity }
  -> corrected selections, validity, price, weight, lines — in one response
```

The server fixes selections invalidated by a scenario and cascades internally,
with no further round trips. `/validate` and `/quote` remain as read-only routes
for external headless clients; they take selections as given and never correct
them.

### REST routes

| Route | Method | Access |
|---|---|---|
| `/schema/{product_id}` | GET | Public for a published kit; drafts need `edit_post` |
| `/product-tabs/{product_id}` | GET | Same |
| `/state` | POST | Public — computes, persists nothing |
| `/validate` | POST | Public — computes, persists nothing |
| `/quote` | POST | Public — computes, persists nothing |
| `/add-to-cart` | POST | Public — writes to the visitor's own session cart |
| `/saved-guest` | POST | Public, behind a dedicated nonce, consent and honeypot |

Price responses carry `Cache-Control: no-store, private`, because prices depend
on the customer's role.

## Features

- Step-by-step configurator with server-side validation
- Scenarios: conditional hiding, restriction to a subset, dead ends with a message
- Price and weight computed from the selected options
- Category sections with their own icons, colours and help bubbles
- Per-customer exceptions unlocking restricted options
- Saved configurations in My Account, with optional guest saving
- Admin simulator: test a configuration before saving the product
- JSON import and export of a kit's configuration
- Compatible with High-Performance Order Storage (HPOS)

## Requirements

| | |
|---|---|
| WordPress | 6.0 or later |
| PHP | 7.4 or later |
| WooCommerce | 7.0 or later |
| HPOS | Compatible |

## Optional integrations

The plugin works on its own. When they are present it also talks to **Dahu
Pricing** (role-based price labels and tiers) and to a **product bundle plugin**
(components offering a bundle as an option). Neither is required, and neither is
bundled.

## Theming

The configurator reads its palette from CSS custom properties, each with a light, neutral fallback, so it is readable on a stock WordPress page with no setup:

| Variable | Fallback | Used for |
|---|---|---|
| `--color-surface-2` | `#F6F7F7` | cards, panels |
| `--color-surface-3` | `#ECEEEF` | inner surfaces |
| `--color-on-surface` | `#1D2327` | text |
| `--color-border` | `#C9CDD1` | borders, separators |
| `--color-brand-green` | `#D5EB17` | accent (selected state, primary buttons) |
| `--color-on-brand-green` | `#1C1A1A` | text on the accent |

A theme imposes its own palette by defining these on `:root`; the theme's values always win over the fallbacks. For a dark theme, define the surface and text variables and the whole configurator follows:

```css
:root {
	--color-surface-2: #2F2F2F;
	--color-on-surface: #F5F5F5;
}
```

## For theme developers

Take over the configurator's styling:

```php
add_filter( 'dahu_composite_theme_provides_styles', '__return_true' );
```

The plugin then enqueues only its functional stylesheet — drawer position,
animation, scroll locking, column grid — and leaves the skin to you.

Render the detail tabs yourself:

```php
add_filter( 'dahu_composite_product_tabs_callback', static function () {
    return 'my_theme_product_tabs'; // ( WC_Product $product, string[] $slugs )
} );
```

## Hooks

Every filter the plugin exposes. None of them is required; the defaults are
what the plugin does on its own.

| Filter | Default | What it changes |
|---|---|---|
| `dahu_composite_theme_provides_styles` | `false` | `true` leaves the skin to the theme and keeps only the functional stylesheet |
| `dahu_composite_product_tabs_callback` | `''` | Name of a callable that renders the detail tabs instead of the plugin |
| `dahu_composite_category_icons` | English keywords | Keyword table of the “Category icon” layout: the pictogram is derived from the product name, so a catalogue in another language replaces the table |
| `dahu_composite_includes_label` | `Includes` | Label of the “Includes” mention shown under a kit in the cart, the order and the Store API |
| `dahu_composite_summary_aside_html` | `''` | HTML block beside the “Add to cart” button of the Summary |
| `dahu_composite_recap_note` | `''` | Note under the Summary |
| `dahu_composite_help_placeholder` | `''` | Placeholder of the help panel |
| `dahu_composite_option_subtitle` | `''` | Subtitle of an option card |
| `dahu_composite_option_generation` | `''` | Generation badge of an option |
| `dahu_composite_option_has_specs` | `null` | Whether an option has specifications to show |
| `dahu_composite_option_weight` | the product weight | Weight used for the kit parcel |
| `dahu_composite_option_unit_price` | the product price | Unit price of an option |
| `dahu_composite_option_unit_regular_price` | the regular price | Regular unit price of an option |
| `dahu_composite_kit_price_html` | `''` | Price HTML of the kit on the product page |
| `dahu_composite_price_tiers` | the tiers found | Price tiers offered |
| `dahu_composite_container_shipping_class_slug` | the kit's own class | Shipping class slug of the kit container |
| `dahu_composite_bundle_configuration` | the computed configuration | Configuration handed to a bundle component |
| `dahu_composite_inline_boot_state` | `true` | `false` stops the boot state being printed inline |
| `dahu_composite_saved_configs_enabled` | `true` | `false` turns saved configurations off for a kit |
| `dahu_composite_guest_save_enabled` | WooCommerce can create a customer | Whether a visitor may save a configuration |
| `dahu_composite_guest_save_fields` | name and e-mail | Fields of the visitor save form |
| `dahu_composite_guest_save_limit` | `10` | Saves allowed per visitor |
| `dahu_composite_remove_data_on_uninstall` | `false` | `true` wipes kit definitions and saved configurations on uninstall |

Two of them carry wording rather than behaviour, and both are documented with a
worked example in the source: `dahu_composite_category_icons`
(`Dahu_Composite_Frontend::category_icons()`) and
`dahu_composite_includes_label` (`Dahu_Composite_Included::includes_label()`).

## Uninstalling

Deleting the plugin removes its settings and caches. Kit definitions and saved
customer configurations are **kept**, because an uninstall is often just a step
in reinstalling. To wipe everything, set `dahu_composite_remove_data_on_uninstall`
to a truthy value before deleting.

## External services

This plugin does not contact any external service.

## License

MIT — see [LICENSE](LICENSE).
