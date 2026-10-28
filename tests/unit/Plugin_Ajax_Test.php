<?php
/**
 * Unit tests for the AJAX handlers in courier-expedition-wp-plugin.php
 *
 * The nonce printed into wp-admin proves a request came from wp-admin, not
 * that the user may change settings or orders: every handler must also check
 * a capability. All HTTP requests are stopped by pre_http_request.
 */

require_once dirname(__DIR__) . '/TestCase.php';

class Plugin_Ajax_Test extends Livraria_TestCase {

    private $plugin;
    private $captured;

    public function setUp(): void {
        parent::setUp();
        global $wp_test_user_caps;
        $wp_test_user_caps = array();
        $_POST = array();
        $_GET = array();

        $this->captured = array();
        $captured = &$this->captured;
        add_filter('pre_http_request', function($preempt, $args, $url) use (&$captured) {
            $captured[] = array('url' => $url, 'args' => $args);
            return new WP_Error('mocked', 'Stopped by test');
        }, 10, 3);

        update_option('courier_api_base_url', Livraria_API_Client::PRODUCTION_API_URL);
        $this->plugin = new LivrariaPlugin();
    }

    public function tearDown(): void {
        global $wp_test_user_caps;
        $wp_test_user_caps = array();
        $_POST = array();
        parent::tearDown();
    }

    /**
     * Run an AJAX handler and return the JSON response it sent
     */
    private function call_ajax($method) {
        try {
            $this->plugin->$method();
        } catch (Livraria_Test_Json_Response $response) {
            return $response;
        }
        $this->fail($method . ' sent no JSON response');
    }

    private function as_user(array $caps) {
        global $wp_test_user_caps;
        $wp_test_user_caps = $caps;
    }

    public function test_settings_handlers_refuse_a_user_without_manage_options() {
        // A shop manager: may edit orders, may not change the plugin settings
        $this->as_user(array('read', 'edit_shop_orders', 'manage_woocommerce'));
        update_option('livraria_api_token', 'existing-token');
        update_option('livraria_token_expires_at', time() + 3600);
        $_POST = array(
            'api_url' => 'https://collector.example.net/',
            'username' => 'merchant@example.com',
            'password' => 'invented-password-1',
            'remember_me' => '1',
            'option_name' => 'courier_auto_create',
            'option_value' => '1',
        );

        foreach (array('ajax_login', 'ajax_logout', 'ajax_test_api_connection', 'ajax_test_connectivity', 'ajax_update_option') as $method) {
            $response = $this->call_ajax($method);
            $this->assertFalse($response->success, $method);
            $this->assertSame(403, $response->status, $method);
        }

        $this->assertCount(0, $this->captured, 'no request may leave before the capability check');
        $this->assertSame('existing-token', get_option('livraria_api_token'), 'logout must not run');
        $this->assertNotSame('https://collector.example.net/', get_option('courier_api_base_url'));
        $this->assertFalse(get_option('courier_auto_create', false));
    }

    public function test_order_handlers_refuse_a_user_without_edit_shop_orders() {
        $this->as_user(array('read'));
        update_option('livraria_api_token', 'existing-token');
        update_option('livraria_token_expires_at', time() + 3600);
        $_POST = array(
            'order_id' => '101',
            'quote_request_id' => 'qr-0001',
            'courier_quote_id' => 'cq-0001',
        );

        foreach (array('ajax_create_expedition', 'ajax_get_quotes_for_order', 'ajax_select_quote', 'ajax_generate_label', 'ajax_auto_create_expedition', 'ajax_clear_auto_create_flag') as $method) {
            $response = $this->call_ajax($method);
            $this->assertFalse($response->success, $method);
            $this->assertSame(403, $response->status, $method);
        }

        $this->assertCount(0, $this->captured);
    }

    public function test_login_uses_the_production_host_and_saves_no_posted_api_url() {
        $this->as_user(array('read', 'manage_options'));
        // Livraria_TestCase seeds courier_api_password = 'test_pass'; start empty
        delete_option('courier_api_username');
        delete_option('courier_api_password');
        $_POST = array(
            'api_url' => 'https://collector.example.net/',
            'username' => 'merchant@example.com',
            'password' => 'invented-password-1',
            'remember_me' => '1',
        );

        // The login request is stopped (WP_Error): the login fails
        $response = $this->call_ajax('ajax_login');

        $this->assertFalse($response->success);
        $this->assertCount(1, $this->captured);
        $this->assertSame('https://api.livraria.ro/auth/login', $this->captured[0]['url']);
        $this->assertSame(Livraria_API_Client::PRODUCTION_API_URL, get_option('courier_api_base_url'));
        $this->assertSame('', (string) get_option('courier_api_password', ''), 'nothing stored after a failed login');
        $this->assertSame('', (string) get_option('courier_api_username', ''), 'nothing stored after a failed login');
    }

