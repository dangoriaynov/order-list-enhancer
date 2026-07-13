# Warranty dates (batch expiry tracking) — design

Date: 2026-07-13. Status: approved by Dan (this doc reflects the agreed design).

## Purpose

dobavki.club stocks supplements in batches, each with its own "valid until" date
(срок на годност). Dan needs to (a) record those dates per product **and per
variation**, (b) get notified before a batch's date arrives, and (c) see all
products with their dates at a glance, any time. Sales must automatically reduce
batch quantities oldest-first so the records track the shelf.

## Decisions made during brainstorming

- Per-batch model (one product/variation can hold several dated batches), not a
  single date per product and not per-sale warranty periods.
- Batch row = expiry date + quantity + optional free-text note (lot number etc.).
- All batch CRUD happens on one central admin page; product-edit screens are not
  touched.
- Sales consume batch quantity automatically, FIFO (oldest expiry first); rows are
  deleted only manually.
- Notification = summary email + persistent wp-admin banner; warning window is a
  setting (default 30 days).
- Overview page also lists products/variations with no batch data (collapsed
  section) to reveal coverage gaps.
- Feature has its own enable toggle + settings in the existing tabbed settings UI
  (Inventory tab), default OFF.
- Storage: one custom table + per-order meta consumption map (no movement-log
  table).
- Daily WP-Cron check plus a re-check on overview-page load.

## Components

New files, mirroring the consumables (print-stock) subsystem:

| File | Class | Role |
|---|---|---|
| `includes/class-ordelist-warranty.php` | `ORDELIST_Warranty` | hooks: consume/restore, cron, email, banner |
| `includes/class-ordelist-warranty-store.php` | `ORDELIST_Warranty_Store` | all SQL for the batch table (only place that queries it) |
| `includes/class-ordelist-warranty-admin.php` | `ORDELIST_Warranty_Admin` | overview/management page + AJAX endpoints |
| `includes/class-ordelist-warranty-calc.php` | `ORDELIST_Warranty_Calc` | pure static FIFO/restore/status math, no WP calls |
| `assets/js/ole-warranty.js`, `assets/css/ole-warranty.css` | — | page behavior/styles (asset filenames keep the `ole-` convention; handles are `ordelist-warranty`) |
| `tests/warranty/test-calc.php` | — | framework-free tests for the calc class |

Class names use the established all-caps `ORDELIST_` prefix. Wiring (requires +
init) follows the existing pattern in `order-list-enhancer.php`.

## Data model

Table `{$wpdb->prefix}ordelist_batch`, created/upgraded by a version-gated
`dbDelta` exactly like `ORDELIST_Print_Stock_Store::maybe_upgrade()`
(`DB_VERSION` const + `ordelist_warranty_db` option, autoload off). It must NOT
rely on activation hooks — live deploys are rsync.

```
id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
product_id   BIGINT UNSIGNED NOT NULL            -- parent product
variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0  -- 0 for simple products
expiry       DATE NOT NULL
qty          INT NOT NULL DEFAULT 0              -- signed; negative = records/shelf mismatch
note         VARCHAR(200) NOT NULL DEFAULT ''
notified     TINYINT NOT NULL DEFAULT 0          -- 0 none | 1 warned "soon" | 2 warned "expired"
created      DATETIME NOT NULL
KEY product (product_id, variation_id)
KEY expiry (expiry)
```

Uninstall keeps the table (same policy as the consumables tables). All SQL goes
through the store class with `%i` placeholders per the Plugin Check rules.

## FIFO consumption and restore

Hooks (same set as `ORDELIST_Print_Stock`): consume on
`woocommerce_checkout_order_processed` and
`woocommerce_store_api_checkout_order_processed`; consume/restore transitions on
`woocommerce_order_status_changed`, `woocommerce_trash_order`,
`woocommerce_untrash_order` — the exact consume-vs-restore status mapping copies
the print-stock implementation.

Consume, per order line item:

1. Resolve target: `variation_id` if the line has one, else the product id.
2. Load that target's batches ordered `expiry ASC, id ASC`.
3. No batches at all → skip the line (nothing recorded).
4. Subtract the line quantity from the first batch with `qty > 0`, cascading to
   the next when a batch reaches 0. Demand left after the last positive batch is
   subtracted from the **newest** batch (highest expiry, then highest id), which
   may go negative. If no batch has `qty > 0`, the newest batch takes the whole
   demand (further negative).
5. Record the per-batch takes as order meta `_ordelist_batch_consumed`
   (JSON map `batch_id => qty`, via `$order->update_meta_data` — HPOS-safe).

Idempotence: order meta `_ordelist_wr_state` ∈ `''|consumed|restored`, checked
and set exactly like `_ordelist_ps_state` — an order is never consumed or
restored twice.

