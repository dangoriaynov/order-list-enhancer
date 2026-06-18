=== Order List Enhancer ===
Contributors: dangoriaynov
Tags: woocommerce, orders, admin, duplicate orders, customers
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Spot repeat customers, flag likely duplicates, color the shipping column and show the order total — right in the WooCommerce orders admin.

== Description ==

Order List Enhancer improves the WooCommerce admin order screens:

* **Repeat-customer highlighting.** Orders from the same customer (matched by phone, name, or both) are outlined and badged in the orders list, across pagination and all order statuses, so returning customers are recognised even when a previous order is still pending. Click the badge to open a modal with that customer's orders: number (links to the order), date, items, total and status, plus a summary (first order, frequency). The same popup is available from inside an order.
* **Likely-duplicate flag.** Orders placed close together (configurable window) or several in processing get a clear red "duplicate" flag.
* **Shipping coloring.** Color the "Ship to" cell in the orders list and the address block on the order edit screen, by configurable keyword rules (e.g. courier or pickup type).
* **Order total on the edit page.** Optionally show the order total next to the billing address, with a configurable decimal separator.
* **Copy buttons.** One-click copy of the customer name, phone and total on the order edit screen.
* **Phone normalization (display only).** Tidy phone numbers for display (leading 00 → +, add the country code when missing) without ever changing the database.

Every feature has its own toggle on the settings page (WooCommerce → Order List Enhancer), which saves via AJAX without reloading.

Everything runs in the admin only and is shown to users who can edit orders. No external services, no tracking. Compatible with WooCommerce HPOS (High-Performance Order Storage) and sequential order numbers.

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

1. Repeat-customer orders outlined with a badge in the orders list.
2. The details modal with order numbers, dates, items, totals and statuses.
3. The order total shown next to the billing address on the edit screen.

== Changelog ==

= 1.0.8 =
* Repeat-customer popup is now also available from inside an order (edit screen).
* Configurable match mode (phone / name / name + phone), duplicate window, and total decimal separator.
* Copy-to-clipboard buttons for name, phone and total; display-only phone normalization.
* Details modal loads on demand (AJAX) with a loading animation; duplicate flag is evaluated per order.
* WordPress.org readiness: translators comments, headers, i18n loading and packaging cleanups.

= 1.0.0 =
* Initial release: repeat-customer highlighting (all statuses) with a details modal; configurable shipping coloring in the list and on the order edit screen; order total on the edit screen; per-feature toggles on an AJAX-saving settings page; i18n (English + Bulgarian); HPOS support.
