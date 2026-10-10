<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Booking engine functionality.
 *
 * @package Peanut_Booker
 * @since   1.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Booking engine class.
 */
class Peanut_Booker_Booking {

    /**
     * Booking statuses.
     */
    const STATUS_PENDING    = 'pending';
    const STATUS_CONFIRMED  = 'confirmed';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED  = 'completed';
    const STATUS_CANCELLED  = 'cancelled';
    const STATUS_DISPUTED   = 'disputed';

    /**
     * Escrow statuses.
     */
    const ESCROW_PENDING   = 'pending';
    const ESCROW_DEPOSIT   = 'deposit_held';
    const ESCROW_FULL      = 'full_held';
    const ESCROW_RELEASED  = 'released';
    const ESCROW_REFUNDED  = 'refunded';

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'wp_ajax_pb_create_booking', array( $this, 'ajax_create_booking' ) );
        add_action( 'wp_ajax_pb_confirm_booking', array( $this, 'ajax_confirm_booking' ) );
        add_action( 'wp_ajax_pb_complete_booking', array( $this, 'ajax_complete_booking' ) );
        add_action( 'wp_ajax_pb_cancel_booking', array( $this, 'ajax_cancel_booking' ) );

        // Scheduled tasks.
        add_action( 'peanut_booker_check_escrow_releases', array( $this, 'process_auto_releases' ) );
        add_action( 'peanut_booker_send_reminders', array( $this, 'send_booking_reminders' ) );

        // Schedule cron if not scheduled.
        if ( ! wp_next_scheduled( 'peanut_booker_check_escrow_releases' ) ) {
            wp_schedule_event( time(), 'daily', 'peanut_booker_check_escrow_releases' );
        }

        if ( ! wp_next_scheduled( 'peanut_booker_send_reminders' ) ) {
            wp_schedule_event( time(), 'daily', 'peanut_booker_send_reminders' );
        }
    }

    /**
     * Calculate expected booking total based on performer rate and event duration.
     *
     * @param int    $performer_id    Performer ID.
     * @param string $event_start_time Event start time (HH:MM:SS or HH:MM).
     * @param string $event_end_time   Event end time (HH:MM:SS or HH:MM).
     * @return float|WP_Error Calculated amount or error.
     */
    public static function calculate_booking_total( $performer_id, $event_start_time = '', $event_end_time = '' ) {
        $performer = Peanut_Booker_Performer::get( $performer_id );
        if ( ! $performer ) {
            return new WP_Error( 'invalid_performer', __( 'Invalid performer.', 'peanut-booker' ) );
        }

        $hourly_rate = floatval( $performer->hourly_rate );
        if ( $hourly_rate <= 0 ) {
            return new WP_Error( 'no_rate', __( 'Performer has no hourly rate configured.', 'peanut-booker' ) );
        }

        // Calculate duration in hours.
        $duration_hours = 1; // Default minimum of 1 hour.

        if ( ! empty( $event_start_time ) && ! empty( $event_end_time ) ) {
            $start = strtotime( $event_start_time );
            $end   = strtotime( $event_end_time );

            if ( $start !== false && $end !== false && $end > $start ) {
                $duration_hours = ( $end - $start ) / 3600;
                // Round up to nearest 0.5 hour.
                $duration_hours = ceil( $duration_hours * 2 ) / 2;
            }
        }

        // Minimum 1 hour.
        $duration_hours = max( 1, $duration_hours );

        return round( $hourly_rate * $duration_hours, 2 );
    }

    /**
     * Verify client-provided amount against calculated server-side amount.
     *
     * @param float  $client_amount    Amount provided by client.
     * @param float  $calculated_amount Server-calculated amount.
     * @param float  $tolerance_percent Allowed difference percentage (default 1%).
     * @return bool|WP_Error True if valid, WP_Error if not.
     */
    public static function verify_booking_amount( $client_amount, $calculated_amount, $tolerance_percent = 1.0 ) {
        if ( $calculated_amount <= 0 ) {
            return new WP_Error( 'invalid_calculated', __( 'Invalid calculated amount.', 'peanut-booker' ) );
        }

        $difference_percent = abs( ( $client_amount - $calculated_amount ) / $calculated_amount ) * 100;

        if ( $difference_percent > $tolerance_percent ) {
            // Log the discrepancy for audit.
            error_log( sprintf(
                '[Peanut Booker Security] Amount discrepancy detected: client=%.2f, calculated=%.2f, difference=%.2f%%',
                $client_amount,
                $calculated_amount,
                $difference_percent
            ) );

            return new WP_Error(
                'amount_mismatch',
                sprintf(
                    __( 'Booking amount does not match performer rate. Expected: $%.2f', 'peanut-booker' ),
                    $calculated_amount
                )
            );
        }

        return true;
    }

    /**
     * Resolve the price of a new booking from a server-side source.
     *
     * - With a bid_id: the bid must exist, belong to this performer and have
     *   been accepted; its bid_amount is the price (the performer set it).
     * - Otherwise the performer must have an hourly rate, and the requested
     *   total must match the server-side calculation (existing behaviour).
     * - A performer with no hourly rate and no accepted bid has no price, so
     *   the booking is refused rather than priced by the customer.
     *
     * @param object $performer Performer row.
     * @param array  $data      Booking data passed to create().
     * @return float|WP_Error Price or error.
     */
    private static function resolve_booking_price( $performer, $data ) {
        if ( ! empty( $data['bid_id'] ) ) {
            $bid = Peanut_Booker_Database::get_row( 'bids', array( 'id' => absint( $data['bid_id'] ) ) );

            if ( ! $bid || (int) $bid->performer_id !== (int) $performer->id || 'accepted' !== $bid->status ) {
                return new WP_Error( 'invalid_bid', __( 'This booking does not match an accepted bid.', 'peanut-booker' ) );
            }

            $bid_amount = round( floatval( $bid->bid_amount ), 2 );
            if ( $bid_amount <= 0 ) {
                return new WP_Error( 'invalid_bid', __( 'The accepted bid has no price.', 'peanut-booker' ) );
            }

            return $bid_amount;
        }

        if ( floatval( $performer->hourly_rate ) <= 0 ) {
            return new WP_Error(
                'price_required',
                __( 'This performer has not set a price. Post your event to the market to receive a quote.', 'peanut-booker' )
            );
        }

        if ( empty( $data['total_amount'] ) ) {
            return new WP_Error( 'missing_field', sprintf( __( 'Missing required field: %s', 'peanut-booker' ), 'total_amount' ) );
        }

        $calculated_amount = self::calculate_booking_total(
            $performer->id,
            $data['event_start_time'] ?? '',
            $data['event_end_time'] ?? ''
        );
        if ( is_wp_error( $calculated_amount ) ) {
            return $calculated_amount;
        }

        $client_amount = floatval( $data['total_amount'] );
        $verify_result = self::verify_booking_amount( $client_amount, $calculated_amount );
        if ( is_wp_error( $verify_result ) ) {
            return $verify_result;
        }

        return $client_amount;
    }

    /**
     * Create a new booking.
     *
     * @param array $data Booking data.
     * @return int|WP_Error Booking ID or error.
     */
    public static function create( $data ) {
        // total_amount is not required here: when the price comes from an
        // accepted bid it is read from the bid, never from the caller.
        $required = array( 'performer_id', 'customer_id', 'event_date' );

        foreach ( $required as $field ) {
            if ( empty( $data[ $field ] ) ) {
                return new WP_Error( 'missing_field', sprintf( __( 'Missing required field: %s', 'peanut-booker' ), $field ) );
            }
        }

        // Get performer and verify they exist and are active.
        $performer = Peanut_Booker_Performer::get( $data['performer_id'] );
        if ( ! $performer ) {
            return new WP_Error( 'invalid_performer', __( 'Invalid performer.', 'peanut-booker' ) );
        }

        // Verify performer is active and has a published profile.
        if ( $performer->status !== 'approved' ) {
            return new WP_Error( 'performer_inactive', __( 'Performer is not currently accepting bookings.', 'peanut-booker' ) );
        }

        if ( $performer->profile_id && get_post_status( $performer->profile_id ) !== 'publish' ) {
            return new WP_Error( 'performer_unavailable', __( 'Performer profile is unavailable.', 'peanut-booker' ) );
        }

        // The price must come from the server: an accepted bid the performer
        // made, or the performer's hourly rate. A caller-supplied total is
        // never the price on its own.
        $total_amount = self::resolve_booking_price( $performer, $data );
        if ( is_wp_error( $total_amount ) ) {
            return $total_amount;
        }

        // Calculate amounts.
        $deposit_percentage = $performer->deposit_percentage;
        $deposit_amount     = round( $total_amount * ( $deposit_percentage / 100 ), 2 );
        $remaining_amount   = $total_amount - $deposit_amount;

        // Calculate commission.
        $commission_rate    = Peanut_Booker_Roles::get_commission_rate( $performer->tier );
        $platform_commission = round( $total_amount * ( $commission_rate / 100 ), 2 );

        // Add flat fee if configured.
        $options  = get_option( 'peanut_booker_settings', array() );
        $flat_fee = isset( $options['commission_flat_fee'] ) ? floatval( $options['commission_flat_fee'] ) : 0;
        $platform_commission += $flat_fee;

        $performer_payout = $total_amount - $platform_commission;

        // Generate booking number.
        $booking_number = Peanut_Booker_Database::generate_booking_number();

        // Prepare booking data.
        $booking_data = array(
            'booking_number'      => $booking_number,
            'performer_id'        => $data['performer_id'],
            'customer_id'         => $data['customer_id'],
            'event_id'            => $data['event_id'] ?? null,
            'bid_id'              => $data['bid_id'] ?? null,
            'event_title'         => sanitize_text_field( $data['event_title'] ?? '' ),
            'event_description'   => sanitize_textarea_field( $data['event_description'] ?? '' ),
            'event_date'          => sanitize_text_field( $data['event_date'] ),
            'event_start_time'    => sanitize_text_field( $data['event_start_time'] ?? '' ),
            'event_end_time'      => sanitize_text_field( $data['event_end_time'] ?? '' ),
            'event_location'      => sanitize_text_field( $data['event_location'] ?? '' ),
            'event_address'       => sanitize_textarea_field( $data['event_address'] ?? '' ),
            'event_city'          => sanitize_text_field( $data['event_city'] ?? '' ),
            'event_state'         => sanitize_text_field( $data['event_state'] ?? '' ),
            'event_zip'           => sanitize_text_field( $data['event_zip'] ?? '' ),
            'total_amount'        => $total_amount,
            'deposit_amount'      => $deposit_amount,
            'remaining_amount'    => $remaining_amount,
            'platform_commission' => $platform_commission,
            'performer_payout'    => $performer_payout,
            'escrow_status'       => self::ESCROW_PENDING,
            'booking_status'      => self::STATUS_PENDING,
            'notes'               => sanitize_textarea_field( $data['notes'] ?? '' ),
        );

        // Encrypt sensitive fields before storage.
        $booking_data = Peanut_Booker_Encryption::encrypt_booking_data( $booking_data );

        $booking_id = Peanut_Booker_Database::insert( 'bookings', $booking_data );

        if ( ! $booking_id ) {
            return new WP_Error( 'insert_failed', __( 'Failed to create booking.', 'peanut-booker' ) );
        }

        // Block performer's calendar.
        Peanut_Booker_Availability::block_date(
            $data['performer_id'],
            $data['event_date'],
            $booking_id,
            $data['event_start_time'] ?? null,
            $data['event_end_time'] ?? null
        );

        // Trigger actions.
        do_action( 'peanut_booker_booking_created', $booking_id, $booking_data );

        // Send notification to performer.
        Peanut_Booker_Notifications::send( 'new_booking', $booking_id );

        return $booking_id;
    }

    /**
     * Get booking by ID.
     *
     * @param int $booking_id Booking ID.
     * @return object|null Booking object or null.
     */
    public static function get( $booking_id ) {
        $booking = Peanut_Booker_Database::get_row( 'bookings', array( 'id' => $booking_id ) );

        // Decrypt sensitive fields.
        if ( $booking ) {
            $booking = Peanut_Booker_Encryption::decrypt_booking_data( $booking );
        }

        return $booking;
    }

    /**
     * Get booking by booking number.
     *
     * @param string $booking_number Booking number.
     * @return object|null Booking object or null.
     */
    public static function get_by_number( $booking_number ) {
        $booking = Peanut_Booker_Database::get_row( 'bookings', array( 'booking_number' => $booking_number ) );

        // Decrypt sensitive fields.
        if ( $booking ) {
            $booking = Peanut_Booker_Encryption::decrypt_booking_data( $booking );
        }

        return $booking;
    }

    /**
     * Get bookings for a customer.
     *
     * @param int    $customer_id Customer user ID.
     * @param string $status      Optional status filter.
     * @param int    $limit       Number of bookings to return.
     * @param int    $offset      Offset for pagination.
     * @return array Array of bookings.
     */
    public static function get_customer_bookings( $customer_id, $status = '', $limit = 10, $offset = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pb_bookings';

        $where = $wpdb->prepare( "customer_id = %d", $customer_id );
        if ( ! empty( $status ) ) {
            $where .= $wpdb->prepare( " AND booking_status = %s", $status );
        }

        $bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE $where ORDER BY event_date DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        return array_map( array( __CLASS__, 'format_booking_data' ), $bookings );
    }

    /**
     * Get bookings for a performer.
     *
     * @param int    $performer_id Performer ID (from performers table).
     * @param string $status       Optional status filter.
     * @param int    $limit        Number of bookings to return.
     * @param int    $offset       Offset for pagination.
     * @return array Array of bookings.
     */
    public static function get_performer_bookings( $performer_id, $status = '', $limit = 10, $offset = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'pb_bookings';

        $where = $wpdb->prepare( "performer_id = %d", $performer_id );
        if ( ! empty( $status ) ) {
            $where .= $wpdb->prepare( " AND booking_status = %s", $status );
        }

        $bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE $where ORDER BY event_date DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        return array_map( array( __CLASS__, 'format_booking_data' ), $bookings );
    }

    /**
     * Update booking.
     *
     * @param int   $booking_id Booking ID.
     * @param array $data       Data to update.
     * @return bool Success.
     */
    public static function update( $booking_id, $data ) {
        $result = Peanut_Booker_Database::update( 'bookings', $data, array( 'id' => $booking_id ) );

        if ( $result !== false ) {
            do_action( 'peanut_booker_booking_updated', $booking_id, $data );
        }

        return $result !== false;
    }

    /**
     * Update booking status.
     *
     * @param int    $booking_id Booking ID.
     * @param string $status     New status.
     * @return bool Success.
     */
    public static function update_status( $booking_id, $status ) {
        $booking = self::get( $booking_id );
        if ( ! $booking ) {
            return false;
        }

        $old_status = $booking->booking_status;
        $result     = self::update( $booking_id, array( 'booking_status' => $status ) );

        if ( $result ) {
            do_action( 'peanut_booker_booking_status_changed', $booking_id, $status, $old_status );

            // Handle status-specific actions.
            switch ( $status ) {
                case self::STATUS_CONFIRMED:
                    Peanut_Booker_Notifications::send( 'booking_confirmed', $booking_id );
                    break;

                case self::STATUS_COMPLETED:
                    self::update( $booking_id, array( 'completion_date' => current_time( 'mysql' ) ) );
                    self::process_completion( $booking_id );
                    break;

                case self::STATUS_CANCELLED:
                    self::process_cancellation( $booking_id );
                    break;
            }
        }

        return $result;
    }

    /**
     * Performer confirms the booking.
     *
     * @param int $booking_id Booking ID.
     * @param int $performer_user_id Performer user ID.
     * @return bool|WP_Error Success or error.
     */
    public static function performer_confirm( $booking_id, $performer_user_id ) {
        $booking = self::get( $booking_id );
        if ( ! $booking ) {
            return new WP_Error( 'not_found', __( 'Booking not found.', 'peanut-booker' ) );
        }

        $performer = Peanut_Booker_Performer::get_by_user_id( $performer_user_id );
        if ( ! $performer || (int) $performer->id !== (int) $booking->performer_id ) {
            return new WP_Error( 'not_authorized', __( 'Not authorized.', 'peanut-booker' ) );
        }

        if ( $booking->booking_status !== self::STATUS_PENDING ) {
            return new WP_Error( 'invalid_status', __( 'Booking cannot be confirmed.', 'peanut-booker' ) );
        }

        self::update( $booking_id, array( 'performer_confirmed' => 1 ) );

        // If deposit is already paid, confirm the booking.
        if ( $booking->deposit_paid ) {
            self::update_status( $booking_id, self::STATUS_CONFIRMED );
        }

        return true;
    }

    /**
     * Customer confirms event completion.
     *
     * @param int $booking_id Booking ID.
     * @param int $customer_id Customer user ID.
     * @return bool|WP_Error Success or error.
     */
    public static function customer_confirm_completion( $booking_id, $customer_id ) {
        $booking = self::get( $booking_id );
        if ( ! $booking ) {
            return new WP_Error( 'not_found', __( 'Booking not found.', 'peanut-booker' ) );
        }

        if ( (int) $booking->customer_id !== $customer_id ) {
            return new WP_Error( 'not_authorized', __( 'Not authorized.', 'peanut-booker' ) );
        }

        if ( $booking->booking_status !== self::STATUS_CONFIRMED ) {
            return new WP_Error( 'invalid_status', __( 'Booking is not in confirmed status.', 'peanut-booker' ) );
        }

        // Check if event date has passed.
        $event_date = strtotime( $booking->event_date );
        if ( $event_date > strtotime( 'today' ) ) {
            return new WP_Error( 'too_early', __( 'Cannot complete booking before event date.', 'peanut-booker' ) );
        }

        self::update(
            $booking_id,
            array(
                'customer_confirmed_completion' => 1,
                'completion_date'               => current_time( 'mysql' ),
            )
        );

        self::update_status( $booking_id, self::STATUS_COMPLETED );

        return true;
    }

    /**
     * Process booking completion - release escrow.
     *
     * @param int $booking_id Booking ID.
     */
    public static function process_completion( $booking_id ) {
        $booking = self::get( $booking_id );
        if ( ! $booking ) {
            return;
        }

        // Release escrow to performer, only if the booking was paid in full.
        // A booking completed with only a deposit (or nothing) collected keeps
        // its escrow state for staff to resolve; it is not paid out.
        $released = self::release_escrow( $booking_id );
        if ( is_wp_error( $released ) ) {
            error_log(
                sprintf(
                    'Peanut Booker: booking %d completed but escrow not released: %s',
                    absint( $booking_id ),
                    $released->get_error_code()
                )
            );
        }

        // Update performer stats.
        $performer = Peanut_Booker_Performer::get( $booking->performer_id );
        if ( $performer ) {
            Peanut_Booker_Performer::update(
                $performer->id,
                array( 'completed_bookings' => $performer->completed_bookings + 1 )
            );

            // Recalculate achievement score.
            Peanut_Booker_Performer::calculate_achievement_score( $performer->id );
        }

        // Send completion notifications.
        Peanut_Booker_Notifications::send( 'booking_completed', $booking_id );

        do_action( 'peanut_booker_booking_completed', $booking_id, $booking );
    }

    /**
     * Process booking cancellation.
     *
     * @param int $booking_id Booking ID.
     */
    public static function process_cancellation( $booking_id ) {
        $booking = self::get( $booking_id );
        if ( ! $booking ) {
            return;
        }

        // Unblock performer's calendar.
        Peanut_Booker_Availability::unblock_date( $booking->performer_id, $booking->event_date, $booking_id );

        // Handle refund if applicable.
        if ( $booking->deposit_paid || $booking->fully_paid ) {
            // Refund logic - depends on cancellation policy.
            self::process_refund( $booking_id );
        }

        // Send cancellation notifications.
        Peanut_Booker_Notifications::send( 'booking_cancelled', $booking_id );

        do_action( 'peanut_booker_booking_cancelled', $booking_id, $booking );
    }

    /**
     * Whether a booking's escrow may be paid out to the performer.
     *
     * A payout sends the performer `performer_payout`: the booking TOTAL less
     * commission. That money is only held once the customer has paid in full,
     * so a payout requires all of:
     * - booking_status = completed (the event happened),
     * - fully_paid = 1 (deposit and balance collected),
     * - escrow_status = full_held (still held: not pending, deposit_held,
     *   released or refunded).
     * A deposit-only booking is never paid out; its deposit covers only part
     * of the payout. Collect the balance first.
     *
     * @param object|null $booking Booking row.
     * @return true|WP_Error True when eligible, otherwise why not.
     */
    public static function is_payout_eligible( $booking ) {
        if ( ! $booking ) {
            return new WP_Error( 'not_found', __( 'Booking not found.', 'peanut-booker' ), array( 'status' => 404 ) );
        }

        if ( self::ESCROW_RELEASED === $booking->escrow_status ) {
            return new WP_Error( 'already_released', __( 'Payout already released.', 'peanut-booker' ), array( 'status' => 409 ) );
        }

        if ( self::STATUS_COMPLETED !== $booking->booking_status ) {
            return new WP_Error( 'invalid_status', __( 'Booking must be completed to release payout.', 'peanut-booker' ), array( 'status' => 409 ) );
        }

        if ( empty( $booking->fully_paid ) || self::ESCROW_FULL !== $booking->escrow_status ) {
            return new WP_Error(
                'not_paid_in_full',
                sprintf(
                    /* translators: %s: escrow status */
                    __( 'Payout can only be released once the booking is paid in full and held in escrow (escrow status is "%s"). Collect the remaining balance first.', 'peanut-booker' ),
                    $booking->escrow_status
                ),
                array( 'status' => 409 )
            );
        }

        return true;
    }

    /**
     * Release escrow funds to performer.
     *
     * Refuses bookings that are not eligible (see is_payout_eligible()). The
     * escrow state change is conditional on the booking still being
     * full_held, so concurrent releases cannot pay twice.
     *
     * @param int $booking_id  Booking ID.
     * @param int $released_by User who released it (0 = automatic, on completion).
     * @return true|WP_Error True on release, WP_Error when refused.
     */
    public static function release_escrow( $booking_id, $released_by = 0 ) {
        $booking  = self::get( $booking_id );
        $eligible = self::is_payout_eligible( $booking );
        if ( is_wp_error( $eligible ) ) {
            return $eligible;
        }

        $now = current_time( 'mysql' );

        // Claim the release atomically: only one caller moves full_held -> released.
        $claimed = Peanut_Booker_Database::update(
            'bookings',
            array(
                'escrow_status' => self::ESCROW_RELEASED,
                'payout_date'   => $now,
            ),
            array(
                'id'            => absint( $booking_id ),
                'escrow_status' => self::ESCROW_FULL,
            )
        );
        if ( ! $claimed ) {
            return new WP_Error( 'already_released', __( 'Payout already released.', 'peanut-booker' ), array( 'status' => 409 ) );
        }

        $released_by = absint( $released_by );
        $releaser    = $released_by ? get_userdata( $released_by ) : null;
        $notes       = $released_by
            ? sprintf(
                /* translators: 1: user ID, 2: user login */
                __( 'Escrow released to performer by user #%1$d (%2$s)', 'peanut-booker' ),
                $released_by,
                $releaser ? $releaser->user_login : __( 'unknown', 'peanut-booker' )
            )
            : __( 'Escrow released to performer automatically on completion', 'peanut-booker' );

        // Record transaction.
        Peanut_Booker_Database::insert(
            'transactions',
            array(
                'booking_id'       => $booking_id,
                'order_id'         => $booking->order_id,
                'transaction_type' => 'escrow_release',
                'amount'           => $booking->performer_payout,
                'payee_id'         => $booking->performer_id,
                'status'           => 'completed',
                'notes'            => $notes,
            )
        );

        // Send payout notification.
        Peanut_Booker_Notifications::send( 'escrow_released', $booking_id );

        do_action( 'peanut_booker_escrow_released', $booking_id, $booking->performer_payout );

        return true;
    }

    /**
     * Process refund for cancelled booking.
     *
     * @param int $booking_id Booking ID.
     */
    public static function process_refund( $booking_id ) {
        $booking = self::get( $booking_id );
        if ( ! $booking ) {
            return;
        }

        // Refund amount depends on cancellation timing and policy.
        // For now, full refund if cancelled before event.
        $refund_amount = $booking->deposit_paid ? $booking->deposit_amount : 0;
        if ( $booking->fully_paid ) {
            $refund_amount = $booking->total_amount;
        }

        if ( $refund_amount > 0 ) {
            // Process WooCommerce refund if order exists.
            if ( $booking->order_id && function_exists( 'wc_create_refund' ) ) {
                $order = wc_get_order( $booking->order_id );
                if ( $order ) {
                    wc_create_refund(
                        array(
                            'order_id' => $booking->order_id,
                            'amount'   => $refund_amount,
                            'reason'   => __( 'Booking cancelled', 'peanut-booker' ),
                        )
                    );
                }
            }

            // Record transaction.
            Peanut_Booker_Database::insert(
                'transactions',
                array(
                    'booking_id'       => $booking_id,
                    'order_id'         => $booking->order_id,
                    'transaction_type' => 'refund',
                    'amount'           => $refund_amount,
                    'payer_id'         => $booking->customer_id,
                    'status'           => 'completed',
                    'notes'            => __( 'Refund for cancelled booking', 'peanut-booker' ),
                )
            );

            self::update( $booking_id, array( 'escrow_status' => self::ESCROW_REFUNDED ) );
        }

        do_action( 'peanut_booker_booking_refunded', $booking_id, $refund_amount );
    }

    /**
     * Process automatic escrow releases for completed events.
     */
    public function process_auto_releases() {
        global $wpdb;

        $options           = get_option( 'peanut_booker_settings', array() );
        $auto_release_days = isset( $options['escrow_auto_release_days'] ) ? intval( $options['escrow_auto_release_days'] ) : 7;

        $table     = $wpdb->prefix . 'pb_bookings';
        $threshold = gmdate( 'Y-m-d', strtotime( "-{$auto_release_days} days" ) );

        // Find bookings that should be auto-released.
        $bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM $table
                WHERE booking_status = 'confirmed'
                AND escrow_status IN ('deposit_held', 'full_held')
                AND event_date <= %s
                AND customer_confirmed_completion = 0",
                $threshold
            )
        );

        foreach ( $bookings as $booking ) {
            // Auto-complete and release escrow.
            self::update_status( $booking->id, self::STATUS_COMPLETED );
        }
    }

    /**
     * Send booking reminders.
     */
    public function send_booking_reminders() {
        global $wpdb;

        $table    = $wpdb->prefix . 'pb_bookings';
        $tomorrow = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
        $week     = gmdate( 'Y-m-d', strtotime( '+7 days' ) );

        // Get bookings happening tomorrow.
        $tomorrow_bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE event_date = %s AND booking_status = 'confirmed'",
                $tomorrow
            )
        );

        foreach ( $tomorrow_bookings as $booking ) {
            Peanut_Booker_Notifications::send( 'booking_reminder_1day', $booking->id );
        }

        // Get bookings happening in a week.
        $week_bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id FROM $table WHERE event_date = %s AND booking_status = 'confirmed'",
                $week
            )
        );

        foreach ( $week_bookings as $booking ) {
            Peanut_Booker_Notifications::send( 'booking_reminder_7day', $booking->id );
        }
    }

    /**
     * Format booking data for display.
     *
     * @param object $booking Booking object from database.
     * @return array Formatted booking data.
     */
    public static function format_booking_data( $booking ) {
        if ( ! $booking ) {
            return array();
        }

        // Decrypt sensitive fields.
        $booking = Peanut_Booker_Encryption::decrypt_booking_data( $booking );

        // Get performer data.
        $performer      = Peanut_Booker_Performer::get( $booking->performer_id );
        $performer_data = array();

        if ( $performer && $performer->profile_id ) {
            $performer_data = Peanut_Booker_Performer::get_display_data( $performer->profile_id );
        }

        // Get customer data.
        $customer = Peanut_Booker_Customer::get( $booking->customer_id );

        return array(
            'id'                => $booking->id,
            'booking_number'    => $booking->booking_number,
            'event_title'       => $booking->event_title,
            'event_description' => $booking->event_description,
            'event_date'        => $booking->event_date,
            'event_date_formatted' => date_i18n( get_option( 'date_format' ), strtotime( $booking->event_date ) ),
            'event_start_time'  => $booking->event_start_time,
            'event_end_time'    => $booking->event_end_time,
            'event_location'    => $booking->event_location,
            'event_address'     => $booking->event_address,
            'event_city'        => $booking->event_city,
            'event_state'       => $booking->event_state,
            'event_zip'         => $booking->event_zip,
            'total_amount'      => floatval( $booking->total_amount ),
            'deposit_amount'    => floatval( $booking->deposit_amount ),
            'remaining_amount'  => floatval( $booking->remaining_amount ),
            'deposit_paid'      => (bool) $booking->deposit_paid,
            'fully_paid'        => (bool) $booking->fully_paid,
            'escrow_status'     => $booking->escrow_status,
            'booking_status'    => $booking->booking_status,
            'status_label'      => self::get_status_label( $booking->booking_status ),
            'performer'         => $performer_data,
            'customer'          => $customer,
            'performer_confirmed' => (bool) $booking->performer_confirmed,
            'customer_confirmed_completion' => (bool) $booking->customer_confirmed_completion,
            'created_at'        => $booking->created_at,
            'can_cancel'        => self::can_cancel( $booking ),
            'can_complete'      => self::can_complete( $booking ),
        );
    }

    /**
     * Get human-readable status label.
     *
     * @param string $status Status code.
     * @return string Status label.
     */
    public static function get_status_label( $status ) {
        $labels = array(
            self::STATUS_PENDING     => __( 'Pending', 'peanut-booker' ),
            self::STATUS_CONFIRMED   => __( 'Confirmed', 'peanut-booker' ),
            self::STATUS_IN_PROGRESS => __( 'In Progress', 'peanut-booker' ),
            self::STATUS_COMPLETED   => __( 'Completed', 'peanut-booker' ),
            self::STATUS_CANCELLED   => __( 'Cancelled', 'peanut-booker' ),
            self::STATUS_DISPUTED    => __( 'Disputed', 'peanut-booker' ),
        );

        return $labels[ $status ] ?? $status;
    }

    /**
     * Check if booking can be cancelled.
     *
     * @param object $booking Booking object.
     * @return bool
     */
    public static function can_cancel( $booking ) {
        // Can't cancel completed or already cancelled bookings.
        if ( in_array( $booking->booking_status, array( self::STATUS_COMPLETED, self::STATUS_CANCELLED ), true ) ) {
            return false;
        }

        // Can't cancel if event has started.
        $event_datetime = $booking->event_date . ' ' . ( $booking->event_start_time ?: '00:00:00' );
        if ( strtotime( $event_datetime ) <= time() ) {
            return false;
        }

        return true;
    }

    /**
     * Check if booking can be marked as complete.
     *
     * @param object $booking Booking object.
     * @return bool
     */
    public static function can_complete( $booking ) {
        // Must be confirmed.
        if ( $booking->booking_status !== self::STATUS_CONFIRMED ) {
            return false;
        }

        // Event date must have passed.
        if ( strtotime( $booking->event_date ) > strtotime( 'today' ) ) {
            return false;
        }

        return true;
    }

    /**
     * AJAX: Create booking.
     */
    public function ajax_create_booking() {
        // Rate limiting: 10 booking attempts per minute
        Peanut_Booker_Rate_Limiter::enforce_ajax( 'booking' );

        check_ajax_referer( 'pb_booking_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'peanut-booker' ) ) );
        }

        $booking_data = array(
            'performer_id'      => absint( $_POST['performer_id'] ?? 0 ),
            'customer_id'       => get_current_user_id(),
            'event_title'       => sanitize_text_field( $_POST['event_title'] ?? '' ),
            'event_description' => sanitize_textarea_field( $_POST['event_description'] ?? '' ),
            'event_date'        => sanitize_text_field( $_POST['event_date'] ?? '' ),
            'event_start_time'  => sanitize_text_field( $_POST['event_start_time'] ?? '' ),
            'event_end_time'    => sanitize_text_field( $_POST['event_end_time'] ?? '' ),
            'event_location'    => sanitize_text_field( $_POST['event_location'] ?? '' ),
            'event_address'     => sanitize_textarea_field( $_POST['event_address'] ?? '' ),
            'event_city'        => sanitize_text_field( $_POST['event_city'] ?? '' ),
            'event_state'       => sanitize_text_field( $_POST['event_state'] ?? '' ),
            'event_zip'         => sanitize_text_field( $_POST['event_zip'] ?? '' ),
            'total_amount'      => floatval( $_POST['total_amount'] ?? 0 ),
            'notes'             => sanitize_textarea_field( $_POST['notes'] ?? '' ),
        );

        $result = self::create( $booking_data );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $booking = self::get( $result );

        wp_send_json_success(
            array(
                'message'        => __( 'Booking created successfully.', 'peanut-booker' ),
                'booking_id'     => $result,
                'booking_number' => $booking->booking_number,
                'checkout_url'   => self::get_checkout_url( $result ),
            )
        );
    }

    /**
     * Get WooCommerce checkout URL for booking.
     *
     * @param int $booking_id Booking ID.
     * @return string Checkout URL.
     */
    public static function get_checkout_url( $booking_id ) {
        // One source for checkout links: the WooCommerce integration adds the
        // CSRF nonce its checkout handler requires and refuses paid bookings.
        if ( ! class_exists( 'Peanut_Booker_WooCommerce' ) ) {
            return '';
        }

        return Peanut_Booker_WooCommerce::get_checkout_url( $booking_id );
    }

    /**
     * AJAX: Confirm booking (performer).
     */
    public function ajax_confirm_booking() {
        // Rate limiting: 10 booking actions per minute
        Peanut_Booker_Rate_Limiter::enforce_ajax( 'booking' );

        check_ajax_referer( 'pb_booking_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'peanut-booker' ) ) );
        }

        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        $result     = self::performer_confirm( $booking_id, get_current_user_id() );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Booking confirmed.', 'peanut-booker' ) ) );
    }

    /**
     * AJAX: Complete booking (customer).
     */
    public function ajax_complete_booking() {
        // Rate limiting: 10 booking actions per minute
        Peanut_Booker_Rate_Limiter::enforce_ajax( 'booking' );

        check_ajax_referer( 'pb_booking_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'peanut-booker' ) ) );
        }

        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        $result     = self::customer_confirm_completion( $booking_id, get_current_user_id() );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => __( 'Booking marked as complete. Funds will be released to performer.', 'peanut-booker' ) ) );
    }

    /**
     * AJAX: Cancel booking.
     */
    public function ajax_cancel_booking() {
        // Rate limiting: 10 booking actions per minute
        Peanut_Booker_Rate_Limiter::enforce_ajax( 'booking' );

        check_ajax_referer( 'pb_booking_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'peanut-booker' ) ) );
        }

        $booking_id = absint( $_POST['booking_id'] ?? 0 );
        $reason     = sanitize_textarea_field( $_POST['reason'] ?? '' );
        $user_id    = get_current_user_id();

        $booking = self::get( $booking_id );
        if ( ! $booking ) {
            wp_send_json_error( array( 'message' => __( 'Booking not found.', 'peanut-booker' ) ) );
        }

        // Check authorization.
        $performer = Peanut_Booker_Performer::get_by_user_id( $user_id );
        $is_performer = $performer && (int) $performer->id === (int) $booking->performer_id;
        $is_customer  = (int) $booking->customer_id === $user_id;

        if ( ! $is_performer && ! $is_customer && ! current_user_can( 'pb_manage_bookings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Not authorized.', 'peanut-booker' ) ) );
        }

        if ( ! self::can_cancel( $booking ) ) {
            wp_send_json_error( array( 'message' => __( 'This booking cannot be cancelled.', 'peanut-booker' ) ) );
        }

        self::update(
            $booking_id,
            array(
                'cancellation_reason' => $reason,
                'cancelled_by'        => $user_id,
            )
        );

        self::update_status( $booking_id, self::STATUS_CANCELLED );

        wp_send_json_success( array( 'message' => __( 'Booking cancelled.', 'peanut-booker' ) ) );
    }
}
