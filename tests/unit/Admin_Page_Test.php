<?php
/**
 * Unit tests for Livraria_Admin_Page: no password field is ever prefilled,
 * no token reaches the page, and the session state is shown.
 */

require_once dirname(__DIR__) . '/TestCase.php';

class Admin_Page_Test extends Livraria_TestCase {

    const REFRESH = 'invented-refresh-token-0700000123';
    const ACCESS = 'invented-access-token-0700000124';

    private $client;

    public function setUp(): void {
        parent::setUp();
        add_filter('pre_http_request', function($preempt, $args, $url) {
            return new WP_Error('mocked', 'Stopped by test');
        }, 10, 3);

        $this->client = new Livraria_API_Client();
        $this->client->set_encrypted_option('courier_api_username', 'merchant@example.com');
    }

    private function render_page() {
        $page = new Livraria_Admin_Page(new Livraria_API_Client());
        ob_start();
        $page->render();
        return ob_get_clean();
    }

    public function test_connected_page_shows_the_user_and_no_token() {
        update_option('livraria_api_token', self::ACCESS);
        update_option('livraria_token_expires_at', time() + 3600);
        $this->client->set_encrypted_option(Livraria_API_Client::OPT_REFRESH_TOKEN, self::REFRESH);
        update_option(Livraria_API_Client::OPT_CONNECTED_AT, time() - 60);

        $html = $this->render_page();

        $this->assertStringNotContainsString('id="login-password"', $html);
        $this->assertStringContainsString('Connected as merchant@example.com since', $html);
        $this->assertStringContainsString('data-session="1"', $html);
        $this->assertStringNotContainsString(self::REFRESH, $html);
        $this->assertStringNotContainsString(self::ACCESS, $html);
    }

    public function test_not_connected_shows_an_empty_login_form_without_remember_me() {
        $html = $this->render_page();

        $this->assertStringContainsString('id="login-password"', $html);
        $this->assertStringContainsString('name="courier_api_password" value=""', $html);
        $this->assertStringContainsString('value="merchant@example.com"', $html);
        $this->assertStringNotContainsString('remember', strtolower($html));
        $this->assertStringContainsString('Not connected', $html);
    }

    public function test_expired_session_shows_the_message_and_the_login_form() {
        update_option(Livraria_API_Client::OPT_SESSION_STATE, 'expired');
        update_option(Livraria_API_Client::OPT_SESSION_EXPIRED_AT, time() - 60);

        $html = $this->render_page();

        $this->assertStringContainsString('Livraria session expired', $html);
        $this->assertStringContainsString('log in again', $html);
        $this->assertStringContainsString('id="login-password"', $html);
    }

    public function test_no_url_field_outside_dev_mode() {
        $html = $this->render_page();

        $this->assertStringNotContainsString('login-api-url', $html);
        $this->assertStringNotContainsString('name="courier_api_base_url"', $html);
        $this->assertStringContainsString('livraria-api-url-display', $html);
    }
}
