# Flexiown (teljoy-flexiown) — Upgrade, API & Integration Notes

This README documents the upgrade from the original Teljoy gateway (`teljoy-woocommerce-payment-gateway`) to the new Flexiown implementation (`teljoy-flexiown`). It explains notable changes, available REST endpoints, shortcodes/Elementor widgets, admin settings, installation/migration steps and troubleshooting tips.

## TL;DR — Migration summary

- `teljoy-woocommerce-payment-gateway` was migrated and reworked into `teljoy-flexiown`.
- Core changes:
  - Improved log parsing and a `/wp-json/fo/v1/logbook` endpoint that reads WooCommerce log files named `*flexiown*.log`.
  - Moved inline JS out of cart notices (now printed in footer) to avoid rendering script text inside notice boxes.
  - Visual fixes: CSS fallbacks for rejected cart rows (legacy & blocks), improved blocks-cart matching. 
  - Elementor integration: new widget category "Flexiown" with two widgets that render the existing shortcodes.
  - REST endpoints for stores and orders; orders endpoint returns only orders paid/initiated via Flexiown and includes Flexiown custom fields.
  - Security/hardening: transaction payloads now constructed securely (sanitized and `json_encode()`), REST endpoints use header auth (X-API-Key) where configured.
  - Compliance with WooCommerce, Wordpress, Elementor and php best practices as of the date of this document, with backwards compatibility to earlier versions as well as block layout support for new versions.

## What's new / important changes

- Naming: plugin folder and main plugin file renamed from `teljoy-woocommerce-payment-gateway` → `teljoy-flexiown`.
- Logging: `/logbook` endpoint parses WooCommerce logs, groups multiline entries, decodes JSON fragments when present and returns normalized dates and `source_file`.
- Cart UX: rejected items are highlighted reliably in both legacy and blocks carts; fallback matching implemented when `data-key` is missing.
- Elementor: conditional integration that registers a "Flexiown" widget category and two widgets (payment widget and store location dropdown) only when Elementor is active.
- REST API: new namespace `fo/v1` with endpoints described below. Protected endpoints require `X-API-Key` header matching `merchant_api_key` in admin settings.
- Transaction payloads: rebuilt to use proper sanitization and `json_encode()` to avoid JSON/quote injection while preserving the original object structure.

## REST API (quick reference)
Base: `https://your-site.example/wp-json/fo/v1/`

Protected endpoints require header:
- `X-API-Key: <merchant_api_key>`

Endpoints:
- GET `/logbook`
  - Query: `timestamp` (optional ISO date) — return entries newer than timestamp
  - Returns: `[{ date, level, message, source_file }, ...]`
  - Note: `message` may be a decoded JSON object/array or string.

- GET `/stores`
  - Query: `per_page` (int), `page` (int), `search` (string)
  - Returns: store_location CPT items `{ id, title, content, date, modified, status, permalink }`

- GET `/orders`
  - Query: `status` (string), `per_page` (int), `page` (int)
  - Returns: orders filtered to Flexiown payment method and includes Flexiown custom fields:
    - `flexiown_redirect_url`, `flexiown_store_location`, `flexiown_transaction_id`, `flexiown_trust_seed`, `is_vat_exempt`
  - Pagination headers: `X-WP-Total`, `X-WP-TotalPages`

## Shortcodes & Elementor
- Shortcodes (unchanged behaviour):
  - `[flexiown_widget]` — product/payment widget
  - `[store_location_dropdown]` — outputs store selector (only if store locator enabled)
- Elementor:
  - If Elementor is active, a Flexiown widget category is registered with two widgets that render the above shortcodes.
  - Widgets load only when Elementor is present to avoid fatal errors on sites without Elementor.

## Admin Settings (what to check after upgrade)
Available in WooCommerce → Settings → Checkout → Flexiown. Key settings:
- Enable / Disable gateway
- Title / Description (checkout display)
- Api Key (`merchant_api_key`) — REQUIRED for protected REST endpoints
- Staging vs Production
- Hold stock override (`flexiown_stock_hold`)
- Product widget (`enable_product_widget`)
- Display on cart (`flexiown_on_cart`)
- Cart calculation mode (`flexiown_cart_as_combined`)
- Product barcode (`enable_product_barcode`)
- Cart warning system (`enable_cart_warnings`)
- Enable Logging (`enable_logging`)
- Debug email settings (`send_debug_email`, `debug_email`)
- Disable guest order persistence - a test attempt to see if we can break the quest order caching issue
- Enable Agent Mode - optional for testing new ui

## Logs & debugging
- Plugin writes to WooCommerce logger files in `wp-content/uploads/wc-logs/` when logging is enabled.
- The `/logbook` endpoint reads `*flexiown*.log` files only.
- For debugging endpoints: confirm `merchant_api_key` matches header `X-API-Key` and permalinks have been flushed (Settings → Permalinks → Save).

## Security & best-practices implemented
- Transaction payloads: sanitized via WP sanitizers and encoded with `json_encode()` to avoid injection and malformed JSON.
- REST endpoints: protected via API key header check (admin setting). Consider enabling additional checks (IP allowlist, rate limiting).
- Admin forms: ensure nonces are used for future changes (if you modify settings code).
- Logs: avoid writing secrets to logs; debug logs should be subject to access control.

## Performance notes
- The plugin does not enable persistent response caching using transients by default. Any API calls are made on demand.
- The code attempts to avoid unnecessary repeated API calls by limiting when lookups occur, but it does not ship with a built-in transient-based cache. If you need caching, add an object-cache, reverse-proxy, or implement a custom transient/cache layer via the provided hooks, this however would require discussion to prevent stagnant caching/
- Queries have been optimized and major functions refactored for more optimal performance
- An improved seperation of concerns has been implemented
- The different util functions now only fire off when they are enabled, reducing plugin performance impact further
- Version support checking has been implementd with overall security hardening.

