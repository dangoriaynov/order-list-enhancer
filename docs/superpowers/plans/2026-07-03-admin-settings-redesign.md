# Admin Settings Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the single-column, wall-of-text OLE settings page into a tabbed (4 categories), card-based layout with consistent on/off switches, progressive disclosure, and tightened copy — with zero change to which settings exist or how they save.

**Architecture:** One `<form>` (unchanged AJAX whole-form save) wrapping a two-column shell: a left ARIA tablist of 4 categories and one right panel per category. `OLE_Settings_Page::render()` is split into per-category methods that emit feature "cards" via small private helpers. A new `assets/css/ole-settings.css` supplies the visual system; `assets/js/ole-settings.js` gains tab switching (hash-synced), a no-JS fallback, progressive-disclosure toggling, and tooltips. Every input keeps its exact current `name=`.

**Tech Stack:** PHP 7.4+, WordPress/WooCommerce admin, vanilla JS + jQuery (already enqueued), CSS. No build step. gettext for bg_BG.

## Global Constraints

- **Behavior-preservation invariant (the safety rule):** every input's `name=` in the new markup must match a key read in `OLE_Settings_Page::ajax_save()`, and no key read in `ajax_save()` may lose its input. `ajax_save()`, `menu()`, `OLE_Settings::defaults()`, and all option keys are **unchanged**. This is the primary review check.
- Text domain `order-list-enhancer` on every string; new/changed copy + tab labels translated to `bg_BG` and the `.mo` rebuilt.
- Presentational + copy only. No new settings, no removed/renamed keys, no default/sanitization changes, no front-end/behavior changes.
- 4 categories with these hash ids and exact feature membership: **Orders** (`#orders`): repeat-customer highlighting, shipping coloring, order-total coloring, order-total-on-edit, default bulk action, open-one-by-one. **Checkout** (`#checkout`): phone validation, duplicate-order guard, delivery-date notice, extras→products. **Inventory** (`#inventory`): print consumables. **Phone** (`#phone`): phone normalization + country code.
- No-JS fallback: panels are visible by default; JS applies `hidden` to inactive panels on init. Never render a blank page when JS is off.
- No local WordPress: verify with `php -l`, `node --check`, `msgfmt`, and a static render↔save `name=` cross-map. Visual verification is live, post-deploy.
- Assets enqueue only on the settings screen (existing `is_settings_screen()` guard). Bump `OLE_VERSION` (1.0.32 → 1.0.33) for cache-bust.
- Local tooling: `/opt/homebrew/bin/php`, `node`, `/opt/homebrew/bin/msgfmt`. `cd` into the repo first in every shell call.

---

## File Structure

- **`includes/class-ole-settings-page.php`** (modify) — `render()` becomes the tab shell; new private helpers `tab_nav()`, `card_open()`, `card_close()`, `switch_html()`, `help_html()`; four methods `render_tab_orders/checkout/inventory/phone()`. `assets()` also enqueues the new CSS. `ajax_save()` untouched.
- **`assets/css/ole-settings.css`** (create) — tab shell, tablist states, card/switch/tooltip, progressive-disclosure, sticky save bar. Replaces the inline `<style>`.
- **`assets/js/ole-settings.js`** (modify) — tab switching + hash + no-JS `hidden` init; disclosure toggle on switch change; tooltip open/close. Existing save/serialize + color-picker/rule-row logic untouched.
- **`languages/order-list-enhancer-bg_BG.po` / `.mo`** (modify) — bg_BG for new tab labels + shortened copy + tooltip text.
- **`order-list-enhancer.php`** (modify) — bump `OLE_VERSION`.

Reference — current section→tab mapping and the enable-switch field name(s) per feature (from the current `render()` and `ajax_save()`):

