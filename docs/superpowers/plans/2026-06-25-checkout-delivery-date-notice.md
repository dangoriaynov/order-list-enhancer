# Checkout delivery-date notice (orddd field highlight) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When the Order Delivery Date (orddd) field is present at checkout, show a prominent amber "this is the SHIPPING date, not the delivery date" block above it, plus an optional owner-toggled red vacation banner — all texts editable in OLE settings, no-op when the field is absent.

**Architecture:** Mirror the existing `OLE_Phone_Checkout` frontend feature. A new `OLE_Delivery_Notice` PHP class enqueues a CSS + JS pair only on `is_checkout()` and localizes the resolved copy (`window.OLE_DELIVERY`). The JS finds `input[id^="e_deliverydate_"]` and prepends the notice into each field's `.form-row` wrapper; it does nothing if the field (i.e. the orddd plugin) is absent. No PHP dependency on orddd. The only non-trivial logic — whether the vacation banner is still active for today's date — lives in a pure, unit-tested helper.

**Tech Stack:** PHP 7.4+, WooCommerce, vanilla JS (no build step), WordPress i18n (`__()` + `.po`/`.mo`), standalone `php` unit tests.

## Global Constraints

- Text domain for every string: `order-list-enhancer`.
- Feature ships **disabled** (`delivery_notice_enabled` default `no`).
- **No dependency on orddd**: detection is a DOM selector; absent field → no-op, no errors.
- Customer-facing copy uses **English source strings** via `__()`, translated to Bulgarian in `languages/order-list-enhancer-bg_BG.po` (same pattern as the phone feature).
- JS inserts settings text via `textContent` only — **never** `innerHTML` of editable copy (XSS).
- Block colours are **hard-coded in CSS**, not settings.
- Bump `OLE_VERSION` (header + const in `order-list-enhancer.php`) so the new CSS/JS cache-bust.
- Deploy per the OLE deploy workflow: rsync the whole plugin dir, `opcache_reset()`, flush WP + Asset CleanUp + WP Rocket caches; SSH/rsync Bash calls need `dangerouslyDisableSandbox: true`.

---

## File Structure

- **Create** `includes/class-ole-delivery-notice.php` — `OLE_Delivery_Notice`: pure `vacation_active()` helper, `defaults_copy()`, `payload()`, `enqueue()`, `init()`.
- **Create** `assets/js/ole-delivery-notice.js` — DOM injector (consumes `window.OLE_DELIVERY`).
- **Create** `assets/css/ole-delivery-notice.css` — amber static block + red vacation banner.
- **Create** `tests/delivery-notice/test-vacation-active.php` — standalone unit test for `vacation_active()`.
- **Modify** `includes/class-ole-settings.php` — 6 new defaults + sanitization in `get()`.
- **Modify** `includes/class-ole-settings-page.php` — new settings section + save handling.
- **Modify** `includes/class-ole-plugin.php` — init the module when enabled.
- **Modify** `order-list-enhancer.php` — require the new class; bump version.
- **Modify** `languages/order-list-enhancer-bg_BG.po` (+ recompile `.mo`) — Bulgarian for new strings.

---

### Task 1: Pure `vacation_active()` helper + unit test

**Files:**
- Create: `includes/class-ole-delivery-notice.php`
- Test: `tests/delivery-notice/test-vacation-active.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `OLE_Delivery_Notice::vacation_active( string $until, string $today ) : bool` — `true` iff `$until` is a real calendar date in `YYYY-MM-DD` form and `$today <= $until` (string compare of ISO dates). Pure, no WordPress.

- [ ] **Step 1: Write the failing test**

Create `tests/delivery-notice/test-vacation-active.php`:

```php
<?php
// Standalone unit tests for OLE_Delivery_Notice::vacation_active (no WordPress).
define( 'ABSPATH', true );
require __DIR__ . '/../../includes/class-ole-delivery-notice.php';

$fails = 0;
function ck( $cond, $msg ) { global $fails; echo ( $cond ? "ok   - " : "FAIL - " ) . "$msg\n"; if ( ! $cond ) { $fails++; } }
function va( $until, $today ) { return OLE_Delivery_Notice::vacation_active( $until, $today ); }

