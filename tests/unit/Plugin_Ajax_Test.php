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

    public function test_logout_runs_for_an_administrator() {
        $this->as_user(array('read', 'manage_options'));
        update_option('livraria_api_token', 'existing-token');
        update_option('livraria_token_expires_at', time() + 3600);

        $response = $this->call_ajax('ajax_logout');

        $this->assertTrue($response->success);
        $this->assertFalse(get_option('livraria_api_token', false));
    }
}
