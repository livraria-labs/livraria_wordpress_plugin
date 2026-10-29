# Livraria WordPress Plugin

This WordPress plugin integrates with your Livraria expedition API to automatically create shipping expeditions for WooCommerce orders.

## Source Code

The complete source code for this plugin is publicly available and maintained at:
**https://github.com/livraria-labs/livraria_wordpress_plugin**

The source code is also included directly in the deployed plugin - all PHP files are unminified and readable.

## Features

- **Automatic Expedition Creation**: Automatically creates expeditions when orders are completed
- **Manual Expedition Creation**: Create expeditions manually from the order admin page
- **Flexible Configuration**: Configure API settings and default sender information
- **WooCommerce Integration**: Seamless integration with WooCommerce orders
- **Tracking Information**: Display AWB numbers and tracking links in order admin

## Installation

1. Copy the plugin files to your WordPress plugins directory: `/wp-content/plugins/courier-expedition/`
2. Activate the plugin through the WordPress admin panel
3. Configure the API settings under **Settings > Courier API**

## Configuration

### API Settings

Navigate to **Settings > Livraria** to configure:

- **Login**: your Livraria account email and password. The password is used once to log in and is never stored; the plugin keeps a session (an encrypted refresh token). Sessions last a few days, then the plugin asks you to log in again: a notice in wp-admin, a message in the order screen and one email to the site administrator. Log in again under Settings > Livraria. The plugin connects only to Livraria API hosts (default `https://www.api.livraria.ro/`); the settings page shows the configured address read-only (it can be changed only in development mode, see DEVELOPMENT.md)
- **Auto-create expeditions**: Enable/disable automatic expedition creation on order completion
- **Default Sender Information**: Configure your company's shipping details

### Default Sender Address Format

The sender address should be provided as JSON:

```json
{
  "country": "Romania",
  "county": "Bucharest", 
  "city": "Bucharest",
  "postcode": "010101",
  "street": "Main Street",
  "streetNumber": "123",
  "block": "A",
  "staircase": "1",
  "floor": "2", 
  "apartment": "3",
  "localityPostcode": "010101"
}
```

## Lockers (1.2.0)

Buyers can have the parcel delivered to a Sameday easybox or a DPD locker.

### Settings

Under **Settings > Livraria**, "Lockers at checkout":

- **Offer easybox Sameday lockers** and **Offer locker DPD lockers**: both on by default.
- **Require locker**: hides home delivery at checkout. It does not decide the gate: a buyer who chose locker delivery without picking a locker cannot place the order either way.
- **Show the locker choice for these shipping methods**: one checkbox per shipping method of your shop (zone and method title, e.g. `free_shipping:1`). The locker choice appears at checkout only while the buyer's chosen shipping rate is one ticked here. **None ticked = no locker choice at checkout**, and nothing is loaded on the checkout.

### Your step

The plugin creates no shipping method. Create a shipping method for locker delivery in **WooCommerce > Settings > Shipping** (for example a flat rate named "Livrare la locker") and tick it in the list above.

### What the buyer sees

On the block checkout under the shipping options, and on the classic (shortcode) checkout after the customer details, in Romanian whatever the shop's language: "Livrare la adresă" or "Livrare la locker"; with locker delivery, the county (județ) first, then the lockers of both couriers in that county in one list, with courier chips, a city filter and a search. The chosen locker shows as "Locker ales: …" with „Schimbă” to change it.

### Locker list refresh

The locker list refreshes in the background through WP-cron; shops that disable WP-cron (`DISABLE_WP_CRON`) should run `wp-cron.php` from a system cron, as WordPress recommends.

### What the order carries

Four order meta keys (HPOS-safe): `_livraria_delivery_mode` (`locker` or `home`), `_livraria_locker_id`, `_livraria_locker_name`, `_livraria_locker_courier` (`sameday` or `dpd`).

### The order box

The **Deliver with Livraria** box on the order screen (main column):

