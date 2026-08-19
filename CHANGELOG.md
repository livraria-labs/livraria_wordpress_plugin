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

