# Changelog

## v1.1.1 — Discord Save Fix + Abandoned Cart Robustness

### Fixed
- **Discord Settings "Link Expired" Error:** Fixed nonce mismatch on the Discord settings page that caused "The link you followed has expired" when saving settings. The form was posting to `admin-post.php` with a different nonce action/field than expected by the save handler. Now uses the same pattern as all other Guardify settings pages.

### Improved
- **Abandoned Cart — Nonce Refresh for Cached Pages:** If LiteSpeed Cache (or any full-page cache) serves a cached checkout page with a stale nonce, the JS now automatically detects the 403 failure and refreshes the nonce via a lightweight AJAX call, then retries the capture. Every successful capture response also returns a fresh nonce for subsequent calls.
- **Abandoned Cart — Better Checkout Page Detection:** Added fallback detection via `woocommerce_checkout` shortcode and WooCommerce checkout page ID check. This covers Elementor Pro, Divi, and other page builders where `is_checkout()` might return false.
- **Abandoned Cart — MutationObserver for Dynamic Forms:** Instead of a single 2-second timeout, the JS now uses `MutationObserver` to watch for dynamically rendered checkout forms (CartFlows, block checkout, page builders). Falls back to multiple retry attempts at 1s, 2s, 4s, and 7s for browsers without MutationObserver.
- **New AJAX Endpoint:** `guardify_refresh_nonce` — lightweight endpoint that generates a fresh nonce without any security requirement (since it only creates a nonce, not consumes one).

### Technical
- Updated: `includes/class-guardify-settings.php` — Discord form nonce fix
- Updated: `includes/class-guardify-abandoned-cart.php` — Nonce refresh endpoint, improved `is_checkout_page()`, fresh nonce in capture response
- Updated: `assets/js/abandoned-cart.js` — Nonce refresh on 403, MutationObserver form detection, retry logic

## v1.1.0 — Instant Capture + Discord Notifications + Custom Fields

