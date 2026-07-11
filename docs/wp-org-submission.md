# WordPress.org submission guide (Order List Enhancer)

Written 2026-07-12 for v1.0.46. Three parts: screenshots, Plugin Check, submission.

## 1. Screenshots

readme.txt promises exactly these four (== Screenshots == section). Retake all four on
dobavki.club (or the dev copy) at a browser width of ~1280–1440 px, admin language
English *or* Bulgarian — either is fine, but be consistent across all four.

1. **screenshot-1** — Orders list showing repeat-customer outlines + the
   "customer · N orders" / red "duplicate" badges. Use WooCommerce → Orders with a
   customer who has 2+ orders (check the Поръчки list for an outlined pair).
2. **screenshot-2** — The customer-history popup: click a badge, wait for the order
   list to load, capture the modal.
3. **screenshot-3** — A single order edit screen showing: colored address block,
   the order total under the billing address, and the copy buttons (name/phone/total).
4. **screenshot-4** — The settings page (WooCommerce → Order List Enhancer), Поръчки
   tab, showing the cards and toggles. Retake it now that the tabbed redesign +
   per-button copy toggles are live.

Format rules (WP.org):
- PNG or JPG, named exactly `screenshot-1.png` … `screenshot-4.png` (lowercase).
- They do NOT go in the plugin zip — they go in the SVN `assets/` directory
  (next to, not inside, `trunk/`). Same place as the optional `banner-772x250.png`
  and `icon-256x256.png` (a banner + icon are worth making — the listing looks
  bare without them).
- macOS: capture with Cmd+Shift+4 (area) — avoid full-Retina 2x monsters; resize
  to ≤1440 px wide if needed (`sips -Z 1440 screenshot-1.png`).

## 2. Plugin Check (the official validation plugin)

Plugin Check ("PCP") is what the review team runs first — pass it before submitting.

Install on the live site (or better: the dev copy at dev.dobavki.club, so nothing
touches prod):
1. wp-admin → Разширения → Add New → search **"Plugin Check"** (author: WordPress
   Performance Team / plugin slug `plugin-check`) → Install → Activate.
2. Go to **Tools (Инструменти) → Plugin Check**.
3. Pick **Order List Enhancer** from the dropdown, leave all check categories on
   (incl. "Plugin repo" category), click **Check it!**.
4. Fix anything red (ERROR). Yellow warnings: fix what's reasonable, note the rest —
   reviewers tolerate justified warnings.

Known expected finding: the `OLE_`/`ole_` prefix may warn as "too short" — kept by
design (renaming would orphan live `ole_*` options/meta); mention this in the
review thread if asked. The static PHPCS/WPCS layer was already run clean locally;
only the runtime checks need this live pass.

Deactivate + delete Plugin Check afterwards (it is a dev tool).

## 3. Submitting for review

One-time, via the web — there is no CLI for the initial review:

1. **Account**: log in (or register) at wordpress.org with the account that should
   own the plugin — this cannot be transferred easily later.
2. **Build the zip with `bin/build-zip.sh`** (never zip the folder by hand — a
   Finder zip ships `tests/`, hidden dev directories, stray old zips and fails Plugin Check,
   as the 2026-07-12 report showed). The script whitelists runtime files only and
   writes `dist/order-list-enhancer-<version>.zip`. Run Plugin Check against THIS
   zip too. Max 10 MB; ours is well under.
3. **Upload** at <https://wordpress.org/plugins/developers/add/>. The form asks for
   the zip only; the plugin name in the main file's header becomes the requested
   slug (`order-list-enhancer`). Slug is assigned at review time and is final.
4. **Wait for the review email** (plugins@wordpress.org — check spam; typical wait
   is days to a few weeks). Replies go by email; keep the thread. Common asks:
   sanitization/escaping proof, prefix questions, readme fixes. Answer on the same
   thread, upload a fixed zip if requested.
5. **After approval** you get SVN access at
   `https://plugins.svn.wordpress.org/order-list-enhancer/`:
   - `svn co https://plugins.svn.wordpress.org/order-list-enhancer ole-svn`
   - copy the plugin files into `trunk/`, screenshots/banner/icon into `assets/`
   - `svn add` new files, then
     `svn ci -m "Initial release 1.0.46" --username <wporg-user>`
   - tag the release: `svn cp trunk tags/1.0.46 && svn ci -m "Tag 1.0.46"`.
     `Stable tag:` in trunk/readme.txt must equal that tag name.
6. **Ongoing releases**: bump version + changelog in git as usual, then repeat the
   trunk-copy + tag dance in SVN. Translations move to translate.wordpress.org
   once live (our .po/.mo keep working meanwhile; the POT is fresh at 183 strings).

Pre-flight checklist (all currently true for 1.0.46):
- [x] `Stable tag` = plugin `Version` = changelog top entry
- [x] `Tested up to: 7.0`, `Requires at least: 6.2`, `Requires PHP: 7.4`
- [x] GPLv2+ license header + LICENSE file
- [x] All strings text-domain `order-list-enhancer`, POT fresh, bg_BG 100%
- [x] No external services called (readme FAQ states this)
- [ ] Plugin Check runtime pass (needs live WP — part 2 above)
- [ ] 4 fresh screenshots + banner/icon in SVN `assets/`
