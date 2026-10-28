# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.4] - 2026-10-28

### Security
- TLS certificates are verified on every request to the Livraria API, including
  the login. Only a development install (`WP_DEBUG` plus `LIVRARIA_ALLOW_INSECURE_TLS`
  defined `true` in `wp-config.php`) can turn verification off.
- Login, logout, the connection tests and settings changes require the
  `manage_options` capability; the order meta box actions require
  `edit_shop_orders`. Before, the nonce printed on every wp-admin page was enough.
- The API base URL is fixed to `https://www.api.livraria.ro/` outside development
  mode (`WP_DEBUG` plus `LIVRARIA_DEV_MODE` defined `true`): a posted URL is
  ignored, the settings page has no URL field, and login saves the URL only
  after it succeeds. On load, the previous default `https://api.livraria.ro/`
  is rewritten to the www address silently (the API session is kept); a stored
  URL that points anywhere else is reset to the production host, the API
  session is dropped and one line is logged with the host it replaced.
- The username, password and API URL are no longer registered settings, so
  `options.php` cannot write them (a password in clear text, or another host);
  only the login writes them.
- The stored password is never written into the settings page; the page shows
  only whether one is stored, and an empty password field means the stored one,
  used server side. The "Copy password" button is removed.
- Debug logging runs only in development mode and masks passwords and tokens.
  Request bodies, the login request and response, and order, recipient and
  sender profile data are no longer logged on shops; error lines carry the HTTP
  status and the API's message, never a body.
- Without PHP OpenSSL the password is no longer stored in clear text: nothing is
  stored and the settings page says why "Remember me" is unavailable.

### Changed
- Development mode no longer turns on from `WP_DEBUG` alone, from the site
  address (localhost, .local, .dev, staging) or from a `dev-scripts/` folder.

## [1.0.3] - 2026-10-28

### Added
- Every request to the Livraria API sends an `X-Livraria-Client` header:
  `Livraria-WordPress-Plugin/<plugin version>; WordPress/<version>; WooCommerce/<version or unknown>; PHP/<version>`.
  The User-Agent is unchanged (`WordPress-Livraria-Plugin/1.0`).
- `LIVRARIA_WC_PLUGIN_VERSION` constant, equal to the `Version:` header; `deploy.sh`
  stops when the two differ. Asset cache-busting versions use it.

## [1.0.1] - 2026-08-19

### Fixed
- Saturday Delivery and Open on Delivery were always sent as enabled, even when
  the checkboxes were left unchecked. The unchecked boolean `false` was serialized
  by jQuery as the string `"false"`, which PHP's `(bool)` cast treats as `true`,
  so every modal-created quote/expedition silently requested (and was billed for)
  these surcharge services. `order.js` now sends `1`/`0` and the server sanitizer
  uses `filter_var(..., FILTER_VALIDATE_BOOLEAN)`.

## [1.0.2] - 2026-10-08

### Fixed
- COD and insurance amounts accept bani (step 0.01 instead of 0.5); a COD prefilled from an order total such as 393.40 no longer blocks saving the WooCommerce order
- Package weight accepts step 0.01
- Plugin fields are no longer `required`, so they cannot block the WooCommerce order form; Get Quotes keeps its own validation

## [1.0.1] - 2026-08-19

### Fixed
- Saturday delivery and open on delivery are no longer forced on every shipment

## [1.0.0] - 2024-XX-XX

### Added
- Initial release
- WooCommerce integration for automatic expedition creation
- Manual expedition creation from order admin page
- API settings page with authentication configuration
- Support for multiple packages per expedition
- COD (Cash on Delivery) and insurance options
- Saturday delivery and open on delivery options
- AWB number and tracking URL storage
- Test API connection functionality
- Network connectivity testing

