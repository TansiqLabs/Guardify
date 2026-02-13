# Changelog

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

