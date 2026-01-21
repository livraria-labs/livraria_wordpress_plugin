#!/bin/bash

# Development Setup Script for Docker WordPress
# This sets up your development environment for continuous development with Docker

set -e

PLUGIN_NAME="livraria"
SOURCE_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR_NAME="livraria"

echo "🐳 Setting up development environment for Docker WordPress"
echo ""

# Check if container name is provided
if [ -z "$1" ]; then
    echo "Usage: ./dev-setup-docker.sh [container-name]"
    echo ""
    echo "Example:"
    echo "  ./dev-setup-docker.sh wordpress"
    echo "  ./dev-setup-docker.sh my-wp-site"
    echo ""
    echo "To find your container name, run:"
    echo "  docker ps"
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
    echo ""
    echo "Start your container first, or check the name with: docker ps"
    exit 1
fi

echo "Container: $CONTAINER_NAME"
echo "Source directory: $SOURCE_DIR"
echo ""

# Get WordPress plugins path inside container
echo "🔍 Detecting WordPress plugins directory..."
PLUGINS_PATH=$(docker exec "$CONTAINER_NAME" bash -c 'echo $WORDPRESS_PLUGINS_DIR' 2>/dev/null || echo "")

if [ -z "$PLUGINS_PATH" ]; then
    # Try common WordPress paths
    PLUGINS_PATH=$(docker exec "$CONTAINER_NAME" find /var/www/html -name "plugins" -type d 2>/dev/null | head -1 || echo "")
fi

if [ -z "$PLUGINS_PATH" ]; then
    # Default WordPress path
    PLUGINS_PATH="/var/www/html/wp-content/plugins"
fi

TARGET_DIR="$PLUGINS_PATH/$PLUGIN_DIR_NAME"

echo "Plugins path: $PLUGINS_PATH"
echo "Target directory: $TARGET_DIR"
echo ""

# Check if target already exists
if docker exec "$CONTAINER_NAME" test -e "$TARGET_DIR" 2>/dev/null; then
    echo "⚠️  Target directory already exists in container: $TARGET_DIR"
    read -p "Remove existing directory and create symlink? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        docker exec "$CONTAINER_NAME" rm -rf "$TARGET_DIR"
    else
        echo "Aborted."
        exit 1
    fi
fi

# Method 1: Try to create symlink (if source is mounted as volume)
echo "🔗 Attempting to create symlink..."
echo ""

# Check if we can access source from container
if docker exec "$CONTAINER_NAME" test -d "$SOURCE_DIR" 2>/dev/null; then
    # Source is accessible, create symlink
    docker exec "$CONTAINER_NAME" ln -s "$SOURCE_DIR" "$TARGET_DIR"
    echo "✅ Symlink created successfully!"
    echo ""
    echo "📋 Next steps:"
    echo "   1. Go to WordPress admin → Plugins"
    echo "   2. Activate 'Livraria' plugin"
    echo "   3. Start editing files in: $SOURCE_DIR"
    echo "   4. Changes will appear immediately (refresh WordPress page)"
    exit 0
fi

# Method 2: Mount as volume (recommended approach)
echo "💡 Symlink method not available (source not accessible from container)"
echo ""
echo "📦 Recommended: Mount your source directory as a Docker volume"
echo ""
echo "Add this to your docker-compose.yml volumes section:"
echo ""
echo "  volumes:"
echo "    - ${SOURCE_DIR}:/var/www/html/wp-content/plugins/${PLUGIN_DIR_NAME}"
echo ""
echo "Or if using docker run, add:"
echo "  -v ${SOURCE_DIR}:/var/www/html/wp-content/plugins/${PLUGIN_DIR_NAME}"
echo ""
echo "Then restart your container:"
echo "  docker-compose restart"
echo "  # or"
echo "  docker restart $CONTAINER_NAME"
echo ""

# Method 3: Copy files (fallback)
read -p "Would you like to copy files into the container instead? (y/n) " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo "📦 Copying files to container..."
    
    # Create target directory
    docker exec "$CONTAINER_NAME" mkdir -p "$TARGET_DIR"
    
    # Copy files (excluding build, git, etc.)
    docker cp "$SOURCE_DIR/." "${CONTAINER_NAME}:${TARGET_DIR}/"
    
    # Remove files that shouldn't be in WordPress
    docker exec "$CONTAINER_NAME" bash -c "cd $TARGET_DIR && rm -rf .git build *.zip .DS_Store node_modules vendor 2>/dev/null || true"
    
    echo "✅ Files copied successfully!"
    echo ""
    echo "⚠️  Note: You'll need to run this script again after making changes"
    echo "   Or use the volume mount method above for automatic updates"
else
    echo "Setup cancelled."
    exit 1
fi

