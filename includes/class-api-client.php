<?php
/**
 * API Client for Livraria Plugin
 */

if (!defined('ABSPATH')) {
    exit;
}

class Livraria_API_Client {

    /**
     * Default API base URL for a fresh install (the production API).
     */
    const PRODUCTION_API_URL = 'https://www.api.livraria.ro/';

    /**
     * The Livraria API hosts (production and uat). Outside development mode
     * the plugin talks to an https:// URL on one of these hosts and nothing
     * else. The apex hosts answer 301 to their www form.
     */
    const ALLOWED_API_HOSTS = array('www.api.livraria.ro', 'api.livraria.ro', 'www.api-uat.livraria.ro', 'api-uat.livraria.ro');

    private $api_base_url;
    private $api_token;
    private $token_expires_at;
    
    public function __construct($api_base_url = null) {
        $this->api_base_url = self::resolve_base_url($api_base_url);
        $this->api_token = get_option('livraria_api_token', '');
        $this->token_expires_at = get_option('livraria_token_expires_at', 0);
    }

    /**
     * Development mode: wp-config.php enables WP_DEBUG and also defines
     * LIVRARIA_DEV_MODE as boolean true. Only then may the API base URL be
     * changed and debug lines be logged. Nothing a shop can set from wp-admin
     * turns it on.
     *
     * @return bool
     */
    public static function is_dev_mode() {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }
        return defined('LIVRARIA_DEV_MODE') && LIVRARIA_DEV_MODE === true;
    }

    /**
     * The API base URL to use: $candidate, else the stored
     * courier_api_base_url, else PRODUCTION_API_URL. It is not checked here;
     * every request checks is_allowed_api_url() first.
     *
     * @param string|null $candidate URL asked for by the caller
     * @return string
     */
    public static function resolve_base_url($candidate = null) {
        $candidate = (string) $candidate;
        if ($candidate === '') {
            $candidate = (string) get_option('courier_api_base_url', '');
        }
        return $candidate !== '' ? $candidate : self::PRODUCTION_API_URL;
    }

    /**
     * Whether the plugin may talk to $url. In development mode any http(s)
     * URL; otherwise https:// on an ALLOWED_API_HOSTS host, default port, no
     * credentials in the URL.
     *
     * @param string $url
     * @return bool
     */
    public static function is_allowed_api_url($url) {
        $parts = wp_parse_url((string) $url);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if (self::is_dev_mode()) {
            return $scheme === 'https' || $scheme === 'http';
        }
        if ($scheme !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        return in_array(strtolower($parts['host']), self::ALLOWED_API_HOSTS, true);
    }

    /**
     * Host of $url for messages and logs, reduced to host characters
     *
     * @param string $url
     * @return string
     */
    public static function url_host($url) {
        $host = wp_parse_url((string) $url, PHP_URL_HOST);
        return is_string($host) ? preg_replace('/[^A-Za-z0-9.:\-]/', '', $host) : '';
    }

    /**
     * The stored courier_api_base_url when it is not a Livraria API address
     * (outside development mode); '' when it is fine.
     *
     * @return string
     */
    public static function blocked_api_url() {
        if (self::is_dev_mode()) {
            return '';
        }
        $stored = (string) get_option('courier_api_base_url', '');
        return ($stored !== '' && !self::is_allowed_api_url($stored)) ? $stored : '';
    }

    /**
     * Check the stored API base URL on load. A stored URL is never changed:
     * - none stored (fresh install): store PRODUCTION_API_URL;
     * - on an allowed host: nothing to do;
     * - anything else (outside development mode): the plugin stops. Every
     *   request refuses to run, the API session is dropped, wp-admin shows a
     *   notice, and one line is logged per offending URL. A successful login
     *   from the settings page clears it.
     */
    public static function check_stored_api_url() {
        $stored = (string) get_option('courier_api_base_url', '');
        if ($stored === '') {
            update_option('courier_api_base_url', self::PRODUCTION_API_URL);
            return;
        }

        $blocked = self::blocked_api_url();
        if ($blocked === '') {
            if ((string) get_option('livraria_api_url_blocked', '') !== '') {
                delete_option('livraria_api_url_blocked');
            }
            return;
        }
        if ((string) get_option('livraria_api_url_blocked', '') === $blocked) {
            return; // already handled and logged
        }

        update_option('livraria_api_url_blocked', $blocked);
        delete_option('livraria_api_token');
        delete_option('livraria_token_expires_at');

        $host = self::url_host($blocked);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- One security line: the stored API URL is not a Livraria host
        error_log('Livraria: the stored API base URL points at "' . ($host !== '' ? $host : 'an unparseable URL') . '", which is not a Livraria API host; API calls are stopped and the shop is logged out of the API until an administrator logs in again from Settings > Livraria. Check who changed the plugin settings.');
    }

    /**
     * Guard at the start of every request
     *
     * @return bool True when this client's base URL may be used
     */
    private function base_url_allowed() {
        if (empty($this->api_base_url)) {
            $this->log_error('API base URL not configured');
            return false;
        }
        if (!self::is_allowed_api_url($this->api_base_url)) {
            $this->log_error('API base URL is not a Livraria API host (' . self::url_host($this->api_base_url) . '); request refused');
            return false;
        }
        return true;
    }
    
    // -------------------------------------------------------------------------
    // API session: access token (one hour) + refresh token (a few days)
    // -------------------------------------------------------------------------

    /** The refresh token, stored with set_encrypted_option() */
    const OPT_REFRESH_TOKEN = 'livraria_refresh_token';
    /** '' or 'expired' */
    const OPT_SESSION_STATE = 'livraria_session_state';
    const OPT_SESSION_EXPIRED_AT = 'livraria_session_expired_at';
    const OPT_CONNECTED_AT = 'livraria_connected_at';
    /** 1.0.x stored the password here. 1.1.0 reads it once to migrate, then deletes it; nothing writes it. */
    const OPT_LEGACY_PASSWORD = 'courier_api_password';

    /**
     * At most one refresh (or migration login) per PHP request, so a failing
     * API (429, 5xx, network) is not hammered by every call on a page.
     */
    private static $refresh_tried = false;

    /**
     * Forget that this request already tried a refresh (unit tests run many
     * "requests" in one process)
     */
    public static function reset_refresh_guard() {
        self::$refresh_tried = false;
    }

    /**
     * Login to get JWT token. Uses the password once and never stores it: on
     * success the access token, the refresh token (encrypted), the username
     * and the connection time are stored, and any 1.0.x password is deleted.
     * A failed login changes nothing that is stored.
     * 
     * @param string $username Username/email
     * @param string $password Password
     * @return array success, message, code (HTTP status, 0 = no response), refresh_stored
     */
    public function login($username, $password) {
        // Debug: Log the attempt (never the username, password or response)
        $this->log_debug('Login attempt to URL: ' . $this->api_base_url);

        $r = $this->request_json('POST', '/auth/login', array(
            'email' => $username,
            'password' => $password
        ));

        $body = is_array($r['data']) ? $r['data'] : array();
        // {"success": true, "data": {"accessToken", "refreshToken", "expiresIn", ...}}
        $data = (isset($body['data']) && is_array($body['data'])) ? $body['data'] : $body;
        $token = null;
        foreach (array('accessToken', 'access_token', 'token') as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                $token = $data[$key];
                break;
            }
        }

        if ($r['code'] >= 200 && $r['code'] < 300 && $token !== null) {
            $expires_in = isset($data['expiresIn']) ? (int) $data['expiresIn'] : (isset($data['expires_in']) ? (int) $data['expires_in'] : 3600);
            $this->store_access_token($token, $expires_in);

            $refresh = '';
            foreach (array('refreshToken', 'refresh_token') as $key) {
                if (!empty($data[$key]) && is_string($data[$key])) {
                    $refresh = $data[$key];
                    break;
                }
            }
            $refresh_stored = $refresh !== '' && $this->set_encrypted_option(self::OPT_REFRESH_TOKEN, $refresh);
            if (!$refresh_stored) {
                delete_option(self::OPT_REFRESH_TOKEN);
            }

            if (!$this->set_encrypted_option('courier_api_username', $username)) {
                update_option('courier_api_username', $username); // an email address, not a secret
            }
            update_option(self::OPT_CONNECTED_AT, time());
            self::forget_sender_profiles();
            delete_option(self::OPT_SESSION_STATE);
            delete_option(self::OPT_SESSION_EXPIRED_AT);
            delete_option(self::OPT_LEGACY_PASSWORD);
            delete_option('livraria_remember_credentials');
            self::$refresh_tried = false;

            $this->log_debug('Login succeeded' . ($refresh_stored ? '' : ' (no refresh token stored)'));
            return array(
                'success' => true,
                'message' => 'Login successful',
                'code' => $r['code'],
                'refresh_stored' => $refresh_stored,
                'expires_at' => $this->token_expires_at
            );
        }

        $this->log_debug('Login failed (HTTP ' . $r['code'] . ')');
        $error_msg = 'Login failed: ';
        if ($r['code'] === 0) {
            $error_msg .= 'No response from server';
        } elseif (self::error_message($r['data']) !== '') {
            $error_msg .= self::error_message($r['data']);
        } else {
            $error_msg .= 'Invalid credentials or server error';
        }

        return array(
            'success' => false,
            'message' => $error_msg,
            'code' => $r['code'],
            'refresh_stored' => false
        );
    }

    /**
     * Whether the shop has an API session to use: not expired, and an access
     * token, a refresh token or a 1.0.x password to migrate from
     *
     * @return bool
     */
    public function has_session() {
        if (self::is_session_expired()) {
            return false;
        }
        if ($this->api_token && $this->token_expires_at > time()) {
            return true;
        }
        return (string) get_option(self::OPT_REFRESH_TOKEN, '') !== ''
            || (string) get_option(self::OPT_LEGACY_PASSWORD, '') !== '';
    }

    /**
     * Refresh (or migrate) the session now if the access token is not valid,
     * e.g. before the settings page decides what to show
     *
     * @return bool True if an access token is available
     */
    public function ensure_session() {
        return $this->ensure_valid_token();
    }

    /**
     * @return bool True when the session ended and an administrator must log in again
     */
    public static function is_session_expired() {
        return get_option(self::OPT_SESSION_STATE, '') === 'expired';
    }

    /**
     * Forget the API session (logout). The username stays for the login form.
     */
    public static function clear_session() {
        self::forget_sender_profiles();
        delete_option('livraria_api_token');
        delete_option('livraria_token_expires_at');
        delete_option(self::OPT_REFRESH_TOKEN);
        delete_option(self::OPT_CONNECTED_AT);
        delete_option(self::OPT_SESSION_STATE);
        delete_option(self::OPT_SESSION_EXPIRED_AT);
        delete_option(self::OPT_LEGACY_PASSWORD);
        delete_option('livraria_remember_credentials');
    }

    private function store_access_token($token, $expires_in) {
        $this->api_token = $token;
        $this->token_expires_at = time() + max(60, (int) $expires_in);
        update_option('livraria_api_token', $this->api_token);
        update_option('livraria_token_expires_at', $this->token_expires_at);
    }

    /**
     * Make sure there is an access token to send:
     * - still valid: use it;
     * - else a refresh token: POST /auth/refresh (refresh_session());
     * - else a 1.0.x password: log in once with it and drop it (migrate_from_password());
     * - else, if there was a session, it has ended (mark_session_expired()).
     * 
     * @return bool True if a token is available for this request
     */
    private function ensure_valid_token() {
        // Check if token exists and is not expired (with 5 minute buffer)
        if ($this->api_token && $this->token_expires_at > (time() + 300)) {
            return true;
        }
        if (self::is_session_expired()) {
            return false;
        }
        if ((string) get_option(self::OPT_REFRESH_TOKEN, '') !== '') {
            return $this->refresh_session();
        }
        if ((string) get_option(self::OPT_LEGACY_PASSWORD, '') !== '') {
            return $this->migrate_from_password();
        }
        if ((string) get_option('livraria_api_token', '') !== '') {
            // An access token without a refresh token (e.g. no OpenSSL to store it)
            $this->mark_session_expired('access token expired, no refresh token');
        }
        return false;
    }

    /**
     * POST /auth/refresh with the stored refresh token.
     * - 2xx: new access token stored (and the rotated refresh token, if sent);
     * - 401 REFRESH_EXPIRED: the session is over (mark_session_expired());
     * - anything else (429, 5xx, network): this request fails, nothing changes.
     *
     * @return bool True when a new access token was stored
     */
    private function refresh_session() {
        if (self::$refresh_tried) {
            return false;
        }
        self::$refresh_tried = true;

        $refresh = $this->get_encrypted_option(self::OPT_REFRESH_TOKEN, '');
        if ($refresh === '') {
            if ((string) get_option(self::OPT_REFRESH_TOKEN, '') !== '') {
                // Stored but unreadable (e.g. the WordPress salts changed)
                $this->mark_session_expired('stored refresh token unreadable');
            }
            return false;
        }

        $r = $this->request_json('POST', '/auth/refresh', array('refreshToken' => $refresh));
        $body = is_array($r['data']) ? $r['data'] : array();
        $data = (isset($body['data']) && is_array($body['data'])) ? $body['data'] : array();

        if ($r['code'] >= 200 && $r['code'] < 300 && !empty($data['accessToken']) && is_string($data['accessToken'])) {
            $this->store_access_token($data['accessToken'], isset($data['expiresIn']) ? $data['expiresIn'] : 3600);
            if (!empty($data['refreshToken']) && is_string($data['refreshToken'])) {
                $this->set_encrypted_option(self::OPT_REFRESH_TOKEN, $data['refreshToken']); // rotated
            }
            $this->log_debug('Session refresh succeeded');
            return true;
        }

        if ($r['code'] === 401 && self::error_reason($r['data']) === 'REFRESH_EXPIRED') {
            $this->log_debug('Session refresh expired');
            $this->mark_session_expired('refresh token expired');
            $this->set_last_error(401, self::SESSION_EXPIRED_MESSAGE, $r['data']);
            return false;
        }

        $this->log_debug('Session refresh failed (HTTP ' . $r['code'] . ')');
        $this->set_last_error(
            $r['code'],
            'Could not renew the Livraria session: ' . ($r['code'] === 0 ? $this->no_response_message($r) : self::message_from_error_body($r['data'], $r['code'])),
            $r['data']
        );
        return false;
    }

    /**
     * 1.0.x stored the password ("Remember me"). Log in with it once, keep
     * the refresh token, delete the password. A 401 (password changed)
     * deletes it too and ends the session; 429, 5xx or no response keep it
     * for the next attempt.
     *
     * @return bool True when the login succeeded
     */
    private function migrate_from_password() {
        if (self::$refresh_tried) {
            return false;
        }
        self::$refresh_tried = true;

        $password = $this->get_encrypted_option(self::OPT_LEGACY_PASSWORD, '');
        $username = $this->get_encrypted_option('courier_api_username', '');
        if ($password === '' || $username === '') {
            delete_option(self::OPT_LEGACY_PASSWORD); // unreadable or unusable
            return false;
        }

        $result = $this->login($username, $password);
        if ($result['success']) {
            $this->log_debug('Migrated the 1.0.x stored password to a refresh token');
            return true;
        }
        if ($result['code'] === 401) {
            delete_option(self::OPT_LEGACY_PASSWORD);
            $this->mark_session_expired('stored 1.0.x password rejected');
        }
        return false;
    }

    /**
     * The session is over: drop every token and, the first time, tell the
     * site administrator by email. wp-admin shows a notice until someone
     * logs in again.
     *
     * @param string $why For the dev-mode debug line
     */
    private function mark_session_expired($why) {
        $this->api_token = '';
        $this->token_expires_at = 0;
        delete_option('livraria_api_token');
        delete_option('livraria_token_expires_at');
        delete_option(self::OPT_REFRESH_TOKEN);
        self::forget_sender_profiles();

        if (self::is_session_expired()) {
            return;
        }
        update_option(self::OPT_SESSION_STATE, 'expired');
        update_option(self::OPT_SESSION_EXPIRED_AT, time());
        $this->log_debug('Session expired: ' . $why);
        self::send_session_expired_email();
    }

    /**
     * One plain-text email to the site administrator: no tokens, no shop data
     */
    private static function send_session_expired_email() {
        if (!function_exists('wp_mail')) {
            return;
        }
        $to = (string) get_option('admin_email', '');
        if ($to === '') {
            return;
        }
        $settings_url = function_exists('admin_url') ? admin_url('options-general.php?page=courier-api-settings') : '';
        $body = "The Livraria plugin is no longer connected to your Livraria account: its session has ended.\n\n"
            . "Until an administrator logs in again, the plugin cannot get quotes or create shipments.\n\n"
            . "Log in again in WordPress, under Settings > Livraria:\n"
            . $settings_url . "\n";
        wp_mail($to, 'Livraria: log in again to keep creating shipments', $body);
    }

    /**
     * The error text of an API error envelope, '' when the body carries none
     * (see backend_message())
     *
     * @param mixed $data Decoded response body
     * @return string
     */
    private static function error_message($data) {
        return self::backend_message($data);
    }

    // -------------------------------------------------------------------------
    // Last error: the backend's own words about the last failed call
    // -------------------------------------------------------------------------

    /** What the order panel shows while the session is over (401 REFRESH_EXPIRED) */
    const SESSION_EXPIRED_MESSAGE = 'Livraria session expired, log in again: an administrator must log in from Settings > Livraria.';

    /**
     * The last failed call of this client: status (HTTP, 0 = no response or
     * not sent), message (for the merchant), code, missingRecipientFields,
     * reason. Null after a call that succeeded.
     *
     * @var array|null
     */
    private $last_error = null;

    /**
     * @return array|null status, message, code, missingRecipientFields, reason
     */
    public function get_last_error() {
        return $this->last_error;
    }

    /**
     * @return string The merchant-readable message of the last failure, '' if none
     */
    public function last_error_message() {
        return is_array($this->last_error) ? (string) $this->last_error['message'] : '';
    }

    /**
     * @return string The body's `code` of the last failure (e.g. RECIPIENT_FIELDS_REQUIRED), '' if none
     */
    public function last_error_code() {
        return is_array($this->last_error) ? (string) $this->last_error['code'] : '';
    }

    /**
     * @return int HTTP status of the last failure, 0 when no response came back
     */
    public function last_error_status() {
        return is_array($this->last_error) ? (int) $this->last_error['status'] : 0;
    }

    public function clear_last_error() {
        $this->last_error = null;
    }

    /**
     * @param int $status HTTP status, 0 = no response / not sent
     * @param string $message For the merchant
     * @param mixed $data Decoded error body, for code / missingRecipientFields / reason
     */
    private function set_last_error($status, $message, $data = null) {
        $details = self::error_details($data);
        $this->last_error = array(
            'status' => (int) $status,
            'message' => (string) $message,
            'code' => $details['code'],
            'missingRecipientFields' => $details['missingRecipientFields'],
            'reason' => $details['reason'],
        );
    }

    /**
     * The backend's own sentence in an error body, '' when it sent none.
     * Precedence: nested envelope message.error; NestJS validation list
     * message[] (first non-blank); string message; top-level error.
     *
     * @param mixed $data Decoded response body
     * @return string
     */
    public static function backend_message($data) {
        if (!is_array($data)) {
            return '';
        }
        if (isset($data['message'])) {
            $message = $data['message'];
            if (is_array($message)) {
                if (isset($message['error']) && is_string($message['error']) && trim($message['error']) !== '') {
                    return trim($message['error']);
                }
                if (array_key_exists(0, $message)) {
                    foreach ($message as $item) {
                        if (is_string($item) && trim($item) !== '') {
                            return trim($item);
                        }
                    }
                }
            } elseif (is_string($message) && trim($message) !== '') {
                return trim($message);
            }
        }
        if (isset($data['error']) && is_string($data['error']) && trim($data['error']) !== '') {
            return trim($data['error']);
        }
        return '';
    }

    /**
     * What the merchant reads for a non-2xx answer: the backend's own
     * sentence (backend_message()), else a canned line for 401/403/404/429,
     * else "The Livraria API returned HTTP N."
     *
     * @param mixed $data Decoded response body
     * @param int $status HTTP status
     * @return string
     */
    public static function message_from_error_body($data, $status) {
        $message = self::backend_message($data);
        if ($message !== '') {
            return $message;
        }
        switch ((int) $status) {
            case 401:
                return 'The Livraria session is no longer valid. Log in again under Settings > Livraria.';
            case 403:
                return 'The Livraria API refused this request (HTTP 403).';
            case 404:
                return 'The Livraria API could not find what this request asked for (HTTP 404).';
            case 429:
                return 'Too many requests to the Livraria API. Wait a moment and try again.';
            default:
                return 'The Livraria API returned HTTP ' . (int) $status . '.';
        }
    }

    /**
     * code, missingRecipientFields and reason of an error body, at the top
     * level or inside the nested `message` envelope
     *
     * @param mixed $data Decoded response body
     * @return array code (string), missingRecipientFields (string[]), reason (string)
     */
    public static function error_details($data) {
        $details = array('code' => '', 'missingRecipientFields' => array(), 'reason' => '');
        if (!is_array($data)) {
            return $details;
        }
        $nested = (isset($data['message']) && is_array($data['message']) && !array_key_exists(0, $data['message']))
            ? $data['message']
            : array();

        foreach (array($data, $nested) as $source) {
            if ($details['code'] === '' && isset($source['code']) && is_string($source['code'])) {
                $details['code'] = $source['code'];
            }
            if (empty($details['missingRecipientFields']) && isset($source['missingRecipientFields']) && is_array($source['missingRecipientFields'])) {
                $details['missingRecipientFields'] = array_values(array_filter($source['missingRecipientFields'], 'is_string'));
            }
        }
        $details['reason'] = self::error_reason($data);
        return $details;
    }

    /**
     * message.reason of an API error envelope, e.g. REFRESH_EXPIRED
     *
     * @param mixed $data Decoded response body
     * @return string
     */
    private static function error_reason($data) {
        if (is_array($data) && isset($data['message']) && is_array($data['message']) && isset($data['message']['reason'])) {
            return (string) $data['message']['reason'];
        }
        if (is_array($data) && isset($data['reason'])) {
            return (string) $data['reason'];
        }
        return '';
    }
    
    /**
     * Test login with given credentials and verify API access
     * 
     * @param string $username Username/email  
     * @param string $password Password
     * @return array Result with success status and message
     */
    public function test_login($username, $password) {
        if (empty($username) || empty($password)) {
            return array(
                'success' => false,
                'message' => 'Username and password are required'
            );
        }
        
        // Step 1: Try to login and get token
        $login_result = $this->login($username, $password);
        
        if (!$login_result['success']) {
            $error_msg = $login_result['message'];
            if ($login_result['code'] === 0) {
                $error_msg .= ' (No response from server - check URL and network)';
            }
            return array(
                'success' => false,
                'message' => $error_msg
            );
        }
        
        return $this->test_api_access('Authentication successful! Login works and API access confirmed.');
    }

    /**
     * Check that the stored session reaches the API (refreshing it if needed)
     *
     * @return array Result with success status and message
     */
    public function test_session() {
        if (self::is_session_expired()) {
            return array('success' => false, 'message' => 'Login failed: the Livraria session expired. Log in again.');
        }
        if (!$this->has_session()) {
            return array('success' => false, 'message' => 'Login failed: not connected. Log in first.');
        }
        return $this->test_api_access('The stored session works: API access confirmed.');
    }

    private function test_api_access($success_message) {
        $test_response = $this->api_request('GET', '/expedition');
        if ($test_response !== false) {
            return array(
                'success' => true,
                'message' => $success_message,
                'token_expires_at' => gmdate('Y-m-d H:i:s', $this->token_expires_at)
            );
        }
        return array(
            'success' => false,
            'message' => 'Login successful but API access failed. Check API permissions.'
        );
    }
    
    /**
     * Create a quote request
     * 
     * @param array $quote_data Quote request data
     * @return array|false API response or false on failure
     */
    public function create_quote_request($quote_data) {
        return $this->api_request('POST', '/public/awb/quotes', $quote_data);
    }
    
    /**
     * Get courier quotes for a quote request
     * NOTE: This method is deprecated - quotes are now returned directly from create_quote_request()
     * 
     * @param string $quote_request_id Quote request ID
     * @return array|false API response or false on failure
     */
    public function get_courier_quotes($quote_request_id) {
        // This method is no longer used - quotes come back from /public/awb/quotes directly
        return false;
    }
    
    /**
     * Select a courier quote for a quote request
     * 
     * @param string $quote_request_id Quote request ID
     * @param string $courier_quote_id Courier quote ID to select
     * @return array|false API response or false on failure
     */
    public function select_courier_quote($quote_request_id, $courier_quote_id) {
        return $this->api_request('PUT', '/quote-request/' . $quote_request_id . '/selected-courier-quote', array(
            'courierQuoteId' => $courier_quote_id
        ));
    }
    
    /**
     * Attach billing info from sender profile to a quote request
     * 
     * @param string $quote_request_id Quote request ID
     * @param string $sender_profile_id Sender profile ID
     * @return array|false API response or false on failure
     */
    public function attach_billing_info_from_sender_profile($quote_request_id, $sender_profile_id) {
        return $this->api_request('POST', '/quote-request/' . $quote_request_id . '/attach-billing-info-from-sender-profile', array(
            'senderProfileId' => $sender_profile_id
        ));
    }
    
    /**
     * Create an expedition
     * 
     * @param array $expedition_data Expedition data
     * @return array|false API response or false on failure
     */
    public function create_expedition($expedition_data) {
        return $this->api_request('POST', '/expedition', $expedition_data);
    }
    
    /**
     * Get expedition by ID
     * 
     * @param string $expedition_id Expedition ID
     * @return array|false API response or false on failure
     */
    public function get_expedition($expedition_id) {
        return $this->api_request('GET', '/expedition/' . $expedition_id);
    }
    
    /**
     * Get expedition by tracking number
     * 
     * @param string $tracking_number AWB/tracking number
     * @return array|false API response or false on failure
     */
    public function get_expedition_by_tracking($tracking_number) {
        return $this->api_request('GET', '/expedition/tracking?trackingNumber=' . $tracking_number);
    }
    
    /**
     * Get user expeditions
     * 
     * @return array|false API response or false on failure
     */
    public function get_user_expeditions() {
        return $this->api_request('GET', '/expedition');
    }
    
    /**
     * Get current user information
     * 
     * @return array|false API response or false on failure
     */
    public function get_user_info() {
        return $this->api_request('GET', '/users/me');
    }
    
    /**
     * Get sender profile information
     *
     * @return array|false API response or false on failure
     */
    public function get_sender_profile() {
        $profiles = $this->api_request('GET', '/users/me/sender-profile');
        if ($profiles !== false) {
            // The order panel renders from this copy (cached_sender_profiles())
            set_transient(self::SENDER_PROFILES_TRANSIENT, $profiles, HOUR_IN_SECONDS);
        }
        return $profiles;
    }

    /** Transient holding the last GET /users/me/sender-profile answer */
    const SENDER_PROFILES_TRANSIENT = 'livraria_sender_profiles';

    /**
     * The cached sender profiles, false when none are cached. No API call.
     *
     * @return mixed
     */
    public static function cached_sender_profiles() {
        return get_transient(self::SENDER_PROFILES_TRANSIENT);
    }

    /**
     * Drop the cached sender profiles (login, logout, session end, settings save)
     */
    public static function forget_sender_profiles() {
        delete_transient(self::SENDER_PROFILES_TRANSIENT);
        if (class_exists('Livraria_Lockers')) {
            Livraria_Lockers::forget_cache(); // the locker lists are the account's too
        }
    }

    /**
     * One courier's lockers (GET /pudo-points/by-courier), with the merchant's session
     *
     * @param string $courier "sameday" or "dpd"
     * @return array|false
     */
    public function get_pudo_points_by_courier($courier) {
        return $this->api_request('GET', '/pudo-points/by-courier?' . http_build_query(array('courier' => (string) $courier)));
    }

    /**
     * Lockers nearest to an address line, mixed couriers, nearest first
     * (GET /pudo-points/closest)
     *
     * @param string $address "street, number, city, county, postcode"
     * @param int $limit
     * @return array|false
     */
    public function get_closest_pudo_points($address, $limit = 10) {
        return $this->api_request('GET', '/pudo-points/closest?' . http_build_query(array('address' => (string) $address, 'limit' => (int) $limit)));
    }

    /**
     * Download AWB PDF
     *
     * @param string $awb_number AWB number
     * @param array $options Download options (format, paperSize, language)
     * @return array|false Binary PDF data with headers or false on failure
     */
    public function download_awb_pdf($awb_number, $options = array()) {
        // Default options
        $defaults = array(
            'format' => 'pdf',
            'paperSize' => 'A4',
            'language' => 'EN'
        );
        $options = array_merge($defaults, $options);

        // Build query string
        $query_params = http_build_query(array(
            'format' => $options['format'],
            'paperSize' => $options['paperSize'],
            'language' => $options['language']
        ));

        $endpoint = '/public/awb/print/' . urlencode($awb_number) . '?' . $query_params;

        $this->log_debug('Downloading AWB PDF: ' . $awb_number . ' with options: ' . json_encode($options));

        // Use special method for binary download
        return $this->api_request_binary('GET', $endpoint);
    }

    /**
     * Make API request for binary data (PDFs, images, etc.)
     * Like api_request(): one refresh and one retry on a 401.
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @return array|false Array with 'body', 'headers', 'content_type', 'content_length' or false on failure
     */
    private function api_request_binary($method, $endpoint) {
        $this->clear_last_error();
        if (!$this->base_url_allowed()) {
            $this->set_last_error(0, self::BLOCKED_URL_MESSAGE);
            return false;
        }

        // Ensure valid token
        if (!$this->ensure_valid_token()) {
            $this->log_error('Unable to obtain valid API token for binary request');
            $this->set_no_session_error();
            return false;
        }

        $url = rtrim($this->api_base_url, '/') . $endpoint;
        $url = $this->convert_localhost_for_docker($url);
        $this->log_debug('Binary API Request: ' . $method . ' ' . $url);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $args = array(
                'method' => $method,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_token,
                    'Accept' => 'application/pdf',
                    'X-Livraria-Client' => $this->client_header()
                ),
                'timeout' => 60, // Longer timeout for PDF generation
                'user-agent' => 'WordPress-Livraria-Plugin/1.0',
                'sslverify' => self::ssl_verify()
            );

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $this->log_error('Binary API Request Failed: ' . $response->get_error_message());
                $this->set_last_error(0, 'No response from the Livraria API (' . $response->get_error_message() . ').');
                return false;
            }

            $response_code = (int) wp_remote_retrieve_response_code($response);
            if ($response_code === 401 && $attempt === 0 && $this->refresh_session()) {
                continue; // one retry with the refreshed token
            }
            break;
        }

        $body = wp_remote_retrieve_body($response);
        if ($response_code >= 200 && $response_code < 300) {
            $headers = wp_remote_retrieve_headers($response);

            $this->log_debug('Binary API Request Successful: ' . strlen($body) . ' bytes received');

            return array(
                'body' => $body,
                'headers' => $headers,
                'content_type' => $headers['content-type'] ?? 'application/pdf',
                'content_length' => $headers['content-length'] ?? strlen($body)
            );
        }

        $this->log_error('Binary API Error: HTTP ' . $response_code . ' (' . strlen($body) . ' bytes)');
        $data = json_decode((string) $body, true);
        $this->set_last_error($response_code, $this->failed_status_message($response_code, $data), $data);
        return false;
    }


    /**
     * Value of the X-Livraria-Client header sent on every request to the
     * Livraria API: this plugin's version, then the WordPress, WooCommerce and
     * PHP versions, e.g.
     * "Livraria-WordPress-Plugin/1.0.3; WordPress/6.7.1; WooCommerce/9.4.0; PHP/8.2.10".
     *
     * Each token is "name/version"; the version keeps only A-Z a-z 0-9 . + _ -
     * and at most 40 characters (the backend ignores a token outside that
     * rule), "unknown" when nothing is left. The User-Agent stays
     * "WordPress-Livraria-Plugin/1.0": the backend reads the platform from it.
     *
     * @return string Header value
     */
    private function client_header() {
        $versions = array(
            'Livraria-WordPress-Plugin' => defined('LIVRARIA_WC_PLUGIN_VERSION') ? LIVRARIA_WC_PLUGIN_VERSION : '',
            'WordPress' => function_exists('get_bloginfo') ? get_bloginfo('version') : '',
            'WooCommerce' => defined('WC_VERSION') ? WC_VERSION : '',
            'PHP' => PHP_VERSION,
        );

        $tokens = array();
        foreach ($versions as $name => $version) {
            $clean = substr(preg_replace('/[^A-Za-z0-9.+_-]/', '', (string) $version), 0, 40);
            $tokens[] = $name . '/' . ($clean !== '' ? $clean : 'unknown');
        }

        return implode('; ', $tokens);
    }

    /**
     * Whether requests verify the API's TLS certificate. Always, except on a
     * development install whose wp-config.php enables WP_DEBUG and also
     * defines LIVRARIA_ALLOW_INSECURE_TLS as boolean true (for a local API
     * with a self-signed certificate). A shop has no setting for it.
     *
     * @return bool
     */
    public static function ssl_verify() {
        return !self::allow_insecure_tls();
    }

    /**
     * @return bool Whether this install may skip TLS verification (both constants above, set by hand)
     */
    public static function allow_insecure_tls() {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return false;
        }
        return defined('LIVRARIA_ALLOW_INSECURE_TLS') && LIVRARIA_ALLOW_INSECURE_TLS === true;
    }

    /**
     * Convert localhost/127.0.0.1 to host.docker.internal for Docker
     * 
     * @param string $url Original URL
     * @return string Converted URL
     */
    private function convert_localhost_for_docker($url) {
        // Check if we're likely in Docker
        $is_docker = (
            file_exists('/.dockerenv') || 
            getenv('DOCKER_CONTAINER') !== false ||
            (function_exists('gethostname') && strpos(gethostname(), 'docker') !== false)
        );
        
        if ($is_docker) {
            // Replace localhost/127.0.0.1 with host.docker.internal
            $url = preg_replace(
                '/^(https?:\/\/)(127\.0\.0\.1|localhost)(:(\d+))?/i',
                '$1host.docker.internal$3',
                $url
            );
        }
        
        return $url;
    }
    
    /**
     * Send one JSON request to the API and return its status and decoded
     * body. Never logs a body outside development mode (log_debug redacts
     * passwords and tokens there too).
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request data
     * @param bool $with_auth Send the access token
     * @return array code (0 = no response or refused), data (decoded body or null), json_ok,
     *               transport ('' or why nothing came back, for code 0)
     */
    private function request_json($method, $endpoint, $data = null, $with_auth = false) {
        $result = array('code' => 0, 'data' => null, 'json_ok' => false, 'transport' => '');
        if (!$this->base_url_allowed()) {
            $result['transport'] = self::BLOCKED_URL_MESSAGE;
            return $result;
        }

        $url = rtrim($this->api_base_url, '/') . $endpoint;
        $url = $this->convert_localhost_for_docker($url);

        $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Livraria-Client' => $this->client_header()
        );
        if ($with_auth) {
            $headers['Authorization'] = 'Bearer ' . $this->api_token;
        }
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            'user-agent' => 'WordPress-Livraria-Plugin/1.0',
            'sslverify' => self::ssl_verify()
        );

        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
            $args['body'] = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            // Dev mode only, redacted
            $this->log_debug('API Request: ' . $method . ' ' . $url, $data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->log_error('API Request Failed: ' . $response->get_error_message());
            $result['transport'] = 'No response from the Livraria API (' . $response->get_error_message() . ').';
            return $result;
        }

        $result['code'] = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $this->log_debug('API Response: ' . $result['code'], $body);

        $decoded = json_decode((string) $body, true);
        $result['json_ok'] = json_last_error() === JSON_ERROR_NONE;
        $result['data'] = $result['json_ok'] ? $decoded : null;
        return $result;
    }

    /**
     * Turn a request_json() result into the decoded body, or false (logged)
     *
     * @param array $r request_json() result
     * @param string $label For the error line
     * @return mixed
     */
    private function json_result($r, $label) {
        if ($r['code'] >= 200 && $r['code'] < 300) {
            if (!$r['json_ok']) {
                $this->log_error('Invalid JSON response');
                $this->set_last_error($r['code'], 'The Livraria API returned a response this plugin could not read.');
                return false;
            }
            $this->clear_last_error();
            return $r['data'];
        }
        if ($r['code'] !== 0) {
            $message = self::error_message($r['data']);
            // Never the body itself: it can echo what was sent
            $this->log_error($label . ': HTTP ' . $r['code'] . ($message !== '' ? ': ' . $message : ' (no message field)'));
            $this->set_last_error($r['code'], $this->failed_status_message($r['code'], $r['data']), $r['data']);
        } else {
            $this->set_last_error(0, $this->no_response_message($r));
        }
        return false;
    }

    /** What every call says when the stored API URL is off the allow-list */
    const BLOCKED_URL_MESSAGE = 'The configured Livraria API address is not a Livraria API host, so nothing was sent. An administrator must log in again from Settings > Livraria.';

    /**
     * Message for a non-2xx answer: the reconnect text when a 401 ended the
     * session, else message_from_error_body()
     *
     * @param int $status
     * @param mixed $data Decoded body
     * @return string
     */
    private function failed_status_message($status, $data) {
        if ((int) $status === 401 && self::is_session_expired()) {
            return self::SESSION_EXPIRED_MESSAGE;
        }
        return self::message_from_error_body($data, $status);
    }

    /**
     * @param array $r request_json() result with code 0
     * @return string
     */
    private function no_response_message($r) {
        return (isset($r['transport']) && $r['transport'] !== '') ? $r['transport'] : 'No response from the Livraria API.';
    }

    /**
     * Last error when there is no access token to send: the reconnect text
     * when the session is over; else what the refresh said, if it said
     * anything; else "not connected".
     */
    private function set_no_session_error() {
        if (self::is_session_expired()) {
            $this->set_last_error(401, self::SESSION_EXPIRED_MESSAGE, array('message' => array('reason' => 'REFRESH_EXPIRED')));
            return;
        }
        if ($this->last_error === null) {
            $this->set_last_error(0, 'Not connected to Livraria: an administrator must log in from Settings > Livraria.');
        }
    }

    /**
     * Make a raw API request (without authentication)
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request data
     * @return array|false API response or false on failure
     */
    private function raw_api_request($method, $endpoint, $data = null) {
        $this->clear_last_error();
        return $this->json_result($this->request_json($method, $endpoint, $data), 'Raw API Error');
    }
    
    /**
     * Make an authenticated API request. A 401 to a token that looked valid
     * gets one refresh and one retry; a second 401 is a failure.
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request data
     * @return array|false API response or false on failure
     */
    private function api_request($method, $endpoint, $data = null) {
        $this->clear_last_error();
        if (!$this->base_url_allowed()) {
            $this->set_last_error(0, self::BLOCKED_URL_MESSAGE);
            return false;
        }
        
        // Ensure we have a valid token before making the request
        if (!$this->ensure_valid_token()) {
            $this->log_error('Unable to obtain valid API token');
            $this->set_no_session_error();
            return false;
        }
        
        $r = $this->request_json($method, $endpoint, $data, true);
        if ($r['code'] === 401 && $this->refresh_session()) {
            $r = $this->request_json($method, $endpoint, $data, true);
        }
        return $this->json_result($r, 'API Error');
    }
    
    /**
     * Log error message: when WP_DEBUG is on. Callers pass an HTTP status,
     * the API's message field or a WordPress error, never a request or
     * response body, a header, a token or a password.
     * 
     * @param string $message Error message
     */
    private function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Error logging only when WP_DEBUG is enabled, no secrets
            error_log('Livraria API Error: ' . $message);
        }
    }
    
    /**
     * Log debug message: development mode only (is_dev_mode()), with
     * passwords and tokens masked in $data. A shop's debug.log is often
     * readable by others (backups, support, a public wp-content/debug.log).
     * 
     * @param string $message Debug message
     * @param mixed $data Additional data to log (array or JSON string)
     */
    private function log_debug($message, $data = null) {
        if (!self::is_dev_mode()) {
            return;
        }
        $log_message = 'Livraria API Debug: ' . $message;
        if ($data !== null) {
            $log_message .= ' - ' . wp_json_encode(self::redact($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only in development mode, redacted
        error_log($log_message);
    }

    /**
     * Keys whose values are never logged (compared case-insensitively)
     */
    const REDACTED_KEYS = array(
        'password', 'accesstoken', 'access_token', 'refreshtoken', 'refresh_token', 'idtoken', 'id_token', 'token', 'authorization',
        // People and addresses (quote bodies, sender profiles): never in a log
        'name', 'firstname', 'lastname', 'companyname', 'company', 'cui', 'email', 'username', 'phone', 'phonensn',
        'address', 'street', 'streetnumber', 'block', 'staircase', 'floor', 'apartment', 'city', 'county', 'postcode',
        'localitypostcode', 'bankaccount', 'codiban', 'iban', 'shipmentnote', 'packagedescription',
    );

    /**
     * $data with every REDACTED_KEYS value replaced by "***", at any depth.
     * A JSON string is decoded first; any other string is reduced to its length.
     *
     * @param mixed $data
     * @return mixed
     */
    public static function redact($data) {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                return self::redact($decoded);
            }
            return '[' . strlen($data) . ' bytes]';
        }
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }
        return $data;
    }
    
    /**
     * Get API base URL
     * 
     * @return string
     */
    public function get_api_base_url() {
        return $this->api_base_url;
    }
    
    /**
     * Set API base URL
     * 
     * @param string $url
     */
    public function set_api_base_url($url) {
        $this->api_base_url = rtrim(self::resolve_base_url($url), '/');
    }
    
    /**
     * Set API token
     * 
     * @param string $token
     */
    public function set_api_token($token) {
        $this->api_token = $token;
    }
    
    /**
     * Get available couriers from public endpoint
     * 
     * @return array|false Array of courier objects or false on failure
     */
    public function get_available_couriers() {
        return $this->raw_api_request('GET', '/public/couriers');
    }
    
    /**
     * Romanian localities whose name matches $name (the backend matches
     * loosely: "Suceava" also returns "Poieni-Suceava"). Public endpoint.
     *
     * @param string $name City name as typed on the order
     * @return array|false List of { name, county, postcode } or false on failure
     */
    public function find_cities($name) {
        $response = $this->raw_api_request('GET', '/public/location/cities?' . http_build_query(array('name' => (string) $name)));
        return $response === false ? false : self::as_list($response);
    }

    /**
     * Postcode lookup; an empty list is a valid "no match". Public endpoint.
     *
     * @param array $query Any of postcode, county, city, streetName
     * @return array|false List of { county, city, postcode, streetName } or false on failure
     */
    public function find_postcodes($query) {
        $allowed = array_intersect_key((array) $query, array_flip(array('postcode', 'county', 'city', 'streetName')));
        $response = $this->raw_api_request('GET', '/public/location/postcodes?' . http_build_query($allowed));
        return $response === false ? false : self::as_list($response);
    }

    /**
     * A list from a response that may wrap it in { data: [...] } or be one object
     *
     * @param mixed $response
     * @return array
     */
    private static function as_list($response) {
        if (is_array($response) && isset($response['data']) && is_array($response['data'])) {
            $response = $response['data'];
        }
        if (!is_array($response) || $response === array()) {
            return array();
        }
        return array_key_exists(0, $response) ? array_values($response) : array($response);
    }

    /**
     * Check if API is configured
     * 
     * @return bool
     */
    public function is_configured() {
        return !empty($this->api_base_url) && !empty($this->api_token);
    }
    
    /**
     * Test basic connectivity to the API server
     * 
     * @return array Result with connectivity info
     */
    public function test_connectivity() {
        if (!$this->base_url_allowed()) {
            return array(
                'success' => false,
                'message' => 'The configured API address is not a Livraria API host'
            );
        }
        
        // Convert localhost/127.0.0.1 to host.docker.internal for Docker environments
        $original_url = $this->api_base_url;
        $test_url = $this->convert_localhost_for_docker($original_url);
        
        if ($test_url !== $original_url) {
            $this->log_debug('Docker detected: Converted ' . $original_url . ' to ' . $test_url);
        }
        
        // Try a simple GET request to the base URL or a health check endpoint
        $test_url = rtrim($test_url, '/') . '/';
        
        $args = array(
            'method' => 'GET',
            'headers' => array(
                'X-Livraria-Client' => $this->client_header()
            ),
            'timeout' => 10,
            'user-agent' => 'WordPress-Livraria-Plugin/1.0',
            'sslverify' => self::ssl_verify()
        );
        
        $this->log_debug('Testing connectivity to: ' . $test_url);
        
        $response = wp_remote_request($test_url, $args);
        
        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            $this->log_debug('Connectivity test failed: ' . $error);
            
            $error_message = 'Network error: ' . $error;
            
            // Provide helpful Docker-specific guidance
            if ($test_url !== $original_url && (strpos($original_url, 'localhost') !== false || strpos($original_url, '127.0.0.1') !== false)) {
                $error_message .= '. Tip: In Docker, use "host.docker.internal" instead of "localhost". The URL was automatically converted to: ' . $test_url;
            }
            
            return array(
                'success' => false,
                'message' => $error_message,
                'details' => array(
                    'url' => $test_url,
                    'original_url' => $original_url,
                    'error_code' => $response->get_error_code(),
                    'error_message' => $error
                )
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $this->log_debug('Connectivity test response code: ' . $response_code);
        
        return array(
            'success' => true,
            'message' => 'Server is reachable (HTTP ' . $response_code . ')',
            'details' => array(
                'url' => $test_url,
                'original_url' => $original_url,
                'response_code' => $response_code,
                'headers' => wp_remote_retrieve_headers($response)
            )
        );
    }
    
    /**
     * Get encrypted option value (public method for use outside class)
     * Uses WordPress encryption if available, falls back to plain text for backwards compatibility
     * 
     * @param string $option_name Option name
     * @param mixed $default Default value
     * @return string Decrypted value
     */
    public function get_encrypted_option($option_name, $default = '') {
        $encrypted_value = get_option($option_name, $default);
        
        // If empty, return default
        if (empty($encrypted_value)) {
            return $default;
        }
        
        // Check if value is encrypted (starts with encrypted prefix)
        if (strpos($encrypted_value, 'livraria_encrypted:') === 0) {
            // Extract encrypted data
            $encrypted_data = substr($encrypted_value, strlen('livraria_encrypted:'));
            
            // Try to decrypt using WordPress functions (WordPress 5.2+)
            if (function_exists('wp_salt') && function_exists('openssl_decrypt')) {
                $key = wp_salt('nonce');
                $iv_length = openssl_cipher_iv_length('AES-256-CBC');
                
                // Extract IV and encrypted data
                $data = base64_decode($encrypted_data);
                if ($data !== false && strlen($data) > $iv_length) {
                    $iv = substr($data, 0, $iv_length);
                    $encrypted = substr($data, $iv_length);
                    
                    $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
                    if ($decrypted !== false) {
                        return $decrypted;
                    }
                }
            }
            
            // If decryption fails, return empty (credentials need to be re-entered)
            return '';
        }
        
        // Not encrypted (backwards compatibility) - return as-is
        return $encrypted_value;
    }
    
    /**
     * Whether this server can encrypt stored credentials (PHP OpenSSL)
     *
     * @return bool
     */
    public static function can_encrypt() {
        return function_exists('wp_salt') && function_exists('openssl_encrypt') && function_exists('openssl_random_pseudo_bytes');
    }

    /**
     * Set encrypted option value (public method for use outside class)
     * Encrypts sensitive data (the refresh token) before storing. Without
     * encryption nothing is stored: a token in clear text is worse than none.
     * 
     * @param string $option_name Option name
     * @param string $value Value to encrypt and store
     * @return bool Success; false when the value could not be encrypted (nothing stored)
     */
    public function set_encrypted_option($option_name, $value) {
        // If value is empty, just store it
        if (empty($value)) {
            return update_option($option_name, '');
        }
        
        // Try to encrypt using WordPress functions (WordPress 5.2+)
        if (self::can_encrypt()) {
            $key = wp_salt('nonce');
            $iv_length = openssl_cipher_iv_length('AES-256-CBC');
            $iv = openssl_random_pseudo_bytes($iv_length);
            
            $encrypted = openssl_encrypt($value, 'AES-256-CBC', $key, 0, $iv);
            if ($encrypted !== false) {
                // Store with prefix and IV
                $encrypted_data = base64_encode($iv . $encrypted);
                return update_option($option_name, 'livraria_encrypted:' . $encrypted_data);
            }
        }
        
        // Encryption unavailable or failed: refuse to store
        $this->log_error('Could not encrypt ' . $option_name . ' (is PHP OpenSSL enabled?); not stored.');
        return false;
    }
}