Restore (cancel/refund/trash per the print-stock mapping): read the map, add each
qty back to its batch id; a batch deleted in the meantime is skipped silently;
set state `restored`.

Editing `qty` on the overview page never touches `notified`; editing `expiry`
resets `notified` to 0 (the next check re-evaluates from scratch).

## Notifications

Daily WP-Cron event `ordelist_warranty_daily`:

- Scheduled (if absent) when settings are saved with the feature enabled, and on
  `admin_init` while enabled (self-heals a missed schedule); unscheduled on
  settings-save with the feature disabled and on plugin deactivation
  (`register_deactivation_hook`). The callback exits immediately if the feature
  is disabled.
- The overview page runs the same check on load as a fallback.

The check ("today" = `current_time`, site timezone), over batches with
`qty > 0` only:

- `expiry < today` and `notified < 2` → "expired" list, set `notified = 2`.
- `today <= expiry <= today + warranty_days` and `notified < 1` → "expiring
  soon" list, set `notified = 1`.
- If either list is non-empty, send ONE summary email to
  `get_option( 'admin_email' )` (subject + body style copied from the
  consumables low-stock email): sections "Expired" and "Expiring soon", each row
  as product/variation name, expiry date, qty, note.

Banner: `admin_notices`, shown to `manage_woocommerce` users on all admin
screens except the warranty page itself (same suppress pattern as the
consumables low banner), whenever any `qty > 0` batch is expired or within the
window: "N batches expire within X days, M already expired — Open warranty
dates" with a link to the page. Banner state is computed by a cheap store query
(counts only).

## Overview page

`add_submenu_page( 'woocommerce', …, 'manage_woocommerce', 'ordelist-warranty', … )`,
menu title „Срокове на годност" / "Warranty dates". Menu, page, and all hooks are
registered only when the feature is enabled (consumables pattern).

Layout (mirrors the consumables stock page, including its inline-AJAX behavior,
button-disabled-until-valid, and green save-flash):

1. **Add row** (top): product/variation search via the existing
   `wc-enhanced-select` pattern (variations selectable individually), date input,
   qty input, note input, Add button (disabled until product + valid date + qty
   entered).
2. **Batch table**: all rows sorted `expiry ASC`. Columns: product name +
   variation attributes (linked to the product edit screen), editable date, qty,
   note (inline AJAX save per row), status, delete button. Row coloring: red =
   expired or `qty < 0`; yellow = within the warning window; grey + "sold out"
   label = `qty = 0`; normal otherwise.
3. **Coverage gaps** (collapsed `<details>`, count in the summary): published
   non-variable products and individual published variations having zero batch
   rows, linked to their edit screens.

AJAX endpoints: `wp_ajax_ordelist_wr_add`, `wp_ajax_ordelist_wr_save`,
`wp_ajax_ordelist_wr_delete`, all `manage_woocommerce` + nonce `ordelist_wr`
(`check_ajax_referer`), registered only when enabled.

## Settings

New card "Warranty dates" in the **Inventory** tab of the existing settings page,
saved through the existing `ajax_save()` flow (input `name=` ↔ sanitizer pairs):

- `warranty_enabled` — toggle, default `'no'`.
- `warranty_days` — integer, default 30, allowed 1–365, silently clamped; help
  text states range/default/clamping per the v1.0.46 convention.

## i18n

Every new string uses the plugin's current text domain (`order-list-enhancer`
today; it follows whatever slug the WP.org review confirms) and gets a Bulgarian
translation; the `.mo` is rebuilt (bulgarian-i18n rule). Value labels included.

## Testing

`tests/warranty/test-calc.php` (framework-free, `php tests/warranty/test-calc.php`),
covering `ORDELIST_Warranty_Calc`:

- FIFO: single-batch decrement; cascade across 2+ batches; exact-fit; over-demand
  pushing the newest batch negative; all-zero batches taking full demand on the
  newest; no-batches skip.
- Restore: exact put-back per map; deleted-batch skip.
- Status/notification classification: expired / soon / ok on window boundary
  days (expiry today, today+window, today+window+1), `qty <= 0` exclusion for
  emails, `notified` state transitions including date-edit re-arm.

Optional later: `tests/warranty/it-consume.php` (wp-cli eval-file on a live WP)
creating a synthetic order and asserting batch decrement + meta map, like the
extras `it-*` tests.

## Out of scope

- No per-batch sync with WooCommerce stock counts (independent records).
- No movement-log table / audit trail.
- No product-edit-screen UI.
- No customer-facing output anywhere.
- No auto-deletion of expired or sold-out rows.

## Release/deploy notes

Ships in the next version after the current WP.org review-round work (prefix
rename landed as 1.0.49; the text-domain pass may land as 1.0.50 first). The
table auto-creates on the first admin load after deploy via `maybe_upgrade()`;
no migration needed on dobavki.club since the feature starts empty and disabled.
