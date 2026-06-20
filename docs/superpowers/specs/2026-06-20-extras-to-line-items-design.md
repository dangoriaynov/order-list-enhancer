# Spec — Convert order "extras" into real product line items

**Date:** 2026-06-20
**Plugin:** Order List Enhancer (OLE)
**Status:** Draft for review

## Problem

On dobavki.club customers add "extras" to an order in two ways:

1. **Product Add-Ons** (`woocommerce-product-addons` 8.0.0) — chosen on the product page; the add-on price is **baked into the parent product's line** (no separate product).
2. **Checkout Add-Ons** (`woocommerce-checkout-add-ons` 2.7.4) — chosen on checkout; stored as a **fee line item**.

Neither becomes a real product, so staff must manually move the order to an editable status, add each extra as a product by hand, and tweak prices to keep the total. Slow and error-prone.

## Goal

At **order creation**, automatically replace each *mapped* extra (PA selection or CA fee) with a **real product line item** priced at exactly what the customer paid. The order total stays identical, stock for the mapped product is decremented, and the customer-facing cart/checkout is unchanged.

## Scope

**In:** Product Add-Ons + Checkout Add-Ons; convert at order creation; manual mapping table; line price = amount actually charged.
**Out:** Product Bundles (already create line items); converting in the cart; retroactive bulk conversion of historical orders. *(Optional phase 2: a per-order "Convert extras now" button for legacy orders, and an "unmapped extra" admin warning.)*

## Verified source data (production)

**PA — on the parent `line_item` meta:**
- Visible meta: `<field name>` ⇒ `<option label>` (e.g. `Екстри` ⇒ `+ 16 бр pH тест ленти (5 мм х 50 мм)`).
- `_pao_ids`: serialized array; each entry `{ key:<field>, value:<label>, id, raw_value, raw_price:<price>, price_type:<flat_fee|…> }`.
- `_pao_total`: sum of add-on prices on the line — **already included** in `_line_subtotal`/`_line_total`.

**CA — a `fee` order item:**
- `order_item_name` = add-on name (e.g. `вкоренител Грандис`).
- `_fee_amount` / `_line_total` = price; plus `_wc_checkout_add_on_id`, `_wc_checkout_add_on_value`, `_wc_checkout_add_on_label`.

## Data model (new settings)

- `extras_enabled`: `yes|no` (default `no`).
- `extras_map`: array of rows `{ match:<string>, product:<product_or_variation_id> }`.
- **Matching** uses one text token per row, compared (trim + collapse whitespace + case-insensitive) against:
  - PA: each `_pao_ids[].value` (the `+ …` label).
  - CA: the fee `order_item_name`.
  - PA labels start with `+`, CA names don't → effectively no collision; one unified table.

## Conversion flow

**Hooks:** `woocommerce_checkout_order_processed` (classic) **and** `woocommerce_store_api_checkout_order_processed` (blocks).
**Guard:** skip if order meta `_ole_extras_converted` is set.

For each **line_item**:
- Parse `_pao_ids`; for each entry whose normalized `value` matches a mapping:
  - price = `raw_price` when `price_type = flat_fee`; if the line has a single add-on, cross-check against `_pao_total`.
  - Add a new `line_item` for the mapped product/variation: qty 1, `subtotal = total = price`, plus the **provenance meta** below.
  - Decrement the parent line's `_line_subtotal` and `_line_total` by `price`; remove that entry from `_pao_ids` and reduce `_pao_total`; **and delete the parent's *visible* add-on meta for it** — the `<field> ⇒ <label>` row (e.g. `Екстри ⇒ + 16 бр…`), which is a non-underscore key that otherwise prints on the invoice. (Keep the rows of any unconverted add-ons on the same line.) Record on the parent the hidden meta `_ole_extra_moved[] = { label, price, → new item id }`.

For each **fee** item:
- If the normalized fee name matches a mapping:
  - Add a new `line_item` for the mapped product at `total = _fee_amount`, plus the **provenance meta** below.
  - Remove the fee item.

