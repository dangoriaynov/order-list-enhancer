# Spec — Checkout delivery-date notice (orddd field highlight)

**Date:** 2026-06-25
**Plugin:** Order List Enhancer (OLE)
**Status:** Draft for review

## Problem

dobavki.club uses the third-party **Order Delivery Date for WooCommerce** (orddd) plugin, which adds a date field to checkout (`#e_deliverydate_0`, wrapper `p#e_deliverydate_0_field`, note `small.orddd_field_note`). Customers repeatedly misread this field two ways:

1. They think it is the **date they will receive** the parcel, when it is the date the shop **ships** it (delivery to a courier office is ~1 working day later).
2. When the earliest selectable date is far out (e.g. the shop owner is on vacation), customers don't realise the order won't move soon.

The field has no prominent explanation, so these mistakes generate support questions and complaints.

## Goal

When the orddd date field is present at checkout, show a **prominent, styled accent block above it** that makes the shipping-vs-receiving distinction unmistakable, plus an optional **vacation banner** the owner can switch on while away. Both texts are editable from the OLE settings. The feature must be **optional and self-disabling**: if the orddd field is absent (plugin off / not on this page), it does nothing.

## Scope

**In:** A checkout-only, JS-injected accent block above the orddd delivery-date field (static, always shown when enabled + field present); an optional manually-toggled vacation banner with an "away until" date that auto-expires; full text control from settings (titles/body/banner text); Bulgarian default copy.
**Out:** Reading orddd's available/blocked dates or any orddd internals; changing which dates the customer can pick (orddd still owns that); any dynamic "this date is far out" detection; admin-side changes; storing anything on the order.

## Approach (and rejected alternative)

**Chosen — JS detect-and-inject on checkout**, mirroring the existing `OLE_Phone_Checkout`: enqueue CSS/JS only on `is_checkout()`, find the field in the DOM, prepend the block, no-op when absent. Robust against orddd version changes and the plugin being off.

**Rejected — render via orddd's own PHP hooks** (`orddd_*` filters/actions): couples OLE to orddd's internal structure and version, and breaks the "optional, silently absent" requirement. Not worth the fragility for a presentational notice.

## Components / files (OLE)

- **Create `includes/class-ole-delivery-notice.php`** — `OLE_Delivery_Notice`:
  - `init()` → `add_action( 'wp_enqueue_scripts', … )`.
  - `enqueue()` → if `is_checkout()`: enqueue `assets/css/ole-delivery-notice.css` + `assets/js/ole-delivery-notice.js` (in footer), `wp_localize_script( 'ole-delivery-notice', 'OLE_DELIVERY', … )` with the resolved texts + vacation payload.
  - `payload()` → builds the localized array: `title`, `body`, and `vacation` = `null` when inactive, else `{ text: '<banner with %s replaced by the formatted date>' }`. Texts fall back to translatable defaults when the option is empty.
  - `vacation_active( string $until, string $today ) : bool` — **pure** helper (no WP): `true` iff `$until` is a valid `YYYY-MM-DD` and `$today <= $until`. Used to decide whether to emit the banner. Unit-testable.
- **Create `assets/js/ole-delivery-notice.js`** — finds every `input[id^="e_deliverydate_"]`; for each, if its `.form-row` wrapper isn't already marked, prepends the accent block (vacation banner first when present, then the static block) as the wrapper's first child and marks it (`data-ole-deliv="1"`). Builds nodes with `textContent` (no `innerHTML` of settings text). Rebinds on the jQuery `updated_checkout` event. No-op if no field / no `OLE_DELIVERY`.
- **Create `assets/css/ole-delivery-notice.css`** — the two block styles (see Visual).
- **Create `tests/delivery-notice/test-vacation-active.php`** — standalone PHP unit test for `vacation_active()` (boundaries: empty, malformed, past, today, future).
- **Modify `includes/class-ole-settings.php`** — new defaults + sanitization (see Settings).
- **Modify `includes/class-ole-settings-page.php`** — new "Delivery-date notice" section (enable toggle, title, body, vacation enable, vacation date, vacation text) + save handling.
- **Modify `includes/class-ole-plugin.php`** — `if ( OLE_Settings::is_yes( $opts, 'delivery_notice_enabled' ) ) { OLE_Delivery_Notice::init(); }`.
- **Modify `order-list-enhancer.php`** — require the new class; bump `OLE_VERSION` (asset cache-bust).
- **Modify `languages/order-list-enhancer-bg_BG.po` (+ recompile `.mo`)** — Bulgarian for the new default strings + settings labels.

