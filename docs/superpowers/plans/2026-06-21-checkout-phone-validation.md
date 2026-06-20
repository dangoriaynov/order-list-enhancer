# Checkout Phone Validation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an optional, toggleable checkout phone-number validator (Bulgarian numbers) with live + on-submit feedback, a warn/block mode, and an admin flag (order page + orders list) for invalid numbers.

**Architecture:** A pure, dual-ported validator (`OLE_Phone_Validator` in PHP + a parity-tested JS port) holds the Bulgarian rules. `OLE_Phone_Checkout` wires it into WooCommerce: live checkout JS, server-side validation (warn=notice / block=error), and admin display (order-edit flag + orders-list badge computed live). Gated by settings, default off.

**Tech Stack:** PHP 7.4+, WooCommerce 8+ (HPOS), WordPress 6.x. Pure-logic tests run with plain `php` (server) and `node` (local) for JS parity; WC-coupled parts via WP-CLI `eval`/manual.

## Global Constraints

- Text domain `order-list-enhancer`; user-facing strings via `__()` (source English, Bulgarian in `languages/…-bg_BG.po`).
- All files start with `if ( ! defined( 'ABSPATH' ) ) { exit; }` EXCEPT the JS files and the pure-PHP test (which `define('ABSPATH', true)` before requiring).
- Bulgarian rule: strip to digits + leading `+`; resolve NSN; **valid = NSN length 8–9, first digit 2–9**; `normalized = '+359' + NSN`. Reasons: `ok | empty | too_short | too_long | not_bulgarian | invalid`.
- Validator is country-code parameterised via `phone_cc` (default `359`); reuse the existing `phone_cc` setting.
- Settings: `phone_validate_enabled` (default `no`), `phone_validate_mode` (`warn` default | `block`).
- Live JS rules MUST match the PHP validator exactly (parity tested).
- Admin flag computed LIVE from the billing phone (no stored meta, no migration).
- HPOS-safe; do not modify the existing `OLE_Phone` display normalizer.
- Server: SSH `ssh -p 6676 root@31.131.26.210`, root `/home/dobavki/public_html`, `PHP=/opt/alt/php-fpm83/usr/bin/php; WP="$PHP /usr/local/bin/wp --allow-root"`. The live site has a **persistent object cache** — after any `update_option`, call `wp_cache_flush()` and verify with a fresh read.
- Spec: `docs/superpowers/specs/2026-06-21-checkout-phone-validation-design.md`.

---

## File Structure

- Create `includes/class-ole-phone-validator.php` — pure validator (no WP).
- Create `tests/phone/test-phone-validator.php` — standalone PHP unit tests.
- Create `includes/class-ole-phone-checkout.php` — WC integration (frontend enqueue/localize, server validation, admin order-edit flag, orders-list invalid-id query).
- Create `assets/js/ole-phone-checkout.js` — live checkout validation + node-testable JS validator port.
- Create `tests/phone/test-phone-js.cjs` — node parity tests for the JS validator.
- Modify `includes/class-ole-settings.php` — defaults + sanitize for the two new keys.
- Modify `includes/class-ole-settings-page.php` — settings section + save.
- Modify `includes/class-ole-plugin.php` — on the orders-list screen, add the invalid-phone id set to `OLE_DATA`.
- Modify `assets/js/ole-admin.js` — orders-list ⚠ badge from `D.phoneInvalid`.
- Modify `assets/css/ole-admin.css` — admin flag + list badge + checkout feedback styles.
- Modify `order-list-enhancer.php` — require new classes; init checkout module when enabled; bump version.

---

## Task 1: Settings — phone validation keys

**Files:**
- Modify: `includes/class-ole-settings.php`

**Interfaces:**
- Produces: `OLE_Settings::get()['phone_validate_enabled']` (`'yes'|'no'`), `['phone_validate_mode']` (`'warn'|'block'`).

- [ ] **Step 1: Add defaults**

In `defaults()` add after `extras_map`:

```php
			'phone_validate_enabled' => 'no', // checkout phone-number validation
			'phone_validate_mode'    => 'warn', // 'warn' (allow + flag) | 'block' (stop order)
```

- [ ] **Step 2: Sanitize in `get()`**

After the `extras_map` sanitize line add:

```php
		$opts['phone_validate_mode'] = ( 'block' === $opts['phone_validate_mode'] ) ? 'block' : 'warn';
```

