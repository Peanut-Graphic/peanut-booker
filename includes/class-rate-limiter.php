<?php
/**
 * Rate Limiter for API endpoints
 *
 * Uses WordPress transients for simple rate limiting without external dependencies.
 *
 * @package Peanut_Booker
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Booker_Rate_Limiter {

    /**
     * Default rate limits per endpoint type (requests per window)
     */
    private static array $limits = [
        'booking' => ['limit' => 10, 'window' => 60],          // 10 booking attempts per minute
        'message' => ['limit' => 20, 'window' => 60],          // 20 messages per minute
        'review' => ['limit' => 5, 'window' => 300],           // 5 reviews per 5 minutes
        'signup' => ['limit' => 5, 'window' => 300],           // 5 signups per 5 minutes
        'general' => ['limit' => 60, 'window' => 60],          // 60 requests per minute (for GET)
        'tracking' => ['limit' => 30, 'window' => 60],         // 30 public microsite tracking pings per minute
    ];

    /**
     * Check if the request should be rate limited
     *
     * @param string $action The action type (booking, message, review, signup, general)
     * @param string|null $identifier Optional identifier (defaults to IP)
     * @return array ['allowed' => bool, 'remaining' => int, 'reset' => int]
     */
    public static function check(string $action, ?string $identifier = null): array {
        $identifier = $identifier ?? self::get_identifier();
        $config = self::$limits[$action] ?? self::$limits['general'];

        $key = self::get_key($action, $identifier);
        $data = get_transient($key);

        $now = time();

        if ($data === false) {
            // First request - initialize
            $data = [
                'count' => 1,
                'window_start' => $now,
            ];
            set_transient($key, $data, $config['window']);

            return [
                'allowed' => true,
                'remaining' => $config['limit'] - 1,
                'reset' => $now + $config['window'],
            ];
        }

        // Check if window has expired (transient should handle this, but double-check)
        if ($now - $data['window_start'] >= $config['window']) {
            // New window
            $data = [
                'count' => 1,
                'window_start' => $now,
            ];
            set_transient($key, $data, $config['window']);

            return [
                'allowed' => true,
                'remaining' => $config['limit'] - 1,
                'reset' => $now + $config['window'],
            ];
        }

        // Within window - check limit
        if ($data['count'] >= $config['limit']) {
            $reset = $data['window_start'] + $config['window'];

            // Log the rate limit hit
            self::log_rate_limit($action, $identifier);

            return [
                'allowed' => false,
                'remaining' => 0,
                'reset' => $reset,
            ];
        }

        // Increment and allow
        $data['count']++;
        set_transient($key, $data, $config['window'] - ($now - $data['window_start']));

        return [
            'allowed' => true,
            'remaining' => $config['limit'] - $data['count'],
            'reset' => $data['window_start'] + $config['window'],
        ];
    }

    /**
     * Check rate limit and respond if exceeded (alias for enforce)
     *
     * @param string $action The action type
     * @param string|null $identifier Optional identifier
     * @return \WP_REST_Response|null Null if allowed, WP_REST_Response if limited
     */
    public static function check_or_respond(string $action, ?string $identifier = null): ?\WP_REST_Response {
        return self::enforce($action, $identifier);
    }

    /**
     * Enforce rate limiting - returns WP_REST_Response if limited
     *
     * @param string $action The action type
     * @param string|null $identifier Optional identifier
     * @return \WP_REST_Response|null Null if allowed, WP_REST_Response if limited
     */
    public static function enforce(string $action, ?string $identifier = null): ?\WP_REST_Response {
        $result = self::check($action, $identifier);

        if (!$result['allowed']) {
            $response = new \WP_REST_Response([
                'success' => false,
                'code' => 'rate_limit_exceeded',
                'message' => 'Too many requests. Please try again later.',
                'retry_after' => $result['reset'] - time(),
            ], 429);

            $response->header('X-RateLimit-Limit', self::get_limit($action));
            $response->header('X-RateLimit-Remaining', $result['remaining']);
            $response->header('X-RateLimit-Reset', $result['reset']);
            $response->header('Retry-After', $result['reset'] - time());

            return $response;
        }

        return null;
    }

    /**
     * Get the limit for an action
     *
     * @param string $action The action type
     * @return int The limit
     */
    public static function get_limit(string $action): int {
        return self::$limits[$action]['limit'] ?? self::$limits['general']['limit'];
    }

    /**
     * Get the window for an action
     *
     * @param string $action The action type
     * @return int The window in seconds
     */
    public static function get_window(string $action): int {
        return self::$limits[$action]['window'] ?? self::$limits['general']['window'];
    }

    /**
     * Reset rate limit for an identifier
     *
     * @param string $action The action type
     * @param string|null $identifier Optional identifier
     */
    public static function reset(string $action, ?string $identifier = null): void {
        $identifier = $identifier ?? self::get_identifier();
        $key = self::get_key($action, $identifier);
        delete_transient($key);
    }

    /**
     * Get client identifier (hashed IP for privacy)
     *
     * @return string Hashed identifier
     */
    private static function get_identifier(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        // Check for proxied IP
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($forwarded[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        }

        // Hash IP for privacy
        return wp_hash($ip . wp_salt('auth'));
    }

    /**
     * Generate transient key
     *
     * @param string $action The action type
     * @param string $identifier The identifier
     * @return string The transient key
     */
    private static function get_key(string $action, string $identifier): string {
        // Transient names are limited to 172 characters
        $hash = substr(md5($identifier), 0, 16);
        return 'pb_rl_' . $action . '_' . $hash;
    }

    /**
     * Enforce rate limiting for AJAX requests
     *
     * @param string $action The action type
     * @param string|null $identifier Optional identifier
     * @return bool True if allowed, sends JSON error and dies if limited
     */
    public static function enforce_ajax(string $action, ?string $identifier = null): bool {
        $result = self::check($action, $identifier);

        if (!$result['allowed']) {
            wp_send_json_error([
                'code' => 'rate_limit_exceeded',
                'message' => __('Too many requests. Please try again later.', 'peanut-booker'),
                'retry_after' => $result['reset'] - time(),
            ], 429);
            // wp_send_json_error calls die(), but just in case:
            die();
        }

        return true;
    }

    /**
     * Log rate limit events
     *
     * @param string $action The action that was rate limited
     * @param string $identifier The identifier that was limited
     */
    private static function log_rate_limit(string $action, string $identifier): void {
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'plugin' => 'peanut-booker',
            'event' => 'rate_limit_exceeded',
            'action' => $action,
            'identifier_hash' => substr($identifier, 0, 8) . '...',
        ];

        error_log('Peanut Booker Rate Limit: ' . wp_json_encode($log_entry));

        // Fire action for external monitoring
        do_action('peanut_booker_rate_limit', $action, $identifier);
    }

    /**
     * Add rate limit headers to a response
     *
     * @param \WP_REST_Response $response The response
     * @param string $action The action type
     * @param string|null $identifier Optional identifier
     * @return \WP_REST_Response The response with headers
     */
    public static function add_headers(\WP_REST_Response $response, string $action, ?string $identifier = null): \WP_REST_Response {
        $identifier = $identifier ?? self::get_identifier();
        $config = self::$limits[$action] ?? self::$limits['general'];
        $key = self::get_key($action, $identifier);
        $data = get_transient($key);

        $remaining = $config['limit'];
        $reset = time() + $config['window'];

        if ($data !== false) {
            $remaining = max(0, $config['limit'] - $data['count']);
            $reset = $data['window_start'] + $config['window'];
        }

        $response->header('X-RateLimit-Limit', $config['limit']);
        $response->header('X-RateLimit-Remaining', $remaining);
        $response->header('X-RateLimit-Reset', $reset);

        return $response;
    }
}
