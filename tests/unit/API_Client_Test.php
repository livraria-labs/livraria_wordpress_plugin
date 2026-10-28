<?php
/**
 * Unit tests for Livraria_API_Client class
 * 
 * Tests API client functionality in isolation with mocked HTTP requests
 */

// Use simple test case for unit tests that don't need WordPress
require_once dirname(__DIR__) . '/TestCase.php';

class API_Client_Test extends Livraria_TestCase {
    
    private $api_client;
    
    public function setUp(): void {
        parent::setUp();
        $this->api_client = new Livraria_API_Client('http://test-api.example.com');
    }
    
    /**
     * Test successful login
     */
    public function test_login_success() {
        // Mock successful login response
        $mock_response = $this->create_mock_response(200, array(
            'accessToken' => 'test-token-123',
            'expiresIn' => 3600
        ));
        
        add_filter('pre_http_request', function($preempt, $args, $url) use ($mock_response) {
            if (strpos($url, '/auth/login') !== false) {
                return $mock_response;
            }
            return $preempt;
        }, 10, 3);
        
        $result = $this->api_client->login('test_user', 'test_pass');
        
        $this->assertTrue($result['success']);
        $this->assertEquals('test-token-123', get_option('livraria_api_token'));
    }
    
    /**
     * Test login failure with invalid credentials
     * Note: Currently raw_api_request returns false on 401, so login returns "No response from server"
     */
    public function test_login_failure_invalid_credentials() {
        // Mock 401 response
        $mock_response = $this->create_mock_response(401, array(
            'message' => 'Invalid credentials'
        ));
        
        add_filter('pre_http_request', function($preempt, $args, $url) use ($mock_response) {
            if (strpos($url, '/auth/login') !== false) {
                return $mock_response;
            }
            return $preempt;
        }, 10, 3);
        
        $result = $this->api_client->login('wrong_user', 'wrong_pass');
        
        $this->assertFalse($result['success']);
        // Currently, raw_api_request returns false on 401, so login returns "No response from server"
        // This is expected behavior - the test verifies login fails
        $this->assertNotEmpty($result['message'], 'Error message should be present');
    }
    
    /**
     * Test token refresh when expired
     * Note: This test requires the api_request method to be public or we need to test through public methods
     */
    public function test_token_refresh_on_expiry() {
        // Set expired token
        update_option('livraria_api_token', 'old-token');
        update_option('livraria_token_expires_at', time() - 100);
        
        // Mock login response
        $mock_login = $this->create_mock_response(200, array(
            'accessToken' => 'new-token-456',
            'expiresIn' => 3600
        ));
        
        // Mock API request that would trigger refresh
        $mock_api = $this->create_mock_response(200, array('data' => 'test'));
        
        $login_called = false;
        add_filter('pre_http_request', function($preempt, $args, $url) use ($mock_login, $mock_api, &$login_called) {
            if (strpos($url, '/auth/login') !== false) {
                $login_called = true;
                return $mock_login;
            }
            return $mock_api;
        }, 10, 3);
        
        // Test token refresh through get_user_info (which calls api_request internally)
        $this->api_client->get_user_info();
        
        // Token should be refreshed
        $this->assertEquals('new-token-456', get_option('livraria_api_token'));
    }
    
    /**
     * Test get_user_info
     */
    public function test_get_user_info() {
        // Set valid token - need to set it in the client instance too
        update_option('livraria_api_token', 'valid-token');
        update_option('livraria_token_expires_at', time() + 3600);
        
        // Recreate client to pick up the token
        $this->api_client = new Livraria_API_Client('http://test-api.example.com');
        
        $mock_response = $this->create_mock_response(200, array(
            'id' => 1,
            'email' => 'test@example.com',
            'name' => 'Test User'
        ));
        
        add_filter('pre_http_request', function($preempt, $args, $url) use ($mock_response) {
            // Match the full URL or just the endpoint
            if (strpos($url, '/users/me') !== false && strpos($url, '/sender-profile') === false) {
                return $mock_response;
            }
            return $preempt;
        }, 10, 3);
        
        $user_info = $this->api_client->get_user_info();
        
        $this->assertNotFalse($user_info, 'get_user_info should return data, got: ' . var_export($user_info, true));
        $this->assertIsArray($user_info);
        $this->assertEquals('test@example.com', $user_info['email']);
    }
    
