# Spec — Admin settings page redesign (tabbed, card-based)

**Date:** 2026-07-03
**Plugin:** Order List Enhancer (OLE)
**Status:** Draft for review

## Problem

`OLE_Settings_Page::render()` is one giant `<form>` with ~12 `<h2>` sections stacked in a single column, each a WordPress `.form-table`. There is no dedicated visual system: styling is a small inline `<style>` block plus WP core table styles, controls are heterogeneous (checkboxes, rule tables, color pickers, product-search selects), and every feature carries a long wall-of-text description. The result is hard to read, hard to scan, and visually inconsistent ("купа тексту, важко читається, все різношерсте"). Finding and changing one feature's settings means scrolling past everything else.

## Goal

Restructure the settings page into a **tabbed, card-based** layout that groups the features into a few clear categories, gives every feature a consistent card with an on/off switch and progressive disclosure of its controls, and tightens the copy — **without changing which settings exist or how they are saved**. Presentational + copy only; zero behavior change to any feature.

## Scope

**In:** A two-column layout — a left vertical tab list of 4 categories + a right panel showing only the active category's cards; a reusable feature-card visual (title, one-line description, a "?" help tooltip for detail, a consistent on/off switch, progressively-disclosed controls); a new `assets/css/ole-settings.css`; tab + tooltip JS in the existing `assets/js/ole-settings.js`; splitting the monolithic `render()` into per-category methods; tightened English copy + matching bg_BG; graceful no-JS fallback and ARIA tablist semantics.

**Out:** Any change to setting keys, field `name=` attributes, defaults, sanitization, or `ajax_save()` — the saved data and every feature's runtime behavior stay identical. No new settings, no removed settings, no renamed option keys. No changes to the separate "Print consumables" stock page or to any feature's front-end/admin behavior. No server-side persistence of the active tab (client-side only).

## Approach (and rejected alternatives)

**Chosen — vertical category tabs + cards.** A left column lists 4 category tabs; the right panel renders only the active category's feature cards (others `hidden` but kept in the DOM). Familiar (mirrors WooCommerce settings), maximizes focus (2–4 cards on screen instead of 12 sections), and works with the existing whole-form AJAX save.

**Rejected — accordion cards** (all features as collapsible rows on one page): simplest to build and everything is visible, but the page stays long and it does less to group related features.

**Rejected — single page + sticky anchor nav**: keeps the familiar long scroll and only adds jump links; doesn't reduce on-screen density, which is the core complaint.

## Category grouping (all 12 features mapped)

Grouped by **where the feature acts**:

- **Orders** (`#orders`) — admin order list & screen:
  1. Repeat-customer highlighting (`dup_enabled`, `match_mode`, `scan_limit`, `dup_window_days`)
  2. Shipping coloring (`ship_enabled`, `ship_color_edit`, `ship_rules`, `ship_default_color`, `ship_default_label`)
  3. Order-total coloring (`total_color_enabled`, `total_color_rules`)
  4. Order total on edit page (`total_on_edit`, `total_decimal_sep`, `copy_buttons`)
  5. Default bulk action (`bulk_default_action`)
  6. Open selected one-by-one (`seq_open_enabled`, `seq_open_interval`)
- **Checkout** (`#checkout`) — customer-facing / order creation:
  7. Checkout phone validation (`phone_validate_enabled`, `phone_validate_mode`)
  8. Duplicate-order guard (`dup_guard_enabled`, `dup_guard_mode`, `dup_guard_window_min`)
  9. Delivery-date notice (`delivery_notice_enabled`, `delivery_notice_title`, `delivery_notice_body`, `delivery_vacation_enabled`, `delivery_vacation_until`, `delivery_vacation_text`)
  10. Extras → products (`extras_enabled`, `extras_map`)
- **Inventory** (`#inventory`):
  11. Print consumables (`print_stock_enabled`, `print_stock_threshold_sticker`, `print_stock_threshold_instruction`) + link to the stock page
- **Phone** (`#phone`):
  12. Phone normalization + default country code (`normalize_phone`, `phone_cc`)

## Structure & navigation

One `<form id="ole-settings-form">` wraps everything (unchanged), so the existing AJAX save serializes the **whole** form regardless of the active tab — editing across tabs then pressing Save loses nothing. Inside: a flex/grid two-column shell — `<ul>` tab list (ARIA `role="tablist"`, each tab `role="tab"`) on the left, and one panel per category (`role="tabpanel"`) on the right. Only the active panel is visible; the rest carry `hidden`. The active tab is remembered in the URL hash (`#checkout`) so reload/back reopens it; default is the first tab when no/invalid hash. **No-JS fallback:** without JS all panels render visible (the `hidden` attribute is applied by JS on init), so the page degrades to the current stacked layout rather than breaking.

