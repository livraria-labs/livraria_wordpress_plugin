#!/bin/bash

# Development Setup Script for Livraria Plugin
# This sets up your development environment for continuous development

set -e

PLUGIN_NAME="livraria"
SOURCE_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR_NAME="livraria"

echo "🔧 Setting up development environment for Livraria Plugin"
echo ""

# Check if WordPress path is provided
if [ -z "$1" ]; then
    echo "Usage: ./dev-setup.sh /path/to/wordpress/wp-content/plugins"
    echo ""
    echo "Example:"
    echo "  ./dev-setup.sh ~/Sites/my-wordpress-site/wp-content/plugins"
    echo ""
    exit 1
fi

WP_PLUGINS_DIR="$1"
TARGET_DIR="$WP_PLUGINS_DIR/$PLUGIN_DIR_NAME"

# Validate WordPress plugins directory exists
if [ ! -d "$WP_PLUGINS_DIR" ]; then
    echo "❌ Error: WordPress plugins directory not found: $WP_PLUGINS_DIR"
    echo ""
    echo "Please provide the full path to your WordPress wp-content/plugins directory"
    exit 1
fi

echo "Source directory: $SOURCE_DIR"
echo "Target directory: $TARGET_DIR"
echo ""

# Check if target already exists
if [ -e "$TARGET_DIR" ]; then
    echo "⚠️  Target directory already exists: $TARGET_DIR"
    read -p "Remove existing directory and create symlink? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        rm -rf "$TARGET_DIR"
    else
        echo "Aborted."
        exit 1
    fi
fi

# Create symlink
echo "🔗 Creating symlink..."
ln -s "$SOURCE_DIR" "$TARGET_DIR"

if [ $? -eq 0 ]; then
    echo "✅ Symlink created successfully!"
    echo ""
    echo "📋 Next steps:"
    echo "   1. Go to WordPress admin → Plugins"
    echo "   2. Activate 'Livraria' plugin"
    echo "   3. Start editing files in: $SOURCE_DIR"
    echo "   4. Changes will appear immediately (refresh WordPress page)"
    echo ""
    echo "💡 Tip: To remove the symlink later, run:"
    echo "   rm $TARGET_DIR"
else
    echo "❌ Failed to create symlink"
    exit 1
fi

