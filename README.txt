=== Dahu Composite ===
Contributors: hugovialjaime
Tags: woocommerce, product configurator, composite products, kits, bundles
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.73.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Server-driven configurable product kits for WooCommerce: scenarios, validation, pricing and weight computed server side, exposed through a REST API.

== Description ==

Dahu Composite turns a WooCommerce product into a guided configurator. The
customer walks through steps, picks an option at each one, and ends on a
summary with a price and a weight that the server — not the browser — worked
out.

That split is the point. Everything a shopper can change is revalidated server
side before it reaches the cart, so a configuration that passed the
configurator is a configuration the cart accepts. Nothing a client sends is
trusted.

= How it is built =

A kit is a list of components. Each component offers products as options, and
each option can carry its own variation, quantity bounds, weight handling and
display. Scenarios describe what happens to one component depending on what was
chosen in another: hide options, keep only some, or stop the journey with a
message.

The front end is a thin client. It calls one REST route, `/state`, on every
customer action; the server corrects selections invalidated by a scenario,
cascades internally without further round trips, and returns the new state,
price and weight in one response.

= Features =

* Step-by-step configurator with server-side validation
* Scenarios: conditional hiding, restriction to a subset, dead ends with a message
* Price and weight computed from the selected options
* Category sections ("ranges") with their own icons, colours and help bubbles
* Per-customer exceptions unlocking restricted options
* Saved configurations in My Account, with optional guest saving
* Admin simulator: test a configuration before saving the product
* JSON import and export of a kit's configuration
* REST API for headless front ends
* Compatible with High-Performance Order Storage (HPOS)

= Optional integrations =

The plugin works on its own. When they are present it also talks to:

* **Dahu Pricing** — role-based price labels and tiers.
* **A product bundle plugin** — components that offer a bundle as an option.

Neither is required, and neither is bundled.

= For developers =

Themes style the configurator by returning true from
`dahu_composite_theme_provides_styles`; the plugin then drops its own skin.
Detail tabs can be rendered by the theme through
`dahu_composite_product_tabs_callback`.

= External services =

This plugin does not contact any external service.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the
   Plugins screen.
2. Activate it through the Plugins screen.
3. Create a product and set its type to "Configurable kit (Dahu)".
4. Add components and options in the Kit tab, then save.

Settings live under WooCommerce > Dahu Composite.

== Frequently Asked Questions ==

= Does it require WooCommerce? =

Yes, WooCommerce 7.0 or later.

= Is it compatible with High-Performance Order Storage (HPOS)? =

Yes. The plugin reaches orders only through the WC_Order API, never with
direct SQL.

= Why are some REST routes open to anonymous visitors? =

Because the configurator has to work for a shopper who is not logged in, which
is most shoppers. Those routes compute and return a result; they persist
nothing. The one route that lets a visitor save something requires a dedicated
nonce, an explicit consent, and a honeypot check, and it has to be switched on
by the merchant first.

= What happens to my kits if I delete the plugin? =

Nothing, by default. Settings and caches are removed; kit definitions and saved
customer configurations are kept, because an uninstall is often a step in
reinstalling. Set the `dahu_composite_remove_data_on_uninstall` option to a
truthy value before deleting if you really want everything gone.

== Screenshots ==

1. The configurator, mid-journey.
2. The summary step with price and weight.
3. The kit editor in the product admin.
4. The admin simulator.

== Changelog ==

= 0.73.0 =
* First public release.

== Upgrade Notice ==

= 0.73.0 =
First public release.
