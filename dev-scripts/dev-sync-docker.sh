#!/bin/bash

# Development Sync Script for Docker WordPress
# Copies files to Docker container for development

set -e

PLUGIN_NAME="livraria"
SOURCE_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR_NAME="livraria"

echo "🔄 Syncing plugin files to Docker container"
echo ""

# Check if container name is provided
if [ -z "$1" ]; then
    echo "Usage: ./dev-sync-docker.sh [container-name]"
    echo ""
    echo "Example:"
    echo "  ./dev-sync-docker.sh wordpress"
    echo ""
    exit 1
fi

CONTAINER_NAME="$1"

# Check if container exists and is running
if ! docker ps --format "{{.Names}}" | grep -q "^${CONTAINER_NAME}$"; then
    echo "❌ Error: Container '$CONTAINER_NAME' is not running"
    echo ""
    echo "Available containers:"
    docker ps --format "  {{.Names}}"
    exit 1
fi

# Get WordPress plugins path
PLUGINS_PATH=$(docker exec "$CONTAINER_NAME" find /var/www/html -name "plugins" -type d 2>/dev/null | head -1 || echo "/var/www/html/wp-content/plugins")
TARGET_DIR="$PLUGINS_PATH/$PLUGIN_DIR_NAME"

echo "Container: $CONTAINER_NAME"
echo "Source: $SOURCE_DIR"
echo "Target: $TARGET_DIR"
echo ""

# Create target directory
docker exec "$CONTAINER_NAME" mkdir -p "$TARGET_DIR"

# Create temporary directory for clean copy
TEMP_DIR=$(mktemp -d)
trap "rm -rf $TEMP_DIR" EXIT

# Copy files excluding build, git, etc.
rsync -av --delete \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='build' \
    --exclude='*.zip' \
    --exclude='.DS_Store' \
    --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='*.md' \
    --exclude='*.sh' \
    --exclude='.editorconfig' \
    "$SOURCE_DIR/" "$TEMP_DIR/"

# Copy to container
echo "📦 Copying files to container..."
docker cp "$TEMP_DIR/." "${CONTAINER_NAME}:${TARGET_DIR}/"

echo ""
echo "✅ Files synced successfully!"
echo ""
echo "💡 Run this script whenever you make changes"
echo "   Or set up a volume mount for automatic updates (see dev-setup-docker.sh)"

