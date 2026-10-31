=== Dahu Composite ===
Contributors: dahuconcept
Tags: woocommerce, product configurator, composite products, kits, bundles
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.78.1
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

= On the version number =

This plugin is published at 0.x on purpose, and the number is not modesty: it
says that the configurator has been proved by an injection bench and a static
harness, and not yet by a long run on a busy shop. Every money path — pricing,
weight, cart validation — is covered by named assertions that are themselves
proved to fail when the guard behind them is removed, so a defect there is a
defect the bench missed rather than a defect nobody looked for.

What 0.x reserves is the part a bench cannot reach: the shapes of real
catalogues, the themes it will meet, the plugins it will sit beside. The number
will move to 1.0 when it has met those, not when the feature list feels long
enough.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it from the
   Plugins screen.
2. Activate it through the Plugins screen.
3. Create a product and set its type to "Composite product".
4. Add components and options in the Components box, then save.

Settings live under WooCommerce > Composite products.

== Frequently Asked Questions ==

= Does it require WooCommerce? =

Yes, WooCommerce 7.0 or later. The dependency is declared in the plugin header,
so WordPress 6.5 and later will refuse to activate this plugin until WooCommerce
is installed and active, rather than letting it load into a broken screen. On
WordPress 6.0 to 6.4 the declaration is ignored, and the plugin falls back to
saying so in an admin notice.

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

= 0.78.1 =
* The screen a shop sees before it has any data now says what will appear there, and offers the link to the action that fills it.

= 0.78.0 =
* The activation screen now opens on the plugin's own mark rather than its initials, and the banner carries the same drawing as a watermark.

= 0.77.0 =
* Step cards (Thumbnail and Card styles) are compact and horizontal: a small
  photo on the left, edge to edge, the name, text and prices on the right, the
  buttons at the bottom right. Several columns on a desktop, one full-width
  column on a phone. Branch cards ("Kit type") are unchanged.
* New per-kit setting "Summary background" in the Appearance box: Light (the
  default, nothing changes) or Dark. Dark re-scopes the skin tokens inside the
  summary only, so it follows the site's own accent and needs no !important.
  The setting writes no product meta while it stays on Light, so existing
  products carry nothing new.

= 0.76.0 =
* Fixed: the shipping class of composite containers defaulted to one shop's own
  class slug. On any other shop that slug matched no term, so a kit was never
  given a shipping class and saving one raised "the shipping class does not
  exist" every time. No class is assumed any more: the setting starts on
  "None", and the new `dahu_composite_container_shipping_class_slug` reading is
  exposed as `Dahu_Composite_Product_Type::get_container_shipping_class_slug()`.
* Fixed: that same warning also fired when an administrator deliberately chose
  "None". An empty setting and a class that cannot be found are now told apart,
  and only the second is reported.
* Fixed: a theme whose detail-tabs callback raised an `Error` — a method called
  on null, a wrong argument type — took the product page down with it, because
  the guard around the call only caught `Exception`. The global loop was left
  pointing at the wrong product too. The same two clauses around the accessory
  additions were widened, one of which left the re-entry guard stuck on.

= 0.75.0 =
* Fixed: a summary row never carried its state. The class was joined to the row
  without a separator, so `is-current`, `is-done` and `is-todo` were welded to
  the row class and none of their rules ever matched: no current step, no tick,
  no greyed-out step to come.
* Fixed: the summary ticked screens the customer had never seen. The tick asked
  “is anything missing?” — the question that drives the navigation — so an
  optional screen, or one whose choice comes from a default value, was shown as
  settled from the very first step. It is now ticked once the screen has been
  reached and filled in, or passed over.
* Fixed: the cart badge counted a kit once per row. A kit of three components
  announced four items, although the components are always shown indented under
  the kit and the customer added one product. The item count of an order on the
  My account page followed the same rule as the badge. Quantities, prices,
  shipping and the order itself are untouched.
* Fixed: the store setting “Redirect to the cart page after successful
  addition” was ignored by the configurator, which adds through its own REST
  route. It is now honoured, through the standard
  `woocommerce_add_to_cart_redirect` filter and a `dahu_composite_add_to_cart_redirect`
  filter for the kit alone; an empty string keeps the customer on the kit page,
  as before.
* Fixed: a flipped card only closed from its button. A click on an empty area of
  the back now brings it back to its front, leaving the text, every interactive
  element and a text selection in progress alone.

= 0.74.0 =
* Fixed: an option with variants never showed its “Included products” section,
  whatever its variants shipped with. The section was fed from the option
  product alone, which supplies nothing of its own, so the list was always
  empty and the icon never appeared.
* Added: such an option now announces the included products of its preselected
  variation — the first one offered that includes something, in stock first —
  and switches to the list of the variant the customer picks.
* Added: as long as no variant is picked, the “Included products” control is
  greyed out, with “Choose an option to see what is included” in place of the
  list: a preselected list changes with the choice, and announcing it as
  settled would be a promise the order does not keep.

= 0.73.1 =
* Fixed: the first price tier a shop saved was dropped without a word, and
  only stuck on a second attempt. WordPress sanitises an option twice the
  first time it is written, and the second pass discarded the tier.
* Fixed: a question answered on an earlier step came back showing the front
  of its card, so the answer given was no longer in sight on returning to
  that step.

= 0.73.0 =
* First public release.

== Upgrade Notice ==

= 0.73.0 =
First public release.
