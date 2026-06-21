=== Order List Enhancer ===
Contributors: dangoriaynov
Tags: woocommerce, orders, admin, duplicate orders, customers
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.19
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Spot repeat customers, flag likely duplicates, color the shipping column and show the order total — right in the WooCommerce orders admin.

== Description ==

Order List Enhancer adds the order insights you actually need to the WooCommerce admin — right where you manage orders, without opening each one.

**Spot returning customers and accidental duplicates.**
As you browse the orders list, orders that belong to the same customer (matched by phone, by name, or by both) are outlined in a shared color and marked with a badge. Matching looks across every order status, so a repeat customer is recognised even if their previous order is still processing or was never completed. Click the badge — on the list or from inside an order — to open a popup with that customer's history: each order links to itself and shows its date, items, total and status, plus a quick summary (first order date and how often they buy). Orders placed within a few days of each other, or several still in processing, are flagged in red as likely duplicates, so you can catch double orders before you ship them.

**Color your delivery column.**
Define simple "keyword → color" rules (for example your couriers, or pickup vs. delivery to address) and the "Ship to" cell in the list — and the address block on the order screen — is colored automatically, so you can scan deliveries at a glance.

**Work faster inside an order.**
Show the order total next to the billing address, copy the customer's name, phone or total to the clipboard with one click, and tidy phone numbers for display (leading 00 → +, add the country code when it is missing) — all without ever changing what is stored in the database.

**Convert order "extras" into real products.**
If you sell add-ons through Product Add-Ons or Checkout Add-Ons, map each extra to a product and Order List Enhancer turns it into a real line item the moment the order is created — priced at exactly what the customer paid (the order total never changes), counted in stock, with an admin-only note showing which extra it came from (never printed on invoices). No more moving an order to an editable status and rebuilding it by hand. Off by default.

**Pick a default bulk action.**
Choose which entry is pre-selected in the orders-list bulk-actions menu; the list of choices fills itself from your own orders screen.

**Validate phone numbers at checkout.**
Turn on checkout phone validation and customers get instant, clear feedback on whether their Bulgarian number is valid — and why — as they type, backed by server-side enforcement you can set to *warn* (let the order through but flag it) or *block* (stop it until fixed). Orders with an unusable number are flagged on the order screen and with a badge in the list, so you catch them before you ship. Off by default.

**Built to fit in.**
Every feature has its own on/off switch on a single settings page that saves instantly, with no page reload, a color picker for every color, and a Settings link right on the Plugins screen. Everything runs in the admin only, for users who can edit orders — no external services and no tracking. Compatible with WooCommerce HPOS (High-Performance Order Storage) and sequential order numbers, and fully translatable (English and Bulgarian included).

== Installation ==

1. Upload the plugin to `/wp-content/plugins/order-list-enhancer/` or install it via the Plugins screen.
2. Activate it.
3. Configure it under WooCommerce → Order List Enhancer.

== Frequently Asked Questions ==

= Does it work with HPOS? =
Yes. It declares compatibility with custom order tables and queries them directly; it also falls back to the legacy orders screen.

= Does duplicate detection only look at the current status tab? =
No. It scans recent orders across all statuses (except trash and drafts), so returning customers are detected even when an earlier order is unfinished.

= Does it send data anywhere? =
No. It only reads orders in your admin and renders the UI locally.

== Screenshots ==

1. Repeat customers and likely duplicates highlighted right in the orders list.
2. The customer popup — every order with date, items, total and status, plus a summary.
3. The order screen: colored delivery, order total and one-click copy buttons.
4. Settings — every feature has a toggle, with color pickers, saved without a reload.

== Changelog ==

= 1.0.19 =
* Extras → products: a quantity extra such as “2 бр …” now converts to a product line with quantity N (price unchanged), so the picked quantity is correct.

= 1.0.18 =
* Checkout phone validation: the phone field now shows the standard WooCommerce red "invalid" state (green when valid), the message is clearly styled, and all validation messages are translated to Bulgarian.

= 1.0.17 =
* New: optional checkout phone-number validation (Bulgarian numbers). Live ✓/✗ feedback under the phone field as the customer types, plus server-side enforcement — choose "warn" (allow the order, flag it) or "block" (stop it until fixed). Invalid numbers are flagged on the order page and with a badge in the orders list. Off by default.

= 1.0.16 =
* New: convert add-on "extras" (Product Add-Ons & Checkout Add-Ons) into real product line items at order creation, priced at what the customer paid — net-zero (order total unchanged), idempotent, with admin-only provenance under each converted line that never reaches the invoice. Off by default; map each extra to a product in Settings.
* New: "default bulk action" setting that pre-selects an action in the orders-list bulk menu, self-populated from your orders screen.
* Details popup: the "first order · frequency" summary now wraps to its own line, and the loading spinner is centered.

= 1.0.13 =
* Customer popup also opens from inside an order (badge in the order title).
* Visual color picker for all color fields; centered rule-table headers.
* Match mode (phone / name / name + phone); configurable duplicate window and total decimal separator.
* Display-only phone normalization; copy-to-clipboard buttons for name, phone and total.
* Settings link on the Plugins screen; passes Plugin Check with no errors/warnings.

= 1.0.8 =
* Repeat-customer popup is now also available from inside an order (edit screen).
* Configurable match mode (phone / name / name + phone), duplicate window, and total decimal separator.
* Copy-to-clipboard buttons for name, phone and total; display-only phone normalization.
* Details modal loads on demand (AJAX) with a loading animation; duplicate flag is evaluated per order.
* WordPress.org readiness: translators comments, headers, i18n loading and packaging cleanups.

= 1.0.0 =
* Initial release: repeat-customer highlighting (all statuses) with a details modal; configurable shipping coloring in the list and on the order edit screen; order total on the edit screen; per-feature toggles on an AJAX-saving settings page; i18n (English + Bulgarian); HPOS support.
