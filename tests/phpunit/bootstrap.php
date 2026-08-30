<?php
/**
 * PHPUnit Bootstrap for Peanut Booker
 *
 * Provides WordPress function mocks and test environment setup.
 *
 * @package Peanut_Booker\Tests
 */

// Define constants that WordPress would normally define.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'WPINC' ) ) {
    define( 'WPINC', 'wp-includes' );
}

if ( ! defined( 'PEANUT_BOOKER_VERSION' ) ) {
    define( 'PEANUT_BOOKER_VERSION', '2.0.0' );
}

if ( ! defined( 'PEANUT_BOOKER_DB_VERSION' ) ) {
    define( 'PEANUT_BOOKER_DB_VERSION', '1.3.0' );
}

if ( ! defined( 'PEANUT_BOOKER_PATH' ) ) {
    define( 'PEANUT_BOOKER_PATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'AUTH_KEY' ) ) {
    define( 'AUTH_KEY', 'test-auth-key-for-unit-testing-with-adequate-length' );
}

if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
    define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-for-unit-testing-with-adequate-length' );
}

// Output constants for testing.
if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'ARRAY_N' ) ) {
    define( 'ARRAY_N', 'ARRAY_N' );
}

// Mock WP_REST_Server constants.
if ( ! class_exists( 'WP_REST_Server' ) ) {
    class WP_REST_Server {
        const READABLE = 'GET';
        const CREATABLE = 'POST';
        const EDITABLE = 'POST, PUT, PATCH';
        const DELETABLE = 'DELETE';
    }
}

// Mock WP_REST_Response class.
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public $data;
        public $status;
        public $headers = array();

        public function __construct( $data = null, $status = 200, $headers = array() ) {
            $this->data = $data;
            $this->status = $status;
            $this->headers = $headers;
        }

        public function header( $key, $value ) {
            $this->headers[ $key ] = $value;
        }

        public function get_data() {
            return $this->data;
        }

        public function get_status() {
            return $this->status;
        }
    }
}

// Mock WP_REST_Request class.
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request implements ArrayAccess {
        private $params = array();
        private $route = '';
        private $method = 'GET';

        public function __construct( $method = 'GET', $route = '' ) {
            $this->method = $method;
            $this->route = $route;
        }

        public function set_param( $key, $value ) {
            $this->params[ $key ] = $value;
        }

        public function get_param( $key ) {
            return $this->params[ $key ] ?? null;
        }

        public function get_params() {
            return $this->params;
        }

        public function get_route() {
            return $this->route;
        }

        public function get_method() {
            return $this->method;
        }

        public function get_json_params() {
            return $this->params;
        }

        public function offsetExists( $offset ): bool {
            return isset( $this->params[ $offset ] );
        }

        public function offsetGet( $offset ): mixed {
            return $this->params[ $offset ] ?? null;
        }

        public function offsetSet( $offset, $value ): void {
            $this->params[ $offset ] = $value;
        }

        public function offsetUnset( $offset ): void {
            unset( $this->params[ $offset ] );
        }
    }
}

// Mock WP_Error class.
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        protected $code;
        protected $message;
        protected $data;
        protected $errors = array();
        protected $error_data = array();

        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( empty( $code ) ) {
                return;
            }

            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
            $this->errors[ $code ][] = $message;
            if ( ! empty( $data ) ) {
                $this->error_data[ $code ] = $data;
            }
        }

        public function get_error_code() {
            return $this->code;
        }

        public function get_error_message( $code = '' ) {
            if ( empty( $code ) ) {
                $code = $this->get_error_code();
            }
            $messages = $this->errors[ $code ] ?? array();
            return $messages[0] ?? '';
        }

        public function get_error_codes() {
            return array_keys( $this->errors );
        }

        public function get_error_data( $code = '' ) {
            if ( empty( $code ) ) {
                $code = $this->get_error_code();
            }
            return $this->error_data[ $code ] ?? null;
        }

        public function add( $code, $message, $data = '' ) {
            $this->errors[ $code ][] = $message;
            if ( ! empty( $data ) ) {
                $this->error_data[ $code ] = $data;
            }
        }

        public function has_errors() {
            return ! empty( $this->errors );
        }
    }
}

