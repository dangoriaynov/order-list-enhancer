# Per-button copy toggles (design)

Date: 2026-07-12. Approved approach: **three toggles replace the single one**.

## Problem

One setting `copy_buttons` shows/hides all three copy-to-clipboard buttons on the
order edit screen (name, phone, total). Dan wants to control each button separately.

## Design

- Settings keys: `copy_name`, `copy_phone`, `copy_total` (yes/no, default `yes`)
  replace `copy_buttons`.
- Migration in `OLE_Settings::get()`: when the saved option still contains the
  legacy `copy_buttons` and a new key is absent, the new key inherits the legacy
  value. Extracted as a pure static helper so it is unit-testable without WordPress.
  The legacy key stops being written on the next save.
- `OLE_Plugin::enqueue()` passes `flags.copy = { name, phone, total }` (booleans,
  edit screen only); the script-skip early-return treats "any of the three on" as
  copy-active.
- `ole-admin.js` `addCopyButtons()` gates each button on its own flag and returns
  early when all three are off.
- Settings UI: the single "Copy buttons" row in the "Order total on the edit screen"
  card becomes three switch rows (name / phone / total).
- i18n: new labels translated to bg_BG, `.mo` rebuilt.

## Testing

- New WP-free unit test for the migration helper (legacy yes/no/absent ×
  new-keys present/absent).
- Manual: toggle combinations on the live settings page after deploy.
