# Spec — Order total coloring (threshold rings)

**Date:** 2026-06-22
**Plugin:** Order List Enhancer (OLE)
**Status:** Draft for review

## Problem

Staff want to spot high-value orders at a glance in the WooCommerce orders list and on the
single-order edit screen. The plugin already colors orders by **shipping method** (keyword →
background fill of the `Ship to` cell / edit address panel). There is no equivalent cue for the
**order total**.

## Goal

An optional, toggleable feature that highlights an order when its **total reaches a configured
threshold**, using a **colored ring** around the same element the shipping feature fills. Multiple
thresholds can each have their own color; when an order qualifies for several, the **highest
threshold wins**. Rings and shipping fills are independent visual channels, so an order can show
both at once — readable as a unified color legend.

## Scope

**In:** A settings section with a rules table (`threshold (float) → color → optional label`);
applying a colored ring to (a) the `Ship to` cell in the orders list and (b) the address panel on
the order edit screen, when `order_total >= threshold`; "highest matched threshold wins"; works
independently of (and on top of) the existing shipping coloring; default **off**.

**Out:** Any background-fill change (rings only — shipping fill is untouched); a per-feature default
color (only explicit thresholds color anything); separate list-vs-edit toggles (one toggle covers
both screens); currency/locale conversion (the configured thresholds are compared in the store's
own currency).

## Visual model

Two independent channels on the **same element** (no precedence between them — both can show):

| Channel | Trigger | Effect |
|---|---|---|
| **Fill** (existing) | shipping keyword / default match | background color of the cell/panel |
| **Ring** (new) | `order_total >= threshold` | colored inset ring hugging the cell/panel boundary |

- **Orders list:** ring on `td.column-shipping_address` — `box-shadow: inset 0 0 0 3px <color>`,
  slightly rounded, drawn on top of any shipping fill. No background change.
- **Edit screen:** ring on each `#order_data .address` panel, **composed** with the existing
  left-bar shadow the shipping feature adds (both remain visible).
- The threshold **label** (if set) becomes the element's `title` tooltip. Deterministic
  composition: if the element already has a shipping `title`, append `" · " + ringLabel`; otherwise
  set `title = ringLabel`. Labels are informational, not exclusive.

## Matching rule ("highest hit prevails")

- An order matches a rule when `order_total >= threshold` (float comparison).
- Rules are evaluated from the **highest threshold down**; the first match wins and supplies the
  ring color/label. Rules with no/zero threshold are dropped.

## Components / files (OLE)

- **Create `includes/class-ole-order-color.php`** — `OLE_Order_Color::for_js( $opts ) : array`
  returning `[ 'rules' => [ ['threshold'=>float,'color'=>string,'label'=>string], … ] ]` sorted
  **descending by threshold**, dropping rows with `threshold <= 0` or empty color. Mirrors
  `OLE_Shipping::for_js`. Pure data prep, no WordPress side effects — unit-testable.
- **Modify `includes/class-ole-settings.php`**
  - Defaults: `total_color_enabled` (`no`), `total_color_rules` (`array()`).
  - Add `clean_total_color_rules( $rows )` (alongside `clean_extras_map`): keep rows where
    `threshold > 0`; cast `threshold` to float, `color` via `sanitize_hex_color`, `label` via
    `sanitize_text_field`.
  - Apply the cleaner in `get()`.
- **Modify `includes/class-ole-settings-page.php`**
  - New `<h2>Order total coloring</h2>` section, placed after "Shipping coloring".
  - One enable checkbox (`total_color_enabled`) — note that it covers both list and edit.
  - A rules table reusing the shipping table's markup/classes (`ole-rules`, add/remove buttons,
    `.ole-color` picker) but with a numeric first column:
    `<input type="number" name="total_threshold[]" step="0.01" min="0">`,
    `name="total_color[]"` (`.ole-color`), `name="total_label[]"`.
  - `ajax_save`: build `total_color_rules` from the three parallel arrays (same loop shape as the
    shipping rules / extras map), then pass through `clean_total_color_rules`.
- **Modify `includes/class-ole-plugin.php`**
  - Compute `$total_color_active = OLE_Settings::is_yes( $opts, 'total_color_enabled' )`.
  - Extend the list and edit early-return guards so assets still load when only this feature is on.
  - Add a `totalColor` payload to the localized `OLE_DATA`:
    `{ on: bool, rules: OLE_Order_Color::for_js(...)['rules'] }`.
  - Add `priceFormat: { decimal: wc_get_price_decimal_separator(), thousand: wc_get_price_thousand_separator() }`
    so the list JS can parse the displayed amount into a float.
  - **Edit context:** the order id is already resolved (`$oid`); when the feature is on, pass the
    exact `orderTotal: (float) $order->get_total()` so the edit screen needs no parsing.
