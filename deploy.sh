#!/bin/bash

# Livraria Plugin Deployment Script
# Usage: ./deploy.sh [staging|production]

set -e

ENVIRONMENT=${1:-staging}
PLUGIN_NAME="livraria"
BUILD_DIR="build"
VERSION=$(grep "Version:" courier-expedition-wp-plugin.php | cut -d' ' -f3)

echo "🚀 Deploying Livraria Plugin v$VERSION to $ENVIRONMENT"

# Clean build directory
rm -rf $BUILD_DIR
mkdir -p $BUILD_DIR/$PLUGIN_NAME

# Copy plugin files
echo "📦 Copying plugin files..."
cp *.php $BUILD_DIR/$PLUGIN_NAME/
cp -r includes $BUILD_DIR/$PLUGIN_NAME/
cp -r assets $BUILD_DIR/$PLUGIN_NAME/
cp README.md $BUILD_DIR/$PLUGIN_NAME/

# Create WordPress-style readme.txt
echo "📝 Creating readme.txt..."
cat > $BUILD_DIR/$PLUGIN_NAME/readme.txt << 'EOF'
=== Livraria ===
Contributors: yourcompany
Tags: woocommerce, shipping, courier, expedition, logistics
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: trunk
License: GPL v2 or later

Automatically generate shipping expeditions for WooCommerce orders via Livraria API.

== Description ==

This plugin integrates with your Livraria expedition API to automatically create shipping expeditions for WooCommerce orders.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/courier-expedition/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure API settings under Settings > Livraria

== Changelog ==

= 1.0.0 =
* Initial release
* WooCommerce integration
* Automatic expedition creation
* Manual expedition management
EOF

# Environment-specific configuration
if [ "$ENVIRONMENT" = "production" ]; then
    echo "🔧 Configuring for production..."
    # Remove debug code, optimize files
    sed -i '' 's/WP_DEBUG.*true/WP_DEBUG", false/g' $BUILD_DIR/$PLUGIN_NAME/*.php
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