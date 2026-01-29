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

- **API Base URL**: Your courier API base URL (e.g., `https://api.yourservice.com`)
- **API Token**: Your authentication token for the courier API
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
4. **Select Quote**: Automatically selects the first available quote
5. **Create Expedition**: Creates the expedition with billing information
6. **Store Data**: Saves expedition ID and AWB number to order meta
7. **Order Note**: Adds a note to the order with expedition details

## Manual Usage

For manual expedition creation:

1. Go to **WooCommerce > Orders**
2. Edit an order
3. Find the **Courier Expedition** metabox in the sidebar
4. Click **Create Expedition** button

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

### Selecting Different Courier Quotes

By default, the plugin selects the first available quote. To customize this behavior, modify the `create_expedition_for_order()` method:

```php
// Instead of using the first quote:
$selected_quote = $quotes_response[0];

// Add custom logic, e.g., cheapest quote:
$selected_quote = array_reduce($quotes_response, function($carry, $quote) {
    return (!$carry || $quote['amount'] < $carry['amount']) ? $quote : $carry;
});
```

### Package Dimensions

Default package dimensions can be customized in the `prepare_quote_request_data()` method. Currently uses:
- Width: 10cm
- Height: 10cm  
- Length: 10cm
- Weight: From product data or 1kg default

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

- All API requests include authentication headers
- AJAX requests are protected with WordPress nonces
- Settings are properly escaped and sanitized
- Only administrators can configure plugin settings

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