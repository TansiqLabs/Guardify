# Changelog

## v1.2.6 — Hotfix: make update checker recognize new version

### Fixed
- Rebuilt release with updated plugin header to ensure WordPress recognizes the bumped version. Added 1.2.6 tag to force the update checker.

## v1.2.5 — Settings Page & Defaults Improvements

### Fixed
- **Settings persistence bug:** options on subpages (SteadFast/Discord/Incomplete Orders) were accidentally reset to OFF when saving any other page. The save handler now scopes settings per-page, using a hidden `guardify_settings_page` field.
- **Defaults kept off after caching flush:** phone cooldown, IP cooldown, VPN block, address detection, name similarity, and notification now default to ON. Compatibility with cached sites verified.

### Changed
- **SteadFast submenu visibility:** only appears when the integration is actually enabled via TansiqLabs API (license sync).
- Updated activation defaults to enable core protections by default.

### Technical
- Refactored `save_settings()` in `class-guardify-settings.php` with page scoping and added hidden fields to each form.
- Adjusted default `get_option()` fallbacks across settings page and feature classes.
- Added conditional submenu logic for SteadFast.
- Version bump to 1.2.5.


## v1.2.3 — Incomplete Order Rewrite: Custom DB Table

### Changed
- **Custom Database Table:** Incomplete orders are now stored in a dedicated `{prefix}_guardify_incomplete_orders` table instead of creating WooCommerce orders with `wc-incomplete` status. This eliminates WC order pollution and improves performance.
- **Settings Page Rewrite:** The Abandoned Cart admin page now reads from the custom table via `Guardify_Abandoned_Cart::get_instance()` methods. Displays ID, Name, Phone, Products, Total, and Time ago columns.
- **New Actions:** Added Delete, Convert to WC Order, Bulk Delete, and CSV Export buttons with AJAX handling directly on the settings page.
- **Cooldown System:** Added `guardify_incomplete_cooldown_enabled` and `guardify_incomplete_cooldown_minutes` settings to prevent duplicate captures from the same phone number within a configurable time window.
- **Discord Events:** Consolidated `incomplete`/`identified` events into a single `incomplete` event (`guardify_incomplete_order_captured` hook). Removed `identified` event entirely.

### Removed
- Removed `capture_on_input` and `debounce` settings (no longer needed with new capture logic).
- Removed WooCommerce Orders page link for incomplete orders (no longer a WC order status).
- Removed `guardify_incomplete_order_created` and `guardify_incomplete_order_identified` Discord hooks.

### Technical
- Rewritten: `includes/class-guardify-abandoned-cart.php` — Full rewrite using custom DB table (`901 lines`)
- Rewritten: `assets/js/abandoned-cart.js` — New JS capture logic (`~165 lines`)
- Updated: `includes/class-guardify-discord.php` — New `guardify_incomplete_order_captured` hook
- Updated: `includes/class-guardify-settings.php` — New `render_abandoned_cart_page()`, updated `save_settings()`, updated Discord `$all_events`
- Updated: `guardify.php` — Version bump, updated defaults
- Version: 1.2.2 → 1.2.3

## v1.2.2 — Discord: Single Message, Taka Fix & Full Details

### Fixed
- **Taka Symbol Encoding:** `&#2547;&nbsp;` no longer appears in Discord messages. All prices now show as "Taka 1,200" instead of broken HTML entities.
- **Duplicate Messages Eliminated:** New order + immediate processing status change no longer sends 2 separate messages. The processing transition is silently skipped when `new_order` notification was already sent.

### Changed
- **Single Message Per Event:** All order information (customer info, cart items, browser/device data, UTM, fraud report, repeat customer history) is now consolidated into ONE Discord embed per event. Previously sent as 3-5 separate embeds across the message.
- **Full Details on Every Notification:** Removed "compact" mode — every status change (completed, cancelled, refunded, etc.) now includes the complete order details (name, address, email, products, price, size, etc.), not just a brief status update.
- **Removed Avatar Option:** Bot avatar URL setting removed from Discord settings page. Discord's default webhook avatar is used.

### Technical
- Rewritten: `includes/class-guardify-discord.php` — Complete consolidation into single-embed architecture
- Updated: `includes/class-guardify-settings.php` — Removed avatar field from Discord settings
- Added: `format_price()` helper — Decodes HTML entities and replaces ৳ with "Taka"
- Version: 1.2.1 → 1.2.2

## v1.2.1 — Patch: Discord admin settings & webhook fixes

### Fixed / Improved
- Added the Discord settings page in WP Admin (Guardify → Discord): enable/disable, primary webhook URL, per-event webhook overrides, bot name/avatar, and a Test webhook button.
- `send_webhook()` now resolves per-event webhook URLs and preserves `event_type` across scheduled retries; improved retry handling and logging.
- Bumped plugin version to 1.2.1.

## v1.2.0 — Discord Integration Restored & Enriched