// Mock WP_Query class.
if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        public $posts = array();
        public $post_count = 0;
        public $found_posts = 0;
        public $max_num_pages = 0;
        public $current_post = -1;
        public $post;

        public function __construct( $args = array() ) {
            // Mock constructor - tests can inject posts.
        }

        public function have_posts() {
            return $this->current_post + 1 < $this->post_count;
        }

        public function the_post() {
            $this->current_post++;
            if ( isset( $this->posts[ $this->current_post ] ) ) {
                $this->post = $this->posts[ $this->current_post ];
            }
        }
    }
}

// Global wpdb mock storage.
global $wpdb;
$wpdb = new class {
    public $prefix = 'wp_';
    public $insert_id = 1;
    public $last_error = '';
    private $mock_results = array();
    private $mock_var = null;
    private $mock_row = null;

    public function prepare( $query, ...$args ) {
        if ( ! is_array( $args ) || count( $args ) === 0 ) {
            return $query;
        }

        // Handle when args is passed as array.
        if ( count( $args ) === 1 && is_array( $args[0] ) ) {
            $args = $args[0];
        }

        $placeholders = array_fill( 0, count( $args ), '%s' );
        foreach ( $args as $i => $arg ) {
            if ( is_int( $arg ) ) {
                $placeholders[ $i ] = '%d';
            }
        }

        return vsprintf( str_replace( array( '%s', '%d' ), $placeholders, $query ), $args );
    }

    public function insert( $table, $data, $format = null ) {
        $this->insert_id++;
        return 1;
    }

    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        return 1;
    }

    public function delete( $table, $where, $where_format = null ) {
        return 1;
    }

    public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
        return $this->mock_row;
    }

    public function get_results( $query = null, $output = OBJECT ) {
        return $this->mock_results;
    }

    public function get_var( $query = null, $x = 0, $y = 0 ) {
        return $this->mock_var;
    }

    public function get_col( $query = null, $x = 0 ) {
        return array();
    }

    public function query( $query ) {
        return true;
    }

    // Test helper methods.
    public function set_mock_results( $results ) {
        $this->mock_results = $results;
    }

    public function set_mock_var( $var ) {
        $this->mock_var = $var;
    }

    public function set_mock_row( $row ) {
        $this->mock_row = $row;
    }

    public function reset_mocks() {
        $this->mock_results = array();
        $this->mock_var = null;
        $this->mock_row = null;
        $this->insert_id = 1;
    }
};

// WordPress function mocks.
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = array() ) {
        if ( is_object( $args ) ) {
            $args = get_object_vars( $args );
        } elseif ( is_string( $args ) ) {
            parse_str( $args, $args );
        }
        return array_merge( $defaults, $args );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'sanitize_title' ) ) {
    function sanitize_title( $title, $fallback_title = '', $context = 'save' ) {
        return strtolower( preg_replace( '/[^a-zA-Z0-9-]/', '-', $title ) );
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $key ) {
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) {
        return abs( (int) $value );
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url, $protocols = null ) {
        return filter_var( $url, FILTER_SANITIZE_URL );
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $data ) {
        return strip_tags( $data, '<a><b><strong><i><em><p><br><ul><ol><li>' );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        return stripslashes( $value );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

// Mock options storage.
global $mock_options;
$mock_options = array();

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        global $mock_options;
        return $mock_options[ $option ] ?? $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value, $autoload = null ) {
        global $mock_options;
        $mock_options[ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( $option ) {
        global $mock_options;
        unset( $mock_options[ $option ] );
        return true;
    }
}

// Mock transients storage.
global $mock_transients;
$mock_transients = array();

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $transient ) {
        global $mock_transients;
        if ( ! isset( $mock_transients[ $transient ] ) ) {
            return false;
        }
        $data = $mock_transients[ $transient ];
        if ( isset( $data['expiration'] ) && $data['expiration'] < time() ) {
            unset( $mock_transients[ $transient ] );
            return false;
        }
        return $data['value'];
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $transient, $value, $expiration = 0 ) {
        global $mock_transients;
        $mock_transients[ $transient ] = array(
            'value'      => $value,
            'expiration' => $expiration > 0 ? time() + $expiration : 0,
        );
        return true;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( $transient ) {
        global $mock_transients;
        unset( $mock_transients[ $transient ] );
        return true;
    }
}

// Mock post meta storage.
global $mock_post_meta;
$mock_post_meta = array();

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key = '', $single = false ) {
        global $mock_post_meta;
        if ( empty( $key ) ) {
            return $mock_post_meta[ $post_id ] ?? array();
        }
        $value = $mock_post_meta[ $post_id ][ $key ] ?? array();
        return $single ? ( $value[0] ?? '' ) : $value;
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $meta_key, $meta_value, $prev_value = '' ) {
        global $mock_post_meta;
        if ( ! isset( $mock_post_meta[ $post_id ] ) ) {
            $mock_post_meta[ $post_id ] = array();
        }
        $mock_post_meta[ $post_id ][ $meta_key ] = array( $meta_value );
        return true;
    }
}

