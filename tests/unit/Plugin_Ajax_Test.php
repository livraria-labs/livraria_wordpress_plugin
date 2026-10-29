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

    private function ok_login_responses(array &$urls) {
        $GLOBALS['wp_test_filters'] = array();
        add_filter('pre_http_request', function($preempt, $args, $url) use (&$urls) {
            $urls[] = $url;
            return array(
                'response' => array('code' => 200, 'message' => 'OK'),
                'body' => json_encode(array('accessToken' => 'invented-token-1', 'expiresIn' => 3600)),
                'headers' => array(),
            );
        }, 10, 3);
    }

    public function test_login_with_a_posted_foreign_url_is_refused_and_saves_nothing() {
        $this->as_user(array('read', 'manage_options'));
        delete_option('courier_api_username');
        $_POST = array(
            'api_url' => 'https://collector.example.net/',
            'username' => 'merchant@example.com',
            'password' => 'invented-password-1',
            'remember_me' => '1',
        );

        $response = $this->call_ajax('ajax_login');

        $this->assertFalse($response->success);
        $this->assertStringContainsString('collector.example.net', $response->data);
        $this->assertCount(0, $this->captured, 'no request to the foreign host');
        $this->assertSame(Livraria_API_Client::PRODUCTION_API_URL, get_option('courier_api_base_url'));
        $this->assertSame('', (string) get_option('courier_api_password', ''), 'nothing stored after a refused login');
        $this->assertSame('', (string) get_option('livraria_refresh_token', ''), 'nothing stored after a refused login');
        $this->assertSame('', (string) get_option('courier_api_username', ''), 'nothing stored after a refused login');
    }

    public function test_login_with_a_posted_uat_url_succeeds_and_saves_it() {
        $this->as_user(array('read', 'manage_options'));
        $urls = array();
        $this->ok_login_responses($urls);
        $_POST = array(
            'api_url' => 'https://www.api-uat.livraria.ro/',
            'username' => 'merchant@example.com',
            'password' => 'invented-password-1',
            'remember_me' => '0',
        );

        $response = $this->call_ajax('ajax_login');

        $this->assertTrue($response->success);
        $this->assertSame(array('https://www.api-uat.livraria.ro/auth/login'), $urls);
        $this->assertSame('https://www.api-uat.livraria.ro/', get_option('courier_api_base_url'));
    }

    public function test_login_without_a_posted_url_uses_the_stored_allowed_url() {
        $this->as_user(array('read', 'manage_options'));
        update_option('courier_api_base_url', 'https://api.livraria.ro/');
        $urls = array();
        $this->ok_login_responses($urls);
        $_POST = array('username' => 'merchant@example.com', 'password' => 'invented-password-1', 'remember_me' => '0');

        $this->assertTrue($this->call_ajax('ajax_login')->success);
        $this->assertSame(array('https://api.livraria.ro/auth/login'), $urls);
        $this->assertSame('https://api.livraria.ro/', get_option('courier_api_base_url'));
    }

    public function test_test_connection_handlers_refuse_a_posted_foreign_url() {
        $this->as_user(array('read', 'manage_options'));
        $_POST = array(
            'api_url' => 'https://collector.example.net/',
            'username' => 'merchant@example.com',
            'password' => 'invented-password-1',
        );

        $this->assertFalse($this->call_ajax('ajax_test_connectivity')->success);
        $this->assertFalse($this->call_ajax('ajax_test_api_connection')->success);

        $this->assertCount(0, $this->captured);
    }

    public function test_load_keeps_allowed_hosts_untouched() {
        foreach (array('https://api.livraria.ro/', 'https://api.livraria.ro', 'https://www.api.livraria.ro/', 'https://www.api-uat.livraria.ro/', 'https://api-uat.livraria.ro/') as $stored) {
            update_option('courier_api_base_url', $stored);
            update_option('livraria_api_token', 'existing-token');
            update_option('livraria_token_expires_at', time() + 3600);
            $this->start_log_capture();

            new LivrariaPlugin();

            $this->assertSame($stored, get_option('courier_api_base_url'), $stored);
            $this->assertSame('existing-token', get_option('livraria_api_token'), 'session kept for ' . $stored);
            $this->assertSame('', $this->captured_log(), $stored);
            $this->assertSame('', Livraria_API_Client::blocked_api_url(), $stored);
        }
    }

    public function test_load_with_a_foreign_host_stops_the_plugin_without_changing_the_url() {
        update_option('courier_api_base_url', 'https://evil.example.com/');
        update_option('livraria_api_token', 'existing-token');
        update_option('livraria_token_expires_at', time() + 3600);
        $this->start_log_capture();

        $plugin = new LivrariaPlugin();
        new LivrariaPlugin(); // a second request logs nothing more

        // URL untouched, session dropped, one log line
        $this->assertSame('https://evil.example.com/', get_option('courier_api_base_url'));
        $this->assertFalse(get_option('livraria_api_token', false));
        $lines = array_values(array_filter(explode("\n", $this->captured_log())));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('evil.example.com', $lines[0]);

        // Every request path refuses to run
        update_option('livraria_api_token', 'token-set-later');
        update_option('livraria_token_expires_at', time() + 3600);
        $client = new Livraria_API_Client();
        $this->assertSame('https://evil.example.com/', $client->get_api_base_url());
        $this->assertFalse($client->get_user_info());
        $this->assertFalse($client->download_awb_pdf('AWB0000000003'));
        $this->assertFalse($client->get_available_couriers());
        $this->assertFalse($client->login('merchant@example.com', 'invented-password-7')['success']);
        $this->assertFalse($client->test_connectivity()['success']);
        $this->assertCount(0, $this->captured);

        // Notice for administrators only
        $this->as_user(array('read'));
        ob_start();
        $plugin->render_blocked_api_url_notice();
        $this->assertSame('', ob_get_clean());
        $this->as_user(array('read', 'manage_options'));
        ob_start();
        $plugin->render_blocked_api_url_notice();
        $notice = ob_get_clean();
        $this->assertStringContainsString('notice-error', $notice);
        $this->assertStringContainsString('evil.example.com', $notice);
        $this->assertStringContainsString('log in again', $notice);
    }

    public function test_login_from_the_blocked_state_uses_production_and_clears_it() {
        update_option('courier_api_base_url', 'https://evil.example.com/');
        $this->start_log_capture();
        $this->plugin = new LivrariaPlugin();
        $this->as_user(array('read', 'manage_options'));
        $urls = array();
        $this->ok_login_responses($urls);
        $_POST = array('username' => 'merchant@example.com', 'password' => 'invented-password-8', 'remember_me' => '0');

        $this->assertTrue($this->call_ajax('ajax_login')->success);

        $this->assertSame(array(Livraria_API_Client::PRODUCTION_API_URL . 'auth/login'), $urls);
        $this->assertSame(Livraria_API_Client::PRODUCTION_API_URL, get_option('courier_api_base_url'));
        $this->assertSame('', Livraria_API_Client::blocked_api_url());
        $this->assertFalse(get_option('livraria_api_url_blocked', false));
    }

    public function test_load_on_a_fresh_install_sets_production_without_logging() {
        $this->assertSame('https://www.api.livraria.ro/', Livraria_API_Client::PRODUCTION_API_URL);
        delete_option('courier_api_base_url');
        $this->start_log_capture();

        new LivrariaPlugin();

        $this->assertSame(Livraria_API_Client::PRODUCTION_API_URL, get_option('courier_api_base_url'));
        $this->assertSame('', $this->captured_log());
    }

    public function test_credentials_and_api_url_are_not_registered_settings() {
        global $wp_test_registered_settings;
        $wp_test_registered_settings = array();

        $this->plugin->admin_init();

        foreach (array('courier_api_base_url', 'courier_api_username', 'courier_api_password') as $option) {
            $this->assertArrayNotHasKey($option, $wp_test_registered_settings, $option);
        }
        $this->assertArrayHasKey('courier_auto_create', $wp_test_registered_settings);
        $this->assertArrayHasKey('courier_sender_apartment', $wp_test_registered_settings);
    }

    public function test_every_ajax_hook_points_at_a_method_that_exists() {
        global $wp_test_actions;
        $saved = $wp_test_actions;
        $wp_test_actions = array();
        new LivrariaPlugin();
        $registered = $wp_test_actions;
        $wp_test_actions = $saved;

        $this->assertArrayNotHasKey('wp_ajax_livraria_get_courier_quotes', $registered);
        foreach ($registered as $hook => $callbacks) {
            if (strpos($hook, 'wp_ajax_') !== 0) {
                continue;
            }
            foreach ($callbacks as $callback) {
                $this->assertTrue(is_callable($callback), $hook);
            }
        }
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