### Added
- **Immediate Incomplete Order Creation:** Incomplete orders are now created the instant a visitor lands on the checkout page — before they fill any form field. Browser metadata (device, screen, referrer, timezone, connection type) is captured immediately.
- **Browser Metadata Capture:** Collects 20+ data points: user agent, screen/viewport size, language, timezone, platform, connection type, device memory, touch support, color depth, pixel ratio, and more.
- **UTM / Campaign Parameter Tracking:** Automatically captures `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `fbclid`, and `gclid` from the checkout URL. Stored as order metadata and sent to Discord.
- **Custom Checkout Field Capture:** Dynamically scans and captures ALL non-standard WooCommerce checkout fields (size, color, custom dropdowns, text fields etc.). Stored both as a JSON blob and as individual order meta entries for easy admin display.
- **Discord Webhook Notifications:** New `Guardify_Discord` class sends rich embed messages to a Discord channel for:
  - 🌐 **Visitor arriving** on checkout (browser-only data)
  - 🟠 **Customer identified** (phone/email filled, includes fraud report)
  - 🟢 **Order placed** (full order data + fraud report)
  - 🚨 **Fraud block** (order auto-failed due to high fraud score)
- **Discord Notification Content:** Each notification includes customer info, cart items, custom fields, browser/device details, UTM data, and a full fraud report (score, risk signals, verdict, courier delivery stats).
- **Discord Settings Page:** New "Discord" submenu under Guardify with webhook URL, bot name, avatar URL, event toggles, and a test webhook button.
- **Test Webhook Button:** One-click test to verify Discord webhook is working correctly from the admin settings page.

### Changed
- **No Phone/Email Requirement:** Incomplete orders are created immediately with just browser data. Previously required at least a phone or email to create the order.
- **Broader Field Capture Triggers:** JS now captures blur/change events on ALL form fields (including custom fields like size, color), not just billing/shipping fields.
- **sendBeacon Enhancement:** Page unload/tab switch captures now also include browser metadata and custom field data.
- **Draft Deduplication:** Now also deduplicates by IP address for browser-only captures (no phone/email), preventing duplicate orders from the same visitor refreshing the page.
- **Action Hooks:** Added `guardify_incomplete_order_created`, `guardify_incomplete_order_identified`, and `guardify_incomplete_order_updated` action hooks for extensibility.
- **Updated trigger labels:** Added "Page Load" trigger label for immediate capture events.

### Technical
- New file: `includes/class-guardify-discord.php` — Discord webhook integration
- Updated: `includes/class-guardify-abandoned-cart.php` — Immediate capture, browser metadata, custom fields, action hooks
- Updated: `assets/js/abandoned-cart.js` — Page load capture, browser data collection, dynamic field scanning
- Updated: `includes/class-guardify-settings.php` — Discord settings page and save logic
- Updated: `guardify.php` — Discord class loader, default options, version bump to 1.1.0

## v1.0.9 — Incomplete Order Capture (Abandoned Cart)

### Added
- **Incomplete Order Capture:** New `Guardify_Abandoned_Cart` class that captures checkout data in real-time as customers fill out forms. Creates "Incomplete" orders when customers abandon checkout without submitting.
- **Custom `wc-incomplete` order status:** Registered with WooCommerce, appears in the regular Orders list with a distinctive red badge. Filterable like any other order status.
- **Browser close / tab switch detection:** Uses `visibilitychange` + `beforeunload` events with `navigator.sendBeacon` for reliable capture even when users close the browser.
- **Field-level capture:** Debounced AJAX capture on field blur — phone and email trigger immediate capture, other fields follow the configurable debounce timer.
- **CartFlows full support:** Detects CartFlows checkout steps, captures `_wcf_flow_id` and `_wcf_checkout_id` metadata for CartFlows orders.
- **Admin settings page:** New "Incomplete Orders" submenu under Guardify with toggle, debounce config, retention period, recent incomplete orders table, and compatibility info.
- **Auto-cleanup:** Configurable retention (default 30 days). Daily cron deletes old incomplete orders automatically.
- **Draft deduplication:** Same phone/email within 2 hours reuses existing incomplete order instead of creating duplicates.
- **Auto-trash on completion:** When a customer completes checkout, the corresponding incomplete order is automatically trashed.

### LiteSpeed Cache + Redis + OpenLiteSpeed Compatibility
- Uses `admin-ajax.php` exclusively (never REST API) — **admin-ajax is never cached by LiteSpeed**, even with Aggressive/Advanced preset.
- Explicit `litespeed_control_set_nocache` action called as defense-in-depth.
- Redis object cache is transparent — `wp_options` and WC order storage work normally through Redis.
- OpenLiteSpeed server fully compatible — no `.htaccess` or server-level config changes needed.
- No ESI nonce issues — nonces are passed via `wp_localize_script` (inline JS data), not embedded in cached HTML.

### Notes
- WooCommerce HPOS compatible (uses `wc_get_orders()` and WC Order objects, not direct DB queries).
- Default: enabled on activation. Configurable via Guardify → Incomplete Orders.

---

## v1.0.8 — Patch: cleanup SSO & plugin UI

### Fixed
- Removed deprecated WP→Console SSO endpoint and AJAX SSO flow; simplified the `Open Console` card to a direct link.
- Minor PHP/JS cleanup and documentation updates.

### Notes
- Non-breaking patch; update TansiqLabs Console for SSO improvements.

---

## v1.0.7 — Patch: persist scores, bulk update, verdict & admin UI

### Fixed
- Persisted fraud `score`/`risk` to order meta so scores appear on the WooCommerce Orders page.
- `render_fraud_badge()` now prefers stored order meta over transient cache.
- AJAX `fraud-check` now saves scores to order meta (prevents transient-only loss).

### Added
- Bulk-update admin action to backfill scores for old orders (batched processing with progress UI).
- `verdict` summary shown in the order meta box.
- UI shows courier `sources` when multiple courier providers are available.

### Notes
- Backwards-compatible patch; no DB schema changes. Works with the TansiqLabs Console multi-courier API.

---

## v1.0.6 — Fix: show courier stats when local meta missing

### Fixed
- Order list `Score` column could show `0 / 0 / 0%` when courier history existed in the TansiqLabs Console but there was no local courier meta. Guardify now falls back to the TansiqLabs `fraud-check` courier summary for the billing phone (cached 10 minutes) so `TOTAL` / `DELIVERED` / `RETURNED` reflect courier history.

### Notes
- This is a non-breaking patch and does not change stored order meta — it only augments the admin display.

---

## v1.0.5 — Steadfast courier (display + docs)

### Changed
- Guardify UI will now display Steadfast courier delivery/cancellation stats when TansiqLabs provides global Steadfast credentials (server-side fallback). This fixes cases where courier history showed 0 despite Steadfast reporting deliveries.
- Documentation: clarified Steadfast environment variables and `.env.example` in the TansiqLabs repo (used as universal fallback for fraud checks).

### Notes
- No functional plugin code changes were required — the fix was implemented on the TansiqLabs API side and the plugin will now surface courier data when the console is configured.

---

## v1.0.4 — Universal Fraud Intelligence

### Added
- **Universal fraud check:** Automatic fraud score check on every new order using cross-network threat intelligence
- **Auto-block:** Orders exceeding the configured fraud threshold are automatically failed with detailed fraud signals in order notes
- **Fraud meta data:** Score, risk level, and check timestamp stored as order meta for reporting
- **Network effect:** Fraud scores now reflect threat data from ALL Guardify-connected sites, not just your own

### Changed
- `class-guardify-fraud-check.php` moved outside `is_admin()` gate — hooks `woocommerce_new_order` + `woocommerce_checkout_order_created` for frontend auto-check
- Fraud threshold configured per-site via TansiqLabs Console → Guardify → Settings

---

## v1.0.3 — LiteSpeed Cache compatibility & incomplete order fix

### Fixed
- **LiteSpeed Cache compatibility:** Replaced all `wp_localize_script()` calls with `wp_add_inline_script()` across ALL 9 modules — fully compatible with LiteSpeed Cache Advanced, Aggressive, and Extreme optimization modes
- **Incomplete order cooldown:** Hooked `check_cooldown_on_new_order()` to `woocommerce_new_order` and `woocommerce_checkout_order_created` — draft/incomplete orders from Cartflows and similar plugins now trigger cooldown auto-fail

### Modules updated
- `class-guardify-settings.php` — `guardifyAjax`
- `class-guardify-order-cooldown.php` — `guardifyOrderCooldown`
- `class-guardify-phone-validation.php` — `guardifyPhoneValidation`
- `class-guardify-steadfast.php` — `guardifySteadfast`
- `class-guardify-order-columns.php` — `guardifyOrderColumns`
- `class-guardify-fraud-check.php` — `guardifyFraudCheck`
- `class-guardify-phone-history.php` — `guardifyPhoneHistory`
- `class-guardify-blocklist.php` — `guardifyBlocklist`
- `class-guardify-staff-report.php` — `guardifyStaffReport`

### Performance
- Frontend scripts load ONLY on checkout pages with `defer` strategy — zero impact on homepage/product/cart
- Inline data scripts paired to their handles — immune to JS combination/reordering

---

## v1.0.2 — Courier proxy architecture

### Changed
- All Steadfast courier API calls now route through TansiqLabs API proxy (`/api/guardify/courier/*`)
- Courier credentials (API key, Secret key) no longer stored in WordPress — they stay on the TansiqLabs server
- Plugin authenticates with its `guardify_site_api_key` for all courier operations
- Settings page updated: connection status based on enabled flag, not local credentials
- Validate endpoint no longer exposes Steadfast credentials in response

### Security
- Courier API keys never leave TansiqLabs server — eliminates credential exposure risk
- All courier operations authenticated via site API key

---

## v1.0.1 — License activation fix

### Fixed
- Complete rewrite of license activation system to work with LiteSpeed Cache and JS optimizers
- Replaced jQuery AJAX / `wp_localize_script()` with inline XHR and `onclick` handlers
- Added `data-no-optimize`, `data-cfasync`, `data-pagespeed-no-defer` attributes to prevent JS optimization
- Added non-AJAX fallback via `admin_post` hook for environments where JavaScript fails entirely
- Added inline visual feedback for all license actions (activate, verify, deactivate)

---

## v1.0.0 — Initial release

### Added
- PSR-4 plugin scaffold and core protection modules
- BD phone validation, phone/IP cooldowns, VPN/proxy detection
- Device fingerprinting, blocklist manager, phone history
- SteadFast courier integration (printable invoice + bulk send)
- Staff reports, analytics dashboard, email notifications

### Notes
- Requires license activation (TansiqLabs Console) to enable features

