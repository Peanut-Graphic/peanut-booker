<?php
/**
 * The pb_get_availability and pb_send_message AJAX handlers, dispatched through
 * WordPress's real admin-ajax test harness against the real plugin schema.
 *
 * Neither handler was reachable on origin/main (pb_get_availability checked a
 * nonce action nothing generated; nothing generates pb_messages_nonce), but both
 * are registered and would become live the moment a nonce is printed, so they
 * must hold the same boundary as the REST routes.
 */
namespace Peanut_Booker\Tests\ContractWp;

use Peanut_Booker_Activator;
use Peanut_Booker_Availability;
use Peanut_Booker_Database;
use Peanut_Booker_Messages;
use Peanut_Booker_Post_Types;
use Peanut_Booker_Rate_Limiter;
use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

final class LatentAjaxHandlersTest extends WP_Ajax_UnitTestCase {
    public static function wpSetUpBeforeClass($factory): void {
        $base = dirname(__DIR__, 2) . '/includes/';
        foreach (['activator', 'database', 'encryption', 'customer', 'roles', 'performer', 'availability', 'messages', 'rate-limiter', 'post-types'] as $name) {
            require_once $base . 'class-' . $name . '.php';
        }
        $schema = new \ReflectionMethod(Peanut_Booker_Activator::class, 'create_tables');
        $schema->invoke(null);
    }

    public function set_up(): void {
        parent::set_up();
        foreach (['general', 'message'] as $bucket) {
            Peanut_Booker_Rate_Limiter::reset($bucket);
        }
        $types = new Peanut_Booker_Post_Types();
        $types->register_post_types();
        new Peanut_Booker_Availability();
        new Peanut_Booker_Messages();
        reset_phpmailer_instance();
    }

