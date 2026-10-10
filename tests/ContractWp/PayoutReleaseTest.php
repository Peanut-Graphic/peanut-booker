<?php
/**
 * Escrow payout release on a real WordPress with the real plugin schema:
 * the admin single and bulk release routes and the completion path.
 *
 * A payout sends the performer `performer_payout` (the booking total less
 * commission), so it may only be released once the customer has paid in full
 * and the money is held: booking completed, fully_paid = 1, escrow full_held.
 */
namespace Peanut_Booker\Tests\ContractWp;

use Peanut_Booker_Activator;
use Peanut_Booker_Booking;
use Peanut_Booker_Database;
use Peanut_Booker_REST_API_Admin;
use WP_REST_Request;
use WP_UnitTestCase;

final class PayoutReleaseTest extends WP_UnitTestCase {
    private int $admin;

    public static function wpSetUpBeforeClass($factory): void {
        $base = dirname(__DIR__, 2) . '/includes/';
        foreach (['activator', 'database', 'encryption', 'customer', 'roles', 'performer', 'availability', 'notifications', 'booking', 'rate-limiter', 'rest-api-admin'] as $name) {
            require_once $base . 'class-' . $name . '.php';
        }
        $schema = new \ReflectionMethod(Peanut_Booker_Activator::class, 'create_tables');
        $schema->invoke(null);
        // WooCommerce is not installed here; the release notification formats
        // the amount with wc_price(). Provide a minimal stand-in for that one
        // formatting helper only.
        if (!function_exists('wc_price')) {
            eval('function wc_price($amount, $args = array()) { return "$" . number_format((float) $amount, 2); }');
        }
    }

    public function set_up(): void {
        parent::set_up();
        reset_phpmailer_instance();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
        new Peanut_Booker_REST_API_Admin();
        global $wp_rest_server;
        $wp_rest_server = null;
        do_action('rest_api_init');
    }

    private function booking(string $status, string $escrow, int $deposit_paid, int $fully_paid): int {
        $user = self::factory()->user->create();
        $performer = Peanut_Booker_Database::insert('performers', ['user_id' => $user, 'profile_id' => 0, 'status' => 'active']);
        $id = Peanut_Booker_Database::insert('bookings', [
            'booking_number' => 'PB-' . wp_generate_password(8, false), 'performer_id' => $performer,
            'customer_id' => self::factory()->user->create(), 'event_title' => 'Synthetic', 'event_date' => '2026-09-01',
            'total_amount' => 400, 'deposit_amount' => 100, 'remaining_amount' => 300,
            'platform_commission' => 40, 'performer_payout' => 360,
            'booking_status' => $status, 'escrow_status' => $escrow,
            'deposit_paid' => $deposit_paid, 'fully_paid' => $fully_paid,
            'completion_date' => '2026-09-02 12:00:00',
        ]);
        $this->assertNotFalse($id);
        return (int) $id;
    }

    private function row(int $id): object {
        return Peanut_Booker_Database::get_row('bookings', ['id' => $id]);
    }

    private function releases(int $id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pb_transactions WHERE booking_id = %d AND transaction_type = 'escrow_release'",
            $id
        ));
    }

    private function release(int $id) {
        return rest_get_server()->dispatch(new WP_REST_Request('POST', '/peanut-booker/v1/admin/payouts/' . $id . '/release'));
    }

    /** @return array<string, array{0:string,1:string,2:int,3:int}> */
    public static function ineligibleBookings(): array {
        return [
            'unpaid' => ['completed', 'pending', 0, 0],
            'deposit only' => ['completed', 'deposit_held', 1, 0],
            'full_held but not fully paid' => ['completed', 'full_held', 1, 0],
            'paid in full but not completed' => ['confirmed', 'full_held', 1, 1],
            'already released' => ['completed', 'released', 1, 1],
            'refunded' => ['completed', 'refunded', 1, 1],
        ];
    }

    /** @dataProvider ineligibleBookings */
    public function test_single_release_is_refused_unless_paid_in_full_and_held(string $status, string $escrow, int $deposit_paid, int $fully_paid): void {
        $id = $this->booking($status, $escrow, $deposit_paid, $fully_paid);
        $response = $this->release($id);
        $this->assertGreaterThanOrEqual(400, $response->get_status());
        $this->assertNotEmpty($response->get_data()['message'] ?? '', 'the refusal explains why');
        $this->assertSame($escrow, $this->row($id)->escrow_status);
        $this->assertNull($this->row($id)->payout_date);
        $this->assertSame([], $this->releases($id));
    }

    public function test_single_release_pays_a_fully_paid_held_booking_and_records_who(): void {
        $id = $this->booking('completed', 'full_held', 1, 1);
        $response = $this->release($id);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame('released', $this->row($id)->escrow_status);
        $this->assertNotNull($this->row($id)->payout_date);
        $rows = $this->releases($id);
        $this->assertCount(1, $rows);
        $this->assertSame('360.00', $rows[0]->amount);
        $this->assertStringContainsString('#' . $this->admin, $rows[0]->notes, 'the release records which user released it');

        $again = $this->release($id);
        $this->assertGreaterThanOrEqual(400, $again->get_status());
        $this->assertCount(1, $this->releases($id), 'a payout is released once');
    }

    public function test_release_requires_payout_capability(): void {
        $id = $this->booking('completed', 'full_held', 1, 1);
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame(403, $this->release($id)->get_status());
        $this->assertSame('full_held', $this->row($id)->escrow_status);
    }

    public function test_bulk_release_pays_only_eligible_bookings(): void {
        $good_a = $this->booking('completed', 'full_held', 1, 1);
        $good_b = $this->booking('completed', 'full_held', 1, 1);
        $deposit_only = $this->booking('completed', 'deposit_held', 1, 0);
        $unpaid = $this->booking('completed', 'pending', 0, 0);
        $not_done = $this->booking('confirmed', 'full_held', 1, 1);

        $request = new WP_REST_Request('POST', '/peanut-booker/v1/admin/payouts/bulk-release');
        $request->set_param('booking_ids', [$good_a, $good_b, $deposit_only, $unpaid, $not_done, 999999]);
        $response = rest_get_server()->dispatch($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(2, $data['released']);
        $this->assertEqualsCanonicalizing([$deposit_only, $unpaid, $not_done, 999999], array_map('intval', array_keys($data['skipped'])));

        foreach ([$good_a, $good_b] as $id) {
            $this->assertSame('released', $this->row($id)->escrow_status);
            $this->assertCount(1, $this->releases($id));
        }
        foreach ([$deposit_only => 'deposit_held', $unpaid => 'pending', $not_done => 'full_held'] as $id => $escrow) {
            $this->assertSame($escrow, $this->row($id)->escrow_status);
            $this->assertSame([], $this->releases($id));
        }
    }

    public function test_completing_a_deposit_only_booking_does_not_release_escrow(): void {
        $id = $this->booking('confirmed', 'deposit_held', 1, 0);
        Peanut_Booker_Booking::update_status($id, Peanut_Booker_Booking::STATUS_COMPLETED);
        $this->assertSame('completed', $this->row($id)->booking_status);
        $this->assertSame('deposit_held', $this->row($id)->escrow_status);
        $this->assertSame([], $this->releases($id));
    }

    public function test_completing_a_fully_paid_booking_still_releases_escrow(): void {
        $id = $this->booking('confirmed', 'full_held', 1, 1);
        Peanut_Booker_Booking::update_status($id, Peanut_Booker_Booking::STATUS_COMPLETED);
        $this->assertSame('released', $this->row($id)->escrow_status);
        $this->assertCount(1, $this->releases($id));
    }
}
