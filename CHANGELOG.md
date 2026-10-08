# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