- **Modify `assets/js/ole-admin.js`**
  - `matchTotal( totalFloat )` → `{ color, label }` for the highest matched threshold, or `null`
    (rules already sorted desc from PHP; return first where `total >= threshold`).
  - `parseAmount( text )` → float, using `priceFormat` (strip currency symbol/whitespace, remove the
    thousand separator, treat the decimal separator as `.`). Reuses the parsing idea already in the
    copy-total button.
  - **List:** for each row, read `td.column-order_total .woocommerce-Price-amount` → `parseAmount` →
    `matchTotal`; if matched, set the inset ring on `td.column-shipping_address`. Runs from the same
    `run()` / MutationObserver path as the other list features. Gated by `FLAGS.totalColor`.
  - **Edit:** if `D.totalColor.on`, run `matchTotal( D.totalColor.orderTotal )`; if matched, compose
    the ring onto each `#order_data .address` panel (preserving the shipping left-bar shadow).
  - The ring is applied **regardless of** whether the shipping fill is present — independent flags.
- **Modify `assets/css/ole-admin.css`** — minimal helper(s) for the ring (e.g. an `.ole-ring`
  class / rounded corners) if cleaner than inline styles; keep consistent with the existing
  shipping/edit styling.
- **Create `tests/order-color/test-order-color.php`** — standalone PHP unit tests for
  `clean_total_color_rules` (drops zero/empty, casts float, sanitizes color) and
  `OLE_Order_Color::for_js` (descending sort, drops invalid rows).
- **Create `tests/order-color/test-order-color-js.cjs`** — node test (following
  `tests/phone/test-phone-js.cjs`) for `matchTotal` (highest-matched-threshold wins; nothing below
  the lowest threshold) and `parseAmount` (comma vs dot decimal, thousand separators, currency
  symbol).
- **Modify `order-list-enhancer.php`** — `require_once` the new class; bump `Version` header and
  `OLE_VERSION` to `1.0.24`.

## Behaviour summary

| Order total vs thresholds | List `Ship to` cell | Edit address panel |
|---|---|---|
| Below all thresholds | shipping fill only (unchanged) | shipping fill only (unchanged) |
| Meets one or more | + ring in the **highest** matched threshold's color | + same ring, composed with shipping shadow |
| Feature off | unchanged | unchanged |

## Error handling / edge cases

- **Unparseable list total** (missing/odd markup) → no ring for that row (fail silent), shipping
  fill unaffected.
- **Decimal/thousand separators**: parsing is driven by `priceFormat` from PHP, so `1 234,50 лв.`
  and `1,234.50 $` both parse correctly; thresholds are entered as plain numbers (`1234.5`).
- **Equal threshold values** in two rows: deterministic — after the descending sort the first one
  wins; both share the same threshold so the visible difference is only color (last-edited-loses is
  acceptable; not worth de-duping).
- **Feature on, no rules / all rules invalid**: nothing rings; no errors.
- **Shipping disabled, total coloring on**: rings still apply (the color functions no longer depend
  on `FLAGS.shipping`).
- **HPOS + legacy**: both screens are already handled by the existing `screen_context()` logic; the
  new code rides the same enqueue path.

## Testing

- **PHP unit** (plain PHP): `clean_total_color_rules` drops `threshold = 0`/empty-color rows, casts
  `"149.50"` → `149.5`, runs color through `sanitize_hex_color`; `OLE_Order_Color::for_js` returns
  rules sorted `200,150,100` from an unsorted input and omits invalid rows.
- **JS unit** (node): `matchTotal` returns the 200-rule for total `250`, the 150-rule for `150`
  (boundary, `>=`), `null` for `99` when the lowest threshold is `100`; `parseAmount('1 234,50 лв.')`
  → `1234.5` with `{decimal:',',thousand:' '}`, `parseAmount('$1,234.50')` → `1234.5` with
  `{decimal:'.',thousand:','}`.
- **Admin smoke** (manual): a list with orders above/below a threshold shows rings only on the
  qualifying rows, on top of shipping fills; the edit screen of a qualifying order shows the ring on
  the address panel.

## Notes

This is a new, self-contained feature parallel to "Shipping coloring", reusing its settings-table
markup and the existing admin color-application code. It does not touch the shipping fill behavior,
the duplicate row tinting, or the default-bulk-action feature. Build on its own branch.