| Feature (card) | Tab | Header switch `name` (disclosure) | Extra switches in body | Control-only fields |
|---|---|---|---|---|
| Repeat-customer highlighting | orders | `dup_enabled` | — | `match_mode`, `scan_limit`, `dup_window_days` |
| Shipping coloring | orders | — (multi-toggle, no disclosure) | `ship_enabled`, `ship_color_edit` | rule table `rule_*[]`, `ship_default_color`, `ship_default_label` |
| Order-total coloring | orders | `total_color_enabled` | — | rule table `total_*[]` |
| Order total on edit | orders | `total_on_edit` | `copy_buttons` | `total_decimal_sep` |
| Default bulk action | orders | — (control-only) | — | `bulk_default_action` |
| Open one-by-one | orders | `seq_open_enabled` | — | `seq_open_interval` |
| Checkout phone validation | checkout | `phone_validate_enabled` | — | `phone_validate_mode` |
| Duplicate-order guard | checkout | `dup_guard_enabled` | — | `dup_guard_mode`, `dup_guard_window_min` |
| Delivery-date notice | checkout | `delivery_notice_enabled` | `delivery_vacation_enabled` | `delivery_notice_title/body`, `delivery_vacation_until/text` |
| Extras → products | checkout | `extras_enabled` | — | mapping `extra_match[]`, `extra_product[]` |
| Print consumables | inventory | `print_stock_enabled` | — | `print_stock_threshold_sticker/instruction`, stock-page link |
| Phone normalization | phone | `normalize_phone` | — | `phone_cc` |

---

## Task 1: Foundation — tab shell, helpers, CSS/JS, verbatim section move

Deliverable: the page renders as 4 working tabs; every existing field is present with its exact `name=`; save works; no cards yet (sections moved verbatim into the panels). This isolates the risky structural move behind the invariant check before any restyling.

**Files:**
- Modify: `includes/class-ole-settings-page.php`
- Create: `assets/css/ole-settings.css`
- Modify: `assets/js/ole-settings.js`

**Interfaces:**
- Produces (private static, used by Tasks 2–4): `card_open( string $title, string $help = '', $switch = null ) : void` (`$switch` = `null` or `[ 'name' => string, 'checked' => bool ]`; when set, it renders the header on/off switch AND puts `data-switch="<name>"` on the card so the JS drives progressive disclosure), `card_close() : void`, `switch_html( string $name, bool $checked, string $label = '' ) : string`, `help_html( string $text ) : string`, `tab_nav( array $tabs ) : void`.
- Produces: `render_tab_orders() / render_tab_checkout() / render_tab_inventory() / render_tab_phone() : void` (Task 1 fills them with the moved-verbatim markup; Tasks 2–4 convert their contents to cards).

- [ ] **Step 1: Create the CSS**

Create `assets/css/ole-settings.css`:

```css
/* ---- Tab shell ---- */
.ole-settings-shell { display: flex; gap: 20px; align-items: flex-start; margin-top: 12px; }
.ole-tabnav { flex: 0 0 200px; margin: 0; position: sticky; top: 40px; }
.ole-tabnav li { margin: 0; }
.ole-tabnav a {
	display: block; padding: 10px 14px; text-decoration: none; color: #1d2327;
	border-left: 3px solid transparent; border-radius: 4px; font-weight: 600;
}
.ole-tabnav a:hover { background: #f0f0f1; }
.ole-tabnav a.is-active { background: #f0f6fc; border-left-color: #2271b1; color: #0a4b78; }
.ole-tabpanels { flex: 1 1 auto; min-width: 0; }
.ole-tabpanel { }

/* ---- Card ---- */
.ole-card {
	background: #fff; border: 1px solid #dcdcde; border-radius: 8px;
	padding: 16px 18px; margin: 0 0 16px; box-shadow: 0 1px 1px rgba(0,0,0,.03);
}
.ole-card-head { display: flex; align-items: center; gap: 8px; }
.ole-card-title { font-size: 14px; font-weight: 600; margin: 0; flex: 1 1 auto; }
.ole-card-body { margin-top: 12px; }
.ole-card-body .form-table { margin-top: 0; }
.ole-card-body .form-table th { padding-left: 0; }
/* Progressive disclosure: dim + hide body when the header switch is off */
.ole-card.ole-off .ole-card-body { display: none; }
.ole-card.ole-off .ole-card-title { color: #646970; }

/* ---- On/off switch (wraps a real checkbox) ---- */
.ole-switch { position: relative; display: inline-flex; align-items: center; cursor: pointer; }
.ole-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
.ole-switch .ole-slider {
	width: 40px; height: 22px; background: #c3c4c7; border-radius: 22px; transition: background .15s; position: relative;
}
.ole-switch .ole-slider::before {
	content: ""; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px;
	background: #fff; border-radius: 50%; transition: transform .15s;
}
.ole-switch input:checked + .ole-slider { background: #2271b1; }
.ole-switch input:checked + .ole-slider::before { transform: translateX(18px); }
.ole-switch input:focus + .ole-slider { box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2271b1; }

/* ---- Help tooltip ---- */
.ole-help {
	display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px;
	border-radius: 50%; background: #dcdcde; color: #1d2327; font-size: 12px; font-weight: 700;
	cursor: help; user-select: none; text-decoration: none;
}
.ole-help:focus { outline: 2px solid #2271b1; }

/* ---- Sticky save bar ---- */
.ole-savebar {
	position: sticky; bottom: 0; background: #fff; border-top: 1px solid #dcdcde;
	padding: 12px 0; margin-top: 8px; display: flex; align-items: center; gap: 12px;
}

/* Keep rule/mapping tables full width inside cards (migrated from the old inline <style>). */
#ole-settings-form .form-table { width: 100%; }
#ole-settings-form .ole-rules,
#ole-settings-form .ole-extras { width: 100%; max-width: 100% !important; }
#ole-settings-form .ole-extras td input[type=text],
#ole-settings-form .ole-extras td .wc-product-search,
#ole-settings-form .ole-extras td .select2-container { width: 100% !important; }

@media screen and (max-width: 782px) {
	.ole-settings-shell { flex-direction: column; }
	.ole-tabnav { position: static; flex-basis: auto; display: flex; flex-wrap: wrap; gap: 6px; }
}
```

