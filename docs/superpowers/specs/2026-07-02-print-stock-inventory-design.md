# Spec — Print-consumables inventory (stickers + instruction sheets)

**Date:** 2026-07-02
**Plugin:** Order List Enhancer (OLE)
**Status:** Draft for review

## Problem

dobavki.club ships physical parcels that include printed **stickers** (one per item, per SKU) and printed **instruction sheets** (one universal sheet can cover several products — e.g. one instruction for Grandis, Fitosporin, cytokinin paste, succinic acid in powder and the same in tablets). Today there is no tracking of these printed consumables, so the shop only discovers it has run out **after the fact**, when an order can't be packed. The owner needs to know **in advance** when it is time to print more.

## Goal

Track printed consumables as their own inventory, decremented automatically as orders come in, so the owner sees when stock is low and audits every movement:

- Per product/variation: a **sticker** stock, decremented by the purchased **quantity**.
- Named **instruction sheets** (a sheet ↔ several products), each decremented by **1 per order** that contains any of its products.
- Decrement **at order placement**, with **automatic restore** when an order is cancelled / failed / refunded / trashed.
- **Per-type low thresholds** — one for stickers, one for instruction sheets (defaults 20 and 5, since stickers deplete faster); stock **may go negative** to show the real print debt.
- Surfaced four ways: a stock admin page, an admin banner, an order-list badge, and an email.
- **Optional and self-contained**: a master toggle like every other OLE feature; ships **off**.

## Scope

**In:** Two consumable types (per-SKU stickers, shared instruction sheets) stored in custom tables with a movement ledger; automatic consume/restore driven by order status; per-item stock editing (product/variation screen + a dedicated stock page); instruction-sheet CRUD; per-type low thresholds; four notification channels (page, banner, order-list badge, email); Bulgarian copy; WP-free unit tests for the pure logic.

**Out:** Re-computing consumption when an already-consumed order's line items are later edited (v1 uses a snapshot; restore always reverses the original snapshot). Per-item thresholds (only two, one per type). Multi-copy instructions (always −1 per order). Front-end/customer-facing changes. Tracking anything other than stickers and instruction sheets. Purchase-order / reorder automation beyond the "time to print" signal.

## Approach (and rejected alternatives)

**Chosen — custom tables + movement ledger, with per-order reconciliation.** A `ole_consumable` table holds each consumable (a sticker for one product/variation, or an instruction sheet), a `ole_consumable_product` link table maps sheets to their products, and a `ole_consume_log` ledger records every delta. Consumption is driven by **reconciling order state**: on any order transition we compare "should this order be consumed?" (`is_live` status) against the ledger and apply the difference. The ledger is the source of truth for idempotency and for exact restore.

**Rejected — options + product meta (lighter).** Storing sheets in a single option array and sticker stock in product meta matches the existing plugin style and needs less code, but gives no audit trail and makes exact restore harder; rejected in favour of the auditable ledger.

**Rejected — decrement at a fulfilment status** (`processing`/`completed`) instead of at placement. More accurate to the physical print moment but a later signal; the owner wants to know as soon as an order arrives ("коли купують"). Reconciliation still restores anything that later dies.

## Data model (3 tables, `dbDelta`-managed)

Installed/upgraded by a **DB-version installer** (option `ole_print_stock_db`) checked on `admin_init` — **not** only `register_activation_hook`, because deploys are manual rsync without deactivate/activate, so an activation hook may never fire on upgrade.

**`{prefix}ole_consumable`** — one row per consumable.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `type` | VARCHAR(20) | `sticker` \| `instruction` |
| `name` | VARCHAR(191) | sheet name; for a sticker, cached product/variation name |
| `ref_id` | BIGINT | sticker: product **or variation** id; instruction: `0` |
| `stock` | INT (signed) | current count; **may be negative** |
| `low_notified` | TINYINT(1) DEFAULT 0 | edge-trigger flag (avoids repeat emails/banner churn) |
| `updated_at` | DATETIME | |

Index `KEY (type, ref_id)` (a plain index, **not** UNIQUE — instructions all share `ref_id = 0`, which a unique constraint would forbid). One-sticker-per-ref is enforced in the Store layer by lookup-then-upsert on `(type='sticker', ref_id)`; instructions are keyed by `id`.

