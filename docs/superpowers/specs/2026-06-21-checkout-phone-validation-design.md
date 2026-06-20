# Spec — Checkout phone-number validation

**Date:** 2026-06-21
**Plugin:** Order List Enhancer (OLE)
**Status:** Draft for review

## Problem

On dobavki.club customers frequently enter unusable phone numbers at checkout (couriers Speedy/Econt need a reachable number). Analysis of ~6,140 billing phones on the live site:

- ~5,250 national leading-`0` (10 digits, `0888123456`), ~830 international `+359…` (12 digits) → **~98% Bulgarian**, mostly mobile.
- ~180 are the same numbers with inconsistent separators (`088 123 4567`, `0888/123-456`, `(+359)…`).
- ~38 are 9-digit (dropped the leading `0`); ~60 foreign / no-prefix; ~20 are 1–8-digit **garbage / typos**.

There is no checkout-time validation, so garbage numbers land on orders and are only caught manually.

## Goal

An optional, toggleable checkout phone validator that (a) gives the customer clear, immediate feedback on whether their number is valid **and why**, and (b) flags orders with invalid numbers in the admin so staff can verify them. Accept **any valid Bulgarian number** (mobile + landline); tolerate separators.

## Scope

**In:** Bulgarian-number validation; live (JS) + on-submit (server) checkout validation; a warn|block mode setting (default warn); admin flag on the order edit screen and a badge in the orders list (computed live, so it also flags existing orders).
**Out:** International-number validation (foreign numbers are reported as "enter a Bulgarian number"); bundling google-libphonenumber; editing/normalizing the stored phone value (validation only — the existing admin-side display normalizer `OLE_Phone`/`normalize_phone` is separate and untouched).

## Validation rules (Bulgarian)

`OLE_Phone_Validator::validate( string $raw, string $cc = '359' ) : array` returns `[ 'valid' => bool, 'reason' => string, 'normalized' => string ]`. Pure logic, no WordPress — unit-testable.

1. Keep only digits and a single leading `+`.
2. Resolve country code + national significant number (NSN):
   - `+359…` or `00359…` or `359…` → BG, NSN = the rest.
   - leading `0` (not `00`) → BG national, NSN = digits after the one leading `0`.
   - other `+…` or `00…` → **foreign** → `reason = not_bulgarian`.
   - no prefix, all digits → assume BG, NSN = the digits.
3. Validate NSN: digits only, **length 8–9, first digit 2–9** (mobiles `8X/9X…`, landlines `2…`–`7…`). `normalized = '+359' + NSN`.
4. Reasons → `ok` | `empty` | `too_short` (NSN < 8) | `too_long` (NSN > 9) | `not_bulgarian` | `invalid` (first digit 0/1 or non-conforming).

This accepts every dominant real-world shape (10-digit `0…`, 12-digit `+359…`, `00359…`, bare 9-digit) and rejects the garbage/typo set.

### Messages (English source strings, text domain `order-list-enhancer`; Bulgarian via `languages/…-bg_BG.po`)

- `empty` → "Please enter a phone number."
- `too_short` → "The phone number looks too short. Example: 0888 123 456."
- `too_long` → "The phone number looks too long. Example: 0888 123 456."
- `not_bulgarian` → "Please enter a Bulgarian number (e.g. 0888 123 456)."
- `invalid` → "Invalid phone number. Example: 0888 123 456 or +359 888 123 456."
- `ok` → valid (green ✓).

## Components / files (OLE)

- Create `includes/class-ole-phone-validator.php` — the pure validator above (no WP calls).
- Create `includes/class-ole-phone-checkout.php` — WooCommerce integration:
  - **Frontend:** enqueue `assets/js/ole-phone-checkout.js` on the checkout; localize the per-reason messages (translated via `__()`).
  - **Server enforcement:** `woocommerce_after_checkout_validation` + the Store-API checkout-validation hook — validate the billing phone; **block** mode → `wc_add_notice( … , 'error' )` / Store-API error (order blocked); **warn** mode → allow.
  - **Admin order edit:** on `woocommerce_admin_order_data_after_billing_address`, compute validity live; if invalid render `⚠ Невалиден телефон — провери` (admin-only, styled red).
  - **Admin orders list badge:** independently of the duplicate feature, on the admin orders-list screen query the billing phones of the recent orders in view (own light query, capped), compute the invalid set via the validator, and localize it; `assets/js/ole-admin.js` adds a small ⚠ badge to matching rows (same row-matching mechanism as the duplicate badges, but its own data — works even when duplicate highlighting is off).
- Create `tests/phone/test-phone-validator.php` — standalone unit tests for the validator (the real-data shapes + garbage).
- Modify `includes/class-ole-settings.php` — defaults `phone_validate_enabled` (`no`), `phone_validate_mode` (`warn`|`block`, default `warn`); reuse existing `phone_cc` (default to `359` when empty).
- Modify `includes/class-ole-settings-page.php` — new "Checkout phone validation" section (enable toggle + mode select) + save.
- Modify `assets/js/ole-admin.js` — add the orders-list invalid-phone badge.
- Modify `assets/css/ole-admin.css` — styles for the admin warning + list badge.
- Create `assets/js/ole-phone-checkout.js` — live validation under the billing phone field (input/blur): green ✓ / red ✗ with the reason; uses the same rules as the PHP validator (ported), fed messages via localization.
- Modify `order-list-enhancer.php` — require the new classes; init the checkout module when enabled; bump version.

## Behaviour summary

| Mode | Customer sees (live + on submit) | Order placed? | Admin |
|---|---|---|---|
| **Warn** (default) | ✓/✗ + reason under the field | Yes (even if invalid) | Invalid → red flag on order page + list badge |
| **Block** | ✓/✗ + reason; submit blocked with error | Only when valid | (valid by construction; legacy invalid orders still flagged) |

The admin flag is computed **live** from the billing phone via the validator, so it covers historical orders too — no stored meta, no migration.

## Error handling / edge cases

- Empty phone: WooCommerce already requires the field; the validator's `empty` message is a fallback if the field is optional in some theme.
- Separators/whitespace: stripped before validation (handles the `088 123 456` / `0888/123-456` / `(+359)…` shapes).
- Foreign numbers: reported `not_bulgarian` (rejected in block mode, flagged in warn mode) — consistent with "Bulgarian only".
- Block mode must enforce on BOTH classic and Store-API (block) checkout so it can't be bypassed.
- Live JS and server PHP must agree (same rules) so a customer never sees ✓ then gets a server block.

## Testing

- **Validator unit tests** (runnable with plain PHP): `0888123456`→valid `+359888123456`; `+359 888 123 456`→valid; `00359888123456`→valid; `359888123456`→valid; `888123456`→valid (assume BG); `0888/123-456`→valid; Sofia `028123456`→valid landline; `+4915112345678`→`not_bulgarian`; `12345`→`too_short`; `08881234567`→`too_long`; `0188123456`→`invalid`; ``→`empty`.
- **Server enforcement** (manual + a server `eval` exercising the validation callback): block mode rejects a bad phone; warn mode allows + flags.
- **Admin render smoke** (server `eval`): the order-edit flag renders for an order with a bad billing phone and nothing for a valid one.
- **Live JS**: manual on the checkout — ✓/✗ updates as you type; matches the server result.

## Notes

This module shares the existing `phone_cc` setting with the admin display normalizer but is otherwise independent. It is a new feature in OLE, separate from the extras→products work; it should be built on its own branch (after the extras branch is integrated).