if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( $post_id, $meta_key, $meta_value = '' ) {
        global $mock_post_meta;
        unset( $mock_post_meta[ $post_id ][ $meta_key ] );
        return true;
    }
}

// Mock user functions.
global $mock_current_user_id;
$mock_current_user_id = 0;

global $mock_users;
$mock_users = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        global $mock_current_user_id;
        return $mock_current_user_id;
    }
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
    function is_user_logged_in() {
        global $mock_current_user_id;
        return $mock_current_user_id > 0;
    }
}

if ( ! function_exists( 'get_userdata' ) ) {
    function get_userdata( $user_id ) {
        global $mock_users;
        return $mock_users[ $user_id ] ?? null;
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability, ...$args ) {
        global $mock_current_user_id, $mock_users;
        if ( ! isset( $mock_users[ $mock_current_user_id ] ) ) {
            return false;
        }
        $user = $mock_users[ $mock_current_user_id ];
        return isset( $user->allcaps[ $capability ] ) && $user->allcaps[ $capability ];
    }
}

if ( ! function_exists( 'user_can' ) ) {
    function user_can( $user_id, $capability, ...$args ) {
        global $mock_users;
        if ( ! isset( $mock_users[ $user_id ] ) ) {
            return false;
        }
        $user = $mock_users[ $user_id ];
        return isset( $user->allcaps[ $capability ] ) && $user->allcaps[ $capability ];
    }
}

if ( ! function_exists( 'wp_hash' ) ) {
    function wp_hash( $data, $scheme = 'auth' ) {
        return hash( 'sha256', $data );
    }
}

if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( $scheme = 'auth' ) {
        return 'test-salt-' . $scheme;
    }
}

if ( ! function_exists( 'get_avatar_url' ) ) {
    function get_avatar_url( $id_or_email, $args = null ) {
        return 'https://example.com/avatar/' . $id_or_email . '.png';
    }
}

// Mock post functions.
global $mock_posts;
$mock_posts = array();

if ( ! function_exists( 'get_post' ) ) {
    function get_post( $post = null, $output = OBJECT, $filter = 'raw' ) {
        global $mock_posts;
        return $mock_posts[ $post ] ?? null;
    }
}

if ( ! function_exists( 'get_post_status' ) ) {
    function get_post_status( $post = null ) {
        global $mock_posts;
        $p = $mock_posts[ $post ] ?? null;
        return $p ? $p->post_status : false;
    }
}

if ( ! function_exists( 'wp_insert_post' ) ) {
    function wp_insert_post( $postarr, $wp_error = false, $fire_after_hooks = true ) {
        global $mock_posts;
        static $post_id = 100;
        $post_id++;

        $post = (object) array(
            'ID'           => $post_id,
            'post_title'   => $postarr['post_title'] ?? '',
            'post_content' => $postarr['post_content'] ?? '',
            'post_status'  => $postarr['post_status'] ?? 'draft',
            'post_type'    => $postarr['post_type'] ?? 'post',
            'post_author'  => $postarr['post_author'] ?? 1,
            'post_excerpt' => $postarr['post_excerpt'] ?? '',
            'post_date'    => date( 'Y-m-d H:i:s' ),
        );

        $mock_posts[ $post_id ] = $post;
        return $post_id;
    }
}

if ( ! function_exists( 'wp_update_post' ) ) {
    function wp_update_post( $postarr, $wp_error = false, $fire_after_hooks = true ) {
        global $mock_posts;
        $id = $postarr['ID'] ?? 0;
        if ( isset( $mock_posts[ $id ] ) ) {
            foreach ( $postarr as $key => $value ) {
                if ( $key !== 'ID' ) {
                    $mock_posts[ $id ]->$key = $value;
                }
            }
        }
        return $id;
    }
}

if ( ! function_exists( 'has_post_thumbnail' ) ) {
    function has_post_thumbnail( $post = null ) {
        return false;
    }
}

if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
    function get_the_post_thumbnail_url( $post = null, $size = 'post-thumbnail' ) {
        return '';
    }
}

if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $post = 0, $leavename = false ) {
        return 'https://example.com/?p=' . $post;
    }
}