ck( va( '', '2026-06-25' ) === false, "empty until -> false" );
ck( va( 'soon', '2026-06-25' ) === false, "non-date until -> false" );
ck( va( '2026-13-40', '2026-06-25' ) === false, "calendar-invalid until -> false" );
ck( va( '2026-06-24', '2026-06-25' ) === false, "yesterday -> false (expired)" );
ck( va( '2026-06-25', '2026-06-25' ) === true, "today -> true" );
ck( va( '2026-06-30', '2026-06-25' ) === true, "future -> true" );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/delivery-notice/test-vacation-active.php`
Expected: FAIL — fatal "Class 'OLE_Delivery_Notice' not found" (the include file doesn't exist yet).

- [ ] **Step 3: Write minimal implementation**

Create `includes/class-ole-delivery-notice.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Підсвітка поля дати доставки (плагін orddd) на чекауті: акцентний блок над полем
 * + опційний банер відпустки. Тільки презентація — нічого не пишемо в замовлення.
 * Тихо нічого не робить, якщо поля orddd на сторінці немає.
 */
class OLE_Delivery_Notice {

	/**
	 * Чи активний банер відпустки на дату $today.
	 * Pure, без WordPress.
	 *
	 * @param string $until Дата закінчення відпустки у форматі YYYY-MM-DD (або порожньо).
	 * @param string $today Поточна дата у форматі YYYY-MM-DD.
	 * @return bool true, якщо $until — реальна дата і $today <= $until.
	 */
	public static function vacation_active( $until, $today ) {
		$until = trim( (string) $until );
		$d     = DateTime::createFromFormat( 'Y-m-d', $until );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $until ) {
			return false;
		}
		return strcmp( (string) $today, $until ) <= 0;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php tests/delivery-notice/test-vacation-active.php`
Expected: `ALL PASS` (exit 0), 6 `ok` lines.

- [ ] **Step 5: Commit**

```bash
git add includes/class-ole-delivery-notice.php tests/delivery-notice/test-vacation-active.php
git commit -m "feat: OLE_Delivery_Notice::vacation_active() pure helper + test"
```

---

### Task 2: Settings defaults + sanitization

**Files:**
- Modify: `includes/class-ole-settings.php` (`defaults()` ~line 38, `get()` ~line 116)

**Interfaces:**
- Consumes: nothing.
- Produces: settings keys `delivery_notice_enabled` (`yes|no`), `delivery_notice_title` (string), `delivery_notice_body` (string), `delivery_vacation_enabled` (`yes|no`), `delivery_vacation_until` (`''` or `YYYY-MM-DD`), `delivery_vacation_text` (string) — readable via `OLE_Settings::get()`.

- [ ] **Step 1: Add defaults**

In `includes/class-ole-settings.php`, in `defaults()`, after the line `'seq_open_interval'   => 20,` (last entry before the closing `);`), add:

```php
			'delivery_notice_enabled'   => 'no',  // highlight the orddd delivery-date field at checkout
			'delivery_notice_title'     => '',     // empty → translatable default
			'delivery_notice_body'      => '',     // empty → translatable default
			'delivery_vacation_enabled' => 'no',  // show the "we are away" banner
			'delivery_vacation_until'   => '',     // YYYY-MM-DD; empty/past → banner hidden
			'delivery_vacation_text'    => '',     // empty → translatable default; supports one %s (date)
```

- [ ] **Step 2: Add sanitization**

In `get()`, immediately after the line `$opts['seq_open_interval'] = max( 1, min( 300, (int) $opts['seq_open_interval'] ) );`, add:

```php
		$opts['delivery_notice_title']  = sanitize_text_field( (string) $opts['delivery_notice_title'] );
		$opts['delivery_notice_body']   = sanitize_textarea_field( (string) $opts['delivery_notice_body'] );
		$opts['delivery_vacation_text'] = sanitize_textarea_field( (string) $opts['delivery_vacation_text'] );
		$opts['delivery_vacation_until'] = ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $opts['delivery_vacation_until'] ) )
			? (string) $opts['delivery_vacation_until']
			: '';
