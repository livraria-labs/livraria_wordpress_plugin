<?php
/**
 * Unit tests for Livraria_Admin_Page: the stored password never reaches the
 * settings page HTML, only whether one is stored.
 */

require_once dirname(__DIR__) . '/TestCase.php';

class Admin_Page_Test extends Livraria_TestCase {

    const PASSWORD = 'Invented-Pass-0700000123';

    private $client;

    public function setUp(): void {
        parent::setUp();
        add_filter('pre_http_request', function($preempt, $args, $url) {
            return new WP_Error('mocked', 'Stopped by test');
        }, 10, 3);

        $this->client = new Livraria_API_Client();
        update_option('livraria_remember_credentials', true);
        $this->client->set_encrypted_option('courier_api_username', 'merchant@example.com');
        $this->client->set_encrypted_option('courier_api_password', self::PASSWORD);
        $this->assertSame(self::PASSWORD, $this->client->get_encrypted_option('courier_api_password'));
    }

    private function render_page() {
        $page = new Livraria_Admin_Page($this->client);
        ob_start();
        $page->render();
        return ob_get_clean();
    }

    public function test_login_form_does_not_contain_the_stored_password() {
        // No API session: the login form is shown
        $html = $this->render_page();

        $this->assertStringContainsString('id="login-password"', $html);
        $this->assertStringNotContainsString(self::PASSWORD, $html);
        $this->assertStringNotContainsString(htmlspecialchars(self::PASSWORD, ENT_QUOTES), $html);
        $this->assertStringContainsString('data-stored="1"', $html);
        $this->assertStringContainsString('Stored (encrypted)', $html);
        $this->assertStringNotContainsString('livraria-copy-password', $html);
    }

    public function test_logged_in_page_does_not_contain_the_stored_password() {
        update_option('livraria_api_token', 'invented-token-1');
        update_option('livraria_token_expires_at', time() + 3600);

        $html = $this->render_page();

        $this->assertStringNotContainsString('id="login-password"', $html);
        $this->assertStringNotContainsString(self::PASSWORD, $html);
        $this->assertStringContainsString('data-password-stored="1"', $html);
    }

    public function test_no_url_field_outside_dev_mode() {
        $html = $this->render_page();

        $this->assertStringNotContainsString('login-api-url', $html);
        $this->assertStringNotContainsString('name="courier_api_base_url"', $html);
    }
}