- [ ] **Step 2: Add the tab + disclosure + tooltip JS**

In `assets/js/ole-settings.js`, append (do NOT modify existing save/color-picker/rule-row code):

```js
( function ( $ ) {
	'use strict';

	function initTabs() {
		var $nav = $( '.ole-tabnav a' );
		var $panels = $( '.ole-tabpanel' );
		if ( ! $nav.length ) { return; }

		function activate( id ) {
			var found = false;
			$panels.each( function () {
				var match = ( '#' + this.id ) === id;
				this.hidden = ! match;
				found = found || match;
			} );
			if ( ! found ) { // invalid hash → first panel
				$panels.each( function ( i ) { this.hidden = i !== 0; } );
				id = '#' + $panels.get( 0 ).id;
			}
			$nav.removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
			$nav.filter( '[href="' + id + '"]' ).addClass( 'is-active' ).attr( 'aria-selected', 'true' );
		}

		$nav.on( 'click', function ( e ) {
			e.preventDefault();
			var id = $( this ).attr( 'href' );
			if ( window.history && history.replaceState ) { history.replaceState( null, '', id ); }
			else { window.location.hash = id; }
			activate( id );
		} );

		activate( window.location.hash || ( '#' + $panels.get( 0 ).id ) );
	}

	function initDisclosure() {
		$( '.ole-card[data-switch]' ).each( function () {
			var $card = $( this );
			var name = $card.data( 'switch' );
			var $cb = $card.find( 'input[name="' + name + '"]' ).first();
			function sync() { $card.toggleClass( 'ole-off', ! $cb.prop( 'checked' ) ); }
			$cb.on( 'change', sync );
			sync();
		} );
	}

	function initHelp() {
		$( '.ole-help' ).on( 'click', function ( e ) { e.preventDefault(); } );
	}

	$( function () { initTabs(); initDisclosure(); initHelp(); } );
} )( jQuery );
```

(Tooltip detail uses the native `title` attribute set in PHP; `initHelp` only stops the `#` link from jumping. No extra tooltip library.)

- [ ] **Step 3: Enqueue the CSS**

In `includes/class-ole-settings-page.php`, in `assets()`, after the `wp_enqueue_style( 'wp-color-picker' );` line, add:

```php
		wp_enqueue_style( 'ole-settings', OLE_URL . 'assets/css/ole-settings.css', array(), OLE_VERSION );
```

- [ ] **Step 4: Add the private helpers**

In `includes/class-ole-settings-page.php`, add these methods to the class (place them just above `render()`):