```

- [ ] **Step 3: Lint**

Run: `php -l includes/class-ole-settings.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-ole-settings.php
git commit -m "feat: delivery-notice settings defaults + sanitization"
```

---

### Task 3: `OLE_Delivery_Notice` payload/enqueue/init + wiring + version bump

**Files:**
- Modify: `includes/class-ole-delivery-notice.php` (add methods to the class from Task 1)
- Modify: `includes/class-ole-plugin.php` (`__construct()` ~line 40-42)
- Modify: `order-list-enhancer.php` (require ~line 41; version line 6 + const line 26)

**Interfaces:**
- Consumes: `OLE_Settings::get()`, `OLE_Delivery_Notice::vacation_active()`.
- Produces: the localized JS object **`window.OLE_DELIVERY`** = `{ title: string, body: string, vacation: null | { text: string } }`. `vacation` is non-null only when the banner is enabled AND its date is today/future. Task 4 (JS) consumes exactly this shape.

- [ ] **Step 1: Add the methods to `OLE_Delivery_Notice`**

In `includes/class-ole-delivery-notice.php`, add these methods inside the class (after `vacation_active()`):

```php
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/** Перекладні дефолти текстів (фолбек, коли налаштування порожні). */
	public static function defaults_copy() {
		return array(
			'title'    => __( '📦 This is the SHIPPING date', 'order-list-enhancer' ),
			'body'     => __( 'Not the date you receive it. Delivery to the courier office usually takes about 1 working day.', 'order-list-enhancer' ),
			/* translators: %s: vacation end date. */
			'vacation' => __( '🌴 We are on vacation until %s. Orders placed now will be shipped after that date.', 'order-list-enhancer' ),
		);
	}

	/** Дані для фронтенду: тексти + (опційно) банер відпустки. */
	public static function payload() {
		$o   = OLE_Settings::get();
		$def = self::defaults_copy();

		$title = '' !== trim( (string) $o['delivery_notice_title'] ) ? (string) $o['delivery_notice_title'] : $def['title'];
		$body  = '' !== trim( (string) $o['delivery_notice_body'] ) ? (string) $o['delivery_notice_body'] : $def['body'];

		$vacation = null;
		if ( OLE_Settings::is_yes( $o, 'delivery_vacation_enabled' )
			&& self::vacation_active( (string) $o['delivery_vacation_until'], current_time( 'Y-m-d' ) ) ) {
			$tpl  = '' !== trim( (string) $o['delivery_vacation_text'] ) ? (string) $o['delivery_vacation_text'] : $def['vacation'];
			$date = date_i18n( get_option( 'date_format' ), strtotime( (string) $o['delivery_vacation_until'] . ' 12:00:00' ) );
			// str_replace (not sprintf) so stray % in the owner's text can't break it.
			$vacation = array( 'text' => str_replace( '%s', $date, $tpl ) );
		}

		return array(
			'title'    => $title,
			'body'     => $body,
			'vacation' => $vacation,
		);
	}

	public static function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_style( 'ole-delivery-notice', OLE_URL . 'assets/css/ole-delivery-notice.css', array(), OLE_VERSION );
		wp_enqueue_script( 'ole-delivery-notice', OLE_URL . 'assets/js/ole-delivery-notice.js', array(), OLE_VERSION, true );
		wp_localize_script( 'ole-delivery-notice', 'OLE_DELIVERY', self::payload() );
	}
```

- [ ] **Step 2: Require the class**

In `order-list-enhancer.php`, after the line `require_once OLE_DIR . 'includes/class-ole-phone-checkout.php';`, add:

```php
require_once OLE_DIR . 'includes/class-ole-delivery-notice.php';
```

- [ ] **Step 3: Init when enabled**

In `includes/class-ole-plugin.php`, in `__construct()`, after the existing block:

```php
		if ( OLE_Settings::is_yes( $opts, 'phone_validate_enabled' ) ) {
			OLE_Phone_Checkout::init();
		}
```

add:

```php
		if ( OLE_Settings::is_yes( $opts, 'delivery_notice_enabled' ) ) {
			OLE_Delivery_Notice::init();
		}