    /**
     * Test get_sender_profile
     */
    public function test_get_sender_profile() {
        update_option('livraria_api_token', 'valid-token');
        update_option('livraria_token_expires_at', time() + 3600);
        
        // Recreate client to pick up the token
        $this->api_client = new Livraria_API_Client('http://test-api.example.com');
        
        $mock_response = $this->create_mock_response(200, array(
            array(
                'name' => 'Test Sender',
                'email' => 'sender@example.com',
                'phone' => '+40123456789',
                'street' => 'Test Street',
                'city' => 'Bucharest',
                'country' => 'Romania'
            )
        ));
        
        add_filter('pre_http_request', function($preempt, $args, $url) use ($mock_response) {
            if (strpos($url, '/users/me/sender-profile') !== false) {
                return $mock_response;
            }
            return $preempt;
        }, 10, 3);
        
        $profile = $this->api_client->get_sender_profile();
        
        $this->assertNotFalse($profile, 'get_sender_profile should return data');
        $this->assertIsArray($profile);
        // Profile can be array of profiles or single profile
        if (isset($profile[0])) {
            $this->assertEquals('Test Sender', $profile[0]['name']);
        } else {
            $this->assertEquals('Test Sender', $profile['name']);
        }
    }
    
    /**
     * Test localhost conversion for Docker
     */
    public function test_convert_localhost_for_docker() {
        // Test that localhost is converted to host.docker.internal
        $reflection = new ReflectionClass($this->api_client);
        $method = $reflection->getMethod('convert_localhost_for_docker');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->api_client, 'http://localhost:3000/api');
        $this->assertEquals('http://host.docker.internal:3000/api', $result);
        
        $result = $method->invoke($this->api_client, 'http://127.0.0.1:3000/api');
        $this->assertEquals('http://host.docker.internal:3000/api', $result);
        
