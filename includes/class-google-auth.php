<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google OAuth authentication handler.
 *
 * @package Peanut_Booker
 * @since   1.3.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Google OAuth authentication class.
 */
class Peanut_Booker_Google_Auth {

    /**
     * Google OAuth endpoints.
     */
    const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const USER_URL  = 'https://www.googleapis.com/oauth2/v2/userinfo';

    /**
     * OAuth callback endpoint.
     */
    const CALLBACK_ENDPOINT = 'pb-oauth-callback';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'init', array( $this, 'register_rewrite_rules' ) );
        add_action( 'template_redirect', array( $this, 'handle_oauth_callback' ) );
        add_action( 'wp_ajax_nopriv_pb_google_login', array( $this, 'ajax_google_login_redirect' ) );
        add_action( 'wp_ajax_pb_google_login', array( $this, 'ajax_google_login_redirect' ) );
    }

    /**
     * Check if Google Auth is enabled.
     *
     * @return bool
     */
    public static function is_enabled() {
        $client_id     = Peanut_Booker::get_option( 'google_client_id', '' );
        $client_secret = Peanut_Booker::get_option( 'google_client_secret', '' );

        return ! empty( $client_id ) && ! empty( $client_secret );
    }

    /**
     * Get OAuth redirect URI.
     *
     * @return string
     */
    public static function get_redirect_uri() {
        return home_url( '/' . self::CALLBACK_ENDPOINT . '/' );
    }

    /**
     * Register rewrite rules for OAuth callback.
     */
    public function register_rewrite_rules() {
        add_rewrite_rule(
            '^' . self::CALLBACK_ENDPOINT . '/?$',
            'index.php?pb_oauth_callback=1',
            'top'
        );

        add_rewrite_tag( '%pb_oauth_callback%', '([^&]+)' );
    }

    /**
     * Get Google OAuth authorization URL.
     *
     * @param string $action   The action (login, signup_performer, signup_customer, link).
     * @param string $redirect Optional redirect URL after auth.
     * @return string Authorization URL.
     */
    public static function get_auth_url( $action = 'login', $redirect = '' ) {
        if ( ! self::is_enabled() ) {
            return '';
        }

        $client_id = Peanut_Booker::get_option( 'google_client_id', '' );

        // Store action and redirect in state.
        $state = base64_encode( wp_json_encode( array(
            'action'   => $action,
            'redirect' => $redirect ?: home_url( '/' ),
            'nonce'    => wp_create_nonce( 'pb_google_oauth' ),
        ) ) );

        $params = array(
            'client_id'     => $client_id,
            'redirect_uri'  => self::get_redirect_uri(),
            'response_type' => 'code',
            'scope'         => 'email profile',
            'access_type'   => 'online',
            'state'         => $state,
        );

        return self::AUTH_URL . '?' . http_build_query( $params );
    }

    /**
     * AJAX handler to redirect to Google login.
     */
    public function ajax_google_login_redirect() {
        // Verify nonce for public endpoint
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( $_POST['nonce'] ), 'pb_google_login' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'peanut-booker' ) ) );
        }

        $action   = isset( $_POST['auth_action'] ) ? sanitize_text_field( $_POST['auth_action'] ) : 'login';
        $redirect = isset( $_POST['redirect'] ) ? esc_url_raw( $_POST['redirect'] ) : '';

        $auth_url = self::get_auth_url( $action, $redirect );

        if ( empty( $auth_url ) ) {
            wp_send_json_error( array( 'message' => __( 'Google login is not configured.', 'peanut-booker' ) ) );
        }

        wp_send_json_success( array( 'redirect' => $auth_url ) );
    }

    /**
     * Handle OAuth callback from Google.
     */
    public function handle_oauth_callback() {
        if ( ! get_query_var( 'pb_oauth_callback' ) ) {
            return;
        }

        // Check for error.
        if ( isset( $_GET['error'] ) ) {
            $this->redirect_with_error( __( 'Google authentication was cancelled or failed.', 'peanut-booker' ) );
            return;
        }

        // Check for code.
        $code = isset( $_GET['code'] ) ? sanitize_text_field( $_GET['code'] ) : '';
        if ( empty( $code ) ) {
            $this->redirect_with_error( __( 'Invalid authentication response.', 'peanut-booker' ) );
            return;
        }

        // Decode state.
        $state_raw = isset( $_GET['state'] ) ? sanitize_text_field( $_GET['state'] ) : '';
        $state     = json_decode( base64_decode( $state_raw ), true );

        if ( ! $state || ! wp_verify_nonce( $state['nonce'] ?? '', 'pb_google_oauth' ) ) {
            $this->redirect_with_error( __( 'Invalid authentication state.', 'peanut-booker' ) );
            return;
        }

        $action       = $state['action'] ?? 'login';
        $redirect_url = $state['redirect'] ?? home_url( '/' );

        // Exchange code for token.
        $token = $this->exchange_code_for_token( $code );
        if ( is_wp_error( $token ) ) {
            $this->redirect_with_error( $token->get_error_message() );
            return;
        }

        // Get user info from Google.
        $google_user = $this->get_google_user( $token['access_token'] );
        if ( is_wp_error( $google_user ) ) {
            $this->redirect_with_error( $google_user->get_error_message() );
            return;
        }

        // Process based on action.
        $result = $this->process_google_user( $google_user, $action );
        if ( is_wp_error( $result ) ) {
            $this->redirect_with_error( $result->get_error_message() );
            return;
        }

        // Redirect to success URL.
        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Exchange authorization code for access token.
     *
     * @param string $code Authorization code.
     * @return array|WP_Error Token data or error.
     */
    private function exchange_code_for_token( $code ) {
        $client_id     = Peanut_Booker::get_option( 'google_client_id', '' );
        $client_secret = Peanut_Booker::get_option( 'google_client_secret', '' );

        $response = wp_remote_post( self::TOKEN_URL, array(
            'body' => array(
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => self::get_redirect_uri(),
                'grant_type'    => 'authorization_code',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'oauth_error', $body['error_description'] ?? $body['error'] );
        }

        return $body;
    }

    /**
     * Get user info from Google.
     *
     * @param string $access_token Access token.
     * @return array|WP_Error User data or error.
     */
    private function get_google_user( $access_token ) {
        $response = wp_remote_get( self::USER_URL, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'oauth_error', $body['error']['message'] ?? __( 'Failed to get user info.', 'peanut-booker' ) );
        }

        return $body;
    }

    /**
     * Process Google user based on action.
     *
     * @param array  $google_user Google user data.
     * @param string $action      Action type.
     * @return true|WP_Error True on success or error.
     */
    private function process_google_user( $google_user, $action ) {
        $google_id = $google_user['id'] ?? '';
        $email     = $google_user['email'] ?? '';
        $name      = $google_user['name'] ?? '';

        if ( empty( $google_id ) || empty( $email ) ) {
            return new WP_Error( 'missing_data', __( 'Failed to get user information from Google.', 'peanut-booker' ) );
        }

        // SECURITY: Only trust the email when Google says it is verified.
        // An unverified address could belong to someone else, and the email
        // is what ties a Google account to an existing WordPress account.
        if ( ! self::is_verified_email( $google_user ) ) {
            return new WP_Error( 'email_not_verified', __( 'Your Google account email address is not verified. Verify it with Google, or sign in with your password.', 'peanut-booker' ) );
        }

        // Check if user exists by Google ID.
        $user_id = $this->get_user_by_google_id( $google_id );

        // Linking from the profile always targets the signed-in user; it never
        // goes through the email match below.
        if ( 'link' === $action ) {
            if ( ! is_user_logged_in() ) {
                return new WP_Error( 'not_logged_in', __( 'You must be logged in to link your Google account.', 'peanut-booker' ) );
            }
            $current_user_id = get_current_user_id();
            if ( $user_id && (int) $user_id !== (int) $current_user_id ) {
                return new WP_Error( 'already_linked', __( 'This Google account is already connected to a different account.', 'peanut-booker' ) );
            }
            update_user_meta( $current_user_id, 'pb_google_id', $google_id );
            return true;
        }

        // If not found by Google ID, check by email.
        if ( ! $user_id ) {
            $user = get_user_by( 'email', $email );
            if ( $user ) {
                // SECURITY: Never link automatically when the account has
                // elevated privileges or is already linked to another Google
                // account. Those users sign in with their password and link
                // Google from their profile (the 'link' action).
                $existing_google_id = (string) get_user_meta( $user->ID, 'pb_google_id', true );
                if ( self::is_privileged_user( $user ) || ( '' !== $existing_google_id && $existing_google_id !== (string) $google_id ) ) {
                    return new WP_Error( 'link_required', __( 'An account with this email already exists. Sign in with your password, then connect Google from your profile.', 'peanut-booker' ) );
                }

                $user_id = $user->ID;
                // Link Google ID to existing user.
                update_user_meta( $user_id, 'pb_google_id', $google_id );
            }
        }

        switch ( $action ) {
            case 'login':
                if ( ! $user_id ) {
                    return new WP_Error( 'user_not_found', __( 'No account found with this Google account. Please sign up first.', 'peanut-booker' ) );
                }
                $this->log_user_in( $user_id );
                break;

            case 'signup_performer':
                if ( $user_id ) {
                    // User exists, just log them in.
                    $this->log_user_in( $user_id );
                } else {
                    // Create new performer.
                    $user_id = $this->create_user( $google_user, 'pb_performer' );
                    if ( is_wp_error( $user_id ) ) {
                        return $user_id;
                    }
                    update_user_meta( $user_id, 'pb_google_id', $google_id );
                    $this->log_user_in( $user_id );

                    // Create performer profile.
                    Peanut_Booker_Performer::create_profile( $user_id );
                }
                break;

            case 'signup_customer':
                if ( $user_id ) {
                    // User exists, just log them in.
                    $this->log_user_in( $user_id );
                } else {
                    // Create new customer.
                    $user_id = $this->create_user( $google_user, 'pb_customer' );
                    if ( is_wp_error( $user_id ) ) {
                        return $user_id;
                    }
                    update_user_meta( $user_id, 'pb_google_id', $google_id );
                    $this->log_user_in( $user_id );
                }
                break;

            default:
                return new WP_Error( 'invalid_action', __( 'Invalid action.', 'peanut-booker' ) );
        }

        return true;
    }

    /**
     * Whether Google reports the account email as verified.
     *
     * The v2 userinfo endpoint returns `verified_email`; OpenID Connect
     * userinfo returns `email_verified`. Anything other than true is unverified.
     *
     * @param array $google_user Google user data.
     * @return bool
     */
    private static function is_verified_email( $google_user ) {
        foreach ( array( 'verified_email', 'email_verified' ) as $claim ) {
            if ( isset( $google_user[ $claim ] ) && ( true === $google_user[ $claim ] || 'true' === $google_user[ $claim ] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an account is too privileged to be linked by email match.
     *
     * Administrators, editors and anyone holding a Peanut Booker management
     * capability (pb_manage_*), or core capabilities those roles carry.
     *
     * @param WP_User $user User.
     * @return bool
     */
    private static function is_privileged_user( $user ) {
        if ( array_intersect( array( 'administrator', 'editor' ), (array) $user->roles ) ) {
            return true;
        }

        if ( is_multisite() && is_super_admin( $user->ID ) ) {
            return true;
        }

        foreach ( array( 'manage_options', 'edit_others_posts', 'promote_users', 'edit_users' ) as $cap ) {
            if ( user_can( $user, $cap ) ) {
                return true;
            }
        }

        foreach ( (array) $user->allcaps as $cap => $granted ) {
            if ( $granted && 0 === strpos( (string) $cap, 'pb_manage_' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user by Google ID.
     *
     * @param string $google_id Google user ID.
     * @return int|false User ID or false.
     */
    private function get_user_by_google_id( $google_id ) {
        global $wpdb;

        $user_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'pb_google_id' AND meta_value = %s LIMIT 1",
                $google_id
            )
        );

        return $user_id ? (int) $user_id : false;
    }

    /**
     * Create new user from Google data.
     *
     * @param array  $google_user Google user data.
     * @param string $role        User role.
     * @return int|WP_Error User ID or error.
     */
    private function create_user( $google_user, $role ) {
        $email    = $google_user['email'];
        $name     = $google_user['name'] ?? '';
        $username = sanitize_user( strtolower( str_replace( ' ', '', $name ) ) );

        // Ensure unique username.
        $base_username = $username;
        $counter       = 1;
        while ( username_exists( $username ) ) {
            $username = $base_username . $counter;
            $counter++;
        }

        // Create user.
        $user_id = wp_insert_user( array(
            'user_login'   => $username,
            'user_email'   => $email,
            'display_name' => $name,
            'first_name'   => $google_user['given_name'] ?? '',
            'last_name'    => $google_user['family_name'] ?? '',
            'user_pass'    => wp_generate_password( 24 ),
            'role'         => $role,
        ) );

        return $user_id;
    }

    /**
     * Log user in.
     *
     * @param int $user_id User ID.
     */
    private function log_user_in( $user_id ) {
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );

        /**
         * Fires after user logs in via Google.
         *
         * @param int $user_id User ID.
         */
        do_action( 'pb_google_login', $user_id );
    }

    /**
     * Redirect with error message.
     *
     * @param string $message Error message.
     */
    private function redirect_with_error( $message ) {
        $redirect = add_query_arg( 'pb_auth_error', urlencode( $message ), wp_login_url() );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Render Google login button.
     *
     * @param string $action   Action type (login, signup_performer, signup_customer).
     * @param string $redirect Redirect URL after auth.
     * @param string $text     Button text.
     * @return string Button HTML.
     */
    public static function render_button( $action = 'login', $redirect = '', $text = '' ) {
        if ( ! self::is_enabled() ) {
            return '';
        }

        if ( empty( $text ) ) {
            switch ( $action ) {
                case 'signup_performer':
                case 'signup_customer':
                    $text = __( 'Sign up with Google', 'peanut-booker' );
                    break;
                default:
                    $text = __( 'Sign in with Google', 'peanut-booker' );
            }
        }

        $auth_url = self::get_auth_url( $action, $redirect );

        $html = '<div class="pb-social-login">';
        $html .= '<a href="' . esc_url( $auth_url ) . '" class="pb-google-btn">';
        $html .= '<svg class="pb-google-icon" width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">';
        $html .= '<path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.717v2.258h2.908c1.702-1.567 2.684-3.874 2.684-6.615z" fill="#4285F4"/>';
        $html .= '<path d="M9.003 18c2.43 0 4.467-.806 5.956-2.18l-2.909-2.26c-.806.54-1.836.86-3.047.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332A8.997 8.997 0 0 0 9.003 18z" fill="#34A853"/>';
        $html .= '<path d="M3.964 10.71c-.18-.54-.282-1.117-.282-1.71s.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/>';
        $html .= '<path d="M9.003 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.464.891 11.428 0 9.002 0A8.997 8.997 0 0 0 .957 4.958L3.964 7.29c.708-2.127 2.692-3.71 5.036-3.71z" fill="#EA4335"/>';
        $html .= '</svg>';
        $html .= '<span>' . esc_html( $text ) . '</span>';
        $html .= '</a>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Check if user has Google account linked.
     *
     * @param int $user_id User ID.
     * @return bool
     */
    public static function is_google_linked( ?int $user_id = null ) {
        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        $google_id = get_user_meta( $user_id, 'pb_google_id', true );

        return ! empty( $google_id );
    }

    /**
     * Unlink Google account from user.
     *
     * @param int $user_id User ID.
     */
    public static function unlink( ?int $user_id = null ) {
        if ( null === $user_id ) {
            $user_id = get_current_user_id();
        }

        delete_user_meta( $user_id, 'pb_google_id' );
    }
}