if ( ! function_exists( 'wp_trim_words' ) ) {
    function wp_trim_words( $text, $num_words = 55, $more = null ) {
        $words = explode( ' ', $text );
        return implode( ' ', array_slice( $words, 0, $num_words ) );
    }
}

// Mock taxonomy functions.
if ( ! function_exists( 'wp_get_post_terms' ) ) {
    function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
        return array();
    }
}

if ( ! function_exists( 'wp_set_post_terms' ) ) {
    function wp_set_post_terms( $post_id, $tags = '', $taxonomy = 'post_tag', $append = false ) {
        return array();
    }
}

if ( ! function_exists( 'get_terms' ) ) {
    function get_terms( $args = array() ) {
        return array();
    }
}

// Mock role functions.
global $mock_roles;
$mock_roles = array();

if ( ! function_exists( 'get_role' ) ) {
    function get_role( $role ) {
        global $mock_roles;
        return $mock_roles[ $role ] ?? null;
    }
}

if ( ! function_exists( 'add_role' ) ) {
    function add_role( $role, $display_name, $capabilities = array() ) {
        global $mock_roles;
        $mock_roles[ $role ] = new class( $capabilities ) {
            public $capabilities = array();

            public function __construct( $capabilities ) {
                $this->capabilities = $capabilities;
            }

            public function add_cap( $cap, $grant = true ) {
                $this->capabilities[ $cap ] = $grant;
            }

            public function remove_cap( $cap ) {
                unset( $this->capabilities[ $cap ] );
            }

            public function has_cap( $cap ) {
                return isset( $this->capabilities[ $cap ] ) && $this->capabilities[ $cap ];
            }
        };
        return $mock_roles[ $role ];
    }
}

if ( ! function_exists( 'remove_role' ) ) {
    function remove_role( $role ) {
        global $mock_roles;
        unset( $mock_roles[ $role ] );
    }
}

// Mock action/filter functions.
global $mock_actions;
$mock_actions = array();

global $mock_filters;
$mock_filters = array();

if ( ! function_exists( 'add_action' ) ) {
    function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
        global $mock_actions;
        $mock_actions[ $hook_name ][] = array(
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        );
        return true;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook_name, ...$args ) {
        global $mock_actions;
        // In tests, we just track that action was called.
        return null;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
        global $mock_filters;
        $mock_filters[ $hook_name ][] = array(
            'callback'      => $callback,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        );
        return true;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook_name, $value, ...$args ) {
        return $value;
    }
}

if ( ! function_exists( 'remove_action' ) ) {
    function remove_action( $hook_name, $callback, $priority = 10 ) {
        return true;
    }
}

// Mock nonce functions.
if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( $action = -1 ) {
        return md5( $action . 'test-nonce-salt' );
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( $nonce, $action = -1 ) {
        return $nonce === wp_create_nonce( $action ) ? 1 : false;
    }
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ) {
        return true;
    }
}

// Mock cron functions.
if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( $hook, $args = array() ) {
        return false;
    }
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
    function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array(), $wp_error = false ) {
        return true;
    }
}

// Mock date/time functions.
if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type, $gmt = 0 ) {
        if ( 'mysql' === $type ) {
            return gmdate( 'Y-m-d H:i:s' );
        }
        if ( 'timestamp' === $type ) {
            return time();
        }
        return time();
    }
}

if ( ! function_exists( 'date_i18n' ) ) {
    function date_i18n( $format, $timestamp = false, $gmt = false ) {
        if ( false === $timestamp ) {
            $timestamp = time();
        }
        return date( $format, $timestamp );
    }
}

if ( ! function_exists( 'human_time_diff' ) ) {
    function human_time_diff( $from, $to = 0 ) {
        if ( empty( $to ) ) {
            $to = time();
        }
        $diff = abs( $to - $from );

        if ( $diff < HOUR_IN_SECONDS ) {
            return round( $diff / 60 ) . ' mins';
        } elseif ( $diff < DAY_IN_SECONDS ) {
            return round( $diff / HOUR_IN_SECONDS ) . ' hours';
        }
        return round( $diff / DAY_IN_SECONDS ) . ' days';
    }
}

// Mock i18n functions.
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( '_e' ) ) {
    function _e( $text, $domain = 'default' ) {
        echo $text;
    }
}

if ( ! function_exists( '_n' ) ) {
    function _n( $single, $plural, $number, $domain = 'default' ) {
        return $number == 1 ? $single : $plural;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) {
        return esc_html( $text );
    }
}