```php
	/** Ліва навігація вкладок. $tabs = [ ['id'=>'orders','label'=>'…'], … ]. */
	private static function tab_nav( array $tabs ) {
		echo '<ul class="ole-tabnav" role="tablist">';
		foreach ( $tabs as $t ) {
			printf(
				'<li role="presentation"><a role="tab" href="#%1$s" aria-controls="%1$s" aria-selected="false">%2$s</a></li>',
				esc_attr( $t['id'] ),
				esc_html( $t['label'] )
			);
		}
		echo '</ul>';
	}

	/** Перемикач on/off навколо справжнього чекбокса (той самий name). */
	private static function switch_html( $name, $checked, $label = '' ) {
		return sprintf(
			'<label class="ole-switch">%1$s<input type="checkbox" name="%2$s" %3$s/><span class="ole-slider"></span></label>',
			'' !== $label ? '<span class="screen-reader-text">' . esc_html( $label ) . '</span>' : '',
			esc_attr( $name ),
			$checked ? 'checked' : ''
		);
	}

	/** «?»-іконка з підказкою у title. */
	private static function help_html( $text ) {
		if ( '' === $text ) {
			return '';
		}
		return '<a href="#" class="ole-help" title="' . esc_attr( $text ) . '" aria-label="' . esc_attr( $text ) . '">?</a>';
	}

	/**
	 * Відкриває картку фічі.
	 * @param string     $title
	 * @param string     $help   довгий опис у tooltip ('' = без іконки)
	 * @param array|null $switch ['name'=>string,'checked'=>bool] для перемикача в шапці, або null
	 */
	private static function card_open( $title, $help = '', $switch = null ) {
		$attr = '';
		if ( is_array( $switch ) ) {
			$attr = ' data-switch="' . esc_attr( $switch['name'] ) . '"';
		}
		echo '<div class="ole-card"' . $attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="ole-card-head">';
		echo '<h3 class="ole-card-title">' . esc_html( $title ) . '</h3>';
		echo self::help_html( $help ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( is_array( $switch ) ) {
			echo self::switch_html( $switch['name'], (bool) $switch['checked'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div><div class="ole-card-body">';
	}

	private static function card_close() {
		echo '</div></div>';
	}
```

- [ ] **Step 5: Rewrite `render()` into the tab shell + move sections verbatim**

In `includes/class-ole-settings-page.php`, rewrite `render()` so it:
1. Drops the inline `<style>` block (now in the CSS file).
2. Emits the shell and calls the four tab methods.

Replace the `render()` body with:

```php
	public function render() {
		$o  = OLE_Settings::get();
		$cb = function ( $key ) use ( $o ) {
			return checked( OLE_Settings::is_yes( $o, $key ), true, false );
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Order List Enhancer', 'order-list-enhancer' ); ?></h1>
			<form id="ole-settings-form">
				<div class="ole-settings-shell">
					<?php
					self::tab_nav(
						array(
							array( 'id' => 'orders',    'label' => __( 'Orders', 'order-list-enhancer' ) ),
							array( 'id' => 'checkout',  'label' => __( 'Checkout', 'order-list-enhancer' ) ),
							array( 'id' => 'inventory', 'label' => __( 'Inventory', 'order-list-enhancer' ) ),
							array( 'id' => 'phone',     'label' => __( 'Phone', 'order-list-enhancer' ) ),
						)
					);
					?>
					<div class="ole-tabpanels">
						<div class="ole-tabpanel" id="orders" role="tabpanel"><?php $this->render_tab_orders( $o, $cb ); ?></div>
						<div class="ole-tabpanel" id="checkout" role="tabpanel"><?php $this->render_tab_checkout( $o, $cb ); ?></div>
						<div class="ole-tabpanel" id="inventory" role="tabpanel"><?php $this->render_tab_inventory( $o, $cb ); ?></div>
						<div class="ole-tabpanel" id="phone" role="tabpanel"><?php $this->render_tab_phone( $o, $cb ); ?></div>
					</div>
				</div>
				<div class="ole-savebar">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'order-list-enhancer' ); ?></button>
					<span class="ole-save-status" style="font-weight:600;"></span>
				</div>
			</form>
		</div>
		<?php
	}
```