## Installation / Upgrade steps (recommended)
1. Ensure system requirements:
   - PHP >= 7.4
   - WordPress >= 5.0
   - WooCommerce >= 8.0
2. Backup site files and DB.
3. Deactivate the old teljoy plugin if installed.
4. Upload `teljoy-flexiown` plugin folder and activate or install via the WP admin plugin installer (zip).
5. Go to WooCommerce → Settings → Payments → Flexiown:
   - Set `merchant_api_key`
   - Configure staging/production modes
   - Enable logging (optional - most logs will only write when in staging to reduce prod performance issues)
6. Flush permalinks (Settings → Permalinks → Save Changes).
7. If using Elementor, open editor to confirm Flexiown widget category appears.

Important: the gateway will not function for live payments until a valid merchant API key is configured.

Notes:
- Keep both plugins deactivated simultaneously during migration to avoid class/name collisions.
- If you previously used teljoy plugin shortcodes, they are preserved where possible; confirm theme templates after upgrade.
- Clear Cache may need to be done on individual pages if changes arent showing before logging a bug.

## Developer notes & file map (high level)
- Main plugin: `teljoy-flexiown.php`
- Includes: `includes/` (util functions, webhooks, widgets, store selector, admin)
- Admin: `includes/admin/settings.php`, `menu.php`, `config.php`
- Elementor: `includes/elementor-widgets/` and `class-flexiown-elementor.php` (conditional load)
- Legacy teljoy plugin: `teljoy-woocommerce-payment-gateway/` remains in repo for reference (first plugin).

## Shortcodes
- `[flexiown_widget]` — product widget (renders Flexiown price and link). Can be used in page builders or in templates when `is_using_page_builder` is enabled.
- `[store_location_dropdown]` — (when `flexiown_store_locator` is enabled) outputs the store locations dropdown.

## Elementor
- The plugin includes Elementor widget classes (see `elementor-widgets/`) and registers Elementor integrations; use the editor to add the Flexiown widgets if you use Elementor.

## Admin settings (what each option does)
Settings are available in WooCommerce → Settings → Checkout → Flexiown.

- `Enable/Disable` (enabled)
  - Turns the Flexiown gateway on/off in WooCommerce checkout.

- `Title`
  - Label shown to customers on the checkout payment methods list.

- `Description`
  - Text shown under the payment method title at checkout.

- `Api Key (merchant_api_key)`
  - REQUIRED for the gateway to operate and for protected REST endpoints. Set this to the merchant key provided by Flexiown.

- `Flexiown Staging`
  - Place the gateway into staging/production mode.

- `Hold stock (minutes) override` (`flexiown_stock_hold`)
  - Controls how long stock for unpaid orders is held (in minutes). When the plugin verifies merchant status it may override WooCommerce hold time, it receives seconds from the api and converts it to minutes for wordpress, this button also no longer uses step intervals to adjust minutes.

- `Product Page Widget` (`enable_product_widget`)
  - Enables the product page Flexiown widget. When enabled, the widget displays Flexiown price and a link/modal on product pages.

- `Display on Cart` (`flexiown_on_cart`)
  - When enabled, displays a cart-level Flexiown banner showing a representative monthly price. There are separate implementations for legacy (PHP-rendered) and blocks-based cart.

- `Set cart rate based on total instead of lowest` (`flexiown_cart_as_combined`)
  - When enabled, the cart rate is calculated from the combined price rather than the lowest item price.

- `Enable Product Barcode` (`enable_product_barcode`)
  - Adds a barcode field to product edit pages used by the product payload sent to Flexiown.

- `Enable Cart warning system` (`enable_cart_warnings`)
  - When enabled the cart will be scanned and any items not accepted by Flexiown will be highlighted with a grey background and a notice will show with a "Checkout with Flexiown" button that can remove rejected items.

- `Enable Logging` (`enable_logging`)
  - Enable writing logs for debug/troubleshooting; logs are written to WooCommerce log files and visible via the `/logbook` endpoint.

- `Send Debug Emails` (`send_debug_email`) and `Who Receives Debug E-mails?` (`debug_email`)
  - When enabled the plugin sends debug emails to the configured address on certain events or errors.

- `Disable Guest Order Persistence`
  - When enabled, disallows persistent cart features for guest users (optional behavior).

- `Enable Agent Mode`
  - Enables agent mode features (advanced/partner behaviour).

## Troubleshooting & notes
- If you see JavaScript printed in cart notices or old images, clear caches. The upgrade moved inline script output to the footer to avoid notice text rendering the script.
- Blocks cart highlighting: the plugin tries `data-key` attribute first; if that is not present it falls back to matching product permalink or slug. If your theme uses unusual cart markup, enable browser console logs or provide the theme HTML for a small tweak.
- Logs can be viewed via the WordPress log files (WooCommerce logger) or the plugin's `/wp-json/fo/v1/logbook` endpoint (requires merchant API key header for protected access).
- `/wp-json/` returns 404:
  - Try `https://your-site.example/?rest_route=/` to detect permalink or server rewrite issues.
  - Flush permalinks and temporarily disable security plugins that may block REST.
- Custom REST endpoints return 403/401:
  - Ensure `X-API-Key` header is present and matches `merchant_api_key`.
- Elementor fatal error (Class not found):
  - Ensure Elementor is active. The integration only registers widget classes when Elementor is present.
- Logbook returns "file_not_found":
  - Ensure logging enabled and files exist in `wp-content/uploads/wc-logs/` and filenames include `flexiown`.

## Contact & support
For integration questions or to obtain your merchant API key, contact Flexiown support or consult the Flexiown docs.

---
Generated on: September 9, 2025
