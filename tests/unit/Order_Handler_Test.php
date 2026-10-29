<?php
/**
 * Unit tests for Livraria_Order_Handler class
 * 
 * Tests order processing logic with mocked API client
 */

// Use simple test case for unit tests that don't need WordPress
require_once dirname(__DIR__) . '/TestCase.php';

class Order_Handler_Test extends Livraria_TestCase {
    
    private $order_handler;
    private $mock_api_client;
    
    public function setUp(): void {
        parent::setUp();
        
        // Create a real API client instance for testing
        // We'll mock the HTTP requests instead of the client itself
        $this->mock_api_client = new Livraria_API_Client('https://www.api.livraria.ro');
        
        $this->order_handler = new Livraria_Order_Handler($this->mock_api_client);
    }
    
    /**
     * Test sender info extraction from API profile
     */
    public function test_get_sender_info_from_api() {
        // Mock sender profile API response
        $sender_profile = array(
            'name' => 'Test Sender',
            'email' => 'sender@example.com',
            'phone' => '+40123456789',
            'isCompany' => true,
            'cui' => 'RO12345678'
        );
        
        // Set valid token first
        update_option('livraria_api_token', 'test-token');
        update_option('livraria_token_expires_at', time() + 3600);
        
        // Recreate API client to pick up the token
        $this->mock_api_client = new Livraria_API_Client('https://www.api.livraria.ro');
        $this->order_handler = new Livraria_Order_Handler($this->mock_api_client);
        
        // Mock HTTP request to return sender profile
        $mock_response = $this->create_mock_response(200, $sender_profile);
        add_filter('pre_http_request', function($preempt, $args, $url) use ($mock_response) {
            if (strpos($url, '/users/me/sender-profile') !== false) {
                return $mock_response;
            }
            return $preempt;
        }, 10, 3);
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($this->order_handler);
        $method = $reflection->getMethod('get_sender_info');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->order_handler);
        
        $this->assertEquals('Test Sender', $result['name']);
        $this->assertEquals('sender@example.com', $result['email']);
        $this->assertEquals('+40123456789', $result['phone']);
        $this->assertTrue($result['isCompany']);
    }
    
    /**
     * Test sender address extraction from nested address object
     */
    public function test_get_sender_address_from_nested_address() {
        $sender_profile = array(
            'name' => 'Test Sender',
            'address' => array(
                'street' => 'Test Street',
                'streetNumber' => '123',
                'city' => 'Bucharest',
                'county' => 'Bucharest',
                'country' => 'Romania',
                'postcode' => '010001'
            )
        );
        
        update_option('livraria_api_token', 'test-token');
        update_option('livraria_token_expires_at', time() + 3600);
        
        // Recreate API client to pick up the token
        $this->mock_api_client = new Livraria_API_Client('https://www.api.livraria.ro');
        $this->order_handler = new Livraria_Order_Handler($this->mock_api_client);
        
        $mock_response = $this->create_mock_response(200, $sender_profile);
        add_filter('pre_http_request', function($preempt, $args, $url) use ($mock_response) {
            if (strpos($url, '/users/me/sender-profile') !== false) {
                return $mock_response;
            }
            return $preempt;
        }, 10, 3);
        
        $reflection = new ReflectionClass($this->order_handler);
        $method = $reflection->getMethod('get_sender_address');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->order_handler);
        
        $this->assertEquals('Test Street', $result['street']);
        $this->assertEquals('Bucharest', $result['city']);
        $this->assertEquals('Romania', $result['country']);
    }
    
    /**
     * Test sender address extraction from top-level fields
     */
    public function test_get_sender_address_from_top_level() {
        $sender_profile = array(
            'name' => 'Test Sender',
            'street' => 'Test Street',
            'streetNumber' => '123',
            'city' => 'Bucharest',
            'county' => 'Bucharest',
            'country' => 'Romania',
            'postcode' => '010001'
        );
        
        update_option('livraria_api_token', 'test-token');
        update_option('livraria_token_expires_at', time() + 3600);
        
        // Recreate API client to pick up the token
        $this->mock_api_client = new Livraria_API_Client('https://www.api.livraria.ro');
        $this->order_handler = new Livraria_Order_Handler($this->mock_api_client);
        
        $mock_response = $this->create_mock_response(200, $sender_profile);
        add_filter('pre_http_request', function($preempt, $args, $url) use ($mock_response) {
            if (strpos($url, '/users/me/sender-profile') !== false) {
                return $mock_response;
            }
            return $preempt;
        }, 10, 3);
        
        $reflection = new ReflectionClass($this->order_handler);
        $method = $reflection->getMethod('get_sender_address');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->order_handler);
        
        $this->assertEquals('Test Street', $result['street']);
        $this->assertEquals('Bucharest', $result['city']);
    }
    
    /**
     * Test missing required address fields throws exception
     */
    public function test_get_sender_address_missing_required_fields() {
        $sender_profile = array(
            'name' => 'Test Sender',
            'city' => 'Bucharest'
            // Missing street, country, etc.
        );
        
        update_option('livraria_api_token', 'test-token');
        update_option('livraria_token_expires_at', time() + 3600);
        
        // Recreate API client to pick up the token
        $this->mock_api_client = new Livraria_API_Client('https://www.api.livraria.ro');
        $this->order_handler = new Livraria_Order_Handler($this->mock_api_client);
        
        $mock_response = $this->create_mock_response(200, $sender_profile);
        add_filter('pre_http_request', function($preempt, $args, $url) use ($mock_response) {
            if (strpos($url, '/users/me/sender-profile') !== false) {
                return $mock_response;
            }
            return $preempt;
        }, 10, 3);
        
        $reflection = new ReflectionClass($this->order_handler);
        $method = $reflection->getMethod('get_sender_address');
        $method->setAccessible(true);
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('missing required field');
        
        $method->invoke($this->order_handler);
    }
    
    /**
     * Test Romanian diacritics removal
     */
    public function test_remove_romanian_diacritics() {
        $reflection = new ReflectionClass($this->order_handler);
        $method = $reflection->getMethod('remove_romanian_diacritics');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->order_handler, 'București');
        $this->assertEquals('Bucuresti', $result);
        
        $result = $method->invoke($this->order_handler, 'Iași');
        $this->assertEquals('Iasi', $result);
    }
    
    /**
     * Test COD object building with IBAN
     */
    public function test_build_cod_object_with_iban() {
        $sender_profile = array(
            'codIban' => 'RO49AAAA1B31007593840000'
        );
        
        $reflection = new ReflectionClass($this->order_handler);
        $method = $reflection->getMethod('build_cod_object');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->order_handler, 100.00, $sender_profile);
        
        $this->assertEquals(100.00, $result['amount']);
        $this->assertEquals('CLIENT', $result['collector']);
        $this->assertEquals('RO49AAAA1B31007593840000', $result['bankAccount']);
    }
    
    /**
     * Test COD object building without IBAN
     */
    public function test_build_cod_object_without_iban() {
        $sender_profile = array();
        
        $reflection = new ReflectionClass($this->order_handler);
        $method = $reflection->getMethod('build_cod_object');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->order_handler, 100.00, $sender_profile);
        
        $this->assertEquals(100.00, $result['amount']);
        $this->assertEquals('CLIENT', $result['collector']);
        $this->assertArrayNotHasKey('bankAccount', $result);
    }
}
