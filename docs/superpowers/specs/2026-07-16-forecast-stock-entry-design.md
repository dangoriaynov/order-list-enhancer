# Planning page: per-variation stock + sales table with quick batch entry

Date: 2026-07-16. Status: approved by Dan (chat) - "quick entry -> batches" option.

## Problem

Dan wants, per variation, right on the purchase-planning page:
1. how much stock is left (with a way to ENTER it for products where quantities
   are not tracked),
2. how much was sold (exact kg and pieces of each variation),
3. the purchase suggestion driven by these facts plus the expiry dates of stock.

## Decision

### New "stock and sales by variation" table

Rendered under the per-year totals table, one row per variation of the picked
product (or a single row for a simple product), visible once a product is
selected (same `ole-fc-needs-product` gating):

- **Name** - the existing payload name (recovered names for deleted variations).
- **Sold in the selected slice, reference year** - pieces and kg (kg empty when
  the variation has no weight). Client-side from the payload series:
  `C.rangeSum( C.foldFeb29( v.series[refYear] ), start, end )`, kg = pcs sum x
  `weight_kg`. Re-rendered when period / reference year / unit change.
- **Sellable stock (pcs)** - sum of that target's rows in `payload.batches`
  (already filtered to qty > 0 and unexpired).
- **Quick entry** (existing targets only; deleted variations get a read-only
  row): number input (qty, positive int), date input (optional "good until"),
  Add button.

### Quick entry endpoint

`wp_ajax_ordelist_fc_add_batch` in `ORDELIST_Forecast_Admin` (nonce
`ordelist_fc`, capability `manage_woocommerce`) - the forecast feature must not
depend on the warranty feature being enabled, so it gets its own action:

- Input: `target` (variation id or simple-product id), `qty` (int > 0),
  `expiry` (optional).
- Expiry semantics: valid `Y-m-d` date -> used as is; empty -> sentinel
  `2099-12-31` (stock counts as sellable forever, never triggers warnings).
  Invalid -> 400.
- Resolves product/variation ids exactly like `ORDELIST_Warranty_Admin::ajax_add`
  (variable parent rejected), then `ORDELIST_Warranty_Store::add_batch(...)`
  with note `''`, then `ORDELIST_Warranty::run_check()`.
- Success response: `{ ok: true }`; the client re-requests
  `ordelist_fc_series` to refresh the payload and re-renders (single source of
  truth, no client-side patching).

### Shared date validation

`clean_date()` moves from `ORDELIST_Warranty_Admin` (private) to
`ORDELIST_Warranty_Calc::clean_date()` (public static, pure); the warranty admin
delegates to it. New pure helper `ORDELIST_Warranty_Calc::stock_expiry( $raw )`:
valid date -> the date, '' -> '2099-12-31', anything else -> null (reject).

## i18n

New strings (bg_BG translated, .mo rebuilt, plain hyphens only):
"Stock and sales by variation", "Variation", "Sold in %s", "Stock",
"Good until (optional)", "Add", "Added.".

## Tests

- `tests/warranty/test-calc.php`: `clean_date` (valid, invalid format, Feb 30)
  and `stock_expiry` (date / empty->sentinel / garbage->null).
- Existing suites stay green.

## Out of scope

Editing/deleting batches from the planning page (the warranty page already does
that), stock for deleted variations, changing the recommendation formula.