Then create the four methods by **moving the existing section markup verbatim** out of the old `render()` into the matching method. Signature for each: `private function render_tab_orders( $o, $cb ) { ?> …existing <h2>+table blocks… <?php }`. Move blocks per the mapping table in File Structure:
- `render_tab_orders`: the "Repeat customer highlighting", "Shipping coloring", "Order total coloring", "Order total on edit page", "Orders list — default bulk action", and "Open selected orders one-by-one" blocks (verbatim, including their `<?php $rules = …; $trules = …; ?>` setup that currently lives inside `render()` — move that setup into this method).
- `render_tab_checkout`: "Checkout phone validation", "Duplicate-order guard (checkout)", "Delivery-date notice (checkout)", "Extras → products" blocks (verbatim, including the `$emap` setup).
- `render_tab_inventory`: "Print consumables (stickers & instructions)" block (verbatim).
- `render_tab_phone`: "Phone numbers" block (verbatim).

Do not change any `name=`, control, or `$cb(...)`/`selected(...)` usage in the moved markup. Remove the old standalone `<p class="submit">…</p>` (its Save button now lives in `.ole-savebar`).

- [ ] **Step 6: Verify (static) and cross-map**

Run:
```
cd /Users/danko/PycharmProjects/order-list-enhancer
/opt/homebrew/bin/php -l includes/class-ole-settings-page.php
node --check assets/js/ole-settings.js
```
Expected: both clean.

Then the **render↔save cross-map**: list every `name="…"` in the four new tab methods and confirm each appears as a key in `ajax_save()` (and every `ajax_save()` `$in[...]` key has a field). Because Step 5 moved markup verbatim, the set must be identical to before. Command to eyeball the field names:
```
grep -oE 'name="[a-z_]+(\[\])?"' includes/class-ole-settings-page.php | sort -u
```

- [ ] **Step 7: Commit**

