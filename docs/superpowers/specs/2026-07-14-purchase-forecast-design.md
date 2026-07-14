# Purchase forecast & sales analytics page — design

Date: 2026-07-14. Status: approved by Dan (this doc reflects the agreed design).

## Purpose

Dan buys supplements in bulk bags and packs them into variations (grams/kg per
pack); some products (e.g. „Ризо-вітал 42") are instead bought per variation, at
different prices. He needs, for a chosen future period, a computed answer to
"how much of this product should I order to get through the period comfortably"
— not so little that stock runs out immediately, not so much that batches expire
and get thrown away (over-buying has no upside: delivery is free, reordering is
always possible, and expiry dates on later purchases are often better). He also
needs an at-a-glance, multi-year view of what was sold — in kg AND in pieces —
for any time range.

## Decisions made during brainstorming

- Multi-year visual comparison: overlaid per-year sales curves on one
  interactive chart; the analysis period is changeable live, with no page
  reload and no repeated server calls.
- Forecast anchoring: a chosen REFERENCE YEAR's sales over the same calendar
  slice, scaled by a coefficient describing how the current year is running.
  The coefficient is auto-computed per selected target — current-year sales
  from Jan 1 to today ÷ reference-year sales over the same slice, in kg for
  product mode and in pieces for variation/weightless mode — and manually
  editable, with an "auto" reset button. Reference-year slice = 0 → coefficient
  1 + a hint.
- Configurable error margin % on top (setting default, adjustable on the page).
- Units: kg is the headline number (bulk-bag purchasing), with a per-variation
  breakdown in pieces; the user can also select ONE variation as the analysis
  target (for products purchased per-variation); weightless targets (ml/бр
  products) are analyzed in pieces only.
- Sales are normalized to kg via the WooCommerce weight field on variations
  (verified live: filled for 35 of 36 published variations), converted through
  the store's `woocommerce_weight_unit` option.
- The recommendation subtracts the currently sellable batch stock recorded by
  the warranty-dates feature; batches expiring mid-period count fully but
  produce a visible warning ("X kg of current stock expires before the period
  ends"). No pseudo-precise partial-expiry modeling.
- Data source: WooCommerce's `{prefix}wc_order_product_lookup` analytics table
  (verified live: complete from 2020-12 to today, ~13.7k rows, every year
  populated) — no order-table joins, no WC Analytics REST dependency.
- Chart: Chart.js, bundled locally (no CDN — Plugin Check), MIT license file
  shipped alongside.
- All interactivity (period, reference year, coefficient, margin,
  recommendation) computed client-side from one AJAX payload per product.
- Own enable toggle + margin setting in the existing settings UI, default OFF.
- Read-only feature: no cron, no order hooks, no writes anywhere.

## Components

| File | Role |
|---|---|
| `includes/class-ordelist-forecast-data.php` | `ORDELIST_Forecast_Data` — the ONLY reader of `{prefix}wc_order_product_lookup` (via `%i`, class-level phpcs:disable like the warranty store); assembles the AJAX payload; pure static `shape_rows()` for unit tests |
| `includes/class-ordelist-forecast-admin.php` | `ORDELIST_Forecast_Admin` — submenu page (slug `ordelist-forecast`, cap `manage_woocommerce`), AJAX endpoint, asset enqueue |
| `assets/js/ole-forecast-calc.js` | Pure math, no DOM/jQuery: cumulative series, range sums, auto-coefficient, forecast, recommendation split, mid-period expiry warning. Exports via `module.exports` when present so node can test it |
| `assets/js/ole-forecast.js` | Page glue: picker, Chart.js wiring, controls, results panel |
| `assets/css/ole-forecast.css` | Page styles (`ole-fc-*` classes) |
| `assets/vendor/chartjs/chart.umd.js` + `LICENSE.md` | Bundled Chart.js 4.x (pinned), enqueued only on this page |
| `tests/forecast/test-calc.js` | Framework-free node tests for the JS math (`node tests/forecast/test-calc.js`) |
| `tests/forecast/test-shape.php` | Framework-free PHP test for `shape_rows()` |

Naming follows the repo rules: `ORDELIST_Forecast_*` classes,
`wp_ajax_ordelist_fc_*` actions, nonce `ordelist_fc`, handles `ordelist-forecast*`,
CSS classes `ole-fc-*`, asset filenames `ole-forecast*` (deliberate `ole-`
convention). Text domain `order-list-enhancer` (follows the WP.org slug
outcome later, like everything else).

## Data layer and AJAX contract

`wp_ajax_ordelist_fc_series` (nonce `ordelist_fc` via `check_ajax_referer`
first, then `manage_woocommerce`). Input: `product` (a product OR variation id
from the picker). The handler resolves the parent product, then returns ONE
payload for the whole product:

```json
{
  "product_id": 123,
  "variations": [
    {
      "id": 456,                  // 0 for a simple product's own row
      "name": "Ризо-вітал 42 — 1 кг",
      "weight_kg": 1.0,           // null when the weight field is empty
      "series": { "2021": { "03-15": 2, "03-18": 1 }, "2022": { … } }
    }
  ],
  "batches": [ { "variation_id": 456, "expiry": "2026-09-01", "qty": 7 } ]
}
```

- `series` is sparse: per year, `MM-DD` → summed pieces sold that day, straight
  from `SUM(product_qty)` grouped by `variation_id, DATE(date_created)`. The
  client multiplies by `weight_kg` for kg curves; Feb 29 data folds into
  `02-28` client-side so years align.
- Weights come from `wc_get_product()->get_weight()` converted to kg via
  `woocommerce_weight_unit` (`g` → ÷1000, `kg` → ×1, `lbs`/`oz` → standard
  factors). A missing weight ships as `null`; the UI shows a "no weight set —
  pieces only" hint for that variation and excludes it from kg totals.
- `batches` reuses `ORDELIST_Warranty_Store::batches_for_target()` per
  variation (and the product itself for simple products), filtered to
  `expiry >= today` and `qty > 0`. If the warranty feature is disabled or has
  no rows, `batches` is empty and the UI notes "залишки не враховано — партії
  не ведуться".
- The product picker is the existing `wc-product-search` enhanced select with
  `woocommerce_json_search_products_and_variations` (variable parents allowed
  here — picking the parent means "whole product"; picking a variation means
  single-variation mode).

## Page and client behavior

1. **Chart** (Chart.js line): X = day-of-year (365 points), Y = cumulative kg
   (or pieces — a units toggle; kg default when any variation has weight). One
   line per year that has data; the current year visually emphasized; legend
   click toggles years. In single-variation mode the same chart shows only that
   variation's data.
2. **Period controls**: start/end date inputs + presets (місяць / квартал /
   півроку). The equivalent calendar slice is highlighted on the chart. The
   SAME range drives both directions:
   - **Past totals table** under the chart: for each year with data, kg and
     pieces sold within that calendar slice (per variation and total) — the
     "порівняння наочне з іншими роками" view.
   - **Forecast panel** for the upcoming period.
3. **Forecast panel**: reference-year select (years with data), coefficient
   input (auto-seeded, editable, „авто" button restores), margin % input
   (seeded from the setting). Output, recomputed instantly on any change:
   - forecast demand: `ref_year_slice × coefficient × (1 + margin/100)` — in
     kg (product mode) or pieces (variation / weightless mode);
   - sellable stock from `batches` (kg via weights / pieces);
   - **recommendation** `= max(0, forecast − stock)`, kg headline + pieces per
     variation (product-mode split proportional to the reference year's
     per-variation shares, rounded up; variations with zero reference sales get
     0);
   - warning line when part of the counted stock expires before period end:
     "з них X кг (Y шт) зійде з термінів до DD.MM".
4. No page reloads: everything after the single series fetch is client math
   from `ole-forecast-calc.js`.

## Settings

New card "Purchase planning" in the **Inventory** tab (existing card/switch
patterns, `ajax_save()` + `defaults()` + `get()` clamp — the full
name↔key↔default triangle):

- `forecast_enabled` — toggle, default `'no'`. Off = fully inert (no menu, no
  AJAX registration, no assets).
- `forecast_margin` — integer percent, default 20, allowed 0–100, silently
  clamped; help text states range/default/clamping per the repo convention.
- A button linking to `admin.php?page=ordelist-forecast`.

Gating follows the established pattern: `ORDELIST_Plugin::__construct` calls
`ORDELIST_Forecast_Admin::init()` only when `forecast_enabled` is `'yes'`.

## Testing

- `tests/forecast/test-calc.js` (node, framework-free `check()` style):
  cumulative building incl. Feb-29 folding and sparse gaps; range sum across
  month/year boundaries; auto-coefficient incl. zero-reference → 1; forecast
  formula; recommendation clamp at 0; per-variation split rounding-up and
  zero-share handling; weightless (pieces) mode; mid-period expiry warning
  amounts.
- `tests/forecast/test-shape.php`: `ORDELIST_Forecast_Data::shape_rows()` turns
  raw lookup rows + weights into the payload structure (grouping, sparse days,
  unit conversion factors, null weight passthrough).
- The runnable suite stays WP-free; live verification (real lookup data, picker,
  chart) happens on dobavki.club after deploy.

## Out of scope

- No writes: no purchase orders, no stock mutations, nothing stored per run.
- No new cron, no order hooks.
- No seasonality math beyond "same calendar slice of a chosen reference year ×
  coefficient × margin" — deliberately simple and inspectable.
- No per-batch FIFO simulation of the future period (the mid-period expiry
  warning covers it informationally).
- Version bump / readme / release packaging (release pass depends on the
  in-flight WP.org review; the WP.org zip must include the new vendor dir via
  `bin/build-zip.sh` whitelist — noted for the release pass).
- Filling the one variation with a missing weight — Dan does that in the
  product editor; the UI surfaces it.

## Release/deploy notes

Feature ships default-OFF; no DB schema, no migration. `bin/build-zip.sh`
whitelist already includes `assets` wholesale, so the vendor dir ships —
verify the zip size stays acceptable (~200 KB added). Chart.js version pinned
in `assets/vendor/chartjs/` with its LICENSE.md (WP.org bundled-library rule).
