<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fired during plugin activation.
 *
 * @package Peanut_Booker
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Fired during plugin activation.
 */
class Peanut_Booker_Activator {

    /**
     * Activate the plugin.
     */
    public static function activate() {
        self::create_tables();
        self::create_roles();
        self::create_pages();
        self::set_default_options();

        // Register post types and taxonomies before flushing rewrite rules.
        require_once PEANUT_BOOKER_PATH . 'includes/class-post-types.php';
        $post_types = new Peanut_Booker_Post_Types();
        $post_types->register_post_types();
        $post_types->register_taxonomies();

        // Flush rewrite rules.
        flush_rewrite_rules();

        // Set activation flag.
        update_option( 'peanut_booker_activated', true );
        update_option( 'peanut_booker_db_version', PEANUT_BOOKER_DB_VERSION );

        // Schedule rewrite flush on next page load (in case activation happens before init).
        set_transient( 'peanut_booker_flush_rewrite', true, 60 );
    }

    /**
     * Create custom database tables.
     *
     * Public + idempotent (dbDelta): safe to re-run on upgrade. Called both by
     * activate() and by Peanut_Booker_Database::check_db_version() so new tables
     * and columns reach existing installs on auto-update.
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Performers extended data table.
        $table_performers = $wpdb->prefix . 'pb_performers';
        $sql_performers   = "CREATE TABLE $table_performers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            profile_id bigint(20) unsigned DEFAULT NULL,
            tier varchar(20) NOT NULL DEFAULT 'free',
            achievement_level varchar(20) NOT NULL DEFAULT 'bronze',
            achievement_score int(11) NOT NULL DEFAULT 0,
            completed_bookings int(11) NOT NULL DEFAULT 0,
            average_rating decimal(3,2) DEFAULT NULL,
            total_reviews int(11) NOT NULL DEFAULT 0,
            profile_completeness int(3) NOT NULL DEFAULT 0,
            hourly_rate decimal(10,2) DEFAULT NULL,
            deposit_percentage int(3) DEFAULT 25,
            minimum_deposit decimal(10,2) DEFAULT NULL,
            service_radius int(11) DEFAULT NULL,
            is_verified tinyint(1) NOT NULL DEFAULT 0,
            is_featured tinyint(1) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_id (user_id),
            KEY profile_id (profile_id),
            KEY tier (tier),
            KEY achievement_level (achievement_level),
            KEY status (status)
        ) $charset_collate;";
        dbDelta( $sql_performers );

        // Bookings table.
        $table_bookings = $wpdb->prefix . 'pb_bookings';
        $sql_bookings   = "CREATE TABLE $table_bookings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_number varchar(32) NOT NULL,
            performer_id bigint(20) unsigned NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            event_id bigint(20) unsigned DEFAULT NULL,
            bid_id bigint(20) unsigned DEFAULT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            event_title varchar(255) NOT NULL,
            event_description text,
            event_date date NOT NULL,
            event_start_time time DEFAULT NULL,
            event_end_time time DEFAULT NULL,
            event_location varchar(255) DEFAULT NULL,
            event_address text,
            event_city varchar(100) DEFAULT NULL,
            event_state varchar(100) DEFAULT NULL,
            event_zip varchar(255) DEFAULT NULL,
            total_amount decimal(10,2) NOT NULL,
            deposit_amount decimal(10,2) NOT NULL,
            remaining_amount decimal(10,2) NOT NULL,
            platform_commission decimal(10,2) DEFAULT NULL,
            performer_payout decimal(10,2) DEFAULT NULL,
            deposit_paid tinyint(1) NOT NULL DEFAULT 0,
            fully_paid tinyint(1) NOT NULL DEFAULT 0,
            escrow_status varchar(20) NOT NULL DEFAULT 'pending',
            booking_status varchar(20) NOT NULL DEFAULT 'pending',
            performer_confirmed tinyint(1) NOT NULL DEFAULT 0,
            customer_confirmed_completion tinyint(1) NOT NULL DEFAULT 0,
            completion_date datetime DEFAULT NULL,
            payout_date datetime DEFAULT NULL,
            cancellation_reason text,
            cancellation_date datetime DEFAULT NULL,
            cancelled_by bigint(20) unsigned DEFAULT NULL,
            notes text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY booking_number (booking_number),
            KEY performer_id (performer_id),
            KEY customer_id (customer_id),
            KEY event_id (event_id),
            KEY order_id (order_id),
            KEY event_date (event_date),
            KEY booking_status (booking_status),
            KEY escrow_status (escrow_status)
        ) $charset_collate;";
        dbDelta( $sql_bookings );

        // Reviews table.
        $table_reviews = $wpdb->prefix . 'pb_reviews';
        $sql_reviews   = "CREATE TABLE $table_reviews (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL,
            reviewer_id bigint(20) unsigned NOT NULL,
            reviewee_id bigint(20) unsigned NOT NULL,
            reviewer_type varchar(20) NOT NULL,
            rating tinyint(1) NOT NULL,
            title varchar(255) DEFAULT NULL,
            content text,
            response text,
            response_date datetime DEFAULT NULL,
            is_flagged tinyint(1) NOT NULL DEFAULT 0,
            flag_reason text,
            flagged_by bigint(20) unsigned DEFAULT NULL,
            flagged_date datetime DEFAULT NULL,
            arbitration_status varchar(20) DEFAULT NULL,
            arbitration_notes text,
            arbitrated_by bigint(20) unsigned DEFAULT NULL,
            arbitration_date datetime DEFAULT NULL,
            is_visible tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY booking_id (booking_id),
            KEY reviewer_id (reviewer_id),
            KEY reviewee_id (reviewee_id),
            KEY reviewer_type (reviewer_type),
            KEY reviewee_type (reviewee_id, reviewer_type),
            KEY is_flagged (is_flagged),
            KEY is_visible (is_visible)
        ) $charset_collate;";
        dbDelta( $sql_reviews );

        // Market events table.
        $table_events = $wpdb->prefix . 'pb_events';
        $sql_events   = "CREATE TABLE $table_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_id bigint(20) unsigned NOT NULL,
            post_id bigint(20) unsigned DEFAULT NULL,
            title varchar(255) NOT NULL,
            description text,
            event_date date NOT NULL,
            event_start_time time DEFAULT NULL,
            event_end_time time DEFAULT NULL,
            location varchar(255) DEFAULT NULL,
            address text,
            city varchar(100) DEFAULT NULL,
            state varchar(100) DEFAULT NULL,
            zip varchar(20) DEFAULT NULL,
            category_id bigint(20) unsigned DEFAULT NULL,
            budget_min decimal(10,2) DEFAULT NULL,
            budget_max decimal(10,2) DEFAULT NULL,
            bid_deadline datetime DEFAULT NULL,
            auto_deadline_days int(3) DEFAULT 7,
            total_bids int(11) NOT NULL DEFAULT 0,
            accepted_bid_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'open',
            is_featured tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY customer_id (customer_id),
            KEY post_id (post_id),
            KEY event_date (event_date),
            KEY category_id (category_id),
            KEY status (status),
            KEY bid_deadline (bid_deadline)
        ) $charset_collate;";
        dbDelta( $sql_events );

        // Bids table.
        $table_bids = $wpdb->prefix . 'pb_bids';
        $sql_bids   = "CREATE TABLE $table_bids (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id bigint(20) unsigned NOT NULL,
            performer_id bigint(20) unsigned NOT NULL,
            bid_amount decimal(10,2) NOT NULL,
            message text,
            status varchar(20) NOT NULL DEFAULT 'pending',
            is_read tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY event_performer (event_id, performer_id),
            KEY event_id (event_id),
            KEY performer_id (performer_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta( $sql_bids );

        // Availability table.
        $table_availability = $wpdb->prefix . 'pb_availability';
        $sql_availability   = "CREATE TABLE $table_availability (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            performer_id bigint(20) unsigned NOT NULL,
            date date NOT NULL,
            slot_type varchar(20) NOT NULL DEFAULT 'full_day',
            start_time time DEFAULT NULL,
            end_time time DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'available',
            booking_id bigint(20) unsigned DEFAULT NULL,
            block_type varchar(20) DEFAULT 'manual',
            event_name varchar(255) DEFAULT NULL,
            venue_name varchar(255) DEFAULT NULL,
            event_type varchar(100) DEFAULT NULL,
            event_location varchar(255) DEFAULT NULL,
            event_time time DEFAULT NULL,
            is_public tinyint(1) NOT NULL DEFAULT 1,
            ticket_url varchar(255) DEFAULT NULL,
            notes varchar(255) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY performer_date_slot (performer_id, date, slot_type, start_time),
            KEY performer_id (performer_id),
            KEY date (date),
            KEY status (status),
            KEY booking_id (booking_id),
            KEY block_type (block_type)
        ) $charset_collate;";
        dbDelta( $sql_availability );

        // Transactions table.
        $table_transactions = $wpdb->prefix . 'pb_transactions';
        $sql_transactions   = "CREATE TABLE $table_transactions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            transaction_type varchar(30) NOT NULL,
            amount decimal(10,2) NOT NULL,
            payment_method varchar(50) DEFAULT NULL,
            payment_id varchar(255) DEFAULT NULL,
            payer_id bigint(20) unsigned DEFAULT NULL,
            payee_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            notes text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY booking_id (booking_id),
            KEY order_id (order_id),
            KEY transaction_type (transaction_type),
            KEY status (status),
            KEY payer_id (payer_id),
            KEY payee_id (payee_id)
        ) $charset_collate;";
        dbDelta( $sql_transactions );

        // Subscriptions table (for Pro memberships).
        $table_subscriptions = $wpdb->prefix . 'pb_subscriptions';
        $sql_subscriptions   = "CREATE TABLE $table_subscriptions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            wc_subscription_id bigint(20) unsigned DEFAULT NULL,
            plan_type varchar(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            start_date datetime NOT NULL,
            end_date datetime DEFAULT NULL,
            next_billing_date datetime DEFAULT NULL,
            amount decimal(10,2) NOT NULL,
            payment_method varchar(50) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY wc_subscription_id (wc_subscription_id),
            KEY status (status),
            KEY end_date (end_date)
        ) $charset_collate;";
        dbDelta( $sql_subscriptions );

        // Messages table.
        $table_messages = $wpdb->prefix . 'pb_messages';
        $sql_messages   = "CREATE TABLE $table_messages (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sender_id bigint(20) unsigned NOT NULL,
            recipient_id bigint(20) unsigned NOT NULL,
            message text NOT NULL,
            booking_id bigint(20) unsigned DEFAULT NULL,
            is_read tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY sender_id (sender_id),
            KEY recipient_id (recipient_id),
            KEY booking_id (booking_id),
            KEY created_at (created_at),
            KEY conversation (sender_id, recipient_id, created_at)
        ) $charset_collate;";
        dbDelta( $sql_messages );

        // Sponsored slots table.
        $table_sponsored = $wpdb->prefix . 'pb_sponsored_slots';
        $sql_sponsored   = "CREATE TABLE $table_sponsored (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            performer_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            slot_type varchar(30) NOT NULL,
            position int(3) DEFAULT NULL,
            start_date datetime NOT NULL,
            end_date datetime NOT NULL,
            amount_paid decimal(10,2) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            impressions int(11) NOT NULL DEFAULT 0,
            clicks int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY performer_id (performer_id),
            KEY slot_type (slot_type),
            KEY status (status),
            KEY start_date (start_date),
            KEY end_date (end_date)
        ) $charset_collate;";
        dbDelta( $sql_sponsored );

        // Microsites table.
        $table_microsites = $wpdb->prefix . 'pb_microsites';
        $sql_microsites   = "CREATE TABLE $table_microsites (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            performer_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            subscription_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            slug varchar(100) NOT NULL,
            custom_domain varchar(255) DEFAULT NULL,
            domain_verified tinyint(1) NOT NULL DEFAULT 0,
            has_custom_domain_addon tinyint(1) NOT NULL DEFAULT 0,
            design_settings text,
            meta_title varchar(255) DEFAULT NULL,
            meta_description text,
            view_count int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY performer_id (performer_id),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta( $sql_microsites );

        // Microsite analytics table.
        // Written per-pageview by the public REST endpoint
        // (Peanut_Booker_REST_API::track_microsite_view); read by the admin
        // microsite-stats endpoint (trait-performers get_performer_microsite_stats).
        // One row aggregates a (microsite, date, hour, referrer) bucket.
        $table_microsite_analytics = $wpdb->prefix . 'pb_microsite_analytics';
        $sql_microsite_analytics   = "CREATE TABLE $table_microsite_analytics (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            microsite_id bigint(20) unsigned NOT NULL,
            date date NOT NULL,
            hour_of_day tinyint(3) unsigned DEFAULT NULL,
            referrer_domain varchar(255) DEFAULT NULL,
            page_views int(11) unsigned NOT NULL DEFAULT 0,
            unique_visitors int(11) unsigned NOT NULL DEFAULT 0,
            booking_clicks int(11) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY bucket (microsite_id, date, hour_of_day, referrer_domain),
            KEY microsite_id (microsite_id),
            KEY date (date)
        ) $charset_collate;";
        dbDelta( $sql_microsite_analytics );
    }

    /**
     * Create custom user roles.
     */
    private static function create_roles() {
        // Get subscriber capabilities as base.
        $subscriber = get_role( 'subscriber' );
        $base_caps  = $subscriber ? $subscriber->capabilities : array( 'read' => true );

        // Performer role.
        $performer_caps = array_merge(
            $base_caps,
            array(
                'pb_performer'           => true,
                'pb_edit_own_profile'    => true,
                'pb_view_bookings'       => true,
                'pb_manage_availability' => true,
                'pb_respond_reviews'     => true,
                'pb_view_market'         => true,
            )
        );
        add_role( 'pb_performer', __( 'Performer', 'peanut-booker' ), $performer_caps );

        // Customer role.
        $customer_caps = array_merge(
            $base_caps,
            array(
                'pb_customer'        => true,
                'pb_book_performers' => true,
                'pb_create_events'   => true,
                'pb_leave_reviews'   => true,
                'pb_view_market'     => true,
            )
        );
        add_role( 'pb_customer', __( 'Customer', 'peanut-booker' ), $customer_caps );

        // Add performer management caps to admin.
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( 'pb_manage_performers' );
            $admin->add_cap( 'pb_manage_bookings' );
            $admin->add_cap( 'pb_manage_reviews' );
            $admin->add_cap( 'pb_manage_market' );
            $admin->add_cap( 'pb_manage_settings' );
            $admin->add_cap( 'pb_arbitrate_reviews' );
            $admin->add_cap( 'pb_manage_payouts' );
        }
    }

    /**
     * Create required pages.
     */
    private static function create_pages() {
        $pages = array(
            'performer-directory' => array(
                'title'   => __( 'Find Performers', 'peanut-booker' ),
                'content' => '[pb_performer_directory]',
            ),
            'market'              => array(
                'title'   => __( 'Event Market', 'peanut-booker' ),
                'content' => '[pb_market]',
            ),
            'dashboard'           => array(
                'title'   => __( 'My Dashboard', 'peanut-booker' ),
                'content' => '[pb_my_dashboard]',
            ),
            'performer-signup'    => array(
                'title'   => __( 'Become a Performer', 'peanut-booker' ),
                'content' => '[pb_performer_signup]',
            ),
            'customer-signup'     => array(
                'title'   => __( 'Book a Performer', 'peanut-booker' ),
                'content' => '[pb_customer_signup]',
            ),
        );

        $page_ids = array();

        foreach ( $pages as $slug => $page ) {
            // Check if page already exists.
            $existing = get_page_by_path( $slug );
            if ( $existing ) {
                $page_ids[ $slug ] = $existing->ID;
                continue;
            }

            $page_id = wp_insert_post(
                array(
                    'post_title'     => $page['title'],
                    'post_content'   => $page['content'],
                    'post_status'    => 'publish',
                    'post_type'      => 'page',
                    'post_name'      => $slug,
                    'comment_status' => 'closed',
                )
            );

            if ( ! is_wp_error( $page_id ) ) {
                $page_ids[ $slug ] = $page_id;
            }
        }

        update_option( 'peanut_booker_pages', $page_ids );
    }

    /**
     * Set default plugin options.
     */
    private static function set_default_options() {
        $defaults = array(
            // General.
            'currency'                  => 'USD',
            'date_format'               => 'F j, Y',
            'time_format'               => 'g:i a',

            // Commission.
            'commission_free_tier'      => 15,
            'commission_pro_tier'       => 10,
            'commission_flat_fee'       => 0,

            // Subscriptions.
            'pro_monthly_price'         => 19.99,
            'pro_annual_price'          => 199.99,

            // Booking.
            'min_deposit_percentage'    => 10,
            'max_deposit_percentage'    => 100,
            'default_deposit_percentage' => 25,
            'escrow_auto_release_days'  => 7,
            'booking_buffer_hours'      => 24,

            // Market.
            'market_auto_deadline_days' => 7,
            'max_bids_per_event'        => 50,

            // Achievement thresholds.
            'achievement_bronze'        => 0,
            'achievement_silver'        => 100,
            'achievement_gold'          => 500,
            'achievement_platinum'      => 2000,

            // Sponsored slots.
            'sponsored_sidebar_price'   => 49.99,
            'sponsored_featured_price'  => 99.99,
            'sponsored_duration_days'   => 30,

            // Email settings.
            'email_from_name'           => get_bloginfo( 'name' ),
            'email_from_address'        => get_bloginfo( 'admin_email' ),
        );

        $existing = get_option( 'peanut_booker_settings', array() );
        $merged   = array_merge( $defaults, $existing );
        update_option( 'peanut_booker_settings', $merged );
    }
}
