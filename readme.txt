=== Order List Enhancer ===
Contributors: dangoriaynov
Tags: woocommerce, orders, admin, duplicate orders, customers
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Spot repeat customers (with a details modal), color the orders-list shipping column by rules, and show the order total on the edit screen — for WooCommerce.

== Description ==

Order List Enhancer improves the WooCommerce admin order screens with three tools:

* **Repeat-customer highlighting.** Orders that belong to the same customer (matched by phone, e-mail, name or shipping address — transitively) are outlined and badged in the orders list, even across pagination. Matching scans all order statuses, so a returning customer is recognised even when a previous order is still pending or was never completed. Click the badge to open a modal listing that customer's orders: order number (links to the order), date, purchased items, total and status.
* **Shipping coloring.** Color the "Ship to" cell in the orders list, and the address block on the single order edit screen, based on configurable keyword rules (for example courier or pickup type).
* **Order total on the edit page.** Optionally show the order total next to the billing address on the single order edit screen.

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

= 1.0.0 =
* Initial release: repeat-customer highlighting (all statuses) with a details modal; configurable shipping coloring in the list and on the order edit screen; order total on the edit screen; per-feature toggles on an AJAX-saving settings page; i18n (English + Bulgarian); HPOS support.