```bash
git add includes/class-ole-settings-page.php assets/css/ole-settings.css assets/js/ole-settings.js
git commit -m "feat(settings): tabbed shell + card/switch helpers; move sections into 4 tabs (verbatim)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Convert the Orders tab to cards

Deliverable: the 6 Orders features render as cards (header title + `?` help + on/off switch where applicable), with progressive disclosure on single-toggle cards. Fields/names unchanged.

**Files:** Modify `includes/class-ole-settings-page.php` (`render_tab_orders`).

**Interfaces:** Consumes `card_open/card_close/switch_html/help_html` from Task 1.

**Transformation pattern (apply to each feature):** replace the old `<h2>Title</h2><table class="form-table"><tbody> … </tbody></table>` with:
- `self::card_open( __('Short title'), __('Help detail…'), $switch );` where `$switch = array( 'name' => '<enable_field>', 'checked' => OLE_Settings::is_yes( $o, '<enable_field>' ) )` for single-toggle features, or `null` for control-only/multi-toggle features.
- the feature's **body controls** (the existing `<tr>` rows minus the enable-toggle row, which is now the header switch) inside a `<table class="form-table"><tbody> … </tbody></table>`.
- `self::card_close();`

- [ ] **Step 1: Worked example — "Repeat-customer highlighting" (single-toggle, disclosure)**

Replace its block with:

```php
		self::card_open(
			__( 'Repeat customers', 'order-list-enhancer' ),
			__( 'Outline & badge orders from the same customer in the list, with a details modal. Choose how a match is decided and how far back to scan.', 'order-list-enhancer' ),
			array( 'name' => 'dup_enabled', 'checked' => OLE_Settings::is_yes( $o, 'dup_enabled' ) )
		);
		?>
		<table class="form-table"><tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Match mode', 'order-list-enhancer' ); ?></th>
				<td>
					<?php $mode = $o['match_mode']; ?>
					<select name="match_mode">
						<option value="phone" <?php selected( $mode, 'phone' ); ?>><?php esc_html_e( 'By phone', 'order-list-enhancer' ); ?></option>
						<option value="names" <?php selected( $mode, 'names' ); ?>><?php esc_html_e( 'By name', 'order-list-enhancer' ); ?></option>
						<option value="name_phone" <?php selected( $mode, 'name_phone' ); ?>><?php esc_html_e( 'By name + phone', 'order-list-enhancer' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Scan limit', 'order-list-enhancer' ); ?></th>
				<td><input type="number" name="scan_limit" min="100" max="5000" step="100" value="<?php echo esc_attr( $o['scan_limit'] ); ?>"/></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Duplicate window (days)', 'order-list-enhancer' ); ?></th>
				<td><input type="number" name="dup_window_days" min="1" max="60" step="1" value="<?php echo esc_attr( $o['dup_window_days'] ); ?>"/></td>
			</tr>
		</tbody></table>
		<?php
		self::card_close();
```

(The long per-field descriptions move into the card's `?` help text or are dropped where redundant; keep field labels.)

- [ ] **Step 2: Convert the remaining 5 Orders features using the same pattern**

Apply the pattern to each, using these header definitions (body = the feature's existing non-toggle rows, verbatim from the current markup):
- **Shipping coloring** — `card_open( __('Shipping coloring'), __('Color the "Ship to" cell in the list and the address block on the order screen, by keyword rules.'), null )`. Because it has two toggles (`ship_enabled`, `ship_color_edit`), render them as two `switch_html()` rows at the top of the body (with visible labels via a `form-table` row each), then the rules table + default color/label rows — all verbatim. No `data-switch` (no disclosure).
- **Order-total coloring** — `card_open( __('Order-total coloring'), __('Ring an order (row + address panel) when its total reaches a threshold. Highest matched threshold wins.'), array('name'=>'total_color_enabled','checked'=>OLE_Settings::is_yes($o,'total_color_enabled')) )`; body = the threshold rules table (verbatim, keep the `$trules` setup at the method top).
- **Order total on edit** — `card_open( __('Order total on the edit screen'), __('Show the total near the billing address on the order screen, with copy buttons.'), array('name'=>'total_on_edit','checked'=>OLE_Settings::is_yes($o,'total_on_edit')) )`; body = the `copy_buttons` toggle (as a `switch_html` row) + the `total_decimal_sep` select (verbatim).
- **Default bulk action** — `card_open( __('Default bulk action'), __('Pre-select an entry in the orders-list bulk-actions menu on page load.'), null )` (control-only); body = the `bulk_default_action` select block, verbatim (keep the `$bulk_actions`/`$bulk_cur` setup).
- **Open one-by-one** — `card_open( __('Open selected one-by-one'), __('Add a button that opens each checkbox-selected order in its own tab, one at a time.'), array('name'=>'seq_open_enabled','checked'=>OLE_Settings::is_yes($o,'seq_open_enabled')) )`; body = the `seq_open_interval` number field (verbatim).

Keep every `name=`, `selected()`, `$cb`, and rules-table markup exactly as in the current file.

- [ ] **Step 3: Verify + commit**

```
cd /Users/danko/PycharmProjects/order-list-enhancer
/opt/homebrew/bin/php -l includes/class-ole-settings-page.php
# static field names:
grep -oE 'name="[a-z_]+(\[\])?"' includes/class-ole-settings-page.php | sort -u
# switch-provided field names (dynamic — from card_open $switch and switch_html calls):
grep -oE "'name' *=> *'[a-z_]+'|switch_html\( *'[a-z_]+'" includes/class-ole-settings-page.php | sort -u
```
The enable toggles moved into `switch_html()` calls, so their `name` is now dynamic (won't show in the first grep) — the SECOND grep must list them. Confirm the Orders enable fields are all still emitted somewhere (`dup_enabled`, `ship_enabled`, `ship_color_edit`, `total_color_enabled`, `total_on_edit`, `copy_buttons`, `seq_open_enabled`) — none dropped. Then:
```bash
git add includes/class-ole-settings-page.php
git commit -m "feat(settings): Orders tab as cards with switches + disclosure

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Convert the Checkout tab to cards

Deliverable: the 4 Checkout features render as cards; fields/names unchanged.

**Files:** Modify `includes/class-ole-settings-page.php` (`render_tab_checkout`).

**Interfaces:** Consumes the Task 1 helpers.

- [ ] **Step 1: Convert the 4 features using the Task 2 pattern**

- **Checkout phone validation** — `card_open( __('Checkout phone validation'), __('Validate the billing phone (Bulgarian numbers) at checkout and flag invalid ones in admin.'), array('name'=>'phone_validate_enabled','checked'=>OLE_Settings::is_yes($o,'phone_validate_enabled')) )`; body = the `phone_validate_mode` select (verbatim).
- **Duplicate-order guard** — `card_open( __('Duplicate-order guard'), __('Detect an identical recent order (same phone + cart) at checkout and confirm or block it.'), array('name'=>'dup_guard_enabled','checked'=>OLE_Settings::is_yes($o,'dup_guard_enabled')) )`; body = `dup_guard_mode` select + `dup_guard_window_min` number (verbatim).
- **Delivery-date notice** — `card_open( __('Delivery-date notice'), __('Show a highlighted note above the delivery-date field at checkout; optional vacation banner.'), array('name'=>'delivery_notice_enabled','checked'=>OLE_Settings::is_yes($o,'delivery_notice_enabled')) )`; body = title/body text fields + the `delivery_vacation_enabled` toggle (as a `switch_html` row) + vacation date/text fields (verbatim).
- **Extras → products** — `card_open( __('Extras → products'), __('At order creation, turn each mapped add-on extra into a real product line at the price paid.'), array('name'=>'extras_enabled','checked'=>OLE_Settings::is_yes($o,'extras_enabled')) )`; body = the mapping table (verbatim, keep the `$emap` setup at the method top).

Keep every `name=`, `wc-product-search` attribute, `$cb`, and table markup exactly as current.

- [ ] **Step 2: Verify + commit**

```
cd /Users/danko/PycharmProjects/order-list-enhancer
/opt/homebrew/bin/php -l includes/class-ole-settings-page.php
grep -oE 'name="[a-z_]+(\[\])?"' includes/class-ole-settings-page.php | sort -u
grep -oE "'name' *=> *'[a-z_]+'|switch_html\( *'[a-z_]+'" includes/class-ole-settings-page.php | sort -u
```
Confirm the Checkout field names are all still present across BOTH greps (`phone_validate_enabled`+`phone_validate_mode`, `dup_guard_enabled`+`dup_guard_mode`+`dup_guard_window_min`, `delivery_notice_enabled`+`delivery_vacation_enabled`+the delivery text fields, `extras_enabled`, `extra_match[]`, `extra_product[]`). Then:
```bash
git add includes/class-ole-settings-page.php
git commit -m "feat(settings): Checkout tab as cards

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Convert the Inventory + Phone tabs to cards

Deliverable: the last 2 features render as cards; fields/names unchanged.

**Files:** Modify `includes/class-ole-settings-page.php` (`render_tab_inventory`, `render_tab_phone`).

**Interfaces:** Consumes the Task 1 helpers.

- [ ] **Step 1: Convert Print consumables (inventory)**

`card_open( __('Print consumables'), __('Track sticker & instruction-sheet stock, auto-decrement at order placement, and warn when low.'), array('name'=>'print_stock_enabled','checked'=>OLE_Settings::is_yes($o,'print_stock_enabled')) )`; body = the two threshold number fields + the "Open consumables stock" button row (verbatim). Keep the `admin.php?page=ole-print-stock` link.

- [ ] **Step 2: Convert Phone normalization (phone)**

`card_open( __('Phone numbers'), __('Tidy phone numbers for display (leading 00 → +, add country code when missing). Never changes the database.'), array('name'=>'normalize_phone','checked'=>OLE_Settings::is_yes($o,'normalize_phone')) )`; body = the `phone_cc` text field (verbatim). Note `phone_cc` has no toggle and is used by other features — keep it always visible in the body.

- [ ] **Step 3: Verify + commit**

```
cd /Users/danko/PycharmProjects/order-list-enhancer
/opt/homebrew/bin/php -l includes/class-ole-settings-page.php
# union of static + switch-provided names must equal the pre-redesign set:
{ grep -oE 'name="[a-z_]+(\[\])?"' includes/class-ole-settings-page.php | sed -E 's/name="([a-z_]+)(\[\])?"/\1/'; \
  grep -oE "'name' *=> *'[a-z_]+'|switch_html\( *'[a-z_]+'" includes/class-ole-settings-page.php | grep -oE "'[a-z_]+'$" | tr -d "'"; } | sort -u
```
Compare this union against the field set captured before the redesign (Task 1 Step 6). It must be identical — nothing added, nothing dropped. Confirm `print_stock_enabled`, `print_stock_threshold_sticker`, `print_stock_threshold_instruction`, `normalize_phone`, `phone_cc` are all present. Then:
```bash
git add includes/class-ole-settings-page.php
git commit -m "feat(settings): Inventory + Phone tabs as cards

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: bg_BG translations + version bump

Deliverable: every new string (4 tab labels, card titles, help texts, "Save changes" if new) is translated; `.mo` recompiled; version bumped.

**Files:** Modify `languages/order-list-enhancer-bg_BG.po` (+ `.mo`), `order-list-enhancer.php`.

- [ ] **Step 1: Bump the version**

In `order-list-enhancer.php`, change the header `Version:` and `define('OLE_VERSION', …)` from `1.0.32` to `1.0.33`.

- [ ] **Step 2: Add bg_BG entries for every NEW msgid**

For each new/changed string introduced in Tasks 1–4 (the 4 tab labels and every `card_open()` title + help text), append a `msgid`/`msgstr` pair to `languages/order-list-enhancer-bg_BG.po`. **Byte-match rule:** each `msgid` must be identical to the exact English string in the PHP source (verify with `grep -RnF "<string>" includes/`); reuse existing entries where a string already exists (e.g. "Save changes" already has an entry — do not duplicate). Translations to use:

```po
msgid "Orders"
msgstr "Поръчки"

msgid "Checkout"
msgstr "Плащане"

msgid "Inventory"
msgstr "Наличности"

msgid "Phone"
msgstr "Телефон"

msgid "Repeat customers"
msgstr "Повторни клиенти"

msgid "Order-total coloring"
msgstr "Оцветяване по сума на поръчката"

msgid "Order total on the edit screen"
msgstr "Обща сума на екрана за редакция"

msgid "Default bulk action"
msgstr "Действие по подразбиране"

msgid "Open selected one-by-one"
msgstr "Отваряй избраните една по една"

msgid "Duplicate-order guard"
msgstr "Защита от дублирани поръчки"

msgid "Delivery-date notice"
msgstr "Бележка за датата на доставка"
```

(For every remaining new title/help string in the source — e.g. "Shipping coloring", "Checkout phone validation", "Extras → products", "Print consumables", "Phone numbers", and all help sentences — add a matching `msgid`/`msgstr`; where a title already existed as a section `<h2>` string in the old file and still exists verbatim, its old translation still applies and needs no new entry. Confirm via grep which titles are genuinely new vs reused.)

- [ ] **Step 3: Recompile the `.mo`**

```
cd /Users/danko/PycharmProjects/order-list-enhancer
/opt/homebrew/bin/msgfmt --check --statistics languages/order-list-enhancer-bg_BG.po -o languages/order-list-enhancer-bg_BG.mo
```
Expected: exits 0, prints a translated-count line, no errors.

- [ ] **Step 4: Changelog + commit**

Add a `= 1.0.33 =` entry to `readme.txt`: `Settings page redesigned: 4 tabbed categories, feature cards with on/off switches, tightened copy.` Then:

```bash
git add order-list-enhancer.php languages/order-list-enhancer-bg_BG.po languages/order-list-enhancer-bg_BG.mo readme.txt
git commit -m "chore(settings): bg_BG for redesign, bump 1.0.33, changelog

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Final verification (live, after deploy)

Deploy per [[deploy-procedure]]. Then: all 4 tabs switch without reload and via the URL hash; editing fields across multiple tabs and pressing Save persists everything; on/off switches show/hide their card bodies; color pickers, shipping/total rule tables, and the extras product-search still work inside cards; the "?" tooltips show the detail text; copy reads correctly in Bulgarian; with JavaScript disabled the page shows all panels and still saves. Confirm no setting silently stopped saving (spot-check one field per tab round-trips).

## Notes

Presentational redesign on the `feature/print-stock-inventory` branch (bundled with print-consumables for a single deploy). Behavior-neutral by construction (field names + `ajax_save()` unchanged). WordPress.org publishing-readiness audit is a separate follow-up.