- [ ] **Step 3: Commit**

```bash
git add includes/class-ole-settings.php
git commit -m "feat(phone): add phone_validate_enabled + phone_validate_mode settings

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Pure validator (`OLE_Phone_Validator`) + unit tests

**Files:**
- Create: `includes/class-ole-phone-validator.php`
- Test: `tests/phone/test-phone-validator.php`

**Interfaces:**
- Produces: `OLE_Phone_Validator::validate( string $raw, string $cc = '359' ) : array` → `[ 'valid'=>bool, 'reason'=>string, 'normalized'=>string ]`.

- [ ] **Step 1: Write the failing tests**

Create `tests/phone/test-phone-validator.php`:

```php
<?php
// Standalone unit tests for OLE_Phone_Validator (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ole-phone-validator.php';

$fails = 0;
function ck( $cond, $msg ) { global $fails; echo ( $cond ? "ok   - " : "FAIL - " ) . "$msg\n"; if ( ! $cond ) { $fails++; } }
function v( $raw ) { return OLE_Phone_Validator::validate( $raw ); }

// Valid Bulgarian forms (all → +359888123456).
foreach ( array( '0888123456', '+359888123456', '00359888123456', '359888123456', '888123456', '0888 123 456', '0888/123-456', '(+359) 888 123 456' ) as $in ) {
	$r = v( $in );
	ck( $r['valid'] && $r['normalized'] === '+359888123456', "valid: '$in' -> +359888123456" );
}
// Valid Sofia landline (NSN 8 digits, first '2').
$r = v( '028123456' ); ck( $r['valid'] && $r['normalized'] === '+35928123456', "valid Sofia landline 028123456" );

// Invalid cases with specific reasons.
ck( v( '' )['reason'] === 'empty', "empty -> empty" );
ck( v( '   ' )['reason'] === 'empty', "spaces -> empty" );
ck( v( '12345' )['reason'] === 'too_short', "12345 -> too_short" );
ck( v( '08881234567' )['reason'] === 'too_long', "08881234567 (NSN 10) -> too_long" );
ck( v( '0188123456' )['reason'] === 'invalid', "0188123456 (NSN starts 1) -> invalid" );
ck( v( '+4915112345678' )['reason'] === 'not_bulgarian', "+49… -> not_bulgarian" );
ck( v( '004915112345' )['reason'] === 'not_bulgarian', "0049… -> not_bulgarian" );
$bad = v( '12345' ); ck( $bad['valid'] === false && $bad['normalized'] === '', "invalid has valid=false, normalized=''" );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/phone/test-phone-validator.php`
Expected: FAIL — `require` error (class file missing). (No local PHP? run on the server: copy the two files to `/tmp/ole-pv/{includes,tests/phone}` preserving structure and `/opt/alt/php-fpm83/usr/bin/php /tmp/ole-pv/tests/phone/test-phone-validator.php`.)

- [ ] **Step 3: Implement the validator**

Create `includes/class-ole-phone-validator.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Чиста перевірка болгарського телефону (без WordPress) — юніт-тестована;
 * правила дзеркалить JS-порт у assets/js/ole-phone-checkout.js.
 */
class OLE_Phone_Validator {

	/** @return array{valid:bool,reason:string,normalized:string} */
	public static function validate( $raw, $cc = '359' ) {
		$cc  = preg_replace( '/\D+/', '', (string) $cc );
		if ( '' === $cc ) {
			$cc = '359';
		}
		$s   = (string) $raw;
		$has_plus = ( '' !== $s && '+' === $s[0] );
		$digits   = preg_replace( '/\D+/', '', $s );

		if ( '' === $digits ) {
			return self::result( 'empty', '', $cc );
		}

		$nsn = null;
		if ( $has_plus ) {
			if ( 0 === strpos( $digits, $cc ) ) {
				$nsn = substr( $digits, strlen( $cc ) );
			} else {
				return self::result( 'not_bulgarian', '', $cc );
			}
		} elseif ( 0 === strpos( $digits, '00' . $cc ) ) {
			$nsn = substr( $digits, 2 + strlen( $cc ) );
		} elseif ( 0 === strpos( $digits, '00' ) ) {
			return self::result( 'not_bulgarian', '', $cc );
		} elseif ( 0 === strpos( $digits, '0' ) ) {
			$nsn = substr( $digits, 1 );
		} elseif ( 0 === strpos( $digits, $cc ) && strlen( $digits ) >= strlen( $cc ) + 8 ) {
			$nsn = substr( $digits, strlen( $cc ) );
		} else {
			$nsn = $digits;
		}

		$len = strlen( $nsn );
		if ( $len < 8 ) {
			return self::result( 'too_short', '', $cc );
		}
		if ( $len > 9 ) {
			return self::result( 'too_long', '', $cc );
		}
		$first = $nsn[0];
		if ( $first < '2' || $first > '9' ) {
			return self::result( 'invalid', '', $cc );
		}
		return self::result( 'ok', $nsn, $cc );
	}

