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
Tested up to: 6.9
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

When an admin creates or manages a shipment from the WooCommerce order screen, the following data is sent to the Livraria API (https://api.livraria.ro/): recipient name, shipping address, package dimensions, weight, and order number.

Service provider: Livraria Hub S.R.L.
- Terms of Service: https://www.livraria.ro/en/terms-and-conditions
- Privacy Policy: https://www.livraria.ro/en/privacy-policy

== Changelog ==

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