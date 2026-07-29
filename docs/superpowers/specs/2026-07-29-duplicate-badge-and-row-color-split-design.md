# Splitting repeat-customer coloring from the badge

**Date:** 2026-07-29
**Status:** approved

## Problem

The "Repeat customers" card is a single switch (`dup_enabled`) that turns on four
things at once: the row outline, the row background fill, the
«клиент · N поръчки» badge, and the details modal the badge opens. Turning off the
row coloring therefore also costs the badge, which is the useful part. The store
owner asked to be able to enable and disable the coloring and the badge
independently.

## Design

`dup_enabled` stays as the card's master switch - it governs whether orders are
scanned for repeat customers at all. Two new settings sit inside the card:

| key | default | controls |
| --- | --- | --- |
| `dup_color_enabled` | `yes` | row outline, row background fill, the colored bar on the first cell (`tr.ole-dup`, `--ole-bd`, `--ole-bg`) |
| `dup_badge_enabled` | `yes` | the «клиент · N поръчки» badge in the orders list, the badge on the order edit screen, and the details modal both open |

The modal follows the badge: with the badge off there is no way into the modal
and no attempt to keep one. That was the owner's explicit choice - the
alternative (opening it from the order number) would add a click behaviour to a
control that already navigates.

Both defaults are `yes`, so existing installs keep today's behaviour: their
stored option has neither key, and `ORDELIST_Settings::get()` merges the
defaults in. No migration code.

Duplicate rows (red) and group rows (palette) share `dup_color_enabled`. They are
one visual language and splitting them further was not asked for.

### Scanning

`ORDELIST_Duplicates::build()` runs on every orders-list load and scans up to
`scan_limit` orders. It is now skipped unless the master switch is on **and** at
least one of the two sub-switches is on, since with both off nothing would be
drawn from the result. This removes the scan for anyone who turns both off.

### Data flow

`ORDELIST_Plugin::enqueue()` already passes a `flags` object to the browser. It
gains `flags.dupColor` and `flags.dupBadge`; `flags.duplicates` keeps its current
meaning of "there is duplicate data to draw" and is now true only when the scan
actually ran. `markDuplicates()` in `assets/js/ole-admin.js` applies the row
classes and CSS variables only under `dupColor`, and builds the badge only under
`dupBadge`. The edit-screen badge (`editGroup` payload) is only sent under
`dupBadge`.

### Testing

`markDuplicates()` has no test today, and the split is exactly the kind of
change that breaks one branch silently. A new
`tests/duplicates/test-mark-duplicates-js.cjs` drives `ole-admin.js` against a
fake DOM and asserts each of the four flag combinations: what gets classed and
styled, and whether a badge is appended.

## Files

- `includes/class-ordelist-settings.php` - two default keys
- `includes/class-ordelist-settings-page.php` - two switches, two saved fields
- `includes/class-ordelist-plugin.php` - scan condition, flags, `editGroup` gating
- `assets/js/ole-admin.js` - `markDuplicates()` split
- `languages/` - two labels, bg_BG translation, rebuilt `.mo`
- `tests/duplicates/test-mark-duplicates-js.cjs` - new