### Critical Fix
- **Discord Notifications NOW WORK:** The `Guardify_Discord` class was never loaded in `guardify_init()` — the file existed but was never `require`'d or instantiated. This is the root cause of messages not being sent. Fixed by adding Discord class loading alongside Fraud Check in the plugin initialization.
- **Webhook delivery was unreliable:** `send_webhook()` used `'blocking' => false` in `wp_remote_post()`, which fires-and-forgets the HTTP request. On many hosts, the PHP process terminates before the request completes. Changed to `'blocking' => true` to ensure messages are actually delivered.

### New Features
- **Order Status Change Notifications:** Discord now receives notifications for ALL status changes — processing, completed, on-hold, cancelled, refunded, failed — each with unique colors and emojis.
- **Repeat Customer Detection:** New embed shows previous orders, total spent, completion rate, and a reliability score (🟢 Reliable / 🟡 Mixed / 🔴 Risky) for returning customers.
- **Coupon Tracking:** Coupons used in orders are displayed with discount amounts.
- **Payment Details:** Payment method, transaction ID, and order date shown in notifications.
- **Order Totals Breakdown:** Subtotal, shipping (with method name), discounts, tax, and total displayed separately.
- **Product Variation Info:** Cart items embed now shows variation details (size, color, etc.) alongside product names.
- **Visual Fraud Score Bar:** Fraud score shown with a color bar (🟥🟥🟥⬜⬜⬜⬜⬜⬜⬜) for quick visual assessment.
- **Webhook Retry Mechanism:** Failed webhook calls (network error, rate limit, server error) are automatically retried up to 2 times with scheduled delays.
- **Rate Limit Handling:** Discord 429 responses are detected and retried after the specified cooldown period.
- **Enhanced Error Logging:** All webhook failures logged with attempt number, HTTP status, and response body for debugging.
- **Status Changed By:** Shows who changed the order status (admin user name/email or "Customer / System").
- **Smart Deduplication:** Avoids sending duplicate notifications when a new order immediately transitions to processing.
- **Admin/API Order Capture:** Fallback hook catches orders created via REST API or admin panel.
- **TikTok Click ID (ttclid):** Added to UTM/campaign tracking.
- **Samsung & UC Browser Detection:** User agent parser now identifies these popular mobile browsers.

### Technical
- Updated: `guardify.php` — Added Discord class loading in `guardify_init()` (the critical missing piece)
- Rewritten: `includes/class-guardify-discord.php` — Complete rewrite with all new features
- Version: 1.1.3 → 1.2.0

## v1.1.3 — Incomplete Order Fix + WC Session Init

### Fixed
- **Incomplete Order Capture NOW WORKS:** Fixed the root cause — `admin-ajax.php` does not automatically load WooCommerce session/cart for non-logged-in users. Added explicit WC session initialization and cart loading in the AJAX handler so cart items are properly available when creating draft orders.
- **Nonce refresh was one-shot:** Previously, if the first nonce refresh also resulted in an expired nonce (e.g., user sits on page for hours), all subsequent capture attempts failed silently forever. Now allows retry every 30 seconds with a cooldown instead of a single boolean flag.

### Improved
- Added `error_log()` calls in AJAX handler for draft order creation failures — makes production debugging possible.
- Better validation: returns explicit error if `get_or_create_draft_order` returns 0/false.

### Technical
- Updated: `includes/class-guardify-abandoned-cart.php` — WC session/cart init in AJAX, error logging
- Updated: `assets/js/abandoned-cart.js` — Cooldown-based nonce refresh (30s) replaces one-shot boolean

## v1.1.2 — Speed Fix + Discord Moved to Console

### Performance
- **Eliminated page_load order creation:** Previously, every checkout page visit created a full WooCommerce order (DB write + cart item loop) even with zero form data. Now the server returns immediately without any database write on `page_load`. Orders are only created once the customer starts filling in fields (phone, email, or name).
- **Removed Discord class from plugin:** The `Guardify_Discord` class made a blocking 8-second API call to fetch fraud scores on every identified order. Discord notifications are now handled server-side by the TansiqLabs Console — zero impact on WooCommerce page speed.

### Changed
- **Discord Integration moved to TansiqLabs Console:** Discord webhook settings and notifications are no longer in the WordPress plugin. Configure Discord in your TansiqLabs Console dashboard instead. This eliminates all blocking HTTP calls from the plugin.

### Removed
- Discord settings submenu page from WordPress admin
- Discord class loading and default options from plugin activation
- All Discord-related save logic from settings handler

### Fixed
- **Incomplete Order Capture:** Fixed the root issue — `page_load` captures no longer create empty junk orders that clutter the orders list. Real incomplete orders only appear when a customer actually interacts with the checkout form.

### Technical
- Updated: `guardify.php` — Version bump, removed Discord class loading and default options
- Updated: `includes/class-guardify-settings.php` — Removed Discord submenu, save logic, and render page
- Updated: `includes/class-guardify-abandoned-cart.php` — Skip order creation on page_load with no form data
- Updated: `assets/js/abandoned-cart.js` — page_load now only warms the nonce (no order created)

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

