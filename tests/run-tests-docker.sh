#!/bin/bash
# Run PHPUnit tests inside Docker WordPress container

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
WP_LOCAL_DIR="$(dirname "$PLUGIN_DIR")/wp-local"

echo "Running tests in Docker container..."

# Check if docker-compose is available
if ! command -v docker-compose &> /dev/null && ! command -v docker &> /dev/null; then
    echo "Error: Docker is not available"
    exit 1
fi

# Use docker-compose if available, otherwise docker compose
if command -v docker-compose &> /dev/null; then
    DOCKER_COMPOSE="docker-compose"
else
    DOCKER_COMPOSE="docker compose"
fi

cd "$WP_LOCAL_DIR"

# Check if container is running
if ! $DOCKER_COMPOSE ps wordpress | grep -q "Up"; then
    echo "Error: WordPress container is not running"
    echo "Please start it with: cd $WP_LOCAL_DIR && $DOCKER_COMPOSE up -d"
    exit 1
fi

# Install Composer in container if not already installed
echo "Checking for Composer in container..."
$DOCKER_COMPOSE exec -T wordpress bash -c "
    if ! command -v composer &> /dev/null; then
        echo 'Installing Composer...'
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    fi
"

# Install PHPUnit dependencies
echo "Installing test dependencies..."
$DOCKER_COMPOSE exec -T -w /var/www/html/wp-content/plugins/livraria wordpress bash -c "
    if [ ! -d vendor ]; then
        composer install --no-interaction
    fi
"

# Run tests
echo "Running PHPUnit tests..."
echo "Running unit tests with mocked WordPress functions..."
$DOCKER_COMPOSE exec -T -w /var/www/html/wp-content/plugins/livraria wordpress bash -c "
    if [ -f vendor/bin/phpunit ]; then
        vendor/bin/phpunit
    elif [ -f ./vendor/bin/phpunit ]; then
        ./vendor/bin/phpunit
    else
        echo 'Error: PHPUnit not found. Run: composer install'
        exit 1
    fi
"
