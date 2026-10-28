<?php
/**
 * Unit tests for the 1.1.0 API session: refresh token instead of a stored
 * password. Every HTTP call is answered by pre_http_request stubs.
 */

require_once dirname(__DIR__) . '/TestCase.php';

class Session_Test extends Livraria_TestCase {

    const PASSWORD = 'invented-password-0700000200';
    const REFRESH = 'invented-refresh-0700000201';
    const REFRESH_ROTATED = 'invented-refresh-0700000202';
    const ACCESS = 'invented-access-0700000203';
    const ACCESS_NEW = 'invented-access-0700000204';

    /** @var array Each request: url, args */
    private $requests;
    /** @var array path => response or callable($args) */
    private $routes;

    public function setUp(): void {
        parent::setUp();
        $_POST = array();
        global $wp_test_user_caps;
        $wp_test_user_caps = array();
        update_option('courier_api_base_url', Livraria_API_Client::PRODUCTION_API_URL);
        delete_option('courier_api_username');

        $this->requests = array();
        $this->routes = array();
        $requests = &$this->requests;
        $routes = &$this->routes;
        add_filter('pre_http_request', function($preempt, $args, $url) use (&$requests, &$routes) {
            $requests[] = array('url' => $url, 'args' => $args);
            foreach ($routes as $path => $response) {
                if (strpos($url, $path) !== false) {
                    return is_callable($response) ? $response($args) : $response;
                }
            }
            return new WP_Error('mocked', 'No route in this test');
        }, 10, 3);
    }

    public function tearDown(): void {
        $_POST = array();
        global $wp_test_user_caps;
        $wp_test_user_caps = array();
        parent::tearDown();
    }

    private function json($code, $body) {
        return array('response' => array('code' => $code, 'message' => ''), 'body' => json_encode($body), 'headers' => array());
    }

    private function login_ok($refresh = self::REFRESH) {
        return $this->json(201, array('success' => true, 'data' => array(
            'accessToken' => self::ACCESS, 'refreshToken' => $refresh, 'expiresIn' => 3600, 'tokenType' => 'Bearer',
            'user' => array('email' => 'merchant@example.com'),
        )));
    }

    private function refresh_ok($rotated = null) {
        $data = array('accessToken' => self::ACCESS_NEW, 'expiresIn' => 3600, 'tokenType' => 'Bearer');
        if ($rotated !== null) {
            $data['refreshToken'] = $rotated;
        }
        return $this->json(201, array('success' => true, 'data' => $data, 'message' => 'Session refreshed'));
    }

    private function refresh_expired() {
        return $this->json(401, array('statusCode' => 401, 'timestamp' => '2026-10-28T10:00:00.000Z', 'path' => '/auth/refresh',
            'message' => array('success' => false, 'error' => 'Session expired. Please log in again.', 'reason' => 'REFRESH_EXPIRED')));
    }

    private function client() {
        return new Livraria_API_Client();
    }

    private function stored_refresh() {
        return $this->client()->get_encrypted_option(Livraria_API_Client::OPT_REFRESH_TOKEN, '');
    }

    private function paths() {
        return array_map(function($r) { return (string) parse_url($r['url'], PHP_URL_PATH); }, $this->requests);
    }

    /** A connected shop whose access token has expired */
    private function expired_access_with_refresh() {
        update_option('livraria_api_token', self::ACCESS);
        update_option('livraria_token_expires_at', time() - 10);
        $this->client()->set_encrypted_option(Livraria_API_Client::OPT_REFRESH_TOKEN, self::REFRESH);
        $this->client()->set_encrypted_option('courier_api_username', 'merchant@example.com');
    }

    // --- login ---------------------------------------------------------------