    /** Dispatch an AJAX action and return the decoded JSON response (or null). */
    private function ajax(string $action, array $post): ?array {
        $_POST = $post;
        $_REQUEST = $post + ['action' => $action];
        $this->_last_response = '';
        try {
            $this->_handleAjax($action);
        } catch (WPAjaxDieContinueException | WPAjaxDieStopException $e) {
            // wp_send_json_* ends the request.
        }
        $decoded = json_decode($this->_last_response, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return array{0:int,1:int,2:int} performer id, profile id, user id */
    private function performer(string $status = 'publish'): array {
        $user = self::factory()->user->create(['role' => 'subscriber']);
        $post = self::factory()->post->create(['post_type' => 'pb_performer', 'post_status' => $status]);
        $id = Peanut_Booker_Database::insert('performers', ['user_id' => $user, 'profile_id' => $post, 'status' => 'active']);
        $this->assertNotFalse($id);
        return [(int) $id, (int) $post, (int) $user];
    }

    private function booking(int $performer_id, int $customer_id): int {
        $id = Peanut_Booker_Database::insert('bookings', [
            'booking_number' => 'PB-' . wp_generate_password(8, false), 'performer_id' => $performer_id, 'customer_id' => $customer_id,
            'event_title' => 'Synthetic', 'event_date' => '2026-12-01', 'total_amount' => 100, 'deposit_amount' => 25, 'remaining_amount' => 75,
        ]);
        $this->assertNotFalse($id);
        return (int) $id;
    }

    private function messages_between(int $a, int $b): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pb_messages WHERE (sender_id = %d AND recipient_id = %d) OR (sender_id = %d AND recipient_id = %d)",
            $a, $b, $b, $a
        ));
    }

    // --- pb_get_availability -------------------------------------------------

    public function test_public_availability_ajax_returns_only_date_status_color(): void {
        [$id] = $this->performer();
        $month = gmdate('Y-m', strtotime('+1 month'));
        Peanut_Booker_Database::insert('availability', ['performer_id' => $id, 'date' => $month . '-10', 'slot_type' => 'full_day', 'status' => 'booked', 'booking_id' => 515151, 'event_name' => 'PRIVATE-EVENT', 'venue_name' => 'PRIVATE-VENUE', 'notes' => 'PRIVATE-NOTES']);

        wp_set_current_user(0);
        // The public script posts year and month separately with peanutBooker.nonces.availability.
        $response = $this->ajax('pb_get_availability', [
            'performer_id' => (string) $id, 'year' => substr($month, 0, 4), 'month' => (string) (int) substr($month, 5, 2),
            'nonce' => wp_create_nonce('pb_availability_nonce'),
        ]);
        $this->assertTrue($response['success'] ?? false, $this->_last_response);
        $day = $response['data']['calendar'][$month . '-10'];
        $keys = array_keys($day);
        sort($keys);
        $this->assertSame(['color', 'date', 'status'], $keys);
        $this->assertSame('booked', $day['status']);
        $this->assertStringNotContainsString('PRIVATE', wp_json_encode($response['data']['calendar']));
        $this->assertStringNotContainsString('515151', wp_json_encode($response['data']['calendar']));
    }

    public function test_availability_ajax_refuses_unpublished_profiles_and_bad_nonces(): void {
        [$draft] = $this->performer('draft');
        wp_set_current_user(0);
        $response = $this->ajax('pb_get_availability', ['performer_id' => (string) $draft, 'nonce' => wp_create_nonce('pb_availability_nonce')]);
        $this->assertFalse($response['success'] ?? true);

        [$published] = $this->performer();
        $response = $this->ajax('pb_get_availability', ['performer_id' => (string) $published, 'nonce' => 'not-a-nonce']);
        $this->assertFalse($response['success'] ?? true);
    }

    public function test_performer_sees_own_full_calendar_over_ajax(): void {
        [$id, , $user] = $this->performer();
        $month = gmdate('Y-m', strtotime('+1 month'));
        Peanut_Booker_Database::insert('availability', ['performer_id' => $id, 'date' => $month . '-10', 'slot_type' => 'full_day', 'status' => 'booked', 'event_name' => 'PRIVATE-EVENT']);
        wp_set_current_user($user);
        $response = $this->ajax('pb_get_availability', ['performer_id' => (string) $id, 'month' => $month, 'nonce' => wp_create_nonce('pb_availability_nonce')]);
        $this->assertSame('PRIVATE-EVENT', $response['data']['calendar'][$month . '-10']['slots'][0]['event_name']);
    }

    // --- pb_send_message -----------------------------------------------------

    private function send(int $recipient, ?int $booking = null): ?array {
        $post = ['recipient_id' => (string) $recipient, 'message' => 'Hello there', 'nonce' => wp_create_nonce('pb_messages_nonce')];
        if (null !== $booking) {
            $post['booking_id'] = (string) $booking;
        }
        return $this->ajax('pb_send_message', $post);
    }

    public function test_customer_cannot_message_arbitrary_users(): void {
        $customer = self::factory()->user->create(['role' => 'subscriber']);
        $stranger = self::factory()->user->create(['role' => 'subscriber']);
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($customer);

        foreach ([$stranger, $admin] as $recipient) {
            $response = $this->send($recipient);
            $this->assertFalse($response['success'] ?? true, $this->_last_response);
            $this->assertSame(0, $this->messages_between($customer, $recipient));
        }
        $this->assertCount(0, tests_retrieve_phpmailer_instance()->mock_sent, 'no notification email is relayed');
    }

    public function test_booking_parties_can_message_each_other(): void {
        [$performer, , $performer_user] = $this->performer();
        $customer = self::factory()->user->create(['role' => 'subscriber']);
        $booking = $this->booking($performer, $customer);

        wp_set_current_user($customer);
        $this->assertTrue($this->send($performer_user, $booking)['success'] ?? false, $this->_last_response);
        wp_set_current_user($performer_user);
        $this->assertTrue($this->send($customer)['success'] ?? false, $this->_last_response);
        $this->assertSame(2, $this->messages_between($customer, $performer_user));
    }

    public function test_booking_id_must_belong_to_the_conversation(): void {
        [$performer, , $performer_user] = $this->performer();
        $customer = self::factory()->user->create(['role' => 'subscriber']);
        $this->booking($performer, $customer);
        $other_customer = self::factory()->user->create(['role' => 'subscriber']);
        $someone_elses_booking = $this->booking($performer, $other_customer);

        wp_set_current_user($customer);
        $this->assertFalse($this->send($performer_user, $someone_elses_booking)['success'] ?? true, $this->_last_response);
        $this->assertSame(0, $this->messages_between($customer, $performer_user));
    }

    public function test_admin_can_message_and_be_replied_to(): void {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $customer = self::factory()->user->create(['role' => 'subscriber']);
        get_user_by('id', $admin)->add_cap('pb_manage_bookings');

        wp_set_current_user($admin);
        $this->assertTrue($this->send($customer)['success'] ?? false, $this->_last_response);
        wp_set_current_user($customer);
        $this->assertTrue($this->send($admin)['success'] ?? false, 'replying to a message you received is allowed');
    }
}
