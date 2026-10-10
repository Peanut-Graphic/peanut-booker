<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Messaging system functionality.
 *
 * @package Peanut_Booker
 * @since   1.6.0
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Messages class.
 */
class Peanut_Booker_Messages {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'wp_ajax_pb_send_message', array( $this, 'ajax_send_message' ) );
		add_action( 'wp_ajax_pb_get_messages', array( $this, 'ajax_get_messages' ) );
		add_action( 'wp_ajax_pb_mark_read', array( $this, 'ajax_mark_read' ) );
		add_action( 'wp_ajax_pb_get_conversations', array( $this, 'ajax_get_conversations' ) );
	}

	/**
	 * Send a message.
	 *
	 * @param int    $sender_id    Sender user ID.
	 * @param int    $recipient_id Recipient user ID.
	 * @param string $message      Message content.
	 * @param int    $booking_id   Optional booking ID.
	 * @return int|WP_Error Message ID or error.
	 */
	public static function send( $sender_id, $recipient_id, $message, $booking_id = null ) {
		if ( empty( $message ) ) {
			return new WP_Error( 'empty_message', __( 'Message cannot be empty.', 'peanut-booker' ) );
		}

		if ( $sender_id === $recipient_id ) {
			return new WP_Error( 'invalid_recipient', __( 'Cannot send message to yourself.', 'peanut-booker' ) );
		}

		$message_data = array(
			'sender_id'    => $sender_id,
			'recipient_id' => $recipient_id,
			'message'      => sanitize_textarea_field( $message ),
			'booking_id'   => $booking_id,
			'is_read'      => 0,
			'created_at'   => current_time( 'mysql' ),
		);

		$message_id = Peanut_Booker_Database::insert( 'messages', $message_data );

		if ( ! $message_id ) {
			return new WP_Error( 'insert_failed', __( 'Failed to send message.', 'peanut-booker' ) );
		}

		// Send email notification.
		self::send_notification( $message_id );

		do_action( 'peanut_booker_message_sent', $message_id, $sender_id, $recipient_id );

		return $message_id;
	}

	/**
	 * Whether a user may send a message to another user.
	 *
	 * Allowed when the two are the customer and the performer of a booking
	 * (and, if a booking ID is given, it is one of their bookings), when the
	 * sender manages bookings, or when the sender is replying to a message the
	 * recipient sent them.
	 *
	 * @param int      $sender_id    Sender user ID.
	 * @param int      $recipient_id Recipient user ID.
	 * @param int|null $booking_id   Optional booking the message is about.
	 * @return bool
	 */
	public static function can_message( $sender_id, $recipient_id, $booking_id = null ) {
		global $wpdb;

		$sender_id    = absint( $sender_id );
		$recipient_id = absint( $recipient_id );
		$booking_id   = $booking_id ? absint( $booking_id ) : 0;

		if ( ! $sender_id || ! $recipient_id || $sender_id === $recipient_id ) {
			return false;
		}

		$bookings   = $wpdb->prefix . 'pb_bookings';
		$performers = $wpdb->prefix . 'pb_performers';

		if ( user_can( $sender_id, 'pb_manage_bookings' ) ) {
			if ( ! $booking_id ) {
				return true;
			}
			// Staff may attach any booking, as long as it exists.
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $bookings WHERE id = %d", $booking_id ) );
		}

		$shared_booking = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT b.id FROM $bookings b
				INNER JOIN $performers p ON p.id = b.performer_id
				WHERE ( ( b.customer_id = %d AND p.user_id = %d ) OR ( b.customer_id = %d AND p.user_id = %d ) )
				AND ( %d = 0 OR b.id = %d )
				LIMIT 1",
				$sender_id,
				$recipient_id,
				$recipient_id,
				$sender_id,
				$booking_id,
				$booking_id
			)
		);

		if ( $shared_booking ) {
			return true;
		}

		// A reply to someone who messaged you first (e.g. staff), not tied to
		// a booking you are not part of.
		if ( ! $booking_id ) {
			$messages = $wpdb->prefix . 'pb_messages';
			return (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM $messages WHERE sender_id = %d AND recipient_id = %d LIMIT 1",
					$recipient_id,
					$sender_id
				)
			);
		}

		return false;
	}

	/**
	 * Get messages between two users.
	 *
	 * @param int $user1_id First user ID.
	 * @param int $user2_id Second user ID.
	 * @param int $limit    Number of messages to return.
	 * @param int $offset   Offset for pagination.
	 * @return array Messages.
	 */
	public static function get_conversation( $user1_id, $user2_id, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pb_messages';

		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table
				WHERE (sender_id = %d AND recipient_id = %d)
				OR (sender_id = %d AND recipient_id = %d)
				ORDER BY created_at DESC
				LIMIT %d OFFSET %d",
				$user1_id,
				$user2_id,
				$user2_id,
				$user1_id,
				$limit,
				$offset
			)
		);

		return array_reverse( $messages );
	}

	/**
	 * Get all conversations for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array Conversations with latest message.
	 */
	public static function get_conversations( $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pb_messages';

		// Get unique conversation partners.
		$conversations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE
						WHEN sender_id = %d THEN recipient_id
						ELSE sender_id
					END as other_user_id,
					MAX(created_at) as last_message_time,
					COUNT(CASE WHEN recipient_id = %d AND is_read = 0 THEN 1 END) as unread_count
				FROM $table
				WHERE sender_id = %d OR recipient_id = %d
				GROUP BY other_user_id
				ORDER BY last_message_time DESC",
				$user_id,
				$user_id,
				$user_id,
				$user_id
			)
		);

		$result = array();

		foreach ( $conversations as $conv ) {
			$other_user = get_userdata( $conv->other_user_id );
			if ( ! $other_user ) {
				continue;
			}

			// Get last message.
			$last_message = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $table
					WHERE (sender_id = %d AND recipient_id = %d)
					OR (sender_id = %d AND recipient_id = %d)
					ORDER BY created_at DESC
					LIMIT 1",
					$user_id,
					$conv->other_user_id,
					$conv->other_user_id,
					$user_id
				)
			);

			// Check if other user is performer.
			$performer = Peanut_Booker_Performer::get_by_user_id( $conv->other_user_id );

			$result[] = array(
				'user_id'         => $conv->other_user_id,
				'user_name'       => $other_user->display_name,
				'user_avatar'     => get_avatar_url( $conv->other_user_id ),
				'is_performer'    => (bool) $performer,
				'last_message'    => $last_message ? $last_message->message : '',
				'last_message_time' => $conv->last_message_time,
				'unread_count'    => intval( $conv->unread_count ),
			);
		}

		return $result;
	}

	/**
	 * Mark messages as read.
	 *
	 * @param int $user_id     User ID (recipient).
	 * @param int $sender_id   Sender ID.
	 * @return bool Success.
	 */
	public static function mark_read( $user_id, $sender_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pb_messages';

		$result = $wpdb->update(
			$table,
			array( 'is_read' => 1 ),
			array(
				'recipient_id' => $user_id,
				'sender_id'    => $sender_id,
			)
		);

		return $result !== false;
	}

	/**
	 * Get unread message count for user.
	 *
	 * @param int $user_id User ID.
	 * @return int Unread count.
	 */
	public static function get_unread_count( $user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pb_messages';

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table
				WHERE recipient_id = %d AND is_read = 0",
				$user_id
			)
		);
	}

	/**
	 * Send email notification for new message.
	 *
	 * @param int $message_id Message ID.
	 */
	private static function send_notification( $message_id ) {
		$message = Peanut_Booker_Database::get_row( 'messages', array( 'id' => $message_id ) );
		if ( ! $message ) {
			return;
		}

		$sender    = get_userdata( $message->sender_id );
		$recipient = get_userdata( $message->recipient_id );

		if ( ! $sender || ! $recipient ) {
			return;
		}

		$subject = sprintf(
			__( 'New message from %s', 'peanut-booker' ),
			$sender->display_name
		);

		$message_text = wp_trim_words( $message->message, 50 );

		$body = sprintf(
			__( "Hi %s,\n\nYou have received a new message from %s:\n\n%s\n\nLogin to view and reply: %s\n\nBest regards,\nPeanut Booker", 'peanut-booker' ),
			$recipient->display_name,
			$sender->display_name,
			$message_text,
			admin_url( 'admin.php?page=pb-messages' )
		);

		wp_mail( $recipient->user_email, $subject, $body );
	}

	/**
	 * AJAX: Send message.
	 */
	public function ajax_send_message() {
		check_ajax_referer( 'pb_messages_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'peanut-booker' ) ) );
		}

		Peanut_Booker_Rate_Limiter::enforce_ajax( 'message' );

		$sender_id    = get_current_user_id();
		$recipient_id = absint( wp_unslash( $_POST['recipient_id'] ?? 0 ) );
		$message      = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
		$booking_id   = ! empty( $_POST['booking_id'] ) ? absint( wp_unslash( $_POST['booking_id'] ) ) : null;

		if ( ! $recipient_id || ! get_userdata( $recipient_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid recipient.', 'peanut-booker' ) ) );
		}

		// SECURITY: Only people with a booking between them (or staff, or
		// someone replying to a message they received) may message each other.
		// Otherwise any account could message, and trigger notification email
		// to, any user ID, including administrators.
		if ( ! self::can_message( $sender_id, $recipient_id, $booking_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You can only message people you have a booking with.', 'peanut-booker' ) ), 403 );
		}

		$result = self::send( $sender_id, $recipient_id, $message, $booking_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$message_obj = Peanut_Booker_Database::get_row( 'messages', array( 'id' => $result ) );

		wp_send_json_success(
			array(
				'message'    => __( 'Message sent.', 'peanut-booker' ),
				'message_id' => $result,
				'message_data' => self::format_message( $message_obj, $sender_id ),
			)
		);
	}

	/**
	 * AJAX: Get messages.
	 */
	public function ajax_get_messages() {
		check_ajax_referer( 'pb_messages_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'peanut-booker' ) ) );
		}

		$user_id      = get_current_user_id();
		$other_user_id = absint( $_GET['user_id'] ?? 0 );
		$limit        = absint( $_GET['limit'] ?? 50 );
		$offset       = absint( $_GET['offset'] ?? 0 );

		if ( ! $other_user_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid user.', 'peanut-booker' ) ) );
		}

		$messages = self::get_conversation( $user_id, $other_user_id, $limit, $offset );

		// Mark messages as read.
		self::mark_read( $user_id, $other_user_id );

		$formatted = array_map(
			function( $message ) use ( $user_id ) {
				return self::format_message( $message, $user_id );
			},
			$messages
		);

		wp_send_json_success(
			array(
				'messages' => $formatted,
			)
		);
	}

	/**
	 * AJAX: Mark messages as read.
	 */
	public function ajax_mark_read() {
		check_ajax_referer( 'pb_messages_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'peanut-booker' ) ) );
		}

		$user_id   = get_current_user_id();
		$sender_id = absint( $_POST['sender_id'] ?? 0 );

		if ( ! $sender_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid sender.', 'peanut-booker' ) ) );
		}

		$result = self::mark_read( $user_id, $sender_id );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Messages marked as read.', 'peanut-booker' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to mark messages as read.', 'peanut-booker' ) ) );
		}
	}

	/**
	 * AJAX: Get conversations.
	 */
	public function ajax_get_conversations() {
		check_ajax_referer( 'pb_messages_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in.', 'peanut-booker' ) ) );
		}

		$user_id = get_current_user_id();
		$conversations = self::get_conversations( $user_id );

		wp_send_json_success(
			array(
				'conversations' => $conversations,
				'unread_total'  => self::get_unread_count( $user_id ),
			)
		);
	}

	/**
	 * Format message for display.
	 *
	 * @param object $message    Message object.
	 * @param int    $current_user Current user ID.
	 * @return array Formatted message.
	 */
	private static function format_message( $message, $current_user ) {
		$sender = get_userdata( $message->sender_id );

		return array(
			'id'          => $message->id,
			'message'     => $message->message,
			'sender_id'   => $message->sender_id,
			'sender_name' => $sender ? $sender->display_name : __( 'Unknown', 'peanut-booker' ),
			'sender_avatar' => get_avatar_url( $message->sender_id ),
			'is_own'      => $message->sender_id == $current_user,
			'is_read'     => (bool) $message->is_read,
			'created_at'  => $message->created_at,
			'created_ago' => human_time_diff( strtotime( $message->created_at ), current_time( 'timestamp' ) ),
			'booking_id'  => $message->booking_id,
		);
	}

	/**
	 * Render messages UI.
	 *
	 * @param int $user_id      Current user ID.
	 * @param int $other_user_id Optional other user to show conversation with.
	 * @return string HTML.
	 */
	public static function render( $user_id, $other_user_id = null ) {
		$conversations = self::get_conversations( $user_id );

		ob_start();
		?>
		<div class="pb-messages-container" data-user-id="<?php echo esc_attr( $user_id ); ?>">
			<div class="pb-messages-sidebar">
				<h3><?php esc_html_e( 'Conversations', 'peanut-booker' ); ?></h3>
				<div class="pb-conversations-list" role="list">
					<?php if ( empty( $conversations ) ) : ?>
						<p class="pb-no-conversations"><?php esc_html_e( 'No conversations yet.', 'peanut-booker' ); ?></p>
					<?php else : ?>
						<?php foreach ( $conversations as $conv ) : ?>
							<div class="pb-conversation-item <?php echo $conv['unread_count'] > 0 ? 'unread' : ''; ?>"
								data-user-id="<?php echo esc_attr( $conv['user_id'] ); ?>"
								role="listitem"
								tabindex="0"
								aria-label="<?php echo esc_attr( sprintf( __( 'Conversation with %s', 'peanut-booker' ), $conv['user_name'] ) ); ?>">
								<img src="<?php echo esc_url( $conv['user_avatar'] ); ?>" alt="" class="pb-avatar">
								<div class="pb-conv-details">
									<div class="pb-conv-name"><?php echo esc_html( $conv['user_name'] ); ?></div>
									<div class="pb-conv-preview"><?php echo esc_html( wp_trim_words( $conv['last_message'], 10 ) ); ?></div>
								</div>
								<?php if ( $conv['unread_count'] > 0 ) : ?>
									<span class="pb-unread-badge" aria-label="<?php echo esc_attr( sprintf( _n( '%d unread message', '%d unread messages', $conv['unread_count'], 'peanut-booker' ), $conv['unread_count'] ) ); ?>">
										<?php echo esc_html( $conv['unread_count'] ); ?>
									</span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<div class="pb-messages-main">
				<div class="pb-messages-header" role="banner"></div>
				<div class="pb-messages-body" role="log" aria-live="polite" aria-atomic="false"></div>
				<div class="pb-messages-footer">
					<form class="pb-message-form" aria-label="<?php esc_attr_e( 'Send message', 'peanut-booker' ); ?>">
						<textarea
							name="message"
							placeholder="<?php esc_attr_e( 'Type your message...', 'peanut-booker' ); ?>"
							rows="3"
							required
							aria-label="<?php esc_attr_e( 'Message text', 'peanut-booker' ); ?>"></textarea>
						<button type="submit" aria-label="<?php esc_attr_e( 'Send message', 'peanut-booker' ); ?>">
							<?php esc_html_e( 'Send', 'peanut-booker' ); ?>
						</button>
					</form>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