**Provenance meta on every converted line** (`_`-prefixed → hidden from WooCommerce's default display, so it never reaches the customer invoice/email; rendered admin-only — see *Visibility*):
- `_ole_addon_origin = { source: pa|ca, label:<original extra text>, price:<charged>, src_item:<parent line id | removed fee name> }`.

Persist items. Because money is **moved, not added**, the order total is unchanged — do **not** call `calculate_totals()` (it would reprice from the catalog). Then:
- Add **one order note** (admin-only) summarizing every conversion: `OLE: «<extra>» → <product name> (×, <price>)`, one line per converted extra. This shows in the order Notes panel as an audit trail.
- Set order meta `_ole_extras_converted = 1`.

## Totals, tax, stock

- **Totals:** net-zero move → order total identical by construction; add a safety assertion that Σ(extracted add-on prices) == `_pao_total` per line, else skip that line and log.
- **Tax:** store extras are tax 0 in the data; copy the parent/fee tax handling (v1 assumes no tax on extras).
- **Stock:** the new line is a real product, so WooCommerce's normal stock reduction (order processing / payment complete, typical for COD) includes it. Verify timing; fallback to `wc_update_product_stock()` if the order already passed reduction.

## Invoice / print safety (verified on prod)

The invoice + pick list are generated by `woocommerce-pip`. Verified in its source:
- It lists item meta via `$item->get_formatted_meta_data( '_', true )`. WooCommerce core **always skips meta whose key starts with `_`** in that call (the `true` flag only stops attribute-label filtering — it never un-hides `_` keys). → our `_ole_addon_origin`, `_ole_extra_moved`, `_ole_extras_converted` **never** print.
- PIP's Product Add-Ons integration only matches `_wc_pao_*` / attached-add-on keys — it does not touch `_ole_*`.
- The conversion **order note** is an order note, not item meta — it never appears on any PIP document.
- Admin provenance is rendered through `woocommerce_after_order_itemmeta`, which fires only on the admin order screen, not in PIP templates.

Consequently the only thing the conversion changes on the printed invoice/pick list is: the converted extra stops showing as a parent-line note (we remove its visible `<field> ⇒ <label>` meta) and instead appears as its **own real product line**. Net total on the document is unchanged.

A safety check belongs in manual testing: print the invoice **and** the pick list for a converted order and confirm no `_ole_*` / "converted from" text appears, only the real product lines.

## Visibility & audit on the order (admin-only)

Staff must be able to confirm, at a glance on the order, that each converted line came from which extra — so the wrong item is never picked/shipped. The **real product lines** appear normally on the order and the packing slip (that's what gets picked); the *provenance* is shown only in admin, never on the customer's invoice/email:

1. **Under each converted line** — render an admin note via `woocommerce_after_order_itemmeta` (the hook PA itself uses): `↩ Преобразувано от добавка: «<original extra>» (беше <price>)`. OLE already loads CSS/JS on the order edit screen, so the converted lines also get a subtle highlight/badge to stand out.
2. **On the affected parent line** (PA) — render `➡ Добавка «<extra>» преместена в отделен ред` from its `_ole_extra_moved` meta, so it's obvious the parent's price dropped on purpose.
3. **Order Notes panel** — the single summary order note added during conversion (see flow) lists every `«extra» → product`.
4. Keep the provenance meta `_`-prefixed and add it to `woocommerce_hidden_order_itemmeta` so the raw keys never render as plain meta rows (we control the display ourselves in #1/#2).

This guarantees the picker sees real products on the slip **and** a clear "converted from …" trail in admin for verification.

## Edge cases

- Unmapped extra → left untouched (no regression).
- Multiple add-ons on one line → handle each; parity check vs `_pao_total`.
- `price_type` ≠ `flat_fee` (quantity/percentage) → v1 skips with a notice (store extras are flat_fee).
- Parent qty > 1 → new line uses qty 1 with the exact charged total (money parity guaranteed; packing quantity is a noted v1 limitation).
- Idempotency via `_ole_extras_converted` + tagged lines; re-save / "order again" won't re-convert.

## UI (OLE settings)

New section **"Extras → products"**:
- Enable toggle.
- Mapping table: rows of `[Extra text]` `[Product — WC product/variation search (wc-enhanced-select)]` `[×]`, plus "Add row".
- Help text: the match is the exact extra label as it appears on the order/checkout.

## Files

- `includes/class-ole-extras.php` — new module: settings access, matching, conversion (order-processed hooks), provenance meta + order note, and admin rendering (`woocommerce_after_order_itemmeta`, `woocommerce_hidden_order_itemmeta`).
- `includes/class-ole-settings.php` — defaults `extras_enabled`, `extras_map` + sanitize.
- `includes/class-ole-settings-page.php` — new section + save handling.
- `assets/js/ole-settings.js` — product search + add/remove rows.
- `assets/css/ole-admin.css` — highlight/badge for converted lines on the order edit screen.
- `order-list-enhancer.php` — require new class; bump version.

## Testing

- Logic: normalization/matching; `_pao_ids` price extraction; net-zero parity.
- Manual (staging): PA-extra order → product line + total parity + stock; CA-fee order → same; unmapped extra → untouched; multi add-on line; idempotency on re-save.
- Visibility: converted lines show the admin "↩ converted from …" note + highlight, the parent shows "➡ moved …", the order note lists all conversions, and the **customer invoice/email shows none of the provenance** (only the real product lines).
