# Test Suite

This directory contains automated tests for the Livraria WordPress plugin.

## Quick Start

### Running Tests in Docker (Recommended)

If your WordPress is running in Docker:

```bash
./tests/run-tests-docker.sh
```

This script will:
1. Check if the WordPress container is running
2. Install Composer in the container (if needed)
3. Install test dependencies
4. Run PHPUnit tests

### Running Tests Locally

If you have PHP and Composer installed locally:

```bash
# Install dependencies
composer install

# Run all tests
composer test

# Run with coverage
composer test-coverage
```

## Test Structure

- `unit/` - Unit tests (fast, isolated, mock dependencies)
- `integration/` - Integration tests (WordPress + database)

## Writing New Tests

1. Create test file in appropriate directory
2. Extend `Livraria_TestCase`
3. Use descriptive test method names: `test_what_it_does()`
4. Follow Arrange-Act-Assert pattern

See `TESTING.md` in project root for detailed documentation.

## Troubleshooting

**Docker not running:**
```bash
cd /path/to/wp-local
docker-compose up -d
```

**Tests fail with "WordPress test suite not found":**
You need to set up the WordPress test suite. See `TESTING.md` for instructions.

**Composer not found in container:**
The `run-tests-docker.sh` script will automatically install Composer if needed.
