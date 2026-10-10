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
        // Hash IP for privacy
        return wp_hash(self::get_client_ip() . wp_salt('auth'));
    }

    /**
     * The address of the client making this request.
     *
     * REMOTE_ADDR is the connecting peer and cannot be forged over TCP.
     * X-Forwarded-For / X-Real-IP are plain request headers the client can
     * set to anything, so they are used ONLY when the connecting peer is a
     * configured trusted proxy. There are none by default; configure them
     * with the `peanut_booker_trusted_proxies` option (array or comma list of
     * IPs / CIDR ranges) or the filter of the same name.
     *
     * Behind trusted proxies, X-Forwarded-For is read right to left and the
     * first address that is not itself a trusted proxy is the client (the
     * left-most entries are whatever the client sent).
     *
     * @return string Client IP address ('' if none can be determined).
     */
    public static function get_client_ip(): string {
        $remote = self::valid_ip($_SERVER['REMOTE_ADDR'] ?? '');
        if ('' === $remote) {
            return '';
        }

        $trusted = self::get_trusted_proxies();
        if (!$trusted || !self::ip_matches($remote, $trusted)) {
            return $remote;
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $hops = array_reverse(explode(',', (string) wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
            foreach ($hops as $hop) {
                $ip = self::valid_ip($hop);
                if ('' === $ip) {
                    // A malformed hop means the chain cannot be trusted past here.
                    break;
                }
                if (!self::ip_matches($ip, $trusted)) {
                    return $ip;
                }
            }
            return $remote;
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = self::valid_ip(wp_unslash($_SERVER['HTTP_X_REAL_IP']));
            if ('' !== $ip) {
                return $ip;
            }
        }

        return $remote;
    }

    /**
     * Configured trusted proxy addresses / CIDR ranges.
     *
     * @return string[]
     */
    private static function get_trusted_proxies(): array {
        $configured = get_option('peanut_booker_trusted_proxies', array());
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        /**
         * Filter the proxies whose X-Forwarded-For / X-Real-IP headers are trusted.
         *
         * @param string[] $proxies IPs or CIDR ranges. Default: none.
         */
        $configured = apply_filters('peanut_booker_trusted_proxies', (array) $configured);

        return array_values(array_filter(array_map('trim', array_map('strval', (array) $configured))));
    }

    /**
     * Return a trimmed, valid IP address, or '' when it is not one.
     *
     * @param mixed $value Candidate address.
     * @return string
     */
    private static function valid_ip($value): string {
        $value = trim((string) $value);
        return false !== filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    /**
     * Whether an IP matches any of the given IPs / CIDR ranges.
     *
     * @param string   $ip     IP address.
     * @param string[] $ranges IPs or CIDR ranges (IPv4 or IPv6).
     * @return bool
     */
    private static function ip_matches(string $ip, array $ranges): bool {
        $packed = @inet_pton($ip);
        if (false === $packed) {
            return false;
        }

        foreach ($ranges as $range) {
            $bits = null;
            if (false !== strpos($range, '/')) {
                [$range, $bits] = explode('/', $range, 2);
                $bits = (int) $bits;
            }
            $subnet = @inet_pton(trim($range));
            if (false === $subnet || strlen($subnet) !== strlen($packed)) {
                continue;
            }
            $max = strlen($packed) * 8;
            $bits = null === $bits ? $max : max(0, min($max, $bits));

            $bytes = intdiv($bits, 8);
            $rest  = $bits % 8;
            if (substr($packed, 0, $bytes) !== substr($subnet, 0, $bytes)) {
                continue;
            }
            if (0 === $rest) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($packed[$bytes]) & $mask) === (ord($subnet[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
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