**`{prefix}ole_consumable_product`** — sheet ↔ products (instructions only, many-to-many).

`consumable_id` BIGINT, `product_id` BIGINT, PRIMARY KEY `(consumable_id, product_id)`, KEY `(product_id)`.

**`{prefix}ole_consume_log`** — movement ledger.

`id` BIGINT PK, `order_id` BIGINT, `consumable_id` BIGINT, `delta` INT (− consume / + restore / manual), `reason` VARCHAR(20) (`order` \| `restore` \| `manual`), `created_at` DATETIME. KEY `(order_id)`, KEY `(consumable_id)`.

Idempotency & restore read from the ledger: an order is "consumed" iff it has net-negative `order`/`restore` rows; restore writes the exact reverse. No per-order applied flag needed. The order-list **badge** is a light order meta `_ole_depleted` (names of consumables this order pushed to ≤ threshold), written during consume.

Sticker `ref_id` = `variation_id` when the line item has one, else `product_id`. Instruction matching uses the **parent** `product_id` (a sheet covers the product regardless of variation).

## Components / files

- **Create `includes/class-ole-print-stock-calc.php`** — `OLE_Print_Stock_Calc`, **pure, no WordPress**:
  - `compute( array $lines, array $config ) : array` — `$lines` = `[ ['product_id'=>, 'variation_id'=>, 'qty'=>], … ]`; `$config` = sticker refs that have stock rows + instruction sheets `[ ['id'=>, 'product_ids'=>[…]], … ]`. Returns `[ consumable_id => delta ]` (stickers −qty per matching ref; each sheet −1 once if its product set intersects the order's parent product ids).
  - `crosses_low( int $before, int $after, int $threshold ) : bool` — edge test for the notification trigger.
  - Unit-tested standalone (like `OLE_Extras_Matcher`, `OLE_Dup_Guard::find_match`).
- **Create `includes/class-ole-print-stock-store.php`** — `OLE_Print_Stock_Store` (DB layer): `install()`/`maybe_upgrade()` (`dbDelta`), consumable CRUD, sheet↔product links, `apply_delta()` via atomic `UPDATE … SET stock = stock - N` + ledger insert in one path, `already_consumed($order_id)` / `ledger_for($order_id)`, `low_items()` for banner/email, `all_for_admin()` for the page.
- **Create `includes/class-ole-print-stock.php`** — `OLE_Print_Stock` (WP glue): `init()` wires the order hooks, product-screen sticker fields, order-list badge column, admin banner, and email; `reconcile( WC_Order $order )` is the single consume/restore entry point.
- **Create `includes/class-ole-print-stock-admin.php`** — `OLE_Print_Stock_Admin`: stock page render + AJAX endpoints (inline stock edit, "+N printed", sheet CRUD).
- **Create `assets/js/ole-print-stock-admin.js`**, **`assets/css/ole-print-stock-admin.css`** — stock page interactivity + styles.
- **Create tests** under `tests/print-stock/` — see Testing.
- **Modify `includes/class-ole-settings.php`** — new defaults + sanitization (see Settings).
- **Modify `includes/class-ole-settings-page.php`** — feature section: enable toggle + the two thresholds (sticker, instruction); link to the stock page.
- **Modify `includes/class-ole-plugin.php`** — `if ( OLE_Settings::is_yes( $opts, 'print_stock_enabled' ) ) { OLE_Print_Stock::init(); }`.
- **Modify `order-list-enhancer.php`** — require the new classes; bump `OLE_VERSION`.
- **Modify `languages/order-list-enhancer-bg_BG.po` (+ recompile `.mo`)** — Bulgarian for all new strings, incl. the email subject/body ([[bulgarian-i18n]]).

## Consumption / restore logic (per-order reconciliation)

`is_live( $status )` = status **not** in `{ cancelled, failed, refunded, trash }`.

`reconcile( $order )`:
- Live and not yet consumed → **consume**: `compute()` deltas → `apply_delta()` (negative) + ledger `reason=order`; evaluate threshold per touched item; write `_ole_depleted` for the badge.
- Not live and currently consumed → **restore**: reverse the ledger's `order` rows exactly (`apply_delta` positive, `reason=restore`); re-arm `low_notified` where stock rises above threshold; clear `_ole_depleted`.
- Live again after restore → consume again (fresh `order` rows).

Hooks: `woocommerce_checkout_order_processed` + `woocommerce_store_api_checkout_order_processed` (creation), `woocommerce_order_status_changed`, `woocommerce_trash_order` / `woocommerce_untrash_order`. Consumption snapshot is taken at first consume; later manual edits to line items do not re-compute (documented limitation) and restore reverses the original snapshot.

## Admin UI

**Stock page** (submenu next to OLE settings):
- Table of all consumables: name, type (sticker/instruction), current stock, status (ok / low / negative). Lowest first, low/negative rows highlighted.
- Inline AJAX stock edit + a quick **"+N printed"** action; every manual change writes a `reason=manual` ledger row.
- **Instruction-sheet** section: create / edit / delete (name + product multiselect via search + initial stock).

**Product screen:**
- A "Sticker stock" field in the **Inventory** tab for simple products; a per-**variation** field for variable products. Saving upserts the sticker consumable for that product/variation.

**Settings:** master toggle + the two thresholds (sticker, instruction) live in the OLE settings page beside the other feature toggles.

## Notifications (4 channels, shared edge-trigger)

`low_notified` flips to 1 when stock crosses to `≤ its type's threshold`, and resets to 0 when stock rises back above it (restock/restore), so signals fire on the **edge**, not every order.

- **Email** — to `admin_email`. One order that pushes several items low sends **one** email listing them all. Subject/body translatable (bg_BG).
- **Admin banner** — `admin_notice` on WC/plugin screens: "N items need printing", linking to the stock page (count of rows where `stock <= ` the row's type threshold).
- **Order-list badge** — on any order that drove a consumable to `≤ threshold` (or negative); rendered from `_ole_depleted`, tooltip distinguishes "low" vs "negative".
- **Stock page** — the primary consolidated view (above).

## Settings

New keys in `OLE_Settings::defaults()` (feature ships **off**):

| Key | Default | Notes |
|---|---|---|
| `print_stock_enabled` | `no` | master toggle |
| `print_stock_threshold_sticker` | `20` | low threshold for stickers (stock ≤ N → "time to print") |
| `print_stock_threshold_instruction` | `5` | low threshold for instruction sheets |

The threshold applied to a consumable is chosen by its `type`. Sanitization in `OLE_Settings::get()`: both thresholds clamped to a sane integer range (e.g. `0…100000`). Consumable stock and sheet definitions live in the custom tables, not in the option.

## Error handling / edge cases

- **Idempotency:** consume/restore check the ledger first, so the double checkout hooks (classic + Store API) can't double-count.
- **Atomicity:** stock changes use `UPDATE … SET stock = stock ± N` (never read-modify-write), with the ledger row written in the same path — safe under concurrent orders.
- **Untracked product:** a line item with no sticker row is simply skipped (not an error); a product in no sheet triggers no instruction.
- **Deleted product/sheet:** the consumable and its ledger rows remain for audit; orphaned-product stickers can be hidden/flagged on the stock page.
- **Uninstall:** tables are **kept** (valuable inventory data); `uninstall.php` does not drop them.
- **XSS/format safety:** admin inputs sanitized server-side; stock values are integers.

## Testing

- **`OLE_Print_Stock_Calc::compute()` (plain PHP):** stickers −qty; instruction −1 per order regardless of qty; two products from the same sheet → still −1; variation line → keyed on `variation_id`; sheet product-set intersection; empty config → no deltas.
- **`crosses_low()` (plain PHP):** boundaries around the threshold (above→at, at→below, staying below, rising back above).
- **Store smoke (`wp eval`):** create → order rows appear and stock drops; cancel → restore rows reverse exactly; re-activate → consumes again; manual edit writes a `manual` row.
- **Live (manual, deploy):** place a real order, confirm stickers drop by qty and the shared instruction by 1; low threshold fires banner + badge + one email; cancel restores; feature off → nothing.

## Notes

Independent of the other OLE features; own branch off `main`. Deploy per the OLE workflow ([[deploy-procedure]]): bump version, rsync the whole plugin dir, `opcache_reset()`, flush caches, and confirm the DB installer created/updated the three tables. This is the first of two features from the same request; the **order-list quick tags** feature is a separate spec.
