<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Market and bidding functionality.
 *
 * @package Peanut_Booker
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Market and bidding class.
 */
class Peanut_Booker_Market {

    /**
     * Event statuses.
     */
    const STATUS_OPEN      = 'open';
    const STATUS_CLOSED    = 'closed';
    const STATUS_BOOKED    = 'booked';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_EXPIRED   = 'expired';

    /**
     * Bid statuses.
     */
    const BID_PENDING  = 'pending';
    const BID_ACCEPTED = 'accepted';
    const BID_REJECTED = 'rejected';
    const BID_WITHDRAWN = 'withdrawn';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'wp_ajax_pb_create_market_event', array( $this, 'ajax_create_event' ) );
        add_action( 'wp_ajax_pb_submit_bid', array( $this, 'ajax_submit_bid' ) );
        add_action( 'wp_ajax_pb_accept_bid', array( $this, 'ajax_accept_bid' ) );
        add_action( 'wp_ajax_pb_reject_bid', array( $this, 'ajax_reject_bid' ) );
        add_action( 'wp_ajax_pb_withdraw_bid', array( $this, 'ajax_withdraw_bid' ) );
        add_action( 'wp_ajax_pb_close_event', array( $this, 'ajax_close_event' ) );

        // Scheduled task for deadline checking.
        add_action( 'peanut_booker_check_bid_deadlines', array( $this, 'check_deadlines' ) );

        if ( ! wp_next_scheduled( 'peanut_booker_check_bid_deadlines' ) ) {
            wp_schedule_event( time(), 'hourly', 'peanut_booker_check_bid_deadlines' );
        }

        // Template filter.
        add_filter( 'template_include', array( $this, 'load_market_template' ) );
    }

    /**
     * Create a market event.
     *
     * @param array $data Event data.
     * @return int|WP_Error Post ID or error.
     */
    public static function create_event( $data ) {
        $required = array( 'customer_id', 'title', 'event_date' );

        foreach ( $required as $field ) {
            if ( empty( $data[ $field ] ) ) {
                return new WP_Error( 'missing_field', sprintf( __( 'Missing required field: %s', 'peanut-booker' ), $field ) );
            }
        }

        // Create post.
        $post_id = wp_insert_post(
            array(
                'post_type'    => 'pb_market_event',
                'post_status'  => 'publish',
                'post_title'   => sanitize_text_field( $data['title'] ),
                'post_content' => sanitize_textarea_field( $data['description'] ?? '' ),
                'post_author'  => $data['customer_id'],
            )
        );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Set meta fields.
        $meta_fields = array(
            'pb_customer_id'    => $data['customer_id'],
            'pb_event_date'     => sanitize_text_field( $data['event_date'] ),
            'pb_event_time'     => sanitize_text_field( $data['event_time'] ?? '' ),
            'pb_event_duration' => absint( $data['event_duration'] ?? 2 ),
            'pb_venue_name'     => sanitize_text_field( $data['venue_name'] ?? '' ),
            'pb_venue_address'  => sanitize_text_field( $data['venue_address'] ?? '' ),
            'pb_venue_city'     => sanitize_text_field( $data['venue_city'] ?? '' ),
            'pb_venue_state'    => sanitize_text_field( $data['venue_state'] ?? '' ),
            'pb_venue_zip'      => sanitize_text_field( $data['venue_zip'] ?? '' ),
            'pb_budget_min'     => floatval( $data['budget_min'] ?? 0 ),
            'pb_budget_max'     => floatval( $data['budget_max'] ?? 0 ),
            'pb_event_status'   => self::STATUS_OPEN,
            'pb_total_bids'     => 0,
            'pb_special_requirements' => sanitize_textarea_field( $data['special_requirements'] ?? '' ),
        );

        // Calculate bid deadline.
        $options = get_option( 'peanut_booker_settings', array() );
        $auto_deadline_days = isset( $options['market_auto_deadline_days'] ) ? intval( $options['market_auto_deadline_days'] ) : 7;

        if ( ! empty( $data['bid_deadline'] ) ) {
            $meta_fields['pb_bid_deadline'] = sanitize_text_field( $data['bid_deadline'] );
        } else {
            // Auto deadline: X days before event.
            $event_date = strtotime( $data['event_date'] );
            $deadline   = $event_date - ( $auto_deadline_days * DAY_IN_SECONDS );
            $meta_fields['pb_bid_deadline'] = gmdate( 'Y-m-d H:i:s', max( $deadline, time() + DAY_IN_SECONDS ) );
        }

        foreach ( $meta_fields as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }

        // Set category.
        if ( ! empty( $data['category'] ) ) {
            wp_set_post_terms( $post_id, array( absint( $data['category'] ) ), 'pb_performer_category' );
        }

        // Set service area.
        if ( ! empty( $data['service_area'] ) ) {
            wp_set_post_terms( $post_id, array( absint( $data['service_area'] ) ), 'pb_service_area' );
        }

        // Create record in custom table.
        Peanut_Booker_Database::insert(
            'events',
            array(
                'customer_id'   => $data['customer_id'],
                'post_id'       => $post_id,
                'title'         => $data['title'],
                'description'   => $data['description'] ?? '',
                'event_date'    => $data['event_date'],
                'event_start_time' => $data['event_time'] ?? null,
                'city'          => $data['venue_city'] ?? '',
                'state'         => $data['venue_state'] ?? '',
                'budget_min'    => $data['budget_min'] ?? null,
                'budget_max'    => $data['budget_max'] ?? null,
                'bid_deadline'  => $meta_fields['pb_bid_deadline'],
                'status'        => self::STATUS_OPEN,
            )
        );

        do_action( 'peanut_booker_market_event_created', $post_id, $data );

        return $post_id;
    }

    /**
     * Get event data.
     *
     * @param int $post_id Post ID.
     * @return array Event data.
     */
    public static function get_event_data( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || 'pb_market_event' !== $post->post_type ) {
            return array();
        }

        $customer_id = get_post_meta( $post_id, 'pb_customer_id', true );
        $customer    = Peanut_Booker_Customer::get( $customer_id );

        // Get bid count.
        $total_bids = absint( get_post_meta( $post_id, 'pb_total_bids', true ) );

        // Get category.
        $categories = wp_get_post_terms( $post_id, 'pb_performer_category', array( 'fields' => 'names' ) );

        // Get deadline status.
        $bid_deadline    = get_post_meta( $post_id, 'pb_bid_deadline', true );
        $deadline_passed = $bid_deadline && strtotime( $bid_deadline ) < time();

        $status = get_post_meta( $post_id, 'pb_event_status', true );

        return array(
            'id'                => $post_id,
            'title'             => $post->post_title,
            'description'       => $post->post_content,
            'excerpt'           => wp_trim_words( $post->post_content, 30 ),
            'permalink'         => get_permalink( $post_id ),
            'customer'          => $customer,
            'event_date'        => get_post_meta( $post_id, 'pb_event_date', true ),
            'event_date_formatted' => date_i18n( get_option( 'date_format' ), strtotime( get_post_meta( $post_id, 'pb_event_date', true ) ) ),
            'event_time'        => get_post_meta( $post_id, 'pb_event_time', true ),
            'event_duration'    => get_post_meta( $post_id, 'pb_event_duration', true ),
            'venue_name'        => get_post_meta( $post_id, 'pb_venue_name', true ),
            'venue_city'        => get_post_meta( $post_id, 'pb_venue_city', true ),
            'venue_state'       => get_post_meta( $post_id, 'pb_venue_state', true ),
            'budget_min'        => floatval( get_post_meta( $post_id, 'pb_budget_min', true ) ),
            'budget_max'        => floatval( get_post_meta( $post_id, 'pb_budget_max', true ) ),
            'budget_display'    => self::format_budget_range(
                get_post_meta( $post_id, 'pb_budget_min', true ),
                get_post_meta( $post_id, 'pb_budget_max', true )
            ),
            'bid_deadline'      => $bid_deadline,
            'deadline_formatted' => $bid_deadline ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $bid_deadline ) ) : '',
            'deadline_passed'   => $deadline_passed,
            'total_bids'        => $total_bids,
            'status'            => $status,
            'status_label'      => self::get_status_label( $status ),
            'is_open'           => self::STATUS_OPEN === $status && ! $deadline_passed,
            'categories'        => $categories,
            'special_requirements' => get_post_meta( $post_id, 'pb_special_requirements', true ),
            'created_at'        => $post->post_date,
        );
    }

    /**
     * Format budget range for display.
     *
     * @param float $min Minimum budget.
     * @param float $max Maximum budget.
     * @return string Formatted budget range.
     */
    public static function format_budget_range( $min, $max ) {
        $min = floatval( $min );
        $max = floatval( $max );

        if ( $min > 0 && $max > 0 ) {
            return sprintf( '$%s - $%s', number_format( $min ), number_format( $max ) );
        } elseif ( $max > 0 ) {
            return sprintf( __( 'Up to $%s', 'peanut-booker' ), number_format( $max ) );
        } elseif ( $min > 0 ) {
            return sprintf( __( 'Starting at $%s', 'peanut-booker' ), number_format( $min ) );
        }

        return __( 'Budget flexible', 'peanut-booker' );
    }

    /**
     * Get status label.
     *
     * @param string $status Status code.
     * @return string Status label.
     */
    public static function get_status_label( $status ) {
        $labels = array(
            self::STATUS_OPEN      => __( 'Open for Bids', 'peanut-booker' ),
            self::STATUS_CLOSED    => __( 'Bidding Closed', 'peanut-booker' ),
            self::STATUS_BOOKED    => __( 'Booked', 'peanut-booker' ),
            self::STATUS_CANCELLED => __( 'Cancelled', 'peanut-booker' ),
            self::STATUS_EXPIRED   => __( 'Expired', 'peanut-booker' ),
        );

        return $labels[ $status ] ?? $status;
    }

    /**
     * Submit a bid on an event (Pro performers only).
     *
     * @param array $data Bid data.
     * @return int|WP_Error Bid ID or error.
     */
    public static function submit_bid( $data ) {
        $event_id     = absint( $data['event_id'] ?? 0 );
        $performer_id = absint( $data['performer_id'] ?? 0 );
        $bid_amount   = floatval( $data['bid_amount'] ?? 0 );
        $message      = sanitize_textarea_field( $data['message'] ?? '' );

        // Validate event.
        $event = self::get_event_data( $event_id );
        if ( empty( $event ) ) {
            return new WP_Error( 'invalid_event', __( 'Event not found.', 'peanut-booker' ) );
        }

        if ( ! $event['is_open'] ) {
            return new WP_Error( 'event_closed', __( 'This event is no longer accepting bids.', 'peanut-booker' ) );
        }

        // Validate performer.
        $performer = Peanut_Booker_Performer::get( $performer_id );
        if ( ! $performer ) {
            return new WP_Error( 'invalid_performer', __( 'Performer not found.', 'peanut-booker' ) );
        }

        // Check Pro tier.
        if ( ! Peanut_Booker_Roles::can_bid_on_events( $performer->user_id ) ) {
            return new WP_Error( 'not_pro', __( 'Only Pro performers can bid on market events.', 'peanut-booker' ) );
        }

        // Check if already bid.
        $existing = Peanut_Booker_Database::get_row(
            'bids',
            array(
                'event_id'     => $event_id,
                'performer_id' => $performer_id,
            )
        );

        if ( $existing ) {
            return new WP_Error( 'already_bid', __( 'You have already submitted a bid for this event.', 'peanut-booker' ) );
        }

        // Check max bids.
        $options  = get_option( 'peanut_booker_settings', array() );
        $max_bids = isset( $options['max_bids_per_event'] ) ? intval( $options['max_bids_per_event'] ) : 50;

        if ( $event['total_bids'] >= $max_bids ) {
            return new WP_Error( 'max_bids', __( 'This event has reached the maximum number of bids.', 'peanut-booker' ) );
        }

        // Insert bid.
        $bid_id = Peanut_Booker_Database::insert(
            'bids',
            array(
                'event_id'     => $event_id,
                'performer_id' => $performer_id,
                'bid_amount'   => $bid_amount,
                'message'      => $message,
                'status'       => self::BID_PENDING,
            )
        );

        if ( ! $bid_id ) {
            return new WP_Error( 'insert_failed', __( 'Failed to submit bid.', 'peanut-booker' ) );
        }

        // Update bid count.
        update_post_meta( $event_id, 'pb_total_bids', $event['total_bids'] + 1 );

        Peanut_Booker_Database::update(
            'events',
            array( 'total_bids' => $event['total_bids'] + 1 ),
            array( 'post_id' => $event_id )
        );

        // Send notification to customer.
        Peanut_Booker_Notifications::send( 'new_bid', $bid_id );

        do_action( 'peanut_booker_bid_submitted', $bid_id, $event_id, $performer_id );

        return $bid_id;
    }

    /**
     * Get bids for an event.
     *
     * @param int    $event_id Event ID.
     * @param string $status   Optional status filter.
     * @return array Array of bids.
     */
    public static function get_event_bids( $event_id, $status = '' ) {
        $where = array( 'event_id' => $event_id );

        if ( ! empty( $status ) ) {
            $where['status'] = $status;
        }

        $bids = Peanut_Booker_Database::get_results( 'bids', $where, 'created_at', 'DESC' );

        return array_map( array( __CLASS__, 'format_bid_data' ), $bids );
    }

    /**
     * Get bids by performer.
     *
     * @param int    $performer_id Performer ID.
     * @param string $status       Optional status filter.
     * @return array Array of bids.
     */
    public static function get_performer_bids( $performer_id, $status = '' ) {
        $where = array( 'performer_id' => $performer_id );

        if ( ! empty( $status ) ) {
            $where['status'] = $status;
        }

        $bids = Peanut_Booker_Database::get_results( 'bids', $where, 'created_at', 'DESC' );

        return array_map( array( __CLASS__, 'format_bid_data' ), $bids );
    }

    /**
     * Format bid data.
     *
     * @param object $bid Bid object.
     * @return array Formatted bid data.
     */
    public static function format_bid_data( $bid ) {
        if ( ! $bid ) {
            return array();
        }

        $performer = Peanut_Booker_Performer::get( $bid->performer_id );
        $performer_data = array();

        if ( $performer && $performer->profile_id ) {
            $performer_data = Peanut_Booker_Performer::get_display_data( $performer->profile_id );
        }

        $event = self::get_event_data( $bid->event_id );

        return array(
            'id'           => $bid->id,
            'event_id'     => $bid->event_id,
            'event'        => $event,
            'performer_id' => $bid->performer_id,
            'performer'    => $performer_data,
            'bid_amount'   => floatval( $bid->bid_amount ),
            'message'      => $bid->message,
            'status'       => $bid->status,
            'status_label' => self::get_bid_status_label( $bid->status ),
            'is_read'      => (bool) $bid->is_read,
            'created_at'   => $bid->created_at,
        );
    }

    /**
     * Get bid status label.
     *
     * @param string $status Status code.
     * @return string Status label.
     */
    public static function get_bid_status_label( $status ) {
        $labels = array(
            self::BID_PENDING   => __( 'Pending', 'peanut-booker' ),
            self::BID_ACCEPTED  => __( 'Accepted', 'peanut-booker' ),
            self::BID_REJECTED  => __( 'Not Selected', 'peanut-booker' ),
            self::BID_WITHDRAWN => __( 'Withdrawn', 'peanut-booker' ),
        );

        return $labels[ $status ] ?? $status;
    }

    /**
     * Accept a bid.
     *
     * @param int $bid_id      Bid ID.
     * @param int $customer_id Customer user ID.
     * @return bool|WP_Error Success or error.
     */
    public static function accept_bid( $bid_id, $customer_id ) {
        $bid = Peanut_Booker_Database::get_row( 'bids', array( 'id' => $bid_id ) );
        if ( ! $bid ) {
            return new WP_Error( 'not_found', __( 'Bid not found.', 'peanut-booker' ) );
        }

        $event = self::get_event_data( $bid->event_id );
        if ( empty( $event ) ) {
            return new WP_Error( 'invalid_event', __( 'Event not found.', 'peanut-booker' ) );
        }

        // Verify ownership.
        if ( (int) $event['customer']['user_id'] !== $customer_id ) {
            return new WP_Error( 'not_authorized', __( 'Not authorized.', 'peanut-booker' ) );
        }

        if ( $bid->status !== self::BID_PENDING ) {
            return new WP_Error( 'invalid_status', __( 'This bid cannot be accepted.', 'peanut-booker' ) );
        }

        // Update bid status.
        Peanut_Booker_Database::update( 'bids', array( 'status' => self::BID_ACCEPTED ), array( 'id' => $bid_id ) );

        // Reject all other bids.
        global $wpdb;
        $table = $wpdb->prefix . 'pb_bids';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET status = %s WHERE event_id = %d AND id != %d AND status = %s",
                self::BID_REJECTED,
                $bid->event_id,
                $bid_id,
                self::BID_PENDING
            )
        );

        // Update event status.
        update_post_meta( $bid->event_id, 'pb_event_status', self::STATUS_BOOKED );
        update_post_meta( $bid->event_id, 'pb_accepted_bid_id', $bid_id );

        Peanut_Booker_Database::update(
            'events',
            array(
                'status'          => self::STATUS_BOOKED,
                'accepted_bid_id' => $bid_id,
            ),
            array( 'post_id' => $bid->event_id )
        );

        // Create booking from bid.
        $booking_data = array(
            'performer_id'    => $bid->performer_id,
            'customer_id'     => $customer_id,
            'event_id'        => $bid->event_id,
            'bid_id'          => $bid_id,
            'event_title'     => $event['title'],
            'event_description' => $event['description'],
            'event_date'      => $event['event_date'],
            'event_start_time' => $event['event_time'],
            'event_location'  => $event['venue_name'],
            'event_city'      => $event['venue_city'],
            'event_state'     => $event['venue_state'],
            'total_amount'    => $bid->bid_amount,
        );

        $booking_id = Peanut_Booker_Booking::create( $booking_data );

        // Notify performers.
        Peanut_Booker_Notifications::send( 'bid_accepted', $bid_id );

        // Notify rejected bidders.
        $rejected_bids = self::get_event_bids( $bid->event_id, self::BID_REJECTED );
        foreach ( $rejected_bids as $rejected_bid ) {
            Peanut_Booker_Notifications::send( 'bid_rejected', $rejected_bid['id'] );
        }

        do_action( 'peanut_booker_bid_accepted', $bid_id, $booking_id );

        return true;
    }

    /**
     * Reject a bid.
     *
     * @param int $bid_id      Bid ID.
     * @param int $customer_id Customer user ID.
     * @return bool|WP_Error Success or error.
     */
    public static function reject_bid( $bid_id, $customer_id ) {
        $bid = Peanut_Booker_Database::get_row( 'bids', array( 'id' => $bid_id ) );
        if ( ! $bid ) {
            return new WP_Error( 'not_found', __( 'Bid not found.', 'peanut-booker' ) );
        }

        $event = self::get_event_data( $bid->event_id );

        // Verify ownership.
        if ( (int) $event['customer']['user_id'] !== $customer_id ) {
            return new WP_Error( 'not_authorized', __( 'Not authorized.', 'peanut-booker' ) );
        }

        Peanut_Booker_Database::update( 'bids', array( 'status' => self::BID_REJECTED ), array( 'id' => $bid_id ) );

        Peanut_Booker_Notifications::send( 'bid_rejected', $bid_id );

        do_action( 'peanut_booker_bid_rejected', $bid_id );

        return true;
    }

    /**
     * Withdraw a bid (by performer).
     *
     * @param int $bid_id       Bid ID.
     * @param int $performer_id Performer ID.
     * @return bool|WP_Error Success or error.
     */
    public static function withdraw_bid( $bid_id, $performer_id ) {
        $bid = Peanut_Booker_Database::get_row( 'bids', array( 'id' => $bid_id ) );
        if ( ! $bid ) {
            return new WP_Error( 'not_found', __( 'Bid not found.', 'peanut-booker' ) );
        }

        if ( (int) $bid->performer_id !== $performer_id ) {
            return new WP_Error( 'not_authorized', __( 'Not authorized.', 'peanut-booker' ) );
        }

        if ( $bid->status !== self::BID_PENDING ) {
            return new WP_Error( 'invalid_status', __( 'This bid cannot be withdrawn.', 'peanut-booker' ) );
        }

        Peanut_Booker_Database::update( 'bids', array( 'status' => self::BID_WITHDRAWN ), array( 'id' => $bid_id ) );

        // Update bid count.
        $event = self::get_event_data( $bid->event_id );
        $new_count = max( 0, $event['total_bids'] - 1 );
        update_post_meta( $bid->event_id, 'pb_total_bids', $new_count );

        do_action( 'peanut_booker_bid_withdrawn', $bid_id );

        return true;
    }

    /**
     * Close event for bidding.
     *
     * @param int $event_id    Event post ID.
     * @param int $customer_id Customer user ID.
     * @return bool|WP_Error Success or error.
     */
    public static function close_event( $event_id, $customer_id ) {
        $event = self::get_event_data( $event_id );
        if ( empty( $event ) ) {
            return new WP_Error( 'not_found', __( 'Event not found.', 'peanut-booker' ) );
        }

        if ( (int) $event['customer']['user_id'] !== $customer_id ) {
            return new WP_Error( 'not_authorized', __( 'Not authorized.', 'peanut-booker' ) );
        }

        update_post_meta( $event_id, 'pb_event_status', self::STATUS_CLOSED );

        Peanut_Booker_Database::update(
            'events',
            array( 'status' => self::STATUS_CLOSED ),
            array( 'post_id' => $event_id )
        );

        do_action( 'peanut_booker_event_closed', $event_id );

        return true;
    }

    /**
     * Check for expired bid deadlines.
     */
    public function check_deadlines() {
        global $wpdb;

        $table = $wpdb->prefix . 'pb_events';
        $now   = current_time( 'mysql' );

        // Find open events past deadline.
        $expired = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id FROM $table WHERE status = %s AND bid_deadline < %s",
                self::STATUS_OPEN,
                $now
            )
        );

        foreach ( $expired as $event ) {
            update_post_meta( $event->post_id, 'pb_event_status', self::STATUS_EXPIRED );

            Peanut_Booker_Database::update(
                'events',
                array( 'status' => self::STATUS_EXPIRED ),
                array( 'post_id' => $event->post_id )
            );

            do_action( 'peanut_booker_event_expired', $event->post_id );
        }
    }

    /**
     * Query market events.
     *
     * @param array $args Query arguments.
     * @return array Array of events.
     */
    public static function query( $args = array() ) {
        $defaults = array(
            'public_only'    => false,
            'status'         => self::STATUS_OPEN,
            'category'       => '',
            'service_area'   => '',
            'budget_min'     => 0,
            'budget_max'     => 0,
            'date_from'      => '',
            'date_to'        => '',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'posts_per_page' => 12,
            'paged'          => 1,
            'search'         => '',
        );

        $args = wp_parse_args( $args, $defaults );

        $query_args = array(
            'post_type'      => 'pb_market_event',
            'post_status'    => 'publish',
            'posts_per_page' => $args['posts_per_page'],
            'paged'          => $args['paged'],
            'orderby'        => $args['orderby'],
            'order'          => $args['order'],
        );

        // Only the public REST catalog opts into this; preserve internal callers.
        if ( $args['public_only'] ) {
            $query_args['has_password'] = false;
        }

        // Status filter.
        if ( ! empty( $args['status'] ) ) {
            $query_args['meta_query'][] = array(
                'key'   => 'pb_event_status',
                'value' => $args['status'],
            );
        }

        // Category filter.
        if ( ! empty( $args['category'] ) ) {
            $query_args['tax_query'][] = array(
                'taxonomy' => 'pb_performer_category',
                'field'    => 'slug',
                'terms'    => $args['category'],
            );
        }

        // Service area filter.
        if ( ! empty( $args['service_area'] ) ) {
            $query_args['tax_query'][] = array(
                'taxonomy' => 'pb_service_area',
                'field'    => 'slug',
                'terms'    => $args['service_area'],
            );
        }

        // Date range filter.
        if ( ! empty( $args['date_from'] ) ) {
            $query_args['meta_query'][] = array(
                'key'     => 'pb_event_date',
                'value'   => $args['date_from'],
                'compare' => '>=',
                'type'    => 'DATE',
            );
        }

        if ( ! empty( $args['date_to'] ) ) {
            $query_args['meta_query'][] = array(
                'key'     => 'pb_event_date',
                'value'   => $args['date_to'],
                'compare' => '<=',
                'type'    => 'DATE',
            );
        }

        // Search.
        if ( ! empty( $args['search'] ) ) {
            $query_args['s'] = sanitize_text_field( $args['search'] );
        }

        $query   = new WP_Query( $query_args );
        $results = array();

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $results[] = self::get_event_data( get_the_ID() );
            }
            wp_reset_postdata();
        }

        return array(
            'events'       => $results,
            'total'        => $query->found_posts,
            'max_pages'    => $query->max_num_pages,
            'current_page' => $args['paged'],
        );
    }

    /**
     * Load market template.
     *
     * @param string $template Template path.
     * @return string Modified template path.
     */
    public function load_market_template( $template ) {
        if ( is_singular( 'pb_market_event' ) ) {
            $custom_template = PEANUT_BOOKER_PATH . 'templates/single-market-event.php';
            if ( file_exists( $custom_template ) ) {
                return $custom_template;
            }
        }

        if ( is_post_type_archive( 'pb_market_event' ) ) {
            $custom_template = PEANUT_BOOKER_PATH . 'templates/archive-market.php';
            if ( file_exists( $custom_template ) ) {
                return $custom_template;
            }
        }

        return $template;
    }

    /**
     * AJAX: Create event.
     */
    public function ajax_create_event() {
        check_ajax_referer( 'pb_market_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'peanut-booker' ) ) );
        }

        $data = array(
            'customer_id'  => get_current_user_id(),
            'title'        => sanitize_text_field( $_POST['title'] ?? '' ),
            'description'  => sanitize_textarea_field( $_POST['description'] ?? '' ),
            'event_date'   => sanitize_text_field( $_POST['event_date'] ?? '' ),
            'event_time'   => sanitize_text_field( $_POST['event_time'] ?? '' ),
            'event_duration' => absint( $_POST['event_duration'] ?? 2 ),
            'venue_name'   => sanitize_text_field( $_POST['venue_name'] ?? '' ),
            'venue_address' => sanitize_text_field( $_POST['venue_address'] ?? '' ),
            'venue_city'   => sanitize_text_field( $_POST['venue_city'] ?? '' ),
            'venue_state'  => sanitize_text_field( $_POST['venue_state'] ?? '' ),
            'venue_zip'    => sanitize_text_field( $_POST['venue_zip'] ?? '' ),
            'budget_min'   => floatval( $_POST['budget_min'] ?? 0 ),
            'budget_max'   => floatval( $_POST['budget_max'] ?? 0 ),
            'category'     => absint( $_POST['category'] ?? 0 ),
            'service_area' => absint( $_POST['service_area'] ?? 0 ),
            'bid_deadline' => sanitize_text_field( $_POST['bid_deadline'] ?? '' ),
            'special_requirements' => sanitize_textarea_field( $_POST['special_requirements'] ?? '' ),
        );

        $result = self::create_event( $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success(
            array(
                'message'   => __( 'Event posted to market successfully.', 'peanut-booker' ),
                'event_id'  => $result,
                'permalink' => get_permalink( $result ),
            )
        );
    }

    /**
     * AJAX: Submit bid.
     */
    public function ajax_submit_bid() {
        check_ajax_referer( 'pb_market_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'peanut-booker' ) ) );
        }

        $performer = Peanut_Booker_Performer::get_by_user_id( get_current_user_id() );
        if ( ! $performer ) {
            wp_send_json_error( array( 'message' => __( 'Performer profile not found.', 'peanut-booker' ) ) );
        }

        $data = array(
            'event_id'     => absint( $_POST['event_id'] ?? 0 ),
            'performer_id' => $performer->id,
            'bid_amount'   => floatval( $_POST['bid_amount'] ?? 0 ),
            'message'      => sanitize_textarea_field( $_POST['message'] ?? '' ),
        );

        $result = self::submit_bid( $data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Bid submitted successfully.', 'peanut-booker' ) ) );
    }

    /**
     * AJAX: Accept bid.
     */
    public function ajax_accept_bid() {
        check_ajax_referer( 'pb_market_nonce', 'nonce' );

        $result = self::accept_bid( absint( $_POST['bid_id'] ?? 0 ), get_current_user_id() );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Bid accepted. A booking has been created.', 'peanut-booker' ) ) );
    }

    /**
     * AJAX: Reject bid.
     */
    public function ajax_reject_bid() {
        check_ajax_referer( 'pb_market_nonce', 'nonce' );

        $result = self::reject_bid( absint( $_POST['bid_id'] ?? 0 ), get_current_user_id() );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Bid rejected.', 'peanut-booker' ) ) );
    }

    /**
     * AJAX: Withdraw bid.
     */
    public function ajax_withdraw_bid() {
        check_ajax_referer( 'pb_market_nonce', 'nonce' );

        $performer = Peanut_Booker_Performer::get_by_user_id( get_current_user_id() );
        if ( ! $performer ) {
            wp_send_json_error( array( 'message' => __( 'Performer not found.', 'peanut-booker' ) ) );
        }

        $result = self::withdraw_bid( absint( $_POST['bid_id'] ?? 0 ), $performer->id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Bid withdrawn.', 'peanut-booker' ) ) );
    }

    /**
     * AJAX: Close event.
     */
    public function ajax_close_event() {
        check_ajax_referer( 'pb_market_nonce', 'nonce' );

        $result = self::close_event( absint( $_POST['event_id'] ?? 0 ), get_current_user_id() );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Event closed for bidding.', 'peanut-booker' ) ) );
    }
}
