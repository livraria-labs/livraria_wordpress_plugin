# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

**Read first, it changes what shipments cost:** an order is now quoted as ONE
parcel (the summed weight of its products, the largest product's length and
width, the summed height), no longer one parcel per order line. Couriers price
per parcel, so multi-line orders quote lower. The order screen shows the parcel
before quoting; add rows there to send several parcels. Setting the option
`livraria_package_mode` to `per_line` keeps the old one-parcel-per-line rule.
Weights are now converted to kg and dimensions to cm from the shop's units
(g, lbs, oz; m, mm, in, yd): a shop set to grams was quoting 1,000 times the
weight.

### Fixed
- Every failed call to the Livraria API shows the API's own reason on the
  order screen as "<what failed>: <reason>" (for example "Could not create the
  shipment: This quote has expired, please get a new one"), instead of
  "Failed to create expedition". An ended session still asks an administrator
  to log in again.
- The recipient phone is sent the way couriers need it (+40 and 9 digits) for
  every way a Romanian number is typed; a number that cannot be used stops the
  quote with a message naming it, and the order screen offers "Add phone".
  The shipping phone is used first, then the billing phone.
- Address line 2 is added to the street instead of being sent as the street
  number (couriers printed "Bl. D6, sc. A, ap. 5" as the house number).
- The recipient county and city are taken from Livraria's locality list (the
  order's state is used as a hint, and as before when the list cannot be
  reached).
- Cash on delivery is requested only for cash-on-delivery orders (or when an
  amount is typed on the order screen); prepaid orders no longer send a
  zero-amount COD.
- Courier quotes the API marks as unavailable are shown greyed, last, with the
  API's reason and no price, and can never be selected; automatic creation
  picks the cheapest available quote instead of the first one.
- Automatic creation claims the order first, so a repeated status change
  cannot create a second shipment; a shipment is never created for an order
  that already has one. When automatic creation stops, the reason is added to
  the order notes.

### Changed
- The recipient email is optional. When a courier needs it, its quote says so
  and the order screen offers "Add email", which saves the email on the
  WooCommerce order and gets quotes again.
- Order data is read and written through WooCommerce's order API, and the
  plugin declares compatibility with High-Performance Order Storage. Shipments
  recorded by earlier versions stay visible.

## [1.1.0] - 2026-10-28

### Changed
- The merchant's password is never stored. Login uses it once and keeps an
  encrypted refresh token; the access token is renewed with
  `POST /auth/refresh`. The "Remember me" checkbox is gone.
- Shops on 1.0.x that stored their password are moved over on the first API
  call, with no action needed: one login with the stored password, then the
  password is deleted.
- When the session ends (the refresh token expired, or the stored 1.0.x
  password no longer works), administrators see a notice in wp-admin, the order
  screen shows the message instead of its actions, the settings page shows the
  login form, and the site administrator gets one email. Logging in again
  restores everything. Temporary API errors (rate limit, server error, no
  response) fail only that request and keep the session.
- An authenticated request that answers 401 gets one session refresh and one
  retry.
- The settings page shows who the shop is connected as and since when.
- A failed login, including a failed "Test Authentication", no longer ends the
  current session; "Test Authentication" without a password tests the stored
  session.
- Without PHP OpenSSL the refresh token is not stored (nothing is stored in
  clear text): the session then lasts about an hour, and the settings page says so.

## [1.0.4] - 2026-10-28

### Security
- TLS certificates are verified on every request to the Livraria API, including
  the login. Only a development install (`WP_DEBUG` plus `LIVRARIA_ALLOW_INSECURE_TLS`
  defined `true` in `wp-config.php`) can turn verification off.
- Login, logout, the connection tests and settings changes require the
  `manage_options` capability; the order meta box actions require
  `edit_shop_orders`. Before, the nonce printed on every wp-admin page was enough.
- Outside development mode (`WP_DEBUG` plus `LIVRARIA_DEV_MODE` defined `true`)
  the plugin talks only to `https://` on the Livraria API hosts
  (`www.api.livraria.ro`, `api.livraria.ro`, `www.api-uat.livraria.ro`,
  `api-uat.livraria.ro`). A stored URL on those hosts is kept as it is; the
  settings page shows it read-only. A fresh install gets
  `https://www.api.livraria.ro/`. Login saves the URL only after it succeeds and
  refuses a posted URL on any other host.
- A stored URL on any other host is never changed or replaced: the plugin stops
  every API call, drops the API session, shows a wp-admin notice to
  administrators and logs one line with the host, until an administrator logs
  in again from the settings page.
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