	private static function result( $reason, $nsn, $cc ) {
		return array(
			'valid'      => ( 'ok' === $reason ),
			'reason'     => $reason,
			'normalized' => ( 'ok' === $reason ) ? '+' . $cc . $nsn : '',
		);
	}
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/phone/test-phone-validator.php`
Expected: `ALL PASS`, exit 0.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ole-phone-validator.php tests/phone/test-phone-validator.php
git commit -m "feat(phone): pure Bulgarian phone validator + unit tests

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Settings UI — "Checkout phone validation" section

**Files:**
- Modify: `includes/class-ole-settings-page.php`

**Interfaces:**
- Consumes: `OLE_Settings::get()['phone_validate_enabled'|'phone_validate_mode']`.
- Produces: POST `phone_validate_enabled`, `phone_validate_mode` saved.

- [ ] **Step 1: Render the section**

In `render()`, immediately before `<h2><?php esc_html_e( 'Phone numbers', 'order-list-enhancer' ); ?></h2>`, insert:

```php
				<h2><?php esc_html_e( 'Checkout phone validation', 'order-list-enhancer' ); ?></h2>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Validate at checkout', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="phone_validate_enabled" <?php echo $cb( 'phone_validate_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'Validate the billing phone number on the checkout (Bulgarian numbers) and flag invalid numbers in the admin.', 'order-list-enhancer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'When invalid', 'order-list-enhancer' ); ?></th>
						<td>
							<?php $pmode = $o['phone_validate_mode']; ?>
							<select name="phone_validate_mode">
								<option value="warn" <?php selected( $pmode, 'warn' ); ?>><?php esc_html_e( 'Warn only (allow the order, flag it)', 'order-list-enhancer' ); ?></option>
								<option value="block" <?php selected( $pmode, 'block' ); ?>><?php esc_html_e( 'Block the order until fixed', 'order-list-enhancer' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Country code comes from “Default country code” below (default 359). Invalid orders are flagged on the order page and in the orders list regardless of mode.', 'order-list-enhancer' ); ?></p>
						</td>
					</tr>
				</tbody></table>

```

- [ ] **Step 2: Save the fields**

In `ajax_save()`, add to the `$opts = array( ... )`:

```php
			'phone_validate_enabled' => $bool( 'phone_validate_enabled' ),
			'phone_validate_mode'    => ( isset( $in['phone_validate_mode'] ) && 'block' === $in['phone_validate_mode'] ) ? 'block' : 'warn',
```

- [ ] **Step 3: Lint + commit**

Lint (server `/tmp`): `php -l includes/class-ole-settings-page.php` → no syntax errors.

```bash
git add includes/class-ole-settings-page.php
git commit -m "feat(phone): settings UI — enable toggle + warn/block mode

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Checkout — live JS validation (+ JS validator parity tests)

**Files:**
- Create: `assets/js/ole-phone-checkout.js`
- Create: `tests/phone/test-phone-js.cjs`
- Create: `includes/class-ole-phone-checkout.php` (frontend enqueue/localize + `init()` scaffold)

**Interfaces:**
- Consumes: `OLE_Phone_Validator` (PHP, for the localized messages keys), `OLE_Settings::get()`.
- Produces: `OLE_Phone_Checkout::init()`; JS global `OLEPhoneValidate( raw, cc )` → `{ valid, reason, normalized }` (parity with PHP).

- [ ] **Step 1: Write the failing JS parity test**

Create `tests/phone/test-phone-js.cjs`:

```js
const v = require( '../../assets/js/ole-phone-checkout.js' );
let fails = 0;
function ck( c, m ) { console.log( ( c ? 'ok   - ' : 'FAIL - ' ) + m ); if ( ! c ) fails++; }
[ '0888123456', '+359888123456', '00359888123456', '359888123456', '888123456', '0888 123 456', '0888/123-456', '(+359) 888 123 456' ].forEach( function ( i ) {
	const r = v( i, '359' ); ck( r.valid && r.normalized === '+359888123456', "valid: '" + i + "'" );
} );
ck( v( '028123456', '359' ).normalized === '+35928123456', 'Sofia landline' );
ck( v( '', '359' ).reason === 'empty', 'empty' );
ck( v( '12345', '359' ).reason === 'too_short', 'too_short' );
ck( v( '08881234567', '359' ).reason === 'too_long', 'too_long' );
ck( v( '0188123456', '359' ).reason === 'invalid', 'invalid' );
ck( v( '+4915112345678', '359' ).reason === 'not_bulgarian', 'not_bulgarian' );
console.log( fails ? '\n' + fails + ' FAILED' : '\nALL PASS' );
process.exit( fails ? 1 : 0 );
```

- [ ] **Step 2: Run it to verify it fails**

Run: `node tests/phone/test-phone-js.cjs`
Expected: FAIL — cannot find module (the JS file doesn't exist yet).

- [ ] **Step 3: Implement the checkout JS (validator port + live UI + node export)**

Create `assets/js/ole-phone-checkout.js`:

```js
( function () {
	// Validator — must mirror OLE_Phone_Validator (PHP).
	function validate( raw, cc ) {
		cc = String( cc || '359' ).replace( /\D+/g, '' ) || '359';
		var s = String( raw == null ? '' : raw );
		var hasPlus = s.length > 0 && s.charAt( 0 ) === '+';
		var digits = s.replace( /\D+/g, '' );
		function res( reason, nsn ) {
			return { valid: reason === 'ok', reason: reason, normalized: reason === 'ok' ? '+' + cc + nsn : '' };
		}
		if ( digits === '' ) { return res( 'empty', '' ); }
		var nsn = null;
		if ( hasPlus ) {
			if ( digits.indexOf( cc ) === 0 ) { nsn = digits.slice( cc.length ); }
			else { return res( 'not_bulgarian', '' ); }
		} else if ( digits.indexOf( '00' + cc ) === 0 ) { nsn = digits.slice( 2 + cc.length ); }
		else if ( digits.indexOf( '00' ) === 0 ) { return res( 'not_bulgarian', '' ); }
		else if ( digits.indexOf( '0' ) === 0 ) { nsn = digits.slice( 1 ); }
		else if ( digits.indexOf( cc ) === 0 && digits.length >= cc.length + 8 ) { nsn = digits.slice( cc.length ); }
		else { nsn = digits; }
		if ( nsn.length < 8 ) { return res( 'too_short', '' ); }
		if ( nsn.length > 9 ) { return res( 'too_long', '' ); }
		var f = nsn.charAt( 0 );
		if ( f < '2' || f > '9' ) { return res( 'invalid', '' ); }
		return res( 'ok', nsn );
	}

	// Node export for parity tests; harmless in the browser.
	if ( typeof module !== 'undefined' && module.exports ) { module.exports = validate; }

	// Browser: live feedback under the billing phone field.
	if ( typeof window === 'undefined' || typeof document === 'undefined' ) { return; }
	var D = window.OLE_PHONE || null;
	if ( ! D ) { return; }

	function fieldEl() { return document.getElementById( 'billing_phone' ); }
	function ensureMsg( field ) {
		var wrap = field.closest( '.form-row' ) || field.parentNode;
		var el = wrap.querySelector( '.ole-phone-msg' );
		if ( ! el ) { el = document.createElement( 'span' ); el.className = 'ole-phone-msg'; wrap.appendChild( el ); }
		return el;
	}
	function check( field ) {
		var raw = field.value || '';
		var msg = ensureMsg( field );
		if ( raw.replace( /\s+/g, '' ) === '' ) { msg.textContent = ''; msg.className = 'ole-phone-msg'; return; }
		var r = validate( raw, D.cc );
		if ( r.valid ) { msg.textContent = '✓ ' + ( D.i18n.ok || '' ); msg.className = 'ole-phone-msg is-ok'; }
		else { msg.textContent = '✗ ' + ( D.i18n[ r.reason ] || D.i18n.invalid ); msg.className = 'ole-phone-msg is-bad'; }
	}
	function bind() {
		var f = fieldEl(); if ( ! f || f.getAttribute( 'data-ole-phone' ) ) { return; }
		f.setAttribute( 'data-ole-phone', '1' );
		f.addEventListener( 'input', function () { check( f ); } );
		f.addEventListener( 'blur', function () { check( f ); } );
	}
	bind();
	// Block checkout re-renders the form; rebind on updates.
	document.body && document.body.addEventListener( 'updated_checkout', bind );
	if ( window.jQuery ) { window.jQuery( document.body ).on( 'updated_checkout', bind ); }
} )();
```

- [ ] **Step 4: Run the parity test to verify it passes**

Run: `node tests/phone/test-phone-js.cjs`
Expected: `ALL PASS`, exit 0.

- [ ] **Step 5: Implement the frontend enqueue/localize in `OLE_Phone_Checkout`**

Create `includes/class-ole-phone-checkout.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Перевірка телефону на чекауті (frontend + server) та позначки в адмінці.
 * Чисті правила — у [[OLE_Phone_Validator]].
 */
class OLE_Phone_Checkout {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_checkout' ) );
		// Server enforcement + admin flags are added in later tasks.
	}

	private static function cc() {
		$cc = preg_replace( '/\D+/', '', (string) OLE_Settings::get()['phone_cc'] );
		return '' !== $cc ? $cc : '359';
	}

	/** Перекладені повідомлення за кодом причини. */
	public static function messages() {
		return array(
			'ok'            => __( 'Looks valid', 'order-list-enhancer' ),
			'empty'         => __( 'Please enter a phone number.', 'order-list-enhancer' ),
			'too_short'     => __( 'The phone number looks too short. Example: 0888 123 456.', 'order-list-enhancer' ),
			'too_long'      => __( 'The phone number looks too long. Example: 0888 123 456.', 'order-list-enhancer' ),
			'not_bulgarian' => __( 'Please enter a Bulgarian number (e.g. 0888 123 456).', 'order-list-enhancer' ),
			'invalid'       => __( 'Invalid phone number. Example: 0888 123 456 or +359 888 123 456.', 'order-list-enhancer' ),
		);
	}

	public static function enqueue_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_script( 'ole-phone-checkout', OLE_URL . 'assets/js/ole-phone-checkout.js', array(), OLE_VERSION, true );
		wp_localize_script(
			'ole-phone-checkout',
			'OLE_PHONE',
			array(
				'cc'   => self::cc(),
				'i18n' => self::messages(),
			)
		);
	}
}
```

- [ ] **Step 6: Lint + commit**

Lint (server `/tmp`): `php -l includes/class-ole-phone-checkout.php` → no syntax errors.

```bash
git add assets/js/ole-phone-checkout.js tests/phone/test-phone-js.cjs includes/class-ole-phone-checkout.php
git commit -m "feat(phone): live checkout validation (JS port + parity tests + enqueue)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Checkout — server-side enforcement (warn / block)

**Files:**
- Modify: `includes/class-ole-phone-checkout.php`

**Interfaces:**
- Consumes: `OLE_Phone_Validator::validate()`, `OLE_Settings::get()`.
- Produces: `OLE_Phone_Checkout::check_value( string $phone ) : array` (validator result); validation hooks.

- [ ] **Step 1: Register hooks in `init()`**

Append inside `init()`:

```php
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_blocks' ), 10, 2 );
```

- [ ] **Step 2: Implement validation**

Add to the class:

```php
	/** Перевіряє значення телефону за поточними налаштуваннями. */
	public static function check_value( $phone ) {
		return OLE_Phone_Validator::validate( (string) $phone, self::cc() );
	}

	private static function is_block_mode() {
		return 'block' === OLE_Settings::get()['phone_validate_mode'];
	}

	/** Класичний чекаут: додає помилку у режимі block. */
	public static function validate_classic( $data, $errors = null ) {
		$phone = isset( $data['billing_phone'] ) ? (string) $data['billing_phone'] : '';
		if ( '' === trim( $phone ) ) {
			return;
		}
		$res = self::check_value( $phone );
		if ( ! $res['valid'] && self::is_block_mode() ) {
			$msgs = self::messages();
			$msg  = isset( $msgs[ $res['reason'] ] ) ? $msgs[ $res['reason'] ] : $msgs['invalid'];
			if ( $errors instanceof WP_Error ) {
				$errors->add( 'ole_phone_invalid', $msg );
			} else {
				wc_add_notice( $msg, 'error' );
			}
		}
	}

	/** Блоковий чекаут (Store API): кидає виняток у режимі block. */
	public static function validate_blocks( $order, $request ) {
		if ( ! self::is_block_mode() || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		$phone = $order->get_billing_phone();
		if ( '' === trim( (string) $phone ) ) {
			return;
		}
		$res = self::check_value( $phone );
		if ( ! $res['valid'] ) {
			$msgs = self::messages();
			$msg  = isset( $msgs[ $res['reason'] ] ) ? $msgs[ $res['reason'] ] : $msgs['invalid'];
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'ole_phone_invalid', esc_html( $msg ), 400 );
		}
	}
```

- [ ] **Step 3: Server smoke test (deploy the two files to `/tmp`, run via WP-CLI)**

Run (server): load the validator + checkout class and assert `check_value` + mode behavior:

```bash
ssh -p 6676 root@31.131.26.210 'PHP=/opt/alt/php-fpm83/usr/bin/php; cd /home/dobavki/public_html && $PHP /usr/local/bin/wp --allow-root eval "
require \"/tmp/ole-p5/includes/class-ole-phone-validator.php\";
require \"/tmp/ole-p5/includes/class-ole-phone-checkout.php\";
\$bak=get_option(\"ole_settings\"); register_shutdown_function(function() use(\$bak){update_option(\"ole_settings\",\$bak);});
\$o=get_option(\"ole_settings\"); \$o[\"phone_validate_enabled\"]=\"yes\"; \$o[\"phone_validate_mode\"]=\"block\"; \$o[\"phone_cc\"]=\"359\"; update_option(\"ole_settings\",\$o); wp_cache_flush();
echo OLE_Phone_Checkout::check_value(\"0888123456\")[\"valid\"]?\"valid-ok\n\":\"valid-FAIL\n\";
echo OLE_Phone_Checkout::check_value(\"12345\")[\"reason\"].\"\n\";
"'
```
Expected: `valid-ok` then `too_short`. (Controller stages the two files in `/tmp/ole-p5/includes` first.)

- [ ] **Step 4: Lint + commit**

```bash
git add includes/class-ole-phone-checkout.php
git commit -m "feat(phone): server-side checkout validation (warn allows, block stops)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Admin flags — order edit screen + orders list badge

**Files:**
- Modify: `includes/class-ole-phone-checkout.php`
- Modify: `includes/class-ole-plugin.php`
- Modify: `assets/js/ole-admin.js`
- Modify: `assets/css/ole-admin.css`

**Interfaces:**
- Consumes: `OLE_Phone_Checkout::check_value()`.
- Produces: `OLE_Phone_Checkout::render_order_flag( WC_Order $order )`; `OLE_Phone_Checkout::invalid_order_ids( int $limit = 500 ) : int[]`.

- [ ] **Step 1: Register the order-edit flag hook in `init()`**

Append inside `init()`:

```php
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_order_flag' ) );
```

- [ ] **Step 2: Implement the order-edit flag + the orders-list query**

Add to the class:

```php
	/** Червона позначка на сторінці замовлення, якщо телефон невалідний. */
	public static function render_order_flag( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		$phone = (string) $order->get_billing_phone();
		if ( '' === trim( $phone ) ) {
			return;
		}
		$res = self::check_value( $phone );
		if ( $res['valid'] ) {
			return;
		}
		$msgs = self::messages();
		$why  = isset( $msgs[ $res['reason'] ] ) ? $msgs[ $res['reason'] ] : $msgs['invalid'];
		printf(
			'<p class="ole-phone-invalid">⚠ %s<br><span class="ole-phone-why">%s</span></p>',
			esc_html__( 'Phone may be invalid — please verify.', 'order-list-enhancer' ),
			esc_html( $why )
		);
	}

	/** ID останніх замовлень із невалідним білінг-телефоном (для бейджів у списку). */
	public static function invalid_order_ids( $limit = 500 ) {
		$ids = array();
		$cc  = self::cc();
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $ids;
		}
		$orders = wc_get_orders(
			array(
				'limit'   => (int) $limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'type'    => 'shop_order',
				'return'  => 'objects',
			)
		);
		foreach ( $orders as $o ) {
			$phone = (string) $o->get_billing_phone();
			if ( '' === trim( $phone ) ) {
				continue;
			}
			$res = OLE_Phone_Validator::validate( $phone, $cc );
			if ( ! $res['valid'] ) {
				$ids[] = $o->get_id();
			}
		}
		return $ids;
	}
```

- [ ] **Step 3: Feed the invalid-id set to the orders-list JS**

In `includes/class-ole-plugin.php` `enqueue()`, after the `if ( 'list' === $context ) { ... }` block that sets `bulk*`, add (still inside the `enqueue()` method, before `wp_enqueue_style`):

```php
		if ( 'list' === $context && OLE_Settings::is_yes( $opts, 'phone_validate_enabled' ) ) {
			$data['phoneInvalid'] = array_map( 'strval', OLE_Phone_Checkout::invalid_order_ids() );
		}
```

Also ensure the script still loads on the list when only this feature is on: change the early-return guard

```php
		if ( 'list' === $context && ! $dup_on && ! $ship_active && '' === $bulk_def ) {
			return;
		}
```

to also keep loading when phone validation is on:

```php
		if ( 'list' === $context && ! $dup_on && ! $ship_active && '' === $bulk_def && ! OLE_Settings::is_yes( $opts, 'phone_validate_enabled' ) ) {
			return;
		}
```

- [ ] **Step 4: Add the orders-list badge in `assets/js/ole-admin.js`**

In `ole-admin.js`, add a function and call it from `run()` (list context). First add near the other helpers:

```js
	var phoneDone = false;
	function markPhoneInvalid() {
		if ( phoneDone ) { return; }
		var set = D.phoneInvalid;
		if ( ! set || ! set.length ) { return; }
		phoneDone = true;
		var ids = {};
		set.forEach( function ( id ) { ids[ String( id ) ] = 1; } );
		var rows = document.querySelectorAll( '.wp-list-table tbody tr' );
		Array.prototype.forEach.call( rows, function ( tr ) {
			var cb = tr.querySelector( '.check-column input[type=checkbox]' );
			if ( ! cb || ! cb.value || ! ids[ String( cb.value ) ] ) { return; }
			var cell = tr.querySelector( 'td.column-order_number, td.order_number' ) || tr.querySelectorAll( 'td' )[0];
			if ( ! cell || cell.querySelector( '.ole-phone-badge' ) ) { return; }
			var b = document.createElement( 'span' );
			b.className = 'ole-phone-badge';
			b.textContent = '⚠ ' + ( ( I18N && I18N.phoneBadge ) || 'phone?' );
			cell.appendChild( document.createElement( 'br' ) );
			cell.appendChild( b );
		} );
	}
```

In `run()` (the non-edit branch) add the call after `markDuplicates();`/`setupBulkActions();`:

```js
		markPhoneInvalid();
```

Add `phoneBadge` to the localized `i18n` array in `class-ole-plugin.php` `enqueue()` (`$data['i18n']`):

```php
					'phoneBadge'  => __( 'invalid phone', 'order-list-enhancer' ),
```

- [ ] **Step 5: Styles in `assets/css/ole-admin.css`**

Append:

```css
/* Checkout phone validation feedback (frontend) */
.ole-phone-msg { display: block; margin-top: 4px; font-size: 13px; }
.ole-phone-msg.is-ok { color: #1a7a3c; }
.ole-phone-msg.is-bad { color: #b32d2e; }
/* Admin invalid-phone flags */
.ole-phone-invalid { color: #b32d2e; font-weight: 600; margin: 6px 0 0; }
.ole-phone-invalid .ole-phone-why { font-weight: 400; color: #50575e; font-size: 12px; }
.ole-phone-badge { display: inline-block; margin-top: 5px; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; line-height: 1.7; color: #fff; background: #b32d2e; white-space: nowrap; }
```

- [ ] **Step 6: Lint + node + commit**

Lint `class-ole-phone-checkout.php` and `class-ole-plugin.php` (server `/tmp`) → no syntax errors; `node --check assets/js/ole-admin.js` → OK.

```bash
git add includes/class-ole-phone-checkout.php includes/class-ole-plugin.php assets/js/ole-admin.js assets/css/ole-admin.css
git commit -m "feat(phone): admin flags — order page warning + orders-list badge

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: Wire-up, gate, version, deploy, E2E

**Files:**
- Modify: `order-list-enhancer.php`
- Modify: `includes/class-ole-plugin.php`

- [ ] **Step 1: Require the new classes**

In `order-list-enhancer.php`, after the `class-ole-extras.php` require add:

```php
require_once OLE_DIR . 'includes/class-ole-phone-validator.php';
require_once OLE_DIR . 'includes/class-ole-phone-checkout.php';
```

- [ ] **Step 2: Gate + init**

In `class-ole-plugin.php` constructor, after the `if ( OLE_Settings::is_yes( $opts, 'extras_enabled' ) ) { OLE_Extras::init(); }` block add:

```php
		if ( OLE_Settings::is_yes( $opts, 'phone_validate_enabled' ) ) {
			OLE_Phone_Checkout::init();
		}
```

- [ ] **Step 3: Bump version**

In `order-list-enhancer.php`, change `Version:` header and `OLE_VERSION` from `1.0.16` to `1.0.17`.

- [ ] **Step 4: Deploy + lint + run all tests**

```bash
rsync -az --no-perms --no-owner --no-group --exclude='.git*' --exclude='.wordpress-org' --exclude='.superpowers' --exclude='docs' --exclude='tests' -e "ssh -p 6676" \
  ./ root@31.131.26.210:/home/dobavki/public_html/wp-content/plugins/order-list-enhancer/
# stage tests in /tmp and run validator unit (php) + check classes load + gate off
ssh -p 6676 root@31.131.26.210 'PHP=/opt/alt/php-fpm83/usr/bin/php; WP="$PHP /usr/local/bin/wp --allow-root"; P=/home/dobavki/public_html/wp-content/plugins/order-list-enhancer; cd /home/dobavki/public_html && \
  for f in order-list-enhancer.php $(ls $P/includes/*.php); do $PHP -l "$f" >/dev/null && echo "ok $(basename $f)" || $PHP -l "$f"; done && \
  $WP eval "var_dump(class_exists(\"OLE_Phone_Validator\"),class_exists(\"OLE_Phone_Checkout\")); echo OLE_VERSION.\" phone_enabled=\".OLE_Settings::get()[\"phone_validate_enabled\"].\"\n\";"'
node tests/phone/test-phone-js.cjs
```
Expected: all `ok`/`No syntax errors`; both classes `true`; `1.0.17 phone_enabled=no`; node `ALL PASS`. Then run the PHP validator unit test from `/tmp` (`ALL PASS`).

- [ ] **Step 5: End-to-end (manual, on a test/staging checkout)**

Enable the feature (WooCommerce → Order List Enhancer → Checkout phone validation: tick, mode Warn). On the storefront checkout: type `12345` → red ✗ "too short"; type `0888 123 456` → green ✓. Switch to Block, try to place an order with `12345` → blocked with the message; fix it → order goes through. Open an existing order whose phone is junk → red flag on the order page + ⚠ badge in the orders list. Disable when done (or leave on, your call) and clear caches (`$WP cache flush`; `rm -rf wp-content/cache/asset-cleanup/* wp-content/cache/wp-rocket/*`).

- [ ] **Step 6: Commit**

```bash
git add order-list-enhancer.php includes/class-ole-plugin.php
git commit -m "feat(phone): wire up + gate checkout phone validation; bump 1.0.17

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review notes

- **Spec coverage:** validator rules + reasons (T2) ✓; messages (T4) ✓; settings enable+mode (T1/T3) ✓; live JS feedback (T4) ✓; server warn/block on classic + Store-API (T5) ✓; admin order-edit flag (T6) ✓; orders-list badge computed live (T6) ✓; historical orders flagged (live compute, T6) ✓; JS↔PHP parity (T4 node test) ✓; gated default-off (T1/T7) ✓; reuse `phone_cc` (T4/T5/T6) ✓; foreign → not_bulgarian (T2) ✓.
- **Type consistency:** `validate(raw,cc)→{valid,reason,normalized}` identical in PHP (T2) and JS (T4); `check_value()→validator array`, `invalid_order_ids()→int[]`, `render_order_flag(WC_Order)` consistent across T5/T6/T7.
- **Known scope choices (spec):** validation only (never rewrites the stored phone); Bulgarian-only (foreign rejected with guidance); admin flag computed live (no meta/migration).