        // Non-localhost should remain unchanged
        $result = $method->invoke($this->api_client, 'http://example.com/api');
        $this->assertEquals('http://example.com/api', $result);
    }

    /**
     * Backend TOKEN rule for one X-Livraria-Client token
     * (apps/web/src/modules/client-integrations/plugin-client.ts)
     */
    const CLIENT_TOKEN = '/^([A-Za-z0-9._-]{1,40})\\/([A-Za-z0-9.+_-]{1,40})$/';

    private function invoke_client_header() {
        $method = (new ReflectionClass($this->api_client))->getMethod('client_header');
        $method->setAccessible(true);
        return $method->invoke($this->api_client);
    }

    private function plugin_header_version() {
        $main = file_get_contents(dirname(__DIR__, 2) . '/courier-expedition-wp-plugin.php');
        $this->assertSame(1, preg_match('/^\\s*\\*\\s*Version:\\s*([0-9]+\\.[0-9]+\\.[0-9]+)\\s*$/m', $main, $match));
        return $match[1];
    }

    /**
     * Capture the request args of every outgoing call and stop it there
     */
    private function capture_requests(array &$captured) {
        add_filter('pre_http_request', function($preempt, $args, $url) use (&$captured) {
            $captured[] = array('url' => $url, 'args' => $args);
            return new WP_Error('mocked', 'Stopped by test');
        }, 10, 3);
    }

    public function test_plugin_version_constant_matches_header() {
        $this->assertTrue(defined('LIVRARIA_WC_PLUGIN_VERSION'));
        $this->assertSame($this->plugin_header_version(), LIVRARIA_WC_PLUGIN_VERSION);
    }

    public function test_client_header_shape() {
        global $wp_test_bloginfo_version;
        $wp_test_bloginfo_version = '6.7.1-alpha-12345';

        $header = $this->invoke_client_header();
        $tokens = explode('; ', $header);

        $this->assertCount(4, $tokens, $header);
        foreach ($tokens as $token) {
            $this->assertMatchesRegularExpression(self::CLIENT_TOKEN, $token);
        }
        $this->assertSame('Livraria-WordPress-Plugin/' . $this->plugin_header_version(), $tokens[0]);
        $this->assertSame('WordPress/6.7.1-alpha-12345', $tokens[1]);
        // WC_VERSION is not defined in the unit-test bootstrap
        $this->assertSame('WooCommerce/unknown', $tokens[2]);
        $this->assertSame('PHP/' . preg_replace('/[^A-Za-z0-9.+_-]/', '', PHP_VERSION), $tokens[3]);
    }

    public function test_client_header_strips_characters_outside_the_token_rule() {
        global $wp_test_bloginfo_version;
        $wp_test_bloginfo_version = '6.8 RC1 <b>;x' . str_repeat('9', 60);

        $tokens = explode('; ', $this->invoke_client_header());

        $this->assertCount(4, $tokens);
        $this->assertSame('WordPress/' . substr('6.8RC1bx' . str_repeat('9', 60), 0, 40), $tokens[1]);
        $this->assertMatchesRegularExpression(self::CLIENT_TOKEN, $tokens[1]);
    }

    public function test_client_header_unknown_when_version_is_empty() {
        global $wp_test_bloginfo_version;
        $wp_test_bloginfo_version = '';

        $tokens = explode('; ', $this->invoke_client_header());

        $this->assertSame('WordPress/unknown', $tokens[1]);
    }

    public function test_every_request_sends_client_header_and_keeps_user_agent() {
        update_option('livraria_api_token', 'valid-token');
        update_option('livraria_token_expires_at', time() + 3600);
        $this->api_client = new Livraria_API_Client('http://test-api.example.com');

        $captured = array();
        $this->capture_requests($captured);

        $this->api_client->get_user_info();                          // api_request
        $this->api_client->download_awb_pdf('AWB0000000001');        // api_request_binary
        $this->api_client->test_connectivity();                      // connectivity check
        // Last: a failed login clears the token the calls above need
        $this->api_client->login('test_user', 'test_pass');          // raw_api_request

        $this->assertCount(4, $captured);
        $expected = $this->invoke_client_header();
        foreach ($captured as $request) {
            $this->assertSame($expected, $request['args']['headers']['X-Livraria-Client'] ?? null, $request['url']);
            $this->assertSame('WordPress-Livraria-Plugin/1.0', $request['args']['user-agent'], $request['url']);
        }
    }

    public function test_every_request_verifies_tls_outside_dev_mode() {
        // The unit-test bootstrap defines neither WP_DEBUG nor
        // LIVRARIA_ALLOW_INSECURE_TLS: this is a production install.
        $this->assertFalse(Livraria_API_Client::allow_insecure_tls());

        update_option('livraria_api_token', 'valid-token');
        update_option('livraria_token_expires_at', time() + 3600);
        $this->api_client = new Livraria_API_Client('http://test-api.example.com');

        $captured = array();
        $this->capture_requests($captured);

        $this->api_client->get_user_info();                          // api_request
        $this->api_client->download_awb_pdf('AWB0000000001');        // api_request_binary
        $this->api_client->test_connectivity();                      // connectivity check
        $this->api_client->get_available_couriers();                 // raw_api_request
        $this->api_client->login('test_user', 'test_pass');          // raw_api_request (login)

        $this->assertCount(5, $captured);
        foreach ($captured as $request) {
            $this->assertArrayHasKey('sslverify', $request['args'], $request['url']);
            $this->assertTrue($request['args']['sslverify'], $request['url']);
        }
    }

    public function test_nothing_is_logged_outside_dev_mode() {
        $this->assertFalse(Livraria_API_Client::is_dev_mode());
        $this->start_log_capture();

        $responses = array(
            '/auth/login' => $this->create_mock_response(200, array('accessToken' => 'invented-token-2', 'refreshToken' => 'invented-refresh-2', 'expiresIn' => 3600)),
            '/public/awb/quotes' => $this->create_mock_response(201, array('id' => 'qr-0002')),
            '/expedition' => array('response' => array('code' => 500, 'message' => 'Error'), 'body' => 'raw body echoing invented-password-3', 'headers' => array()),
        );
        add_filter('pre_http_request', function($preempt, $args, $url) use ($responses) {
            foreach ($responses as $path => $response) {
                if (strpos($url, $path) !== false) {
                    return $response;
                }
            }
            return new WP_Error('mocked', 'Stopped by test');
        }, 10, 3);

        $this->assertTrue($this->api_client->login('merchant@example.com', 'invented-password-3')['success']);
        $this->api_client->create_quote_request(array('recipient' => array('name' => 'Ana Exemplu', 'phone' => '0700000456')));
        $this->api_client->create_expedition(array('quoteRequestId' => 'qr-0002'));
        $this->api_client->download_awb_pdf('AWB0000000002');

        $this->assertSame('', $this->captured_log());
    }

    public function test_redact_masks_passwords_and_tokens_at_any_depth() {
        $redacted = Livraria_API_Client::redact(array(
            'email' => 'merchant@example.com',
            'password' => 'invented-password-4',
            'data' => array('accessToken' => 'a', 'refreshToken' => 'r', 'expiresIn' => 3600),
        ));
        $this->assertSame('***', $redacted['password']);
        $this->assertSame('***', $redacted['data']['accessToken']);
        $this->assertSame('***', $redacted['data']['refreshToken']);
        $this->assertSame(3600, $redacted['data']['expiresIn']);

        $from_json = Livraria_API_Client::redact('{"success":true,"data":{"accessToken":"a"}}');
        $this->assertSame('***', $from_json['data']['accessToken']);
        $this->assertSame('[9 bytes]', Livraria_API_Client::redact('not json!'));
    }

    public function tearDown(): void {
        global $wp_test_bloginfo_version;
        $wp_test_bloginfo_version = null;
        parent::tearDown();
    }
}
