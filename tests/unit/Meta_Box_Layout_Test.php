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

        $this->assertStringContainsString('class="livraria-route-grid"', $html);
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
}
