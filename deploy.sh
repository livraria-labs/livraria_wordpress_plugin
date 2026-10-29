#!/bin/bash

# Livraria Plugin Deployment Script
# Usage: ./deploy.sh [staging|production]

set -e

ENVIRONMENT=${1:-staging}
PLUGIN_NAME="livraria"
BUILD_DIR="build"
# Extract version number from plugin header (handles comment format)
# Handles formats like: "Version: 1.0.0" or " * Version: 1.0.0"
VERSION=$(grep -i "Version:" courier-expedition-wp-plugin.php | sed -E 's/.*[Vv]ersion:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*/\1/' | tr -d '[:space:]' | head -1)

# Validate version was extracted
if [ -z "$VERSION" ] || [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "❌ Error: Could not extract valid version number from courier-expedition-wp-plugin.php"
    echo "   Found: '$VERSION'"
    echo "   Make sure the file contains a line like: Version: 1.0.0"
    exit 1
fi

# The LIVRARIA_WC_PLUGIN_VERSION constant (sent to the API in X-Livraria-Client)
# must equal the Version: header line
CONSTANT_VERSION=$(grep -E "define\('LIVRARIA_WC_PLUGIN_VERSION'" courier-expedition-wp-plugin.php | sed -E "s/.*'LIVRARIA_WC_PLUGIN_VERSION',[[:space:]]*'([^']*)'.*/\1/" | head -1)
if [ "$CONSTANT_VERSION" != "$VERSION" ]; then
    echo "❌ Error: LIVRARIA_WC_PLUGIN_VERSION ('$CONSTANT_VERSION') differs from the Version: header ('$VERSION') in courier-expedition-wp-plugin.php"
    exit 1
fi

# Safety: Ensure BUILD_DIR is a relative path (never absolute root paths)
# This prevents accidental deletion of important system directories
if [[ "$BUILD_DIR" =~ ^/ ]]; then
    echo "❌ Error: BUILD_DIR must be a relative path for safety"
    exit 1
fi

echo "🚀 Deploying Livraria Plugin v$VERSION to $ENVIRONMENT"

# Clean build directory
rm -rf $BUILD_DIR
mkdir -p $BUILD_DIR/$PLUGIN_NAME

# Copy plugin files
echo "📦 Copying plugin files..."
echo "ℹ️  Note: This script only modifies the $BUILD_DIR directory. Source repository files are never touched."

if [ "$ENVIRONMENT" = "production" ]; then
    # Production: Copy only production files explicitly, excluding all hidden and dev files
    # IMPORTANT: All operations only affect $BUILD_DIR/$PLUGIN_NAME - source repository is never modified
    echo "🧹 Creating clean production build (excluding hidden files and dev files)..."
    
    # Copy PHP files (excluding deploy.sh and any hidden files)
    # Only copies TO build directory, never modifies source
    for file in *.php; do
        if [ -f "$file" ] && [ "$file" != "deploy.sh" ] && [[ ! "$file" =~ ^\. ]]; then
            cp "$file" $BUILD_DIR/$PLUGIN_NAME/
        fi
    done
    
    # Copy includes directory (excluding hidden files, logs, temp files)
    # rsync only copies TO build directory, source files remain untouched
    if [ -d "includes" ]; then
        rsync -av \
            --exclude='.*' \
            --exclude='*.log' \
            --exclude='*.tmp' \
            --exclude='*.temp' \
            --exclude='*.bak' \
            --exclude='*.backup' \
            --exclude='.DS_Store' \
            --exclude='Thumbs.db' \
            includes/ $BUILD_DIR/$PLUGIN_NAME/includes/
    fi
    
    # Copy assets directory (excluding hidden files, logs, temp files)
    # rsync only copies TO build directory, source files remain untouched
    if [ -d "assets" ]; then
        rsync -av \
            --exclude='.*' \
            --exclude='*.log' \
            --exclude='*.tmp' \
            --exclude='*.temp' \
            --exclude='*.bak' \
            --exclude='*.backup' \
            --exclude='.DS_Store' \
            --exclude='Thumbs.db' \
            assets/ $BUILD_DIR/$PLUGIN_NAME/assets/
    fi
else
    # Staging: Include more files but still exclude some dev files
    rsync -av \
        --exclude='.git' \
        --exclude='.gitignore' \
        --exclude='.DS_Store' \
        --exclude='build' \
        --exclude='*.zip' \
        --exclude='vendor' \
        --exclude='node_modules' \
        --exclude='coverage' \
        --exclude='.phpunit.result.cache' \
        --exclude='.phpunit.cache' \
        --exclude='tests' \
        --exclude='dev-scripts' \
        --exclude='composer.json' \
        --exclude='phpunit.xml' \
        --exclude='testing-checklist.md' \
        --exclude='CHANGELOG.md' \
        --exclude='DEVELOPMENT.md' \
        --exclude='QUICKSTART.md' \
        --exclude='TESTING.md' \
        --exclude='deploy.sh' \
        --exclude='.claude' \
        --exclude='.editorconfig' \
        ./ \
        $BUILD_DIR/$PLUGIN_NAME/
fi

# Create WordPress-style readme.txt
echo "📝 Creating readme.txt..."
cat > $BUILD_DIR/$PLUGIN_NAME/readme.txt << EOF
=== Livraria Shipping for WooCommerce ===
Contributors: livrarialabs
Tags: woocommerce, shipping, courier, expedition, logistics
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: $VERSION
License: GPL v2 or later

Automatically generate shipping expeditions for WooCommerce orders via Livraria API.

== Description ==

This plugin integrates with your Livraria expedition API to automatically create shipping expeditions for WooCommerce orders.

== Installation ==

1. Upload the plugin files to \`/wp-content/plugins/livraria-shipping-for-woocommerce/\`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure API settings under Settings > Livraria

== External Services ==

This plugin connects to the Livraria API to create and manage shipping expeditions for WooCommerce orders.

When an admin creates or manages a shipment from the WooCommerce order screen, the following data is sent to the Livraria API (https://www.api.livraria.ro/): recipient name, phone, email (when the order has one), shipping address or chosen locker, package dimensions, weight, and order number.

When the checkout offers locker delivery, the shop's own server fetches the Sameday and DPD locker lists from the Livraria API (cached for 15 minutes); the buyer's browser talks only to the shop. No buyer data is sent to fetch the list.

Every request to the Livraria API also carries the plugin version and the WordPress, WooCommerce and PHP versions of this site, so that Livraria support can tell which plugin version a shop runs. No other site data is sent.

Service provider: Livraria Hub S.R.L.
- Terms of Service: https://www.livraria.ro/en/terms-and-conditions
- Privacy Policy: https://www.livraria.ro/en/privacy-policy

== Changelog ==

= 1.2.0 =
* Quotes are now for ONE parcel per order (summed weight, largest length and width, summed height), in kg and cm from the shop's units; a computed parcel is at least 1 kg and 10 x 10 x 10 cm. Edit or add parcels on the order screen before quoting
* Lockers: for the shipping methods you tick under Settings > Livraria, buyers choose home or locker delivery at checkout (classic and block checkout), pick the county, then one Sameday easybox or DPD locker from one list
* Order screen: choose the route (courier pickup or drop-off at a locker; the buyer's locker, the order address or another locker), every route is quoted at once under tabs, the cheapest marked
* "Add email", "Add phone", "Add postcode", "Add street", "Add city" save the missing detail on the order and quote again
* Every failed request shows the Livraria API's own reason
* Phone numbers are sent in the format couriers need; a number that cannot be used stops the quote with a clear message
* Address line 2 goes into the street; the county and city come from Livraria's locality list
* Cash on delivery is requested only for cash-on-delivery orders or a typed amount
* Unavailable courier quotes are shown greyed with their reason and can never be selected
* Automatic creation can no longer create a second shipment for the same order
* Works with WooCommerce High-Performance Order Storage
* The order box is redesigned and sits in the main column; the old one-click create request and the quote-selection option are removed

= 1.1.0 =
* The password is no longer stored: the plugin keeps an encrypted session token and renews it automatically
* Shops that stored their password with 1.0.x are moved over automatically and the password is deleted
* When the session ends, wp-admin shows a notice, the order screen shows a message and the site administrator gets one email: log in again under Settings > Livraria
* The settings page shows which account the shop is connected as
* Removed the "Remember me" option

= 1.0.4 =
* Security: TLS certificates are verified on every request to the Livraria API
* Security: only administrators (manage_options) can log in, log out or test the connection; order actions need edit_shop_orders
* Security: the plugin talks only to Livraria API hosts; the settings page shows the API address read-only
* Security: if the stored API address is not a Livraria host, all API calls stop and an administrator must log in again
* Security: the stored password is no longer included in the settings page
* Security: no request data, passwords or tokens in the debug log
* Security: without PHP OpenSSL the password is not stored at all

= 1.0.3 =
* Each request to the Livraria API carries an X-Livraria-Client header with the plugin, WordPress, WooCommerce and PHP versions

= 1.0.2 =
* Fixed: COD and insurance amounts accept bani (e.g. 393.40); a prefilled COD no longer blocks saving the order
* Fixed: package weight accepts two decimals

= 1.0.1 =
* Fixed: Saturday delivery and open on delivery no longer forced on every shipment

= 1.0.0 =
* Initial release
* WooCommerce integration
* Automatic expedition creation
* Manual expedition management
EOF

# Environment-specific configuration
if [ "$ENVIRONMENT" = "production" ]; then
    echo "🔧 Configuring for production build..."
    
    # Clean up build directory only - remove any remaining hidden files
    # All find commands explicitly target $BUILD_DIR/$PLUGIN_NAME - source files are never touched
    echo "   Removing hidden files from build directory..."
    find $BUILD_DIR/$PLUGIN_NAME -name ".*" -type f -delete 2>/dev/null || true
    find $BUILD_DIR/$PLUGIN_NAME -name ".DS_Store" -delete 2>/dev/null || true
    find $BUILD_DIR/$PLUGIN_NAME -name "Thumbs.db" -delete 2>/dev/null || true
    
    # Optimize PHP files in build directory only
    # sed only modifies files in $BUILD_DIR/$PLUGIN_NAME - source repository files remain unchanged
    echo "   Optimizing PHP files in build directory..."
    find $BUILD_DIR/$PLUGIN_NAME -name "*.php" -type f -exec sed -i '' 's/WP_DEBUG.*true/WP_DEBUG", false/g' {} \;
    
    echo "✅ Production build cleaned and optimized (source repository untouched)"
else
    echo "🔧 Configuring for staging..."
fi

# Create deployment package
echo "📦 Creating deployment package..."
cd $BUILD_DIR
zip -r $PLUGIN_NAME-v$VERSION-$ENVIRONMENT.zip $PLUGIN_NAME/
cd ..

echo "✅ Plugin packaged: $BUILD_DIR/$PLUGIN_NAME-v$VERSION-$ENVIRONMENT.zip"

# Optional: Deploy to server
if [ -n "$DEPLOY_HOST" ] && [ -n "$DEPLOY_PATH" ]; then
    echo "🚀 Deploying to server..."
    scp $BUILD_DIR/$PLUGIN_NAME-v$VERSION-$ENVIRONMENT.zip $DEPLOY_HOST:$DEPLOY_PATH/
    
    # SSH and activate plugin
    ssh $DEPLOY_HOST << EOF
cd $DEPLOY_PATH
unzip -o $PLUGIN_NAME-v$VERSION-$ENVIRONMENT.zip
wp plugin activate $PLUGIN_NAME
EOF
    echo "✅ Deployed and activated on $ENVIRONMENT"
else
    echo "ℹ️  Manual upload required: Use the generated ZIP file"
fi

echo "🎉 Deployment complete!"
echo "📋 Next steps:"
echo "   1. Upload and activate plugin in WordPress"
echo "   2. Configure API settings"  
echo "   3. Test with a sample order"
echo "   4. Monitor error logs"