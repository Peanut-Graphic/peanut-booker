<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Booking-related REST API admin endpoints.
 *
 * This trait contains all booking management endpoints for the admin REST API.
 * It is designed to be used by Peanut_Booker_REST_API_Admin class.
 *
 * Methods included:
 * - Route registration
 * - Listing bookings (get_admin_bookings)
 * - Export bookings to CSV (export_bookings)
 * - Update booking status (update_booking_status)
 * - Cancel booking (cancel_booking)
 *
 * @package Peanut_Booker
 * @since   2.0.0
 */

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Trait for booking admin endpoints.
 */
trait Peanut_Booker_REST_Admin_Bookings {

    /**
     * Register booking-related routes.
     *
     * Called from main register_routes() method.
     */
    protected function register_booking_routes() {
        // Admin Bookings list.
        register_rest_route(
            $this->namespace,
            '/admin/bookings',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_admin_bookings' ),
                'permission_callback' => array( $this, 'check_manage_bookings' ),
                'args'                => $this->get_booking_params(),
            )
        );

        // Export bookings to CSV.
        register_rest_route(
            $this->namespace,
            '/admin/bookings/export',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'export_bookings' ),
                'permission_callback' => array( $this, 'check_manage_bookings' ),
            )
        );

        // Update booking status.
        register_rest_route(
            $this->namespace,
            '/admin/bookings/(?P<id>\d+)/status',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'update_booking_status' ),
                'permission_callback' => array( $this, 'check_manage_bookings' ),
            )
        );

        // Cancel booking.
        register_rest_route(
            $this->namespace,
            '/admin/bookings/(?P<id>\d+)/cancel',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'cancel_booking' ),
                'permission_callback' => array( $this, 'check_manage_bookings' ),
            )
        );
    }

    /**
     * Get booking query params.
     *
     * @return array Query parameters for booking endpoints.
     */
    protected function get_booking_params() {
        return array(
            'page'      => array(
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ),
            'per_page'  => array(
                'default'           => 20,
                'sanitize_callback' => 'absint',
            ),
            'search'    => array(
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'status'    => array(
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'date_from' => array(
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'date_to'   => array(
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    /**
     * Get admin bookings list.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function get_admin_bookings( $request ) {
        global $wpdb;

        $page      = $request->get_param( 'page' );
        $per_page  = $request->get_param( 'per_page' );
        $search    = $request->get_param( 'search' );
        $status    = $request->get_param( 'status' );
        $date_from = $request->get_param( 'date_from' );
        $date_to   = $request->get_param( 'date_to' );
        $offset    = ( $page - 1 ) * $per_page;

        $bookings_table   = Peanut_Booker_Database::get_table( 'bookings' );

        $where_clauses = array( '1=1' );
        $where_values  = array();

        if ( ! empty( $search ) ) {
            $where_clauses[] = '(b.booking_number LIKE %s OR b.event_title LIKE %s)';
            $search_like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where_values[]  = $search_like;
            $where_values[]  = $search_like;
        }

        if ( ! empty( $status ) ) {
            $where_clauses[] = 'b.booking_status = %s';
            $where_values[]  = $status;
        }

        if ( ! empty( $date_from ) ) {
            $where_clauses[] = 'b.event_date >= %s';
            $where_values[]  = $date_from;
        }

        if ( ! empty( $date_to ) ) {
            $where_clauses[] = 'b.event_date <= %s';
            $where_values[]  = $date_to;
        }

        $where_sql = implode( ' AND ', $where_clauses );

        // Get total count.
        $count_sql = "SELECT COUNT(*) FROM $bookings_table b WHERE $where_sql";
        if ( ! empty( $where_values ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $where_values );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // Get results with performer and customer info.
        $sql = "SELECT b.*,
                       u_customer.display_name as customer_name,
                       u_customer.user_email as customer_email
                FROM $bookings_table b
                LEFT JOIN {$wpdb->users} u_customer ON b.customer_id = u_customer.ID
                WHERE $where_sql
                ORDER BY b.event_date DESC
                LIMIT %d OFFSET %d";

        $query_values = array_merge( $where_values, array( $per_page, $offset ) );
        $sql          = $wpdb->prepare( $sql, $query_values );

        $results = $wpdb->get_results( $sql );

        // Format results.
        $bookings = array();
        foreach ( $results as $booking ) {
            $bookings[] = $this->format_booking( $booking );
        }

        return rest_ensure_response(
            array(
                'success' => true,
                'data'    => array(
                    'data'        => $bookings,
                    'total'       => $total,
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total_pages' => ceil( $total / $per_page ),
                ),
            )
        );
    }

    /**
     * Export bookings to CSV.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object with CSV content.
     */
    public function export_bookings( $request ) {
        global $wpdb;

        $status    = $request->get_param( 'status' );
        $date_from = $request->get_param( 'date_from' );
        $date_to   = $request->get_param( 'date_to' );

        $bookings_table = Peanut_Booker_Database::get_table( 'bookings' );

        $where_clauses = array( '1=1' );
        $where_values  = array();

        if ( ! empty( $status ) ) {
            $where_clauses[] = 'b.booking_status = %s';
            $where_values[]  = $status;
        }

        if ( ! empty( $date_from ) ) {
            $where_clauses[] = 'b.event_date >= %s';
            $where_values[]  = $date_from;
        }

        if ( ! empty( $date_to ) ) {
            $where_clauses[] = 'b.event_date <= %s';
            $where_values[]  = $date_to;
        }

        $where_sql = implode( ' AND ', $where_clauses );

        $sql = "SELECT b.*,
                       u_customer.display_name as customer_name,
                       u_customer.user_email as customer_email
                FROM $bookings_table b
                LEFT JOIN {$wpdb->users} u_customer ON b.customer_id = u_customer.ID
                WHERE $where_sql
                ORDER BY b.event_date DESC";

        if ( ! empty( $where_values ) ) {
            $sql = $wpdb->prepare( $sql, $where_values );
        }

        $results = $wpdb->get_results( $sql );

        // Generate CSV.
        $csv_lines = array();
        $csv_lines[] = 'Booking ID,Performer,Customer,Event Date,Location,Total,Commission,Status,Escrow Status';

        foreach ( $results as $booking ) {
            $performer_name = $this->get_booking_performer_name( $booking->performer_id );
            $csv_lines[] = sprintf(
                '"%s","%s","%s","%s","%s, %s",%.2f,%.2f,"%s","%s"',
                $booking->booking_number,
                $performer_name,
                $booking->customer_name,
                $booking->event_date,
                $booking->event_city,
                $booking->event_state,
                $booking->total_amount,
                $booking->platform_commission,
                $booking->booking_status,
                $booking->escrow_status
            );
        }

        $csv_content = implode( "\n", $csv_lines );

        return new WP_REST_Response(
            $csv_content,
            200,
            array(
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => 'attachment; filename="bookings-' . gmdate( 'Y-m-d' ) . '.csv"',
            )
        );
    }

    /**
     * Update booking status.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error Response object or error.
     */
    public function update_booking_status( $request ) {
        $id     = $request['id'];
        $status = $request->get_param( 'status' );

        $valid_statuses = array( 'pending', 'confirmed', 'completed', 'cancelled' );
        if ( ! in_array( $status, $valid_statuses, true ) ) {
            return new WP_Error( 'invalid_status', 'Invalid booking status.', array( 'status' => 400 ) );
        }

        $update_data = array(
            'booking_status' => $status,
            'updated_at'     => current_time( 'mysql' ),
        );

        if ( 'completed' === $status ) {
            $update_data['completion_date'] = current_time( 'mysql' );
        }

        Peanut_Booker_Database::update( 'bookings', $update_data, array( 'id' => $id ) );

        $booking = Peanut_Booker_Database::get_row( 'bookings', array( 'id' => $id ) );

        return rest_ensure_response(
            array(
                'success' => true,
                'data'    => $this->format_booking( $booking ),
            )
        );
    }

    /**
     * Cancel booking.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function cancel_booking( $request ) {
        $id     = $request['id'];
        $reason = $request->get_param( 'reason' ) ?: 'Cancelled by admin';

        Peanut_Booker_Database::update(
            'bookings',
            array(
                'booking_status'      => 'cancelled',
                'cancellation_date'   => current_time( 'mysql' ),
                'cancellation_reason' => sanitize_text_field( $reason ),
                'updated_at'          => current_time( 'mysql' ),
            ),
            array( 'id' => $id )
        );

        $booking = Peanut_Booker_Database::get_row( 'bookings', array( 'id' => $id ) );

        return rest_ensure_response(
            array(
                'success' => true,
                'data'    => $this->format_booking( $booking ),
            )
        );
    }

    /**
     * Format booking for API response.
     *
     * @param object $booking Booking database row.
     * @return array|null Formatted booking data or null.
     */
    private function format_booking( $booking ) {
        if ( ! $booking ) {
            return null;
        }

        return array(
            'id'                          => (int) $booking->id,
            'booking_number'              => $booking->booking_number,
            'performer_id'                => (int) $booking->performer_id,
            'performer_name'              => $this->get_booking_performer_name( $booking->performer_id ),
            'customer_id'                 => (int) $booking->customer_id,
            'customer_name'               => isset( $booking->customer_name ) ? $booking->customer_name : '',
            'customer_email'              => isset( $booking->customer_email ) ? $booking->customer_email : '',
            'event_title'                 => $booking->event_title,
            'event_description'           => $booking->event_description,
            'event_date'                  => $booking->event_date,
            'event_time_start'            => $booking->event_time_start,
            'event_time_end'              => $booking->event_time_end,
            'event_location'              => $booking->event_location,
            'event_city'                  => $booking->event_city,
            'event_state'                 => $booking->event_state,
            'total_amount'                => (float) $booking->total_amount,
            'deposit_amount'              => (float) $booking->deposit_amount,
            'remaining_amount'            => (float) $booking->remaining_amount,
            'commission_amount'           => (float) $booking->platform_commission,
            'payout_amount'               => (float) $booking->performer_payout,
            'booking_status'              => $booking->booking_status,
            'escrow_status'               => $booking->escrow_status,
            'performer_confirmed'         => (bool) $booking->performer_confirmed,
            'customer_confirmed_completion' => (bool) $booking->customer_confirmed_completion,
            'completion_date'             => $booking->completion_date,
            'payout_date'                 => $booking->payout_date,
            'cancellation_date'           => $booking->cancellation_date,
            'cancellation_reason'         => $booking->cancellation_reason,
            'created_at'                  => $booking->created_at,
            'updated_at'                  => $booking->updated_at,
        );
    }

    /**
     * Get performer name for booking display.
     *
     * @param int $performer_id Performer ID.
     * @return string Performer name.
     */
    private function get_booking_performer_name( $performer_id ) {
        $performer = Peanut_Booker_Database::get_row( 'performers', array( 'id' => $performer_id ) );
        if ( ! $performer ) {
            return '';
        }

        if ( $performer->profile_id ) {
            $stage_name = get_post_meta( $performer->profile_id, '_pb_stage_name', true );
            if ( ! empty( $stage_name ) ) {
                return $stage_name;
            }
            return get_the_title( $performer->profile_id );
        }

        $user = get_userdata( $performer->user_id );
        return $user ? $user->display_name : '';
    }
}
