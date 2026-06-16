<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Conversation-related REST API admin endpoints.
 *
 * This trait contains all conversation/messaging management endpoints for the admin REST API.
 * It is designed to be used by Peanut_Booker_REST_API_Admin class.
 *
 * Methods included:
 * - Route registration
 * - Listing conversations (get_admin_conversations)
 * - Getting conversation messages (get_conversation_messages)
 *
 * @package Peanut_Booker
 * @since   2.0.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Trait for conversation admin endpoints.
 */
trait Peanut_Booker_REST_Admin_Conversations {

	/**
	 * Register conversation-related routes.
	 *
	 * Called from main register_routes() method.
	 */
	protected function register_conversation_routes() {
		// Admin conversations list.
		register_rest_route(
			$this->namespace,
			'/admin/conversations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_admin_conversations' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
				'args'                => $this->get_pagination_params(),
			)
		);

		// Single conversation messages.
		register_rest_route(
			$this->namespace,
			'/admin/conversations/(?P<id>\d+)/messages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_conversation_messages' ),
				'permission_callback' => array( $this, 'check_admin_permission' ),
			)
		);
	}

	/**
	 * Get admin conversations list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_admin_conversations( $request ) {
		global $wpdb;

		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$search   = $request->get_param( 'search' );
		$offset   = ( $page - 1 ) * $per_page;

		$messages_table = Peanut_Booker_Database::get_table( 'messages' );

		// Build a conversation view from messages.
		$where_clauses = array( '1=1' );
		$where_values  = array();

		if ( ! empty( $search ) ) {
			$where_clauses[] = '(sender_name LIKE %s OR recipient_name LIKE %s)';
			$search_like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where_values[]  = $search_like;
			$where_values[]  = $search_like;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Get unique conversations (grouped by sender/recipient pair).
		$sql = "SELECT
					MIN(id) as id,
					LEAST(sender_id, recipient_id) as participant_1_id,
					GREATEST(sender_id, recipient_id) as participant_2_id,
					MAX(created_at) as last_message_at,
					booking_id
				FROM $messages_table
				WHERE $where_sql
				GROUP BY LEAST(sender_id, recipient_id), GREATEST(sender_id, recipient_id), booking_id
				ORDER BY last_message_at DESC
				LIMIT %d OFFSET %d";

		$query_values = array_merge( $where_values, array( $per_page, $offset ) );
		if ( ! empty( $query_values ) ) {
			$sql = $wpdb->prepare( $sql, $query_values );
		}

		$results = $wpdb->get_results( $sql );

		// Get total count.
		$count_sql = "SELECT COUNT(DISTINCT CONCAT(LEAST(sender_id, recipient_id), '-', GREATEST(sender_id, recipient_id), '-', COALESCE(booking_id, 0)))
					  FROM $messages_table WHERE $where_sql";
		if ( ! empty( $where_values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $where_values );
		}
		$total = (int) $wpdb->get_var( $count_sql );

		// Format results.
		$conversations = array();
		foreach ( $results as $row ) {
			$user1      = get_userdata( $row->participant_1_id );
			$user2      = get_userdata( $row->participant_2_id );
			$user1_type = $this->get_conversation_user_type( $row->participant_1_id );
			$user2_type = $this->get_conversation_user_type( $row->participant_2_id );

			// Get last message.
			$last_msg = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT message FROM $messages_table
					 WHERE (sender_id = %d AND recipient_id = %d) OR (sender_id = %d AND recipient_id = %d)
					 ORDER BY created_at DESC LIMIT 1",
					$row->participant_1_id,
					$row->participant_2_id,
					$row->participant_2_id,
					$row->participant_1_id
				)
			);

			// Get unread count.
			$unread = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $messages_table
					 WHERE recipient_id IN (%d, %d) AND is_read = 0
					 AND ((sender_id = %d AND recipient_id = %d) OR (sender_id = %d AND recipient_id = %d))",
					$row->participant_1_id,
					$row->participant_2_id,
					$row->participant_1_id,
					$row->participant_2_id,
					$row->participant_2_id,
					$row->participant_1_id
				)
			);

			$conversations[] = array(
				'id'                 => (int) $row->id,
				'participant_1_id'   => (int) $row->participant_1_id,
				'participant_1_name' => $user1 ? $user1->display_name : 'Unknown',
				'participant_1_type' => $user1_type,
				'participant_2_id'   => (int) $row->participant_2_id,
				'participant_2_name' => $user2 ? $user2->display_name : 'Unknown',
				'participant_2_type' => $user2_type,
				'last_message'       => $last_msg ? wp_trim_words( $last_msg, 15 ) : null,
				'last_message_at'    => $row->last_message_at,
				'unread_count'       => (int) $unread,
				'booking_id'         => $row->booking_id ? (int) $row->booking_id : null,
				'created_at'         => $row->last_message_at,
			);
		}

		return rest_ensure_response(
			array(
				'data'        => $conversations,
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Get conversation messages.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function get_conversation_messages( $request ) {
		global $wpdb;

		$conversation_id = $request['id'];
		$messages_table  = Peanut_Booker_Database::get_table( 'messages' );

		// Get a reference message to find participants.
		$ref_msg = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT sender_id, recipient_id FROM $messages_table WHERE id = %d",
				$conversation_id
			)
		);

		if ( ! $ref_msg ) {
			return new WP_Error( 'not_found', 'Conversation not found.', array( 'status' => 404 ) );
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $messages_table
				 WHERE (sender_id = %d AND recipient_id = %d) OR (sender_id = %d AND recipient_id = %d)
				 ORDER BY created_at ASC
				 LIMIT 100",
				$ref_msg->sender_id,
				$ref_msg->recipient_id,
				$ref_msg->recipient_id,
				$ref_msg->sender_id
			)
		);

		$messages = array();
		foreach ( $results as $msg ) {
			$sender    = get_userdata( $msg->sender_id );
			$recipient = get_userdata( $msg->recipient_id );

			$messages[] = array(
				'id'              => (int) $msg->id,
				'conversation_id' => $conversation_id,
				'sender_id'       => (int) $msg->sender_id,
				'sender_name'     => $sender ? $sender->display_name : 'Unknown',
				'sender_type'     => $this->get_conversation_user_type( $msg->sender_id ),
				'recipient_id'    => (int) $msg->recipient_id,
				'recipient_name'  => $recipient ? $recipient->display_name : 'Unknown',
				'content'         => $msg->message,
				'is_read'         => (bool) $msg->is_read,
				'booking_id'      => $msg->booking_id ? (int) $msg->booking_id : null,
				'created_at'      => $msg->created_at,
			);
		}

		return rest_ensure_response(
			array(
				'data'        => $messages,
				'total'       => count( $messages ),
				'page'        => 1,
				'per_page'    => 100,
				'total_pages' => 1,
			)
		);
	}

	/**
	 * Get user type for conversation display.
	 *
	 * @param int $user_id User ID.
	 * @return string User type (performer, customer, or unknown).
	 */
	private function get_conversation_user_type( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return 'unknown';
		}
		if ( in_array( 'pb_performer', (array) $user->roles, true ) ) {
			return 'performer';
		}
		return 'customer';
	}
}