if ( ! function_exists( 'esc_attr__' ) ) {
    function esc_attr__( $text, $domain = 'default' ) {
        return esc_attr( $text );
    }
}

// Mock password function.
if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $password .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
        }
        return $password;
    }
}

// Mock JSON response functions.
if ( ! function_exists( 'wp_send_json' ) ) {
    function wp_send_json( $response, $status_code = null, $flags = 0 ) {
        // In tests, we don't actually send anything.
        return;
    }
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null, $status_code = null, $flags = 0 ) {
        return;
    }
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null, $status_code = null, $flags = 0 ) {
        return;
    }
}

// Mock rest_ensure_response.
if ( ! function_exists( 'rest_ensure_response' ) ) {
    function rest_ensure_response( $response ) {
        if ( $response instanceof WP_REST_Response ) {
            return $response;
        }
        return new WP_REST_Response( $response );
    }
}

// Mock register_rest_route.
if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( $namespace, $route, $args = array(), $override = false ) {
        return true;
    }
}

// Mock post revision functions.
if ( ! function_exists( 'wp_is_post_revision' ) ) {
    function wp_is_post_revision( $post ) {
        return false;
    }
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
    function wp_is_post_autosave( $post ) {
        return false;
    }
}

// Mock email function.
if ( ! function_exists( 'wp_mail' ) ) {
    function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
        return true;
    }
}

// Mock admin URL function.
if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '', $scheme = 'admin' ) {
        return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
    }
}

// Mock add_query_arg function.
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( ...$args ) {
        if ( is_array( $args[0] ) ) {
            $query_args = $args[0];
            $url = $args[1] ?? '';
        } else {
            $query_args = array( $args[0] => $args[1] );
            $url = $args[2] ?? '';
        }

        $parsed = parse_url( $url );
        $base = ( $parsed['scheme'] ?? 'https' ) . '://' . ( $parsed['host'] ?? 'example.com' ) . ( $parsed['path'] ?? '' );

        parse_str( $parsed['query'] ?? '', $existing );
        $merged = array_merge( $existing, $query_args );

        return $base . '?' . http_build_query( $merged );
    }
}

// Mock WooCommerce functions.
if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $order_id ) {
        return null;
    }
}

if ( ! function_exists( 'wc_get_checkout_url' ) ) {
    function wc_get_checkout_url() {
        return 'https://example.com/checkout/';
    }
}

if ( ! function_exists( 'wc_create_refund' ) ) {
    function wc_create_refund( $args = array() ) {
        return true;
    }
}

// Mock version_compare function (already exists in PHP but including for completeness).
if ( ! function_exists( 'version_compare' ) ) {
    function version_compare( $version1, $version2, $operator = null ) {
        return \version_compare( $version1, $version2, $operator );
    }
}

// Mock the_ID function.
if ( ! function_exists( 'get_the_ID' ) ) {
    function get_the_ID() {
        global $wp_query;
        if ( isset( $wp_query ) && isset( $wp_query->post ) ) {
            return $wp_query->post->ID;
        }
        return 0;
    }
}

// Mock wp_reset_postdata function.
if ( ! function_exists( 'wp_reset_postdata' ) ) {
    function wp_reset_postdata() {
        return null;
    }
}

// Mock file_exists check for templates.
if ( ! function_exists( 'file_exists' ) ) {
    // Use built-in file_exists.
}

// Mock error_log to capture log messages.
global $mock_error_log;
$mock_error_log = array();

// Helper function to reset all mocks.
function peanut_booker_reset_mocks() {
    global $wpdb, $mock_options, $mock_transients, $mock_post_meta, $mock_current_user_id;
    global $mock_users, $mock_posts, $mock_roles, $mock_actions, $mock_filters, $mock_error_log;

    $wpdb->reset_mocks();
    $mock_options = array();
    $mock_transients = array();
    $mock_post_meta = array();
    $mock_current_user_id = 0;
    $mock_users = array();
    $mock_posts = array();
    $mock_roles = array();
    $mock_actions = array();
    $mock_filters = array();
    $mock_error_log = array();
}

// Load base test case.
require_once __DIR__ . '/TestCase.php';

// Autoload plugin classes.
spl_autoload_register( function( $class ) {
    $prefix = 'Peanut_Booker_';

    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    $class_name = substr( $class, strlen( $prefix ) );
    $file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
    $file_path = PEANUT_BOOKER_PATH . 'includes/' . $file_name;

    if ( file_exists( $file_path ) ) {
        require_once $file_path;
    }
});
