# Forecast chart: projected current-year curve + month-boundary X ticks

Date: 2026-07-16. Status: approved by Dan (chat).

## Problem

On the purchase-planning page (`admin.php?page=ordelist-forecast`):

1. The current-year cumulative line flatlines after today (no sales data exists
   yet), while the whole point of the page is to show what consumption *could*
   become by the end of the year given the current pace vs. previous years.
2. X-axis ticks are picked by Chart.js `maxTicksLimit: 12` from 365 `MM-DD`
   category labels, producing arbitrary dates (`03-04`, `05-05`, `10-07`, …).

## Decision

### 1. Projection curve

- New pure function in `assets/js/ole-forecast-calc.js`:

  `C.projection( curSeries, refSeries, todayMMDD, coefficient, mmdd )`

  Returns a 365-point array aligned with `C.mmddList()`:
  - `null` for every index **before** today (the solid actual line owns that range);
  - from today's index onward:
    `cumCur[today] + ( cumRef[i] − cumRef[today] ) × coefficient`.

- The safety margin (Резерв %) is **excluded** — the dashed curve shows expected
  consumption; the margin remains only inside the "Recommended purchase" number.
  (Dan's explicit choice.)

- Chart rendering (`assets/js/ole-forecast.js`):
  - The current-year solid dataset gets `null` after today's index instead of a
    flat tail.
  - One extra dataset draws the projection: same color as the current year,
    `borderDash`, `borderWidth` 1.5, starts exactly at today's point so the two
    read as one line. Hidden from the legend (filter), tooltip label
    `"<year> — <projection>"` where `<projection>` is a localized string.
  - Recomputed live in `recalcPanel()` — reacts to coefficient edits, авто,
    reference-year change. Резерв changes do not move it.

- Edge cases: empty/short reference year → flat dashed line; coefficient 0 →
  flat; negative daily values (refunds) in the reference year may make it dip,
  same as the historical curves; period highlight and totals table unchanged.

### 2. X-axis ticks

- `autoSkip: false` + tick callback: label only indices where `MM-DD` ends in
  `-01`, i.e. the 1st of each month → exactly 12 ticks.
- Tick text = localized short month name from the browser locale
  (`document.documentElement.lang`, fallback `en`), via
  `Date.toLocaleDateString(..., { month: 'short' })` — no new translation
  strings for the axis.

## i18n

One new string for the tooltip label (`projection` → bg: `прогноза`) added to
the forecast-admin localize array and `languages/order-list-enhancer-bg_BG.po`;
`.mo` rebuilt.

## Tests

`tests/forecast/test-calc.js` gains cases for `C.projection`:
- continues from the actual cumulative value at today;
- scales reference-year growth by the coefficient;
- `null` before today's index;
- empty reference year → flat;
- today at year end → single-point projection.

## Out of scope

Server code, warranty page, purchase-recommendation math, chart colors/layout.
