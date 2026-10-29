<?php
/**
 * The order box sits in the main column (context normal), wide enough for
 * the Route, the packages table and the quotes table.
 */

require_once dirname(__DIR__) . '/QuoteFlowTestCase.php';
require_once dirname(__DIR__) . '/stubs/OrdersTableDataStore.php';

class Meta_Box_Layout_Test extends Livraria_Quote_Flow_TestCase {

    public function tearDown(): void {
        unset($GLOBALS['wp_test_screen'], $GLOBALS['wp_test_meta_boxes']);
        parent::tearDown();
    }

    public function test_every_registration_is_in_the_main_column() {
        $GLOBALS['wp_test_meta_boxes'] = array();
        $GLOBALS['wp_test_screen'] = (object) array('id' => 'woocommerce_page_wc-orders', 'post_type' => '');

        (new LivrariaPlugin())->add_expedition_meta_box();

        $boxes = $GLOBALS['wp_test_meta_boxes'];
        $this->assertCount(3, $boxes, 'classic, HPOS and the current screen');
        $this->assertSame(array('shop_order', 'woocommerce_page_wc-orders', 'woocommerce_page_wc-orders'), array_column($boxes, 'screen'));
        foreach ($boxes as $box) {
            $this->assertSame('normal', $box['context']);
            $this->assertSame('high', $box['priority']);
            $this->assertSame('livraria-shipping', $box['id'], 'a new id, so saved sidebar positions do not pin it');
        }
    }

    public function test_the_panel_is_laid_out_for_the_wide_column_and_keeps_every_field() {
        $order = $this->order(1601);
        $post = new stdClass();
        $post->ID = 1601;
        ob_start();
        (new LivrariaPlugin())->expedition_meta_box_callback($post);
        $html = ob_get_clean();

        $this->assertStringContainsString('livraria-route-grid', $html);
        $this->assertStringContainsString('<table class="widefat livraria-packages">', $html);
        foreach (array('package_weight[]', 'package_length[]', 'package_width[]', 'package_height[]', 'content_description', 'cod_amount',
            'insurance_amount', 'open_on_delivery', 'saturday_delivery') as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html, $field);
        }
        foreach (array('livraria-origin-mode', 'livraria-destination-mode', 'add-package-btn', 'create-expedition-btn', 'generate-label-btn', 'quotes-container', 'livraria-reload-profiles') as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, $id);
        }
        $this->assertLessThan(strpos($html, 'livraria-packages'), strpos($html, 'livraria-route-grid'), 'Route first');
        $this->assertLessThan(strpos($html, 'livraria-options'), strpos($html, 'livraria-packages'), 'then packages, then options');
        $this->assertCount(0, $this->requests);
    }

    private function render($order_id) {
        $post = new stdClass();
        $post->ID = $order_id;
        ob_start();
        (new LivrariaPlugin())->expedition_meta_box_callback($post);
        return ob_get_clean();
    }

    public function test_no_inline_style_is_left_in_the_panel_markup() {
        $this->order(1602);
        $shipped = $this->order(1603);
        $shipped->meta = array('_courier_expedition_id' => 'exp-invented-1603', '_courier_awb_number' => 'AWB0000001603', '_courier_name' => 'Curier Exemplu');
        set_transient(Livraria_API_Client::SENDER_PROFILES_TRANSIENT, array(array('id' => 'sp-invented-1', 'name' => 'Depozit Exemplu')), 3600);

        foreach (array(1602 => 'unshipped', 1603 => 'shipped') as $id => $state) {
            $html = $this->render($id);
            $this->assertStringContainsString('class="livraria-panel"', $html, $state);
            $this->assertDoesNotMatchRegularExpression('/\sstyle=/', $html, $state . ': styling lives in assets/order-panel.css');
        }
        $shipped_html = $this->render(1603);
        $this->assertStringContainsString('AWB created', $shipped_html);
        $this->assertStringContainsString('id="livraria-delete-expedition-btn"', $shipped_html);
        $this->assertStringContainsString('Download A6', $shipped_html);
    }

    public function test_the_sidebar_width_stacks_the_panel() {
        $root = dirname(dirname(__DIR__));
        $css = file_get_contents($root . '/assets/order-panel.css');
        $js = file_get_contents($root . '/assets/order.js');

        $this->assertStringContainsString('container-type: inline-size', $css);
        $this->assertStringContainsString('@container lp (max-width: 560px)', $css);
        foreach (array('.lp-route', '.livraria-options', '.livraria-packages thead', '.lp-quote') as $rule) {
            $this->assertStringContainsString('.livraria-panel.lp-narrow ' . $rule, $css, 'fallback for ' . $rule);
        }
        $this->assertStringContainsString('ResizeObserver', $js);
        $this->assertStringContainsString("toggleClass('lp-narrow'", $js);

        $this->order(1604);
        $html = $this->render(1604);
        foreach (array('Weight (kg)', 'Length (cm)', 'Width (cm)', 'Height (cm)') as $label) {
            $this->assertStringContainsString('data-label="' . $label . '"', $html, 'stacked parcel rows label their inputs');
        }
    }
}
