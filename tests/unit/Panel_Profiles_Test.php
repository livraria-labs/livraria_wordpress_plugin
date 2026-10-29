<?php
/**
 * The order panel renders from the database only: sender profiles come from
 * a 1-hour cache, and a cache miss shows "Reload profiles" instead of an API
 * call during the render.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';

class Panel_Profiles_Test extends Livraria_Quote_Flow_TestCase {

    private $profiles = array(
        array('id' => 'sp-invented-1', 'name' => 'Depozit Exemplu'),
        array('id' => 'sp-invented-2', 'companyName' => 'Magazin Exemplu SRL'),
    );

    public function tearDown(): void {
        global $wp_test_user_caps;
        $wp_test_user_caps = array();
        $_POST = array();
        parent::tearDown();
    }

    private function render_panel($order) {
        $plugin = new LivrariaPlugin();
        ob_start();
        $post = new stdClass();
        $post->ID = $order->get_id(); // the classic screen passes the post; the order comes from wc_get_order()
        $plugin->expedition_meta_box_callback($post);
        return ob_get_clean();
    }

    public function test_a_cache_miss_renders_reload_profiles_without_an_api_call() {
        $html = $this->render_panel($this->order(1201));

        $this->assertStringContainsString('id="livraria-reload-profiles"', $html);
        $this->assertStringContainsString('Reload profiles', $html);
        $this->assertStringNotContainsString('livraria-sender-profile-select', $html);
        $this->assertCount(0, $this->requests, 'rendering the panel calls no API');
    }

    public function test_cached_profiles_render_as_a_select_without_an_api_call() {
        set_transient(Livraria_API_Client::SENDER_PROFILES_TRANSIENT, $this->profiles, 3600);
        update_option('livraria_default_sender_profile_id', 'sp-invented-2');

        $html = $this->render_panel($this->order(1202));

        $this->assertStringContainsString('<option value="sp-invented-1">Depozit Exemplu</option>', $html);
        $this->assertStringContainsString('<option value="sp-invented-2" selected="selected">Magazin Exemplu SRL</option>', $html);
        $this->assertCount(0, $this->requests);
    }

    public function test_a_single_profile_object_is_one_option() {
        $this->assertSame(
            array(array('id' => 'sp-invented-3', 'label' => 'Depozit Trei')),
            LivrariaPlugin::sender_profile_options(array('id' => 'sp-invented-3', 'name' => 'Depozit Trei', 'email' => 'x@example.com'))
        );
    }

    public function test_reload_profiles_fetches_and_caches() {
        global $wp_test_user_caps;
        $wp_test_user_caps = array('read', 'edit_shop_orders');
        $this->routes['/users/me/sender-profile'] = $this->json(200, $this->profiles);
        $order = $this->order(1203);
        $order->meta['_livraria_sender_profile_id'] = 'sp-invented-1';
        $_POST = array('order_id' => '1203');

        try {
            (new LivrariaPlugin())->ajax_reload_sender_profiles();
            $this->fail('no JSON response');
        } catch (Livraria_Test_Json_Response $response) {
            $this->assertTrue($response->success);
            $this->assertSame('sp-invented-1', $response->data['selected']);
            $this->assertSame('Magazin Exemplu SRL', $response->data['options'][1]['label']);
        }
        $this->assertSame($this->profiles, Livraria_API_Client::cached_sender_profiles());
    }

    public function test_reload_profiles_failure_shows_the_backends_words() {
        global $wp_test_user_caps;
        $wp_test_user_caps = array('read', 'edit_shop_orders');
        $this->routes['/users/me/sender-profile'] = $this->json(503, array('message' => 'Service temporarily unavailable'));
        $_POST = array('order_id' => '1204');

        try {
            (new LivrariaPlugin())->ajax_reload_sender_profiles();
            $this->fail('no JSON response');
        } catch (Livraria_Test_Json_Response $response) {
            $this->assertFalse($response->success);
            $this->assertSame('Could not load the sender profiles: Service temporarily unavailable', $response->data);
        }
        $this->assertFalse(Livraria_API_Client::cached_sender_profiles());
    }

    public function test_login_logout_and_settings_save_drop_the_cache() {
        $cache = function() {
            set_transient(Livraria_API_Client::SENDER_PROFILES_TRANSIENT, $this->profiles, 3600);
        };

        $cache();
        $this->routes['/auth/login'] = $this->json(200, array('accessToken' => 'invented-access-token-3', 'refreshToken' => 'invented-refresh-token-3', 'expiresIn' => 3600));
        $this->assertTrue((new Livraria_API_Client('https://www.api.livraria.ro'))->login('merchant@example.com', 'invented-password-9')['success']);
        $this->assertFalse(Livraria_API_Client::cached_sender_profiles(), 'login');

        $cache();
        Livraria_API_Client::clear_session();
        $this->assertFalse(Livraria_API_Client::cached_sender_profiles(), 'logout');

        $cache();
        $plugin = new LivrariaPlugin();
        $plugin->forget_sender_profiles_on_settings_save('woocommerce_currency');
        $this->assertNotFalse(Livraria_API_Client::cached_sender_profiles(), 'unrelated option');
        $plugin->forget_sender_profiles_on_settings_save('courier_sender_city');
        $this->assertFalse(Livraria_API_Client::cached_sender_profiles(), 'settings save');

        global $wp_test_user_caps, $wp_test_actions;
        $this->assertContains(array($plugin, 'forget_sender_profiles_on_settings_save'), $wp_test_actions['updated_option']);
        $cache();
        $wp_test_user_caps = array('read', 'manage_options');
        $_POST = array('option_name' => 'livraria_default_sender_profile_id', 'option_value' => 'sp-invented-2');
        try {
            $plugin->ajax_update_option();
        } catch (Livraria_Test_Json_Response $response) {
            $this->assertTrue($response->success);
        }
        $this->assertFalse(Livraria_API_Client::cached_sender_profiles(), 'default profile changed');
    }
}