    public function test_login_stores_the_refresh_token_encrypted_and_never_the_password() {
        $this->routes['/auth/login'] = $this->login_ok();
        update_option('courier_api_password', 'livraria_encrypted:left-over-from-1.0.x');

        $result = $this->client()->login('merchant@example.com', self::PASSWORD);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['refresh_stored']);
        $this->assertSame(self::ACCESS, get_option('livraria_api_token'));
        $this->assertStringStartsWith('livraria_encrypted:', get_option(Livraria_API_Client::OPT_REFRESH_TOKEN));
        $this->assertSame(self::REFRESH, $this->stored_refresh());
        $this->assertFalse(get_option('courier_api_password', false), '1.0.x password deleted');
        $this->assertSame('merchant@example.com', $this->client()->get_encrypted_option('courier_api_username'));
        global $wp_test_options;
        foreach ($wp_test_options as $name => $value) {
            $this->assertStringNotContainsString(self::PASSWORD, (string) wp_json_encode($value), $name);
        }
    }

    public function test_ajax_login_never_stores_the_password() {
        global $wp_test_user_caps, $wp_test_options;
        $wp_test_user_caps = array('read', 'manage_options');
        $this->routes['/auth/login'] = $this->login_ok();
        $_POST = array('username' => 'merchant@example.com', 'password' => self::PASSWORD, 'remember_me' => '1');
        $plugin = new LivrariaPlugin();

        try {
            $plugin->ajax_login();
            $this->fail('no JSON response');
        } catch (Livraria_Test_Json_Response $response) {
            $this->assertTrue($response->success);
            $this->assertSame('Login successful', $response->data['message']);
        }

        $this->assertFalse(get_option('courier_api_password', false));
        $this->assertFalse(get_option('livraria_remember_credentials', false));
        foreach ($wp_test_options as $name => $value) {
            $this->assertStringNotContainsString(self::PASSWORD, (string) wp_json_encode($value), $name);
        }
    }

    public function test_no_code_path_writes_the_password_option() {
        $root = dirname(__DIR__, 2);
        $files = array_merge(array($root . '/courier-expedition-wp-plugin.php'), glob($root . '/includes/*.php'));
        foreach ($files as $file) {
            $code = file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/(update_option|add_option|set_encrypted_option)\\(\\s*[\'"]courier_api_password/', $code, $file);
            $this->assertDoesNotMatchRegularExpression('/(update_option|add_option|set_encrypted_option)\\(\\s*self::OPT_LEGACY_PASSWORD/', $code, $file);
        }
    }

    // --- refresh -------------------------------------------------------------

    public function test_expired_access_token_is_refreshed_and_a_rotated_refresh_token_replaces_the_old() {
        $this->expired_access_with_refresh();
        $sent = array();
        $this->routes['/auth/refresh'] = function($args) use (&$sent) {
            $sent[] = json_decode($args['body'], true);
            return $this->refresh_ok(self::REFRESH_ROTATED);
        };
        $this->routes['/users/me'] = $this->json(200, array('email' => 'merchant@example.com'));

        $user = $this->client()->get_user_info();

        $this->assertSame('merchant@example.com', $user['email']);
        $this->assertSame(array('/auth/refresh', '/users/me'), $this->paths());
        $this->assertSame(array(array('refreshToken' => self::REFRESH)), $sent);
        $this->assertArrayNotHasKey('Authorization', $this->requests[0]['args']['headers']);
        $this->assertSame('Bearer ' . self::ACCESS_NEW, $this->requests[1]['args']['headers']['Authorization']);
        $this->assertSame(self::ACCESS_NEW, get_option('livraria_api_token'));
        $this->assertGreaterThan(time() + 3000, get_option('livraria_token_expires_at'));
        $this->assertSame(self::REFRESH_ROTATED, $this->stored_refresh());
    }

    public function test_refresh_without_rotation_keeps_the_refresh_token() {
        $this->expired_access_with_refresh();
        $this->routes['/auth/refresh'] = $this->refresh_ok();
        $this->routes['/users/me'] = $this->json(200, array('email' => 'merchant@example.com'));

        $this->client()->get_user_info();

        $this->assertSame(self::REFRESH, $this->stored_refresh());
    }

    public function test_refresh_expired_clears_tokens_marks_expired_and_emails_once() {
        global $wp_test_mails;
        $this->expired_access_with_refresh();
        $this->routes['/auth/refresh'] = $this->refresh_expired();
        $this->start_log_capture();

        $this->assertFalse($this->client()->get_user_info());
        Livraria_API_Client::reset_refresh_guard();
        $this->assertFalse($this->client()->get_sender_profile()); // a later request

        $this->assertSame(array('/auth/refresh'), $this->paths(), 'no login, no second refresh');
        $this->assertTrue(Livraria_API_Client::is_session_expired());
        $this->assertFalse(get_option('livraria_api_token', false));
        $this->assertFalse(get_option(Livraria_API_Client::OPT_REFRESH_TOKEN, false));
        $this->assertCount(1, $wp_test_mails);
        $mail = $wp_test_mails[0];
        $this->assertSame('admin@example.com', $mail['to']);
        $this->assertStringContainsString('log in again', strtolower($mail['subject'] . ' ' . $mail['message']));
        $this->assertStringNotContainsString(self::REFRESH, $mail['message']);
        $this->assertStringNotContainsString(self::ACCESS, $mail['message']);
        $this->assertSame('', $this->captured_log());
    }

    /**
     * @dataProvider transient_failures
     */
    public function test_transient_refresh_failures_keep_everything($response) {
        global $wp_test_mails;
        $this->expired_access_with_refresh();
        $this->routes['/auth/refresh'] = $response === 'network' ? new WP_Error('http_request_failed', 'timeout') : $this->json($response, array('statusCode' => $response, 'message' => array('success' => false, 'error' => 'Session refresh failed')));

        $client = $this->client();
        $this->assertFalse($client->get_user_info());
        $this->assertFalse($client->get_sender_profile()); // same request: no second refresh

        $this->assertSame(array('/auth/refresh'), $this->paths());
        $this->assertFalse(Livraria_API_Client::is_session_expired());
        $this->assertSame(self::REFRESH, $this->stored_refresh());
        $this->assertSame(self::ACCESS, get_option('livraria_api_token'));
        $this->assertCount(0, $wp_test_mails);
    }

    public static function transient_failures() {
        return array('429' => array(429), '500' => array(500), '401 without REFRESH_EXPIRED' => array(401), 'network' => array('network'));
    }

    public function test_a_401_to_a_valid_looking_token_gets_one_refresh_and_one_retry() {
        update_option('livraria_api_token', self::ACCESS);
        update_option('livraria_token_expires_at', time() + 3600);
        $this->client()->set_encrypted_option(Livraria_API_Client::OPT_REFRESH_TOKEN, self::REFRESH);
        $calls = 0;
        $this->routes['/auth/refresh'] = $this->refresh_ok();
        $this->routes['/users/me'] = function($args) use (&$calls) {
            $calls++;
            return $calls === 1 ? $this->json(401, array('message' => 'Unauthorized')) : $this->json(200, array('email' => 'merchant@example.com'));
        };

        $user = $this->client()->get_user_info();

        $this->assertSame('merchant@example.com', $user['email']);
        $this->assertSame(array('/users/me', '/auth/refresh', '/users/me'), $this->paths());
    }

    public function test_a_second_401_is_a_failure() {
        update_option('livraria_api_token', self::ACCESS);
        update_option('livraria_token_expires_at', time() + 3600);
        $this->client()->set_encrypted_option(Livraria_API_Client::OPT_REFRESH_TOKEN, self::REFRESH);
        $this->routes['/auth/refresh'] = $this->refresh_ok();
        $this->routes['/users/me'] = $this->json(401, array('message' => 'Unauthorized'));

        $this->assertFalse($this->client()->get_user_info());
        $this->assertSame(array('/users/me', '/auth/refresh', '/users/me'), $this->paths());
    }

    // --- migration from 1.0.x ---------------------------------------------------

    private function legacy_install() {
        update_option('livraria_remember_credentials', true);
        $this->client()->set_encrypted_option('courier_api_username', 'merchant@example.com');
        // 1.0.x wrote this with the same encryption; set it the same way here
        update_option('courier_api_password', 'livraria_encrypted:' . $this->encrypt_like_1_0(self::PASSWORD));
        update_option('livraria_api_token', self::ACCESS);
        update_option('livraria_token_expires_at', time() - 10);
    }

    private function encrypt_like_1_0($value) {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('AES-256-CBC'));
        return base64_encode($iv . openssl_encrypt($value, 'AES-256-CBC', wp_salt('nonce'), 0, $iv));
    }

    public function test_migration_logs_in_once_stores_the_refresh_token_and_deletes_the_password() {
        $this->legacy_install();
        $sent = array();
        $this->routes['/auth/login'] = function($args) use (&$sent) {
            $sent[] = json_decode($args['body'], true);
            return $this->login_ok();
        };
        $this->routes['/users/me'] = $this->json(200, array('email' => 'merchant@example.com'));

        $this->assertNotFalse($this->client()->get_user_info());

        $this->assertSame(array('/auth/login', '/users/me'), $this->paths());
        $this->assertSame(array(array('email' => 'merchant@example.com', 'password' => self::PASSWORD)), $sent);
        $this->assertSame(self::REFRESH, $this->stored_refresh());
        $this->assertFalse(get_option('courier_api_password', false));
        $this->assertFalse(get_option('livraria_remember_credentials', false));
        $this->assertFalse(Livraria_API_Client::is_session_expired());
    }

    public function test_migration_with_a_rejected_password_deletes_it_and_expires_the_session() {
        global $wp_test_mails;
        $this->legacy_install();
        $this->routes['/auth/login'] = $this->json(401, array('statusCode' => 401, 'message' => 'Invalid credentials'));

        $this->assertFalse($this->client()->get_user_info());

        $this->assertSame(array('/auth/login'), $this->paths());
        $this->assertFalse(get_option('courier_api_password', false));
        $this->assertTrue(Livraria_API_Client::is_session_expired());
        $this->assertCount(1, $wp_test_mails);
    }

    public function test_migration_on_a_transient_failure_keeps_the_password_for_next_time() {
        global $wp_test_mails;
        $this->legacy_install();
        $this->routes['/auth/login'] = $this->json(503, array('message' => 'Unavailable'));

        $this->assertFalse($this->client()->get_user_info());

        $this->assertNotFalse(get_option('courier_api_password', false));
        $this->assertFalse(Livraria_API_Client::is_session_expired());
        $this->assertCount(0, $wp_test_mails);
    }

    // --- visibility ------------------------------------------------------------

    public function test_expired_session_notice_is_for_manage_options_only() {
        global $wp_test_user_caps;
        update_option(Livraria_API_Client::OPT_SESSION_STATE, 'expired');
        $plugin = new LivrariaPlugin();

        $wp_test_user_caps = array('read', 'edit_shop_orders');
        ob_start();
        $plugin->render_session_expired_notice();
        $this->assertSame('', ob_get_clean());

        $wp_test_user_caps = array('read', 'manage_options');
        ob_start();
        $plugin->render_session_expired_notice();
        $notice = ob_get_clean();
        $this->assertStringContainsString('Livraria session expired, log in again', $notice);
        $this->assertStringContainsString('page=courier-api-settings', $notice);

        delete_option(Livraria_API_Client::OPT_SESSION_STATE);
        ob_start();
        $plugin->render_session_expired_notice();
        $this->assertSame('', ob_get_clean());
    }

    public function test_order_meta_box_shows_the_expired_message_instead_of_its_actions() {
        update_option(Livraria_API_Client::OPT_SESSION_STATE, 'expired');
        $plugin = new LivrariaPlugin();
        $order = new class {
            public $ID = 101;
        };
        $GLOBALS['wp_test_orders'] = array(101 => new Livraria_Test_Order());

        ob_start();
        $plugin->expedition_meta_box_callback($order);
        $html = ob_get_clean();

        $this->assertStringContainsString('Livraria session expired, log in again', $html);
        $this->assertStringNotContainsString('create-expedition-btn', $html);
        $this->assertCount(0, $this->requests);
    }

    public function test_login_clears_the_expired_state() {
        update_option(Livraria_API_Client::OPT_SESSION_STATE, 'expired');
        $this->routes['/auth/login'] = $this->login_ok();

        $this->assertTrue($this->client()->login('merchant@example.com', self::PASSWORD)['success']);

        $this->assertFalse(Livraria_API_Client::is_session_expired());
        $this->assertGreaterThan(0, (int) get_option(Livraria_API_Client::OPT_CONNECTED_AT));
    }

    // --- logging -----------------------------------------------------------------

    public function test_no_token_or_password_is_logged() {
        $this->start_log_capture();
        $this->routes['/auth/login'] = $this->login_ok();
        $this->client()->login('merchant@example.com', self::PASSWORD);
        update_option('livraria_token_expires_at', time() - 10);
        Livraria_API_Client::reset_refresh_guard();
        $this->routes['/auth/refresh'] = $this->refresh_ok(self::REFRESH_ROTATED);
        $this->routes['/users/me'] = $this->json(500, array('message' => 'boom'));
        $this->client()->get_user_info();

        $log = $this->captured_log();
        foreach (array(self::PASSWORD, self::REFRESH, self::REFRESH_ROTATED, self::ACCESS, self::ACCESS_NEW) as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }
}