    public function test_login_success_keeps_the_production_host() {
        $this->as_user(array('read', 'manage_options'));
        $GLOBALS['wp_test_filters'] = array();
        $urls = array();
        add_filter('pre_http_request', function($preempt, $args, $url) use (&$urls) {
            $urls[] = $url;
            return array(
                'response' => array('code' => 200, 'message' => 'OK'),
                'body' => json_encode(array('accessToken' => 'invented-token-1', 'expiresIn' => 3600)),
                'headers' => array(),
            );
        }, 10, 3);
        $_POST = array(
            'api_url' => 'https://collector.example.net/',
            'username' => 'merchant@example.com',
            'password' => 'invented-password-1',
            'remember_me' => '0',
        );

        $response = $this->call_ajax('ajax_login');

        $this->assertTrue($response->success);
        $this->assertSame(array('https://api.livraria.ro/auth/login'), $urls);
        $this->assertSame(Livraria_API_Client::PRODUCTION_API_URL, get_option('courier_api_base_url'));
    }

    public function test_test_connection_handlers_ignore_a_posted_api_url() {
        $this->as_user(array('read', 'manage_options'));
        $_POST = array(
            'api_url' => 'https://collector.example.net/',
            'username' => 'merchant@example.com',
            'password' => 'invented-password-1',
        );

        $this->call_ajax('ajax_test_connectivity');
        $this->call_ajax('ajax_test_api_connection');

        $this->assertCount(2, $this->captured);
        foreach ($this->captured as $request) {
            $this->assertStringStartsWith('https://api.livraria.ro/', $request['url']);
        }
    }

    public function test_stored_credentials_are_used_server_side_against_production() {
        $this->as_user(array('read', 'manage_options'));
        $client = new Livraria_API_Client();
        update_option('livraria_remember_credentials', true);
        $client->set_encrypted_option('courier_api_username', 'merchant@example.com');
        $client->set_encrypted_option('courier_api_password', 'invented-password-2');
        $this->plugin = new LivrariaPlugin();

        $_POST = array('api_url' => 'https://collector.example.net/', 'username' => 'merchant@example.com', 'password' => '', 'use_stored_credentials' => '1');
        $this->call_ajax('ajax_test_api_connection');
        $_POST = array('username' => 'merchant@example.com', 'password' => '', 'remember_me' => '1');
        $this->call_ajax('ajax_login');

        $this->assertCount(2, $this->captured);
        foreach ($this->captured as $request) {
            $this->assertSame('https://api.livraria.ro/auth/login', $request['url']);
            $body = json_decode($request['args']['body'], true);
            $this->assertSame('invented-password-2', $body['password']);
        }
    }

    public function test_remember_me_stores_credentials_only_encrypted() {
        $this->as_user(array('read', 'manage_options'));
        $GLOBALS['wp_test_filters'] = array();
        add_filter('pre_http_request', function($preempt, $args, $url) {
            return array(
                'response' => array('code' => 200, 'message' => 'OK'),
                'body' => json_encode(array('accessToken' => 'invented-token-3', 'expiresIn' => 3600)),
                'headers' => array(),
            );
        }, 10, 3);
        $_POST = array('username' => 'merchant@example.com', 'password' => 'invented-password-5', 'remember_me' => '1');

        $response = $this->call_ajax('ajax_login');

        $this->assertTrue($response->success);
        $this->assertTrue(Livraria_API_Client::can_encrypt(), 'the test PHP has OpenSSL');
        $this->assertStringStartsWith('livraria_encrypted:', get_option('courier_api_password'));
        $this->assertStringNotContainsString('invented-password-5', get_option('courier_api_password'));
        $this->assertSame('invented-password-5', (new Livraria_API_Client())->get_encrypted_option('courier_api_password'));
    }

    public function test_load_resets_a_foreign_api_url_and_logs_one_line() {
        update_option('courier_api_base_url', 'https://collector.example.net/api/');
        update_option('livraria_api_token', 'token-from-the-other-host');
        update_option('livraria_token_expires_at', time() + 3600);
        $this->start_log_capture();

        new LivrariaPlugin();

        $this->assertSame(Livraria_API_Client::PRODUCTION_API_URL, get_option('courier_api_base_url'));
        $this->assertFalse(get_option('livraria_api_token', false));
        $lines = array_values(array_filter(explode("\n", $this->captured_log())));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('collector.example.net', $lines[0]);
        $this->assertStringNotContainsString('/api/', $lines[0]);
    }

    public function test_load_on_a_fresh_install_sets_production_without_logging() {
        delete_option('courier_api_base_url');
        $this->start_log_capture();

        new LivrariaPlugin();

        $this->assertSame(Livraria_API_Client::PRODUCTION_API_URL, get_option('courier_api_base_url'));
        $this->assertSame('', $this->captured_log());
    }

    public function test_logout_runs_for_an_administrator() {
        $this->as_user(array('read', 'manage_options'));
        update_option('livraria_api_token', 'existing-token');
        update_option('livraria_token_expires_at', time() + 3600);

        $response = $this->call_ajax('ajax_logout');

        $this->assertTrue($response->success);
        $this->assertFalse(get_option('livraria_api_token', false));
    }
}