- **Route**: From (courier pickup at the sender address, or drop-off at a locker, the nearest suggested) and To (the buyer's locker, the order's shipping address, or a locker chosen in the box).
- **Get quotes** asks every route the chosen ends allow at once; the quotes show under tabs (All, Door to door, To a locker, From a locker, Locker to locker) with "· Cheapest" on the route of the lowest usable quote. Unavailable quotes stay visible, greyed, with the reason. The cheapest usable quote is preselected; the action reads "Create AWB · courier · price".
- A **UAT** badge shows when the plugin is connected to the Livraria test API.
- A locker pick the plugin cannot use (no courier recorded, or a courier other than Sameday and DPD) is named in a banner; nothing is quoted until you choose **Ship to the order address instead** (undo brings the buyer's pick back).
- When a quote needs a detail the order lacks, **Add email**, **Add phone**, **Add postcode**, **Add street** or **Add city** saves it on the WooCommerce **order** (never on the Livraria side) and asks the quotes again.

### Upgrading from 1.1.0

- Quotes are for **one parcel per order** now (summed weight, largest length and width, summed height, in kg and cm, never below 1 kg and 10 x 10 x 10 cm). Edit or add parcels in the box before quoting; the option `livraria_package_mode = per_line` keeps one parcel per order line.
- The old one-click create request (`livraria_create_expedition`) and the `courier_quote_selection` option are gone.
- The box moved from the sidebar to the main column (new box id `livraria-shipping`).
- Nothing changes at checkout until a shipping method is ticked under "Show the locker choice for these shipping methods".

## API Integration

The plugin integrates with your courier API through the following endpoints:

### 1. Get Available Couriers
`GET /public/couriers`

Fetches the list of available couriers and their IDs dynamically. Response cached for 1 hour.

### 2. Create Quote Request & Get Courier Quotes
`POST /public/awb/quotes`

Creates a quote request with sender, recipient, package, and service details using dynamically fetched courier IDs. Returns both the quote request and available courier quotes in a single response:
```json
{
  "quoteRequest": { "id": "...", ... },
  "courierQuotes": [ { "id": "...", "courierName": "...", "amount": "..." }, ... ]
}
```

### 3. Create Expedition
`POST /expedition`

Creates the actual expedition with selected quote and billing information.

## Workflow

1. **Order Completion**: When a WooCommerce order is marked as completed (and auto-create is enabled)
2. **Quote Request**: Plugin creates a quote request with order details
3. **Get Quotes**: Retrieves available courier quotes
4. **Select Quote**: Automatically selects the cheapest usable quote (to the buyer's locker when the order has one)
5. **Create Expedition**: Creates the expedition with billing information
6. **Store Data**: Saves expedition ID and AWB number to order meta
7. **Order Note**: Adds a note to the order with expedition details

## Manual Usage

For manual expedition creation:

1. Go to **WooCommerce > Orders**
2. Edit an order
3. Find the **Deliver with Livraria** box under the order items
4. Click **Get quotes**, choose a quote, then **Create AWB**

## Order Meta Fields

The plugin stores the following meta fields on orders:

- `_courier_expedition_id`: The expedition ID from the API
- `_courier_awb_number`: The AWB/tracking number
- `_courier_tracking_url`: The tracking URL (if provided)

## Error Handling

The plugin includes comprehensive error handling:

- API connection errors are logged to WordPress error log
- Failed expeditions display error messages in admin
- Duplicate expedition creation is prevented
- Missing WooCommerce dependency is detected

## Customization

### Quote selection

The order box shows every quote and preselects the cheapest usable one; the merchant can select another. Automatic creation ships with the cheapest usable quote.

### Package Dimensions

By default an order is one parcel worked out from the products (see "Upgrading from 1.1.0"). Products without a weight or a dimension count with the defaults: 1 kg, 10 x 10 x 10 cm.

## Troubleshooting

### Common Issues

1. **No expeditions created**: Check API credentials and URL in settings
2. **Missing AWB numbers**: AWB generation is asynchronous - numbers appear after processing
3. **Weight calculation errors**: Ensure products have weight values set
4. **JSON format errors**: Validate sender address JSON format

### Debug Information

Enable WordPress debug logging by adding to `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

API errors will be logged to `/wp-content/debug.log`.

## Security

- All API requests include authentication headers and verify the API's TLS certificate
- AJAX requests are protected with WordPress nonces and a capability check: `manage_options` for settings, login, logout and the connection tests; `edit_shop_orders` for order actions
- Settings are properly escaped and sanitized
- Only administrators can configure plugin settings
- The password is never stored. The session's refresh token is stored encrypted (never without PHP OpenSSL: without it you log in again about every hour) and never shown on the settings page
- Debug logging (development mode only) masks passwords and tokens

## Compatibility

- **WordPress**: 5.0+
- **WooCommerce**: 3.0+
- **PHP**: 7.4+

## Development

### Source Code Access

The complete source code is available at:
- **GitHub Repository**: https://github.com/livraria-labs/livraria_wordpress_plugin
- **Source Code**: Included in the deployed plugin (all files are unminified)

### Build Tools

This plugin uses the following development tools:

#### Composer (PHP Dependency Management)
```bash
# Install dependencies
composer install

# Install development dependencies (PHPUnit)
composer install --dev
```

#### PHPUnit (Testing)
```bash
# Run tests
composer test
# or
vendor/bin/phpunit

# Run tests with coverage
composer test-coverage
```

See [TESTING.md](TESTING.md) for detailed testing documentation.

#### Deployment Script
```bash
# Build plugin package
./deploy.sh staging
# or
./deploy.sh production
```

The deployment script (`deploy.sh`) creates a WordPress-ready ZIP file in the `build/` directory.

### Development Setup

For local development with Docker, see:
- [QUICKSTART.md](QUICKSTART.md) - Quick setup guide
- [DEVELOPMENT.md](DEVELOPMENT.md) - Comprehensive development guide
- [dev-scripts/](dev-scripts/) - Development helper scripts

## Support

For issues related to:
- **Plugin functionality**: Check WordPress error logs and ensure proper configuration
- **API integration**: Verify your courier API credentials and endpoints
- **WooCommerce compatibility**: Ensure WooCommerce is active and up to date
- **Source code**: Visit https://github.com/livraria-labs/livraria_wordpress_plugin