## Feature card & controls

A reusable card: header row = feature title + a "?" help icon (long explanation in its tooltip) + a right-aligned **on/off switch**; body = the feature's existing controls, revealed/enabled only when the switch is on (progressive disclosure); collapsed/dimmed when off. The switch is a CSS-styled label around the **same checkbox input with the same `name`** as today — no markup change that affects `ajax_save()`. Cards accommodate variants: multi-toggle features (Shipping coloring has list + edit toggles; Total-on-edit has `total_on_edit` + `copy_buttons`; Delivery notice has a vacation sub-toggle) render additional switches inside the body; control-only features with no enable flag (Default bulk action; the `phone_cc` field) render as a card with no header switch. Rule tables (shipping, total-color, extras map), color pickers (`wp-color-picker`), and product-search selects (`wc-enhanced-select`) are kept as-is but restyled to the card's spacing rhythm.

## Copy

Each feature's long description is cut to a single scannable line next to the title; the full explanation moves into the "?" help tooltip. Voice/format made consistent across cards. Both the English source strings and the bg_BG translation are updated together, and the `.mo` recompiled ([[bulgarian-i18n]]).

## Save UX

The single primary **Save** button and its existing status text ("Saving…/Saved./Save failed.") are unchanged in behavior; the button is placed in a sticky bar at the bottom of the panel so it is reachable from any tab. Switching tabs never triggers a save; one Save persists the entire form.

## Components / files

- **Modify `includes/class-ole-settings-page.php`** — replace the monolithic `render()` body with the tabbed shell + a small card/switch helper and four per-category methods: `render_tab_orders()`, `render_tab_checkout()`, `render_tab_inventory()`, `render_tab_phone()`. Every field keeps its current `name=` attribute. `ajax_save()`, `menu()`, `assets()` enqueue list, and settings keys are **unchanged** (except `assets()` also enqueues the new CSS). Move the inline `<style>` out to the new CSS file.
- **Create `assets/css/ole-settings.css`** — the two-column tab shell, tab-list states, card/switch/tooltip styles, progressive-disclosure (dimmed/collapsed) styles, sticky save bar. Enqueued only on the settings screen (existing `is_settings_screen()` guard).
- **Modify `assets/js/ole-settings.js`** — tab switching (click + hash sync + default), applying `hidden` to inactive panels on init (the no-JS fallback), the "?" tooltip behavior, and toggling the dimmed/collapsed state when a card's switch changes. Must not alter the existing save/serialize logic.
- **Modify `languages/order-list-enhancer-bg_BG.po` (+ recompile `.mo`)** — bg_BG for every new/changed label, tab name, and shortened description.
- **Modify `order-list-enhancer.php`** — bump `OLE_VERSION` (asset cache-bust for the new CSS/JS).

## Behavior-preservation invariant (the safety rule)

Because this is a live plugin, the redesign must be provably behavior-neutral: **every input's `name=` in the new markup matches a key read in `ajax_save()`, and no key read in `ajax_save()` loses its input.** This is the primary review/verification check (a render↔save cross-map). Any drift = a setting silently stops saving.

## Error handling / edge cases

- **No JS:** panels render visible (JS applies `hidden` on init), so settings remain fully usable — graceful degradation, not a blank page.
- **Invalid/absent hash:** default to the first tab.
- **Feature off:** controls dimmed/collapsed but still in the DOM and still serialized on save (so toggling off then Save doesn't wipe a feature's stored sub-values any differently than today).
- **XSS:** all dynamic values already escaped server-side; the redesign only rewraps markup and adds static styling/JS — no new user-data output paths.

## Testing

- **Static:** `php -l` on `class-ole-settings-page.php`; `node --check` on `ole-settings.js`; `msgfmt` on the bg_BG `.po`.
- **Render↔save cross-map (the key check):** enumerate every `name=` in the four render methods and confirm each maps to a key handled in `ajax_save()`, and vice-versa — no field dropped, no key orphaned.
- **Live (manual, after deploy):** all 4 tabs switch without reload and via hash; Save persists edits made across multiple tabs; toggles show/hide their controls; color pickers, rule tables, and product-search still work inside cards; copy reads correctly in bg_BG; with JS disabled the page still shows and saves.

## Notes

Presentational redesign on the existing `feature/print-stock-inventory` branch (bundled with the print-consumables work per the confirmed sequence); no behavior change. Deploy is outward-facing and handled with the user ([[deploy-procedure]]). The WordPress.org publishing-readiness audit (guidelines + Plugin Check) is a separate follow-up.
