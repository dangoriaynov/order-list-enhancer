# Spec - Split combo variations into real product lines

**Date:** 2026-08-12
**Plugin:** Order List Enhancer (OLE)
**Status:** Approved by Dan (price split proportional to catalog prices; cost taken from each product's own catalog field)

## Problem

Order 11244 (line 29255) and order 11203 (line 29196) both carry variation **5102** -
`Фитасио (P-K тор) +спринцовка - 1 бр + цитокининова паста`. That variation is a *combo*:
the customer pays 10.00 and receives a Фитасио bottle **and** one Цитокининова паста
(product 2191). Nothing splits it, so:

- stock for 2191 is never decremented (it manages its own stock, 1092 on hand);
- the picker sees one line and can miss the paste;
- COGS sits as one lump (5.73) on a line that is really 5.68 + 0.05.

The existing "Extras -> products" module cannot help: it reads only Product Add-Ons
(`_pao_ids` on a line) and Checkout Add-On `fee` items. Verified on production that
product 4074 has **no** add-ons (`_product_addons = a:0:{}`) and the site has no global
add-ons at all - so this input type was never covered. The original design doc
(2026-06-20) lists variations/bundles as explicitly out of scope.

## Goal

At order creation, a mapped combo line becomes **two real product lines**:
the base product as its ordinary variation, plus each bundled component as its own line.
Line money, order total, and order COGS are all conserved.

```
was:   Фитасио (P-K тор) +спринцовка - 1 бр + цитокининова паста   10.00
now:   Фитасио (P-K тор) +спринцовка - 1 бр х 300 мл                7.73
       Цитокининова паста / Кейки паста  x1                         2.27
                                                                   ------
                                                                    10.00
```

## Scope

**In:** combo *variations* and combo *simple products*; mapping table in settings;
conversion at order creation on the same two hooks the extras module already uses;
a manual per-order action to fix orders placed before this shipped; admin-only
"was -> now" provenance on both lines.

**Out:** automatic discovery of combos (the mapping is manual, as with extras);
cart-side changes; retroactive bulk conversion of all historical orders.

## Data model

New setting `combo_map`, rows of
`{ combo: <product|variation id>, base: <product|variation id>, product: <component id>, qty: <int> }`.

- Several rows sharing the same `combo` describe several components; the `base` of the
  first row with one wins.
- A row is dropped on save unless `combo > 0 && base > 0 && product > 0`; `qty` clamps to 1-99.
- Keyed on **ID, not label**: order item meta stores the attribute *slug*
  (`1-бр-цитокининова-паста`), not the human label, and the existing `extras_map` text
  matching would need a slug->term lookup and could silently miss.

Store mapping as of today (full sweep of all 29 published products / 36 variations -
only one real combo exists; every other `+` in a title is packaging or an ingredient list):

| combo | base | component | qty |
|---|---|---|---|
| 5102 Фитасио - 1 бр + цитокининова паста | 5103 Фитасио - 1 бр х 300 мл | 2191 Цитокининова паста | 1 |

## Conversion

Runs inside `ORDELIST_Extras::convert()` as a third pass, after the PA and CA passes,
on `woocommerce_checkout_order_processed` + `woocommerce_store_api_checkout_order_processed`.

For each `line_item` whose variation id (or product id) is a mapped combo:

1. **Reference prices** come from the catalog: `price(base)` and `price(component) * qty`
   per one combo unit.
2. **Money** is split proportionally to those references. Components are rounded to the
   store's price precision; **the base line takes the remainder**, so the split sums to the
   original line to the cent by construction - `subtotal` and `total` are split separately,
   so a discounted line stays discounted.
3. **Tax** is scaled with the existing `scale_taxes()` by each part's share of the subtotal;
   the base keeps the remaining fraction, so order tax is unchanged.
4. **The base line is edited in place** (product swapped, name and variation attribute meta
   refreshed) rather than deleted and re-added - line 29255 is a Product Bundle child
   (`_bundled_by`, `_bundle_cart_key`), and removing it would break the bundle's child list.
5. **Components** are appended with `add_product_line()`, which already decrements stock and
   tags `_reduced_stock` when the order's stock was already reduced.
6. **Stock on the swap**: only corrected when the base resolves to a *different*
   stock-managing product than the combo did (for 5102 -> 5103 both resolve to parent 4074,
   so nothing moves).
7. **COGS** is re-read from each product's own catalog field (`_wc_cog_cost`, falling back to
   the variation's parent), never derived from the split price, and the order's
   `_wc_cog_order_total_cost` is adjusted by exactly the delta - so the sum of item costs and
   the order cost stay equal. Skipped entirely when a product has no cost field.

**Safety:** if the base product, any component product, or a positive reference total is
missing, the line is left completely untouched.

## Idempotency

- The order-level `_ordelist_extras_converted` guard still blocks the two automatic hooks
  from double-firing.
- Combo splitting is additionally guarded **per line** by `_ordelist_combo_split`, so the
  manual action is safe to run twice.
- `convert()` gains `$force`; only the manual action passes it. This matters because order
  11244 already has `_ordelist_extras_converted = 1` from its Биоглобин checkout add-on -
  without `$force` the fix would silently no-op on exactly the order that needs it.

## Admin visibility

- **Base line:** `➡ Було: «<combo>» (<price>) → <base> (<amt>) + <component> ×N (<amt>)`
- **Component line:** `↩ Виділено з комбо «<combo>» (<amt>)`
- One order note listing every split, as the extras conversion already does.
- Both meta keys are `_`-prefixed and registered in `woocommerce_hidden_order_itemmeta`,
  so nothing reaches the customer invoice or the PIP packing slip. The two product lines
  themselves *do* print - that is the point.

## Fixing 11244 and 11203

No SQL on production: orders are object-cached (Memcached), so a raw INSERT leaves a stale
cached order. Instead the manual **Order actions -> "OLE: split combos / convert extras"**
entry is deployed, then clicked on each order. Both lines carry `_reduced_stock`, so
product 2191 is decremented by the same code path used at checkout.

## Files

- `includes/class-ordelist-extras-matcher.php` - `combo_index()`, `split_amount()` (pure).
- `includes/class-ordelist-extras.php` - combo pass, `$force`, COGS sync, order action, provenance.
- `includes/class-ordelist-settings.php` - `combo_map` default + `clean_combo_map()`.
- `includes/class-ordelist-settings-page.php` - mapping table + save handling.
- `assets/js/ole-settings.js` - add/remove rows for the combo table.
- `tests/extras/test-matcher.php` - unit tests for the two new pure functions.
- `tests/extras/it-combo-variations.php` - integration test.
- `languages/` - bg_BG strings + rebuilt .mo; `readme.txt` changelog; version bump.
