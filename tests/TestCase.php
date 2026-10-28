<?php
/**
 * Base test case for unit tests
 * Uses mocked WordPress functions - no WordPress test suite required
 */

class Livraria_TestCase extends PHPUnit\Framework\TestCase {
    
    /**
     * Set up test environment before each test
     */
    public function setUp(): void {
        parent::setUp();
        
        // Clear test options
        global $wp_test_options;
        $wp_test_options = array();
        
        // Clear test filters
        global $wp_test_filters;
        $wp_test_filters = array();
        
        // Set default plugin options for testing
        $this->set_default_options();

        // Each test is a fresh "request"
        Livraria_API_Client::reset_refresh_guard();
        global $wp_test_mails;
        $wp_test_mails = array();
    }
    
    private $log_file = null;
    private $previous_error_log = null;

    /**
     * Send error_log() to a temporary file for the rest of this test
     */
    protected function start_log_capture() {
        if ($this->log_file !== null) {
            file_put_contents($this->log_file, ''); // already capturing: start over
            return;
        }
        $this->log_file = tempnam(sys_get_temp_dir(), 'livraria-log-');
        $this->previous_error_log = ini_set('error_log', $this->log_file);
    }

    /**
     * Everything error_log() wrote since start_log_capture()
     *
     * @return string
     */
    protected function captured_log() {
        clearstatcache();
        return $this->log_file && file_exists($this->log_file) ? (string) file_get_contents($this->log_file) : '';
    }

    /**
     * Clean up after each test
     */
    public function tearDown(): void {
        if ($this->log_file !== null) {
            ini_set('error_log', $this->previous_error_log === false ? '' : (string) $this->previous_error_log);
            @unlink($this->log_file);
            $this->log_file = null;
        }

        // Clean up plugin options
        delete_option('courier_api_base_url');
        delete_option('courier_api_username');
        delete_option('courier_api_password');
        delete_option('livraria_api_token');
        delete_option('livraria_token_expires_at');
        
        parent::tearDown();
    }
    
    /**
     * Set default plugin options for testing
     */
    protected function set_default_options() {
        update_option('courier_api_base_url', 'https://www.api.livraria.ro');
        update_option('courier_api_username', 'test_user');
        update_option('admin_email', 'admin@example.com');
    }
    
    /**
     * Create a mock API response
     * 
     * @param int $code HTTP status code
     * @param array $body Response body data
     * @param array $headers Response headers
     * @return array Mock response array
     */
    protected function create_mock_response($code = 200, $body = array(), $headers = array()) {
        return array(
            'response' => array(
                'code' => $code,
                'message' => $this->get_status_message($code)
            ),
            'body' => json_encode($body),
            'headers' => $headers
        );
    }
    
    /**
     * Get HTTP status message
     */
    private function get_status_message($code) {
        $messages = array(
            200 => 'OK',
            201 => 'Created',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            404 => 'Not Found',
            500 => 'Internal Server Error'
        );
        return $messages[$code] ?? 'Unknown';
    }
}