## Settings (full control)

New keys in `OLE_Settings::defaults()` (feature ships **off**):

| Key | Default | Notes |
|---|---|---|
| `delivery_notice_enabled` | `no` | master toggle |
| `delivery_notice_title` | `''` | empty → translatable default title |
| `delivery_notice_body` | `''` | empty → translatable default body |
| `delivery_vacation_enabled` | `no` | banner toggle |
| `delivery_vacation_until` | `''` | `YYYY-MM-DD`; empty/past → banner hidden |
| `delivery_vacation_text` | `''` | empty → default; supports one `%s` (formatted date) |

Sanitization in `OLE_Settings::get()`: titles/body/text via `sanitize_text_field` / `sanitize_textarea_field`; `delivery_vacation_until` kept only if it matches `^\d{4}-\d{2}-\d{2}$` (else `''`).

## Visual (CSS — colour hard-coded, not a setting, to keep config lean)

Both blocks: rounded corners, padding, `margin-bottom` so they sit clearly above the field; icon + bold title line + body line; `box-sizing: border-box`, full width of the form row.

- **Static block** — amber "attention": light amber background, amber border/left-accent, dark text, icon 📦. Uses OLE's existing amber `#b26a00` family for the accent.
- **Vacation banner** — warm-red, slightly more prominent than the static block (light red background, red accent border), icon 🌴. Rendered **above** the static block.

Injection point: first child of the orddd `.form-row` wrapper (`p[id$="_field"]` containing `input[id^="e_deliverydate_"]`), i.e. above the field's label.

## Default copy (Bulgarian — confirmed)

- **Static title:** `📦 Това е датата на ИЗПРАЩАНЕ`
- **Static body:** `Не е датата на получаване. Обикновено доставката до офис на куриера отнема около 1 работен ден.`
- **Vacation banner:** `🌴 В момента сме в отпуск до %s. Поръчките, направени сега, ще бъдат изпратени след тази дата.`

The `%s` is replaced server-side with the vacation date formatted via `date_i18n` in the site locale (Bulgarian), so the JS only inserts text. Source strings declared via `__()` in the text domain `order-list-enhancer` and translated through `languages/…-bg_BG.po`.

## Behaviour summary

| Condition | Result on checkout |
|---|---|
| Feature off | nothing |
| Feature on, orddd field absent | nothing (no-op) |
| Feature on, field present, vacation off (or date empty/past) | static amber block above the field |
| Feature on, field present, vacation on, date today/future | warm-red banner **+** static block above the field |
| Multiple orddd fields (`_0`, `_1`, …) | block injected above each |

## Error handling / edge cases

- **orddd absent / disabled:** field selector matches nothing → no-op. No PHP dependency on orddd.
- **XSS safety:** settings text is escaped server-side (`esc_html`) for localization and inserted via `textContent` in JS — editable copy can't inject markup.
- **Idempotency:** wrapper guard attribute prevents duplicate blocks across `updated_checkout` re-renders.
- **Vacation auto-expiry:** the owner can leave the date set; once `today > until` the banner stops showing (decided server-side by `vacation_active()`), no manual cleanup.
- **Locale date format:** formatting done server-side with `date_i18n` so it reads naturally in Bulgarian.

## Testing

- **`vacation_active()` unit test** (plain PHP): `''`→false; `2026-13-40`→false; yesterday→false; today→true; tomorrow→true.
- **Server smoke** (`wp eval`): `OLE_Delivery_Notice::payload()` returns the static texts and a non-null `vacation` only when enabled + active date.
- **Live checkout (manual, deploy + screenshot):** block appears above the orddd field; vacation banner appears above it when toggled and disappears past the date; feature off / orddd disabled → nothing; copy renders in Bulgarian.

## Notes

Independent of the phone-validation and extras features. Built on its own branch off `main` (or directly on `main` if the user prefers, as with phone validation). Deploy per the OLE deploy workflow: bump version, rsync the whole plugin dir (preserves `includes/`, `assets/`), `opcache_reset()`, flush WP + Asset CleanUp + WP Rocket caches, then verify the live checkout DOM selector still matches (`input[id^="e_deliverydate_"]`).
