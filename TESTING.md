# Automated Testing Guide for Livraria WordPress Plugin

This guide explains how to write and run automated tests for the plugin.

## Overview

We use **PHPUnit** with the **WordPress Test Suite** for automated testing. This allows us to:
- Test individual classes and methods (unit tests)
- Test WordPress hooks and database interactions (integration tests)
- Mock external API calls
- Ensure code quality and prevent regressions

## Test Structure

```
tests/
├── bootstrap.php              # Test environment setup
├── TestCase.php               # Base test case class
├── unit/                      # Unit tests (isolated, fast)
│   ├── API_Client_Test.php
│   ├── Order_Handler_Test.php
│   └── Admin_Page_Test.php
└── integration/               # Integration tests (WordPress + DB)
    ├── Plugin_Activation_Test.php
    └── WooCommerce_Integration_Test.php
```

## Setup

### 1. Install Dependencies

```bash
composer require --dev phpunit/phpunit wp-browser/wp-browser
```

Or manually install:
- PHPUnit 9.x or 10.x
- WordPress Test Suite (via WP-CLI or manual download)

### 2. Configure PHPUnit

Create `phpunit.xml` in the project root (see example below).

### 3. Set Up WordPress Test Environment

You need a separate test database. Options:

**Option A: Using WP-CLI (Recommended)**
```bash
wp scaffold plugin-tests livraria
```

**Option B: Manual Setup**
1. Create a test database
2. Download WordPress test suite
3. Configure `wp-tests-config.php`

## Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test file
vendor/bin/phpunit tests/unit/API_Client_Test.php

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/

# Run specific test method
vendor/bin/phpunit --filter test_login_success
```

## Writing Tests

### Unit Test Example

```php
<?php
class API_Client_Test extends TestCase {
    
    public function test_login_success() {
        // Arrange
        $client = new Livraria_API_Client('http://test-api.com');
        
        // Mock wp_remote_request
        $mock_response = array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'accessToken' => 'test-token',
                'expiresIn' => 3600
            ))
        );
        
        // Act
        $result = $client->login('user', 'pass');
        
        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('test-token', $client->get_token());
    }
}
```

### Integration Test Example

```php
<?php
class WooCommerce_Integration_Test extends TestCase {
    
    public function test_order_completion_triggers_expedition() {
        // Create test order
        $order = wc_create_order();
        $order->set_status('completed');
        $order->save();
        
        // Verify expedition was created
        $expedition_id = get_post_meta($order->get_id(), '_courier_expedition_id', true);
        $this->assertNotEmpty($expedition_id);
    }
}
```

## Mocking Strategies

### Mocking API Calls

Since we can't make real API calls in tests, we mock `wp_remote_request`:

```php
// In your test
add_filter('pre_http_request', function($preempt, $args, $url) {
    if (strpos($url, '/auth/login') !== false) {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array('accessToken' => 'mock-token'))
        );
    }
    return $preempt;
}, 10, 3);
```

### Mocking WordPress Functions

Use WordPress test utilities:
- `WP_UnitTestCase` provides WordPress functions
- `$this->factory->post->create()` for test posts
- `$this->factory->user->create()` for test users

## Test Categories

### Unit Tests
- Test individual methods in isolation
- Mock all dependencies
- Fast execution
- Examples: API client methods, data parsing, validation

### Integration Tests
- Test WordPress hooks and filters
- Test database interactions
- Use real WordPress functions
- Examples: Plugin activation, order processing, settings save

### Functional Tests
- Test complete workflows
- May require WooCommerce
- Slower execution
- Examples: Full order-to-expedition flow

## Best Practices

1. **Test One Thing**: Each test should verify one behavior
2. **Use Descriptive Names**: `test_login_fails_with_invalid_credentials()` not `test_login()`
3. **Arrange-Act-Assert**: Structure tests clearly
4. **Mock External Dependencies**: Don't make real API calls
5. **Clean Up**: Use `setUp()` and `tearDown()` methods
6. **Test Edge Cases**: Empty values, null, invalid formats
7. **Test Error Handling**: What happens when things go wrong?

## Continuous Integration

Add to `.github/workflows/tests.yml`:

```yaml
name: Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - uses: php-actions/composer@v1
      - name: Run tests
        run: vendor/bin/phpunit
```

## Debugging Tests

```bash
# Verbose output
vendor/bin/phpunit --verbose

# Stop on first failure
vendor/bin/phpunit --stop-on-failure

# Filter by group
vendor/bin/phpunit --group api
```

## Coverage Goals

Aim for:
- **Critical paths**: 80%+ coverage
- **API client**: 90%+ coverage
- **Order handler**: 80%+ coverage
- **Overall**: 70%+ coverage

## Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WordPress Testing Handbook](https://make.wordpress.org/core/handbook/testing/)
- [WP Browser Documentation](https://wpbrowser.wptestkit.dev/)
