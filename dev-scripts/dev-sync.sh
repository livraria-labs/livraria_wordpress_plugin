#!/bin/bash

# Development Sync Script
# Alternative to symlink: copies files to WordPress for development
# Use this if symlinks don't work or you prefer file copying

set -e

PLUGIN_NAME="livraria"
SOURCE_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR_NAME="livraria"

echo "🔄 Syncing plugin files for development"
echo ""

# Check if WordPress path is provided
if [ -z "$1" ]; then
    echo "Usage: ./dev-sync.sh /path/to/wordpress/wp-content/plugins"
    echo ""
    echo "Example:"
    echo "  ./dev-sync.sh ~/Sites/my-wordpress-site/wp-content/plugins"
    echo ""
    exit 1
fi

WP_PLUGINS_DIR="$1"
TARGET_DIR="$WP_PLUGINS_DIR/$PLUGIN_DIR_NAME"

# Validate WordPress plugins directory exists
if [ ! -d "$WP_PLUGINS_DIR" ]; then
    echo "❌ Error: WordPress plugins directory not found: $WP_PLUGINS_DIR"
    exit 1
fi

echo "Source: $SOURCE_DIR"
echo "Target: $TARGET_DIR"
echo ""

# Create target directory if it doesn't exist
mkdir -p "$TARGET_DIR"

# Copy files (excluding build, git, etc.)
echo "📦 Copying files..."
rsync -av --delete \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='build' \
    --exclude='*.zip' \
    --exclude='.DS_Store' \
    --exclude='node_modules' \
    --exclude='vendor' \
    "$SOURCE_DIR/" "$TARGET_DIR/"

echo ""
echo "✅ Files synced successfully!"
echo ""
echo "💡 Run this script whenever you make changes to sync them to WordPress"
echo "   Or set up a file watcher to run it automatically"