```

- [ ] **Step 4: Bump version**

In `order-list-enhancer.php`: change the header ` * Version:           1.0.28` to `1.0.29`, and `define( 'OLE_VERSION', '1.0.28' );` to `'1.0.29'`.

- [ ] **Step 5: Lint + structural check**

Run:
```bash
php -l includes/class-ole-delivery-notice.php && php -l includes/class-ole-plugin.php && php -l order-list-enhancer.php
php -r 'define("ABSPATH",true); require "includes/class-ole-delivery-notice.php"; foreach(["init","payload","enqueue","vacation_active","defaults_copy"] as $m){ echo method_exists("OLE_Delivery_Notice",$m)?"ok $m\n":"MISSING $m\n"; }'
```
Expected: three `No syntax errors detected`; five `ok` lines (no `MISSING`).

- [ ] **Step 6: Re-run Task 1 unit test (guard against regressions)**

Run: `php tests/delivery-notice/test-vacation-active.php`
Expected: `ALL PASS`.

- [ ] **Step 7: Commit**

```bash
git add includes/class-ole-delivery-notice.php includes/class-ole-plugin.php order-list-enhancer.php
git commit -m "feat: delivery-notice payload/enqueue + init wiring; bump 1.0.29"
```

---

### Task 4: Frontend JS injector

**Files:**
- Create: `assets/js/ole-delivery-notice.js`

**Interfaces:**
- Consumes: `window.OLE_DELIVERY` = `{ title, body, vacation: null | { text } }` (from Task 3).
- Produces: DOM only — prepends `.ole-deliv` blocks into each orddd `.form-row` wrapper.

- [ ] **Step 1: Write the script**

Create `assets/js/ole-delivery-notice.js`:

```js
( function () {
	if ( typeof window === 'undefined' || typeof document === 'undefined' ) { return; }
	var D = window.OLE_DELIVERY || null;
	if ( ! D ) { return; }

	// Будуємо акцентний блок (іконка вже всередині тексту title/body).
	function buildBlock( cls, title, body ) {
		var box = document.createElement( 'div' );
		box.className = 'ole-deliv ' + cls;
		if ( title ) {
			var h = document.createElement( 'div' );
			h.className = 'ole-deliv-title';
			h.textContent = title;
			box.appendChild( h );
		}
		if ( body ) {
			var p = document.createElement( 'div' );
			p.className = 'ole-deliv-body';
			p.textContent = body;
			box.appendChild( p );
		}
		return box;
	}

	function wrapperOf( input ) {
		return ( input.closest && input.closest( '.form-row' ) ) || input.parentNode;
	}

	function decorate() {
		var inputs = document.querySelectorAll( 'input[id^="e_deliverydate_"]' );
		for ( var i = 0; i < inputs.length; i++ ) {
			var wrap = wrapperOf( inputs[ i ] );
			if ( ! wrap || wrap.getAttribute( 'data-ole-deliv' ) ) { continue; }
			wrap.setAttribute( 'data-ole-deliv', '1' );
			var frag = document.createDocumentFragment();
			if ( D.vacation && D.vacation.text ) {
				// Банер відпустки — над статичним блоком; весь текст в body-рядку.
				frag.appendChild( buildBlock( 'ole-deliv-vacation', '', D.vacation.text ) );
			}
			frag.appendChild( buildBlock( 'ole-deliv-ship', D.title || '', D.body || '' ) );
			wrap.insertBefore( frag, wrap.firstChild );
		}
	}

	decorate();
	// Чекаут перерендерюється на оновленнях — ребайнд через подію WooCommerce.
	if ( window.jQuery ) { window.jQuery( document.body ).on( 'updated_checkout', decorate ); }
} )();
```

- [ ] **Step 2: Syntax check**

Run: `node --check assets/js/ole-delivery-notice.js`
Expected: no output, exit 0.

- [ ] **Step 3: Commit**

```bash
git add assets/js/ole-delivery-notice.js
git commit -m "feat: delivery-notice checkout JS injector"
```

---

### Task 5: CSS (amber block + red banner)

**Files:**
- Create: `assets/css/ole-delivery-notice.css`

**Interfaces:**
- Consumes: the class names emitted by Task 4 (`.ole-deliv`, `.ole-deliv-ship`, `.ole-deliv-vacation`, `.ole-deliv-title`, `.ole-deliv-body`).
- Produces: nothing consumed downstream.

- [ ] **Step 1: Write the stylesheet**

Create `assets/css/ole-delivery-notice.css`:

```css
/* Order List Enhancer — checkout delivery-date notice (orddd field) */
.ole-deliv {
	box-sizing: border-box;
	width: 100%;
	margin: 0 0 .75em;
	padding: .7em .9em;
	border: 1px solid transparent;
	border-radius: 6px;
	line-height: 1.45;
}
.ole-deliv-title {
	font-weight: 700;
	font-size: 1.02em;
	margin-bottom: .15em;
}
.ole-deliv-body {
	font-size: .95em;
}
/* Статичний блок — янтарний «увага». */
.ole-deliv-ship {
	background: #fff8e6;
	border-color: #e6c98a;
	border-left: 4px solid #b26a00;
	color: #5a4300;
}
/* Банер відпустки — тепло-червоний, помітніший, над статичним блоком. */
.ole-deliv-vacation {
	background: #fdecea;
	border-color: #f0b3ac;
	border-left: 4px solid #c0392b;
	color: #7a241b;
	font-weight: 600;
}
```

- [ ] **Step 2: Verify the file exists and is non-empty**

Run: `test -s assets/css/ole-delivery-notice.css && echo OK`
Expected: `OK`. (Visual confirmation happens in Task 7 on the live checkout.)

- [ ] **Step 3: Commit**

```bash
git add assets/css/ole-delivery-notice.css
git commit -m "feat: delivery-notice checkout styles"
```

---

### Task 6: Settings-page UI section + save

**Files:**
- Modify: `includes/class-ole-settings-page.php` (`render()` — insert after the "Checkout phone validation" `</table>` ~line 351; `ajax_save()` `$opts` array ~line 451)

**Interfaces:**
- Consumes: settings keys from Task 2; `$o` (resolved settings) and `$cb` (checkbox helper) already in scope in `render()`.
- Produces: form fields named `delivery_notice_enabled`, `delivery_notice_title`, `delivery_notice_body`, `delivery_vacation_enabled`, `delivery_vacation_until`, `delivery_vacation_text`, persisted by `ajax_save()`.

- [ ] **Step 1: Add the settings section to `render()`**

In `includes/class-ole-settings-page.php`, immediately after the closing `</tbody></table>` of the "Checkout phone validation" section (right before `<h2><?php esc_html_e( 'Phone numbers', …`), insert:

```php
				<h2><?php esc_html_e( 'Delivery-date notice (checkout)', 'order-list-enhancer' ); ?></h2>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Show notice', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="delivery_notice_enabled" <?php echo $cb( 'delivery_notice_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'When the delivery-date field (Order Delivery Date plugin) is on the checkout, show a highlighted note above it. Does nothing if that field is absent.', 'order-list-enhancer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Notice title', 'order-list-enhancer' ); ?></th>
						<td><input type="text" name="delivery_notice_title" value="<?php echo esc_attr( $o['delivery_notice_title'] ); ?>" class="regular-text" style="width:100%;max-width:680px"/>
						<p class="description"><?php esc_html_e( 'Bold first line. Leave empty for the default.', 'order-list-enhancer' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Notice text', 'order-list-enhancer' ); ?></th>
						<td><textarea name="delivery_notice_body" rows="2" class="large-text" style="max-width:680px"><?php echo esc_textarea( $o['delivery_notice_body'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Explanation under the title. Leave empty for the default.', 'order-list-enhancer' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Vacation banner', 'order-list-enhancer' ); ?></th>
						<td><label><input type="checkbox" name="delivery_vacation_enabled" <?php echo $cb( 'delivery_vacation_enabled' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>/> <?php esc_html_e( 'Also show a red “we are away” banner above the notice, until the date below.', 'order-list-enhancer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Away until', 'order-list-enhancer' ); ?></th>
						<td><input type="date" name="delivery_vacation_until" value="<?php echo esc_attr( $o['delivery_vacation_until'] ); ?>"/>
						<p class="description"><?php esc_html_e( 'The banner hides automatically after this date.', 'order-list-enhancer' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Vacation text', 'order-list-enhancer' ); ?></th>
						<td><textarea name="delivery_vacation_text" rows="2" class="large-text" style="max-width:680px"><?php echo esc_textarea( $o['delivery_vacation_text'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Use %s where the date should appear. Leave empty for the default.', 'order-list-enhancer' ); ?></p></td>
					</tr>
				</tbody></table>

```

- [ ] **Step 2: Persist the fields in `ajax_save()`**

In `ajax_save()`, in the `$opts = array( … )` literal, after the line `'seq_open_interval'      => isset( $in['seq_open_interval'] ) ? max( 1, min( 300, (int) $in['seq_open_interval'] ) ) : 20,`, add:

```php
				'delivery_notice_enabled'   => $bool( 'delivery_notice_enabled' ),
				'delivery_notice_title'     => isset( $in['delivery_notice_title'] ) ? sanitize_text_field( (string) $in['delivery_notice_title'] ) : '',
				'delivery_notice_body'      => isset( $in['delivery_notice_body'] ) ? sanitize_textarea_field( (string) $in['delivery_notice_body'] ) : '',
				'delivery_vacation_enabled' => $bool( 'delivery_vacation_enabled' ),
				'delivery_vacation_until'   => ( isset( $in['delivery_vacation_until'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $in['delivery_vacation_until'] ) ) ? (string) $in['delivery_vacation_until'] : '',
				'delivery_vacation_text'    => isset( $in['delivery_vacation_text'] ) ? sanitize_textarea_field( (string) $in['delivery_vacation_text'] ) : '',
```

- [ ] **Step 3: Lint**

Run: `php -l includes/class-ole-settings-page.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-ole-settings-page.php
git commit -m "feat: delivery-notice settings UI + save"
```

---

### Task 7: Bulgarian translations + ship & verify

**Files:**
- Modify: `languages/order-list-enhancer-bg_BG.po` (append entries) → recompile `.mo`

**Interfaces:**
- Consumes: every `__()`/`esc_html_e()` source string added in Tasks 3 and 6.
- Produces: Bulgarian `.mo` so the admin section + customer copy render in Bulgarian on the bg_BG site.

- [ ] **Step 1: Append the translation entries**

Append to `languages/order-list-enhancer-bg_BG.po`:

```po
#. translators: %s: vacation end date.
#: includes/class-ole-delivery-notice.php
#, php-format
msgid "🌴 We are on vacation until %s. Orders placed now will be shipped after that date."
msgstr "🌴 В момента сме в отпуск до %s. Поръчките, направени сега, ще бъдат изпратени след тази дата."

#: includes/class-ole-delivery-notice.php
msgid "📦 This is the SHIPPING date"
msgstr "📦 Това е датата на ИЗПРАЩАНЕ"

#: includes/class-ole-delivery-notice.php
msgid "Not the date you receive it. Delivery to the courier office usually takes about 1 working day."
msgstr "Не е датата на получаване. Обикновено доставката до офис на куриера отнема около 1 работен ден."

#: includes/class-ole-settings-page.php
msgid "Delivery-date notice (checkout)"
msgstr "Бележка за датата на доставка (каса)"

#: includes/class-ole-settings-page.php
msgid "Show notice"
msgstr "Показвай бележката"

#: includes/class-ole-settings-page.php
msgid "When the delivery-date field (Order Delivery Date plugin) is on the checkout, show a highlighted note above it. Does nothing if that field is absent."
msgstr "Когато на касата има поле за дата на доставка (плъгин Order Delivery Date), показва подчертана бележка над него. Не прави нищо, ако полето липсва."

#: includes/class-ole-settings-page.php
msgid "Notice title"
msgstr "Заглавие на бележката"

#: includes/class-ole-settings-page.php
msgid "Bold first line. Leave empty for the default."
msgstr "Удебелен първи ред. Оставете празно за стойността по подразбиране."

#: includes/class-ole-settings-page.php
msgid "Notice text"
msgstr "Текст на бележката"

#: includes/class-ole-settings-page.php
msgid "Explanation under the title. Leave empty for the default."
msgstr "Пояснение под заглавието. Оставете празно за стойността по подразбиране."

#: includes/class-ole-settings-page.php
msgid "Vacation banner"
msgstr "Банер за отпуск"

#: includes/class-ole-settings-page.php
msgid "Also show a red “we are away” banner above the notice, until the date below."
msgstr "Показвай и червен банер „в отпуск сме“ над бележката, до посочената по-долу дата."

#: includes/class-ole-settings-page.php
msgid "Away until"
msgstr "Отсъстваме до"

#: includes/class-ole-settings-page.php
msgid "The banner hides automatically after this date."
msgstr "Банерът се скрива автоматично след тази дата."

#: includes/class-ole-settings-page.php
msgid "Vacation text"
msgstr "Текст за отпуск"

#: includes/class-ole-settings-page.php
msgid "Use %s where the date should appear. Leave empty for the default."
msgstr "Използвайте %s там, където трябва да се покаже датата. Оставете празно за стойността по подразбиране."
```

- [ ] **Step 2: Recompile the `.mo`**

Run:
```bash
cd languages && msgfmt order-list-enhancer-bg_BG.po -o order-list-enhancer-bg_BG.mo && cd ..
```
Expected: no errors (msgfmt prints nothing on success).

- [ ] **Step 3: Full local verification**

Run:
```bash
php tests/delivery-notice/test-vacation-active.php
php -l includes/class-ole-delivery-notice.php
php -l includes/class-ole-settings.php
php -l includes/class-ole-settings-page.php
php -l includes/class-ole-plugin.php
php -l order-list-enhancer.php
node --check assets/js/ole-delivery-notice.js
```
Expected: `ALL PASS`; six `No syntax errors detected`; node exit 0.

- [ ] **Step 4: Commit**

```bash
git add languages/order-list-enhancer-bg_BG.po languages/order-list-enhancer-bg_BG.mo
git commit -m "i18n: Bulgarian for delivery-date notice"
```

- [ ] **Step 5: Deploy to dobavki.club** (per the OLE deploy workflow — Bash with `dangerouslyDisableSandbox: true`)

```bash
TS=$(date +%Y%m%d-%H%M%S)
H="root@31.131.26.210"; PLUG="/home/dobavki/public_html/wp-content/plugins/order-list-enhancer"
ssh -p 6676 $H "cp -a $PLUG /home/dobavki/ole-deploy-backup-$TS"
rsync -az --no-perms --no-owner --no-group --exclude='.git*' --exclude='.wordpress-org' -e "ssh -p 6676" ./ $H:$PLUG/
ssh -p 6676 $H "PHP=/opt/alt/php-fpm83/usr/bin/php; WP=\"\$PHP /usr/local/bin/wp --allow-root\"; cd /home/dobavki/public_html; \$WP eval 'opcache_reset();'; \$WP cache flush; \$WP transient delete --all; rm -rf wp-content/cache/asset-cleanup/* wp-content/cache/wp-rocket/*; grep -c OLE_DELIVERY wp-content/plugins/order-list-enhancer/includes/class-ole-delivery-notice.php"
```
Expected: rsync completes; final `grep -c` prints `1` (live file updated).

- [ ] **Step 6: Server payload smoke**

```bash
ssh -p 6676 root@31.131.26.210 "PHP=/opt/alt/php-fpm83/usr/bin/php; WP=\"\$PHP /usr/local/bin/wp --allow-root\"; cd /home/dobavki/public_html; \$WP eval 'var_export( OLE_Delivery_Notice::payload() );'"
```
Expected: an array with non-empty `title`/`body`; `vacation` is `NULL` until the banner is enabled with a future date.

- [ ] **Step 7: Enable + visually verify on the live checkout**

Turn the feature on (WooCommerce → Order List Enhancer → "Delivery-date notice (checkout)" → Show notice ✓, Save), then open `https://dobavki.club/order/` (or the checkout) and confirm:
- the amber block sits **above** the delivery-date field, in Bulgarian;
- toggling the vacation banner with a future "Away until" date shows the red banner above it; a past date hides it;
- with the feature off (or the orddd field absent) nothing renders.

Capture a screenshot for the record.

- [ ] **Step 8: Final commit (if any docs/readme bump)**

```bash
git add -A && git commit -m "docs: delivery-date notice shipped (1.0.29)" || echo "nothing to commit"
```

---

## Self-Review

**Spec coverage:**
- JS detect-and-inject on checkout, no-op when absent → Tasks 3 (enqueue) + 4 (JS). ✓
- Accent block above field; amber static + red vacation banner above it → Tasks 4 + 5. ✓
- Full settings (enable, title, body, vacation enable/date/text) → Tasks 2 + 6. ✓
- Vacation auto-expiry, server-side date format → Task 1 (`vacation_active`) + Task 3 (`payload` with `date_i18n`). ✓
- Bulgarian default copy, English source + `.po` → Tasks 3 + 7. ✓
- XSS safety (`textContent`, escaped settings) → Task 4 (textContent) + Tasks 2/6 (sanitize). ✓
- Version bump + deploy/opcache → Tasks 3 + 7. ✓
- Unit test for the pure helper → Task 1. ✓

**Placeholder scan:** No TBD/TODO; every code step shows complete code; commands have expected output. ✓

**Type consistency:** `OLE_DELIVERY` shape `{ title, body, vacation: null | { text } }` is defined in Task 3's Interfaces and consumed verbatim in Task 4. `vacation_active( $until, $today )` signature is identical in Tasks 1 and 3. Settings keys match across Tasks 2, 3, and 6. CSS class names (`ole-deliv`, `ole-deliv-ship`, `ole-deliv-vacation`, `ole-deliv-title`, `ole-deliv-body`) match between Tasks 4 and 5. ✓
