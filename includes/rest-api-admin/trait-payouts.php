<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Payout-related REST API admin endpoints.
 *
 * This trait contains all payout management endpoints for the admin REST API.
 * It is designed to be used by Peanut_Booker_REST_API_Admin class.
 *
 * Methods included:
 * - Route registration
 * - Payout statistics (get_payout_stats)
 * - Pending payouts list (get_pending_payouts)
 * - Release payout (release_payout)
 * - Bulk release payouts (bulk_release_payouts)
 *
 * @package Peanut_Booker
 * @since   2.0.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Trait for payout admin endpoints.
 */
trait Peanut_Booker_REST_Admin_Payouts {

	/**
	 * Register payout-related routes.
	 *
	 * Called from main register_routes() method.
	 */
	protected function register_payout_routes() {
		// Payout statistics.
		register_rest_route(
			$this->namespace,
			'/admin/payouts/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_payout_stats' ),
				'permission_callback' => array( $this, 'check_manage_payouts' ),
			)
		);

		// Pending payouts list.
		register_rest_route(
			$this->namespace,
			'/admin/payouts/pending',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_pending_payouts' ),
				'permission_callback' => array( $this, 'check_manage_payouts' ),
			)
		);

		// Release single payout.
		register_rest_route(
			$this->namespace,
			'/admin/payouts/(?P<booking_id>\d+)/release',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'release_payout' ),
				'permission_callback' => array( $this, 'check_manage_payouts' ),
			)
		);

		// Bulk release payouts.
		register_rest_route(
			$this->namespace,
			'/admin/payouts/bulk-release',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_release_payouts' ),
				'permission_callback' => array( $this, 'check_manage_payouts' ),
			)
		);
	}

	/**
	 * Get payout statistics.
	 *
	 * @return WP_REST_Response Response object.
	 */
	public function get_payout_stats() {
		global $wpdb;

		$bookings_table = Peanut_Booker_Database::get_table( 'bookings' );

		$pending = $wpdb->get_var(
			"SELECT COALESCE(SUM(performer_payout), 0)
			 FROM $bookings_table
			 WHERE booking_status = 'completed' AND escrow_status = 'full_held'"
		);

		$released = $wpdb->get_var(
			"SELECT COALESCE(SUM(performer_payout), 0)
			 FROM $bookings_table
			 WHERE escrow_status = 'released'"
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'pending'  => (float) $pending,
					'released' => (float) $released,
				),
			)
		);
	}

	/**
	 * Get pending payouts list.
	 *
	 * @return WP_REST_Response Response object.
	 */
	public function get_pending_payouts() {
		global $wpdb;

		$bookings_table = Peanut_Booker_Database::get_table( 'bookings' );

		$sql = "SELECT b.*, u.display_name as customer_name
				FROM $bookings_table b
				LEFT JOIN {$wpdb->users} u ON b.customer_id = u.ID
				WHERE b.booking_status = 'completed' AND b.escrow_status = 'full_held'
				ORDER BY b.completion_date ASC";

		$results = $wpdb->get_results( $sql );

		$payouts = array();
		foreach ( $results as $booking ) {
			$auto_release_days = (int) get_option( 'peanut_booker_auto_release_days', 7 );
			$completion_date   = new DateTime( $booking->completion_date );
			$auto_release_date = $completion_date->modify( "+{$auto_release_days} days" )->format( 'Y-m-d' );

			$payouts[] = array(
				'booking_id'        => (int) $booking->id,
				'booking_number'    => $booking->booking_number,
				'performer_id'      => (int) $booking->performer_id,
				'performer_name'    => $this->get_payout_performer_name( $booking->performer_id ),
				'event_date'        => $booking->event_date,
				'completion_date'   => $booking->completion_date,
				'total_amount'      => (float) $booking->total_amount,
				'commission_amount' => (float) $booking->platform_commission,
				'payout_amount'     => (float) $booking->performer_payout,
				'escrow_status'     => $booking->escrow_status,
				'auto_release_date' => $auto_release_date,
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $payouts,
			)
		);
	}

	/**
	 * Release a single payout.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function release_payout( $request ) {
		$booking_id = $request['booking_id'];

		$booking = Peanut_Booker_Database::get_row( 'bookings', array( 'id' => $booking_id ) );

		if ( ! $booking ) {
			return new WP_Error( 'not_found', 'Booking not found.', array( 'status' => 404 ) );
		}

		if ( 'completed' !== $booking->booking_status ) {
			return new WP_Error( 'invalid_status', 'Booking must be completed to release payout.', array( 'status' => 400 ) );
		}

		if ( 'released' === $booking->escrow_status ) {
			return new WP_Error( 'already_released', 'Payout already released.', array( 'status' => 400 ) );
		}

		Peanut_Booker_Database::update(
			'bookings',
			array(
				'escrow_status' => 'released',
				'payout_date'   => current_time( 'mysql' ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $booking_id )
		);

		// Send notification to performer.
		do_action( 'peanut_booker_payout_released', $booking_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'Payout released successfully.',
			)
		);
	}

	/**
	 * Bulk release payouts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function bulk_release_payouts( $request ) {
		$booking_ids = $request->get_param( 'booking_ids' );

		if ( empty( $booking_ids ) || ! is_array( $booking_ids ) ) {
			return new WP_Error( 'invalid_ids', 'Invalid booking IDs.', array( 'status' => 400 ) );
		}

		$released = 0;
		foreach ( $booking_ids as $booking_id ) {
			$booking = Peanut_Booker_Database::get_row( 'bookings', array( 'id' => $booking_id ) );

			if ( $booking && 'completed' === $booking->booking_status && 'held' === $booking->escrow_status ) {
				Peanut_Booker_Database::update(
					'bookings',
					array(
						'escrow_status' => 'released',
						'payout_date'   => current_time( 'mysql' ),
						'updated_at'    => current_time( 'mysql' ),
					),
					array( 'id' => $booking_id )
				);
				$released++;

				do_action( 'peanut_booker_payout_released', $booking_id );
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => sprintf( 'Released %d payout(s).', $released ),
			)
		);
	}

	/**
	 * Get performer name for payout display.
	 *
	 * @param int $performer_id Performer ID.
	 * @return string Performer name.
	 */
	private function get_payout_performer_name( $performer_id ) {
		$performer = Peanut_Booker_Database::get_row( 'performers', array( 'id' => $performer_id ) );

		if ( ! $performer || ! $performer->profile_id ) {
			return 'Unknown';
		}

		$stage_name = get_post_meta( $performer->profile_id, '_pb_stage_name', true );
		if ( ! empty( $stage_name ) ) {
			return html_entity_decode( $stage_name, ENT_QUOTES, 'UTF-8' );
		}

		return get_the_title( $performer->profile_id ) ?: 'Unknown';
	}
}
